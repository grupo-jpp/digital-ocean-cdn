<?php

declare(strict_types=1);

namespace DigitalOceanCdn\Core\FileStorage;

use Atro\Core\FileStorage\AbstractFileStorage;
use Atro\Entities\File;
use Atro\Entities\Storage;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

class DigitalOceanSpacesStorage extends AbstractFileStorage
{
    private array $clients = [];

    protected function getClient(Storage $storage): S3Client
    {
        $id = $storage->get('id');
        if (!isset($this->clients[$id])) {
            $this->clients[$id] = new S3Client([
                'version'                 => 'latest',
                'region'                  => $storage->get('doRegion') ?: 'us-east-1',
                'endpoint'                => $storage->get('doEndpoint'),
                'use_path_style_endpoint' => false,
                'credentials'             => [
                    'key'    => $storage->get('doAccessKey'),
                    'secret' => $storage->get('doSecretKey'),
                ],
            ]);
        }
        return $this->clients[$id];
    }

    protected function getKey(Storage $storage, File $file): string
    {
        $prefix = trim((string)$storage->get('doPathPrefix'), '/');
        $path   = ltrim((string)$file->get('path'), '/');
        return $prefix ? $prefix . '/' . $path : $path;
    }

    public function upload(Storage $storage, File $file, string $contents): bool
    {
        try {
            $this->getClient($storage)->putObject([
                'Bucket'      => $storage->get('doBucket'),
                'Key'         => $this->getKey($storage, $file),
                'Body'        => $contents,
                'ACL'         => 'public-read',
                'ContentType' => $file->get('mimeType') ?: 'application/octet-stream',
            ]);
            return true;
        } catch (S3Exception $e) {
            $GLOBALS['log']->error('DO Spaces upload error: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(Storage $storage, File $file): bool
    {
        try {
            $this->getClient($storage)->deleteObject([
                'Bucket' => $storage->get('doBucket'),
                'Key'    => $this->getKey($storage, $file),
            ]);
            return true;
        } catch (S3Exception $e) {
            $GLOBALS['log']->error('DO Spaces delete error: ' . $e->getMessage());
            return false;
        }
    }

    public function getContents(Storage $storage, File $file): ?string
    {
        try {
            $result = $this->getClient($storage)->getObject([
                'Bucket' => $storage->get('doBucket'),
                'Key'    => $this->getKey($storage, $file),
            ]);
            return (string)$result['Body'];
        } catch (S3Exception $e) {
            $GLOBALS['log']->error('DO Spaces get error: ' . $e->getMessage());
            return null;
        }
    }

    public function getUrl(Storage $storage, File $file): ?string
    {
        $cdn = rtrim((string)$storage->get('doCdnEndpoint'), '/');
        if ($cdn) {
            return $cdn . '/' . $this->getKey($storage, $file);
        }

        try {
            $cmd = $this->getClient($storage)->getCommand('GetObject', [
                'Bucket' => $storage->get('doBucket'),
                'Key'    => $this->getKey($storage, $file),
            ]);
            $request = $this->getClient($storage)->createPresignedRequest($cmd, '+20 minutes');
            return (string)$request->getUri();
        } catch (S3Exception $e) {
            return null;
        }
    }

    public function exists(Storage $storage, File $file): bool
    {
        try {
            return $this->getClient($storage)->doesObjectExist(
                $storage->get('doBucket'),
                $this->getKey($storage, $file)
            );
        } catch (S3Exception $e) {
            return false;
        }
    }
}