<?php

namespace App\Commands;

use App\Services\Registry;
use LaravelZero\Framework\Commands\Command;

class CleanCommand extends Command
{
    protected $signature = 'clean';

    protected $description = 'Prune old backups and delete snapshot files no longer referenced by the registry';

    public function handle(Registry $registry): int
    {
        $deletedBackups = $registry->pruneBackups();
        $deletedSnapshots = $registry->pruneOrphanedSnapshots();

        $this->info(count($deletedBackups).' old backup(s) removed, '.count($deletedSnapshots).' orphaned snapshot(s) removed.');

        return self::SUCCESS;
    }
}
