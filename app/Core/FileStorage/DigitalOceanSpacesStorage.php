<?php

declare(strict_types=1);

namespace DigitalOceanCdn\Core\FileStorage;

use Atro\Core\FileStorage\AbstractFileStorage;
use Atro\Entities\File;
use Atro\Entities\Storage;
use Aws\S3\S3Client;
use RuntimeException;

class DigitalOceanSpacesStorage extends AbstractFileStorage
{
    private array $clients = [];

    protected function getClient(Storage $storage): S3Client
    {
        $id = (string)$storage->get('id');
        $cacheKey = $id ?: md5((string)json_encode([
            $this->getStorageValue($storage, ['doEndpoint', 'doSpacesEndpoint']),
            $this->getStorageValue($storage, ['doRegion', 'doSpacesRegion'], 'us-east-1'),
            $this->getStorageValue($storage, ['doAccessKey', 'doSpacesAccessKey']),
            $this->getStorageValue($storage, ['doBucket', 'doSpacesBucket']),
        ]));

        if (!isset($this->clients[$cacheKey])) {
            $endpoint = rtrim($this->getStorageValue($storage, ['doEndpoint', 'doSpacesEndpoint']), '/');
            $accessKey = $this->getStorageValue($storage, ['doAccessKey', 'doSpacesAccessKey']);
            $secretKey = $this->getStorageValue($storage, ['doSecretKey', 'doSpacesSecretKey']);

            if (empty($endpoint) || empty($accessKey) || empty($secretKey)) {
                throw new RuntimeException('DigitalOcean Spaces storage is not fully configured.');
            }

            $this->clients[$cacheKey] = new S3Client([
                'version'                 => 'latest',
                'region'                  => $this->getStorageValue($storage, ['doRegion', 'doSpacesRegion'], 'us-east-1'),
                'endpoint'                => $endpoint,
                'use_path_style_endpoint' => false,
                'credentials'             => [
                    'key'    => $accessKey,
                    'secret' => $secretKey,
                ],
            ]);
        }

        return $this->clients[$cacheKey];
    }

    protected function getStorageValue(Storage $storage, array $keys, ?string $default = ''): string
    {
        foreach ($keys as $key) {
            $value = $storage->get($key);
            if ($value !== null && $value !== '') {
                return (string)$value;
            }
        }

        return (string)$default;
    }

    protected function getBucket(Storage $storage): string
    {
        return $this->getStorageValue($storage, ['doBucket', 'doSpacesBucket']);
    }

    protected function getKey(Storage $storage, File $file): string
    {
        $prefix = trim($this->getStorageValue($storage, ['doPathPrefix']), '/');
        $path   = ltrim((string)$file->get('path'), '/');
        return $prefix ? $prefix . '/' . $path : $path;
    }

    public function upload(Storage $storage, File $file, string $contents): bool
    {
        try {
            $bucket = $this->getBucket($storage);
            if (empty($bucket)) {
                throw new RuntimeException('DigitalOcean Spaces bucket is not configured.');
            }

            $this->getClient($storage)->putObject([
                'Bucket'      => $bucket,
                'Key'         => $this->getKey($storage, $file),
                'Body'        => $contents,
                'ACL'         => 'public-read',
                'ContentType' => $file->get('mimeType') ?: 'application/octet-stream',
            ]);
            return true;
        } catch (\Throwable $e) {
            $GLOBALS['log']->error('DO Spaces upload error: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(Storage $storage, File $file): bool
    {
        try {
            $bucket = $this->getBucket($storage);
            if (empty($bucket)) {
                throw new RuntimeException('DigitalOcean Spaces bucket is not configured.');
            }

            $this->getClient($storage)->deleteObject([
                'Bucket' => $bucket,
                'Key'    => $this->getKey($storage, $file),
            ]);
            return true;
        } catch (\Throwable $e) {
            $GLOBALS['log']->error('DO Spaces delete error: ' . $e->getMessage());
            return false;
        }
    }

    public function getContents(Storage $storage, File $file): ?string
    {
        try {
            $bucket = $this->getBucket($storage);
            if (empty($bucket)) {
                throw new RuntimeException('DigitalOcean Spaces bucket is not configured.');
            }

            $result = $this->getClient($storage)->getObject([
                'Bucket' => $bucket,
                'Key'    => $this->getKey($storage, $file),
            ]);
            return (string)$result['Body'];
        } catch (\Throwable $e) {
            $GLOBALS['log']->error('DO Spaces get error: ' . $e->getMessage());
            return null;
        }
    }

    public function getUrl(Storage $storage, File $file): ?string
    {
        $cdn = rtrim($this->getStorageValue($storage, ['doCdnEndpoint', 'doSpacesCdnEndpoint']), '/');
        if ($cdn) {
            $encodedKey = implode('/', array_map('rawurlencode', explode('/', $this->getKey($storage, $file))));
            return $cdn . '/' . $encodedKey;
        }

        try {
            $bucket = $this->getBucket($storage);
            if (empty($bucket)) {
                throw new RuntimeException('DigitalOcean Spaces bucket is not configured.');
            }

            $cmd = $this->getClient($storage)->getCommand('GetObject', [
                'Bucket' => $bucket,
                'Key'    => $this->getKey($storage, $file),
            ]);
            $request = $this->getClient($storage)->createPresignedRequest($cmd, '+20 minutes');
            return (string)$request->getUri();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function exists(Storage $storage, File $file): bool
    {
        try {
            $bucket = $this->getBucket($storage);
            if (empty($bucket)) {
                throw new RuntimeException('DigitalOcean Spaces bucket is not configured.');
            }

            return $this->getClient($storage)->doesObjectExist(
                $bucket,
                $this->getKey($storage, $file)
            );
        } catch (\Throwable $e) {
            return false;
        }
    }
}
