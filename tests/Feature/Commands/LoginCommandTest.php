<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\Feature\Concerns\InspectsFakeProcesses;
use Tests\Feature\Concerns\UsesFakeClaudeHome;

uses(UsesFakeClaudeHome::class, InspectsFakeProcesses::class);

beforeEach(fn () => $this->useFakeClaudeHome());
afterEach(fn () => $this->cleanupFakeClaudeHome());

it('stores the account returned by a successful login', function () {
    Process::fake(function (PendingProcess $process) {
        $scratchDir = $this->environmentOf($process)['CLAUDE_CONFIG_DIR'];

        file_put_contents($scratchDir.'/.credentials.json', json_encode([
            'claudeAiOauth' => ['accessToken' => 'brand-new-token'],
        ]));
        file_put_contents($scratchDir.'/.claude.json', json_encode([
            'oauthAccount' => [
                'accountUuid' => 'login-uuid', 'organizationUuid' => 'login-org',
                'emailAddress' => 'login@example.com', 'displayName' => 'Login User',
            ],
        ]));

        return Process::result(output: 'Login successful.', exitCode: 0);
    });

    $this->artisan('login')
        ->expectsOutputToContain('Stored account Login User')
        ->assertExitCode(0);

    $listing = json_decode(file_get_contents($this->fakeHome.'/registry.json'), true);
    expect($listing['accounts'])->toHaveCount(1)
        ->and($listing['accounts'][0]['account_key'])->toBe('login-uuid::login-org');
});

it('assigns the given --alias to the newly stored account', function () {
    Process::fake(function (PendingProcess $process) {
        $scratchDir = $this->environmentOf($process)['CLAUDE_CONFIG_DIR'];

        file_put_contents($scratchDir.'/.credentials.json', json_encode(['claudeAiOauth' => ['accessToken' => 't']]));
        file_put_contents($scratchDir.'/.claude.json', json_encode([
            'oauthAccount' => ['accountUuid' => 'login-uuid', 'organizationUuid' => 'login-org', 'emailAddress' => 'login@example.com'],
        ]));

        return Process::result(exitCode: 0);
    });

    $this->artisan('login', ['--alias' => 'personal'])->assertExitCode(0);

    $listing = json_decode(file_get_contents($this->fakeHome.'/registry.json'), true);
    expect($listing['accounts'][0]['alias'])->toBe('personal');
});

it('cleans up the scratch directory after a successful login', function () {
    $capturedScratchDir = null;

    Process::fake(function (PendingProcess $process) use (&$capturedScratchDir) {
        $capturedScratchDir = $this->environmentOf($process)['CLAUDE_CONFIG_DIR'];
        file_put_contents($capturedScratchDir.'/.credentials.json', json_encode(['claudeAiOauth' => ['accessToken' => 't']]));
        file_put_contents($capturedScratchDir.'/.claude.json', json_encode(['oauthAccount' => ['accountUuid' => 'u']]));

        return Process::result(exitCode: 0);
    });

    $this->artisan('login')->assertExitCode(0);

    expect(is_dir($capturedScratchDir))->toBeFalse();
});

it('reports a failed login without storing anything', function () {
    Process::fake(fn () => Process::result(errorOutput: 'User cancelled.', exitCode: 1));

    $this->artisan('login')
        ->expectsOutputToContain('Login failed')
        ->assertExitCode(1);

    expect(is_file($this->fakeHome.'/registry.json'))->toBeFalse();
});
