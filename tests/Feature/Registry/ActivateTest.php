<?php

use App\Services\Exceptions\AccountNotFoundException;
use App\Services\Exceptions\NoPreviousAccountException;
use App\Services\Exceptions\RegistryCorruptException;
use App\Services\Exceptions\UnsupportedPlatformException;
use App\Services\Registry;
use Tests\Feature\Concerns\UsesFakeClaudeHome;

uses(UsesFakeClaudeHome::class);

beforeEach(function () {
    $this->useFakeClaudeHome();
    $this->registry = new Registry($this->fakeHome, $this->fakeCredentialsFile, $this->fakeClaudeJsonFile, maxBackups: 5);
});

afterEach(fn () => $this->cleanupFakeClaudeHome());

it('writes the target snapshot credentials over the live credentials file', function () {
    $this->seedFakeAccountPair();
    $this->seedFakeCredentialsFile(); // simulates account A currently live
    $this->seedFakeClaudeJsonFile();

    $this->registry->activate('uuid-b::org-b');

    $credentials = json_decode(file_get_contents($this->fakeCredentialsFile), true);
    expect($credentials['claudeAiOauth']['accessToken'])->toBe('token-b');
});

it('merges only the oauthAccount key into ~/.claude.json, leaving every other key byte-identical', function () {
    $this->seedFakeAccountPair();
    $this->seedFakeCredentialsFile();
    $this->seedFakeClaudeJsonFile();
    $before = json_decode(file_get_contents($this->fakeClaudeJsonFile), true);

    $this->registry->activate('uuid-b::org-b');

    $after = json_decode(file_get_contents($this->fakeClaudeJsonFile), true);

    $oauthAccountAfter = $after['oauthAccount'];
    unset($before['oauthAccount'], $after['oauthAccount']);
    expect($after)->toBe($before)
        ->and($oauthAccountAfter['accountUuid'])->toBe('uuid-b');
});

it('does not corrupt empty JSON objects elsewhere in ~/.claude.json into empty arrays', function () {
    $this->seedFakeAccountPair();
    $this->seedFakeCredentialsFile();
    file_put_contents($this->fakeClaudeJsonFile, json_encode([
        'oauthAccount' => ['accountUuid' => 'uuid-a'],
        'projects' => ['/repo' => ['mcpServers' => new stdClass]],
        'seenNotifications' => new stdClass,
    ]));

    $this->registry->activate('uuid-b::org-b');

    $after = json_decode(file_get_contents($this->fakeClaudeJsonFile));
    expect($after->projects->{'/repo'}->mcpServers)->toEqual(new stdClass)
        ->and($after->seenNotifications)->toEqual(new stdClass);
});

it('does not corrupt empty JSON objects in credentials.json across a capture-then-activate round trip', function () {
    $this->seedFakeAccountPair();
    file_put_contents($this->fakeCredentialsFile, json_encode([
        'claudeAiOauth' => ['accessToken' => 'token-a', 'extra' => new stdClass],
    ]));
    $this->seedFakeClaudeJsonFile();

    // Capture account A (with its empty-object field) as a fresh snapshot, the
    // same way `login`/`import` would, then activate account B, then switch
    // back to A - round-tripping the captured snapshot back onto the live file.
    $snapshot = $this->registry->captureFromPaths($this->fakeCredentialsFile, $this->fakeClaudeJsonFile);
    $this->registry->upsert($snapshot);
    $this->registry->activate('uuid-b::org-b');
    $this->registry->activate($snapshot->accountKey);

    $after = json_decode(file_get_contents($this->fakeCredentialsFile));
    expect($after->claudeAiOauth->extra)->toEqual(new stdClass);
});

it('preserves the live credentials file\'s pre-existing permission mode', function () {
    $this->seedFakeAccountPair();
    $this->seedFakeCredentialsFile();
    $this->seedFakeClaudeJsonFile();
    chmod($this->fakeCredentialsFile, 0644);

    $this->registry->activate('uuid-b::org-b');

    expect(substr(sprintf('%o', fileperms($this->fakeCredentialsFile)), -4))->toBe('0644');
});

it('backs up the live files before overwriting them, even on the very first switch', function () {
    $this->seedFakeAccountPair();
    $this->seedFakeCredentialsFile();
    $this->seedFakeClaudeJsonFile();

    $this->registry->activate('uuid-b::org-b');

    expect(glob($this->fakeHome.'/backups/credentials.json.bak.*'))->not->toBeEmpty()
        ->and(glob($this->fakeHome.'/backups/claude.json.bak.*'))->not->toBeEmpty();
});

it('updates active_account_key, previous_active_account_key, and last_used_at', function () {
    $this->seedFakeAccountPair();
    $this->seedFakeCredentialsFile();
    $this->seedFakeClaudeJsonFile();

    $updated = $this->registry->activate('uuid-b::org-b');

    $listing = $this->registry->listAccounts();

    expect($listing->activeAccountKey)->toBe('uuid-b::org-b')
        ->and($listing->previousAccountKey)->toBe('uuid-a::org-a')
        ->and($updated->lastUsedAt)->not->toBeNull();
});

it('throws AccountNotFoundException for a key not tracked in the registry', function () {
    $this->seedFakeRegistryFile();

    $this->registry->activate('does-not-exist');
})->throws(AccountNotFoundException::class);

it('throws RegistryCorruptException when the registry tracks an account whose snapshot file is missing', function () {
    $this->seedFakeRegistryFile();

    $this->registry->activate('uuid-a::org-a');
})->throws(RegistryCorruptException::class);

it('switch - activates the previous account', function () {
    $this->seedFakeAccountPair();
    $this->seedFakeCredentialsFile();
    $this->seedFakeClaudeJsonFile();

    $this->registry->activate('uuid-b::org-b');
    $backAgain = $this->registry->activatePrevious();

    expect($backAgain->accountKey)->toBe('uuid-a::org-a')
        ->and($this->registry->listAccounts()->activeAccountKey)->toBe('uuid-a::org-a')
        ->and($this->registry->listAccounts()->previousAccountKey)->toBe('uuid-b::org-b');
});

it('throws NoPreviousAccountException when there is nothing to switch back to', function () {
    $this->seedFakeAccountPair();
    $this->seedFakeCredentialsFile();
    $this->seedFakeClaudeJsonFile();

    $this->registry->activatePrevious();
})->throws(NoPreviousAccountException::class);

it('refuses to activate on an unsupported platform', function () {
    $this->seedFakeAccountPair();
    $this->seedFakeCredentialsFile();
    $this->seedFakeClaudeJsonFile();
    $registry = new Registry(
        $this->fakeHome, $this->fakeCredentialsFile, $this->fakeClaudeJsonFile,
        maxBackups: 5, osFamily: 'Darwin',
    );

    $registry->activate('uuid-b::org-b');
})->throws(UnsupportedPlatformException::class);
