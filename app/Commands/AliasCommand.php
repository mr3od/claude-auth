<?php

namespace App\Commands;

use App\Services\Exceptions\AccountNotFoundException;
use App\Services\Exceptions\AmbiguousSelectorException;
use App\Services\Exceptions\InvalidAliasException;
use App\Services\Registry;
use LaravelZero\Framework\Commands\Command;

class AliasCommand extends Command
{
    protected $signature = 'alias {action : set or clear}
                            {selector : Row number, alias, or email substring identifying the account}
                            {alias? : New alias, required when action is "set"}';

    protected $description = 'Set or clear the display alias for a stored account';

    public function handle(Registry $registry): int
    {
        $action = $this->argument('action');

        if (! in_array($action, ['set', 'clear'], true)) {
            $this->error('Action must be "set" or "clear".');

            return self::INVALID;
        }

        if ($action === 'set' && $this->argument('alias') === null) {
            $this->error('An alias is required when action is "set".');

            return self::INVALID;
        }

        try {
            $account = $action === 'set'
                ? $registry->setAlias($this->argument('selector'), $this->argument('alias'))
                : $registry->clearAlias($this->argument('selector'));
        } catch (AccountNotFoundException|InvalidAliasException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (AmbiguousSelectorException $e) {
            $this->error($e->getMessage());
            foreach ($e->candidates as $candidate) {
                $this->line("  - {$candidate->displayLabel()} ({$candidate->email})");
            }

            return self::FAILURE;
        }

        $this->info($action === 'set'
            ? "Alias set to \"{$account->alias}\" for {$account->email}."
            : "Alias cleared for {$account->email}.");

        return self::SUCCESS;
    }
}
