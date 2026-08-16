<?php

namespace App\Commands;

use App\DataTransferObjects\AccountRecord;
use App\DataTransferObjects\SelectorResolutionStatus;
use App\Services\Exceptions\AccountNotFoundException;
use App\Services\Exceptions\NoPreviousAccountException;
use App\Services\Exceptions\RegistryCorruptException;
use App\Services\Registry;
use LaravelZero\Framework\Commands\Command;

class SwitchCommand extends Command
{
    protected $signature = 'switch {query? : Row number, alias, or email substring. Use "-" to switch back to the previous account}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Switch which stored account is active in ~/.claude/.credentials.json';

    public function handle(Registry $registry): int
    {
        $query = $this->argument('query');
        $json = (bool) $this->option('json');

        if ($query === null) {
            if ($json) {
                return $this->usageError('A query is required when using --json.', $json);
            }

            return $this->interactiveSwitch($registry);
        }

        if ($query === '-') {
            try {
                return $this->succeed($registry->activatePrevious(), $json);
            } catch (NoPreviousAccountException $e) {
                return $this->handledError($e->getMessage(), $json, 'no_previous_account');
            }
        }

        $resolution = $registry->resolve($query);

        return match ($resolution->status) {
            SelectorResolutionStatus::Found => $this->activateAndSucceed($registry, $resolution->match->accountKey, $json),
            SelectorResolutionStatus::NotFound => $this->handledError("No account matches \"{$query}\".", $json, 'not_found'),
            SelectorResolutionStatus::Ambiguous => $json
                ? $this->ambiguousJson($query, $resolution->candidates)
                : $this->activateAndSucceed($registry, $this->pickFrom('Multiple accounts match. Which one?', $resolution->candidates), false),
        };
    }

    private function activateAndSucceed(Registry $registry, string $accountKey, bool $json): int
    {
        try {
            return $this->succeed($registry->activate($accountKey), $json);
        } catch (AccountNotFoundException $e) {
            return $this->handledError($e->getMessage(), $json, 'not_found');
        } catch (RegistryCorruptException $e) {
            return $this->handledError($e->getMessage(), $json, 'registry_corrupt');
        }
    }

    private function interactiveSwitch(Registry $registry): int
    {
        $listing = $registry->listAccounts();

        if ($listing->accounts === []) {
            $this->info('No accounts stored yet. Run "claude-auth login" to add one.');

            return self::SUCCESS;
        }

        return $this->activateAndSucceed($registry, $this->pickFrom('Switch to which account?', $listing->accounts), false);
    }

    /**
     * @param  AccountRecord[]  $accounts
     */
    private function pickFrom(string $question, array $accounts): string
    {
        $keysByLabel = [];
        foreach ($accounts as $account) {
            $keysByLabel["{$account->displayLabel()} ({$account->email})"] = $account->accountKey;
        }

        $selectedLabel = $this->choice($question, array_keys($keysByLabel));

        return $keysByLabel[$selectedLabel];
    }

    private function ambiguousJson(string $query, array $candidates): int
    {
        $this->line(json_encode([
            'error' => 'ambiguous_query',
            'query' => $query,
            'candidates' => array_map(fn (AccountRecord $c) => $c->toArray(), $candidates),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::FAILURE;
    }

    private function succeed(AccountRecord $account, bool $json): int
    {
        if ($json) {
            $this->line(json_encode(['switched_to' => $account->toArray()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("Switched to {$account->displayLabel()} ({$account->email}).");
        }

        return self::SUCCESS;
    }

    private function handledError(string $message, bool $json, string $code): int
    {
        if ($json) {
            $this->line(json_encode(['error' => $code, 'message' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }

    private function usageError(string $message, bool $json): int
    {
        if ($json) {
            $this->line(json_encode(['error' => 'usage', 'message' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->error($message);
        }

        return self::INVALID;
    }
}
