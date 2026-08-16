<?php

use Tests\Feature\Concerns\UsesFakeClaudeHome;

uses(UsesFakeClaudeHome::class);

beforeEach(fn () => $this->useFakeClaudeHome());
afterEach(fn () => $this->cleanupFakeClaudeHome());

it('points claude-auth config at an isolated temp directory', function () {
    expect($this->fakeHome)->toContain(sys_get_temp_dir())
        ->and(config('claude-auth.home'))->toBe($this->fakeHome)
        ->and(config('claude-auth.claude_credentials_file'))->toBe($this->fakeCredentialsFile)
        ->and(config('claude-auth.claude_json_file'))->toBe($this->fakeClaudeJsonFile);
});

it('seeds fixture credentials and claude.json files that round-trip through json_decode', function () {
    $this->seedFakeCredentialsFile();
    $this->seedFakeClaudeJsonFile();

    $credentials = json_decode(file_get_contents($this->fakeCredentialsFile), true);
    $claudeJson = json_decode(file_get_contents($this->fakeClaudeJsonFile), true);

    expect($credentials['claudeAiOauth']['accessToken'])->toBe('fake-access-token')
        ->and($claudeJson['oauthAccount']['accountUuid'])->toBe('fake-account-uuid')
        ->and($claudeJson['numStartups'])->toBe(3);
});

it('deletes the temp directory on cleanup', function () {
    $dir = $this->fakeBaseDir;
    $this->seedFakeCredentialsFile();

    expect(is_dir($dir))->toBeTrue();

    $this->cleanupFakeClaudeHome();

    expect(is_dir($dir))->toBeFalse();
});
