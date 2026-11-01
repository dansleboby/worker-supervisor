<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\Worker;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerTest extends TestCase
{
    public function test_from_array_applies_defaults(): void
    {
        $worker = Worker::fromArray([
            'name' => 'queue',
            'template' => 'laravel-queue',
            'folder' => 'app',
            'command' => 'php artisan queue:work',
        ]);

        $this->assertSame(1, $worker->processes);
        $this->assertSame(3600, $worker->stopwaitsecs);
        $this->assertSame('running', $worker->desired);
        $this->assertTrue($worker->autostart);
        $this->assertSame('true', $worker->autorestart);
        $this->assertSame(10, $worker->startsecs);
        $this->assertSame(30, $worker->startretries);
        $this->assertTrue($worker->redirectStderr);
        $this->assertFalse($worker->adminStopped);
    }

    /** @return array<string, array{string, bool}> */
    public static function stringBooleans(): array
    {
        return [
            '"1"' => ['1', true],
            '"0"' => ['0', false],
            '"true"' => ['true', true],
            '"false"' => ['false', false],
            '"on"' => ['on', true],
            '"off"' => ['off', false],
            '""' => ['', false],
        ];
    }

    #[DataProvider('stringBooleans')]
    public function test_string_booleans_from_forms_are_parsed(string $raw, bool $expected): void
    {
        $worker = Worker::fromArray([
            'name' => 'mail',
            'template' => 'custom',
            'folder' => 'site',
            'command' => 'x',
            'autostart' => $raw,
            'redirect_stderr' => $raw,
            'admin_stopped' => $raw,
        ]);

        $this->assertSame($expected, $worker->autostart);
        $this->assertSame($expected, $worker->redirectStderr);
        $this->assertSame($expected, $worker->adminStopped);
    }

    public function test_unrecognised_boolean_string_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Worker::fromArray([
            'name' => 'mail',
            'template' => 'custom',
            'folder' => 'site',
            'command' => 'x',
            'autostart' => 'maybe',
        ]);
    }

    /** @return array<string, array{string}> */
    public static function identityKeys(): array
    {
        return [
            'name' => ['name'],
            'template' => ['template'],
            'folder' => ['folder'],
            'command' => ['command'],
        ];
    }

    #[DataProvider('identityKeys')]
    public function test_missing_identity_key_is_rejected(string $missing): void
    {
        $data = [
            'name' => 'mail',
            'template' => 'custom',
            'folder' => 'site',
            'command' => 'x',
        ];
        unset($data[$missing]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($missing);

        Worker::fromArray($data);
    }

    public function test_round_trip(): void
    {
        $worker = Worker::fromArray([
            'name' => 'mail',
            'template' => 'custom',
            'folder' => 'site',
            'command' => 'php bin/mail.php',
            'processes' => 3,
            'autostart' => false,
            'autorestart' => 'unexpected',
            'startsecs' => 5,
            'startretries' => 2,
            'stopwaitsecs' => 60,
            'redirect_stderr' => false,
            'desired' => 'stopped',
            'admin_stopped' => true,
        ]);

        $this->assertEquals($worker, Worker::fromArray($worker->toArray()));
    }

    public function test_from_array_casts_scalar_types(): void
    {
        $worker = Worker::fromArray([
            'name' => 'mail',
            'template' => 'custom',
            'folder' => 'site',
            'command' => 'x',
            'processes' => '4',
            'autostart' => 0,
            'redirect_stderr' => '',
            'admin_stopped' => 1,
        ]);

        $this->assertSame(4, $worker->processes);
        $this->assertFalse($worker->autostart);
        $this->assertFalse($worker->redirectStderr);
        $this->assertTrue($worker->adminStopped);
    }

    public function test_with_returns_modified_copy(): void
    {
        $worker = Worker::fromArray([
            'name' => 'queue',
            'template' => 'laravel-queue',
            'folder' => 'app',
            'command' => 'php artisan queue:work',
        ]);

        $stopped = $worker->with(['desired' => 'stopped', 'processes' => 2]);

        $this->assertNotSame($worker, $stopped);
        $this->assertSame('stopped', $stopped->desired);
        $this->assertSame(2, $stopped->processes);
        $this->assertSame('running', $worker->desired);
        $this->assertSame(1, $worker->processes);
        $this->assertSame('queue', $stopped->name);
    }
}
