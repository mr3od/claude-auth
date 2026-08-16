<?php

namespace App\Services\Exceptions;

final class NoPreviousAccountException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No previous account to switch back to.');
    }
}
