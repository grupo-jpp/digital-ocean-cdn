<?php

declare(strict_types=1);

namespace DigitalOceanCdn\Core\FileStorage;

use Atro\Core\Container;
use Atro\Core\FileStorage\AbstractFileStorage;
use Atro\Entities\File;
use Atro\Entities\Storage;
use Aws\S3\S3Client;
use RuntimeException;

class DigitalOceanSpacesStorage extends AbstractFileStorage
{
    /** @var array<string, S3Client> */
    private array $clients = [];

    protected function container(): Container
    {
        return $this->container;
    }

    protected function getClient(Storage $storage): S3Client
    {
        $id = (string)$storage->get('id');
        if (isset($this->clients[$id])) {
            return $this->clients[$id];
        }

        $connection = null;
        if ($storage->get('connectionId')) {
            $connection = $this->container->get('entityManager')
                ->getEntity('Connection', $storage->get('connectionId'));
        }

        if ($connection !== null) {
            /** @var \DigitalOceanCdn\ConnectionType\ConnectionDoSpaces $type */
            $type = $this->container->get('connectionFactory')->create($connection);
            /** @var S3Client $client */
            $client = $type->connect($connection);
        } else {
            // fallback: credenciais diretamente no Storage (compatibilidade)
            $endpoint = rtrim((string)$storage->get('doEndpoint'), '/');
            $region   = (string)$storage->get('doRegion') ?: 'us-east-1';
            $key      = (string)$storage->get('doAccessKey');
            $secret   = (string)$storage->get('doSecretKey');

            if ($endpoint === '' || $key === '' || $secret === '') {
                throw new RuntimeException('Digital Ocean Spaces: storage has no connection and no credentials.');
            }

            $client = new S3Client([
                'version'                 => 'latest',
                'region'                  => $region,
                'endpoint'                => $endpoint,
                'use_path_style_endpoint' => false,
                'credentials'             => ['key' => $key, 'secret' => $secret],
            ]);
        }

        return $this->clients[$id] = $client;
    }

    protected function getBucket(Storage $storage): string
    {
        $bucket = (string)$storage->get('doBucket');
        if ($bucket === '' && $storage->get('connectionId')) {
            $conn = $this->container->get('entityManager')->getEntity('Connection', $storage->get('connectionId'));
            if ($conn) {
                $bucket = (string)$conn->get('doSpacesBucket');
                if ($bucket === '' && is_object($conn->get('data')) && isset($conn->get('data')->doSpacesBucket)) {
                    $bucket = (string)$conn->get('data')->doSpacesBucket;
                }
            }
        }
        if ($bucket === '') {
            throw new RuntimeException('Digital Ocean Spaces: bucket is not configured.');
        }
        return $bucket;
    }

    protected function getKey(Storage $storage, File $file): string
    {
        $prefix = trim((string)$storage->get('doPathPrefix'), '/');
        $path   = ltrim((string)$file->get('path'), '/');
        return $prefix !== '' ? $prefix . '/' . $path : $path;
    }

    protected function encodePathForUrl(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    public function upload(Storage $storage, File $file, string $contents): bool
    {
        try {
            $this->getClient($storage)->putObject([
                'Bucket'      => $this->getBucket($storage),
                'Key'         => $this->getKey($storage, $file),
                'Body'        => $contents,
                'ACL'         => 'public-read',
                'ContentType' => (string)$file->get('mimeType') ?: 'application/octet-stream',
            ]);
            return true;
        } catch (\Throwable $e) {
            $GLOBALS['log']->error('DO Spaces upload failed: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(Storage $storage, File $file): bool
    {
        try {
            $this->getClient($storage)->deleteObject([
                'Bucket' => $this->getBucket($storage),
                'Key'    => $this->getKey($storage, $file),
            ]);
            return true;
        } catch (\Throwable $e) {
            $GLOBALS['log']->error('DO Spaces delete failed: ' . $e->getMessage());
            return false;
        }
    }

    public function getContents(Storage $storage, File $file): ?string
    {
        try {
            $res = $this->getClient($storage)->getObject([
                'Bucket' => $this->getBucket($storage),
                'Key'    => $this->getKey($storage, $file),
            ]);
            return (string)$res['Body'];
        } catch (\Throwable $e) {
            $GLOBALS['log']->error('DO Spaces getContents failed: ' . $e->getMessage());
            return null;
        }
    }

    public function exists(Storage $storage, File $file): bool
    {
        try {
            $this->getClient($storage)->headObject([
                'Bucket' => $this->getBucket($storage),
                'Key'    => $this->getKey($storage, $file),
            ]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getUrl(Storage $storage, File $file): string
    {
        $key = $this->encodePathForUrl($this->getKey($storage, $file));
        $cdn = rtrim((string)$storage->get('doCdnEndpoint'), '/');
        if ($cdn !== '') {
            return $cdn . '/' . $key;
        }

        // presigned 20min
        $client  = $this->getClient($storage);
        $command = $client->getCommand('GetObject', [
            'Bucket' => $this->getBucket($storage),
            'Key'    => $this->getKey($storage, $file),
        ]);
        return (string)$client->createPresignedRequest($command, '+20 minutes')->getUri();
    }
}