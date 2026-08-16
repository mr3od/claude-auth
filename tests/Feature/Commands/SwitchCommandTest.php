<?php

use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Concerns\UsesFakeClaudeHome;

uses(UsesFakeClaudeHome::class);

beforeEach(function () {
    $this->useFakeClaudeHome();
    $this->seedFakeAccountPair();
    $this->seedFakeCredentialsFile();
    $this->seedFakeClaudeJsonFile();
});

afterEach(fn () => $this->cleanupFakeClaudeHome());

it('switches by row number', function () {
    $this->artisan('switch', ['query' => '2'])
        ->expectsOutputToContain('Switched to')
        ->assertExitCode(0);

    expect(json_decode(file_get_contents($this->fakeCredentialsFile), true)['claudeAiOauth']['accessToken'])->toBe('token-b');
});

it('switches by alias substring', function () {
    $this->artisan('switch', ['query' => 'work'])
        ->assertExitCode(0);

    expect(json_decode(file_get_contents($this->fakeCredentialsFile), true)['claudeAiOauth']['accessToken'])->toBe('token-a');
});

it('reports a handled error, not a crash, when nothing matches', function () {
    $this->artisan('switch', ['query' => 'nope'])
        ->expectsOutputToContain('No account matches')
        ->assertExitCode(1);
});

it('switches back to the previous account with "-"', function () {
    Artisan::call('switch', ['query' => '2']);
    Artisan::call('switch', ['query' => '-']);

    expect(json_decode(file_get_contents($this->fakeCredentialsFile), true)['claudeAiOauth']['accessToken'])->toBe('token-a');
});

it('reports a handled error for "-" when there is no previous account', function () {
    $this->artisan('switch', ['query' => '-'])
        ->expectsOutputToContain('No previous account')
        ->assertExitCode(1);
});

it('prompts interactively when no query is given', function () {
    $this->artisan('switch')
        ->expectsChoice(
            'Switch to which account?',
            'work (a@example.com)',
            ['work (a@example.com)', 'B User (b@example.com)'],
        )
        ->assertExitCode(0);

    expect(json_decode(file_get_contents($this->fakeCredentialsFile), true)['claudeAiOauth']['accessToken'])->toBe('token-a');
});

it('rejects a missing query combined with --json as a usage error', function () {
    $this->artisan('switch', ['--json' => true])
        ->assertExitCode(2);
});

it('offers an interactive picker when the selector is ambiguous', function () {
    $this->seedFakeRegistryFile([
        'accounts' => [
            ['account_key' => 'uuid-a::org-a', 'account_uuid' => 'uuid-a', 'organization_uuid' => 'org-a', 'email' => 'shared@x.com', 'alias' => 'work', 'organization_name' => null, 'display_name' => null, 'created_at' => 't', 'last_used_at' => null],
            ['account_key' => 'uuid-b::org-b', 'account_uuid' => 'uuid-b', 'organization_uuid' => 'org-b', 'email' => 'shared@x.com', 'alias' => null, 'organization_name' => null, 'display_name' => 'B User', 'created_at' => 't', 'last_used_at' => null],
        ],
    ]);

    $this->artisan('switch', ['query' => 'shared@x.com'])
        ->expectsChoice(
            'Multiple accounts match. Which one?',
            'B User (shared@x.com)',
            ['work (shared@x.com)', 'B User (shared@x.com)'],
        )
        ->assertExitCode(0);

    expect(json_decode(file_get_contents($this->fakeCredentialsFile), true)['claudeAiOauth']['accessToken'])->toBe('token-b');
});

it('returns a single ambiguous_query JSON document instead of guessing under --json', function () {
    $this->seedFakeRegistryFile([
        'accounts' => [
            ['account_key' => 'uuid-a::org-a', 'account_uuid' => 'uuid-a', 'organization_uuid' => 'org-a', 'email' => 'shared@x.com', 'alias' => 'work', 'organization_name' => null, 'display_name' => null, 'created_at' => 't', 'last_used_at' => null],
            ['account_key' => 'uuid-b::org-b', 'account_uuid' => 'uuid-b', 'organization_uuid' => 'org-b', 'email' => 'shared@x.com', 'alias' => null, 'organization_name' => null, 'display_name' => 'B User', 'created_at' => 't', 'last_used_at' => null],
        ],
    ]);

    $before = file_get_contents($this->fakeCredentialsFile);

    Artisan::call('switch', ['query' => 'shared@x.com', '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['error'])->toBe('ambiguous_query')
        ->and($decoded['candidates'])->toHaveCount(2);

    // Never guesses: the live file must be untouched.
    expect(file_get_contents($this->fakeCredentialsFile))->toBe($before);
});

it('outputs a switched_to JSON document on success with --json', function () {
    Artisan::call('switch', ['query' => '2', '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['switched_to']['account_key'])->toBe('uuid-b::org-b');
});
