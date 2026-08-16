<?php

use Tests\Feature\Concerns\UsesFakeClaudeHome;

uses(UsesFakeClaudeHome::class);

beforeEach(fn () => $this->useFakeClaudeHome());
afterEach(fn () => $this->cleanupFakeClaudeHome());

it('imports a single snapshot file via the command', function () {
    $source = $this->fakeBaseDir.'/x.snapshot.json';
    file_put_contents($source, json_encode([
        'schema_version' => 1, 'account_key' => 'uuid-x::org-x', 'captured_at' => 't',
        'credentials' => [], 'oauth_account' => ['accountUuid' => 'uuid-x', 'emailAddress' => 'x@example.com'],
    ]));

    $this->artisan('import', ['path' => $source])
        ->expectsOutputToContain('Imported')
        ->assertExitCode(0);
});

it('rejects --purge combined with a path as a usage error', function () {
    $this->artisan('import', ['path' => 'somewhere', '--purge' => true])->assertExitCode(2);
});

it('rejects a missing path with no --purge as a usage error', function () {
    $this->artisan('import')->assertExitCode(2);
});

it('rebuilds the registry via --purge', function () {
    $this->seedFakeAccountPair();
    unlink($this->fakeHome.'/registry.json');

    $this->artisan('import', ['--purge' => true])
        ->expectsOutputToContain('2 account(s) found')
        ->assertExitCode(0);
});

it('reports a failure without crashing when the import path cannot be read', function () {
    $this->artisan('import', ['path' => $this->fakeBaseDir.'/does-not-exist.snapshot.json'])
        ->assertExitCode(1);
});
