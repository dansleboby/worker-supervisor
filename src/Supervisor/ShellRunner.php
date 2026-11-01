<?php

declare(strict_types=1);

namespace WorkerSupervisor\Supervisor;

use RuntimeException;

/**
 * Runs root's tools (supervisorctl, whmapi1, lvectl) with a fixed environment, never the caller's:
 * supervisorctl honours PYTHONPATH, cPanel's PHP PHPRC and PHP_INI_SCAN_DIR.
 */
final class ShellRunner implements Runner
{
    private const ENV = ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG' => 'C'];

    public function run(array $argv): array
    {
        $proc = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, self::ENV);
        if (!is_resource($proc)) {
            throw new RuntimeException('Unable to start ' . $argv[0]);
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($proc), $out];
    }

    public function detach(array $argv): void
    {
        // Callers pass a `setsid -f ...` argv: setsid forks and exits at once, so proc_close does not block.
        $proc = proc_open($argv, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes, null, self::ENV);
        if (!is_resource($proc)) {
            throw new RuntimeException('Unable to start ' . $argv[0]);
        }
        proc_close($proc);
    }
}
