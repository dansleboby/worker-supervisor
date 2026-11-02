<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\Account;
use WorkerSupervisor\AccountStore;
use WorkerSupervisor\CpUser;
use WorkerSupervisor\Identity;
use WorkerSupervisor\IniRenderer;
use WorkerSupervisor\Lifecycle;
use WorkerSupervisor\LogAccess;
use WorkerSupervisor\Logs;
use WorkerSupervisor\Paths;
use WorkerSupervisor\Settings;
use WorkerSupervisor\Supervisor\ProcessStatus;
use WorkerSupervisor\Worker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LifecycleTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/hooks/';

    private string $root;
    private Paths $paths;
    private AccountStore $store;
    private IniRenderer $ini;
    private FakeSupervisor $sv;
    private FakeFeatures $features;
    /** @var list<array{0: int, 1: string}> */
    private array $logged = [];
    /** @var array<string, int> uid of each system user; one is given at the first lookup */
    private array $uids = [];
    private int $nextUid = 1001;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wsv_life_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var/cpanel/users', 0777, true);
        mkdir($this->root . '/var/cpanel/suspended', 0777, true);
        $this->paths = new Paths($this->root);
        $this->store = new AccountStore($this->paths);
        $this->ini = new IniRenderer($this->paths);
        $this->sv = new FakeSupervisor();
        $this->features = new FakeFeatures();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @var list<list<string>> commands the LogAccess runner was given */
    private array $runs = [];

    private function logAccess(): LogAccess
    {
        $runner = new class ($this->runs) implements \WorkerSupervisor\Supervisor\Runner {
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

        return new LogAccess(
            $this->paths,
            $runner,
            fn (string $u): array|false => is_dir($this->home($u)) ? ['name' => $u, 'gid' => posix_getgid(), 'dir' => $this->home($u)] : false,
            $this->root . '/cagefs.mp',
        );
    }

    private function lifecycle(?LogAccess $logAccess = null): Lifecycle
    {
        return new Lifecycle(
            $this->store,
            Settings::load($this->paths),
            $this->ini,
            $this->sv,
            new Logs($this->paths),
            $this->cpusers(),
            $this->features,
            $this->passwd(...),
            function (int $priority, string $message): void {
                $this->logged[] = [$priority, $message];
            },
            $logAccess,
        );
    }

    /** @return callable(string): ?CpUser */
    private function cpusers(): callable
    {
        return fn (string $u): ?CpUser => CpUser::load($this->paths, $u, $this->passwd(...));
    }

    /** The system user lives as long as its home directory here. */
    private function passwd(string $user): array|false
    {
        if (!is_dir($this->home($user))) {
            return false;
        }
        $this->uids[$user] ??= $this->nextUid++;

        return ['name' => $user, 'uid' => $this->uids[$user], 'dir' => $this->home($user)];
    }

    /** What cPanel leaves once it has removed an account: no cPanel user file, no system user. */
    private function vanish(string $user): void
    {
        unlink($this->paths->cpuserFile($user));
        exec('rm -rf ' . escapeshellarg($this->home($user)));
        unset($this->uids[$user]);
    }

    /** What cPanel has done by the time the post-modify hook runs: uid and STARTDATE are kept (checked on 11.138). */
    private function renameInCpanel(string $old, string $new): void
    {
        rename($this->paths->cpuserFile($old), $this->paths->cpuserFile($new));
        rename($this->home($old), $this->home($new));
        if (isset($this->uids[$old])) {
            $this->uids[$new] = $this->uids[$old];
            unset($this->uids[$old]);
        }
    }

    /** A new cPanel account: its own uid and STARTDATE, even when an earlier account had the name. */
    private function createInCpanel(string $user): void
    {
        $this->uids[$user] = $this->nextUid++;
        file_put_contents($this->paths->cpuserFile($user), "OWNER=root\nPLAN=default\nSTARTDATE=" . (1793000000 + $this->uids[$user]) . "\n");
        @mkdir($this->home($user) . '/app', 0777, true);
    }

    private function identity(string $user): Identity
    {
        return ($this->cpusers())($user)->identity();
    }

    private function ageMarker(string $user, string $kind): void
    {
        touch($this->paths->accountFile($user) . '.' . $kind, time() - 7200);
    }

    private function logText(): string
    {
        return implode("\n", array_column($this->logged, 1));
    }

    private function home(string $user): string
    {
        return $this->root . '/home/' . $user;
    }

    /**
     * A cPanel account with stored workers, their generated files, one log file each and their
     * processes in supervisord, as Service leaves them.
     */
    private function seed(string $user, array $workers = ['queue' => []], bool $featureOff = false): void
    {
        $this->createInCpanel($user);

        $a = (new Account($user, identity: $this->identity($user)))->withFlags(false, $featureOff);
        foreach ($workers as $name => $extra) {
            $a = $a->withWorker(Worker::fromArray($extra + [
                'name' => $name, 'template' => 'custom', 'folder' => $this->home($user) . '/app', 'command' => 'php run.php',
            ]));
        }
        $this->store->save($a);
        foreach ($a->workers as $w) {
            $this->ini->write($a, $w, $a->runnable($w), $a->held($w));
            file_put_contents($this->paths->logDir($user) . '/' . $w->name . '_00.log', "line\n");
            $this->supervisordShows($this->ini->programName($user, $w->name), $a->runnable($w) ? 'RUNNING' : 'STOPPED', $w->processes);
        }
    }

    /** What the fake supervisord reports for the program from now on; null: not loaded. */
    private function supervisordShows(string $program, ?string $state, int $processes = 1): void
    {
        $this->sv->procs = array_values(array_filter($this->sv->procs, static fn (ProcessStatus $p) => $p->program !== $program));
        for ($i = 0; $state !== null && $i < $processes; $i++) {
            $this->sv->procs[] = new ProcessStatus($program, sprintf('%s_%02d', $program, $i), $state, null, null, '');
        }
    }

    private function setOverride(string $user): void
    {
        $s = Settings::load($this->paths);
        $s->setOverride($user, ['max_processes' => 3]);
        $s->save();
    }

    private function account(string $user): Account
    {
        return $this->store->load($user);
    }

    private function iniText(string $user, string $name): string
    {
        return (string) file_get_contents($this->paths->iniFile($user, $name));
    }

    private function suspendInCpanel(string $user): void
    {
        touch($this->paths->suspendedFlag($user));
    }

    public function test_remove_leaves_nothing(): void
    {
        $this->seed('bob', ['queue' => [], 'mail' => ['processes' => 2]]);
        $this->seed('alice');
        $this->setOverride('bob');
        $this->setOverride('alice');

        $this->lifecycle()->remove('bob');

        $this->assertFileDoesNotExist($this->paths->iniFile('bob', 'queue'));
        $this->assertFileDoesNotExist($this->paths->iniFile('bob', 'mail'));
        $this->assertDirectoryDoesNotExist(dirname($this->paths->launchFile('bob', 'queue')));
        $this->assertDirectoryDoesNotExist($this->paths->logDir('bob'));
        $this->assertFileDoesNotExist($this->paths->accountFile('bob'));
        $this->assertNull(Settings::load($this->paths)->override('bob'));
        $this->assertEqualsCanonicalizing([['remove', 'bob_queue'], ['remove', 'bob_mail']], $this->sv->calls);

        $this->assertFileExists($this->paths->iniFile('alice', 'queue'));
        $this->assertFileExists($this->paths->logDir('alice') . '/queue_00.log');
        $this->assertNotNull(Settings::load($this->paths)->override('alice'));
    }

    public function test_reconcile_gives_each_account_its_logs_and_reports_the_cagefs_mount(): void
    {
        $this->seed('bob');
        file_put_contents($this->root . '/cagefs.mp', "/opt\n");

        $report = $this->lifecycle($this->logAccess())->reconcile();

        $this->assertSame(['bob'], $report['linked']);
        $this->assertSame('missing', $report['logs_mount']);
        $this->assertStringContainsString(LogAccess::CAGEFS_LINE, $this->logText());
        $this->assertSame(02750, fileperms($this->paths->logDir('bob')) & 07777);
        $this->assertSame(['/usr/sbin/runuser', '-u', 'bob'], array_slice($this->runs[0], 0, 3));
    }

    public function test_reconcile_never_links_the_logs_of_an_earlier_account_of_the_name(): void
    {
        $this->seed('bob');
        $this->store->save($this->account('bob')->withIdentity(new Identity(999, '1')));

        $report = $this->lifecycle($this->logAccess())->reconcile();

        $this->assertSame([], $report['linked']);
        $this->assertSame([], $this->runs, 'no home link made');
    }

    public function test_rename_points_the_home_link_at_the_new_log_directory(): void
    {
        $this->seed('bob');
        $this->renameInCpanel('bob', 'robert');

        $this->assertTrue($this->lifecycle($this->logAccess())->rename('bob', 'robert'));

        $this->assertSame(['/usr/sbin/runuser', '-u', 'robert'], array_slice(end($this->runs), 0, 3));
        $this->assertSame($this->paths->logDir('robert'), end($this->runs)[9]);
    }

    public function test_remove_drops_a_restore_still_waiting_even_without_an_account_file(): void
    {
        $this->createInCpanel('bob');
        $this->store->withLock('bob', fn () => $this->store->mark('bob', 'restoring', '{}'));

        $this->lifecycle()->remove('bob');

        $this->assertNull($this->store->marker('bob', 'restoring'));
    }

    public function test_rename_moves_a_restore_still_waiting_without_an_account_file(): void
    {
        $this->createInCpanel('bob');
        $this->store->withLock('bob', fn () => $this->store->mark('bob', 'restoring', '{"x":1}'));
        $this->renameInCpanel('bob', 'robert');

        $this->assertTrue($this->lifecycle()->rename('bob', 'robert'));

        $this->assertNull($this->store->marker('bob', 'restoring'));
        $this->assertSame('{"x":1}', $this->store->marker('robert', 'restoring')['value']);
    }

    public function test_remove_cleans_files_of_a_corrupt_account(): void
    {
        $this->seed('bob');
        file_put_contents($this->paths->accountFile('bob'), '{not json');

        $this->lifecycle()->remove('bob');

        $this->assertFileDoesNotExist($this->paths->iniFile('bob', 'queue'));
        $this->assertFileDoesNotExist($this->paths->accountFile('bob'));
        $this->assertSame([['remove', 'bob_queue']], $this->sv->calls);
    }

    public function test_removal_and_rename_keep_a_corrupt_settings_file(): void
    {
        $this->seed('bob');
        $this->seed('carol');
        $corrupt = '{"overrides": {"bob": {"max_workers": 2}';
        file_put_contents($this->paths->settingsFile(), $corrupt);

        $this->vanish('carol');
        $this->lifecycle()->remove('carol');
        $this->renameInCpanel('bob', 'robert');
        $this->assertTrue($this->lifecycle()->rename('bob', 'robert'));

        $this->assertSame($corrupt, file_get_contents($this->paths->settingsFile()));
        $this->assertFileDoesNotExist($this->paths->accountFile('carol'));
    }

    public function test_rename_moves_everything(): void
    {
        $this->seed('bob');
        $this->setOverride('bob');
        $this->renameInCpanel('bob', 'robert');

        $this->assertTrue($this->lifecycle()->rename('bob', 'robert'));

        $this->assertFileDoesNotExist($this->paths->accountFile('bob'));
        $this->assertFileDoesNotExist($this->paths->iniFile('bob', 'queue'));
        $this->assertDirectoryDoesNotExist(dirname($this->paths->launchFile('bob', 'queue')));
        $this->assertDirectoryDoesNotExist($this->paths->logDir('bob'));

        $w = $this->account('robert')->workers['queue'];
        $this->assertSame($this->home('robert') . '/app', $w->folder);
        $this->assertStringContainsString('[program:robert_queue]', $this->iniText('robert', 'queue'));
        $this->assertStringStartsWith($this->home('robert') . "/app\n", (string) file_get_contents($this->paths->launchFile('robert', 'queue')));
        $this->assertSame("line\n", file_get_contents($this->paths->logDir('robert') . '/queue_00.log'));
        $this->assertNull(Settings::load($this->paths)->override('bob'));
        $this->assertSame(['max_processes' => 3], Settings::load($this->paths)->override('robert'));
        $this->assertSame([['remove', 'bob_queue'], ['apply', 'robert_queue', true]], $this->sv->calls);
        $this->assertStringContainsString("Renamed account 'bob' to 'robert'", $this->logText());
    }

    public function test_rename_refuses_existing_target_and_never_starts_it(): void
    {
        $this->seed('bob');
        // Left by an earlier owner of the name: it must not run as the renamed client.
        $this->seed('robert', ['other' => []]);
        $this->vanish('robert');
        $this->lifecycle()->prepareRename('bob', 'robert');
        $this->renameInCpanel('bob', 'robert');

        try {
            $this->lifecycle()->rename('bob', 'robert');
            $this->fail('Expected the rename to be refused.');
        } catch (RuntimeException) {
        }

        $this->assertArrayHasKey('queue', $this->account('bob')->workers);
        $this->assertFileExists($this->paths->iniFile('bob', 'queue'));
        $this->assertFileExists($this->paths->logDir('bob') . '/queue_00.log');
        $other = $this->account('robert')->workers['other'];
        $this->assertTrue($other->adminStopped);
        $this->assertStringContainsString('autostart=false', $this->iniText('robert', 'other'));
        $this->assertSame([['apply', 'robert_other', false]], $this->sv->calls);

        $this->supervisordShows('robert_other', 'STOPPED');
        $this->sv->calls = [];
        $report = $this->lifecycle()->reconcile();
        $this->assertSame([], $this->sv->calls, 'the earlier owner\'s workers stay stopped');
        $this->assertSame([], $report['removed']);
        $this->assertNotSame([], $report['errors']);
        $this->assertArrayHasKey('queue', $this->account('bob')->workers);
    }

    public function test_rename_needs_the_new_user_and_the_old_one_gone(): void
    {
        $this->seed('bob');

        $this->assertFalse($this->lifecycle()->rename('bob', 'robert'), 'robert does not exist');
        file_put_contents($this->paths->cpuserFile('robert'), "OWNER=root\n");
        $this->assertFalse($this->lifecycle()->rename('bob', 'robert'), 'bob still exists');

        $this->assertArrayHasKey('queue', $this->account('bob')->workers);
        $this->assertFileDoesNotExist($this->paths->accountFile('robert'));
        $this->assertSame([], $this->sv->calls);
        $this->assertCount(2, $this->logged);
    }

    public function test_reconcile_finishes_a_pending_rename(): void
    {
        $this->seed('bob');
        $lc = $this->lifecycle();
        $lc->prepareRename('bob', 'robert');
        $this->renameInCpanel('bob', 'robert');

        $report = $lc->reconcile();

        $this->assertSame(['bob -> robert'], $report['renamed']);
        $this->assertSame([], $report['removed']);
        $this->assertSame($this->home('robert') . '/app', $this->account('robert')->workers['queue']->folder);
        $this->assertFileDoesNotExist($this->paths->accountFile('bob'));
    }

    public function test_reconcile_never_removes_an_account_with_a_rename_marker(): void
    {
        $this->seed('bob');
        $lc = $this->lifecycle();
        $lc->prepareRename('bob', 'robert');
        $this->vanish('bob');

        $report = $lc->reconcile();
        $this->assertSame([], $report['removed']);
        $this->assertSame([], $report['errors'], 'a rename may still be running');

        $this->ageMarker('bob', 'renaming');
        $report = $lc->reconcile();
        $this->assertSame([], $report['removed']);
        $this->assertStringContainsString("'robert'", implode("\n", $report['errors']));
        $this->assertFileExists($this->paths->iniFile('bob', 'queue'));
        $this->assertArrayHasKey('queue', $this->account('bob')->workers);
        $this->assertSame([], $this->sv->calls);
    }

    public function test_remove_pre_stops_workers_and_keeps_everything(): void
    {
        $this->seed('bob', ['queue' => [], 'manual' => ['autostart' => false], 'paused' => ['desired' => 'stopped']]);
        $this->setOverride('bob');

        $this->lifecycle()->prepareRemove('bob');

        $this->assertStringContainsString('autostart=false', $this->iniText('bob', 'queue'));
        $this->assertSame([['apply', 'bob_queue', false], ['stop', 'bob_manual']], $this->sv->calls);
        $this->assertSame(['queue', 'manual', 'paused'], array_keys($this->account('bob')->workers));
        $this->assertFileExists($this->paths->launchFile('bob', 'queue'));
        $this->assertFileExists($this->paths->logDir('bob') . '/queue_00.log');
        $this->assertNotNull(Settings::load($this->paths)->override('bob'));
    }

    public function test_remove_post_removes_only_a_gone_account(): void
    {
        $this->seed('bob');
        $lc = $this->lifecycle();
        $lc->prepareRemove('bob');
        $this->sv->calls = [];

        $lc->finishRemove('bob');
        $this->assertFileExists($this->paths->accountFile('bob'));
        $this->assertSame([], $this->sv->calls);

        $this->vanish('bob');
        $lc->finishRemove('bob');
        $this->assertFileDoesNotExist($this->paths->accountFile('bob'));
        $this->assertFileDoesNotExist($this->paths->accountFile('bob') . '.removing');
        $this->assertSame([['remove', 'bob_queue']], $this->sv->calls);
        $this->assertStringContainsString("Removed the worker data of account 'bob'", $this->logText());
    }

    public function test_reconcile_restores_workers_after_an_aborted_removal(): void
    {
        $this->seed('bob', ['queue' => [], 'manual' => ['autostart' => false], 'paused' => ['desired' => 'stopped']]);
        $lc = $this->lifecycle();
        $lc->prepareRemove('bob');
        $this->supervisordShows('bob_queue', 'STOPPED');
        $this->supervisordShows('bob_manual', 'STOPPED');
        $this->sv->calls = [];

        $report = $lc->reconcile();
        $this->assertSame([], $report['restored'], 'the removal may still be running');
        $this->assertSame([], $this->sv->calls);

        $this->ageMarker('bob', 'removing');
        $report = $lc->reconcile();
        $this->assertSame(['bob'], $report['restored']);
        $this->assertStringContainsString('autostart=true', $this->iniText('bob', 'queue'));
        $this->assertSame([['apply', 'bob_queue', true], ['apply', 'bob_manual', true]], $this->sv->calls);
        $this->assertFileDoesNotExist($this->paths->accountFile('bob') . '.removing');

        $this->sv->calls = [];
        $lc->reconcile();
        $this->assertSame([], $this->sv->calls);
    }

    public function test_reconcile_keeps_an_account_whose_system_user_remains(): void
    {
        $this->seed('bob');
        $lc = $this->lifecycle();
        $lc->prepareRemove('bob');
        $this->ageMarker('bob', 'removing');
        $this->sv->calls = [];
        unlink($this->paths->cpuserFile('bob'));

        $report = $lc->reconcile();

        $this->assertSame([], $report['removed']);
        $this->assertSame([], $report['restored']);
        $this->assertFileExists($this->paths->accountFile('bob'));
        $this->assertSame([], $this->sv->calls);
        $this->assertSame([], $this->logged);
    }

    public function test_suspend_stops_workers_and_unsuspend_restores_previous_state(): void
    {
        $this->seed('bob', ['queue' => [], 'paused' => ['desired' => 'stopped']]);

        $this->suspendInCpanel('bob');
        $this->lifecycle()->suspend('bob');
        $this->assertTrue($this->account('bob')->suspended);
        $this->assertStringContainsString('autostart=false', $this->iniText('bob', 'queue'));
        $this->assertSame([['apply', 'bob_queue', false]], $this->sv->calls);

        $this->sv->calls = [];
        unlink($this->paths->suspendedFlag('bob'));
        $this->lifecycle()->unsuspend('bob');
        $this->assertFalse($this->account('bob')->suspended);
        $this->assertStringContainsString('autostart=true', $this->iniText('bob', 'queue'));
        $this->assertSame([['apply', 'bob_queue', true]], $this->sv->calls);
    }

    public function test_suspend_without_autostart_still_stops_the_worker(): void
    {
        $this->seed('bob', ['queue' => ['autostart' => false]]);

        $this->lifecycle()->suspend('bob');

        $this->assertSame([['stop', 'bob_queue']], $this->sv->calls);
    }

    public function test_events_for_accounts_without_workers_write_nothing(): void
    {
        file_put_contents($this->paths->cpuserFile('carol'), "OWNER=root\nPLAN=default\n");
        $lc = $this->lifecycle();

        $lc->suspend('carol');
        $lc->unsuspend('carol');
        $lc->packageChanged('carol');
        $lc->prepareRename('carol', 'carl');
        $lc->prepareRemove('carol');
        $lc->remove('carol');

        $this->assertFileDoesNotExist($this->paths->accountFile('carol'));
        $this->assertFileDoesNotExist($this->paths->accountFile('carol') . '.lock');
        $this->assertSame(0, $this->features->calls);
        $this->assertSame([], $this->sv->calls);
    }

    public function test_package_change_applies_the_feature_state(): void
    {
        $this->seed('bob');
        $this->features->enabled = ['bob' => false];

        $this->lifecycle()->packageChanged('bob');

        $this->assertTrue($this->account('bob')->featureOff);
        $this->assertSame([['apply', 'bob_queue', false]], $this->sv->calls);
    }

    public function test_reconcile_removes_workers_of_vanished_account(): void
    {
        $this->seed('bob');
        $this->seed('alice');
        $this->vanish('bob');

        $report = $this->lifecycle()->reconcile();

        $this->assertSame(['bob'], $report['removed']);
        $this->assertFileDoesNotExist($this->paths->accountFile('bob'));
        $this->assertFileDoesNotExist($this->paths->iniFile('bob', 'queue'));
        $this->assertDirectoryDoesNotExist($this->paths->logDir('bob'));
        $this->assertSame([['remove', 'bob_queue']], $this->sv->calls);
        $this->assertFileExists($this->paths->iniFile('alice', 'queue'));
        $this->assertSame([[LOG_NOTICE, "Removed the worker data of account 'bob' (workers: queue)"]], $this->logged);
    }

    public function test_reconcile_keeps_an_account_being_renamed(): void
    {
        $this->seed('bob');
        $lc = $this->lifecycle();
        $lc->prepareRename('bob', 'robert');
        $this->vanish('bob');

        $report = $lc->reconcile();

        $this->assertSame([], $report['removed']);
        $this->assertFileExists($this->paths->iniFile('bob', 'queue'));
        $this->assertSame([], $this->sv->calls);
    }

    public function test_reconcile_deletes_orphan_wsv_ini(): void
    {
        $this->seed('bob');
        $ghost = new Worker('ghost', 'custom', $this->home('bob') . '/app', 'php run.php');
        $this->ini->write(new Account('bob'), $ghost, true, false);
        $this->ini->write(new Account('zed'), $ghost->with(['name' => 'old']), true, false);
        file_put_contents($this->paths->iniDir() . '/laravel-worker.ini', "[program:laravel]\n");

        $report = $this->lifecycle()->reconcile();

        $this->assertEqualsCanonicalizing(['bob_ghost', 'zed_old'], $report['orphans']);
        $this->assertFileDoesNotExist($this->paths->iniFile('bob', 'ghost'));
        $this->assertFileDoesNotExist($this->paths->launchFile('bob', 'ghost'));
        $this->assertFileDoesNotExist($this->paths->iniFile('zed', 'old'));
        $this->assertEqualsCanonicalizing([['remove', 'bob_ghost'], ['remove', 'zed_old']], $this->sv->calls);
        $this->assertStringContainsString("Removed orphan program file of 'bob_ghost'", $this->logText());
        $this->assertFileExists($this->paths->iniFile('bob', 'queue'));
        $this->assertFileExists($this->paths->iniDir() . '/laravel-worker.ini');
    }

    public function test_reconcile_keeps_ini_files_of_an_unreadable_account(): void
    {
        $this->seed('bob');
        file_put_contents($this->paths->accountFile('bob'), '{not json');

        $report = $this->lifecycle()->reconcile();

        $this->assertFileExists($this->paths->iniFile('bob', 'queue'));
        $this->assertSame([], $report['orphans']);
        $this->assertNotSame([], $report['errors']);
        $this->assertSame([], $this->sv->calls);
        $this->assertSame(LOG_ERR, $this->logged[0][0]);
    }

    public function test_reconcile_feature_off_stops_and_feature_on_restores(): void
    {
        $this->seed('bob', ['queue' => [], 'paused' => ['desired' => 'stopped']]);
        $lc = $this->lifecycle();

        $this->features->enabled = ['bob' => false];
        $report = $lc->reconcile();
        $this->assertSame(['bob'], $report['feature_off']);
        $this->assertTrue($this->account('bob')->featureOff);
        $this->assertStringContainsString('autostart=false', $this->iniText('bob', 'queue'));
        $this->assertSame([['apply', 'bob_queue', false]], $this->sv->calls);

        $this->sv->calls = [];
        $this->features->enabled = ['bob' => true];
        $report = $lc->reconcile();
        $this->assertSame(['bob'], $report['feature_on']);
        $this->assertFalse($this->account('bob')->featureOff);
        $this->assertSame([['apply', 'bob_queue', true]], $this->sv->calls);

        $this->sv->calls = [];
        $report = $lc->reconcile();
        $this->assertSame([], $report['feature_on']);
        $this->assertSame([], $report['rewritten']);
        $this->assertSame([], $this->sv->calls);
    }

    public function test_reconcile_does_nothing_when_feature_not_registered(): void
    {
        $this->seed('bob', featureOff: true);
        $this->seed('alice');
        $this->features->registered = false;
        $this->features->enabled = ['bob' => true, 'alice' => false];

        $report = $this->lifecycle()->reconcile();

        $this->assertSame('unregistered', $report['features']);
        $this->assertTrue($this->account('bob')->featureOff);
        $this->assertFalse($this->account('alice')->featureOff);
        $this->assertSame([], $this->sv->calls);
    }

    public function test_reconcile_keeps_feature_flags_when_the_check_fails(): void
    {
        $this->seed('bob', featureOff: true);
        $this->features->fail = true;

        $report = $this->lifecycle()->reconcile();

        $this->assertSame('failed', $report['features']);
        $this->assertTrue($this->account('bob')->featureOff);
        $this->assertSame([], $this->sv->calls);
    }

    public function test_reconcile_applies_cpanel_suspension(): void
    {
        $this->seed('bob');
        $this->suspendInCpanel('bob');

        $report = $this->lifecycle()->reconcile();

        $this->assertSame(['bob'], $report['suspended']);
        $this->assertTrue($this->account('bob')->suspended);
        $this->assertSame([['apply', 'bob_queue', false]], $this->sv->calls);
    }

    public function test_reconcile_rewrites_drifted_ini(): void
    {
        $this->seed('bob');
        file_put_contents($this->paths->iniFile('bob', 'queue'), "[program:bob_queue]\ncommand=/bin/evil\n");

        $report = $this->lifecycle()->reconcile();

        $this->assertSame(['bob_queue'], $report['rewritten']);
        $this->assertStringNotContainsString('evil', $this->iniText('bob', 'queue'));
        $this->assertSame([['apply', 'bob_queue', true]], $this->sv->calls);
    }

    public function test_reconcile_stops_workers_that_run_although_they_must_not(): void
    {
        // Detached jobs are serialized but not ordered: a start queued earlier can run after a stop.
        $this->seed('bob', ['queue' => [], 'paused' => ['desired' => 'stopped'], 'held' => ['admin_stopped' => true]]);
        $this->seed('carol');
        $this->suspendInCpanel('carol');
        $this->lifecycle()->suspend('carol');
        $this->supervisordShows('bob_paused', 'RUNNING');
        $this->supervisordShows('bob_held', 'BACKOFF');
        $this->supervisordShows('carol_queue', 'STARTING');
        $this->sv->calls = [];

        $report = $this->lifecycle()->reconcile();

        $this->assertSame(['bob_paused', 'bob_held', 'carol_queue'], $report['stopped']);
        $this->assertSame([['stop', 'bob_paused'], ['stop', 'bob_held'], ['stop', 'carol_queue']], $this->sv->calls);
        $this->assertStringContainsString("Stopped 'carol_queue'", $this->logText());

        $this->sv->calls = [];
        $this->supervisordShows('bob_paused', 'STOPPED');
        $this->lifecycle()->reconcile();
        $this->assertNotContains(['stop', 'bob_paused'], $this->sv->calls);
    }

    public function test_reconcile_loads_programs_supervisord_does_not_have(): void
    {
        $this->seed('bob', ['queue' => [], 'paused' => ['desired' => 'stopped']]);
        $this->supervisordShows('bob_queue', null);
        $this->supervisordShows('bob_paused', null);

        $report = $this->lifecycle()->reconcile();

        $this->assertSame(['bob_queue', 'bob_paused'], $report['loaded']);
        $this->assertSame([['apply', 'bob_queue', true], ['apply', 'bob_paused', false]], $this->sv->calls);
    }

    public function test_reconcile_leaves_supervisord_alone_when_it_is_down(): void
    {
        $this->seed('bob', ['paused' => ['desired' => 'stopped']]);
        $this->supervisordShows('bob_paused', 'RUNNING');
        $this->sv->version = null;

        $report = $this->lifecycle()->reconcile();

        $this->assertSame('down', $report['supervisord']);
        $this->assertSame([], $this->sv->calls);
    }

    public function test_root_holds_are_marked_for_the_launcher_and_client_stops_are_not(): void
    {
        $this->seed('bob', ['queue' => [], 'paused' => ['desired' => 'stopped']]);
        $hold = fn (string $name): string => $this->paths->holdFile('bob', $name);
        $this->assertFileDoesNotExist($hold('paused'));

        $this->suspendInCpanel('bob');
        $this->lifecycle()->suspend('bob');
        $this->assertFileExists($hold('queue'));
        $this->assertFileExists($hold('paused'));

        unlink($this->paths->suspendedFlag('bob'));
        $this->lifecycle()->unsuspend('bob');
        $this->assertFileDoesNotExist($hold('queue'));
        $this->assertFileDoesNotExist($hold('paused'));

        $this->lifecycle()->prepareRemove('bob');
        $this->assertFileExists($hold('queue'));
    }

    public function test_rename_keeps_the_identity(): void
    {
        $this->seed('bob');
        $id = $this->identity('bob');
        $this->lifecycle()->prepareRename('bob', 'robert');
        $this->renameInCpanel('bob', 'robert');

        $this->assertTrue($this->lifecycle()->rename('bob', 'robert'));

        $this->assertTrue($id->equals($this->account('robert')->identity));
        $launch = explode("\n", (string) file_get_contents($this->paths->launchFile('robert', 'queue')));
        $this->assertSame((string) $id->uid, $launch[2]);
    }

    public function test_a_stale_rename_never_moves_workers_into_another_account(): void
    {
        // bob's rename to robert was aborted, then bob was removed and the Remove post hook missed;
        // robert is an unrelated account created since.
        $this->seed('bob');
        $this->lifecycle()->prepareRename('bob', 'robert');
        $this->lifecycle()->prepareRemove('bob');
        $this->vanish('bob');
        $this->createInCpanel('robert');
        $this->sv->calls = [];

        $report = $this->lifecycle()->reconcile();

        $this->assertSame([], $report['renamed']);
        $this->assertStringContainsString("'robert' is another cPanel account", implode("\n", $report['errors']));
        $this->assertFileDoesNotExist($this->paths->accountFile('robert'));
        $this->assertArrayHasKey('queue', $this->account('bob')->workers, 'kept for root');
        $this->assertSame([], $this->sv->calls);

        $this->expectException(RuntimeException::class);
        $this->lifecycle()->rename('bob', 'robert');
    }

    public function test_rename_checks_the_stored_identity_without_a_marker(): void
    {
        $this->seed('bob');
        $this->vanish('bob');
        $this->createInCpanel('robert');

        try {
            $this->lifecycle()->rename('bob', 'robert');
            $this->fail('Expected the rename to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('another cPanel account', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->paths->accountFile('robert'));
    }

    public function test_workers_of_an_earlier_account_of_the_name_never_start(): void
    {
        // bob was removed with every hook missed, and a new account took the name before reconcile ran.
        $this->seed('bob', ['queue' => [], 'manual' => ['autostart' => false]]);
        $this->lifecycle()->suspend('bob');
        $this->supervisordShows('bob_queue', 'STOPPED');
        $this->supervisordShows('bob_manual', 'STOPPED');
        $this->vanish('bob');
        $this->createInCpanel('bob');
        $this->sv->calls = [];

        $lc = $this->lifecycle();
        $lc->unsuspend('bob');
        $this->assertSame([], $this->sv->calls, 'the unsuspend hook starts nothing');
        $this->assertTrue($this->account('bob')->suspended);
        $this->assertStringContainsString('earlier account', $this->logText());

        $this->logged = [];
        $report = $lc->reconcile();
        $this->assertSame([], $this->sv->calls);
        $this->assertStringContainsString('earlier account', implode("\n", $report['errors']));
        $this->assertSame(LOG_ERR, $this->logged[0][0]);
        $this->assertFileExists($this->paths->accountFile('bob'), 'kept for root');
        $this->assertFileExists($this->paths->holdFile('bob', 'queue'));
        $this->assertFileExists($this->paths->holdFile('bob', 'manual'));
    }

    public function test_reconcile_holds_and_stops_workers_of_an_earlier_account_of_the_name(): void
    {
        $this->seed('bob', ['queue' => [], 'manual' => ['autostart' => false]]);
        $this->vanish('bob');
        $this->createInCpanel('bob');

        $report = $this->lifecycle()->reconcile();

        $this->assertSame([['apply', 'bob_queue', false], ['stop', 'bob_manual']], $this->sv->calls);
        $this->assertSame(['bob_manual'], $report['stopped']);
        $this->assertStringContainsString('autostart=false', $this->iniText('bob', 'queue'));
        $this->assertNotSame([], $report['errors']);
        $this->assertArrayHasKey('queue', $this->account('bob')->workers);
    }

    public function test_an_aborted_removal_never_restores_workers_for_another_account(): void
    {
        $this->seed('bob');
        $this->lifecycle()->prepareRemove('bob');
        $this->supervisordShows('bob_queue', 'STOPPED');
        $this->ageMarker('bob', 'removing');
        $this->vanish('bob');
        $this->createInCpanel('bob');
        $this->sv->calls = [];

        $report = $this->lifecycle()->reconcile();

        $this->assertSame([], $report['restored']);
        $this->assertStringContainsString('earlier account', implode("\n", $report['errors']));
        $this->assertStringNotContainsString('restored', $this->logText());
        $this->assertSame([], $this->sv->calls);
        $this->assertNotNull($this->store->marker('bob', 'removing'));
    }

    public function test_identity_is_recorded_for_accounts_stored_before_identities(): void
    {
        $this->seed('bob');
        $legacy = new Account('bob', $this->account('bob')->workers);
        $this->store->save($legacy);
        $this->ini->write($legacy, $legacy->workers['queue'], true, false);
        $lc = $this->lifecycle();

        $lc->reconcile();
        $lc->reconcile();

        $this->assertTrue($this->identity('bob')->equals($this->account('bob')->identity));
        $this->assertCount(1, array_filter($this->logged, static fn (array $l) => str_contains($l[1], 'Recorded the identity')));
        $launch = explode("\n", (string) file_get_contents($this->paths->launchFile('bob', 'queue')));
        $this->assertSame((string) $this->identity('bob')->uid, $launch[2]);
        $this->assertSame([], $this->sv->calls);
    }

    /** @return array<string, array{0: string, 1: ?array}> */
    public static function hookFixtures(): array
    {
        return [
            'suspend pre' => ['suspendacct-pre.json', null],
            // data.user is the acting user ("root"); the account is data.args.user.
            'suspend post' => ['suspendacct-post.json', ['suspend', ['wnoshell']]],
            'unsuspend pre' => ['unsuspendacct-pre.json', null],
            'unsuspend post' => ['unsuspendacct-post.json', ['unsuspend', ['wnoshell']]],
            'rename pre' => ['modify-pre.json', ['prepareRename', ['wnoshell', 'wnoshell2']]],
            'rename post' => ['modify-post.json', ['rename', ['wnoshell', 'wnoshell2']]],
            'modify pre' => ['modify-nonrename-pre.json', null],
            'modify post' => ['modify-nonrename-post.json', null],
            'package pre' => ['change_package-pre.json', null],
            'package post' => ['change_package-post.json', ['packageChanged', ['wnoshell']]],
            'remove pre' => ['remove-pre.json', ['prepareRemove', ['wsvtmp']]],
            'remove post' => ['remove-post.json', ['finishRemove', ['wsvtmp']]],
        ];
    }

    #[DataProvider('hookFixtures')]
    public function test_hook_dispatch_from_fixtures(string $fixture, ?array $expected): void
    {
        $payload = json_decode((string) file_get_contents(self::FIXTURES . $fixture), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($expected, Lifecycle::route($payload));
    }

    public function test_hook_route_rejects_unusable_payloads(): void
    {
        $payload = json_decode((string) file_get_contents(self::FIXTURES . 'remove-pre.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertNull(Lifecycle::route([]));
        $this->assertNull(Lifecycle::route(['context' => ['category' => 'Cpanel'] + $payload['context']] + $payload));
        $this->assertNull(Lifecycle::route(['data' => ['user' => '../etc']] + $payload));
        $this->assertNull(Lifecycle::route(['data' => ['user' => ['bob']]] + $payload));
    }

    public function test_handle_runs_the_routed_method(): void
    {
        $this->seed('wnoshell');
        $payload = json_decode((string) file_get_contents(self::FIXTURES . 'suspendacct-post.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('suspend', $this->lifecycle()->handle($payload));
        $this->assertTrue($this->account('wnoshell')->suspended);
        $this->assertNull($this->lifecycle()->handle(['context' => [], 'data' => []]));
    }
}
