<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\Account;
use WorkerSupervisor\Identity;
use WorkerSupervisor\IniRenderer;
use WorkerSupervisor\Paths;
use WorkerSupervisor\Worker;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class IniRendererTest extends TestCase
{
    private string $root;
    private Paths $paths;
    private IniRenderer $renderer;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wsv_ini_' . bin2hex(random_bytes(4));
        $this->paths = new Paths($this->root);
        $this->renderer = new IniRenderer($this->paths);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function worker(array $extra = []): Worker
    {
        return Worker::fromArray(array_merge(
            ['name' => 'queue', 'template' => 'custom', 'folder' => '/home/alice/app', 'command' => 'php artisan queue:work'],
            $extra,
        ));
    }

    private function mode(string $path): string
    {
        clearstatcache();

        return substr(sprintf('%o', fileperms($path)), -4);
    }

    public function test_program_name(): void
    {
        $this->assertSame('alice_queue', $this->renderer->programName('alice', 'queue'));
    }

    public function test_ini_never_contains_client_text(): void
    {
        $command = 'php x "a" %(here)s $HOME; echo';
        $folder = '/home/bob/my app';
        $ini = $this->renderer->renderIni('bob', $this->worker(['folder' => $folder, 'command' => $command]), true);

        $this->assertStringNotContainsString($command, $ini);
        $this->assertStringNotContainsString($folder, $ini);
        $this->assertStringNotContainsString('%(here)s', $ini);
        $this->assertStringNotContainsString('directory=', $ini);
    }

    public function test_ini_matches_expected(): void
    {
        $w = $this->worker([
            'processes' => 2,
            'autorestart' => 'unexpected',
            'startsecs' => 5,
            'startretries' => 3,
            'stopwaitsecs' => 60,
        ]);

        $expected = <<<'INI'
            ; Managed by Worker Supervisor - do not edit, changes are overwritten.
            [program:alice_queue]
            command=/usr/local/cpanel/3rdparty/worker_supervisor/bin/wsv-run alice queue
            process_name=%(program_name)s_%(process_num)02d
            numprocs=2
            user=root
            autostart=true
            autorestart=unexpected
            startsecs=5
            startretries=3
            stopwaitsecs=60
            stopsignal=TERM
            stopasgroup=true
            killasgroup=true
            redirect_stderr=true
            stdout_logfile=/var/log/worker_supervisor/alice/queue_%(process_num)02d.log
            stdout_logfile_maxbytes=10MB
            stdout_logfile_backups=3

            INI;

        $this->assertSame($expected, $this->renderer->renderIni('alice', $w, true));
    }

    public function test_not_runnable_forces_autostart_false(): void
    {
        $this->assertStringContainsString("\nautostart=false\n", $this->renderer->renderIni('alice', $this->worker(), false));
        $this->assertStringContainsString(
            "\nautostart=false\n",
            $this->renderer->renderIni('alice', $this->worker(['autostart' => false]), true),
        );
    }

    public function test_stderr_log_only_when_not_redirected(): void
    {
        $redirected = $this->renderer->renderIni('alice', $this->worker(), true);
        $this->assertStringNotContainsString('stderr_logfile', $redirected);

        $separate = $this->renderer->renderIni('alice', $this->worker(['redirect_stderr' => false]), true);
        $this->assertStringContainsString("\nredirect_stderr=false\n", $separate);
        $this->assertStringEndsWith(
            "stdout_logfile_backups=3\n"
            . "stderr_logfile=/var/log/worker_supervisor/alice/queue_%(process_num)02d.err.log\n"
            . "stderr_logfile_maxbytes=10MB\n"
            . "stderr_logfile_backups=3\n",
            $separate,
        );
    }

    public function test_render_launch(): void
    {
        $this->assertSame("/home/alice/app\nphp artisan queue:work\n1001\n", $this->renderer->renderLaunch($this->worker(), 1001));
        $this->assertSame("/home/alice/app\nphp artisan queue:work\n\n", $this->renderer->renderLaunch($this->worker(), null));
    }

    public function test_launch_file_carries_the_uid_of_the_account(): void
    {
        $this->renderer->write(new Account('alice', identity: new Identity(1001, '1793487157')), $this->worker(), true, false);

        $lines = explode("\n", (string) file_get_contents($this->paths->launchFile('alice', 'queue')));
        $this->assertSame('1001', $lines[2]);
    }

    public function test_launch_rejects_line_breaks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->renderer->renderLaunch($this->worker(['command' => "php a\nrm -rf ~"]), null);
    }

    public function test_unsafe_identifiers_are_rejected(): void
    {
        $cases = [
            fn () => $this->renderer->renderIni('alice', $this->worker(['name' => "q\n[program:x]"]), true),
            fn () => $this->renderer->renderIni('Alice_x', $this->worker(), true),
            fn () => $this->renderer->write(new Account('alice'), $this->worker(['name' => '../../etc']), true, false),
            fn () => $this->renderer->remove('alice', '../queue'),
        ];
        foreach ($cases as $i => $case) {
            try {
                $case();
                $this->fail("case $i did not throw");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_write_reports_change(): void
    {
        $w = $this->worker();
        $this->assertTrue($this->renderer->write(new Account('alice'), $w, true, false));
        $this->assertFalse($this->renderer->write(new Account('alice'), $w, true, false));
        $this->assertTrue($this->renderer->write(new Account('alice'), $w, false, false));

        $ini = $this->paths->iniFile('alice', 'queue');
        $launch = $this->paths->launchFile('alice', 'queue');
        $this->assertSame($this->renderer->renderIni('alice', $w, false), file_get_contents($ini));
        $this->assertSame($this->renderer->renderLaunch($w, null), file_get_contents($launch));
        $this->assertSame('0644', $this->mode($ini));
        $this->assertSame('0600', $this->mode($launch));
        $this->assertSame('0700', $this->mode(dirname($launch)));
        $this->assertSame('0700', $this->mode($this->paths->logDir('alice')));
    }

    public function test_command_change_rewrites_launch_but_not_ini(): void
    {
        $this->renderer->write(new Account('alice'), $this->worker(), true, false);
        $changed = $this->worker(['command' => 'php artisan horizon']);

        $this->assertFalse($this->renderer->write(new Account('alice'), $changed, true, false));
        $this->assertSame($this->renderer->renderLaunch($changed, null), file_get_contents($this->paths->launchFile('alice', 'queue')));
    }

    public function test_remove_deletes_ini_launch_and_logs(): void
    {
        $this->renderer->write(new Account('alice'), $this->worker(), true, false);
        $this->renderer->write(new Account('alice'), $this->worker(['name' => 'queue-high']), true, false);
        $logs = $this->paths->logDir('alice');
        $own = ['queue_00.log', 'queue_00.log.1', 'queue_01.err.log', 'queue_01.err.log.3'];
        $others = ['queue-high_00.log', 'queue-high_00.err.log.2'];
        foreach (array_merge($own, $others) as $f) {
            touch("$logs/$f");
        }

        $this->renderer->remove('alice', 'queue');

        $this->assertFileDoesNotExist($this->paths->iniFile('alice', 'queue'));
        $this->assertFileDoesNotExist($this->paths->launchFile('alice', 'queue'));
        foreach ($own as $f) {
            $this->assertFileDoesNotExist("$logs/$f");
        }
        foreach ($others as $f) {
            $this->assertFileExists("$logs/$f");
        }
        $this->assertFileExists($this->paths->iniFile('alice', 'queue-high'));
        $this->assertFileExists($this->paths->launchFile('alice', 'queue-high'));
    }

    public function test_hold_marker_follows_root_holds(): void
    {
        $a = new Account('alice');
        $hold = $this->paths->holdFile('alice', 'queue');

        $this->renderer->write($a, $this->worker(), false, true);
        $this->assertFileExists($hold);
        $this->assertSame('0600', $this->mode($hold));

        $this->renderer->write($a, $this->worker(['desired' => 'stopped']), false, false);
        $this->assertFileDoesNotExist($hold, 'a client stop is not a hold');

        $this->renderer->write($a, $this->worker(), false, true);
        $this->renderer->remove('alice', 'queue');
        $this->assertFileDoesNotExist($hold);
    }

    public function test_a_held_worker_cannot_be_runnable(): void
    {
        $this->expectException(LogicException::class);
        $this->renderer->write(new Account('alice'), $this->worker(), true, true);
    }

    public function test_remove_of_missing_worker_is_a_no_op(): void
    {
        $this->renderer->remove('alice', 'ghost');
        $this->assertDirectoryDoesNotExist($this->paths->logDir('alice'));
    }
}
