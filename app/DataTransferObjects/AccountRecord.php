<?php

namespace App\DataTransferObjects;

final readonly class AccountRecord
{
    public function __construct(
        public string $accountKey,
        public string $accountUuid,
        public string $organizationUuid,
        public string $email,
        public ?string $alias,
        public ?string $organizationName,
        public ?string $displayName,
        public string $createdAt,
        public ?string $lastUsedAt,
    ) {}

    public function displayLabel(): string
    {
        foreach ([$this->alias, $this->displayName, $this->email] as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return $candidate;
            }
        }

        return $this->accountKey;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            accountKey: $row['account_key'],
            accountUuid: $row['account_uuid'],
            organizationUuid: $row['organization_uuid'],
            email: $row['email'],
            alias: $row['alias'] ?? null,
            organizationName: $row['organization_name'] ?? null,
            displayName: $row['display_name'] ?? null,
            createdAt: $row['created_at'],
            lastUsedAt: $row['last_used_at'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'account_key' => $this->accountKey,
            'account_uuid' => $this->accountUuid,
            'organization_uuid' => $this->organizationUuid,
            'email' => $this->email,
            'alias' => $this->alias,
            'organization_name' => $this->organizationName,
            'display_name' => $this->displayName,
            'created_at' => $this->createdAt,
            'last_used_at' => $this->lastUsedAt,
        ];
    }
}
