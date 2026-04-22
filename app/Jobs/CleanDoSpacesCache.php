<?php
declare(strict_types=1);

namespace DigitalOceanCdn\Jobs;

use Espo\Core\Jobs\Base;

class CleanDoSpacesCache extends Base
{
    public function run(): bool
    {
        $dirs = [
            'data/.do-spaces-cache',
            'data/.do-spaces-pdf-cache',
            'data/.do-spaces-tmp',
            'data/.do-spaces-chunks',
        ];
        $ttl  = 7 * 86400; // 7 dias
        $now  = time();

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                if ($f->isFile() && ($now - $f->getMTime()) > $ttl) {
                    @unlink($f->getPathname());
                } elseif ($f->isDir()) {
                    @rmdir($f->getPathname());
                }
            }
        }
        return true;
    }
}