<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\AccountProbe;
use WorkerSupervisor\AccountStore;
use WorkerSupervisor\CpUser;
use WorkerSupervisor\IniRenderer;
use WorkerSupervisor\Importer;
use WorkerSupervisor\Logs;
use WorkerSupervisor\Paths;
use WorkerSupervisor\Service;
use WorkerSupervisor\ServiceError;
use WorkerSupervisor\Settings;
use WorkerSupervisor\WorkerValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImporterTest extends TestCase
{
    private string $root;
    private Paths $paths;
    private FakeSupervisor $sv;
    private Importer $importer;
    private IniRenderer $ini;
    /** @var callable(string, string): bool */
    private $rename;
    private Service $svc;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wsv_import_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var/cpanel/users', 0777, true);
        mkdir($this->root . '/etc/supervisord.d', 0777, true);
        mkdir($this->root . '/home/acme/app.example.com', 0777, true);
        touch($this->root . '/home/acme/app.example.com/artisan');
        foreach (['acme', 'bob'] as $u) {
            file_put_contents($this->root . '/var/cpanel/users/' . $u, "OWNER=root\nPLAN=default\n");
        }
        mkdir($this->root . '/home/bob', 0777, true);

        $this->paths = new Paths($this->root);
        $root = $this->root;
        $paths = $this->paths;
        $loader = static fn (string $u): ?CpUser => CpUser::load($paths, $u, static fn () => ['dir' => $root . '/home/' . $u]);
        $probe = new class implements AccountProbe {
            public function memoryLimitMb(string $user): ?int
            {
                return null;
            }

            public function phpVersion(string $user, string $folder): ?string
            {
                return null;
            }
        };
        $this->sv = new FakeSupervisor();
        $this->ini = new IniRenderer($paths);
        $this->svc = new Service(
            $paths,
            new AccountStore($paths),
            Settings::load($paths),
            $this->ini,
            $this->sv,
            new Logs($paths),
            new WorkerValidator($probe),
            $loader,
        );
        $this->rename = static fn (string $from, string $to): bool => rename($from, $to);
        $this->importer = new Importer(
            $paths,
            $this->svc,
            $this->sv,
            $this->ini,
            static fn (): int => 1_800_000_000,
            fn (string $from, string $to): bool => ($this->rename)($from, $to),
        );
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function place(string $name, ?string $content = null): string
    {
        $content ??= (string) file_get_contents(__DIR__ . '/../fixtures/import/acme.ini');
        // The fixture names the real home; point it at the temp tree so the folder exists.
        $content = str_replace('/home/acme', $this->root . '/home/acme', $content);
        $file = $this->paths->iniDir() . '/' . $name;
        file_put_contents($file, $content);

        return $file;
    }

    public function test_propose_from_acme(): void
    {
        $this->place('acme.ini');
        $p = $this->importer->propose('acme.ini', 'acme-laravel-worker', 'acme');
        $w = $p['worker'];

        $this->assertSame($this->root . '/home/acme/app.example.com', $w['folder']);
        $this->assertStringStartsWith('/usr/local/bin/php artisan queue:work --queue=emails,default', $w['command']);
        $this->assertStringNotContainsString('exec', $w['command']);
        $this->assertSame('acme-laravel-worker', $w['name']);
        $this->assertSame('laravel-queue', $w['template']);
        $this->assertSame(5, $w['processes']);
        $this->assertSame(10, $w['startsecs']);
        $this->assertSame(30, $w['startretries']);
        $this->assertSame(3600, $w['stopwaitsecs']);
        $this->assertContains('wrapper_replaced', $p['changes']);
        $this->assertContains('directory_from_cd', $p['changes']);
        $this->assertContains('logs_moved', $p['changes']);
        $this->assertContains('user_key_dropped', $p['changes']);
        $this->assertContains('key_dropped:process_name', $p['changes']);
        $this->assertNotContains('key_dropped:stopasgroup', $p['changes'], 'the plugin always sets it');
        $this->assertNotContains('key_dropped:killasgroup', $p['changes']);
        $this->assertNotContains('key_dropped:directory', $p['changes']);
        $this->assertSame([], $p['issues']);
    }

    public function test_propose_fills_every_form_field_the_file_leaves_out(): void
    {
        $this->place('minimal.ini', "[program:minimal]\ncommand=/bin/su - acme -c \"cd /home/acme/app.example.com && exec php artisan queue:work\"\nstopasgroup=false\n");
        $p = $this->importer->propose('minimal.ini', 'minimal', 'acme');

        $this->assertSame(
            ['processes' => 1, 'autostart' => true, 'autorestart' => 'true', 'startsecs' => 10, 'startretries' => 30, 'stopwaitsecs' => 3600, 'redirect_stderr' => true],
            array_intersect_key($p['worker'], array_flip(['processes', 'autostart', 'autorestart', 'startsecs', 'startretries', 'stopwaitsecs', 'redirect_stderr'])),
        );
        $this->assertContains('always_on:stopasgroup', $p['changes']);
    }

    public function test_propose_without_wrapper_keeps_command_and_directory(): void
    {
        $this->place('plain.ini', "[program:Horizon Job]\ncommand=php artisan horizon\ndirectory=/home/acme/app.example.com/\nuser=acme\nenvironment=A=\"b\"\n");
        $p = $this->importer->propose('plain.ini', 'Horizon Job', 'acme');

        $this->assertSame('php artisan horizon', $p['worker']['command']);
        $this->assertSame($this->root . '/home/acme/app.example.com', $p['worker']['folder']);
        $this->assertSame('laravel-horizon', $p['worker']['template']);
        $this->assertSame('horizon-job', $p['worker']['name']);
        $this->assertNotContains('wrapper_replaced', $p['changes']);
        $this->assertContains('user_key_dropped', $p['changes']);
        $this->assertContains('key_dropped:environment', $p['changes']);
    }

    #[DataProvider('wrappers')]
    public function test_wrapper_variants(string $command, string $expected): void
    {
        $this->place('w.ini', "[program:w]\ncommand=$command\n");
        $p = $this->importer->propose('w.ini', 'w', 'acme');

        $this->assertSame($expected, $p['worker']['command']);
        $this->assertSame('/home/x', $p['worker']['folder']);
        $this->assertContains('wrapper_replaced', $p['changes']);
    }

    /** @return array<string, array{string, string}> */
    public static function wrappers(): array
    {
        return [
            'single quotes' => ["su - bob -c 'cd /home/x && exec php bin/console messenger:consume async'", 'php bin/console messenger:consume async'],
            'shell option' => ['/usr/bin/su -s /bin/bash - bob -c "cd /home/x && php artisan queue:work"', 'php artisan queue:work'],
            'runuser' => ['runuser -l bob -c "cd /home/x/ && exec php artisan queue:work"', 'php artisan queue:work'],
            'escaped quotes' => ['/bin/su - bob -c "cd /home/x && php -r \"sleep(1);\""', 'php -r "sleep(1);"'],
        ];
    }

    public function test_suggested_user(): void
    {
        $this->place('acme.ini');
        $this->place('b.ini', "[program:b]\ncommand=php x\nuser=bob\n");
        $this->place('c.ini', "[program:c]\ncommand=php x\nuser=root\n");
        $found = [];
        foreach ($this->importer->candidates() as $c) {
            foreach ($c['programs'] as $prog) {
                $found[$c['file'] . '/' . $prog['name']] = $prog['suggested_user'];
            }
        }

        $this->assertSame('acme', $found['acme.ini/acme-laravel-worker']);
        $this->assertSame('bob', $found['b.ini/b']);
        $this->assertSame('', $found['c.ini/c']);
    }

    public function test_candidates_skip_managed_files(): void
    {
        $this->place('acme.ini');
        $this->place('wsv_bob_queue.ini', "[program:wsv_bob_queue]\ncommand=php x\n");
        $this->place('other.ini', "[group:g]\nprograms=a\n\n[program:a]\ncommand=php a\n\n[program:b]\ncommand=php b\n");
        $this->place('notes.txt', "[program:z]\ncommand=php z\n");

        $files = array_column($this->importer->candidates(), 'programs', 'file');

        $this->assertSame(['acme.ini', 'other.ini'], array_keys($files));
        $this->assertSame(['a', 'b'], array_column($files['other.ini'], 'name'));
    }

    public function test_apply_backs_up_original(): void
    {
        $file = $this->place('acme.ini');
        $original = (string) file_get_contents($file);
        $p = $this->importer->propose('acme.ini', 'acme-laravel-worker', 'acme');

        $this->importer->apply('acme.ini', 'acme-laravel-worker', 'acme', $p['worker']);

        $this->assertFileDoesNotExist($file);
        $backups = glob($this->paths->backupDir() . '/*') ?: [];
        $this->assertCount(1, $backups);
        $this->assertSame(date('Ymd-His', 1_800_000_000) . '-acme.ini', basename($backups[0]));
        $this->assertSame($original, file_get_contents($backups[0]));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($backups[0])), -4));
        $this->assertFileExists($this->paths->iniFile('acme', 'acme-laravel-worker'));
        $this->assertContains(['remove_unmanaged', 'acme-laravel-worker'], $this->sv->calls);
        $this->assertSame(['acme-laravel-worker'], array_column($this->svc->listWorkers('acme')['workers'], 'name'));
    }

    public function test_apply_removes_only_the_program_block(): void
    {
        $file = $this->place('multi.ini', "[group:g]\nprograms=a,b\n\n[program:a]\ncommand=php artisan queue:work\ndirectory=/home/acme/app.example.com\n\n[program:b]\ncommand=php b\n");
        $p = $this->importer->propose('multi.ini', 'a', 'acme');

        $this->importer->apply('multi.ini', 'a', 'acme', $p['worker']);

        $left = (string) file_get_contents($file);
        $this->assertStringContainsString('[group:g]', $left);
        $this->assertStringContainsString('[program:b]', $left);
        $this->assertStringNotContainsString('[program:a]', $left);
        $this->assertStringNotContainsString('queue:work', $left);
    }

    public function test_apply_changes_nothing_when_validation_fails(): void
    {
        $file = $this->place('acme.ini');
        $before = (string) file_get_contents($file);
        $p = $this->importer->propose('acme.ini', 'acme-laravel-worker', 'acme');
        $p['worker']['folder'] = '/etc';

        try {
            $this->importer->apply('acme.ini', 'acme-laravel-worker', 'acme', $p['worker']);
            $this->fail('Expected ServiceError');
        } catch (ServiceError $e) {
            $this->assertSame('validation_failed', $e->errorCode);
        }

        $this->assertSame($before, file_get_contents($file));
        $this->assertSame([], glob($this->paths->backupDir() . '/*') ?: []);
        $this->assertSame([], $this->sv->calls);
    }

    public function test_unknown_file_or_program_is_refused(): void
    {
        $this->place('acme.ini');
        foreach ([['../acme.ini', 'x'], ['wsv_a.ini', 'x'], ['acme.ini', 'nope']] as [$file, $program]) {
            try {
                $this->importer->propose($file, $program, 'acme');
                $this->fail('Expected ServiceError');
            } catch (ServiceError $e) {
                $this->assertSame('bad_request', $e->errorCode);
            }
        }
    }

    public function test_unsupported_name_is_marked_and_apply_changes_nothing(): void
    {
        $file = $this->place('spaced.ini', "[program:Horizon Job]\ncommand=php artisan horizon\ndirectory=/home/acme/app.example.com\n");
        $before = (string) file_get_contents($file);
        $program = $this->importer->candidates()[0]['programs'][0];
        $this->assertFalse($program['importable']);
        $this->assertSame('program_name_unsupported', $program['reason']);

        $p = $this->importer->propose('spaced.ini', 'Horizon Job', 'acme');
        $this->assertApplyRefused('spaced.ini', 'Horizon Job', $p['worker'], 'program_name_unsupported');
        $this->assertSame($before, file_get_contents($file));
    }

    public function test_apply_refuses_a_name_equal_to_the_new_managed_program(): void
    {
        $file = $this->place('c.ini', "[program:acme_queue]\ncommand=php artisan queue:work\ndirectory=/home/acme/app.example.com\n");
        $p = $this->importer->propose('c.ini', 'acme_queue', 'acme');
        $p['worker']['name'] = 'queue';

        $this->assertApplyRefused('c.ini', 'acme_queue', $p['worker'], 'program_name_collision');
        $this->assertFileExists($file);
    }

    /** @param array<string, mixed> $worker */
    private function assertApplyRefused(string $file, string $program, array $worker, string $reason): void
    {
        try {
            $this->importer->apply($file, $program, 'acme', $worker);
            $this->fail('Expected ServiceError');
        } catch (ServiceError $e) {
            $this->assertSame('bad_request', $e->errorCode);
            $this->assertSame($reason, $e->params['reason']);
        }
        $this->assertSame([], $this->sv->calls);
        $this->assertSame([], glob($this->paths->backupDir() . '/*') ?: []);
        $this->assertSame([], glob($this->paths->iniDir() . '/wsv_*') ?: []);
    }

    public function test_user_mismatch_and_ran_as_root_warnings(): void
    {
        $this->place('acme.ini');
        $this->place('root.ini', "[program:r]\ncommand=php x\nuser=root\n");
        $this->place('bare.ini', "[program:s]\ncommand=php x\n");
        $this->place('bob.ini', "[program:t]\ncommand=php x\nuser=bob\n");

        $mismatch = $this->importer->propose('acme.ini', 'acme-laravel-worker', 'bob');
        $this->assertContains('user_mismatch:acme:bob', $mismatch['changes']);
        $this->assertContains('user_mismatch:bob:acme', $this->importer->propose('bob.ini', 't', 'acme')['changes']);
        $this->assertContains('ran_as_root', $this->importer->propose('root.ini', 'r', 'acme')['changes']);
        $this->assertContains('ran_as_root', $this->importer->propose('bare.ini', 's', 'acme')['changes']);
        $ok = $this->importer->propose('acme.ini', 'acme-laravel-worker', 'acme')['changes'];
        $this->assertSame([], preg_grep('/^(user_mismatch|ran_as_root)/', $ok));
    }

    public function test_failed_file_swap_rolls_back_and_keeps_the_old_program(): void
    {
        $file = $this->place('multi.ini', "[program:a]\ncommand=php artisan queue:work\ndirectory=/home/acme/app.example.com\n\n[program:b]\ncommand=php b\n");
        $before = (string) file_get_contents($file);
        $p = $this->importer->propose('multi.ini', 'a', 'acme');
        $this->rename = static fn (): bool => false;

        try {
            $this->importer->apply('multi.ini', 'a', 'acme', $p['worker']);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('multi.ini', $e->getMessage());
        }

        $this->assertSame($before, file_get_contents($file));
        $this->assertFileDoesNotExist($file . '.wsv-tmp');
        $this->assertSame([], glob($this->paths->backupDir() . '/*') ?: []);
        $this->assertNotContains(['remove_unmanaged', 'a'], $this->sv->calls);
        $this->assertSame([], $this->svc->listWorkers('acme')['workers']);
    }

    public function test_sections_follow_supervisors_header_and_continuation_rules(): void
    {
        $file = $this->place('s.ini', "[program:a]\ncommand=php artisan queue:work\n  --tries=3\ndirectory=/home/acme/app.example.com\n  [program:fake]\n\n[program:b] ; note\ncommand=php b\n");
        $names = array_column($this->importer->candidates()[0]['programs'], 'name');
        $this->assertSame(['a', 'b'], $names);

        $p = $this->importer->propose('s.ini', 'a', 'acme');
        $this->assertSame('php artisan queue:work' . "\n" . '--tries=3', $p['worker']['command']);

        $this->importer->apply('s.ini', 'a', 'acme', ['command' => 'php artisan queue:work --tries=3', 'folder' => $this->root . '/home/acme/app.example.com'] + $p['worker']);
        $left = (string) file_get_contents($file);
        $this->assertStringContainsString('[program:b] ; note', $left);
        $this->assertStringContainsString('command=php b', $left);
        $this->assertStringNotContainsString('queue:work', $left);
        $this->assertStringNotContainsString('[program:fake]', $left);
    }
}
