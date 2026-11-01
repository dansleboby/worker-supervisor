<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\CpUser;
use WorkerSupervisor\LimitResolver;
use WorkerSupervisor\Limits;
use WorkerSupervisor\Paths;
use WorkerSupervisor\Settings;
use PHPUnit\Framework\TestCase;

final class LimitResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wsv_limits_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var/cpanel/users', 0777, true);
    }

    protected function tearDown(): void
    {
        $p = new Paths($this->root);
        foreach ([$p->settingsFile(), ...glob($this->root . '/var/cpanel/users/*') ?: []] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($p->dataDir());
        @rmdir($this->root . '/var/cpanel/users');
        @rmdir($this->root . '/var/cpanel');
        @rmdir($this->root . '/var');
        @rmdir($this->root);
    }

    private function user(string $fixture): CpUser
    {
        copy(__DIR__ . '/../fixtures/cpusers/' . $fixture, $this->root . '/var/cpanel/users/bob');
        return CpUser::load(new Paths($this->root), 'bob', fn () => ['dir' => '/home/bob']);
    }

    public function test_defaults_when_no_extension_and_no_override(): void
    {
        $l = LimitResolver::resolve(Settings::load(new Paths($this->root)), $this->user('plain'));
        $this->assertSame(10, $l->maxProcesses);
        $this->assertSame(5, $l->maxWorkers);
        $this->assertSame(3600, $l->maxStopwaitsecs);
        $this->assertSame(['laravel-queue', 'laravel-horizon', 'symfony-messenger', 'custom'], $l->templates);
    }

    public function test_root_owned_package_extension_applies(): void
    {
        $l = LimitResolver::resolve(Settings::load(new Paths($this->root)), $this->user('withext'));
        $this->assertSame(20, $l->maxProcesses);
        $this->assertNotContains('custom', $l->templates);
        $this->assertContains('laravel-queue', $l->templates);
    }

    public function test_reseller_owned_package_is_ignored(): void
    {
        $l = LimitResolver::resolve(Settings::load(new Paths($this->root)), $this->user('reseller'));
        $this->assertSame(10, $l->maxProcesses);
        $this->assertContains('custom', $l->templates);
    }

    public function test_override_beats_extension_per_field(): void
    {
        $s = Settings::load(new Paths($this->root));
        $s->setOverride('bob', ['max_processes' => 3]);
        $l = LimitResolver::resolve($s, $this->user('withext'));
        $this->assertSame(3, $l->maxProcesses);
        $this->assertNotContains('custom', $l->templates);
    }

    public function test_extension_beats_changed_defaults(): void
    {
        $s = Settings::load(new Paths($this->root));
        $s->setDefaults(new Limits(7, 2, 100, ['custom', 'laravel-queue']));
        $l = LimitResolver::resolve($s, $this->user('withext'));
        $this->assertSame(20, $l->maxProcesses);
        $this->assertSame(2, $l->maxWorkers);
        $this->assertSame(['laravel-queue'], $l->templates);
    }

    public function test_settings_roundtrip_rename_and_forget(): void
    {
        $p = new Paths($this->root);
        $this->assertEquals(Limits::serverDefaults(), Settings::load($p)->defaults());

        $s = Settings::load($p);
        $s->setDefaults(new Limits(8, 4, 60, ['custom']));
        $s->setOverride('bob', ['max_workers' => 9]);
        $s->save();
        $this->assertSame(0600, fileperms($p->settingsFile()) & 0777);

        $s = Settings::load($p);
        $this->assertEquals(new Limits(8, 4, 60, ['custom']), $s->defaults());
        $this->assertSame(['max_workers' => 9], $s->override('bob'));

        $s->renameUser('bob', 'rob');
        $this->assertNull($s->override('bob'));
        $this->assertSame(['max_workers' => 9], $s->override('rob'));

        $s->forgetUser('rob');
        $this->assertNull($s->override('rob'));
        $s->setOverride('x', ['max_workers' => 1]);
        $s->setOverride('x', null);
        $this->assertNull($s->override('x'));
    }
}
