<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class ImportCommand extends Command
{
    protected $signature = 'import {path? : Credentials file, or a directory of credential files}
                            {--alias= : Alias to assign (single-file import only)}
                            {--purge : Rebuild the registry from whatever snapshot files exist on disk}';

    protected $description = 'Import an existing credentials file as a new stored account, or rebuild the registry from disk';

    public function handle(): int
    {
        $this->warn('Not implemented yet.');

        return self::SUCCESS;
    }
}
