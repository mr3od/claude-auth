<?php

use App\Services\Exceptions\BatchImportAliasNotAllowedException;
use App\Services\Registry;
use Tests\Feature\Concerns\UsesFakeClaudeHome;

uses(UsesFakeClaudeHome::class);

beforeEach(function () {
    $this->useFakeClaudeHome();
    $this->registry = new Registry($this->fakeHome, $this->fakeCredentialsFile, $this->fakeClaudeJsonFile, maxBackups: 5);
});

afterEach(fn () => $this->cleanupFakeClaudeHome());

it('imports a single snapshot file', function () {
    $source = $this->fakeBaseDir.'/some-key.snapshot.json';
    file_put_contents($source, json_encode([
        'schema_version' => 1, 'account_key' => 'imp-uuid::imp-org', 'captured_at' => '2026-08-01T00:00:00+00:00',
        'credentials' => ['claudeAiOauth' => ['accessToken' => 'imported-token']],
        'oauth_account' => ['accountUuid' => 'imp-uuid', 'organizationUuid' => 'imp-org', 'emailAddress' => 'imp@example.com'],
    ]));

    $records = $this->registry->importPath($source, alias: 'imported');

    expect($records)->toHaveCount(1)
        ->and($records[0]->accountKey)->toBe('imp-uuid::imp-org')
        ->and($records[0]->alias)->toBe('imported')
        ->and($this->registry->listAccounts()->accounts)->toHaveCount(1);
});

it('imports a directory containing a raw .credentials.json + .claude.json pair as one account', function () {
    $dir = $this->fakeBaseDir.'/raw-config';
    mkdir($dir, recursive: true);
    file_put_contents($dir.'/.credentials.json', json_encode(['claudeAiOauth' => ['accessToken' => 'raw-token']]));
    file_put_contents($dir.'/.claude.json', json_encode(['oauthAccount' => ['accountUuid' => 'raw-uuid', 'organizationUuid' => 'raw-org', 'emailAddress' => 'raw@example.com']]));

    $records = $this->registry->importPath($dir);

    expect($records)->toHaveCount(1)
        ->and($records[0]->accountKey)->toBe('raw-uuid::raw-org');
});

it('imports a directory of snapshot files as multiple accounts', function () {
    $dir = $this->fakeBaseDir.'/many-snapshots';
    mkdir($dir, recursive: true);
    foreach (['x', 'y'] as $i => $suffix) {
        file_put_contents($dir."/{$suffix}.snapshot.json", json_encode([
            'schema_version' => 1, 'account_key' => "uuid-{$suffix}::org-{$suffix}", 'captured_at' => '2026-08-01T00:00:00+00:00',
            'credentials' => ['claudeAiOauth' => ['accessToken' => "token-{$suffix}"]],
            'oauth_account' => ['accountUuid' => "uuid-{$suffix}", 'organizationUuid' => "org-{$suffix}", 'emailAddress' => "{$suffix}@example.com"],
        ]));
    }

    $records = $this->registry->importPath($dir);

    expect($records)->toHaveCount(2)
        ->and($this->registry->listAccounts()->accounts)->toHaveCount(2);
});

it('skips a corrupt snapshot file in a directory import rather than crashing the whole import', function () {
    $dir = $this->fakeBaseDir.'/many-snapshots';
    mkdir($dir, recursive: true);
    file_put_contents($dir.'/good.snapshot.json', json_encode([
        'schema_version' => 1, 'account_key' => 'uuid-x::org-x', 'captured_at' => '2026-08-01T00:00:00+00:00',
        'credentials' => ['claudeAiOauth' => ['accessToken' => 'token-x']],
        'oauth_account' => ['accountUuid' => 'uuid-x', 'organizationUuid' => 'org-x', 'emailAddress' => 'x@example.com'],
    ]));
    file_put_contents($dir.'/corrupt.snapshot.json', json_encode([
        'schema_version' => 1, 'account_key' => 'uuid-y::org-y',
        // missing captured_at, credentials, oauth_account
    ]));

    $records = $this->registry->importPath($dir);

    expect($records)->toHaveCount(1)
        ->and($records[0]->accountKey)->toBe('uuid-x::org-x');
});

