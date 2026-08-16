<?php

namespace App\Services;

use App\DataTransferObjects\AccountListing;
use App\DataTransferObjects\AccountRecord;
use App\DataTransferObjects\RemovalResult;
use App\DataTransferObjects\SelectorResolution;
use App\DataTransferObjects\SelectorResolutionBatch;
use App\DataTransferObjects\SelectorResolutionStatus;
use App\Services\Exceptions\AccountNotFoundException;
use App\Services\Exceptions\AmbiguousSelectorException;
use App\Services\Exceptions\InvalidAliasException;
use App\Services\Exceptions\NoPreviousAccountException;
use App\Services\Exceptions\RegistryCorruptException;

final class Registry
{
    private readonly AtomicJsonStore $store;

    private readonly SelectorResolver $resolver;

    private readonly SnapshotCodec $codec;

    public function __construct(
        private readonly string $home,
        private readonly string $credentialsFile,
        private readonly string $claudeJsonFile,
        private readonly int $maxBackups,
    ) {
        $this->store = new AtomicJsonStore($this->home.'/backups', $this->maxBackups);
        $this->resolver = new SelectorResolver;
        $this->codec = new SnapshotCodec;
    }

    public function listAccounts(): AccountListing
    {
        $registry = $this->loadRegistry();

        return new AccountListing(
            accounts: array_map(fn (array $row) => AccountRecord::fromArray($row), $registry['accounts']),
            activeAccountKey: $registry['active_account_key'],
            previousAccountKey: $registry['previous_active_account_key'],
        );
    }

    public function resolve(string $query): SelectorResolution
    {
        return $this->resolver->resolve($this->listAccounts()->accounts, $query);
    }

    /**
     * @param  string[]  $selectors
     */
    public function resolveMany(array $selectors): SelectorResolutionBatch
    {
        $accounts = $this->listAccounts()->accounts;

        $bySelector = [];
        foreach ($selectors as $selector) {
            $bySelector[$selector] = $this->resolver->resolve($accounts, $selector);
        }

        return new SelectorResolutionBatch($bySelector);
    }

    public function activate(string $accountKey): AccountRecord
    {
        $registry = $this->loadRegistry();

        $accountRow = null;
        foreach ($registry['accounts'] as $row) {
            if ($row['account_key'] === $accountKey) {
                $accountRow = $row;
                break;
            }
        }

        if ($accountRow === null) {
            throw new AccountNotFoundException($accountKey);
        }

        $snapshotData = $this->store->readJsonOrNull($this->snapshotPath($accountKey));

        if ($snapshotData === null) {
            throw new RegistryCorruptException("Account \"{$accountKey}\" is tracked in the registry but its snapshot file is missing.");
        }

        $snapshot = $this->codec->decode($snapshotData);

        $this->ensureDirectoriesExist();

        // Unconditional: the live files are the ones a mistake is hardest to recover
        // from, so they're backed up every time, even when content looks unchanged.
        $this->store->backupUnconditional($this->credentialsFile, 'credentials.json');
        $this->store->backupUnconditional($this->claudeJsonFile, 'claude.json');

        $this->store->writeJsonPreservingPermissions($this->credentialsFile, $snapshot->credentials);

        $claudeJson = $this->store->readJsonOrNull($this->claudeJsonFile) ?? [];
        $claudeJson['oauthAccount'] = $snapshot->oauthAccount;
        $this->store->writeJsonPreservingPermissions($this->claudeJsonFile, $claudeJson);

        $now = (new \DateTimeImmutable)->format(DATE_ATOM);
        $previouslyActive = $registry['active_account_key'];

        $registry['active_account_key'] = $accountKey;
        $registry['active_account_activated_at'] = $now;
        if ($previouslyActive !== $accountKey) {
            $registry['previous_active_account_key'] = $previouslyActive;
        }

        $accountRow['last_used_at'] = $now;
        $registry['accounts'] = array_map(
            fn (array $row) => $row['account_key'] === $accountKey ? $accountRow : $row,
            $registry['accounts'],
        );

        $this->saveRegistry($registry);

        return AccountRecord::fromArray($accountRow);
    }

    public function activatePrevious(): AccountRecord
    {
        $previous = $this->loadRegistry()['previous_active_account_key'];

        if ($previous === null) {
            throw new NoPreviousAccountException;
        }

        return $this->activate($previous);
    }

