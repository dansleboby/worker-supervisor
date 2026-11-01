<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

final class ChildFds
{
    /** `ls -l /proc/self/fd` of a child spawned now: the targets of every descriptor it inherited (Linux). */
    public static function list(): string
    {
        $proc = proc_open(['ls', '-l', '/proc/self/fd'], [1 => ['pipe', 'w']], $pipes);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($proc);

        return $out;
    }
}
