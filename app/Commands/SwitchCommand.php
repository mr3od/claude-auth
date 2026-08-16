<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class SwitchCommand extends Command
{
    protected $signature = 'switch {query? : Row number, alias, or email substring. Use "-" to switch back to the previous account}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Switch which stored account is active in ~/.claude/.credentials.json';

    public function handle(): int
    {
        $this->warn('Not implemented yet.');

        return self::SUCCESS;
    }
}
