<?php

namespace App\Services\Exceptions;

final class RegistryCorruptException extends \RuntimeException
{
    public function __construct(string $detail)
    {
        parent::__construct("{$detail} Run \"import --purge\" to rebuild the registry from stored snapshot files.");
    }
}
