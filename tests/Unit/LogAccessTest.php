<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\CpUser;
use WorkerSupervisor\LogAccess;
use WorkerSupervisor\Paths;
use WorkerSupervisor\Supervisor\Runner;
use PHPUnit\Framework\TestCase;

final class LogAccessTest extends TestCase
{
    private string $root;
    private Paths $paths;
    /** @var list<list<string>> */
    private array $runs = [];
    private int $exitCode = 0;
    private ?int $gid;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wsv_logaccess_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var/cpanel/users', 0777, true);
        mkdir($this->root . '/home/bob/app', 0777, true);
        file_put_contents($this->root . '/var/cpanel/users/bob', "OWNER=root\nSTARTDATE=1\n");
        $this->paths = new Paths($this->root);
        // Tests run unprivileged: only the runner's own group can be given to a file.
        $this->gid = posix_getgid();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function access(?string $mpFile = null, string $enterUser = '/nonexistent/cagefs_enter_user'): LogAccess
    {
        $runner = new class ($this) implements Runner {
            public function __construct(private readonly LogAccessTest $test)
            {
            }

            public function run(array $argv): array
            {
                return $this->test->ran($argv);
            }

            public function detach(array $argv): void
            {
            }
        };

        return new LogAccess(
            $this->paths,
            $runner,
            fn (string $u): array|false => $u === 'bob' && $this->gid !== null ? ['name' => 'bob', 'uid' => 1001, 'gid' => $this->gid, 'dir' => $this->root . '/home/bob'] : false,
            $mpFile ?? $this->root . '/cagefs.mp',
            $enterUser,
        );
    }

    /** @return array{0: int, 1: string} */
    public function ran(array $argv): array
    {
        $this->runs[] = $argv;

        return [$this->exitCode, ''];
    }

    private function bob(): CpUser
    {
        return CpUser::load($this->paths, 'bob', fn () => ['uid' => 1001, 'dir' => $this->root . '/home/bob']);
    }

    private function mode(string $path): int
    {
        clearstatcache();

        return fileperms($path) & 07777;
    }

    public function test_secure_shares_the_log_directory_with_the_account_group_only(): void
    {
        $dir = $this->paths->logDir('bob');
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/queue_00.log', "line\n");

        $this->access()->secure('bob');

        $this->assertSame(0711, $this->mode(dirname($dir)), 'others may cross the parent, not list it');
        $this->assertSame(02750, $this->mode($dir), 'setgid: files supervisord creates get the account group');
        $this->assertSame($this->gid, filegroup($dir));
        $this->assertSame($this->gid, filegroup($dir . '/queue_00.log'));
    }

    public function test_secure_creates_a_missing_directory_and_keeps_it_root_only_without_a_group(): void
    {
        $this->gid = null;

        $this->access()->secure('bob');

        $this->assertSame(0700, $this->mode($this->paths->logDir('bob')));
    }

    public function test_link_is_made_by_the_account_itself(): void
    {
        $this->assertTrue($this->access()->link($this->bob()));

        $this->assertCount(1, $this->runs);
        [$runuser, $flag, $user, $sep, $sh, $c] = $this->runs[0];
        $this->assertSame(['/usr/sbin/runuser', '-u', 'bob', '--', '/bin/sh', '-c'], [$runuser, $flag, $user, $sep, $sh, $c]);
        $this->assertSame(['sh', $this->root . '/home/bob', $this->paths->logDir('bob'), dirname($this->paths->logDir('bob')) . '/'], array_slice($this->runs[0], 7));
    }

    public function test_the_link_script_makes_replaces_and_respects_what_it_should(): void
    {
        $script = (new \ReflectionClassConstant(LogAccess::class, 'LINK_SCRIPT'))->getValue();
        $home = $this->root . '/home/bob';
        $prefix = $this->root . '/var/log/worker_supervisor/';
        $run = static fn () => exec('/bin/sh -c ' . escapeshellarg($script) . ' sh ' . escapeshellarg($home) . ' ' . escapeshellarg($prefix . 'bob') . ' ' . escapeshellarg($prefix));
        $link = $home . '/logs/worker_supervisor';

        $run();
        $this->assertSame($prefix . 'bob', readlink($link), 'made, ~/logs included');

        unlink($link);
        symlink($prefix . 'oldname', $link);
        $run();
        $this->assertSame($prefix . 'bob', readlink($link), 'a link to an earlier username is replaced');

        unlink($link);
        symlink('/somewhere/else', $link);
        exec('/bin/sh -c ' . escapeshellarg($script) . ' sh ' . escapeshellarg($home) . ' ' . escapeshellarg($prefix . 'bob') . ' ' . escapeshellarg($prefix), $out, $code);
        $this->assertSame(3, $code, 'left alone, not reported as linked');
        $this->assertSame('/somewhere/else', readlink($link), 'a link of the client\'s is left alone');

        unlink($link);
        mkdir($link);
        $run();
        $this->assertTrue(is_dir($link) && !is_link($link), 'a real directory is left alone');
    }

