<?php

declare(strict_types=1);

namespace DigitalOceanCdn\Services;

use Atro\Entities\File;
use Atro\Entities\Storage;
use DigitalOceanCdn\Core\FileStorage\DigitalOceanSpacesStorage;
use Espo\Core\Templates\Services\Base;

class DigitalOceanSpacesSync extends Base
{
    public function queueSync(string $storageId): array
    {
        /** @var Storage|null $storage */
        $storage = $this->getEntityManager()->getEntity('Storage', $storageId);
        if (!$storage || $storage->get('type') !== 'digitalOceanSpaces') {
            return ['success' => false, 'message' => 'Invalid storage'];
        }

        $this->getContainer()->get('queueManager')->push(
            'DO Spaces sync: ' . $storage->get('name'),
            'DigitalOceanSpacesSyncJob',
            ['storageId' => $storageId]
        );

        return ['success' => true, 'message' => 'Sync queued'];
    }

    public function runSync(string $storageId): array
    {
        /** @var Storage|null $storage */
        $storage = $this->getEntityManager()->getEntity('Storage', $storageId);
        if (!$storage) {
            return ['uploaded' => 0, 'downloaded' => 0, 'errors' => ['Storage not found']];
        }

        $stats = ['uploaded' => 0, 'downloaded' => 0, 'skipped' => 0, 'errors' => []];
        $dir   = (string)$storage->get('syncDirection') ?: 'upload';

        /** @var DigitalOceanSpacesStorage $fs */
        $fs = $this->getContainer()->get('fileStorageManager')->getStorage('digitalOceanSpaces');

        if (in_array($dir, ['upload', 'both'], true)) {
            $this->syncUp($storage, $fs, $stats);
        }
        if (in_array($dir, ['download', 'both'], true)) {
            $this->syncDown($storage, $fs, $stats);
        }

        $storage->set('lastSyncAt', date('Y-m-d H:i:s'));
        $storage->set('lastSyncStatus', empty($stats['errors'])
            ? sprintf('OK up=%d down=%d skipped=%d', $stats['uploaded'], $stats['downloaded'], $stats['skipped'])
            : 'Errors: ' . count($stats['errors']) . ' — ' . $stats['errors'][0]);
        $this->getEntityManager()->saveEntity($storage, ['skipAll' => true]);

        return $stats;
    }

    protected function syncUp(Storage $storage, DigitalOceanSpacesStorage $fs, array &$stats): void
    {
        $files = $this->getEntityManager()->getRepository('File')
            ->where(['storageId' => $storage->get('id')])
            ->find();

        foreach ($files as $file) {
            /** @var File $file */
            try {
                if ($fs->exists($storage, $file)) {
                    $stats['skipped']++;
                    continue;
                }
                $contents = $this->readLocalContents($file);
                if ($contents === null) {
                    $stats['errors'][] = 'Local missing: ' . $file->get('name');
                    continue;
                }
                if ($fs->upload($storage, $file, $contents)) {
                    $stats['uploaded']++;
                }
            } catch (\Throwable $e) {
                $stats['errors'][] = $file->get('name') . ': ' . $e->getMessage();
            }
        }
    }

    /**
     * Lista objetos do bucket e cria registros File que ainda não existem localmente.
     */
    protected function syncDown(Storage $storage, DigitalOceanSpacesStorage $fs, array &$stats): void
    {
        try {
            $reflection = new \ReflectionMethod($fs, 'getClient');
            $reflection->setAccessible(true);
            /** @var \Aws\S3\S3Client $client */
            $client = $reflection->invoke($fs, $storage);

            $bucketRef = new \ReflectionMethod($fs, 'getBucket');
            $bucketRef->setAccessible(true);
            $bucket = (string)$bucketRef->invoke($fs, $storage);

            $prefix = trim((string)$storage->get('doPathPrefix'), '/');
            $prefix = $prefix !== '' ? $prefix . '/' : '';

            $continuation = null;
            do {
                $args = ['Bucket' => $bucket, 'Prefix' => $prefix, 'MaxKeys' => 1000];
                if ($continuation) {
                    $args['ContinuationToken'] = $continuation;
                }
                $res = $client->listObjectsV2($args);
                foreach ($res['Contents'] ?? [] as $obj) {
                    $key = (string)$obj['Key'];
                    if (substr($key, -1) === '/') {
                        continue;
                    }
                    $relPath = $prefix !== '' ? substr($key, strlen($prefix)) : $key;

                    $existing = $this->getEntityManager()->getRepository('File')
                        ->where(['storageId' => $storage->get('id'), 'path' => $relPath])
                        ->findOne();
                    if ($existing) {
                        $stats['skipped']++;
                        continue;
                    }

                    $file = $this->getEntityManager()->getEntity('File');
                    $file->set([
                        'name'      => basename($relPath),
                        'path'      => $relPath,
                        'size'      => (int)($obj['Size'] ?? 0),
                        'storageId' => $storage->get('id'),
                    ]);
                    $this->getEntityManager()->saveEntity($file, ['skipAll' => true]);
                    $stats['downloaded']++;
                }
                $continuation = $res['IsTruncated'] ? ($res['NextContinuationToken'] ?? null) : null;
            } while ($continuation);
        } catch (\Throwable $e) {
            $stats['errors'][] = 'syncDown: ' . $e->getMessage();
        }
    }

    protected function readLocalContents(File $file): ?string
    {
        $path = ltrim((string)$file->get('path'), '/');
        foreach (['upload/' . $path, 'data/upload/' . $path] as $p) {
            if (is_file($p)) {
                return (string)file_get_contents($p);
            }
        }
        return null;
    }
}