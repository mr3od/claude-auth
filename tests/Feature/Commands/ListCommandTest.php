<?php

use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Concerns\UsesFakeClaudeHome;

uses(UsesFakeClaudeHome::class);

beforeEach(fn () => $this->useFakeClaudeHome());
afterEach(fn () => $this->cleanupFakeClaudeHome());

it('tells the user no accounts are stored yet', function () {
    $this->artisan('accounts')
        ->expectsOutputToContain('No accounts stored yet')
        ->assertExitCode(0);
});

it('lists stored accounts as a table, marking the active one', function () {
    $this->seedFakeRegistryFile();

    Artisan::call('accounts');
    $output = Artisan::output();

    expect($output)->toContain('work')
        ->toContain('a@example.com')
        ->toContain('B User')
        ->toContain('b@example.com');
});

it('outputs a single JSON document with --json', function () {
    $this->seedFakeRegistryFile();

    Artisan::call('accounts', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['active_account_key'])->toBe('uuid-a::org-a')
        ->and($decoded['accounts'])->toHaveCount(2)
        ->and($decoded['accounts'][1]['account_key'])->toBe('uuid-b::org-b')
        ->and($decoded['accounts'][1]['number'])->toBe(2);
});
