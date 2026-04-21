<?php

declare(strict_types=1);

namespace DigitalOceanCdn\Jobs;

use Espo\Core\QueueManagerBase;

class DigitalOceanSpacesSyncJob extends QueueManagerBase
{
    public function run(array $data = []): bool
    {
        if (empty($data['storageId'])) {
            return false;
        }
        /** @var \DigitalOceanCdn\Services\DigitalOceanSpacesSync $service */
        $service = $this->getContainer()->get('serviceFactory')->create('DigitalOceanSpacesSync');
        $service->runSync((string)$data['storageId']);
        return true;
    }
}