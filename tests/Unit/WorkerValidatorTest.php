<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\AccountProbe;
use WorkerSupervisor\CpUser;
use WorkerSupervisor\Issue;
use WorkerSupervisor\Limits;
use WorkerSupervisor\Paths;
use WorkerSupervisor\Worker;
use WorkerSupervisor\WorkerValidator;
use PHPUnit\Framework\TestCase;

final class WorkerValidatorTest extends TestCase
{
    private string $root;
    private string $home;
    private ?int $memoryLimit = null;
    private ?string $phpVersion = '8.2';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wsv_val_' . bin2hex(random_bytes(4));
        $this->home = $this->root . '/home/bob';
        mkdir($this->root . '/var/cpanel/users', 0777, true);
        mkdir($this->home . '/app', 0777, true);
        touch($this->home . '/app/artisan');
        file_put_contents($this->root . '/var/cpanel/users/bob', "OWNER=root\n");
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

    private function user(): CpUser
    {
        $home = $this->home;
        $u = CpUser::load(new Paths($this->root), 'bob', fn () => ['dir' => $home]);
        self::assertNotNull($u);
        return $u;
    }

    private function validator(): WorkerValidator
    {
        $test = $this;
        return new WorkerValidator(new class ($test) implements AccountProbe {
            public function __construct(private readonly WorkerValidatorTest $t)
            {
            }

            public function memoryLimitMb(string $user): ?int
            {
                return $this->t->limit();
            }

            public function phpVersion(string $user, string $folder): ?string
            {
                return $this->t->version();
            }
        });
    }

    public function limit(): ?int
    {
        return $this->memoryLimit;
    }

    public function version(): ?string
    {
        return $this->phpVersion;
    }

    private function worker(array $over = []): Worker
    {
        return Worker::fromArray($over + [
            'name' => 'queue',
            'template' => 'laravel-queue',
            'folder' => $this->home . '/app',
            'command' => 'php artisan queue:work --memory=128',
            'processes' => 1,
        ]);
    }

    private function limits(int $procs = 10, int $workers = 5): Limits
    {
        return new Limits($procs, $workers, 3600, ['laravel-queue', 'laravel-horizon', 'laravel-schedule']);
    }

    /** @param Worker[] $others */
    private function check(Worker $w, array $others = [], ?Limits $l = null): array
    {
        return $this->validator()->validate($w, $this->user(), $l ?? $this->limits(), $others);
    }

    /** @return Issue[] */
    private function byCode(array $issues, string $code): array
    {
        return array_values(array_filter($issues, static fn (Issue $i) => $i->code === $code));
    }

    private function levels(array $issues, string $level): array
    {
        return array_values(array_filter($issues, static fn (Issue $i) => $i->level === $level));
    }

    public function test_valid_laravel_worker_has_no_errors(): void
    {
        $issues = $this->check($this->worker());
        self::assertSame([], $this->levels($issues, 'error'));
        $info = $this->levels($issues, 'info');
        self::assertCount(1, $info);
        self::assertSame('php_version', $info[0]->code);
        self::assertSame('8.2', $info[0]->params['version']);
    }

    public function test_symlink_escaping_home_is_rejected(): void
    {
        symlink('/etc', $this->home . '/escape');
        $issues = $this->check($this->worker(['folder' => $this->home . '/escape']));
        self::assertCount(1, $this->byCode($issues, 'folder_outside_home'));
        self::assertSame([], $this->byCode($issues, 'php_version'));
    }

    public function test_dotdot_folder_is_rejected(): void
    {
        $issues = $this->check($this->worker(['folder' => $this->home . '/app/../../../../../../../../etc']));
        self::assertCount(1, $this->byCode($issues, 'folder_outside_home'));
    }

    public function test_folder_with_newline_is_invalid(): void
    {
        $issues = $this->check($this->worker(['folder' => $this->home . "/app\n"]));
        self::assertCount(1, $this->byCode($issues, 'folder_invalid'));
        self::assertSame([], $this->byCode($issues, 'folder_missing'));
    }

    public function test_empty_and_relative_folders_are_invalid(): void
    {
        foreach (['', 'app'] as $folder) {
            $issues = $this->check($this->worker(['folder' => $folder]));
            self::assertCount(1, $this->byCode($issues, 'folder_invalid'), $folder);
            self::assertSame([], $this->byCode($issues, 'folder_missing'), $folder);
        }
    }

    public function test_symlinked_home_is_resolved(): void
    {
        $real = $this->home;
        symlink($this->root . '/home', $this->root . '/link');
        $this->home = $this->root . '/link/bob';
        self::assertSame([], $this->levels($this->check($this->worker(['folder' => $this->home . '/app'])), 'error'));
        self::assertSame([], $this->levels($this->check($this->worker(['folder' => $real . '/app'])), 'error'));
    }

    public function test_folder_with_spaces_is_accepted(): void
    {
        mkdir($this->home . '/my app');
        touch($this->home . '/my app/artisan');
        $issues = $this->check($this->worker(['folder' => $this->home . '/my app']));
        self::assertSame([], $this->levels($issues, 'error'));
    }

    public function test_missing_folder(): void
    {
        $issues = $this->check($this->worker(['folder' => $this->home . '/nope']));
        self::assertCount(1, $this->byCode($issues, 'folder_missing'));
    }

    public function test_missing_artisan(): void
    {
        unlink($this->home . '/app/artisan');
        $issues = $this->check($this->worker());
        $found = $this->byCode($issues, 'required_path_missing');
        self::assertCount(1, $found);
        self::assertSame('artisan', $found[0]->params['path']);
        self::assertSame('folder', $found[0]->field);
        self::assertSame([], $this->byCode($issues, 'php_version'));
    }

