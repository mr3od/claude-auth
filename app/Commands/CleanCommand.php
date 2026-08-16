<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class CleanCommand extends Command
{
    protected $signature = 'clean';

    protected $description = 'Prune old backups and delete snapshot files no longer referenced by the registry';

    public function handle(): int
    {
        $this->warn('Not implemented yet.');

        return self::SUCCESS;
    }
}
