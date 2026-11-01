<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\Account;
use WorkerSupervisor\AccountProbe;
use WorkerSupervisor\AccountStore;
use WorkerSupervisor\Backup;
use WorkerSupervisor\CpUser;
use WorkerSupervisor\Identity;
use WorkerSupervisor\IniRenderer;
use WorkerSupervisor\Paths;
use WorkerSupervisor\Settings;
use WorkerSupervisor\Worker;
use WorkerSupervisor\WorkerValidator;
use PHPUnit\Framework\TestCase;

final class BackupTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/hooks/';

    private string $root;
    private Paths $paths;
    private AccountStore $store;
    private IniRenderer $ini;
    private FakeSupervisor $sv;
    private FakeFeatures $features;
    private int $now = 1_800_000_000;
    /** @var list<array{0: int, 1: string}> */
    private array $logged = [];
    /** @var array<string, int> */
    private array $uids = [];
    private int $nextUid = 1001;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wsv_backup_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var/cpanel/users', 0777, true);
        mkdir($this->root . '/var/cpanel/suspended', 0777, true);
        mkdir($this->root . '/extract', 0777, true);
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

    private function backup(): Backup
    {
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

        return new Backup(
            $this->paths,
            $this->store,
            Settings::load($this->paths),
            $this->ini,
            $this->sv,
            new WorkerValidator($probe),
            fn (string $u): ?CpUser => CpUser::load($this->paths, $u, $this->passwd(...)),
            $this->features,
            function (int $priority, string $message): void {
                $this->logged[] = [$priority, $message];
            },
            fn (): int => $this->now,
        );
    }

    private function passwd(string $user): array|false
    {
        if (!isset($this->uids[$user])) {
            return false;
        }

        return ['name' => $user, 'uid' => $this->uids[$user], 'dir' => $this->home($user)];
    }

    private function home(string $user): string
    {
        return $this->root . '/home/' . $user;
    }

    /** A cPanel account with an app folder holding a Laravel artisan file. */
    private function createInCpanel(string $user, bool $suspended = false): void
    {
        $this->uids[$user] = $this->nextUid++;
        file_put_contents($this->paths->cpuserFile($user), "OWNER=root\nPLAN=default\nSTARTDATE=" . (1793000000 + $this->uids[$user]) . "\n");
        @mkdir($this->home($user) . '/app', 0777, true);
        touch($this->home($user) . '/app/artisan');
        if ($suspended) {
            touch($this->paths->suspendedFlag($user));
        }
    }

    private function identity(string $user): Identity
    {
        return CpUser::load($this->paths, $user, $this->passwd(...))->identity();
    }

    /** @param array<string, array<string, mixed>> $workers */
    private function seed(string $user, array $workers): void
    {
        $a = new Account($user, identity: $this->identity($user));
        foreach ($workers as $name => $extra) {
            $a = $a->withWorker($this->worker($user, $name, $extra));
        }
        $this->store->save($a);
        foreach ($a->workers as $w) {
            $this->ini->write($a, $w, $a->runnable($w), $a->held($w));
        }
    }

    /** @param array<string, mixed> $extra */
    private function worker(string $user, string $name, array $extra = []): Worker
    {
        return Worker::fromArray($extra + [
            'name' => $name, 'template' => 'custom', 'folder' => $this->home($user) . '/app', 'command' => 'php run.php',
        ]);
    }

    /**
     * What the pkgacct component leaves in an archive.
     *
     * @param list<array<string, mixed>> $workers
     */
    private function archive(array $workers, string $home): string
    {
        file_put_contents($this->root . '/extract/' . Backup::FILE, json_encode([
            'format' => 1, 'version' => '1.1.0', 'user' => 'bob', 'home' => $home, 'workers' => $workers,
        ]));

        return $this->root . '/extract';
    }

    /** @param array<string, mixed> $extra */
    private function record(string $name, string $folder, array $extra = []): array
    {
        return $extra + Worker::fromArray(['name' => $name, 'template' => 'custom', 'folder' => $folder, 'command' => 'php run.php'])->toArray();
    }

    /** @return array<string, Worker> */
    private function workers(string $user): array
    {
        return $this->store->load($user)->workers;
    }

    private function logText(): string
    {
        return implode("\n", array_column($this->logged, 1));
    }

    public function test_route_takes_only_the_restore_post_hook(): void
    {
        $post = json_decode((string) file_get_contents(self::FIXTURES . 'pkgacct-restore-post.json'), true);

        $this->assertSame(
            ['restore', ['wshell', '/root/bk/cpanelpkgrestore.TMP.work.ilkCCT7k/safe_to_read_archive/contents']],
            Backup::route($post),
        );
        $this->assertNull(Backup::route(json_decode((string) file_get_contents(self::FIXTURES . 'pkgacct-restore-postextract.json'), true)));
        $this->assertNull(Backup::route(json_decode((string) file_get_contents(self::FIXTURES . 'remove-post.json'), true)));

        $post['data']['user'] = '/root/bk/cpmove-wshell.tar.gz';
        $this->assertNull(Backup::route($post), 'data.user can be a path to the archive');
        $post['data']['user'] = 'wshell';
        $post['data']['extract_dir'] = 'relative/dir';
        $this->assertNull(Backup::route($post));
    }

    public function test_export_carries_the_workers_and_the_home(): void
    {
        $this->createInCpanel('bob');
        $this->seed('bob', ['queue' => ['processes' => 2], 'cron' => ['desired' => 'stopped', 'admin_stopped' => true]]);

        $data = $this->backup()->export('bob');

        $this->assertSame(1, $data['format']);
        $this->assertSame('bob', $data['user']);
        $this->assertSame($this->home('bob'), $data['home']);
        $this->assertSame(
            [$this->worker('bob', 'queue', ['processes' => 2])->toArray(), $this->worker('bob', 'cron', ['desired' => 'stopped', 'admin_stopped' => true])->toArray()],
            $data['workers'],
        );
    }

    public function test_export_is_null_for_an_account_the_plugin_never_stored_or_an_earlier_account(): void
    {
        $this->createInCpanel('bob');
        $this->assertNull($this->backup()->export('bob'));

        $this->store->save(new Account('bob', [], identity: new Identity(999, '1')));
        $this->assertNull($this->backup()->export('bob'), 'data of an earlier account of the name is not this account\'s');
    }

    public function test_restore_creates_the_workers_of_a_new_account_in_their_saved_state(): void
    {
        $this->createInCpanel('bob');
        $dir = $this->archive([
            $this->record('queue', $this->home('bob') . '/app', ['processes' => 2]),
            $this->record('cron', $this->home('bob') . '/app', ['desired' => 'stopped']),
        ], $this->home('bob'));

        $report = $this->backup()->restore('bob', $dir);

        $this->assertSame(['queue', 'cron'], $report['restored']);
        $this->assertSame(2, $this->workers('bob')['queue']->processes);
        $this->assertSame('stopped', $this->workers('bob')['cron']->desired);
        $this->assertTrue($this->identity('bob')->equals($this->store->load('bob')->identity));
        $this->assertFileExists($this->paths->iniFile('bob', 'queue'));
        $this->assertSame([['apply', 'bob_queue', true], ['apply', 'bob_cron', false]], $this->sv->calls);
        $this->assertNull($this->store->marker('bob', 'restoring'), 'nothing left to apply');
    }

    public function test_restore_makes_the_account_match_the_backup_and_leaves_identical_workers_alone(): void
    {
        $this->createInCpanel('bob');
        $this->seed('bob', ['same' => [], 'changed' => ['processes' => 1], 'extra' => []]);
        $dir = $this->archive([
            $this->record('same', $this->home('bob') . '/app'),
            $this->record('changed', $this->home('bob') . '/app', ['processes' => 3]),
            $this->record('added', $this->home('bob') . '/app'),
        ], $this->home('bob'));

        $report = $this->backup()->restore('bob', $dir);

        $this->assertSame(['changed', 'added'], $report['restored']);
        $this->assertSame(['same'], $report['unchanged']);
        $this->assertSame(['extra'], $report['removed']);
        $this->assertSame(['same', 'changed', 'added'], array_keys($this->workers('bob')));
        $this->assertFileDoesNotExist($this->paths->iniFile('bob', 'extra'));
        $this->assertSame(
            [['remove', 'bob_extra'], ['apply', 'bob_changed', true], ['apply', 'bob_added', true]],
            $this->sv->calls,
            'the identical worker is neither restarted nor reloaded',
        );
    }

    public function test_restore_moves_folders_from_the_backup_home_to_the_account_home(): void
    {
        $this->createInCpanel('bob');
        $dir = $this->archive([$this->record('queue', '/home/olduser/app')], '/home/olduser');

        $this->backup()->restore('bob', $dir);

        $this->assertSame($this->home('bob') . '/app', $this->workers('bob')['queue']->folder);
    }

    public function test_workers_whose_folder_is_not_there_yet_wait_for_reconcile(): void
    {
        $this->createInCpanel('bob');
        $dir = $this->archive([
            $this->record('queue', $this->home('bob') . '/app'),
            $this->record('later', $this->home('bob') . '/site'),
            $this->record('laravel', $this->home('bob') . '/site', ['template' => 'laravel-queue']),
        ], $this->home('bob'));

        $report = $this->backup()->restore('bob', $dir);

        $this->assertSame(['queue'], $report['restored']);
        $this->assertSame(['later', 'laravel'], $report['pending']);
        $this->assertNotNull($this->store->marker('bob', 'restoring'));

        // JetBackup restores the home directory after the restore hook.
        mkdir($this->home('bob') . '/site');
        $report = $this->backup()->resumeAll()['bob'];
        $this->assertSame(['later'], $report['restored']);
        $this->assertSame(['laravel'], $report['pending'], 'the template still needs artisan');
        $this->assertSame(['queue', 'later'], array_keys($this->workers('bob')), 'a later pass adds, it removes nothing');

        touch($this->home('bob') . '/site/artisan');
        $this->backup()->resumeAll();
        $this->assertSame(['queue', 'later', 'laravel'], array_keys($this->workers('bob')));
        $this->assertNull($this->store->marker('bob', 'restoring'));
    }

    public function test_waiting_workers_never_go_to_a_later_account_given_the_name(): void
    {
        $this->createInCpanel('bob');
        $this->backup()->restore('bob', $this->archive([$this->record('later', $this->home('bob') . '/site')], $this->home('bob')));

        // bob is removed, and a new account takes the name before the folder ever appeared.
        unset($this->uids['bob']);
        $this->createInCpanel('bob');
        mkdir($this->home('bob') . '/site');
        $report = $this->backup()->resumeAll()['bob'];

        $this->assertSame('account_replaced', $report['error']);
        $this->assertSame([], $this->workers('bob'));
        $this->assertNull($this->store->marker('bob', 'restoring'));
    }

    public function test_pending_workers_are_dropped_and_logged_after_a_day(): void
    {
        $this->createInCpanel('bob');
        $this->backup()->restore('bob', $this->archive([$this->record('later', $this->home('bob') . '/site')], $this->home('bob')));

        $this->now += 86400 + 1;
        $report = $this->backup()->resumeAll()['bob'];

        $this->assertSame(['later'], $report['expired']);
        $this->assertNull($this->store->marker('bob', 'restoring'));
        $this->assertStringContainsString('later', $this->logText());
    }

    public function test_workers_refused_for_limits_or_bad_values_are_skipped_and_logged(): void
    {
        $this->createInCpanel('bob');
        $dir = $this->archive([
            $this->record('big', $this->home('bob') . '/app', ['processes' => 11]),
            $this->record('Bad Name', $this->home('bob') . '/app'),
            ['name' => 'broken'],
            'not an object',
            $this->record('ok', $this->home('bob') . '/app'),
        ], $this->home('bob'));

        $report = $this->backup()->restore('bob', $dir);

        $this->assertSame(['ok'], $report['restored']);
        $this->assertSame(['processes_range'], $report['skipped']['big']);
        $this->assertSame(['name_invalid'], $report['skipped']['#2'], 'a refused name never reaches the log');
        $this->assertSame(['input_invalid'], $report['skipped']['broken']);
        $this->assertSame(['input_invalid'], $report['skipped']['#4']);
        $this->assertSame([], $report['pending']);
        $this->assertStringContainsString('big', $this->logText());
    }

    public function test_restore_keeps_admin_stops_and_holds_the_workers_of_a_suspended_account(): void
    {
        $this->createInCpanel('bob', suspended: true);
        $dir = $this->archive([
            $this->record('queue', $this->home('bob') . '/app'),
            $this->record('held', $this->home('bob') . '/app', ['admin_stopped' => true]),
        ], $this->home('bob'));

        $this->backup()->restore('bob', $dir);

        $this->assertTrue($this->workers('bob')['held']->adminStopped);
        $this->assertSame([['apply', 'bob_queue', false], ['apply', 'bob_held', false]], $this->sv->calls);
        $this->assertFileExists($this->paths->holdFile('bob', 'queue'));
    }

    public function test_restore_holds_the_workers_of_an_account_without_the_feature(): void
    {
        $this->createInCpanel('bob');
        $this->features->enabled = ['bob' => false];

        $this->backup()->restore('bob', $this->archive([$this->record('queue', $this->home('bob') . '/app')], $this->home('bob')));

        $this->assertTrue($this->store->load('bob')->featureOff);
        $this->assertSame([['apply', 'bob_queue', false]], $this->sv->calls);
    }

    public function test_data_of_an_earlier_account_is_left_alone_and_the_restore_waits(): void
    {
        $this->createInCpanel('bob');
        $this->store->save(new Account('bob', ['old' => $this->worker('bob', 'old')], identity: new Identity(999, '1')));

        $report = $this->backup()->restore('bob', $this->archive([$this->record('queue', $this->home('bob') . '/app')], $this->home('bob')));

        $this->assertSame('account_mismatch', $report['error']);
        $this->assertSame(['old'], array_keys($this->workers('bob')));
        $this->assertNotNull($this->store->marker('bob', 'restoring'), 'applied once root removes the stale data');
        $this->assertSame([], $this->sv->calls);
    }

    public function test_an_archive_without_workers_file_changes_nothing(): void
    {
        $this->createInCpanel('bob');
        $this->seed('bob', ['queue' => []]);

        $this->assertNull($this->backup()->restore('bob', $this->root . '/extract'));
        $this->assertSame(['queue'], array_keys($this->workers('bob')));
    }

    public function test_a_malformed_symlinked_or_oversized_file_is_refused(): void
    {
        $this->createInCpanel('bob');
        $this->seed('bob', ['queue' => []]);
        $file = $this->root . '/extract/' . Backup::FILE;

        foreach (['{"format": 2, "home": "/home/bob", "workers": []}', '{"format": 1, "home": "/home/bob"}', 'nope', str_repeat(' ', 1048577) . '{}'] as $content) {
            file_put_contents($file, $content);
            $report = $this->backup()->restore('bob', $this->root . '/extract');
            $this->assertSame('archive_invalid', $report['error'], substr($content, 0, 40));
        }

        unlink($file);
        file_put_contents($this->root . '/elsewhere.json', json_encode(['format' => 1, 'home' => '/home/bob', 'workers' => []]));
        symlink($this->root . '/elsewhere.json', $file);
        $this->assertSame('archive_invalid', $this->backup()->restore('bob', $this->root . '/extract')['error']);

        $this->assertSame(['queue'], array_keys($this->workers('bob')));
        $this->assertNull($this->store->marker('bob', 'restoring'));
    }

    public function test_a_restore_for_an_account_that_cannot_be_read_is_refused(): void
    {
        $report = $this->backup()->restore('bob', $this->archive([$this->record('queue', '/home/bob/app')], '/home/bob'));

        $this->assertSame('account_unknown', $report['error']);
        $this->assertNull($this->store->marker('bob', 'restoring'), 'never staged without the identity it applies to');
    }

    public function test_a_failed_system_user_lookup_waits_instead_of_dropping_the_restore(): void
    {
        $this->createInCpanel('bob');
        $this->backup()->restore('bob', $this->archive([$this->record('later', $this->home('bob') . '/site')], $this->home('bob')));

        $uid = $this->uids['bob'];
        unset($this->uids['bob']);
        $this->assertSame('account_unknown', $this->backup()->resumeAll()['bob']['error']);
        $this->assertNotNull($this->store->marker('bob', 'restoring'));

        $this->uids['bob'] = $uid;
        mkdir($this->home('bob') . '/site');
        $this->assertSame(['later'], $this->backup()->resumeAll()['bob']['restored']);
    }

    public function test_an_archive_without_workers_ends_an_earlier_restore_still_waiting(): void
    {
        $this->createInCpanel('bob');
        $this->backup()->restore('bob', $this->archive([$this->record('later', $this->home('bob') . '/site')], $this->home('bob')));
        unlink($this->root . '/extract/' . Backup::FILE);

        $this->assertNull($this->backup()->restore('bob', $this->root . '/extract'));
        $this->assertNull($this->store->marker('bob', 'restoring'));
    }

    public function test_a_later_pass_leaves_a_worker_the_client_created_meanwhile(): void
    {
        $this->createInCpanel('bob');
        $this->backup()->restore('bob', $this->archive([$this->record('later', $this->home('bob') . '/site')], $this->home('bob')));
        $this->seed('bob', ['later' => ['command' => 'client own']]);
        mkdir($this->home('bob') . '/site');

        $report = $this->backup()->resumeAll()['bob'];

        $this->assertSame(['name_taken'], $report['skipped']['later']);
        $this->assertSame('client own', $this->workers('bob')['later']->command);
        $this->assertNull($this->store->marker('bob', 'restoring'));
    }

    public function test_a_restored_state_reaches_supervisord_when_the_ini_does_not_change(): void
    {
        $this->createInCpanel('bob');
        $this->seed('bob', [
            'on' => ['autostart' => false],
            'off' => ['autostart' => false, 'desired' => 'stopped'],
        ]);
        $dir = $this->archive([
            $this->record('on', $this->home('bob') . '/app', ['autostart' => false, 'desired' => 'stopped']),
            $this->record('off', $this->home('bob') . '/app', ['autostart' => false]),
        ], $this->home('bob'));

        $this->backup()->restore('bob', $dir);

        $this->assertSame([['stop', 'bob_on'], ['apply', 'bob_off', true]], $this->sv->calls);
    }

    public function test_an_unreadable_staged_restore_is_dropped_without_blocking_the_account(): void
    {
        $this->createInCpanel('bob');
        $this->seed('bob', ['queue' => []]);
        $this->store->withLock('bob', fn () => $this->store->mark('bob', 'restoring', '{"staged_at": 1, "wor'));

        $this->assertSame([], $this->backup()->resumeAll());

        $this->assertNull($this->store->marker('bob', 'restoring'));
        $this->assertStringContainsString('unreadable', $this->logText());
        $this->assertSame(['queue'], array_keys($this->workers('bob')));
    }

    public function test_an_archive_listing_too_many_workers_is_refused(): void
    {
        $this->createInCpanel('bob');
        $records = [];
        for ($i = 0; $i < 201; $i++) {
            $records[] = $this->record('w' . $i, $this->home('bob') . '/app');
        }

        $this->assertSame('archive_invalid', $this->backup()->restore('bob', $this->archive($records, $this->home('bob')))['error']);
    }
}
