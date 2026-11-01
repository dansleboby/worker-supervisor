<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\Account;
use WorkerSupervisor\StatusView;
use WorkerSupervisor\Supervisor\ProcessStatus;
use WorkerSupervisor\Worker;
use PHPUnit\Framework\TestCase;

final class StatusViewTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private function view(): StatusView
    {
        return new StatusView(static fn (): int => self::NOW);
    }

    private function worker(array $over = []): Worker
    {
        return Worker::fromArray($over + [
            'name' => 'queue',
            'template' => 'laravel-queue',
            'folder' => '/home/bob/app',
            'command' => 'php artisan queue:work',
            'processes' => 2,
            'startretries' => 7,
        ]);
    }

    private function proc(string $state, int $n = 0, ?int $uptime = null): ProcessStatus
    {
        return new ProcessStatus('bob_queue', sprintf('bob_queue_%02d', $n), $state, $uptime === null ? null : 100 + $n, $uptime, '');
    }

    public function test_fatal_maps_to_failed_with_reason(): void
    {
        $row = $this->view()->row($this->worker(), new Account('bob'), [$this->proc('RUNNING', 0, 60), $this->proc('FATAL', 1)]);

        $this->assertSame([
            'name' => 'queue',
            'template' => 'laravel-queue',
            'folder' => '/home/bob/app',
            'processes' => 2,
            'running' => 1,
            'status' => 'failed',
            'reason' => ['code' => 'exited_too_quickly', 'params' => ['retries' => 7]],
            'since' => self::NOW - 60,
        ], $row);
    }

    public function test_precedence_suspended_over_failed(): void
    {
        $row = $this->view()->row($this->worker(), new Account('bob', [], true), [$this->proc('FATAL')]);

        $this->assertSame('suspended', $row['status']);
        $this->assertNull($row['reason']);
    }

    public function test_feature_off_beats_suspended_and_admin_stopped_beats_failed(): void
    {
        $view = $this->view();
        $admin = $this->worker(['admin_stopped' => true]);

        $this->assertSame('feature_off', $view->row($admin, new Account('bob', [], true, true), [])['status']);
        $this->assertSame('admin_stopped', $view->row($admin, new Account('bob'), [$this->proc('FATAL')])['status']);
    }

    public function test_transitional_states(): void
    {
        $view = $this->view();
        $w = $this->worker();
        $a = new Account('bob');

        $this->assertSame('stopping', $view->row($w, $a, [$this->proc('STARTING'), $this->proc('STOPPING', 1)])['status']);
        $this->assertSame('starting', $view->row($w, $a, [$this->proc('RUNNING', 0, 5), $this->proc('BACKOFF', 1)])['status']);
        $this->assertSame('starting', $view->row($w, $a, [$this->proc('STARTING')])['status']);
    }

    public function test_running_needs_every_process_running_and_since_uses_the_oldest(): void
    {
        $view = $this->view();
        $w = $this->worker();
        $a = new Account('bob');

        $row = $view->row($w, $a, [$this->proc('RUNNING', 0, 30), $this->proc('RUNNING', 1, 90)]);
        $this->assertSame('running', $row['status']);
        $this->assertSame(2, $row['running']);
        $this->assertSame(self::NOW - 90, $row['since']);

        $this->assertSame('stopped', $view->row($w, $a, [$this->proc('RUNNING', 0, 30), $this->proc('STOPPED', 1)])['status']);
    }

    public function test_no_processes_is_stopped_without_since(): void
    {
        $row = $this->view()->row($this->worker(), new Account('bob'), []);

        $this->assertSame('stopped', $row['status']);
        $this->assertSame(0, $row['running']);
        $this->assertNull($row['since']);
        $this->assertNull($row['reason']);
    }

    public function test_by_program_groups_status_lines(): void
    {
        $a = new ProcessStatus('bob_a', 'bob_a_00', 'RUNNING', 1, 1, '');
        $b0 = new ProcessStatus('bob_b', 'bob_b_00', 'STOPPED', null, null, '');
        $b1 = new ProcessStatus('bob_b', 'bob_b_01', 'STOPPED', null, null, '');

        $this->assertSame(['bob_a' => [$a], 'bob_b' => [$b0, $b1]], StatusView::byProgram([$a, $b0, $b1]));
    }
}