    public function test_processes_count_against_other_workers(): void
    {
        $others = [$this->worker(['name' => 'a', 'processes' => 8])];
        $found = $this->byCode($this->check($this->worker(['processes' => 3]), $others), 'processes_range');
        self::assertCount(1, $found);
        self::assertSame(2, $found[0]->params['max']);
    }

    public function test_processes_range_max_never_negative(): void
    {
        $others = [$this->worker(['name' => 'a', 'processes' => 12])];
        $found = $this->byCode($this->check($this->worker(), $others), 'processes_range');
        self::assertSame(0, $found[0]->params['max']);
    }

    public function test_horizon_requires_one_process(): void
    {
        mkdir($this->home . '/app/vendor/laravel/horizon', 0777, true);
        $w = $this->worker(['template' => 'laravel-horizon', 'command' => 'php artisan horizon', 'processes' => 2]);
        $found = $this->byCode($this->check($w), 'processes_fixed');
        self::assertCount(1, $found);
        self::assertSame(1, $found[0]->params['value']);
    }

    public function test_memory_warning(): void
    {
        $this->memoryLimit = 2048;
        $w = $this->worker(['command' => 'php artisan queue:work --memory=1024', 'processes' => 5]);
        $found = $this->byCode($this->check($w), 'memory_over_limit');
        self::assertCount(1, $found);
        self::assertSame('warning', $found[0]->level);
        self::assertSame(5120, $found[0]->params['total_mb']);
        self::assertSame(2048, $found[0]->params['limit_mb']);
    }

    public function test_no_memory_warning_without_limit(): void
    {
        $w = $this->worker(['command' => 'php artisan queue:work --memory=1024', 'processes' => 5]);
        self::assertSame([], $this->byCode($this->check($w), 'memory_over_limit'));
    }

    public function test_command_with_newline_is_rejected(): void
    {
        $w = $this->worker(['command' => "php artisan queue:work\nrm -rf /"]);
        self::assertCount(1, $this->byCode($this->check($w), 'command_invalid'));
    }

    public function test_empty_command(): void
    {
        self::assertCount(1, $this->byCode($this->check($this->worker(['command' => '  '])), 'command_empty'));
    }

    public function test_name_invalid_and_taken(): void
    {
        self::assertCount(1, $this->byCode($this->check($this->worker(['name' => 'Bad_Name'])), 'name_invalid'));
        $others = [$this->worker(['name' => 'queue'])];
        self::assertCount(1, $this->byCode($this->check($this->worker(), $others), 'name_taken'));
    }

    public function test_name_taken(): void
    {
        $others = [$this->worker(['name' => 'queue'])];
        self::assertCount(1, $this->byCode($this->check($this->worker(), $others), 'name_taken'));
    }

    public function test_workers_limit(): void
    {
        $others = [$this->worker(['name' => 'a']), $this->worker(['name' => 'b'])];
        $found = $this->byCode($this->check($this->worker(), $others, $this->limits(10, 2)), 'workers_limit');
        self::assertCount(1, $found);
        self::assertSame(2, $found[0]->params['max']);
    }

    public function test_edit_over_limit_may_keep_but_not_grow(): void
    {
        $others = [$this->worker(['name' => 'a', 'processes' => 6]), $this->worker(['name' => 'b'])];
        $original = $this->worker(['processes' => 6]);
        $l = $this->limits(10, 2);
        $validate = fn (Worker $w) => $this->validator()->validate($w, $this->user(), $l, $others, $original);

        $kept = $validate($this->worker(['processes' => 6, 'command' => 'php artisan queue:work --tries=1']));
        self::assertSame([], $this->levels($kept, 'error'));

        $found = $this->byCode($validate($this->worker(['processes' => 7])), 'processes_range');
        self::assertCount(1, $found);
        self::assertSame(6, $found[0]->params['max']);

        // Without an original this is a new worker: both limits apply.
        $new = $this->check($this->worker(['processes' => 6]), $others, $l);
        self::assertCount(1, $this->byCode($new, 'workers_limit'));
        self::assertSame(3, $this->byCode($new, 'processes_range')[0]->params['max']);
    }

    public function test_unknown_and_disallowed_template(): void
    {
        self::assertCount(1, $this->byCode($this->check($this->worker(['template' => 'nope'])), 'template_unknown'));
        $l = new Limits(10, 5, 3600, ['laravel-schedule']);
        self::assertCount(1, $this->byCode($this->check($this->worker(), [], $l), 'template_not_allowed'));
    }

    public function test_numeric_ranges_and_autorestart(): void
    {
        $w = $this->worker(['stopwaitsecs' => 9999, 'startsecs' => -1, 'startretries' => 101, 'autorestart' => 'maybe']);
        $issues = $this->check($w);
        foreach (['stopwait_range', 'startsecs_range', 'startretries_range', 'autorestart_invalid'] as $code) {
            self::assertCount(1, $this->byCode($issues, $code), $code);
        }
        self::assertSame(3600, $this->byCode($issues, 'stopwait_range')[0]->params['max']);
    }

    public function test_laravel_needs_autorestart_warning(): void
    {
        $found = $this->byCode($this->check($this->worker(['autorestart' => 'unexpected'])), 'laravel_needs_autorestart');
        self::assertCount(1, $found);
        self::assertSame('warning', $found[0]->level);
    }

    public function test_issue_to_array_and_codes(): void
    {
        $i = new Issue('error', 'name', 'name_taken', ['a' => 1], 3);
        self::assertSame(
            ['level' => 'error', 'field' => 'name', 'code' => 'name_taken', 'params' => ['a' => 1], 'line' => 3],
            $i->toArray()
        );
        self::assertContains('folder_invalid', Issue::CODES);
    }
}
