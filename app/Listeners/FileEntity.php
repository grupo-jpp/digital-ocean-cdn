<?php

declare(strict_types=1);

namespace DigitalOceanCdn\Listeners;

use Atro\Core\EventManager\Event;
use Espo\Core\Listeners\AbstractListener;

class FileEntity extends AbstractListener
{
    public function afterSave(Event $event): void
    {
        $entity = $event->getArgument('entity');
        if (!$entity || !$entity->get('storageId')) {
            return;
        }
        $storage = $this->getEntityManager()->getEntity('Storage', $entity->get('storageId'));
        if (!$storage
            || $storage->get('type') !== 'digitalOceanSpaces'
            || !$storage->get('syncEnabled')
            || !$storage->get('syncOnFileSave')) {
            return;
        }

        /** @var \DigitalOceanCdn\Core\FileStorage\DigitalOceanSpacesStorage $fs */
        $fs = $this->getContainer()->get('fileStorageManager')->getStorage('digitalOceanSpaces');
        if ($fs->exists($storage, $entity)) {
            return;
        }
        $path = 'upload/' . ltrim((string)$entity->get('path'), '/');
        if (!is_file($path)) {
            $path = 'data/' . $path;
        }
        if (is_file($path)) {
            $fs->upload($storage, $entity, (string)file_get_contents($path));
        }
    }

    public function afterRemove(Event $event): void
    {
        $entity = $event->getArgument('entity');
        if (!$entity || !$entity->get('storageId')) {
            return;
        }
        $storage = $this->getEntityManager()->getEntity('Storage', $entity->get('storageId'));
        if (!$storage
            || $storage->get('type') !== 'digitalOceanSpaces'
            || !$storage->get('syncDeleteRemote')) {
            return;
        }
        /** @var \DigitalOceanCdn\Core\FileStorage\DigitalOceanSpacesStorage $fs */
        $fs = $this->getContainer()->get('fileStorageManager')->getStorage('digitalOceanSpaces');
        $fs->delete($storage, $entity);
    }
}