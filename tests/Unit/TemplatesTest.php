<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\Templates;
use PHPUnit\Framework\TestCase;

final class TemplatesTest extends TestCase
{
    public function test_catalog_ids(): void
    {
        $this->assertSame(
            ['laravel-queue', 'laravel-horizon', 'symfony-messenger', 'custom'],
            array_map(fn ($t) => $t->id, Templates::all()),
        );
    }

    public function test_horizon_is_single_process(): void
    {
        $this->assertSame(1, Templates::get('laravel-horizon')->fixedProcesses);
    }

    public function test_get_unknown_returns_null(): void
    {
        $this->assertNull(Templates::get('nope'));
    }

    public function test_laravel_queue_definition(): void
    {
        $t = Templates::get('laravel-queue');

        $this->assertSame('php artisan queue:work --sleep=3 --tries=3 --max-time=3600', $t->command);
        $this->assertSame(['artisan'], $t->requiredPaths);
        $this->assertNull($t->fixedProcesses);
        $this->assertTrue($t->laravelRestart);
        $this->assertSame(1, preg_match($t->memoryFlagPattern, 'x --memory=256', $m));
        $this->assertSame('256', $m[1]);
    }

    public function test_custom_template_is_empty(): void
    {
        $t = Templates::get('custom');

        $this->assertSame('', $t->command);
        $this->assertSame([], $t->requiredPaths);
        $this->assertNull($t->memoryFlagPattern);
        $this->assertFalse($t->laravelRestart);
    }
}
