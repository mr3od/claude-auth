<?php

namespace App\Services;

use App\DataTransferObjects\ScratchLoginResult;
use App\Services\Exceptions\ScratchLoginFailedException;
use App\Services\Exceptions\UnsupportedPlatformException;
use Illuminate\Support\Facades\Process;

final class ScratchLoginRunner
{
    public function __construct(
        private readonly string $osFamily = PHP_OS_FAMILY,
    ) {}

    /**
     * Runs "claude auth login" against an isolated scratch CLAUDE_CONFIG_DIR, never the
     * caller's real ~/.claude state, so a failed or partial login can never corrupt it.
     *
     * @param  callable(string $type, string $output): void|null  $onOutput  defaults to
     *                                                                       passing the subprocess's own stdout/stderr straight through, since the login
     *                                                                       flow prints a URL or device code the user must see immediately
     */
    public function run(
        ?string $email = null,
        bool $console = false,
        bool $sso = false,
        ?callable $onOutput = null,
    ): ScratchLoginResult {
        // Anthropic's own docs only document CLAUDE_CONFIG_DIR relocating .credentials.json "on
        // Linux or Windows" - on macOS, Claude Code stores credentials in the Keychain regardless,
        // so this scratch-dir isolation trick can't be trusted to avoid the real system Keychain.
        if ($this->osFamily !== 'Linux') {
            throw new UnsupportedPlatformException($this->osFamily);
        }

        $scratchDir = sys_get_temp_dir().'/claude-auth-login-'.bin2hex(random_bytes(6));
        mkdir($scratchDir, 0700, recursive: true);

        $cleanup = function () use ($scratchDir) {
            if (is_dir($scratchDir)) {
                $this->deleteDirectory($scratchDir);
            }
        };

        $command = ['claude', 'auth', 'login'];
        if ($email !== null) {
            $command[] = '--email';
            $command[] = $email;
        }
        if ($console) {
            $command[] = '--console';
        }
        if ($sso) {
            $command[] = '--sso';
        }

        $result = Process::forever()
            ->env(['CLAUDE_CONFIG_DIR' => $scratchDir])
            ->run($command, $onOutput ?? function (string $type, string $output) {
                fwrite($type === 'err' ? STDERR : STDOUT, $output);
            });

        if ($result->failed()) {
            $detail = trim($result->errorOutput()) !== '' ? $result->errorOutput() : $result->output();
            $cleanup();

            throw new ScratchLoginFailedException(trim($detail));
        }

        return new ScratchLoginResult(
            credentialsPath: $scratchDir.'/.credentials.json',
            claudeJsonPath: $scratchDir.'/.claude.json',
            cleanup: $cleanup,
        );
    }

    private function deleteDirectory(string $dir): void
    {
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