it('rejects --alias when importing a directory of multiple snapshots', function () {
    $dir = $this->fakeBaseDir.'/many-snapshots';
    mkdir($dir, recursive: true);
    file_put_contents($dir.'/a.snapshot.json', json_encode([
        'schema_version' => 1, 'account_key' => 'uuid-a::org-a', 'captured_at' => 't',
        'credentials' => [], 'oauth_account' => ['accountUuid' => 'uuid-a'],
    ]));
    file_put_contents($dir.'/b.snapshot.json', json_encode([
        'schema_version' => 1, 'account_key' => 'uuid-b::org-b', 'captured_at' => 't',
        'credentials' => [], 'oauth_account' => ['accountUuid' => 'uuid-b'],
    ]));

    $this->registry->importPath($dir, alias: 'nope');
})->throws(BatchImportAliasNotAllowedException::class);

it('exports every stored snapshot to the given directory', function () {
    $this->seedFakeAccountPair();
    $dest = $this->fakeBaseDir.'/export-dest';

    $written = $this->registry->exportSnapshots($dest);

    expect($written)->toHaveCount(2);
    foreach ($written as $file) {
        expect(is_file($file))->toBeTrue();
    }
});

it('exports to the backups folder by default', function () {
    $this->seedFakeAccountPair();

    $written = $this->registry->exportSnapshots();

    expect($written)->toHaveCount(2)
        ->and($written[0])->toContain($this->fakeHome.'/backups');
});

it('purge rebuilds the registry from on-disk snapshot files alone', function () {
    $this->seedFakeAccountPair(); // seeds registry.json + 2 snapshot files
    unlink($this->fakeHome.'/registry.json'); // simulate a corrupted/missing registry

    $result = $this->registry->importPurge();

    expect($result->accountsFound)->toBe(2)
        ->and($this->registry->listAccounts()->accounts)->toHaveCount(2);
});

it('purge preserves aliases from the old registry when it is still readable', function () {
    $this->seedFakeAccountPair();
    $this->registry->setAlias('uuid-a::org-a', 'work');

    $result = $this->registry->importPurge();

    expect($result->aliasesPreserved)->toBe(1);

    $accounts = $this->registry->listAccounts()->accounts;
    $workAccount = array_values(array_filter($accounts, fn ($a) => $a->accountKey === 'uuid-a::org-a'))[0];
    expect($workAccount->alias)->toBe('work');
});

it('purge best-effort imports the current live account if readable and not already tracked', function () {
    $this->seedFakeCredentialsFile();
    $this->seedFakeClaudeJsonFile(); // fake-account-uuid::fake-org-uuid, not among the accounts below

    $result = $this->registry->importPurge();

    expect($result->liveAccountImported)->toBeTrue()
        ->and($result->activeAccountKey)->toBe('fake-account-uuid::fake-org-uuid')
        ->and($this->registry->listAccounts()->accounts)->toHaveCount(1);
});

it('purge skips a corrupt snapshot file rather than crashing the whole purge', function () {
    $this->seedFakeAccountPair(); // 2 good snapshot files
    file_put_contents($this->fakeHome.'/accounts/corrupt.snapshot.json', json_encode([
        'schema_version' => 1, 'account_key' => 'uuid-z::org-z',
        // missing captured_at, credentials, oauth_account
    ]));

    $result = $this->registry->importPurge();

    expect($result->accountsFound)->toBe(2);
});

it('purge succeeds with zero accounts when there are no snapshots and no readable live files', function () {
    $result = $this->registry->importPurge();

    expect($result->accountsFound)->toBe(0)
        ->and($result->liveAccountImported)->toBeFalse()
        ->and($result->activeAccountKey)->toBeNull();
});
