<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class AliasCommand extends Command
{
    protected $signature = 'alias {action : set or clear}
                            {selector : Row number, alias, or email substring identifying the account}
                            {alias? : New alias, required when action is "set"}';

    protected $description = 'Set or clear the display alias for a stored account';

    public function handle(): int
    {
        $this->warn('Not implemented yet.');

        return self::SUCCESS;
    }
}
