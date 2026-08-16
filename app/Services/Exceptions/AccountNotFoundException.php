<?php

namespace App\Services\Exceptions;

final class AccountNotFoundException extends \RuntimeException
{
    public function __construct(public readonly string $selector)
    {
        parent::__construct("No account matches \"{$selector}\".");
    }
}
