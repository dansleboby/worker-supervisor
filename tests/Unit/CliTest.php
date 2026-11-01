<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\AccountProbe;
use WorkerSupervisor\AccountStore;
use WorkerSupervisor\AdminService;
use WorkerSupervisor\Cli;
use WorkerSupervisor\CpUser;
use WorkerSupervisor\IniRenderer;
use WorkerSupervisor\Lifecycle;
use WorkerSupervisor\Logs;
use WorkerSupervisor\Paths;
use WorkerSupervisor\Service;
use WorkerSupervisor\ServiceError;
use WorkerSupervisor\Settings;
use WorkerSupervisor\Supervisor\SupervisorClient;
use WorkerSupervisor\WorkerValidator;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    private string $root;
    /** @var list<string> */
    private array $errors = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wsv_cli_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var/cpanel/users', 0777, true);
        mkdir($this->root . '/home/bob', 0777, true);
        file_put_contents($this->root . '/var/cpanel/users/bob', "OWNER=root\nPLAN=default\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function services(?callable $loader = null): array
    {
        $paths = new Paths($this->root);
        $home = $this->root . '/home/bob';
        $sv = new class implements SupervisorClient {
            public function status(): array { return []; }
            public function version(): ?string { return null; }
            public function apply(string $program, bool $start = false): void {}
            public function remove(string $program): void {}
            public function removeUnmanaged(string $program): void {}
            public function acceptsUnmanaged(string $program): bool { return true; }
            public function start(string $program): void {}
            public function stop(string $program): void {}
            public function restart(string $program): void {}
        };
        $probe = new class implements AccountProbe {
            public function memoryLimitMb(string $user): ?int { return null; }
            public function phpVersion(string $user, string $folder): ?string { return null; }
        };

        $loader ??= static fn (string $u): ?CpUser => CpUser::load($paths, $u, static fn () => ['dir' => $home]);
        $store = new AccountStore($paths);
        $settings = Settings::load($paths);
        $ini = new IniRenderer($paths);
        $service = new Service($paths, $store, $settings, $ini, $sv, new Logs($paths), new WorkerValidator($probe), $loader);

        return [
            'service' => $service,
            'admin' => new AdminService($service, $store, $settings, $sv, $loader, $ini),
            'lifecycle' => new Lifecycle($store, $settings, $ini, $sv, new Logs($paths), $loader, new FakeFeatures()),
        ];
    }

    private function call(string $action, string $stdin, ?array $services = null): array
    {
        $out = Cli::run($action, $stdin, $services ?? $this->services(), function (\Throwable $e): void {
            $this->errors[] = $e->getMessage();
        });
        self::assertStringEndsWith("\n", $out);
        self::assertSame(1, substr_count($out, "\n"));

        return json_decode($out, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_unknown_action_is_error_json(): void
    {
        $r = $this->call('frobnicate', '{"user":"bob","args":{}}');
        self::assertFalse($r['ok']);
        self::assertSame('bad_request', $r['error']['code']);
    }

    public function test_list_returns_ok_json(): void
    {
        $r = $this->call('list', '{"user":"bob","args":{}}');
        self::assertTrue($r['ok']);
        self::assertSame([], $r['data']['workers']);
        self::assertSame(0, $r['data']['usage']['workers']);
    }

    public function test_service_error_is_mapped(): void
    {
        $r = $this->call('get', '{"user":"bob","args":{"name":"nope"}}');
        self::assertFalse($r['ok']);
        self::assertSame('worker_unknown', $r['error']['code']);
        self::assertSame(['name' => 'nope'], $r['error']['params']);
        self::assertSame([], $r['error']['issues']);
    }

    public function test_validation_failure_carries_issues(): void
    {
        $r = $this->call('save', '{"user":"bob","args":{"input":{"name":"","template":"custom","command":"x"}}}');
        self::assertSame('validation_failed', $r['error']['code']);
        self::assertNotEmpty($r['error']['issues']);
        self::assertArrayHasKey('code', $r['error']['issues'][0]);
    }

    public function test_save_with_empty_original_name_creates(): void
    {
        $input = '{"name":"w1","template":"custom","folder":"' . $this->root . '/home/bob","command":"sleep 1","processes":1}';
        $r = $this->call('save', '{"user":"bob","args":{"input":' . $input . ',"original_name":"","mode":"form"}}');
        self::assertTrue($r['ok'], json_encode($r));
        self::assertSame('w1', $r['data']['worker']['name']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badRequests')]
    public function test_bad_request(string $stdin): void
    {
        $r = $this->call('list', $stdin);
        self::assertSame('bad_request', $r['error']['code']);
    }

    public static function badRequests(): array
    {
        return [
            'empty' => [''],
            'not json' => ['{nope'],
            'not an object' => ['[1]'],
            'no user' => ['{"args":{}}'],
            'empty user' => ['{"user":"","args":{}}'],
            'path user' => ['{"user":"../../etc/x","args":{}}'],
            'uppercase user' => ['{"user":"Bob","args":{}}'],
            'long user' => ['{"user":"abcdefghijklmnopq","args":{}}'],
            'user not string' => ['{"user":5,"args":{}}'],
            'args not object' => ['{"user":"bob","args":5}'],
        ];
    }

    public function test_bad_request_is_also_reported_for_the_log(): void
    {
        $r = $this->call('get', '{"user":"bob","args":{"name":5}}');

        self::assertSame('bad_request', $r['error']['code']);
        self::assertSame(['name must be a string.'], $this->errors);
    }

    public function test_missing_name_is_bad_request(): void
    {
        $r = $this->call('start', '{"user":"bob","args":{}}');
        self::assertSame('bad_request', $r['error']['code']);
    }

    public function test_invalid_log_args_are_bad_request(): void
    {
        $folder = $this->root . '/home/bob';
        $r = $this->call('save', '{"user":"bob","args":{"input":{"name":"w1","template":"custom","folder":"' . $folder . '","command":"sleep 1"}}}');
        self::assertTrue($r['ok'], json_encode($r));
        $r = $this->call('logs', '{"user":"bob","args":{"name":"w1","stream":"bogus"}}');
        self::assertSame('bad_request', $r['error']['code']);
    }

    public function test_unexpected_throwable_is_internal_and_not_leaked(): void
    {
        $loader = static function (string $u): never {
            throw new \RuntimeException('secret /etc/path detail');
        };
        $out = Cli::run('list', '{"user":"bob","args":{}}', $this->services($loader), function (\Throwable $e): void {
            $this->errors[] = $e->getMessage();
        });
        self::assertStringNotContainsString('secret', $out);
        self::assertSame('internal', json_decode($out, true)['error']['code']);
        self::assertSame(['secret /etc/path detail'], $this->errors);
    }

    public function test_overview_is_a_root_action_without_stdin(): void
    {
        $r = $this->call('overview', '');
        self::assertTrue($r['ok'], json_encode($r));
        self::assertSame([], $r['data']['rows']);
        self::assertFalse($r['data']['health']['running']);
    }

    public function test_reconcile_is_a_root_action_without_stdin(): void
    {
        $r = $this->call('reconcile', '');
        self::assertTrue($r['ok'], json_encode($r));
        self::assertSame([], $r['data']['removed']);
        self::assertSame('checked', $r['data']['features']);
        self::assertFalse(Cli::needsStdin('reconcile'));
        self::assertFalse(Cli::needsStdin('overview'));
        self::assertTrue(Cli::needsStdin('list'));
        self::assertTrue(Cli::needsStdin('admin_stop'));
    }

    public function test_admin_actions_target_the_named_account(): void
    {
        $folder = $this->root . '/home/bob';
        $r = $this->call('save', '{"user":"bob","args":{"input":{"name":"w1","template":"custom","folder":"' . $folder . '","command":"sleep 1"}}}');
        self::assertTrue($r['ok'], json_encode($r));

        self::assertTrue($this->call('admin_stop', '{"user":"bob","args":{"name":"w1"}}')['ok']);
        self::assertSame('admin_stopped', $this->call('start', '{"user":"bob","args":{"name":"w1"}}')['error']['code']);
        self::assertStringContainsString('autostart=false', $this->call('admin_config', '{"user":"bob","args":{"name":"w1"}}')['data']['ini']);

        $r = $this->call('admin_release', '{"user":"bob","args":{"name":"w1"}}');
        self::assertSame(['started' => true], $r['data']);
        self::assertSame('bad_request', $this->call('admin_stop', '{"user":"bob","args":{}}')['error']['code']);
        self::assertSame('bad_request', $this->call('admin_stop', '{"user":"../x","args":{"name":"w1"}}')['error']['code']);
    }

    public function test_bad_request_is_a_declared_error_code(): void
    {
        self::assertContains('bad_request', ServiceError::CODES);
    }
}