    public function test_link_does_nothing_when_the_link_is_there_or_something_else_is(): void
    {
        mkdir($this->root . '/home/bob/logs');
        symlink($this->paths->logDir('bob'), $this->root . '/home/bob/logs/worker_supervisor');
        $this->assertFalse($this->access()->link($this->bob()));

        unlink($this->root . '/home/bob/logs/worker_supervisor');
        mkdir($this->root . '/home/bob/logs/worker_supervisor');
        $this->assertFalse($this->access()->link($this->bob()));

        $this->assertSame([], $this->runs, 'no process spawned');
    }

    public function test_a_link_of_the_client_s_own_spawns_nothing_but_one_of_ours_is_repaired(): void
    {
        mkdir($this->root . '/home/bob/logs');
        symlink('/somewhere/else', $this->root . '/home/bob/logs/worker_supervisor');
        $this->assertFalse($this->access()->link($this->bob()));
        $this->assertSame([], $this->runs);

        unlink($this->root . '/home/bob/logs/worker_supervisor');
        symlink(dirname($this->paths->logDir('bob')) . '/oldname', $this->root . '/home/bob/logs/worker_supervisor');
        $this->assertTrue($this->access()->link($this->bob()));
        $this->assertCount(1, $this->runs);
    }

    public function test_link_runs_inside_cagefs_when_it_is_there(): void
    {
        $enter = $this->root . '/cagefs_enter_user';
        file_put_contents($enter, "#!/bin/sh\n");
        chmod($enter, 0755);

        $this->access(null, $enter)->link($this->bob());

        $this->assertSame([$enter, '--no-fork', 'bob', '/bin/sh', '-c'], array_slice($this->runs[0], 0, 5));
    }

    public function test_secure_never_remounts_cagefs(): void
    {
        file_put_contents($this->root . '/cagefs.mp', "%/var/log/worker_supervisor\n");
        // IniRenderer::write makes the directory before Service gives it to the account.
        mkdir($this->paths->logDir('bob'), 0700, true);

        $this->access()->secure('bob');

        $this->assertSame([], $this->runs, 'a remount would kill the account\'s processes');
    }

    public function test_prepare_all_makes_a_directory_for_every_cpanel_account(): void
    {
        file_put_contents($this->root . '/var/cpanel/users/alice', "OWNER=root\n");
        file_put_contents($this->root . '/var/cpanel/users/Bad.Name', "OWNER=root\n");

        $this->assertSame(['alice', 'bob'], $this->access()->prepareAll());

        $this->assertSame(02750, $this->mode($this->paths->logDir('bob')));
        $this->assertSame(0700, $this->mode($this->paths->logDir('alice')), 'no system user in the fake passwd');
        $this->assertDirectoryDoesNotExist($this->paths->logDir('Bad.Name'));
    }

    public function test_restrict_takes_the_logs_of_an_earlier_account_back(): void
    {
        if (posix_geteuid() !== 0) {
            $this->markTestSkipped('giving a directory to root needs root; checked on the VM');
        }
        $this->access()->secure('bob');
        $this->access()->restrict('bob');

        $this->assertSame(0700, $this->mode($this->paths->logDir('bob')));
        $this->assertSame(0, filegroup($this->paths->logDir('bob')));
    }

    public function test_a_failed_link_reports_false(): void
    {
        $this->exitCode = 1;

        $this->assertFalse($this->access()->link($this->bob()));
    }

    public function test_cagefs_mount_state(): void
    {
        $this->assertNull($this->access($this->root . '/no-cagefs.mp')->cagefsMounted(), 'no CageFS');

        file_put_contents($this->root . '/cagefs.mp', "/opt\n@/var/spool/cron,700\n");
        $this->assertFalse($this->access()->cagefsMounted());

        file_put_contents($this->root . '/cagefs.mp', "/opt\n%/var/log/worker_supervisor\n");
        $this->assertTrue($this->access()->cagefsMounted());
    }
}
