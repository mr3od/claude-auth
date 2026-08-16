<?php

namespace App\DataTransferObjects;

final readonly class ScratchLoginResult
{
    public function __construct(
        public string $credentialsPath,
        public string $claudeJsonPath,
        public \Closure $cleanup,
    ) {}
}
