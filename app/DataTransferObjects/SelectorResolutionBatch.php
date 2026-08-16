<?php

namespace App\DataTransferObjects;

final readonly class SelectorResolutionBatch
{
    /**
     * @param  array<string, SelectorResolution>  $bySelector
     */
    public function __construct(
        public array $bySelector,
    ) {}

    public function allResolved(): bool
    {
        foreach ($this->bySelector as $resolution) {
            if ($resolution->status !== SelectorResolutionStatus::Found) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string[]
     */
    public function resolvedKeys(): array
    {
        return array_values(array_map(
            fn (SelectorResolution $resolution) => $resolution->match->accountKey,
            array_filter($this->bySelector, fn (SelectorResolution $r) => $r->status === SelectorResolutionStatus::Found),
        ));
    }
}
