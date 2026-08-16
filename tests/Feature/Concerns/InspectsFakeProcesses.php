<?php

namespace Tests\Feature\Concerns;

use Illuminate\Process\PendingProcess;

trait InspectsFakeProcesses
{
    /**
     * @return array<string, string>
     */
    protected function environmentOf(PendingProcess $process): array
    {
        $property = new \ReflectionProperty($process, 'environment');
        $property->setAccessible(true);

        return $property->getValue($process) ?? [];
    }
}
