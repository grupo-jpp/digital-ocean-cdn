<?php

declare(strict_types=1);

namespace DigitalOceanCdn\Listeners;

use Atro\Core\EventManager\Event;
use Espo\Core\Listeners\AbstractListener;

/**
 * STUB — listener desativado até o storage estar implementado.
 */
class FileEntity extends AbstractListener
{
    public function afterSave(Event $event): void {}
    public function afterRemove(Event $event): void {}
}