<?php

namespace App\Commands;

use App\Services\Registry;
use LaravelZero\Framework\Commands\Command;

class ListCommand extends Command
{
    protected $signature = 'accounts {--json : Output machine-readable JSON}';

    protected $description = 'List stored Claude accounts and which one is active';

    public function handle(Registry $registry): int
    {
        $listing = $registry->listAccounts();

        if ($this->option('json')) {
            $accounts = [];
            foreach ($listing->accounts as $index => $account) {
                $accounts[] = ['number' => $index + 1, ...$account->toArray()];
            }

            $this->line(json_encode([
                'schema_version' => 1,
                'active_account_key' => $listing->activeAccountKey,
                'previous_active_account_key' => $listing->previousAccountKey,
                'accounts' => $accounts,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($listing->accounts === []) {
            $this->info('No accounts stored yet. Run "claude-auth login" to add one.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($listing->accounts as $index => $account) {
            $rows[] = [
                $index + 1,
                $account->accountKey === $listing->activeAccountKey ? '*' : '',
                $account->displayLabel(),
                $account->email,
                $account->organizationName,
            ];
        }

        $this->table(['#', 'Active', 'Account', 'Email', 'Organization'], $rows);

        return self::SUCCESS;
    }
}
