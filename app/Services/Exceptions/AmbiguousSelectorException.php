<?php

namespace App\Services\Exceptions;

use App\DataTransferObjects\AccountRecord;

final class AmbiguousSelectorException extends \RuntimeException
{
    /**
     * @param  AccountRecord[]  $candidates
     */
    public function __construct(
        public readonly string $selector,
        public readonly array $candidates,
    ) {
        parent::__construct("Multiple accounts match \"{$selector}\". Use a more specific selector.");
    }
}
