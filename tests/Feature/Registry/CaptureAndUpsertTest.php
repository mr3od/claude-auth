<?php

use App\Services\Exceptions\UnsupportedPlatformException;
use App\Services\Registry;
use Tests\Feature\Concerns\UsesFakeClaudeHome;

uses(UsesFakeClaudeHome::class);

beforeEach(function () {
    $this->useFakeClaudeHome();
    $this->registry = new Registry($this->fakeHome, $this->fakeCredentialsFile, $this->fakeClaudeJsonFile, maxBackups: 5);
    $this->scratchDir = $this->fakeBaseDir.'/scratch';
    mkdir($this->scratchDir, recursive: true);
    file_put_contents($this->scratchDir.'/.credentials.json', json_encode([
        'claudeAiOauth' => ['accessToken' => 'scratch-token'],
    ]));
    file_put_contents($this->scratchDir.'/.claude.json', json_encode([
        'oauthAccount' => [
            'accountUuid' => 'new-uuid', 'organizationUuid' => 'new-org',
            'emailAddress' => 'new@example.com', 'organizationName' => 'New Org', 'displayName' => 'New User',
        ],
    ]));
});

afterEach(fn () => $this->cleanupFakeClaudeHome());

it('captures a snapshot from a pair of files, deriving account_key from accountUuid::organizationUuid', function () {
    $snapshot = $this->registry->captureFromPaths($this->scratchDir.'/.credentials.json', $this->scratchDir.'/.claude.json');

    expect($snapshot->accountKey)->toBe('new-uuid::new-org')
        ->and($snapshot->credentials->claudeAiOauth->accessToken)->toBe('scratch-token')
        ->and($snapshot->oauthAccount['emailAddress'])->toBe('new@example.com');
});

it('captures credentials.json without collapsing empty JSON objects into arrays', function () {
    file_put_contents($this->scratchDir.'/.credentials.json', json_encode([
        'claudeAiOauth' => ['accessToken' => 'scratch-token', 'extra' => new stdClass],
    ]));

    $snapshot = $this->registry->captureFromPaths($this->scratchDir.'/.credentials.json', $this->scratchDir.'/.claude.json');

    expect($snapshot->credentials->claudeAiOauth->extra)->toEqual(new stdClass);
});

it('captures oauthAccount without collapsing a nested empty JSON object into an array', function () {
    file_put_contents($this->scratchDir.'/.claude.json', json_encode([
        'oauthAccount' => [
            'accountUuid' => 'new-uuid', 'organizationUuid' => 'new-org',
            'emailAddress' => 'new@example.com', 'extra' => new stdClass,
        ],
    ]));

    $snapshot = $this->registry->captureFromPaths($this->scratchDir.'/.credentials.json', $this->scratchDir.'/.claude.json');

    expect($snapshot->oauthAccount['extra'])->toEqual(new stdClass);
});

it('falls back to accountUuid alone when organizationUuid is absent', function () {
    file_put_contents($this->scratchDir.'/.claude.json', json_encode([
        'oauthAccount' => ['accountUuid' => 'solo-uuid', 'emailAddress' => 'solo@example.com'],
    ]));

    $snapshot = $this->registry->captureFromPaths($this->scratchDir.'/.credentials.json', $this->scratchDir.'/.claude.json');

    expect($snapshot->accountKey)->toBe('solo-uuid');
});

it('captureCurrentAccount reads the configured live paths', function () {
    $this->seedFakeCredentialsFile();
    $this->seedFakeClaudeJsonFile();

    $snapshot = $this->registry->captureCurrentAccount();

    expect($snapshot->accountKey)->toBe('fake-account-uuid::fake-org-uuid');
});

it('refuses to captureCurrentAccount on an unsupported platform', function () {
    $this->seedFakeCredentialsFile();
    $this->seedFakeClaudeJsonFile();
    $registry = new Registry(
        $this->fakeHome, $this->fakeCredentialsFile, $this->fakeClaudeJsonFile,
        maxBackups: 5, osFamily: 'Windows',
    );

    $registry->captureCurrentAccount();
})->throws(UnsupportedPlatformException::class);

it('upsert writes a new snapshot file and adds a new registry entry', function () {
    $snapshot = $this->registry->captureFromPaths($this->scratchDir.'/.credentials.json', $this->scratchDir.'/.claude.json');

    $record = $this->registry->upsert($snapshot);

    expect($record->accountKey)->toBe('new-uuid::new-org')
        ->and($record->email)->toBe('new@example.com')
        ->and($this->registry->listAccounts()->accounts)->toHaveCount(1)
        ->and(glob($this->fakeHome.'/accounts/*.snapshot.json'))->toHaveCount(1);
});

it('upsert assigns the given alias on a new account', function () {
    $snapshot = $this->registry->captureFromPaths($this->scratchDir.'/.credentials.json', $this->scratchDir.'/.claude.json');

    $record = $this->registry->upsert($snapshot, alias: 'personal');

    expect($record->alias)->toBe('personal');
});

it('upsert updates an existing account in place rather than duplicating it', function () {
    $snapshot = $this->registry->captureFromPaths($this->scratchDir.'/.credentials.json', $this->scratchDir.'/.claude.json');
    $this->registry->upsert($snapshot, alias: 'personal');

    // Re-login for the same account: display name changed, credentials refreshed.
    file_put_contents($this->scratchDir.'/.claude.json', json_encode([
        'oauthAccount' => [
            'accountUuid' => 'new-uuid', 'organizationUuid' => 'new-org',
            'emailAddress' => 'new@example.com', 'organizationName' => 'New Org', 'displayName' => 'Renamed User',
        ],
    ]));
    $refreshed = $this->registry->captureFromPaths($this->scratchDir.'/.credentials.json', $this->scratchDir.'/.claude.json');

    $record = $this->registry->upsert($refreshed);

    expect($this->registry->listAccounts()->accounts)->toHaveCount(1)
        ->and($record->displayName)->toBe('Renamed User')
        ->and($record->alias)->toBe('personal'); // preserved, not overwritten by a null alias
});
