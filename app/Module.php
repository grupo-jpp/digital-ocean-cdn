<?php

declare(strict_types=1);

namespace DigitalOceanCdn;

use Atro\Core\ModuleManager\AbstractModule;

class Module extends AbstractModule
{
    public static function getLoadOrder(): int
    {
        return 5110;
    }
}