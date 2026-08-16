<?php

use App\DataTransferObjects\SelectorResolutionStatus;
use App\Services\Registry;
use Tests\Feature\Concerns\UsesFakeClaudeHome;

uses(UsesFakeClaudeHome::class);

beforeEach(function () {
    $this->useFakeClaudeHome();
    $this->seedFakeRegistryFile();
    $this->registry = new Registry($this->fakeHome, $this->fakeCredentialsFile, $this->fakeClaudeJsonFile, maxBackups: 5);
});

afterEach(fn () => $this->cleanupFakeClaudeHome());

it('resolves an exact account_key match', function () {
    $resolution = $this->registry->resolve('uuid-b::org-b');

    expect($resolution->status)->toBe(SelectorResolutionStatus::Found)
        ->and($resolution->match->accountKey)->toBe('uuid-b::org-b');
});

it('resolves a 1-based row number', function () {
    $resolution = $this->registry->resolve('2');

    expect($resolution->status)->toBe(SelectorResolutionStatus::Found)
        ->and($resolution->match->accountKey)->toBe('uuid-b::org-b');
});

it('reports not found for a row number out of range, without falling through to substring matching', function () {
    $resolution = $this->registry->resolve('99');

    expect($resolution->status)->toBe(SelectorResolutionStatus::NotFound);
});

it('resolves a case-insensitive substring match against alias, email, display name, or org name', function () {
    expect($this->registry->resolve('WORK')->match->accountKey)->toBe('uuid-a::org-a')
        ->and($this->registry->resolve('b@example')->match->accountKey)->toBe('uuid-b::org-b')
        ->and($this->registry->resolve('beta')->match->accountKey)->toBe('uuid-b::org-b')
        ->and($this->registry->resolve('b user')->match->accountKey)->toBe('uuid-b::org-b');
});

it('reports ambiguous when a substring matches more than one account', function () {
    $this->seedFakeRegistryFile([
        'accounts' => [
            [
                'account_key' => 'uuid-a::org-a', 'account_uuid' => 'uuid-a', 'organization_uuid' => 'org-a',
                'email' => 'shared@example.com', 'alias' => null, 'organization_name' => 'Acme', 'display_name' => null,
                'created_at' => '2026-08-01T00:00:00+00:00', 'last_used_at' => null,
            ],
            [
                'account_key' => 'uuid-b::org-b', 'account_uuid' => 'uuid-b', 'organization_uuid' => 'org-b',
                'email' => 'shared@example.com', 'alias' => null, 'organization_name' => 'Beta', 'display_name' => null,
                'created_at' => '2026-08-02T00:00:00+00:00', 'last_used_at' => null,
            ],
        ],
    ]);

    $resolution = $this->registry->resolve('shared@example.com');

    expect($resolution->status)->toBe(SelectorResolutionStatus::Ambiguous)
        ->and($resolution->candidates)->toHaveCount(2);
});

it('reports not found for a query matching nothing', function () {
    expect($this->registry->resolve('nope-not-here')->status)->toBe(SelectorResolutionStatus::NotFound);
});

it('resolveMany resolves every selector independently and reports whether all resolved', function () {
    $batch = $this->registry->resolveMany(['work', 'nope-not-here']);

    expect($batch->allResolved())->toBeFalse()
        ->and($batch->bySelector['work']->status)->toBe(SelectorResolutionStatus::Found)
        ->and($batch->bySelector['nope-not-here']->status)->toBe(SelectorResolutionStatus::NotFound);
});

it('resolveMany reports allResolved true and returns resolved keys when every selector matches', function () {
    $batch = $this->registry->resolveMany(['work', 'uuid-b::org-b']);

    expect($batch->allResolved())->toBeTrue()
        ->and($batch->resolvedKeys())->toBe(['uuid-a::org-a', 'uuid-b::org-b']);
});
