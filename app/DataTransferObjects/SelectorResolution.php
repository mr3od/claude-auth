<?php

namespace App\DataTransferObjects;

final readonly class SelectorResolution
{
    /**
     * @param  AccountRecord[]  $candidates
     */
    public function __construct(
        public SelectorResolutionStatus $status,
        public ?AccountRecord $match = null,
        public array $candidates = [],
    ) {}
}
