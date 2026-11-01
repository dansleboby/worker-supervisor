<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\Paths;
use PHPUnit\Framework\TestCase;

final class PathsTest extends TestCase
{
    public function test_paths_are_prefixed_by_root(): void
    {
        $paths = new Paths('/tmp/x');

        $this->assertSame('/tmp/x/etc/supervisord.d/wsv_bob_queue.ini', $paths->iniFile('bob', 'queue'));
        $this->assertSame('/tmp/x/var/cpanel/worker_supervisor/launch/bob/queue', $paths->launchFile('bob', 'queue'));
        $this->assertSame('/tmp/x/var/cpanel/worker_supervisor/launch/bob/queue.hold', $paths->holdFile('bob', 'queue'));
    }
}
