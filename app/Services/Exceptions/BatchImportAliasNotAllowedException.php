<?php

namespace App\Services\Exceptions;

final class BatchImportAliasNotAllowedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('--alias can only be used when importing a single account, not a directory of several.');
    }
}
