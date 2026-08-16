<?php

use App\Services\Registry;
use Tests\Feature\Concerns\UsesFakeClaudeHome;

uses(UsesFakeClaudeHome::class);

beforeEach(function () {
    $this->useFakeClaudeHome();
    $this->registry = new Registry($this->fakeHome, $this->fakeCredentialsFile, $this->fakeClaudeJsonFile, maxBackups: 2);
});

afterEach(fn () => $this->cleanupFakeClaudeHome());

it('prunes backups across registry.json, credentials.json, and claude.json together', function () {
    mkdir($this->fakeHome.'/backups', recursive: true);
    foreach (['registry.json', 'credentials.json', 'claude.json'] as $base) {
        foreach (range(1, 3) as $n) {
            file_put_contents("{$this->fakeHome}/backups/{$base}.bak.2026080{$n}-000000", 'x');
        }
    }

    $deleted = $this->registry->pruneBackups();

    expect($deleted)->toHaveCount(3) // one excess per base name, maxBackups: 2
        ->and(glob($this->fakeHome.'/backups/registry.json.bak.*'))->toHaveCount(2)
        ->and(glob($this->fakeHome.'/backups/credentials.json.bak.*'))->toHaveCount(2)
        ->and(glob($this->fakeHome.'/backups/claude.json.bak.*'))->toHaveCount(2);
});

it('deletes snapshot files no longer referenced by the registry', function () {
    $this->seedFakeAccountPair(); // registry.json + 2 snapshot files

    $this->registry->remove(['uuid-a::org-a']); // registry entry gone, snapshot file becomes orphaned

    $deleted = $this->registry->pruneOrphanedSnapshots();

    expect($deleted)->toHaveCount(1)
        ->and(glob($this->fakeHome.'/accounts/*.snapshot.json'))->toHaveCount(1);
});

it('does nothing when registry.json is missing, so orphaned snapshots stay available for --purge', function () {
    $this->seedFakeAccountPair();
    unlink($this->fakeHome.'/registry.json');

    $deleted = $this->registry->pruneOrphanedSnapshots();

    expect($deleted)->toBe([])
        ->and(glob($this->fakeHome.'/accounts/*.snapshot.json'))->toHaveCount(2);
});

it('keeps every snapshot file still referenced by the registry', function () {
    $this->seedFakeAccountPair();

    $deleted = $this->registry->pruneOrphanedSnapshots();

    expect($deleted)->toBe([])
        ->and(glob($this->fakeHome.'/accounts/*.snapshot.json'))->toHaveCount(2);
});
