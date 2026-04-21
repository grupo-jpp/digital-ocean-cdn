<?php

declare(strict_types=1);

namespace DigitalOceanCdn\Services;

use Atro\Entities\File;
use Atro\Entities\Storage;
use DigitalOceanCdn\Core\FileStorage\DigitalOceanSpacesStorage;
use Espo\Core\Templates\Services\Base;

class DigitalOceanSpacesSync extends Base
{
    /**
     * Enfileira o job de sync — evita timeout HTTP.
     */
    public function queueSync(string $storageId): array
    {
        /** @var Storage|null $storage */
        $storage = $this->getEntityManager()->getEntity('Storage', $storageId);
        if (!$storage || $storage->get('type') !== 'digitalOceanSpaces') {
            return ['success' => false, 'message' => 'Invalid storage'];
        }

        $qm = $this->getContainer()->get('queueManager');
        $qm->push(
            'DO Spaces sync: ' . $storage->get('name'),
            'DigitalOceanSpacesSyncJob',
            ['storageId' => $storageId]
        );

        return ['success' => true, 'message' => 'Sync queued'];
    }

    /**
     * Execução efetiva (chamada pelo Job).
     */
    public function runSync(string $storageId): array
    {
        /** @var Storage $storage */
        $storage = $this->getEntityManager()->getEntity('Storage', $storageId);
        if (!$storage) {
            return ['uploaded' => 0, 'downloaded' => 0, 'errors' => ['Storage not found']];
        }

        $direction = (string)$storage->get('syncDirection') ?: 'upload';
        $stats     = ['uploaded' => 0, 'downloaded' => 0, 'deleted' => 0, 'errors' => []];

        /** @var DigitalOceanSpacesStorage $fs */
        $fs = $this->getContainer()->get('fileStorageManager')->getStorage('digitalOceanSpaces');

        if (in_array($direction, ['upload', 'both'], true)) {
            $stats = array_merge($stats, $this->syncUp($storage, $fs, $stats));
        }

        if (in_array($direction, ['download', 'both'], true)) {
            $stats = array_merge($stats, $this->syncDown($storage, $fs, $stats));
        }

        $storage->set('lastSyncAt', date('Y-m-d H:i:s'));
        $storage->set('lastSyncStatus', empty($stats['errors'])
            ? sprintf('OK up=%d down=%d', $stats['uploaded'], $stats['downloaded'])
            : 'Errors: ' . count($stats['errors']));
        $this->getEntityManager()->saveEntity($storage, ['skipAll' => true]);

        return $stats;
    }

    protected function syncUp(Storage $storage, DigitalOceanSpacesStorage $fs, array $stats): array
    {
        $em = $this->getEntityManager();
        $files = $em->getRepository('File')
            ->where(['storageId' => $storage->get('id')])
            ->find();

        foreach ($files as $file) {
            /** @var File $file */
            try {
                if ($fs->exists($storage, $file)) {
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
        return $stats;
    }

    protected function syncDown(Storage $storage, DigitalOceanSpacesStorage $fs, array $stats): array
    {
        // TODO: listar objetos do bucket via S3 client (ListObjectsV2)
        // e criar/atualizar entidades File correspondentes.
        return $stats;
    }

    protected function readLocalContents(File $file): ?string
    {
        $path = rtrim((string)$file->get('path'), '/');
        $candidates = [
            'upload/' . $path,
            'data/upload/' . $path,
        ];
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return (string)file_get_contents($c);
            }
        }
        return null;
    }
}