<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class LoginCommand extends Command
{
    protected $signature = 'login {--alias= : Alias to assign to the account being added}';

    protected $description = 'Run Claude Code login in an isolated scratch config dir, then store the resulting credentials';

    public function handle(): int
    {
        $this->warn('Not implemented yet.');

        return self::SUCCESS;
    }
}
