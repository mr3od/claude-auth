<?php

use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Concerns\UsesFakeClaudeHome;

uses(UsesFakeClaudeHome::class);

beforeEach(fn () => $this->useFakeClaudeHome());
afterEach(fn () => $this->cleanupFakeClaudeHome());

it('reports zero removed when there is nothing to clean', function () {
    $this->artisan('clean')
        ->expectsOutputToContain('0 old backup(s) removed, 0 orphaned snapshot(s) removed')
        ->assertExitCode(0);
});

it('removes orphaned snapshot files and reports the count', function () {
    $this->seedFakeAccountPair();
    Artisan::call('remove', ['selectors' => ['work'], '--force' => true]);

    $this->artisan('clean')
        ->expectsOutputToContain('1 orphaned snapshot(s) removed')
        ->assertExitCode(0);

    expect(glob($this->fakeHome.'/accounts/*.snapshot.json'))->toHaveCount(1);
});
