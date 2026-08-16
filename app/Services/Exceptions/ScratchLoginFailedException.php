<?php

namespace App\Services\Exceptions;

final class ScratchLoginFailedException extends \RuntimeException
{
    public function __construct(string $detail)
    {
        parent::__construct("Login failed: {$detail}");
    }
}
