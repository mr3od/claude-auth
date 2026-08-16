<?php

use Tests\Feature\Concerns\UsesFakeClaudeHome;

uses(UsesFakeClaudeHome::class);

beforeEach(fn () => $this->useFakeClaudeHome());
afterEach(fn () => $this->cleanupFakeClaudeHome());

it('reports nothing to export when there are no accounts', function () {
    $this->artisan('export')
        ->expectsOutputToContain('nothing to export')
        ->assertExitCode(0);
});

it('exports every stored account to the given directory', function () {
    $this->seedFakeAccountPair();
    $dest = $this->fakeBaseDir.'/dest';

    $this->artisan('export', ['dir' => $dest])
        ->expectsOutputToContain('Exported 2 account(s)')
        ->assertExitCode(0);

    expect(glob($dest.'/*.snapshot.json'))->toHaveCount(2);
});
