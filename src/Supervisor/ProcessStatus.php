<?php

declare(strict_types=1);

namespace WorkerSupervisor\Supervisor;

final class ProcessStatus
{
    public function __construct(
        public readonly string $program,
        public readonly string $process,
        public readonly string $state,
        public readonly ?int $pid,
        public readonly ?int $uptimeSeconds,
        public readonly string $description,
    ) {
    }
}
