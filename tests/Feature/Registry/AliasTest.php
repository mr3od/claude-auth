<?php

use App\Services\Exceptions\AccountNotFoundException;
use App\Services\Exceptions\AmbiguousSelectorException;
use App\Services\Exceptions\InvalidAliasException;
use App\Services\Registry;
use Tests\Feature\Concerns\UsesFakeClaudeHome;

uses(UsesFakeClaudeHome::class);

beforeEach(function () {
    $this->useFakeClaudeHome();
    $this->seedFakeRegistryFile();
    $this->registry = new Registry($this->fakeHome, $this->fakeCredentialsFile, $this->fakeClaudeJsonFile, maxBackups: 5);
});

afterEach(fn () => $this->cleanupFakeClaudeHome());

it('sets an alias and persists it', function () {
    $updated = $this->registry->setAlias('uuid-b::org-b', 'personal');

    expect($updated->alias)->toBe('personal')
        ->and($this->registry->listAccounts()->accounts[1]->alias)->toBe('personal');
});

it('clears an alias', function () {
    $updated = $this->registry->clearAlias('work');

    expect($updated->alias)->toBeNull()
        ->and($this->registry->listAccounts()->accounts[0]->alias)->toBeNull();
});

it('throws AccountNotFoundException for an unresolvable selector', function () {
    $this->registry->setAlias('nope', 'x');
})->throws(AccountNotFoundException::class);

it('throws AmbiguousSelectorException when the selector matches more than one account', function () {
    $this->seedFakeRegistryFile([
        'accounts' => [
            ['account_key' => 'uuid-a::org-a', 'account_uuid' => 'uuid-a', 'organization_uuid' => 'org-a', 'email' => 'shared@x.com', 'alias' => null, 'organization_name' => null, 'display_name' => null, 'created_at' => 't', 'last_used_at' => null],
            ['account_key' => 'uuid-b::org-b', 'account_uuid' => 'uuid-b', 'organization_uuid' => 'org-b', 'email' => 'shared@x.com', 'alias' => null, 'organization_name' => null, 'display_name' => null, 'created_at' => 't', 'last_used_at' => null],
        ],
    ]);

    $this->registry->setAlias('shared@x.com', 'x');
})->throws(AmbiguousSelectorException::class);

it('rejects an empty alias', function () {
    $this->registry->setAlias('work', '');
})->throws(InvalidAliasException::class);

it('rejects an all-digit alias since that collides with row-number selectors', function () {
    $this->registry->setAlias('work', '123');
})->throws(InvalidAliasException::class);

it('rejects an alias containing control characters', function () {
    $this->registry->setAlias('work', "bad\talias");
})->throws(InvalidAliasException::class);

it('rejects a case-insensitive duplicate alias on another account', function () {
    $this->registry->setAlias('uuid-b::org-b', 'WORK');
})->throws(InvalidAliasException::class);

it('allows re-setting an account\'s own existing alias without a duplicate error', function () {
    $updated = $this->registry->setAlias('work', 'work');

    expect($updated->alias)->toBe('work');
});
