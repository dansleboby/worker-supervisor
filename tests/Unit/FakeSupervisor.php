<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\Supervisor\ProcessStatus;
use WorkerSupervisor\Supervisor\SupervisorClient;

final class FakeSupervisor implements SupervisorClient
{
    /** @var list<list<string|bool>> mutating calls only */
    public array $calls = [];

    /** @var list<ProcessStatus> */
    public array $procs = [];

    /** Null plays a supervisord that is down. */
    public ?string $version = '4.2.5';

    public function status(): array
    {
        return $this->procs;
    }

    public function version(): ?string
    {
        return $this->version;
    }

    public function apply(string $program, bool $start = false): void
    {
        $this->calls[] = ['apply', $program, $start];
    }

    public function remove(string $program): void
    {
        $this->calls[] = ['remove', $program];
    }

    public function acceptsUnmanaged(string $program): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/', $program) === 1;
    }

    public function removeUnmanaged(string $program): void
    {
        $this->calls[] = ['remove_unmanaged', $program];
    }

    public function start(string $program): void
    {
        $this->calls[] = ['start', $program];
    }

    // Records and returns at once, like the detached real client.
    public function stop(string $program): void
    {
        $this->calls[] = ['stop', $program];
    }

    public function restart(string $program): void
    {
        $this->calls[] = ['restart', $program];
    }
}
