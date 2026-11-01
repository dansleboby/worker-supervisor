<?php

declare(strict_types=1);

namespace WorkerSupervisor\Supervisor;

interface SupervisorClient
{
    /** @return list<ProcessStatus> */
    public function status(): array;

    /** Null when supervisord is unreachable. */
    public function version(): ?string;

    /**
     * Mutating calls below return immediately; the work runs detached because a stop can wait
     * up to stopwaitsecs. Jobs for one program are serialized.
     */
    public function apply(string $program, bool $start = false): void;

    public function remove(string $program): void;

    /**
     * Like remove() for a hand-written program, whose name does not follow the managed <user>_<name>
     * form. Used by the importer only.
     */
    public function removeUnmanaged(string $program): void;

    /** Whether removeUnmanaged() takes this name, so callers can refuse before changing anything. */
    public function acceptsUnmanaged(string $program): bool;

    /** Fails for a program supervisord has not loaded; apply($program, true) loads it first. */
    public function start(string $program): void;

    public function stop(string $program): void;

    /** Loads the program first if supervisord has not, like apply(). */
    public function restart(string $program): void;
}
