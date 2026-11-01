<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\Account;
use WorkerSupervisor\AccountStore;
use WorkerSupervisor\Identity;
use WorkerSupervisor\Paths;
use WorkerSupervisor\Worker;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AccountStoreTest extends TestCase
{
    private string $root;
    private Paths $paths;
    private AccountStore $store;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wsv_store_' . bin2hex(random_bytes(4));
        $this->paths = new Paths($this->root);
        $this->store = new AccountStore($this->paths);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function worker(string $name = 'queue', array $extra = []): Worker
    {
        return Worker::fromArray(array_merge(
            ['name' => $name, 'template' => 'custom', 'folder' => '/home/u', 'command' => 'php run.php'],
            $extra,
        ));
    }

    public function test_load_missing_returns_empty(): void
    {
        $a = $this->store->load('alice');
        $this->assertSame('alice', $a->user);
        $this->assertSame([], $a->workers);
        $this->assertFalse($a->suspended);
        $this->assertFalse($a->featureOff);
    }

    public function test_save_and_load_round_trip(): void
    {
        $a = (new Account('alice'))->withWorker($this->worker('b'))->withWorker($this->worker('a'))->withFlags(true, true);
        $this->store->save($a);
        $b = $this->store->load('alice');
        $this->assertSame(['b', 'a'], array_keys($b->workers));
        $this->assertEquals($a->workers['a'], $b->workers['a']);
        $this->assertTrue($b->suspended);
        $this->assertTrue($b->featureOff);
        $raw = json_decode((string) file_get_contents($this->paths->accountFile('alice')), true);
        $this->assertSame(1, $raw['version']);
        $this->assertTrue(array_is_list($raw['workers']));
    }

    public function test_identity_round_trip(): void
    {
        $this->store->save(new Account('alice', identity: new Identity(1001, '1793487157')));
        $this->assertSame(['uid' => 1001, 'startdate' => '1793487157'], $this->store->load('alice')->identity?->toArray());

        $this->store->save(new Account('bob'));
        $this->assertNull($this->store->load('bob')->identity, 'stored before identities were recorded');

        file_put_contents($this->paths->accountFile('bob'), '{"identity": {"uid": "x"}, "workers": []}');
        $this->expectException(RuntimeException::class);
        $this->store->load('bob');
    }

    public function test_identity_survives_account_changes(): void
    {
        $a = new Account('alice', identity: new Identity(1001, '1'));

        $this->assertSame($a->identity, $a->withWorker($this->worker())->withoutWorker('queue')->withFlags(true, true)->identity);
        $this->assertTrue($a->ownedBy(new Identity(1001, '1')));
        $this->assertFalse($a->ownedBy(new Identity(1001, '2')));
        $this->assertFalse($a->ownedBy(null), 'an account that cannot be identified is not trusted');
        $this->assertTrue((new Account('alice'))->ownedBy(new Identity(1001, '1')), 'nothing recorded yet');
    }

    public function test_file_mode_is_0600(): void
    {
        $this->store->save((new Account('alice'))->withWorker($this->worker()));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->paths->accountFile('alice'))), -4));
    }

    public function test_corrupt_file_throws(): void
    {
        mkdir(dirname($this->paths->accountFile('alice')), 0700, true);
        file_put_contents($this->paths->accountFile('alice'), '{nope');
        $this->expectException(RuntimeException::class);
        $this->store->load('alice');
    }

    public function test_transaction_saves_returned_account(): void
    {
        $result = $this->store->transaction('alice', fn (Account $a) => [$a->withWorker($this->worker()), 'done']);
        $this->assertSame('done', $result);
        $this->assertArrayHasKey('queue', $this->store->load('alice')->workers);
    }

    public function test_transaction_exception_saves_nothing(): void
    {
        try {
            $this->store->transaction('alice', function (Account $a) {
                throw new RuntimeException('boom');
            });
            $this->fail('expected exception');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->paths->accountFile('alice'));
    }

    public function test_transaction_lock_serializes_writers(): void
    {
        $this->store->transaction('alice', fn (Account $a) => [$a, null]);
        $fh = fopen($this->paths->accountFile('alice') . '.lock', 'c');
        $this->assertTrue(flock($fh, LOCK_EX | LOCK_NB));
        flock($fh, LOCK_UN);
        $held = null;
        $this->store->transaction('alice', function (Account $a) use (&$held) {
            $other = fopen($this->paths->accountFile('alice') . '.lock', 'c');
            $held = !flock($other, LOCK_EX | LOCK_NB);
            fclose($other);
            return [$a, null];
        });
        $this->assertTrue($held);
    }

    public function test_with_worker_rename_and_without(): void
    {
        $a = (new Account('u'))->withWorker($this->worker('old'))->withWorker($this->worker('new'), 'old');
        $this->assertSame(['new'], array_keys($a->workers));
        $this->assertSame([], $a->withoutWorker('new')->workers);
    }

    public function test_rename_moves_file(): void
    {
        $this->store->save((new Account('alice'))->withWorker($this->worker()));
        $this->store->rename('alice', 'bob');
        $this->assertFileDoesNotExist($this->paths->accountFile('alice'));
        $this->assertArrayHasKey('queue', $this->store->load('bob')->workers);
        $this->assertSame('bob', $this->store->load('bob')->user);
    }

    public function test_a_waiting_restore_follows_a_rename_and_ends_with_a_delete(): void
    {
        $this->store->save(new Account('alice'));
        $this->store->mark('alice', 'restoring', '{"x":1}');
        $this->store->mark('alice', 'renaming', 'bob');
        $this->assertSame(['alice'], $this->store->markedUsers('restoring'));

        $this->store->rename('alice', 'bob');

        $this->assertSame('{"x":1}', $this->store->marker('bob', 'restoring')['value']);
        $this->assertNull($this->store->marker('alice', 'restoring'));
        $this->assertNull($this->store->marker('bob', 'renaming'));
        $this->assertSame(['bob'], $this->store->markedUsers('restoring'));

        $this->store->delete('bob');
        $this->assertSame([], $this->store->markedUsers('restoring'));
    }

    public function test_rename_refuses_existing_target(): void
    {
        $this->store->save(new Account('alice'));
        $this->store->save(new Account('bob'));
        $this->expectException(RuntimeException::class);
        $this->store->rename('alice', 'bob');
    }

    public function test_delete_and_users(): void
    {
        $this->assertSame([], $this->store->users());
        $this->store->save(new Account('zed'));
        $this->store->save(new Account('alice'));
        $this->assertSame(['alice', 'zed'], $this->store->users());
        $this->store->delete('zed');
        $this->assertSame(['alice'], $this->store->users());
    }

    public function test_with_lock_holds_the_lock_without_saving(): void
    {
        $held = null;
        $result = $this->store->withLock('alice', function () use (&$held): string {
            $other = fopen($this->paths->accountFile('alice') . '.lock', 'c');
            $held = !flock($other, LOCK_EX | LOCK_NB);
            fclose($other);

            return 'done';
        });

        $this->assertTrue($held);
        $this->assertSame('done', $result);
        $this->assertFileDoesNotExist($this->paths->accountFile('alice'));
    }

    public function test_with_locks_holds_every_lock(): void
    {
        $held = [];
        $this->store->withLocks(['zed', 'alice'], function () use (&$held): void {
            foreach (['alice', 'zed'] as $user) {
                $other = fopen($this->paths->accountFile($user) . '.lock', 'c');
                $held[$user] = !flock($other, LOCK_EX | LOCK_NB);
                fclose($other);
            }
        });

        $this->assertSame(['alice' => true, 'zed' => true], $held);
    }

    public function test_delete_and_rename_keep_lock_files(): void
    {
        // A process blocked on the lock would otherwise end up holding a lock on an unlinked inode.
        $this->store->transaction('alice', static fn (Account $a) => [$a->withWorker(new Worker('queue', 'custom', '/home/u', 'x')), null]);
        $this->store->rename('alice', 'bob');
        $this->assertFileExists($this->paths->accountFile('alice') . '.lock');

        $this->store->delete('bob');
        $this->assertFileDoesNotExist($this->paths->accountFile('bob'));
        $this->assertFileExists($this->paths->accountFile('alice') . '.lock');
    }

    public function test_markers_are_cleared_by_rename_and_delete(): void
    {
        $this->store->save(new Account('alice'));
        $this->assertNull($this->store->marker('alice', 'renaming'));

        $this->store->mark('alice', 'renaming', 'bob');
        $this->store->mark('alice', 'removing');
        $marker = $this->store->marker('alice', 'renaming');
        $this->assertSame('bob', $marker['value']);
        $this->assertGreaterThan(time() - 60, $marker['since']);
        $this->assertSame(['alice'], $this->store->users());

        $this->store->rename('alice', 'bob');
        $this->assertNull($this->store->marker('alice', 'renaming'));
        $this->assertNull($this->store->marker('alice', 'removing'));

        $this->store->mark('bob', 'removing');
        $this->store->unmark('bob', 'removing');
        $this->assertNull($this->store->marker('bob', 'removing'));
        $this->store->mark('bob', 'removing');
        $this->store->delete('bob');
        $this->assertNull($this->store->marker('bob', 'removing'));
    }

    public function test_exists(): void
    {
        $this->assertFalse($this->store->exists('alice'));
        $this->store->save(new Account('alice'));
        $this->assertTrue($this->store->exists('alice'));
    }

    public function test_runnable_rules(): void
    {
        $a = new Account('u');
        $this->assertTrue($a->runnable($this->worker()));
        $this->assertFalse($a->runnable($this->worker('q', ['admin_stopped' => true])));
        $this->assertFalse($a->runnable($this->worker('q', ['desired' => 'stopped'])));
        $this->assertFalse($a->withFlags(true, false)->runnable($this->worker()));
        $this->assertFalse($a->withFlags(false, true)->runnable($this->worker()));
    }

    public function test_lock_is_not_inherited_by_a_process_spawned_while_held(): void
    {
        // Service::save runs the account's login scripts (LveProbe) while holding this lock.
        $fds = $this->store->withLock('bob', static fn (): string => ChildFds::list());

        $this->assertStringContainsString('/proc/', $fds);
        $this->assertStringNotContainsString('bob.json.lock', $fds);
    }
}
