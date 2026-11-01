<?php

declare(strict_types=1);

namespace WorkerSupervisor;

final class Template
{
    /**
     * @param string[] $requiredPaths paths relative to the folder that must exist
     * @param ?string  $memoryFlagPattern PCRE with one capture group for the memory value in MB
     */
    public function __construct(
        public readonly string $id,
        public readonly string $command,
        public readonly array $requiredPaths,
        public readonly ?int $fixedProcesses,
        public readonly bool $laravelRestart,
        public readonly ?string $memoryFlagPattern,
    ) {
    }
}
