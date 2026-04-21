<?php

declare(strict_types=1);

namespace DigitalOceanCdn;

use Atro\Core\AbstractModule;

class Module extends AbstractModule
{
    public static function getLoadOrder(): int
    {
        return 5200;
    }

    public function onLoad(): void
    {
    }
}