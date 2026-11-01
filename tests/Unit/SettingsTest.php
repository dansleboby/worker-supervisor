<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\Paths;
use WorkerSupervisor\Settings;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SettingsTest extends TestCase
{
    private string $root;
    private Paths $paths;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wsv_settings_' . bin2hex(random_bytes(4));
        $this->paths = new Paths($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function test_transaction_keeps_changes_made_since_load(): void
    {
        $whm = Settings::load($this->paths);
        $hook = Settings::load($this->paths);

        $hook->transaction(static fn (Settings $s) => $s->setOverride('alice', ['max_workers' => 2]));
        $whm->transaction(static fn (Settings $s) => $s->setOverride('bob', ['max_workers' => 3]));

        $saved = Settings::load($this->paths);
        $this->assertSame(['max_workers' => 2], $saved->override('alice'));
        $this->assertSame(['max_workers' => 3], $saved->override('bob'));
        $this->assertSame(['max_workers' => 2], $whm->override('alice'), 'the instance itself is refreshed');
    }

    public function test_transaction_holds_the_lock_and_returns_the_result(): void
    {
        $held = null;
        $result = Settings::load($this->paths)->transaction(function () use (&$held): string {
            $other = fopen($this->paths->settingsFile() . '.lock', 'c');
            $held = !flock($other, LOCK_EX | LOCK_NB);
            fclose($other);

            return 'done';
        });

        $this->assertTrue($held);
        $this->assertSame('done', $result);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->paths->settingsFile() . '.lock')), -4));
    }

    public function test_transaction_without_a_change_writes_nothing(): void
    {
        Settings::load($this->paths)->transaction(static fn (Settings $s) => $s->forgetUser('bob'));
        $this->assertFileDoesNotExist($this->paths->settingsFile());

        Settings::load($this->paths)->transaction(static fn (Settings $s) => $s->setOverride('alice', ['max_workers' => 2]));
        touch($this->paths->settingsFile(), time() - 60);
        clearstatcache();
        $mtime = filemtime($this->paths->settingsFile());

        Settings::load($this->paths)->transaction(static fn (Settings $s) => $s->renameUser('bob', 'robert'));
        Settings::load($this->paths)->transaction(static fn (Settings $s) => $s->forgetUser('bob'));

        clearstatcache();
        $this->assertSame($mtime, filemtime($this->paths->settingsFile()));
    }

    public function test_transaction_never_overwrites_an_unparsable_file(): void
    {
        mkdir(dirname($this->paths->settingsFile()), 0700, true);
        file_put_contents($this->paths->settingsFile(), '{"overrides": {"alice": {"max_workers": 2}');

        // An account removal has nothing to forget here, so it neither writes nor fails.
        Settings::load($this->paths)->transaction(static fn (Settings $s) => $s->forgetUser('alice'));

        try {
            Settings::load($this->paths)->transaction(static fn (Settings $s) => $s->setOverride('bob', ['max_workers' => 3]));
            $this->fail('Expected the save to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be parsed', $e->getMessage());
        }
        $this->assertSame('{"overrides": {"alice": {"max_workers": 2}', file_get_contents($this->paths->settingsFile()));
    }

    public function test_transaction_exception_saves_nothing(): void
    {
        try {
            Settings::load($this->paths)->transaction(static function (Settings $s): void {
                $s->setOverride('bob', ['max_workers' => 3]);
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        $this->assertFileDoesNotExist($this->paths->settingsFile());
    }

    public function test_lock_is_not_inherited_by_a_process_spawned_while_held(): void
    {
        $fds = Settings::load($this->paths)->transaction(static fn (): string => ChildFds::list());

        $this->assertStringContainsString('/proc/', $fds);
        $this->assertStringNotContainsString('settings.json.lock', $fds);
    }
}
