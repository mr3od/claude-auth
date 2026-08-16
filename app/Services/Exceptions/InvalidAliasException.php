<?php

namespace App\Services\Exceptions;

final class InvalidAliasException extends \RuntimeException
{
    public function __construct(
        public readonly string $alias,
        public readonly string $reason,
    ) {
        parent::__construct(match ($reason) {
            'empty' => 'Alias cannot be empty.',
            'all_digit' => "Alias \"{$alias}\" cannot be all digits (that would collide with row-number selectors).",
            'control_characters' => "Alias \"{$alias}\" cannot contain control characters.",
            'duplicate' => "Alias \"{$alias}\" is already used by another account.",
            default => "Alias \"{$alias}\" is invalid.",
        });
    }
}
