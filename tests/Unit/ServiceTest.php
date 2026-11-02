<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\Account;
use WorkerSupervisor\AccountProbe;
use WorkerSupervisor\AccountStore;
use WorkerSupervisor\CpUser;
use WorkerSupervisor\IniRenderer;
use WorkerSupervisor\Issue;
use WorkerSupervisor\Logs;
use WorkerSupervisor\Paths;
use WorkerSupervisor\Service;
use WorkerSupervisor\ServiceError;
use WorkerSupervisor\Settings;
use WorkerSupervisor\Supervisor\ProcessStatus;
use WorkerSupervisor\Worker;
use WorkerSupervisor\WorkerValidator;
use PHPUnit\Framework\TestCase;

final class ServiceTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private string $root;
    private string $home;
    private Paths $paths;
    private Settings $settings;
    private AccountStore $store;
    private FakeSupervisor $sv;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wsv_svc_' . bin2hex(random_bytes(4));
        $this->home = $this->root . '/home/bob';
        mkdir($this->root . '/var/cpanel/users', 0777, true);
        mkdir($this->home . '/app', 0777, true);
        touch($this->home . '/app/artisan');
        file_put_contents($this->root . '/var/cpanel/users/bob', "OWNER=root\nPLAN=default\n");

        $this->paths = new Paths($this->root);
        $this->settings = Settings::load($this->paths);
        $this->store = new AccountStore($this->paths);
        $this->sv = new FakeSupervisor();
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root);
    }

    private function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        foreach (glob($path . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $c) {
            $this->rmrf($c);
        }
        @rmdir($path);
    }

    /** @param (callable(string): ?CpUser)|null $loader */
    private function service(?callable $loader = null, ?\WorkerSupervisor\LogAccess $logAccess = null): Service
    {
        $paths = $this->paths;
        $home = $this->home;
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

        return new Service(
            $this->paths,
            $this->store,
            $this->settings,
            new IniRenderer($this->paths),
            $this->sv,
            new Logs($this->paths),
            new WorkerValidator($probe),
            $loader ?? static fn (string $u): ?CpUser => CpUser::load($paths, $u, static fn () => ['dir' => $home]),
            static fn (): int => self::NOW,
            $logAccess,
        );
    }

    /** @return callable(string): ?CpUser */
    private function loaderWithUid(int $uid): callable
    {
        $paths = $this->paths;
        $home = $this->home;

        return static fn (string $u): ?CpUser => CpUser::load($paths, $u, static fn () => ['uid' => $uid, 'dir' => $home]);
    }

    public function test_save_gives_the_account_its_logs_before_supervisord_starts_the_worker(): void
    {
        $runs = [];
        $runner = new class ($runs) implements \WorkerSupervisor\Supervisor\Runner {
            public function __construct(private array &$runs)
            {
            }

            public function run(array $argv): array
            {
                $this->runs[] = $argv;

                return [0, ''];
            }

            public function detach(array $argv): void
            {
            }
        };
        $logAccess = new \WorkerSupervisor\LogAccess($this->paths, $runner, static fn () => ['gid' => posix_getgid()], $this->root . '/cagefs.mp');

        $this->service(null, $logAccess)->save('bob', $this->input(), 'form', null);

        $this->assertSame(02750, fileperms($this->paths->logDir('bob')) & 07777);
        $this->assertSame(['/usr/sbin/runuser', '-u', 'bob'], array_slice($runs[0], 0, 3));
    }

    private function input(array $over = []): array
    {
        return $over + [
            'name' => 'queue',
            'template' => 'laravel-queue',
            'folder' => $this->home . '/app',
            'command' => 'php artisan queue:work',
            'processes' => 1,
        ];
    }

    private function storedWorker(string $name = 'queue'): Worker
    {
        $w = $this->store->load('bob')->workers[$name] ?? null;
        $this->assertNotNull($w, "worker $name is stored");

        return $w;
    }

    private function ini(string $name = 'queue'): string
    {
        return (string) file_get_contents($this->paths->iniFile('bob', $name));
    }

    private function expectServiceError(string $code, callable $fn): ServiceError
    {
        try {
            $fn();
        } catch (ServiceError $e) {
            $this->assertSame($code, $e->errorCode);
            return $e;
        }
        $this->fail("Expected ServiceError $code");
    }

    /** @param list<Issue|array> $issues */
    private function codes(array $issues): array
    {
        return array_map(static fn ($i) => $i instanceof Issue ? $i->code : $i['code'], $issues);
    }

    private function setAdminStopped(string $name, bool $stopped = true): void
    {
        $this->store->transaction('bob', static fn (Account $a) => [
            $a->withWorker($a->workers[$name]->with(['admin_stopped' => $stopped])),
            null,
        ]);
    }

    public function test_service_error_codes(): void
    {
        $this->assertSame(
            ['account_unknown', 'account_mismatch', 'worker_unknown', 'validation_failed', 'admin_stopped', 'suspended', 'feature_off', 'bad_request', 'internal'],
            ServiceError::CODES,
        );
        $e = new ServiceError('worker_unknown', ['name' => 'x']);
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertSame(['name' => 'x'], $e->params);
        $this->assertSame([], $e->issues);
    }

    public function test_save_new_worker_writes_ini_and_applies(): void
    {
        $result = $this->service()->save('bob', $this->input(), 'form', null);

        $this->assertSame([['apply', 'bob_queue', true]], $this->sv->calls);
        $this->assertStringContainsString("autostart=true\n", $this->ini());
        $this->assertFileExists($this->paths->launchFile('bob', 'queue'));
        $w = $this->storedWorker();
        $this->assertSame('running', $w->desired);
        $this->assertSame($w->toArray(), $result['worker']);
        $this->assertStringStartsWith('[program:queue]', $result['expert']);
        $this->assertSame([], $result['issues']);
    }

    public function test_save_does_not_start_a_worker_of_an_account_cpanel_suspended(): void
    {
        // The suspend hook has not updated the account flag yet.
        mkdir($this->root . '/var/cpanel/suspended', 0777, true);
        touch($this->paths->suspendedFlag('bob'));

        $this->service()->save('bob', $this->input(), 'form', null);

        $this->assertSame([['apply', 'bob_queue', false]], $this->sv->calls);
        $this->assertStringContainsString("autostart=false\n", $this->ini());
        $this->assertFileExists($this->paths->holdFile('bob', 'queue'));
    }

    public function test_save_with_errors_throws_validation_failed_and_writes_nothing(): void
    {
        $e = $this->expectServiceError('validation_failed', fn () => $this->service()->save(
            'bob',
            $this->input(['processes' => 0, 'folder' => '/etc']),
            'form',
            null,
        ));

        $this->assertContainsOnlyInstancesOf(Issue::class, $e->issues);
        $this->assertContains('processes_range', $this->codes($e->issues));
        $this->assertContains('folder_outside_home', $this->codes($e->issues));
        $this->assertFileDoesNotExist($this->paths->accountFile('bob'));
        $this->assertFileDoesNotExist($this->paths->iniFile('bob', 'queue'));
        $this->assertFileDoesNotExist($this->paths->launchFile('bob', 'queue'));
        $this->assertSame([], $this->sv->calls);
    }

    public function test_save_warnings_do_not_block(): void
    {
        $result = $this->service()->save('bob', $this->input(['autorestart' => 'unexpected']), 'form', null);

        $this->assertSame(['laravel_needs_autorestart'], $this->codes($result['issues']));
        $this->assertSame('unexpected', $this->storedWorker()->autorestart);
    }

    public function test_stop_returns_immediately_and_disables_autostart(): void
    {
        $svc = $this->service();
        $svc->save('bob', $this->input(), 'form', null);
        $this->sv->calls = [];

        $svc->stop('bob', 'queue');

        $this->assertSame([['stop', 'bob_queue']], $this->sv->calls);
        $this->assertStringContainsString("autostart=false\n", $this->ini());
        $this->assertSame('stopped', $this->storedWorker()->desired);
        $this->assertFileDoesNotExist($this->paths->holdFile('bob', 'queue'), 'a client stop is not a hold');
    }

    public function test_start_sets_desired_running_and_applies_with_start(): void
    {
        $svc = $this->service();
        $svc->save('bob', $this->input(), 'form', null);
        $svc->stop('bob', 'queue');
        $this->sv->calls = [];

        $svc->start('bob', 'queue');

        $this->assertSame([['apply', 'bob_queue', true]], $this->sv->calls);
        $this->assertStringContainsString("autostart=true\n", $this->ini());
        $this->assertSame('running', $this->storedWorker()->desired);

        // Also loads a program supervisord lost or never loaded.
        $this->sv->calls = [];
        $svc->start('bob', 'queue');
        $this->assertSame([['apply', 'bob_queue', true]], $this->sv->calls, 'ini unchanged, e.g. a failed worker');
    }

    public function test_start_refused_when_admin_stopped(): void
    {
        $svc = $this->service();
        $svc->save('bob', $this->input(), 'form', null);
        $this->setAdminStopped('queue');
        $this->sv->calls = [];

        $this->expectServiceError('admin_stopped', fn () => $svc->start('bob', 'queue'));
        $this->expectServiceError('admin_stopped', fn () => $svc->restart('bob', 'queue'));

        $this->assertSame([], $this->sv->calls);
    }

    public function test_start_refused_when_suspended_or_feature_off(): void
    {
        $svc = $this->service();
        $svc->save('bob', $this->input(), 'form', null);
        $this->sv->calls = [];

        $this->store->transaction('bob', static fn (Account $a) => [$a->withFlags(true, false), null]);
        $this->expectServiceError('suspended', fn () => $svc->start('bob', 'queue'));

        $this->store->transaction('bob', static fn (Account $a) => [$a->withFlags(true, true), null]);
        $this->expectServiceError('feature_off', fn () => $svc->restart('bob', 'queue'));

        // cPanel's own suspension counts even before the account flag catches up.
        $this->store->transaction('bob', static fn (Account $a) => [$a->withFlags(false, false), null]);
        mkdir($this->root . '/var/cpanel/suspended', 0777, true);
        touch($this->paths->suspendedFlag('bob'));
        $this->expectServiceError('suspended', fn () => $svc->start('bob', 'queue'));

        $this->assertSame([], $this->sv->calls);
    }

    public function test_restart_restarts_a_running_worker_and_starts_a_stopped_one(): void
    {
        $svc = $this->service();
        $svc->save('bob', $this->input(), 'form', null);
        $this->sv->calls = [];

        $svc->restart('bob', 'queue');
        $this->assertSame([['restart', 'bob_queue']], $this->sv->calls);

        $svc->stop('bob', 'queue');
        $this->sv->calls = [];
        $svc->restart('bob', 'queue');
        $this->assertSame([['apply', 'bob_queue', true]], $this->sv->calls);
        $this->assertSame('running', $this->storedWorker()->desired);
        $this->assertStringContainsString("autostart=true\n", $this->ini());
    }

    public function test_rename_removes_old_program(): void
    {
        $svc = $this->service();
        $svc->save('bob', $this->input(['name' => 'old']), 'form', null);
        $this->sv->calls = [];

        $svc->save('bob', $this->input(['name' => 'new']), 'form', 'old');

        $this->assertSame([['remove', 'bob_old'], ['apply', 'bob_new', true]], $this->sv->calls);
        $this->assertFileDoesNotExist($this->paths->iniFile('bob', 'old'));
        $this->assertFileDoesNotExist($this->paths->launchFile('bob', 'old'));
        $this->assertFileExists($this->paths->iniFile('bob', 'new'));
        $this->assertSame(['new'], array_keys($this->store->load('bob')->workers));
    }

    public function test_edit_picks_the_supervisor_action_from_the_diff(): void
    {
        $svc = $this->service();
        $svc->save('bob', $this->input(), 'form', null);

        $this->sv->calls = [];
        $svc->save('bob', $this->input(), 'form', 'queue');
        $this->assertSame([], $this->sv->calls, 'no change, no action');

        $this->sv->calls = [];
        $svc->save('bob', $this->input(['command' => 'php artisan queue:work --tries=5']), 'form', 'queue');
        $this->assertSame([['restart', 'bob_queue']], $this->sv->calls, 'launch file only');

        $this->sv->calls = [];
        $svc->save('bob', $this->input(['processes' => 2, 'command' => 'php artisan queue:work --tries=5']), 'form', 'queue');
        $this->assertSame([['apply', 'bob_queue', true]], $this->sv->calls, 'ini changed');

        $svc->stop('bob', 'queue');
        $this->sv->calls = [];
        $svc->save('bob', $this->input(['processes' => 3, 'command' => 'php artisan queue:work']), 'form', 'queue');
        $this->assertSame([['apply', 'bob_queue', false]], $this->sv->calls, 'stopped worker is not started');
        $this->assertSame('stopped', $this->storedWorker()->desired);
    }

    public function test_input_cannot_set_desired_or_admin_stopped(): void
    {
        $svc = $this->service();
        $svc->save('bob', $this->input(['desired' => 'stopped', 'admin_stopped' => true]), 'form', null);
        $w = $this->storedWorker();
        $this->assertSame('running', $w->desired);
        $this->assertFalse($w->adminStopped);

        $this->setAdminStopped('queue');
        $svc->stop('bob', 'queue');
        $svc->save('bob', $this->input(['desired' => 'running', 'admin_stopped' => false]), 'form', 'queue');
        $w = $this->storedWorker();
        $this->assertSame('stopped', $w->desired);
        $this->assertTrue($w->adminStopped);
    }

    public function test_edit_keeps_stored_fields_missing_from_input(): void
    {
        $svc = $this->service();
        $svc->save('bob', $this->input(['stopwaitsecs' => 120]), 'form', null);

        $svc->save('bob', ['name' => 'queue', 'processes' => '2'], 'form', 'queue');

        $w = $this->storedWorker();
        $this->assertSame(2, $w->processes);
        $this->assertSame(120, $w->stopwaitsecs);
        $this->assertSame($this->home . '/app', $w->folder);
    }

    public function test_validate_turns_bad_input_into_issues(): void
    {
        $svc = $this->service();

        $r = $svc->validate('bob', $this->input(['processes' => 'two', 'startsecs' => '1.5', 'stopwaitsecs' => ['x']]), 'form', null);
        $this->assertNull($r['worker']);
        $this->assertNull($r['expert']);
        $this->assertSame(['value_invalid', 'value_invalid', 'value_invalid'], $this->codes($r['issues']));
        $this->assertSame(['processes', 'startsecs', 'stopwaitsecs'], array_column(array_column($r['issues'], 'params'), 'field'));

        $r = $svc->validate('bob', $this->input(['autostart' => 'maybe']), 'form', null);
        $this->assertSame(['input_invalid'], $this->codes($r['issues']));
        $this->assertNotSame('', $r['issues'][0]['params']['message']);

        $missingName = $this->input();
        unset($missingName['name']);
        $this->assertSame(['input_invalid'], $this->codes($svc->validate('bob', $missingName, 'form', null)['issues']));

        $this->assertSame(['input_invalid'], $this->codes($svc->validate('bob', $this->input(), 'yaml', null)['issues']));

        $e = $this->expectServiceError('validation_failed', fn () => $svc->save('bob', $this->input(['processes' => 'two']), 'form', null));
        $this->assertSame(['value_invalid'], $this->codes($e->issues));
        $this->assertFileDoesNotExist($this->paths->accountFile('bob'));
    }

    public function test_validate_accepts_integer_strings_and_returns_worker_and_expert(): void
    {
        $r = $this->service()->validate('bob', $this->input(['processes' => '2', 'startsecs' => 5, 'autostart' => 'false']), 'form', null);

        $this->assertSame([], $r['issues']);
        $this->assertSame(2, $r['worker']['processes']);
        $this->assertFalse($r['worker']['autostart']);
        $this->assertStringContainsString("numprocs=2\n", $r['expert']);
        $this->assertFileDoesNotExist($this->paths->accountFile('bob'));
    }

    public function test_validate_excludes_the_worker_being_edited(): void
    {
        $svc = $this->service();
        $svc->save('bob', $this->input(), 'form', null);
        $svc->save('bob', $this->input(['name' => 'other']), 'form', null);

        $this->assertSame([], $svc->validate('bob', $this->input(['processes' => 9]), 'form', 'queue')['issues']);
        $this->assertSame(['name_taken'], $this->codes($svc->validate('bob', $this->input(['name' => 'other']), 'form', 'queue')['issues']));
        $this->assertSame(['name_taken'], $this->codes($svc->validate('bob', $this->input(), 'form', null)['issues']));
    }

    public function test_expert_mode_save_uses_parsed_values(): void
    {
        $text = "[program:mail]\ncommand=php artisan queue:work --queue=mail\ndirectory={$this->home}/app\nnumprocs=2\nstopwaitsecs=60\n";

        $this->service()->save('bob', ['template' => 'laravel-queue', 'expert_text' => $text], 'expert', null);

        $w = $this->storedWorker('mail');
        $this->assertSame('laravel-queue', $w->template);
        $this->assertSame('php artisan queue:work --queue=mail', $w->command);
        $this->assertSame($this->home . '/app', $w->folder);
        $this->assertSame(2, $w->processes);
        $this->assertSame(60, $w->stopwaitsecs);
        $this->assertSame([['apply', 'bob_mail', true]], $this->sv->calls);
    }

    public function test_expert_mode_edits_the_stored_worker_and_reports_parse_errors(): void
    {
        $svc = $this->service();
        $svc->save('bob', $this->input(['stopwaitsecs' => 120]), 'form', null);
        $svc->stop('bob', 'queue');

        $svc->save('bob', ['expert_text' => "[program:queue]\nnumprocs=3\n"], 'expert', 'queue');
        $w = $this->storedWorker();
        $this->assertSame(3, $w->processes);
        $this->assertSame(120, $w->stopwaitsecs);
        $this->assertSame('stopped', $w->desired);

        $r = $svc->validate('bob', ['template' => 'laravel-queue', 'expert_text' => "[program:queue]\nuser=root\n"], 'expert', 'queue');
        $this->assertSame(['expert_key_forbidden'], $this->codes($r['issues']));
        $this->assertSame(2, $r['issues'][0]['line']);
        $this->assertNull($r['worker']);
    }

    public function test_list_usage_counts_processes(): void
    {
        $svc = $this->service();
        $svc->save('bob', $this->input(['name' => 'a', 'processes' => 2]), 'form', null);
        $svc->save('bob', $this->input(['name' => 'b', 'processes' => 3]), 'form', null);
        $this->sv->procs = [
            new ProcessStatus('bob_a', 'bob_a_00', 'RUNNING', 10, 30, ''),
            new ProcessStatus('bob_a', 'bob_a_01', 'RUNNING', 11, 90, ''),
            new ProcessStatus('bob_b', 'bob_b_00', 'FATAL', null, null, ''),
            new ProcessStatus('alice_a', 'alice_a_00', 'RUNNING', 12, 5, ''),
        ];

        $r = $svc->listWorkers('bob');

        $this->assertSame(
            ['processes' => 5, 'max_processes' => 10, 'workers' => 2, 'max_workers' => 5, 'over_limit' => false],
            $r['usage'],
        );
        $this->assertSame(['a', 'b'], array_column($r['workers'], 'name'));
        $this->assertSame(['running', 'failed'], array_column($r['workers'], 'status'));
        $this->assertSame([2, 0], array_column($r['workers'], 'running'));
        $this->assertSame(self::NOW - 90, $r['workers'][0]['since']);
    }

    public function test_usage_over_limit_after_downgrade(): void
    {
        $make = fn (string $name) => Worker::fromArray($this->input(['name' => $name, 'processes' => 6]));
        $this->store->save(new Account('bob', ['a' => $make('a'), 'b' => $make('b')]));
        $this->settings->setOverride('bob', ['max_processes' => 10]);
        $svc = $this->service();

        $r = $svc->listWorkers('bob');

        $this->assertSame(12, $r['usage']['processes']);
        $this->assertSame(10, $r['usage']['max_processes']);
        $this->assertTrue($r['usage']['over_limit']);
        $this->assertSame([6, 6], array_column($r['workers'], 'processes'));
        $this->assertSame([], $this->sv->calls);

        $e = $this->expectServiceError('validation_failed', fn () => $svc->save('bob', $this->input(['name' => 'c']), 'form', null));
        $this->assertSame(['processes_range'], $this->codes($e->issues));
        $this->assertSame(['a', 'b'], array_keys($this->store->load('bob')->workers));
    }

    public function test_over_limit_account_may_edit_but_not_grow(): void
    {
        $make = fn (string $name) => Worker::fromArray($this->input(['name' => $name, 'processes' => 6]));
        $this->store->save(new Account('bob', ['a' => $make('a'), 'b' => $make('b')]));
        $this->settings->setOverride('bob', ['max_processes' => 10, 'max_workers' => 1]);
        $svc = $this->service();

        $svc->save('bob', $this->input(['name' => 'a', 'processes' => 6, 'command' => 'php artisan queue:work --tries=1']), 'form', 'a');
        $this->assertSame('php artisan queue:work --tries=1', $this->storedWorker('a')->command);

        $e = $this->expectServiceError('validation_failed', fn () => $svc->save('bob', $this->input(['name' => 'a', 'processes' => 7]), 'form', 'a'));
        $this->assertSame(['processes_range'], $this->codes($e->issues));
    }

    public function test_delete_removes_files_and_program(): void
    {
        $svc = $this->service();
        $svc->save('bob', $this->input(), 'form', null);
        $this->sv->calls = [];

        $svc->delete('bob', 'queue');

        $this->assertSame([['remove', 'bob_queue']], $this->sv->calls);
        $this->assertSame([], $this->store->load('bob')->workers);
        $this->assertFileDoesNotExist($this->paths->iniFile('bob', 'queue'));
        $this->assertFileDoesNotExist($this->paths->launchFile('bob', 'queue'));
    }

    public function test_unknown_worker(): void
    {
        $svc = $this->service();

        foreach (['delete', 'start', 'stop', 'restart', 'getWorker', 'clearLogs'] as $method) {
            $e = $this->expectServiceError('worker_unknown', fn () => $svc->$method('bob', 'ghost'));
            $this->assertSame(['name' => 'ghost'], $e->params);
        }
        $this->expectServiceError('worker_unknown', fn () => $svc->logs('bob', 'ghost', 0, 'out', 10));
        $this->expectServiceError('worker_unknown', fn () => $svc->save('bob', $this->input(), 'form', 'ghost'));
        $this->expectServiceError('worker_unknown', fn () => $svc->validate('bob', $this->input(), 'form', 'ghost'));
        $this->assertSame([], $this->sv->calls);
    }

    public function test_unknown_account_or_unusable_home(): void
    {
        $this->expectServiceError('account_unknown', fn () => $this->service()->listWorkers('ghost'));

        $paths = $this->paths;
        foreach (['/', ''] as $home) {
            $svc = $this->service(static fn (string $u) => CpUser::load($paths, $u, static fn () => ['dir' => $home]));
            $this->expectServiceError('account_unknown', fn () => $svc->save('bob', ['folder' => '/etc'] + $this->input(), 'form', null));
        }
        $this->assertFileDoesNotExist($this->paths->accountFile('bob'));
    }

    public function test_get_worker_returns_fields_and_expert_text(): void
    {
        $svc = $this->service();
        $svc->save('bob', $this->input(), 'form', null);

        $r = $svc->getWorker('bob', 'queue');

        $this->assertSame($this->storedWorker()->toArray(), $r['worker']);
        $this->assertStringContainsString("command=php artisan queue:work\n", $r['expert']);
    }

    public function test_logs_and_clear_logs(): void
    {
        $svc = $this->service();
        $svc->save('bob', $this->input(), 'form', null);
        $log = $this->paths->logDir('bob') . '/queue_00.log';
        file_put_contents($log, "one\ntwo\nthree\n");

        $r = $svc->logs('bob', 'queue', 0, 'out', 2);

        $this->assertSame("two\nthree\n", $r['text']);
        $this->assertSame([['process' => '00', 'stream' => 'out', 'exists' => true]], $r['files']);

        $svc->clearLogs('bob', 'queue');
        $this->assertSame('', (string) file_get_contents($log));
    }

    public function test_templates_lists_allowed_templates_with_limits(): void
    {
        $this->settings->setOverride('bob', ['templates' => ['laravel-horizon', 'custom']]);

        $r = $this->service()->templates('bob');

        $this->assertSame([
            ['id' => 'laravel-horizon', 'command' => 'php artisan horizon', 'fixed_processes' => 1],
            ['id' => 'custom', 'command' => '', 'fixed_processes' => null],
        ], $r['templates']);
        $this->assertSame(['laravel-horizon', 'custom'], $r['limits']['templates']);
        $this->assertSame(10, $r['limits']['max_processes']);
    }

    public function test_save_records_the_account_identity(): void
    {
        file_put_contents($this->root . '/var/cpanel/users/bob', "OWNER=root\nPLAN=default\nSTARTDATE=1793487157\n");

        $this->service($this->loaderWithUid(1001))->save('bob', $this->input(), 'form', null);

        $this->assertSame(['uid' => 1001, 'startdate' => '1793487157'], $this->store->load('bob')->identity?->toArray());
        $this->assertStringEndsWith("\n1001\n", (string) file_get_contents($this->paths->launchFile('bob', 'queue')));
    }

    public function test_workers_of_an_earlier_account_of_the_name_are_out_of_reach(): void
    {
        file_put_contents($this->root . '/var/cpanel/users/bob', "OWNER=root\nPLAN=default\nSTARTDATE=1793487157\n");
        $this->service($this->loaderWithUid(1001))->save('bob', $this->input(), 'form', null);
        $this->sv->calls = [];
        // bob was removed with every hook missed; the new bob even got the same uid.
        file_put_contents($this->root . '/var/cpanel/users/bob', "OWNER=root\nPLAN=default\nSTARTDATE=1793488782\n");
        $svc = $this->service($this->loaderWithUid(1001));

        $calls = [
            'list' => fn () => $svc->listWorkers('bob'),
            'get' => fn () => $svc->getWorker('bob', 'queue'),
            'validate' => fn () => $svc->validate('bob', $this->input(), 'form', null),
            'save' => fn () => $svc->save('bob', $this->input(['name' => 'other']), 'form', null),
            'delete' => fn () => $svc->delete('bob', 'queue'),
            'start' => fn () => $svc->start('bob', 'queue'),
            'stop' => fn () => $svc->stop('bob', 'queue'),
            'restart' => fn () => $svc->restart('bob', 'queue'),
            'logs' => fn () => $svc->logs('bob', 'queue', 0, 'out', 10),
            'clear logs' => fn () => $svc->clearLogs('bob', 'queue'),
        ];
        foreach ($calls as $what => $call) {
            $this->assertSame('account_mismatch', $this->expectServiceError('account_mismatch', $call)->errorCode, $what);
        }
        $this->assertSame([], $this->sv->calls);
        $this->assertSame(['queue'], array_keys($this->store->load('bob')->workers));
    }

    public function test_issue_codes_include_input_errors(): void
    {
        $this->assertContains('value_invalid', Issue::CODES);
        $this->assertContains('input_invalid', Issue::CODES);
    }
}
