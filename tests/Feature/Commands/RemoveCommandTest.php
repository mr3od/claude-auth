<?php

use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Concerns\UsesFakeClaudeHome;

uses(UsesFakeClaudeHome::class);

beforeEach(function () {
    $this->useFakeClaudeHome();
    $this->seedFakeAccountPair();
});

afterEach(fn () => $this->cleanupFakeClaudeHome());

it('rejects no selectors and no --all as a usage error', function () {
    $this->artisan('remove')->assertExitCode(2);
});

it('rejects combining --all with explicit selectors as a usage error', function () {
    $this->artisan('remove', ['selectors' => ['work'], '--all' => true])->assertExitCode(2);
});

it('removes with --force and no prompt', function () {
    $this->artisan('remove', ['selectors' => ['work'], '--force' => true])
        ->expectsOutputToContain('Removed 1 account')
        ->assertExitCode(0);

    expect($this->fromRegistryJson()['accounts'])->toHaveCount(1);
});

it('prompts for confirmation and removes on yes', function () {
    $this->artisan('remove', ['selectors' => ['work']])
        ->expectsConfirmation('Remove these accounts?', 'yes')
        ->expectsOutputToContain('Removed 1 account')
        ->assertExitCode(0);

    expect($this->fromRegistryJson()['accounts'])->toHaveCount(1);
});

it('prompts for confirmation and does nothing on no', function () {
    $this->artisan('remove', ['selectors' => ['work']])
        ->expectsConfirmation('Remove these accounts?', 'no')
        ->expectsOutputToContain('cancelled')
        ->assertExitCode(0);

    expect($this->fromRegistryJson()['accounts'])->toHaveCount(2);
});

it('removes every account with --all --force', function () {
    $this->artisan('remove', ['--all' => true, '--force' => true])
        ->expectsOutputToContain('Removed 2 account')
        ->assertExitCode(0);

    expect($this->fromRegistryJson()['accounts'])->toHaveCount(0);
});

it('resolves nothing and mutates nothing when a selector is unresolvable', function () {
    $this->artisan('remove', ['selectors' => ['work', 'nope'], '--force' => true])
        ->expectsOutputToContain('Nothing was removed')
        ->assertExitCode(1);

    expect($this->fromRegistryJson()['accounts'])->toHaveCount(2);
});

it('reports each selector status under --json when resolution fails', function () {
    Artisan::call('remove', ['selectors' => ['work', 'nope'], '--force' => true, '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['error'])->toBe('selector_resolution_failed')
        ->and($decoded['selectors']['work'])->toBe('found')
        ->and($decoded['selectors']['nope'])->toBe('notfound');
});

it('never prompts under --json even without --force', function () {
    Artisan::call('remove', ['selectors' => ['work'], '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['removed'])->toBe(['uuid-a::org-a']);
});
