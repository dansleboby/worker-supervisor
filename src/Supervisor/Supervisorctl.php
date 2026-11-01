<?php

declare(strict_types=1);

namespace WorkerSupervisor\Supervisor;

use InvalidArgumentException;

final class Supervisorctl implements SupervisorClient
{
    private const BASE = ['/usr/bin/supervisorctl', '-c', '/etc/supervisord.conf'];
    private const LEGACY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/';
    private const PROGRAM_PATTERN = '/^[a-z][a-z0-9]{0,15}_[a-z0-9][a-z0-9-]{0,31}\z/';
    private const STATUS_PATTERN = '/^(\S+?):(\S+)\s+([A-Z]+)\s*(.*)$/';

    /**
     * @param string $lockDir created 0700 by the PHP side before detaching (it is on tmpfs, so
     *                        it vanishes on reboot); flock cannot create parent directories
     */
    public function __construct(private readonly Runner $runner, private readonly string $lockDir = '/run/worker_supervisor')
    {
    }

    public function status(): array
    {
        // Exit 3 just means "some process is not RUNNING"; unparsable output yields an empty list.
        [, $out] = $this->runner->run([...self::BASE, 'status']);
        $list = [];
        foreach (preg_split('/\R/', $out) ?: [] as $line) {
            if (preg_match(self::STATUS_PATTERN, $line, $m) !== 1) {
                continue;
            }
            $desc = trim($m[4]);
            $pid = null;
            $uptime = null;
            if ($m[3] === 'RUNNING' && preg_match('/^pid (\d+), uptime (?:(\d+) days?, )?(\d+):(\d{2}):(\d{2})$/', $desc, $u) === 1) {
                $pid = (int) $u[1];
                $uptime = ((int) $u[2]) * 86400 + ((int) $u[3]) * 3600 + ((int) $u[4]) * 60 + (int) $u[5];
            }
            $list[] = new ProcessStatus($m[1], $m[2], $m[3], $pid, $uptime, $desc);
        }

        return $list;
    }

    public function version(): ?string
    {
        [$code, $out] = $this->runner->run([...self::BASE, 'version']);
        $out = trim($out);

        return $code === 0 && $out !== '' ? $out : null;
    }

    public function apply(string $program, bool $start = false): void
    {
        $this->assertProgram($program);
        $steps = [['reread'], ['update', $program]];
        if ($start) {
            $steps[] = ['start', $program . ':*'];
        }
        $this->detachJob($program, $steps, ' && ');
    }

    public function remove(string $program): void
    {
        $this->assertProgram($program);
        // `;` not `&&`: stop fails harmlessly when the group is already gone or stopped. Verified on
        // supervisor 4.2.5: `remove` works after the .ini was deleted, as long as the group is stopped.
        $this->detachJob($program, [['stop', $program . ':*'], ['remove', $program]], '; ');
    }

    public function acceptsUnmanaged(string $program): bool
    {
        // The name becomes part of a lock file path, so only plain characters pass.
        return preg_match(self::LEGACY_PATTERN, $program) === 1;
    }

    public function removeUnmanaged(string $program): void
    {
        if (!$this->acceptsUnmanaged($program)) {
            throw new InvalidArgumentException('Invalid program name');
        }
        $this->detachJob($program, [['stop', $program . ':*'], ['remove', $program]], '; ');
    }

    public function start(string $program): void
    {
        $this->control('start', $program);
    }

    public function stop(string $program): void
    {
        $this->control('stop', $program);
    }

    public function restart(string $program): void
    {
        $this->assertProgram($program);
        $this->detachJob($program, [['reread'], ['update', $program], ['restart', $program . ':*']], ' && ');
    }

    private function control(string $verb, string $program): void
    {
        $this->assertProgram($program);
        $this->detachJob($program, [[$verb, $program . ':*']], '');
    }

    private function assertProgram(string $program): void
    {
        if (preg_match(self::PROGRAM_PATTERN, $program) !== 1) {
            throw new InvalidArgumentException('Invalid program name');
        }
    }

    /**
     * One detached process per job; flock on a per-program lock serializes jobs so quick
     * successive actions run in order instead of racing.
     *
     * @param list<list<string>> $steps
     */
    private function detachJob(string $program, array $steps, string $glue): void
    {
        $base = implode(' ', array_map('escapeshellarg', self::BASE));
        $script = implode($glue, array_map(
            static fn (array $step): string => $base . ' ' . implode(' ', array_map('escapeshellarg', $step)),
            $steps
        ));

        if (!is_dir($this->lockDir) && !@mkdir($this->lockDir, 0700, true) && !is_dir($this->lockDir)) {
            throw new \RuntimeException('Cannot create lock directory');
        }

        $this->runner->detach([
            '/usr/bin/setsid', '-f',
            '/usr/bin/flock', $this->lockDir . '/' . $program . '.lock',
            '/bin/bash', '-c', $script,
        ]);
    }
}
