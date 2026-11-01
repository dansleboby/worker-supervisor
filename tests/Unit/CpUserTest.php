<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\CpUser;
use WorkerSupervisor\Identity;
use WorkerSupervisor\Paths;
use PHPUnit\Framework\TestCase;

final class CpUserTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wsv_cpuser_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var/cpanel/users', 0777, true);
        mkdir($this->root . '/var/cpanel/suspended', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/var/cpanel/*/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->root . '/var/cpanel/users');
        @rmdir($this->root . '/var/cpanel/suspended');
        @rmdir($this->root . '/var/cpanel');
        @rmdir($this->root . '/var');
        @rmdir($this->root);
    }

    public function test_missing_cpuser_file_returns_null(): void
    {
        $this->assertNull(CpUser::load(new Paths($this->root), 'ghost', fn () => ['dir' => '/home/ghost']));
    }

    public function test_cpuser_reads_suspended_flag_and_home(): void
    {
        copy(__DIR__ . '/../fixtures/cpusers/plain', $this->root . '/var/cpanel/users/bob');
        $p = new Paths($this->root);
        $lookup = fn (string $u) => ['dir' => '/home/' . $u];

        $u = CpUser::load($p, 'bob', $lookup);
        $this->assertSame('/home/bob', $u->home);
        $this->assertSame('root', $u->owner);
        $this->assertSame('default', $u->plan);
        $this->assertSame('default', $u->featureList);
        $this->assertFalse($u->suspended);
        $this->assertSame([], $u->ext);

        touch($p->suspendedFlag('bob'));
        $this->assertTrue(CpUser::load($p, 'bob', $lookup)->suspended);
    }

    public function test_identity_is_the_uid_and_the_start_date(): void
    {
        file_put_contents($this->root . '/var/cpanel/users/bob', "OWNER=root\nSTARTDATE=1793487157\n");
        $p = new Paths($this->root);

        $id = CpUser::load($p, 'bob', fn () => ['uid' => 1001, 'dir' => '/home/bob'])->identity();
        $this->assertSame(['uid' => 1001, 'startdate' => '1793487157'], $id->toArray());
        $this->assertTrue($id->equals(new Identity(1001, '1793487157')));
        $this->assertFalse($id->equals(new Identity(1001, '1793488782')), 'a later account of the name');
        $this->assertFalse($id->equals(new Identity(1002, '1793487157')));
        $this->assertNull(CpUser::load($p, 'bob', fn () => false)->identity(), 'no system user, no uid');
    }

    public function test_extension_keys_loaded_only_when_listed(): void
    {
        copy(__DIR__ . '/../fixtures/cpusers/withext', $this->root . '/var/cpanel/users/bob');
        $u = CpUser::load(new Paths($this->root), 'bob', fn () => ['dir' => '/home/bob']);
        $this->assertSame(
            ['worker_supervisor_max_processes' => '20', 'worker_supervisor_tpl_custom' => '0'],
            $u->ext
        );

        file_put_contents(
            $this->root . '/var/cpanel/users/bob',
            "OWNER=root\nworker_supervisor_max_processes=20\n"
        );
        $this->assertSame([], CpUser::load(new Paths($this->root), 'bob', fn () => false)->ext);
    }
}
