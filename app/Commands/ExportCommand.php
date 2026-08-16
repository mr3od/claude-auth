<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class ExportCommand extends Command
{
    protected $signature = 'export {dir? : Destination directory, defaults to the registry\'s backup folder}';

    protected $description = 'Export stored account credential snapshots to a directory';

    public function handle(): int
    {
        $this->warn('Not implemented yet.');

        return self::SUCCESS;
    }
}
