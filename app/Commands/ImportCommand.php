<?php

namespace App\Commands;

use App\Services\Registry;
use LaravelZero\Framework\Commands\Command;

class ImportCommand extends Command
{
    protected $signature = 'import {path? : Credentials file, or a directory of credential files}
                            {--alias= : Alias to assign (single-file import only)}
                            {--purge : Rebuild the registry from whatever snapshot files exist on disk}';

    protected $description = 'Import an existing credentials file as a new stored account, or rebuild the registry from disk';

    public function handle(Registry $registry): int
    {
        $path = $this->argument('path');
        $alias = $this->option('alias');
        $purge = (bool) $this->option('purge');

        if ($purge) {
            if ($path !== null || $alias !== null) {
                $this->error('--purge cannot be combined with a path or --alias.');

                return self::INVALID;
            }

            $result = $registry->importPurge();
            $this->info("Rebuilt the registry from disk: {$result->accountsFound} account(s) found, {$result->aliasesPreserved} alias(es) preserved.");
            if ($result->liveAccountImported) {
                $this->line('The currently active Claude account was adopted as a new stored account.');
            }

            return self::SUCCESS;
        }

        if ($path === null) {
            $this->error('Provide a path to import, or use --purge.');

            return self::INVALID;
        }

        try {
            $records = $registry->importPath($path, $alias);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($records as $record) {
            $this->info("Imported {$record->displayLabel()} ({$record->email}).");
        }

        return self::SUCCESS;
    }
}
