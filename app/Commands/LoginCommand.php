<?php

namespace App\Commands;

use App\Services\Exceptions\ScratchLoginFailedException;
use App\Services\Exceptions\UnsupportedPlatformException;
use App\Services\Registry;
use App\Services\ScratchLoginRunner;
use LaravelZero\Framework\Commands\Command;

class LoginCommand extends Command
{
    protected $signature = 'login {--alias= : Alias to assign to the account being added}';

    protected $description = 'Run Claude Code login in an isolated scratch config dir, then store the resulting credentials';

    public function handle(Registry $registry, ScratchLoginRunner $runner): int
    {
        $this->info('Opening Claude Code login in an isolated session...');

        try {
            $result = $runner->run(onOutput: function (string $type, string $output) {
                $this->output->write($output);
            });
        } catch (ScratchLoginFailedException|UnsupportedPlatformException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $snapshot = $registry->captureFromPaths($result->credentialsPath, $result->claudeJsonPath);
            $account = $registry->upsert($snapshot, $this->option('alias'));
        } finally {
            ($result->cleanup)();
        }

        $this->info("Stored account {$account->displayLabel()} ({$account->email}).");
        $this->line('Run "claude-auth switch" to make it the active account.');

        return self::SUCCESS;
    }
}
