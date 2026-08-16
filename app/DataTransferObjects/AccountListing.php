<?php

namespace App\DataTransferObjects;

final readonly class AccountListing
{
    /**
     * @param  AccountRecord[]  $accounts
     */
    public function __construct(
        public array $accounts,
        public ?string $activeAccountKey,
        public ?string $previousAccountKey,
    ) {}
}
