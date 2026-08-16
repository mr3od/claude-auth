<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class RemoveCommand extends Command
{
    protected $signature = 'remove {selectors?* : Row numbers, aliases, or email substrings to remove}
                            {--all : Remove every stored account}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Remove one or more stored accounts';

    public function handle(): int
    {
        $this->warn('Not implemented yet.');

        return self::SUCCESS;
    }
}
