<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\Supervisor\ProcessStatus;
use WorkerSupervisor\Supervisor\Runner;
use WorkerSupervisor\Supervisor\Supervisorctl;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecordingRunner implements Runner
{
    /** @var list<array{string, list<string>}> */
    public array $calls = [];

    /** @param array{0: int, 1: string} $result */
    public function __construct(private array $result = [0, ''])
    {
    }

    public function run(array $argv): array
    {
        $this->calls[] = ['run', $argv];

        return $this->result;
    }

    public function detach(array $argv): void
    {
        $this->calls[] = ['detach', $argv];
    }
}

final class SupervisorctlTest extends TestCase
{
    private const CTL = "'/usr/bin/supervisorctl' '-c' '/etc/supervisord.conf'";

    private string $lockDir;

    protected function setUp(): void
    {
        $this->lockDir = sys_get_temp_dir() . '/bw-lock-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->lockDir)) {
            rmdir($this->lockDir);
        }
    }

    private function client(RecordingRunner $runner): Supervisorctl
    {
        return new Supervisorctl($runner, $this->lockDir);
    }

    public function test_parses_status_fixture(): void
    {
        $runner = new RecordingRunner([3, (string) file_get_contents(__DIR__ . '/../fixtures/supervisorctl-status.txt')]);
        $list = ($this->client($runner))->status();

        $this->assertSame([['run', ['/usr/bin/supervisorctl', '-c', '/etc/supervisord.conf', 'status']]], $runner->calls);
        $this->assertCount(5, $list);
        $this->assertContainsOnlyInstancesOf(ProcessStatus::class, $list);

        $this->assertSame('bob_queue', $list[0]->program);
        $this->assertSame('bob_queue_00', $list[0]->process);
        $this->assertSame('RUNNING', $list[0]->state);
        $this->assertSame(123, $list[0]->pid);
        $this->assertSame(8043, $list[0]->uptimeSeconds);
        $this->assertSame('pid 123, uptime 2:14:03', $list[0]->description);

        $this->assertSame(124, $list[1]->pid);
        $this->assertSame(86400 + 8043, $list[1]->uptimeSeconds);

        $this->assertSame('FATAL', $list[2]->state);
        $this->assertNull($list[2]->pid);
        $this->assertNull($list[2]->uptimeSeconds);
        $this->assertSame('Exited too quickly (process log may have details)', $list[2]->description);

        $this->assertSame('STOPPED', $list[3]->state);
        $this->assertNull($list[3]->uptimeSeconds);
        $this->assertSame('Oct 31 10:00 AM', $list[3]->description);
        $this->assertSame('Not started', $list[4]->description);
    }

    public function test_status_empty_output_means_no_programs(): void
    {
        $this->assertSame([], ($this->client(new RecordingRunner([0, ''])))->status());
    }

    public function test_status_ignores_unparsable_lines(): void
    {
        $runner = new RecordingRunner([4, "error: <class 'socket.error'>, [Errno 2] No such file or directory\n"]);
        $this->assertSame([], ($this->client($runner))->status());
    }

    public function test_version(): void
    {
        $runner = new RecordingRunner([0, "4.2.5\n"]);
        $this->assertSame('4.2.5', ($this->client($runner))->version());
        $this->assertSame([['run', ['/usr/bin/supervisorctl', '-c', '/etc/supervisord.conf', 'version']]], $runner->calls);
    }

    public function test_version_is_null_when_supervisord_is_down(): void
    {
        $this->assertNull(($this->client(new RecordingRunner([2, 'refused'])))->version());
    }

    public function test_stop_is_detached(): void
    {
        $runner = new RecordingRunner();
        ($this->client($runner))->stop('bob_queue');

        $this->assertCount(1, $runner->calls);
        $this->assertSame('detach', $runner->calls[0][0]);
        $this->assertSame(
            ['/usr/bin/setsid', '-f', '/usr/bin/flock', $this->lockDir . '/bob_queue.lock', '/bin/bash', '-c'],
            array_slice($runner->calls[0][1], 0, 6)
        );
        $this->assertSame(self::CTL . " 'stop' 'bob_queue:*'", $this->script($runner));
        $this->assertSame(0700, fileperms($this->lockDir) & 0777);
    }

    public function test_apply_rereads_then_updates(): void
    {
        $runner = new RecordingRunner();
        ($this->client($runner))->apply('bob_queue');

        $this->assertSame(self::CTL . " 'reread' && " . self::CTL . " 'update' 'bob_queue'", $this->script($runner));
    }

    public function test_apply_with_start_starts_after_update(): void
    {
        $runner = new RecordingRunner();
        ($this->client($runner))->apply('bob_queue', true);

        $this->assertSame(
            self::CTL . " 'reread' && " . self::CTL . " 'update' 'bob_queue' && " . self::CTL . " 'start' 'bob_queue:*'",
            $this->script($runner)
        );
    }

    public function test_remove_stops_then_removes(): void
    {
        $runner = new RecordingRunner();
        ($this->client($runner))->remove('bob_queue');

        $this->assertSame(
            self::CTL . " 'stop' 'bob_queue:*'; " . self::CTL . " 'remove' 'bob_queue'",
            $this->script($runner)
        );
    }

    public function test_start_is_detached(): void
    {
        $runner = new RecordingRunner();
        ($this->client($runner))->start('bob_queue');

        $this->assertSame(self::CTL . " 'start' 'bob_queue:*'", $this->script($runner));
        $this->assertSame('detach', $runner->calls[0][0]);
    }

    public function test_restart_loads_the_program_first(): void
    {
        $runner = new RecordingRunner();
        ($this->client($runner))->restart('bob_queue');

        $this->assertSame(
            self::CTL . " 'reread' && " . self::CTL . " 'update' 'bob_queue' && " . self::CTL . " 'restart' 'bob_queue:*'",
            $this->script($runner)
        );
        $this->assertSame('detach', $runner->calls[0][0]);
    }

    public function test_remove_unmanaged_accepts_legacy_names_only(): void
    {
        $runner = new RecordingRunner();
        $client = $this->client($runner);

        $client->removeUnmanaged('acme-laravel-worker');
        $this->assertSame(
            self::CTL . " 'stop' 'acme-laravel-worker:*'; " . self::CTL . " 'remove' 'acme-laravel-worker'",
            $this->script($runner),
        );

        $this->expectException(InvalidArgumentException::class);
        $client->removeUnmanaged('../x');
    }

    #[DataProvider('badNames')]
    public function test_rejects_invalid_program_names(string $name): void
    {
        $runner = new RecordingRunner();
        $client = $this->client($runner);

        foreach (['apply', 'remove', 'start', 'stop', 'restart'] as $method) {
            try {
                $client->{$method}($name);
                $this->fail("$method accepted '$name'");
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame([], $runner->calls);
    }

    /** @return array<string, array{string}> */
    public static function badNames(): array
    {
        return [
            'empty' => [''],
            'no separator' => ['bobqueue'],
            'shell injection' => ["bob_q'; rm -rf /; '"],
            'uppercase' => ['Bob_queue'],
            'space' => ['bob_queue x'],
            'glob' => ['bob_*'],
            'newline' => ["bob_queue\n"],
            'path' => ['../etc_passwd'],
            'user too long' => ['abcdefghijklmnopq_queue'],
        ];
    }

    private function script(RecordingRunner $runner): string
    {
        $argv = $runner->calls[0][1];

        return $argv[count($argv) - 1];
    }
}
