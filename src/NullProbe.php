<?php

declare(strict_types=1);

namespace WorkerSupervisor;

/** Knows nothing: for validations whose informational issues nobody reads, without spawning shells. */
final class NullProbe implements AccountProbe
{
    public function memoryLimitMb(string $user): ?int
    {
        return null;
    }

    public function phpVersion(string $user, string $folder): ?string
    {
        return null;
    }
}
