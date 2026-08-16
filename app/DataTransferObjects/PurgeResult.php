<?php

namespace App\DataTransferObjects;

final readonly class PurgeResult
{
    public function __construct(
        public int $accountsFound,
        public int $aliasesPreserved,
        public bool $liveAccountImported,
        public ?string $activeAccountKey,
    ) {}
}
