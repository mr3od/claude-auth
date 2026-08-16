<?php

namespace App\Services;

use App\DataTransferObjects\AccountListing;
use App\DataTransferObjects\AccountRecord;
use App\DataTransferObjects\AccountSnapshot;
use App\DataTransferObjects\PurgeResult;
use App\DataTransferObjects\RemovalResult;
use App\DataTransferObjects\SelectorResolution;
use App\DataTransferObjects\SelectorResolutionBatch;
use App\DataTransferObjects\SelectorResolutionStatus;
use App\Services\Exceptions\AccountNotFoundException;
use App\Services\Exceptions\AmbiguousSelectorException;
use App\Services\Exceptions\BatchImportAliasNotAllowedException;
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

    public function captureFromPaths(string $credentialsPath, string $claudeJsonPath): AccountSnapshot
    {
        $credentials = $this->store->readJsonOrNull($credentialsPath);
        $claudeJson = $this->store->readJsonOrNull($claudeJsonPath);

        if ($credentials === null || $claudeJson === null || ! isset($claudeJson['oauthAccount'])) {
            throw new \RuntimeException("Could not read a complete account from \"{$credentialsPath}\" and \"{$claudeJsonPath}\".");
        }

        $oauthAccount = $claudeJson['oauthAccount'];

        return new AccountSnapshot(
            accountKey: $this->deriveAccountKey($oauthAccount),
            credentials: $credentials,
            oauthAccount: $oauthAccount,
            capturedAt: (new \DateTimeImmutable)->format(DATE_ATOM),
        );
    }

    public function captureCurrentAccount(): AccountSnapshot
    {
        return $this->captureFromPaths($this->credentialsFile, $this->claudeJsonFile);
    }

    public function upsert(AccountSnapshot $snapshot, ?string $alias = null): AccountRecord
    {
        $this->ensureDirectoriesExist();
        $this->store->writeJsonAtomic($this->snapshotPath($snapshot->accountKey), $this->codec->encode($snapshot));

        $registry = $this->loadRegistry();
        $existing = $this->findRow($registry, $snapshot->accountKey);

        $row = $this->rowFromSnapshot(
            $snapshot,
            alias: $alias ?? $existing['alias'] ?? null,
            createdAt: $existing['created_at'] ?? $snapshot->capturedAt,
            lastUsedAt: $existing['last_used_at'] ?? null,
        );

        $registry['accounts'] = $existing !== null
            ? array_map(fn (array $r) => $r['account_key'] === $snapshot->accountKey ? $row : $r, $registry['accounts'])
            : [...$registry['accounts'], $row];

        $this->saveRegistry($registry);

        return AccountRecord::fromArray($row);
    }

    /**
     * @return AccountRecord[]
     */
    public function importPath(string $path, ?string $alias = null): array
    {
        if (is_dir($path)) {
            return $this->importDirectory($path, $alias);
        }

        $data = $this->store->readJsonOrNull($path);

        if ($data === null) {
            throw new \RuntimeException("Could not read snapshot file \"{$path}\".");
        }

        return [$this->upsert($this->codec->decode($data), $alias)];
    }

    public function importPurge(): PurgeResult
    {
        $this->ensureDirectoriesExist();

        $oldAliases = $this->aliasesByAccountKey($this->store->readJsonOrNull($this->registryPath()));

        $accounts = [];
        foreach (glob($this->home.'/accounts/*.snapshot.json') ?: [] as $file) {
            $data = $this->store->readJsonOrNull($file);

            if ($data === null) {
                continue; // corrupt/unreadable snapshot - best-effort, skip rather than fail the whole purge
            }

            $snapshot = $this->codec->decode($data);
            $accounts[] = $this->rowFromSnapshot($snapshot, $oldAliases[$snapshot->accountKey] ?? null, $snapshot->capturedAt, null);
        }

        $aliasesPreserved = count(array_intersect_key($oldAliases, array_flip(array_column($accounts, 'account_key'))));

        $activeAccountKey = null;
        $liveAccountImported = false;

        try {
            $liveSnapshot = $this->captureCurrentAccount();
            $activeAccountKey = $liveSnapshot->accountKey;

            if (! in_array($liveSnapshot->accountKey, array_column($accounts, 'account_key'), true)) {
                $this->store->writeJsonAtomic($this->snapshotPath($liveSnapshot->accountKey), $this->codec->encode($liveSnapshot));
                $accounts[] = $this->rowFromSnapshot($liveSnapshot, $oldAliases[$liveSnapshot->accountKey] ?? null, $liveSnapshot->capturedAt, null);
                $liveAccountImported = true;
            }
        } catch (\RuntimeException) {
            // No readable live credentials/claude.json pair - purge still succeeds with whatever snapshot files exist.
        }

        $this->saveRegistry([
            'schema_version' => 1,
            'active_account_key' => $activeAccountKey,
            'previous_active_account_key' => null,
            'active_account_activated_at' => $activeAccountKey !== null ? (new \DateTimeImmutable)->format(DATE_ATOM) : null,
            'accounts' => $accounts,
        ]);

        return new PurgeResult(
            accountsFound: count($accounts),
            aliasesPreserved: $aliasesPreserved,
            liveAccountImported: $liveAccountImported,
            activeAccountKey: $activeAccountKey,
        );
    }

    /**
     * @return string[]
     */
    public function exportSnapshots(?string $dir = null): array
    {
        $dir ??= $this->home.'/backups';

        if (! is_dir($dir)) {
            mkdir($dir, 0700, recursive: true);
        }

        $written = [];
        foreach (glob($this->home.'/accounts/*.snapshot.json') ?: [] as $file) {
            $dest = $dir.'/'.basename($file);
            copy($file, $dest);
            $written[] = $dest;
        }

        return $written;
    }

    /**
     * @return AccountRecord[]
     */
    private function importDirectory(string $dir, ?string $alias): array
    {
        if (is_file($dir.'/.credentials.json') && is_file($dir.'/.claude.json')) {
            $snapshot = $this->captureFromPaths($dir.'/.credentials.json', $dir.'/.claude.json');

            return [$this->upsert($snapshot, $alias)];
        }

        $files = glob($dir.'/*.snapshot.json') ?: [];

        if ($files === []) {
            throw new \RuntimeException("No snapshot files, and no .credentials.json + .claude.json pair, found in \"{$dir}\".");
        }

        if ($alias !== null) {
            throw new BatchImportAliasNotAllowedException;
        }

        $records = [];
        foreach ($files as $file) {
            $data = $this->store->readJsonOrNull($file);

            if ($data !== null) {
                $records[] = $this->upsert($this->codec->decode($data));
            }
        }

        return $records;
    }

    /**
     * @param  array{accounts: array<int, array<string, mixed>>}|null  $registry
     * @return array<string, string>
     */
    private function aliasesByAccountKey(?array $registry): array
    {
        $aliases = [];
        foreach ($registry['accounts'] ?? [] as $row) {
            if (! empty($row['alias'])) {
                $aliases[$row['account_key']] = $row['alias'];
            }
        }

        return $aliases;
    }

    /**
     * @param  array{accounts: array<int, array<string, mixed>>}  $registry
     * @return array<string, mixed>|null
     */
    private function findRow(array $registry, string $accountKey): ?array
    {
        foreach ($registry['accounts'] as $row) {
            if ($row['account_key'] === $accountKey) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFromSnapshot(AccountSnapshot $snapshot, ?string $alias, string $createdAt, ?string $lastUsedAt): array
    {
        $oauthAccount = $snapshot->oauthAccount;

        return [
            'account_key' => $snapshot->accountKey,
            'account_uuid' => $oauthAccount['accountUuid'],
            'organization_uuid' => $oauthAccount['organizationUuid'] ?? '',
            'email' => $oauthAccount['emailAddress'] ?? '',
            'alias' => $alias,
            'organization_name' => $oauthAccount['organizationName'] ?? null,
            'display_name' => $oauthAccount['displayName'] ?? null,
            'created_at' => $createdAt,
            'last_used_at' => $lastUsedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $oauthAccount
     */
    private function deriveAccountKey(array $oauthAccount): string
    {
        $organizationUuid = $oauthAccount['organizationUuid'] ?? null;

        return $organizationUuid !== null && $organizationUuid !== ''
            ? "{$oauthAccount['accountUuid']}::{$organizationUuid}"
            : $oauthAccount['accountUuid'];
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
