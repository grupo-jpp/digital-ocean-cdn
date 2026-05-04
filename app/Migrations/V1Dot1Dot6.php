<?php

declare(strict_types=1);

namespace DigitalOceanCdn\Migrations;

use Atro\Core\Migration\Base;

class V1Dot1Dot6 extends Base
{
    public function getMigrationDateTime(): ?\DateTime
    {
        return new \DateTime('2026-05-04 00:00:00');
    }

    public function up(): void
    {
        if ($this->isPgSQL()) {
            $this->exec("ALTER TABLE storage ADD COLUMN IF NOT EXISTS last_sync_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL");
            $this->exec("ALTER TABLE storage ADD COLUMN IF NOT EXISTS last_sync_status TEXT DEFAULT NULL");
        } else {
            $this->exec("ALTER TABLE storage ADD COLUMN IF NOT EXISTS last_sync_at DATETIME DEFAULT NULL");
            $this->exec("ALTER TABLE storage ADD COLUMN IF NOT EXISTS last_sync_status LONGTEXT DEFAULT NULL");
        }
    }

    protected function exec(string $sql): void
    {
        try {
            $this->getPDO()->exec($sql);
        } catch (\Throwable $e) {
            // Silently ignore errors (e.g. column already exists), following AtroCore migration conventions.
        }
    }
}
