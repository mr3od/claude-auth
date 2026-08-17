<?php

namespace App\DataTransferObjects;

final readonly class AccountSnapshot
{
    /**
     * @param  mixed  $credentials  byte-shape-identical to the live .credentials.json file - an
     *                               object/array mix straight from json_decode(), not normalized,
     *                               so an empty JSON object never gets flattened into an empty array
     * @param  array<string, mixed>  $oauthAccount  byte-shape-identical to ~/.claude.json's oauthAccount key
     */
    public function __construct(
        public string $accountKey,
        public mixed $credentials,
        public array $oauthAccount,
        public string $capturedAt,
    ) {}

    /**
     * @param  array<string, mixed>|object  $decoded  an array when read via readJsonOrNull()
     *         (e.g. constructed by hand in a test), an object when read via
     *         readJsonPreservingTypes() (the real read path, which is what preserves
     *         $credentials's object/array shape)
     */
    public static function fromSnapshotFile(array|object $decoded): self
    {
        $decoded = (object) $decoded;

        return new self(
            accountKey: $decoded->account_key,
            credentials: $decoded->credentials,
            oauthAccount: (array) $decoded->oauth_account,
            capturedAt: $decoded->captured_at,
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
