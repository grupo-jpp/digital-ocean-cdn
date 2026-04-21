<?php
declare(strict_types=1);

namespace DigitalOceanCdn;

use Atro\Core\ModuleManager\AbstractModule;
use DigitalOceanCdn\Core\FileStorage\DigitalOceanSpacesStorage;

class Module extends AbstractModule
{
    public static function getLoadOrder(): int
    {
        return 5110;
    }

    public function onLoad()
    {
        // maps Storage.type='digitalOceanSpaces' -> container key 'digitalOceanSpacesStorage'
        $this->container->setClassAlias(
            'digitalOceanSpacesStorage',
            DigitalOceanSpacesStorage::class
        );
    }
}