<?php

namespace App\Services;

use App\DataTransferObjects\AccountListing;
use App\DataTransferObjects\AccountRecord;
use App\DataTransferObjects\SelectorResolution;
use App\DataTransferObjects\SelectorResolutionBatch;

final class Registry
{
    private readonly AtomicJsonStore $store;

    private readonly SelectorResolver $resolver;

    public function __construct(
        private readonly string $home,
        private readonly string $credentialsFile,
        private readonly string $claudeJsonFile,
        private readonly int $maxBackups,
    ) {
        $this->store = new AtomicJsonStore($this->home.'/backups', $this->maxBackups);
        $this->resolver = new SelectorResolver;
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
