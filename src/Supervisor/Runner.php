<?php

declare(strict_types=1);

namespace WorkerSupervisor\Supervisor;

interface Runner
{
    /**
     * Synchronous, no shell.
     *
     * @param list<string> $argv
     * @return array{0: int, 1: string} exit code and stdout
     */
    public function run(array $argv): array;

    /**
     * Fire and forget: returns immediately, stdio on /dev/null.
     *
     * @param list<string> $argv
     */
    public function detach(array $argv): void;
}
