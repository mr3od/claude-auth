<?php

use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Concerns\UsesFakeClaudeHome;

uses(UsesFakeClaudeHome::class);

beforeEach(function () {
    $this->useFakeClaudeHome();
    $this->seedFakeRegistryFile();
});

afterEach(fn () => $this->cleanupFakeClaudeHome());

it('sets an alias via the command', function () {
    $this->artisan('alias', ['action' => 'set', 'selector' => 'uuid-b::org-b', 'alias' => 'personal'])
        ->expectsOutputToContain('Alias set to "personal"')
        ->assertExitCode(0);
});

it('clears an alias via the command', function () {
    $this->artisan('alias', ['action' => 'clear', 'selector' => 'work'])
        ->expectsOutputToContain('Alias cleared')
        ->assertExitCode(0);
});

it('rejects an unknown action with a usage error', function () {
    $this->artisan('alias', ['action' => 'delete', 'selector' => 'work'])
        ->assertExitCode(2);
});

it('rejects "set" without a new alias', function () {
    $this->artisan('alias', ['action' => 'set', 'selector' => 'work'])
        ->assertExitCode(2);
});

it('reports a clear error when the selector matches no account', function () {
    $this->artisan('alias', ['action' => 'clear', 'selector' => 'nope'])
        ->expectsOutputToContain('No account matches')
        ->assertExitCode(1);
});

it('lists candidates when the selector is ambiguous', function () {
    // "example.com" is a substring of both seeded accounts' emails - genuinely ambiguous,
    // unlike a bare out-of-range row number, which always resolves as NotFound.
    Artisan::call('alias', ['action' => 'set', 'selector' => 'example.com', 'alias' => 'x']);

    expect(Artisan::output())
        ->toContain('a@example.com')
        ->toContain('b@example.com');
});
