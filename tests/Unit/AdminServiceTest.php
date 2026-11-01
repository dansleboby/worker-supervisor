<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\Account;
use WorkerSupervisor\AccountProbe;
use WorkerSupervisor\AccountStore;
use WorkerSupervisor\AdminService;
use WorkerSupervisor\CpUser;
use WorkerSupervisor\Identity;
use WorkerSupervisor\IniRenderer;
use WorkerSupervisor\Issue;
use WorkerSupervisor\Logs;
use WorkerSupervisor\Paths;
use WorkerSupervisor\Service;
use WorkerSupervisor\ServiceError;
use WorkerSupervisor\Settings;
use WorkerSupervisor\Supervisor\ProcessStatus;
use WorkerSupervisor\WorkerValidator;
use PHPUnit\Framework\TestCase;

final class AdminServiceTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private string $root;
    private Paths $paths;
    private Settings $settings;
    private AccountStore $store;
    private FakeSupervisor $sv;
    private Service $svc;
    private AdminService $admin;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wsv_admin_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var/cpanel/users', 0777, true);
        foreach (['bob', 'alice'] as $user) {
            mkdir($this->root . '/home/' . $user . '/app', 0777, true);
            touch($this->root . '/home/' . $user . '/app/artisan');
            file_put_contents($this->root . '/var/cpanel/users/' . $user, "OWNER=root\nPLAN=default\n");
        }

        $this->paths = new Paths($this->root);
        $this->settings = Settings::load($this->paths);
        $this->store = new AccountStore($this->paths);
        $this->sv = new FakeSupervisor();

        $paths = $this->paths;
        $root = $this->root;
        $loader = static fn (string $u): ?CpUser => CpUser::load($paths, $u, static fn () => ['uid' => 1001, 'dir' => $root . '/home/' . $u]);
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
        $ini = new IniRenderer($this->paths);
        $now = static fn (): int => self::NOW;

        $this->svc = new Service(
            $this->paths,
            $this->store,
            $this->settings,
            $ini,
            $this->sv,
            new Logs($this->paths),
            new WorkerValidator($probe),
            $loader,
            $now,
        );
        $this->admin = new AdminService($this->svc, $this->store, $this->settings, $this->sv, $loader, $ini, $now);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function save(string $user = 'bob', array $over = []): void
    {
        $this->svc->save($user, $over + [
            'name' => 'queue',
            'template' => 'laravel-queue',
            'folder' => $this->root . '/home/' . $user . '/app',
            'command' => 'php artisan queue:work',
            'processes' => 1,
        ], 'form', null);
    }

    private function ini(string $user = 'bob', string $name = 'queue'): string
    {
        return (string) file_get_contents($this->paths->iniFile($user, $name));
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

    /** @return list<array{string, string}> field and code of each issue */
    private function issues(ServiceError $e): array
    {
        return array_map(static fn (Issue $i) => [$i->field, $i->code], $e->issues);
    }

    public function test_admin_stop_blocks_client_start(): void
    {
        $this->save();
        $this->sv->calls = [];

        $this->admin->adminStop('bob', 'queue');

        $w = $this->store->load('bob')->workers['queue'];
        $this->assertTrue($w->adminStopped);
        $this->assertSame('running', $w->desired, 'the client choice is kept for the release');
        $this->assertStringContainsString("autostart=false\n", $this->ini());
        $this->assertSame([['stop', 'bob_queue']], $this->sv->calls);
        $this->assertFileExists($this->paths->holdFile('bob', 'queue'));

        $this->sv->calls = [];
        $this->expectServiceError('admin_stopped', fn () => $this->svc->start('bob', 'queue'));
        $this->expectServiceError('admin_stopped', fn () => $this->svc->restart('bob', 'queue'));
        $this->expectServiceError('admin_stopped', fn () => $this->admin->start('bob', 'queue'));
        $this->expectServiceError('admin_stopped', fn () => $this->admin->restart('bob', 'queue'));
        $this->assertSame([], $this->sv->calls);
        $this->assertSame('admin_stopped', $this->admin->overview()['rows'][0]['status']);
    }

    public function test_release_restarts_runnable_worker(): void
    {
        $this->save();
        $this->admin->adminStop('bob', 'queue');
        $this->sv->calls = [];

        $this->assertTrue($this->admin->release('bob', 'queue'));

        $this->assertFalse($this->store->load('bob')->workers['queue']->adminStopped);
        $this->assertStringContainsString("autostart=true\n", $this->ini());
        $this->assertSame([['apply', 'bob_queue', true]], $this->sv->calls);
        $this->assertFileDoesNotExist($this->paths->holdFile('bob', 'queue'));
    }

    public function test_release_loads_the_program_before_starting_it(): void
    {
        $this->save('bob', ['autostart' => false]);
        $this->admin->adminStop('bob', 'queue');
        $this->sv->calls = [];

        $this->assertTrue($this->admin->release('bob', 'queue'));

        $this->assertStringContainsString("autostart=false\n", $this->ini());
        $this->assertSame([['apply', 'bob_queue', true]], $this->sv->calls);
    }

    public function test_release_keeps_a_worker_the_client_stopped(): void
    {
        $this->save();
        $this->svc->stop('bob', 'queue');
        $this->admin->adminStop('bob', 'queue');
        $this->sv->calls = [];

        $this->assertFalse($this->admin->release('bob', 'queue'));

        $w = $this->store->load('bob')->workers['queue'];
        $this->assertFalse($w->adminStopped);
        $this->assertSame('stopped', $w->desired);
        $this->assertStringContainsString("autostart=false\n", $this->ini());
        $this->assertSame([], $this->sv->calls);
    }

    public function test_release_does_not_start_a_suspended_account(): void
    {
        $this->save();
        $this->admin->adminStop('bob', 'queue');
        mkdir($this->root . '/var/cpanel/suspended', 0777, true);
        touch($this->paths->suspendedFlag('bob'));
        $this->sv->calls = [];

        $this->assertFalse($this->admin->release('bob', 'queue'));

        $this->assertFalse($this->store->load('bob')->workers['queue']->adminStopped);
        $this->assertStringContainsString("autostart=false\n", $this->ini());
        $this->assertSame([], $this->sv->calls);
        $this->assertFileExists($this->paths->holdFile('bob', 'queue'), 'still held, by the suspension now');
    }

    public function test_release_never_starts_workers_of_an_earlier_account_of_the_name(): void
    {
        $this->save();
        $this->admin->adminStop('bob', 'queue');
        $this->store->transaction('bob', static fn (Account $a) => [new Account('bob', $a->workers, identity: new Identity(1001, '1793487157')), null]);
        $this->sv->calls = [];

        $this->assertFalse($this->admin->release('bob', 'queue'));

        $this->assertSame([], $this->sv->calls);
        $this->assertFileExists($this->paths->holdFile('bob', 'queue'));
        $this->expectServiceError('account_mismatch', fn () => $this->admin->start('bob', 'queue'));
    }

    public function test_admin_start_and_restart_refuse_suspended_and_feature_off(): void
    {
        $this->save();
        $this->svc->stop('bob', 'queue');
        $this->sv->calls = [];

        $this->admin->start('bob', 'queue');
        $this->assertSame([['apply', 'bob_queue', true]], $this->sv->calls);
        $this->admin->restart('bob', 'queue');
        $this->assertSame(['restart', 'bob_queue'], $this->sv->calls[1]);

        $this->sv->calls = [];
        $this->store->transaction('bob', static fn (Account $a) => [$a->withFlags(true, false), null]);
        $this->expectServiceError('suspended', fn () => $this->admin->start('bob', 'queue'));
        $this->store->transaction('bob', static fn (Account $a) => [$a->withFlags(false, true), null]);
        $this->expectServiceError('feature_off', fn () => $this->admin->restart('bob', 'queue'));
        $this->assertSame([], $this->sv->calls);
    }

    public function test_overview_lists_every_account_with_health(): void
    {
        $this->save('bob', ['processes' => 2]);
        $this->save('alice', ['name' => 'mail']);
        $this->save('alice', ['name' => 'idle']);
        $this->svc->stop('alice', 'idle');
        $this->sv->procs = [
            new ProcessStatus('bob_queue', 'bob_queue_00', 'RUNNING', 10, 30, ''),
            new ProcessStatus('bob_queue', 'bob_queue_01', 'RUNNING', 11, 90, ''),
            new ProcessStatus('alice_mail', 'alice_mail_00', 'FATAL', null, null, 'Exited too quickly'),
            new ProcessStatus('alice_idle', 'alice_idle_00', 'STOPPED', null, null, ''),
        ];

        $o = $this->admin->overview();

        $this->assertSame(
            ['running' => true, 'version' => '4.2.5', 'workers' => 3, 'processes' => 4, 'running_processes' => 2, 'failed' => 1],
            $o['health'],
        );
        $this->assertSame([], $o['unreadable']);
        $this->assertSame(['alice', 'alice', 'bob'], array_column($o['rows'], 'user'));
        $this->assertSame(['mail', 'idle', 'queue'], array_column($o['rows'], 'name'));
        $this->assertSame(['failed', 'stopped', 'running'], array_column($o['rows'], 'status'));
        $this->assertSame(self::NOW - 90, $o['rows'][2]['since']);
        $this->assertSame([false, false, false], array_column($o['rows'], 'over_limit'));
    }

    public function test_overview_flags_accounts_over_their_limits(): void
    {
        $this->save('bob', ['processes' => 3]);
        $this->admin->setOverride('bob', ['max_processes' => '2']);

        $this->assertTrue($this->admin->overview()['rows'][0]['over_limit']);
    }

    public function test_overview_when_supervisord_is_down(): void
    {
        $this->save();
        $this->sv->version = null;
        $this->sv->procs = [new ProcessStatus('bob_queue', 'bob_queue_00', 'RUNNING', 10, 30, '')];

        $o = $this->admin->overview();

        $this->assertFalse($o['health']['running']);
        $this->assertNull($o['health']['version']);
        $this->assertSame('stopped', $o['rows'][0]['status']);
    }

    public function test_overview_survives_a_corrupt_account_file(): void
    {
        $this->save();
        file_put_contents($this->paths->accountFile('alice'), '{nope');

        $o = $this->admin->overview();

        $this->assertSame(['alice'], $o['unreadable']);
        $this->assertSame(['bob'], array_column($o['rows'], 'user'));
    }

    public function test_config_returns_rendered_ini_and_expert_text(): void
    {
        $this->save();
        $this->admin->adminStop('bob', 'queue');

        $c = $this->admin->config('bob', 'queue');

        $this->assertSame($this->ini(), $c['ini']);
        $this->assertStringStartsWith("[program:queue]\ncommand=php artisan queue:work\n", $c['expert']);
        $this->expectServiceError('worker_unknown', fn () => $this->admin->config('bob', 'nope'));
    }

    public function test_logs_delegate_to_the_service(): void
    {
        $this->save();
        file_put_contents($this->paths->logDir('bob') . '/queue_00.log', "one\ntwo\n");

        $r = $this->admin->logs('bob', 'queue', 0, 'out', 1);

        $this->assertSame("two\n", $r['text']);
        $this->assertSame([['process' => '00', 'stream' => 'out', 'exists' => true]], $r['files']);
    }

    public function test_override_is_persisted(): void
    {
        $this->admin->setOverride('bob', [
            'max_processes' => '3',
            'max_workers' => '',
            'max_stopwaitsecs' => ' 60 ',
            'templates' => ['custom', 'laravel-queue', 'custom'],
        ]);

        $this->assertSame(
            ['max_processes' => 3, 'max_stopwaitsecs' => 60, 'templates' => ['laravel-queue', 'custom']],
            Settings::load($this->paths)->override('bob'),
        );
        $this->assertSame(3, $this->svc->templates('bob')['limits']['max_processes'], 'the running service sees it');
        $this->assertSame(
            ['max_processes' => 3, 'max_workers' => 5, 'max_stopwaitsecs' => 60, 'templates' => ['laravel-queue', 'custom']],
            $this->admin->limits('bob'),
        );

        $this->admin->setOverride('bob', ['max_processes' => '', 'max_workers' => '', 'max_stopwaitsecs' => '', 'templates' => null]);
        $this->assertNull(Settings::load($this->paths)->override('bob'), 'all blank means no override');

        $this->admin->setOverride('bob', ['templates' => []]);
        $this->assertSame(['templates' => []], Settings::load($this->paths)->override('bob'), 'an empty list overrides');
        $this->assertSame(['bob' => ['templates' => []]], $this->admin->settings()['overrides']);

        $this->admin->setOverride('bob', null);
        $this->assertNull(Settings::load($this->paths)->override('bob'));
    }

    public function test_defaults_are_persisted(): void
    {
        $this->admin->setDefaults([
            'max_processes' => '20',
            'max_workers' => 4,
            'max_stopwaitsecs' => '600',
            'templates' => ['laravel-horizon'],
        ]);

        $expected = ['max_processes' => 20, 'max_workers' => 4, 'max_stopwaitsecs' => 600, 'templates' => ['laravel-horizon']];
        $this->assertSame($expected, Settings::load($this->paths)->defaults()->toArray());
        $this->assertSame(['defaults' => $expected, 'overrides' => []], $this->admin->settings());
    }

    public function test_settings_input_is_validated_and_nothing_is_saved(): void
    {
        $e = $this->expectServiceError('validation_failed', fn () => $this->admin->setDefaults([
            'max_processes' => '0',
            'max_workers' => 'five',
            'max_stopwaitsecs' => '-1',
            'templates' => ['laravel-queue', 'nope'],
        ]));
        $this->assertSame([
            ['max_processes', 'value_invalid'],
            ['max_workers', 'value_invalid'],
            ['max_stopwaitsecs', 'value_invalid'],
            ['templates', 'template_unknown'],
        ], $this->issues($e));

        $e = $this->expectServiceError('validation_failed', fn () => $this->admin->setDefaults(['max_processes' => '']));
        $this->assertSame([
            ['max_processes', 'value_invalid'],
            ['max_workers', 'value_invalid'],
            ['max_stopwaitsecs', 'value_invalid'],
            ['templates', 'value_invalid'],
        ], $this->issues($e), 'every default is required');

        $e = $this->expectServiceError('validation_failed', fn () => $this->admin->setOverride('bob', [
            'max_processes' => '12a',
            'max_workers' => '1.5',
            'max_stopwaitsecs' => '1e3',
            'templates' => 'custom',
        ]));
        $this->assertSame([
            ['max_processes', 'value_invalid'],
            ['max_workers', 'value_invalid'],
            ['max_stopwaitsecs', 'value_invalid'],
            ['templates', 'value_invalid'],
        ], $this->issues($e), 'typed text that is not a whole number is an error, never "not overridden"');

        $this->assertFileDoesNotExist($this->paths->settingsFile());
    }

    public function test_override_needs_an_existing_account(): void
    {
        $this->expectServiceError('account_unknown', fn () => $this->admin->setOverride('ghost', ['max_processes' => '2']));
        $this->expectServiceError('account_unknown', fn () => $this->admin->setOverride('../x', null));
        $this->assertFileDoesNotExist($this->paths->settingsFile());
    }

    public function test_actions_reject_an_invalid_account_name(): void
    {
        foreach (['adminStop', 'release', 'start', 'restart', 'config'] as $method) {
            $this->expectServiceError('account_unknown', fn () => $this->admin->$method('../etc', 'queue'));
        }
        $this->expectServiceError('account_unknown', fn () => $this->admin->limits('../etc'));
        $this->expectServiceError('account_unknown', fn () => $this->admin->limits('ghost'));
        $this->expectServiceError('worker_unknown', fn () => $this->admin->adminStop('bob', 'nope'));
        $this->assertSame([], $this->sv->calls);
    }
}
