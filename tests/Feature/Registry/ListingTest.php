<?php

use App\Services\Registry;
use Tests\Feature\Concerns\UsesFakeClaudeHome;

uses(UsesFakeClaudeHome::class);

beforeEach(function () {
    $this->useFakeClaudeHome();
    $this->registry = new Registry($this->fakeHome, $this->fakeCredentialsFile, $this->fakeClaudeJsonFile, maxBackups: 5);
});

afterEach(fn () => $this->cleanupFakeClaudeHome());

it('returns an empty listing when registry.json does not exist yet', function () {
    $listing = $this->registry->listAccounts();

    expect($listing->accounts)->toBe([])
        ->and($listing->activeAccountKey)->toBeNull()
        ->and($listing->previousAccountKey)->toBeNull();
});

it('lists accounts in registry.json file order with active/previous keys', function () {
    $this->seedFakeRegistryFile();

    $listing = $this->registry->listAccounts();

    expect($listing->accounts)->toHaveCount(2)
        ->and($listing->accounts[0]->accountKey)->toBe('uuid-a::org-a')
        ->and($listing->accounts[1]->accountKey)->toBe('uuid-b::org-b')
        ->and($listing->activeAccountKey)->toBe('uuid-a::org-a')
        ->and($listing->previousAccountKey)->toBeNull();
});

it('derives a display label preferring alias, then display name, then email, then key', function () {
    $this->seedFakeRegistryFile();

    $listing = $this->registry->listAccounts();

    expect($listing->accounts[0]->displayLabel())->toBe('work')
        ->and($listing->accounts[1]->displayLabel())->toBe('B User');
});

it('falls back to the account key as the display label when nothing else is set', function () {
    $this->seedFakeRegistryFile([
        'accounts' => [
            [
                'account_key' => 'uuid-c::org-c', 'account_uuid' => 'uuid-c', 'organization_uuid' => 'org-c',
                'email' => '', 'alias' => null, 'organization_name' => null, 'display_name' => null,
                'created_at' => '2026-08-03T00:00:00+00:00', 'last_used_at' => null,
            ],
        ],
    ]);

    $listing = $this->registry->listAccounts();

    expect($listing->accounts[0]->displayLabel())->toBe('uuid-c::org-c');
});
