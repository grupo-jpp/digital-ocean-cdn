<?php

declare(strict_types=1);

namespace DigitalOceanCdn\Console;

use Espo\Console\AbstractConsole;

class SyncSpaces extends AbstractConsole
{
    public static function getDescription(): string
    {
        return 'Run Digital Ocean Spaces sync for a given storage id.';
    }

    public function run(array $data): void
    {
        $storageId = $data['id'] ?? null;
        if (!$storageId) {
            self::show('Usage: php console.php digital-ocean:sync --id=<storageId>', self::ERROR, true);
        }
        $service = $this->getContainer()->get('serviceFactory')->create('DigitalOceanSpacesSync');
        $result  = $service->runSync((string)$storageId);
        self::show(json_encode($result, JSON_PRETTY_PRINT), self::SUCCESS);
    }
}