<?php

use App\Services\Exceptions\ScratchLoginFailedException;
use App\Services\Exceptions\UnsupportedPlatformException;
use App\Services\ScratchLoginRunner;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\Feature\Concerns\InspectsFakeProcesses;

uses(InspectsFakeProcesses::class);

it('spawns "claude auth login" with CLAUDE_CONFIG_DIR scoped to a scratch dir and returns its file paths', function () {
    $capturedScratchDir = null;

    Process::fake(function (PendingProcess $process) use (&$capturedScratchDir) {
        $env = $this->environmentOf($process);
        $capturedScratchDir = $env['CLAUDE_CONFIG_DIR'];

        expect($process->command)->toBe(['claude', 'auth', 'login']);
        expect(is_dir($capturedScratchDir))->toBeTrue();

        file_put_contents($capturedScratchDir.'/.credentials.json', json_encode(['claudeAiOauth' => ['accessToken' => 'new']]));
        file_put_contents($capturedScratchDir.'/.claude.json', json_encode(['oauthAccount' => ['accountUuid' => 'new-uuid']]));

        return Process::result(output: 'Login successful.', exitCode: 0);
    });

    $runner = new ScratchLoginRunner;
    $result = $runner->run(onOutput: fn () => null);

    expect($result->credentialsPath)->toBe($capturedScratchDir.'/.credentials.json')
        ->and($result->claudeJsonPath)->toBe($capturedScratchDir.'/.claude.json')
        ->and(json_decode(file_get_contents($result->credentialsPath), true)['claudeAiOauth']['accessToken'])->toBe('new');

    expect(is_dir($capturedScratchDir))->toBeTrue(); // not cleaned up until the caller calls cleanup()
    ($result->cleanup)();
    expect(is_dir($capturedScratchDir))->toBeFalse();
});

it('passes --email, --console, and --sso through to the subprocess command', function () {
    Process::fake(function (PendingProcess $process) {
        expect($process->command)->toBe(['claude', 'auth', 'login', '--email', 'x@example.com', '--console', '--sso']);

        return Process::result(exitCode: 0);
    });

    $result = (new ScratchLoginRunner)->run(email: 'x@example.com', console: true, sso: true, onOutput: fn () => null);
    ($result->cleanup)();
});

it('refuses to run on an unsupported platform', function () {
    (new ScratchLoginRunner(osFamily: 'Darwin'))->run(onOutput: fn () => null);
})->throws(UnsupportedPlatformException::class);

it('cleans up the scratch dir and throws on a failed login, never returning a result', function () {
    $capturedScratchDir = null;

    Process::fake(function (PendingProcess $process) use (&$capturedScratchDir) {
        $capturedScratchDir = $this->environmentOf($process)['CLAUDE_CONFIG_DIR'];

        return Process::result(errorOutput: 'Authentication was cancelled.', exitCode: 1);
    });

    try {
        (new ScratchLoginRunner)->run(onOutput: fn () => null);
        $this->fail('Expected ScratchLoginFailedException.');
    } catch (ScratchLoginFailedException $e) {
        expect($e->getMessage())->toContain('Authentication was cancelled.');
    }

    expect(is_dir($capturedScratchDir))->toBeFalse();
});
