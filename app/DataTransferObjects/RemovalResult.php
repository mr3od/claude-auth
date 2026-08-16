<?php

namespace App\DataTransferObjects;

final readonly class RemovalResult
{
    /**
     * @param  string[]  $removedKeys
     */
    public function __construct(
        public array $removedKeys,
        public ?string $newActiveAccountKey,
    ) {}
}
