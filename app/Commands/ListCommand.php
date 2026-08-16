<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class ListCommand extends Command
{
    protected $signature = 'accounts {--json : Output machine-readable JSON}';

    protected $description = 'List stored Claude accounts and which one is active';

    public function handle(): int
    {
        $this->warn('Not implemented yet.');

        return self::SUCCESS;
    }
}
