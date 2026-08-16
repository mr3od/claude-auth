<?php

namespace Tests\Feature\Concerns;

trait UsesFakeClaudeHome
{
    protected string $fakeBaseDir;

    protected string $fakeHome;

    protected string $fakeCredentialsFile;

    protected string $fakeClaudeJsonFile;

    protected function useFakeClaudeHome(): void
    {
        $this->fakeBaseDir = sys_get_temp_dir().'/claude-auth-test-'.bin2hex(random_bytes(6));
        $this->fakeHome = $this->fakeBaseDir.'/registry';
        $this->fakeCredentialsFile = $this->fakeBaseDir.'/credentials.json';
        $this->fakeClaudeJsonFile = $this->fakeBaseDir.'/claude.json';

        mkdir($this->fakeHome, recursive: true);

        config([
            'claude-auth.home' => $this->fakeHome,
            'claude-auth.claude_credentials_file' => $this->fakeCredentialsFile,
            'claude-auth.claude_json_file' => $this->fakeClaudeJsonFile,
            'claude-auth.max_backups' => 5,
        ]);
    }

    protected function cleanupFakeClaudeHome(): void
    {
        if (isset($this->fakeBaseDir) && is_dir($this->fakeBaseDir)) {
            $this->deleteDirectory($this->fakeBaseDir);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function seedFakeCredentialsFile(array $overrides = []): void
    {
        file_put_contents($this->fakeCredentialsFile, json_encode(array_replace_recursive([
            'claudeAiOauth' => [
                'accessToken' => 'fake-access-token',
                'refreshToken' => 'fake-refresh-token',
                'expiresAt' => 1000,
                'refreshTokenExpiresAt' => 2000,
                'scopes' => ['user:profile', 'user:inference'],
                'subscriptionType' => 'pro',
                'rateLimitTier' => 'default',
            ],
        ], $overrides), JSON_PRETTY_PRINT));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function seedFakeClaudeJsonFile(array $overrides = []): void
    {
        file_put_contents($this->fakeClaudeJsonFile, json_encode(array_replace_recursive([
            'numStartups' => 3,
            'tipsHistory' => ['tip-1', 'tip-2'],
            'projects' => ['/home/user/some-project' => ['history' => []]],
            'oauthAccount' => [
                'accountUuid' => 'fake-account-uuid',
                'emailAddress' => 'fake@example.com',
                'organizationUuid' => 'fake-org-uuid',
                'organizationName' => 'Fake Org',
                'displayName' => 'Fake User',
            ],
        ], $overrides), JSON_PRETTY_PRINT));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function seedFakeRegistryFile(array $overrides = []): void
    {
        file_put_contents($this->fakeHome.'/registry.json', json_encode(array_replace_recursive([
            'schema_version' => 1,
            'active_account_key' => 'uuid-a::org-a',
            'previous_active_account_key' => null,
            'active_account_activated_at' => '2026-08-01T00:00:00+00:00',
            'accounts' => [
                [
                    'account_key' => 'uuid-a::org-a',
                    'account_uuid' => 'uuid-a',
                    'organization_uuid' => 'org-a',
                    'email' => 'a@example.com',
                    'alias' => 'work',
                    'organization_name' => 'Acme',
                    'display_name' => 'A User',
                    'created_at' => '2026-08-01T00:00:00+00:00',
                    'last_used_at' => '2026-08-01T00:00:00+00:00',
                ],
                [
                    'account_key' => 'uuid-b::org-b',
                    'account_uuid' => 'uuid-b',
                    'organization_uuid' => 'org-b',
                    'email' => 'b@example.com',
                    'alias' => null,
                    'organization_name' => 'Beta Inc',
                    'display_name' => 'B User',
                    'created_at' => '2026-08-02T00:00:00+00:00',
                    'last_used_at' => null,
                ],
            ],
        ], $overrides), JSON_PRETTY_PRINT));
    }

    private function deleteDirectory(string $dir): void
    {
        $items = scandir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
