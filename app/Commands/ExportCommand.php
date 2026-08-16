<?php

namespace App\Commands;

use App\Services\Registry;
use LaravelZero\Framework\Commands\Command;

class ExportCommand extends Command
{
    protected $signature = 'export {dir? : Destination directory, defaults to the registry\'s backup folder}';

    protected $description = 'Export stored account credential snapshots to a directory';

    public function handle(Registry $registry): int
    {
        $written = $registry->exportSnapshots($this->argument('dir'));

        if ($written === []) {
            $this->info('No accounts stored yet, nothing to export.');

            return self::SUCCESS;
        }

        $this->info('Exported '.count($written).' account(s):');
        foreach ($written as $path) {
            $this->line("  - {$path}");
        }

        return self::SUCCESS;
    }
}