    /**
     * @param  string[]  $accountKeys  already resolved - callers resolve (and confirm)
     *                                 everything before mutating anything
     */
    public function remove(array $accountKeys): RemovalResult
    {
        $registry = $this->loadRegistry();

        $removed = [];
        $remaining = [];
        foreach ($registry['accounts'] as $row) {
            if (in_array($row['account_key'], $accountKeys, true)) {
                $removed[] = $row['account_key'];
            } else {
                $remaining[] = $row;
            }
        }

        $registry['accounts'] = $remaining;

        if (in_array($registry['active_account_key'], $removed, true)) {
            $registry['active_account_key'] = null;
        }

        if (in_array($registry['previous_active_account_key'], $removed, true)) {
            $registry['previous_active_account_key'] = null;
        }

        $this->saveRegistry($registry);

        return new RemovalResult($removed, $registry['active_account_key']);
    }

    public function removeAll(): RemovalResult
    {
        return $this->remove(array_column($this->loadRegistry()['accounts'], 'account_key'));
    }

    private function snapshotPath(string $accountKey): string
    {
        return $this->home.'/accounts/'.$this->codec->filename($accountKey);
    }

    public function setAlias(string $selector, string $alias): AccountRecord
    {
        $this->validateAlias($alias);

        $accounts = $this->listAccounts()->accounts;
        $account = $this->resolveOrFail($accounts, $selector);

        $normalized = mb_strtolower($alias);
        foreach ($accounts as $other) {
            if ($other->accountKey !== $account->accountKey
                && $other->alias !== null
                && mb_strtolower($other->alias) === $normalized) {
                throw new InvalidAliasException($alias, 'duplicate');
            }
        }

        return $this->replaceAccount($accounts, $account->withAlias($alias));
    }

    public function clearAlias(string $selector): AccountRecord
    {
        $accounts = $this->listAccounts()->accounts;
        $account = $this->resolveOrFail($accounts, $selector);

        return $this->replaceAccount($accounts, $account->withAlias(null));
    }

    private function validateAlias(string $alias): void
    {
        if ($alias === '') {
            throw new InvalidAliasException($alias, 'empty');
        }

        if (ctype_digit($alias)) {
            throw new InvalidAliasException($alias, 'all_digit');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $alias) === 1) {
            throw new InvalidAliasException($alias, 'control_characters');
        }
    }

    /**
     * @param  AccountRecord[]  $accounts
     */
    private function resolveOrFail(array $accounts, string $selector): AccountRecord
    {
        $resolution = $this->resolver->resolve($accounts, $selector);

        return match ($resolution->status) {
            SelectorResolutionStatus::Found => $resolution->match,
            SelectorResolutionStatus::NotFound => throw new AccountNotFoundException($selector),
            SelectorResolutionStatus::Ambiguous => throw new AmbiguousSelectorException($selector, $resolution->candidates),
        };
    }

    /**
     * @param  AccountRecord[]  $accounts
     */
    private function replaceAccount(array $accounts, AccountRecord $updated): AccountRecord
    {
        $registry = $this->loadRegistry();

        $registry['accounts'] = array_map(
            fn (array $row) => $row['account_key'] === $updated->accountKey ? $updated->toArray() : $row,
            $registry['accounts'],
        );

        $this->saveRegistry($registry);

        return $updated;
    }

    /**
     * @param  array{schema_version: int, active_account_key: ?string, previous_active_account_key: ?string, active_account_activated_at: ?string, accounts: array<int, array<string, mixed>>}  $data
     */
    private function saveRegistry(array $data): void
    {
        $this->ensureDirectoriesExist();
        $this->store->backupIfChanged($this->registryPath(), 'registry.json');
        $this->store->writeJsonAtomic($this->registryPath(), $data);
        $this->store->pruneBackups('registry.json');
    }

    private function ensureDirectoriesExist(): void
    {
        foreach ([$this->home, $this->home.'/accounts', $this->home.'/backups'] as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0700, recursive: true);
            }
        }
    }

    private function registryPath(): string
    {
        return $this->home.'/registry.json';
    }

    /**
     * @return array{schema_version: int, active_account_key: ?string, previous_active_account_key: ?string, active_account_activated_at: ?string, accounts: array<int, array<string, mixed>>}
     */
    private function loadRegistry(): array
    {
        return $this->store->readJsonOrNull($this->registryPath()) ?? [
            'schema_version' => 1,
            'active_account_key' => null,
            'previous_active_account_key' => null,
            'active_account_activated_at' => null,
            'accounts' => [],
        ];
    }
}
