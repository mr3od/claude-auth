<?php

namespace App\DataTransferObjects;

final readonly class AccountSnapshot
{
    /**
     * @param  array<string, mixed>  $credentials  byte-shape-identical to the live .credentials.json file
     * @param  array<string, mixed>  $oauthAccount  byte-shape-identical to ~/.claude.json's oauthAccount key
     */
    public function __construct(
        public string $accountKey,
        public array $credentials,
        public array $oauthAccount,
        public string $capturedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $decoded
     */
    public static function fromSnapshotFile(array $decoded): self
    {
        return new self(
            accountKey: $decoded['account_key'],
            credentials: $decoded['credentials'],
            oauthAccount: $decoded['oauth_account'],
            capturedAt: $decoded['captured_at'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toSnapshotFile(): array
    {
        return [
            'schema_version' => 1,
            'account_key' => $this->accountKey,
            'captured_at' => $this->capturedAt,
            'credentials' => $this->credentials,
            'oauth_account' => $this->oauthAccount,
        ];
    }
}
