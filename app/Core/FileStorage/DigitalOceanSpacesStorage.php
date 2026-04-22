<?php
declare(strict_types=1);

namespace DigitalOceanCdn\Core\FileStorage;

use Atro\Core\Container;
use Atro\Core\Exceptions\BadRequest;
use Atro\Core\Exceptions\Error;
use Atro\Core\Exceptions\NotFound;
use Atro\Core\FileStorage\FileStorageInterface;
use Atro\Core\FileStorage\LocalStorage;
use Atro\Entities\File;
use Atro\Entities\Folder;
use Atro\Entities\Storage;
use Aws\S3\S3Client;
use Aws\S3\MultipartUploader;
use Aws\S3\Exception\S3Exception;
use Espo\ORM\EntityManager;
use Psr\Http\Message\StreamInterface;

class DigitalOceanSpacesStorage implements FileStorageInterface
{
    protected Container $container;

    /** @var array<string, S3Client> */
    protected array $clients = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    // ---------- core read/write ----------

    public function createFile(File $file): bool
    {
        $input = $file->_input ?? new \stdClass();
        $storage = $file->getStorage();

        $tmp = $this->makeTempPath($file);

        try {
            if (property_exists($input, 'fileContents')) {
                file_put_contents($tmp, LocalStorage::parseInputFileContent($input->fileContents));
            } elseif (property_exists($input, 'localFileName')) {
                if (!file_exists($input->localFileName)) {
                    throw new Error("Local file not found: {$input->localFileName}");
                }
                copy($input->localFileName, $tmp);
            } elseif (property_exists($input, 'remoteUrl')) {
                $remote = (string)$input->remoteUrl;
                if (str_starts_with($remote, 'file://')) {
                    $path = substr($remote, 7);
                    if (!file_exists($path)) {
                        throw new Error("File $path does not exist");
                    }
                    copy($path, $tmp);
                } else {
                    $fp = fopen($tmp, 'w+');
                    $ch = curl_init($remote);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
                    curl_setopt($ch, CURLOPT_FILE, $fp);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_exec($ch);
                    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    fclose($fp);
                    if (!in_array($code, [200, 201])) {
                        @unlink($tmp);
                        throw new Error("Download failed for {$remote} (HTTP $code)");
                    }
                }
            } elseif (property_exists($input, 'allChunks')) {
                $hash = (string)($input->fileUniqueHash ?? '');
                if ($hash === '') {
                    throw new BadRequest('Missing fileUniqueHash for chunked upload.');
                }
                $chunkDir = $this->getChunksDir($storage) . DIRECTORY_SEPARATOR . $hash;

                $f = fopen($tmp, 'a+');
                try {
                    foreach ($input->allChunks as $chunk) {
                        $chunkPath = $chunkDir . DIRECTORY_SEPARATOR . $chunk;
                        if (!file_exists($chunkPath)) {
                            throw new Error("Chunk not found: $chunkPath");
                        }
                        fwrite($f, file_get_contents($chunkPath));
                    }
                } finally {
                    fclose($f);
                }

                $this->removeDir($chunkDir);
            } else {
                throw new BadRequest('No file source provided.');
            }

            $mimeType = mime_content_type($tmp) ?: 'application/octet-stream';
            if ($mimeType === 'text/plain' && pathinfo($tmp, PATHINFO_EXTENSION) === 'csv') {
                $mimeType = 'text/csv';
            }
            $file->set('fileMtime', gmdate('Y-m-d H:i:s', filemtime($tmp)));
            $file->set('mimeType', $mimeType);
            $file->set('fileSize', filesize($tmp));
            $file->set('hash', md5_file($tmp));

            $this->putObject($storage, $this->getKey($file), $tmp, $mimeType);

            return true;
        } finally {
            if (isset($tmp) && file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }

    public function reupload(File $file): bool
    {
        return $this->deleteFilePermanently($file) && $this->createFile($file);
    }

    public function deleteFilePermanently(File $file): bool
    {
        try {
            $storage = $file->getStorage();
            $this->getClient($storage)->deleteObject([
                'Bucket' => $storage->get('bucket'),
                'Key'    => $this->getKey($file),
            ]);
        } catch (S3Exception $e) {
            // soft fail
        }
        return true;
    }

    public function getContents(File $file): string
    {
        $storage = $file->getStorage();
        try {
            $res = $this->getClient($storage)->getObject([
                'Bucket' => $storage->get('bucket'),
                'Key'    => $this->getKey($file),
            ]);
            return (string)$res['Body'];
        } catch (S3Exception $e) {
            throw new NotFound('File not found on Spaces: ' . $e->getMessage());
        }
    }

    public function getStream(File $file): StreamInterface
    {
        $storage = $file->getStorage();
        try {
            $res = $this->getClient($storage)->getObject([
                'Bucket' => $storage->get('bucket'),
                'Key'    => $this->getKey($file),
            ]);
            return $res['Body'];
        } catch (S3Exception $e) {
            throw new NotFound('File not found on Spaces: ' . $e->getMessage());
        }
    }

    public function getUrl(File $file): string
    {
        $storage = $file->getStorage();
        $bucket  = (string)$storage->get('bucket');
        $key     = $this->getKey($file);

        // 1) Prefere CDN configurado no Connection (doSpacesCdnEndpoint)
        $conn = $storage->get('connection');
        $cdn  = $conn ? rtrim((string)$conn->get('doSpacesCdnEndpoint'), '/') : '';

        // 2) Ou cdnEndpoint no Storage
        if ($cdn === '') {
            $cdn = rtrim((string)$storage->get('cdnEndpoint'), '/');
        }

        if ($cdn !== '') {
            // Se o CDN não contém o bucket no host, injeta
            $parts = parse_url($cdn);
            $host  = $parts['host'] ?? '';
            if ($bucket !== '' && $host !== '' && !str_starts_with($host, $bucket . '.')) {
                $cdn = ($parts['scheme'] ?? 'https') . '://' . $bucket . '.' . $host
                     . (isset($parts['path']) ? $parts['path'] : '');
                $cdn = rtrim($cdn, '/');
            }
            return $cdn . '/' . $key;
        }

        // 3) Fallback: endpoint do Spaces + bucket virtual-hosted
        return rtrim($this->getEndpointForPublicUrl($storage), '/') . '/' . $key;
    }

    public function isAvailable(Storage $storage): bool
    {
        try {
            $this->getClient($storage)->headBucket(['Bucket' => $storage->get('bucket')]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ---------- stubs / simples ----------

    public function scan(Storage $storage): void
    {
        throw new BadRequest('Scan is not yet supported on Digital Ocean Spaces storage.');
    }

    public function createFolder(Folder $folder): bool { return true; }
    public function renameFolder(Folder $folder): bool { return true; }
    public function moveFolder(string $entityId, string $wasParentId, string $becameParentId): bool { return true; }
    public function deleteFolderPermanently(Folder $folder): bool { return true; }

    public function renameFile(File $file): bool { return true; }
    public function moveFile(File $file): bool { return true; }

    // ---------- chunked upload ----------

    public function createChunk(\stdClass $input, Storage $storage): array
    {
        if (empty($input->fileUniqueHash) || !isset($input->start) || !isset($input->piece)) {
            throw new BadRequest('Invalid chunk payload.');
        }

        $dir = $this->getChunksDir($storage) . DIRECTORY_SEPARATOR . $input->fileUniqueHash;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . $input->start,
            LocalStorage::parseInputFileContent($input->piece)
        );

        $chunks = array_values(array_filter(
            scandir($dir),
            fn($f) => $f !== '.' && $f !== '..'
        ));
        sort($chunks, SORT_NATURAL);

        return $chunks;
    }

    public function deleteCache(Storage $storage): void
    {
        $this->removeDir($this->getChunksDir($storage));
    }

    // ---------- thumbnails ----------

    public function getThumbnail(File $file, string $size): ?string
    {
        /** @var \Atro\Core\Utils\Thumbnail $creator */
        $creator = $this->container->get(\Atro\Core\Utils\Thumbnail::class);

        $thumbnailPath = $creator->preparePath($file, $size);

        if ($creator->hasThumbnail($file, $size)) {
            return $thumbnailPath;
        }

        // Baixa o original do Spaces para cache local
        $ext = $file->get('extension') ?: pathinfo((string)$file->get('name'), PATHINFO_EXTENSION);
        $cacheDir = 'data/.do-spaces-cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        $localPath = $cacheDir . DIRECTORY_SEPARATOR . $file->get('id') . ($ext ? '.' . $ext : '');

        if (!file_exists($localPath)) {
            try {
                file_put_contents($localPath, $this->getContents($file));
            } catch (\Throwable $e) {
                $GLOBALS['log']->error('DO Spaces: failed to fetch origin for thumb. ' . $e->getMessage());
                return null;
            }
        }

        // Tipos não suportados (ex.: SVG): apenas copia o arquivo para o path público
        if (!$creator->isThumbnailSupported($file)) {
            $publicPath = 'public' . DIRECTORY_SEPARATOR . $thumbnailPath;
            if (!is_dir(dirname($publicPath))) {
                @mkdir(dirname($publicPath), 0775, true);
            }
            if (!file_exists($publicPath)) {
                @copy($localPath, $publicPath);
            }
            return $thumbnailPath;
        }

        $origin = $localPath;
        if ($creator->isPdf($origin)) {
            $origin = $creator->createImageFromPdf($file, $origin);
        }

        if (!$creator->create($origin, $size, $thumbnailPath)) {
            return null;
        }

        return $thumbnailPath;
    }

    public function getThumbnailPdfImageCachePath(File $file): ?string
    {
        $dir = 'data/.do-spaces-pdf-cache' . DIRECTORY_SEPARATOR . (string)$file->get('id');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    // ---------- helpers ----------

    protected function getClient(Storage $storage): S3Client
    {
        $id = (string)$storage->get('id');
        if (isset($this->clients[$id])) {
            return $this->clients[$id];
        }

        $conn = $storage->get('connection');
        if (empty($conn)) {
            throw new Error("Storage '{$storage->get('name')}' has no connection.");
        }

        // Delega a criação do S3Client ao ConnectionType (descriptografa a senha via decryptPassword).
        try {
            $connectionType = new \DigitalOceanCdn\ConnectionType\ConnectionDoSpaces($this->container);
            $client = $connectionType->connect($conn);

            if ($client instanceof S3Client) {
                return $this->clients[$id] = $client;
            }
        } catch (\Throwable $e) {
            $GLOBALS['log']->warning('DO Spaces: ConnectionType->connect failed, falling back. ' . $e->getMessage());
        }

        // Fallback: monta manualmente e tenta descriptografar o secret
        $endpoint = (string)$conn->get('doSpacesEndpoint');
        $region   = (string)($conn->get('doSpacesRegion') ?: 'us-east-1');
        $key      = (string)$conn->get('doSpacesAccessKey');
        $secret   = (string)$conn->get('doSpacesSecretKey');

        if ($secret !== '') {
            try {
                $decoded = $this->container
                    ->get('serviceFactory')
                    ->create('Connection')
                    ->decryptPassword($secret);
                if (is_string($decoded) && $decoded !== '') {
                    $secret = $decoded;
                }
            } catch (\Throwable $e) {
                // mantém valor original
            }
        }

        return $this->clients[$id] = new S3Client([
            'version'                 => 'latest',
            'region'                  => $region,
            'endpoint'                => $endpoint,
            'use_path_style_endpoint' => false,
            'credentials'             => [
                'key'    => $key,
                'secret' => $secret,
            ],
        ]);
    }

    protected function getEndpointForPublicUrl(Storage $storage): string
    {
        $conn = $storage->get('connection');
        $endpoint = rtrim((string)$conn->get('doSpacesEndpoint'), '/');
        $bucket = (string)$storage->get('bucket');
        $parts = parse_url($endpoint);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        return $scheme . '://' . $bucket . '.' . $host;
    }

    protected function getKey(File $file): string
    {
        $storage = $file->getStorage();
        $prefix = trim((string)$storage->get('keyPrefix'), '/');

        $ext = $file->get('extension') ?: pathinfo((string)$file->get('name'), PATHINFO_EXTENSION);
        $name = $file->get('id') . ($ext ? '.' . $ext : '');

        return $prefix === '' ? $name : ($prefix . '/' . $name);
    }

    protected function putObject(Storage $storage, string $key, string $localFile, string $mimeType): void
    {
        $client = $this->getClient($storage);
        $bucket = (string)$storage->get('bucket');
        $size   = (int)@filesize($localFile);

        // Acima de 100MB usa multipart
        if ($size > 100 * 1024 * 1024) {
            $uploader = new MultipartUploader($client, $localFile, [
                'bucket'          => $bucket,
                'key'             => $key,
                'part_size'       => 16 * 1024 * 1024,
                'concurrency'     => 4,
                'before_initiate' => function (\Aws\Command $cmd) use ($mimeType) {
                    $cmd['ContentType'] = $mimeType;
                    $cmd['ACL']         = 'public-read';
                },
            ]);
            $uploader->upload();
            return;
        }

        $client->putObject([
            'Bucket'      => $bucket,
            'Key'         => $key,
            'SourceFile'  => $localFile,
            'ContentType' => $mimeType,
            'ACL'         => 'public-read',
        ]);
    }

    protected function makeTempPath(File $file): string
    {
        $dir = 'data/.do-spaces-tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/' . uniqid('dos-', true);
    }

    protected function getChunksDir(Storage $storage): string
    {
        $dir = 'data/.do-spaces-chunks' . DIRECTORY_SEPARATOR . (string)$storage->get('id');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    protected function getEntityManager(): EntityManager
    {
        return $this->container->get('entityManager');
    }
}