<?php

use App\Services\Registry;
use Tests\Feature\Concerns\UsesFakeClaudeHome;

uses(UsesFakeClaudeHome::class);

beforeEach(function () {
    $this->useFakeClaudeHome();
    $this->seedFakeAccountPair();
    $this->registry = new Registry($this->fakeHome, $this->fakeCredentialsFile, $this->fakeClaudeJsonFile, maxBackups: 5);
});

afterEach(fn () => $this->cleanupFakeClaudeHome());

it('removes the given accounts and leaves the rest', function () {
    $result = $this->registry->remove(['uuid-a::org-a']);

    expect($result->removedKeys)->toBe(['uuid-a::org-a']);

    $listing = $this->registry->listAccounts();
    expect($listing->accounts)->toHaveCount(1)
        ->and($listing->accounts[0]->accountKey)->toBe('uuid-b::org-b');
});

it('clears active_account_key when the removed account was active', function () {
    $result = $this->registry->remove(['uuid-a::org-a']); // uuid-a is the seeded active account

    expect($result->newActiveAccountKey)->toBeNull()
        ->and($this->registry->listAccounts()->activeAccountKey)->toBeNull();
});

it('leaves active_account_key untouched when a non-active account is removed', function () {
    $this->registry->remove(['uuid-b::org-b']);

    expect($this->registry->listAccounts()->activeAccountKey)->toBe('uuid-a::org-a');
});

it('clears previous_active_account_key when the removed account was the previous one', function () {
    $this->registry = new Registry($this->fakeHome, $this->fakeCredentialsFile, $this->fakeClaudeJsonFile, maxBackups: 5);
    $this->seedFakeCredentialsFile();
    $this->seedFakeClaudeJsonFile();
    $this->registry->activate('uuid-b::org-b'); // uuid-a becomes previous

    $this->registry->remove(['uuid-a::org-a']);

    expect($this->registry->listAccounts()->previousAccountKey)->toBeNull();
});

it('removeAll removes every account', function () {
    $result = $this->registry->removeAll();

    expect($result->removedKeys)->toHaveCount(2)
        ->and($this->registry->listAccounts()->accounts)->toBe([]);
});

it('never deletes live credential or claude.json files', function () {
    $this->seedFakeCredentialsFile();
    $this->seedFakeClaudeJsonFile();

    $this->registry->removeAll();

    expect(is_file($this->fakeCredentialsFile))->toBeTrue()
        ->and(is_file($this->fakeClaudeJsonFile))->toBeTrue();
});
