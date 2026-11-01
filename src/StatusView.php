<?php

declare(strict_types=1);

namespace WorkerSupervisor;

use WorkerSupervisor\Supervisor\ProcessStatus;

/** Maps a stored worker plus supervisord's process states to the row the UI shows. */
final class StatusView
{
    /** States in which supervisord runs the process or is about to (BACKOFF retries it). */
    private const ACTIVE = ['RUNNING', 'STARTING', 'BACKOFF'];

    /** @var callable(): int */
    private $now;

    /** @param (callable(): int)|null $now defaults to time() */
    public function __construct(?callable $now = null)
    {
        $this->now = $now ?? time(...);
    }

    /**
     * @param ProcessStatus[] $procs this worker's processes only (see byProgram)
     * @return array{name: string, template: string, folder: string, processes: int, running: int,
     *               status: string, reason: ?array{code: string, params: array<string, int>}, since: ?int}
     */
    public function row(Worker $w, Account $a, array $procs): array
    {
        $states = array_map(static fn (ProcessStatus $p) => $p->state, $procs);
        $running = count(array_keys($states, 'RUNNING', true));
        $any = static fn (string ...$wanted) => array_intersect($states, $wanted) !== [];

        $status = match (true) {
            $a->featureOff => 'feature_off',
            $a->suspended => 'suspended',
            $w->adminStopped => 'admin_stopped',
            $any('FATAL') => 'failed',
            $any('STOPPING') => 'stopping',
            $any('STARTING', 'BACKOFF') => 'starting',
            $states !== [] && $running === count($states) => 'running',
            default => 'stopped',
        };

        $uptimes = array_filter(array_map(static fn (ProcessStatus $p) => $p->uptimeSeconds, $procs), 'is_int');

        return [
            'name' => $w->name,
            'template' => $w->template,
            'folder' => $w->folder,
            'processes' => $w->processes,
            'running' => $running,
            'status' => $status,
            // supervisord only reaches FATAL after startretries failed starts.
            'reason' => $status === 'failed'
                ? ['code' => 'exited_too_quickly', 'params' => ['retries' => $w->startretries]]
                : null,
            'since' => $uptimes === [] ? null : ($this->now)() - max($uptimes),
        ];
    }

    /** @param ProcessStatus[] $procs */
    public static function active(array $procs): bool
    {
        foreach ($procs as $p) {
            if (in_array($p->state, self::ACTIVE, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ProcessStatus[] $procs
     * @return array<string, list<ProcessStatus>> keyed by program name
     */
    public static function byProgram(array $procs): array
    {
        $grouped = [];
        foreach ($procs as $p) {
            $grouped[$p->program][] = $p;
        }

        return $grouped;
    }
}
