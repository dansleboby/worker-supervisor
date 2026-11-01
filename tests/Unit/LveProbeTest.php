<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\LveProbe;
use WorkerSupervisor\Supervisor\Runner;
use PHPUnit\Framework\TestCase;

final class LveProbeTest extends TestCase
{
    private ?Runner $runner = null;

    /** @param array{0: int, 1: string} $result */
    private function probe(array $result): LveProbe
    {
        $this->runner = new class ($result) implements Runner {
            /** @var list<list<string>> */
            public array $argv = [];

            /** @param array{0: int, 1: string} $result */
            public function __construct(private readonly array $result)
            {
            }

            public function run(array $argv): array
            {
                $this->argv[] = $argv;

                return $this->result;
            }

            public function detach(array $argv): void
            {
                throw new \LogicException('not used');
            }
        };

        return new LveProbe($this->runner, static fn (string $u) => $u === 'wshell' ? ['uid' => 1001, 'dir' => '/home/wshell'] : false);
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/lvectl-paneluserlimits.json');
    }

    /** @return list<list<string>> */
    private function argv(): array
    {
        return $this->runner->argv;
    }

    /** Fixture with the PMEM of the default row (ID 0) and the uid row replaced. */
    private function withPmem(string $default, string $user): string
    {
        $data = json_decode($this->fixture(), true);
        $data['data'][0]['PMEM'] = $default;
        $data['data'][1]['PMEM'] = $user;

        return (string) json_encode($data);
    }

    public function test_memory_limit_is_read_from_the_users_row(): void
    {
        $this->assertSame(1024, $this->probe([0, $this->fixture()])->memoryLimitMb('wshell'));
        $this->assertSame(
            [['/usr/bin/timeout', '-k', '5', '10', '/usr/sbin/lvectl', 'paneluserlimits', '1001', '--json', '--bytes']],
            $this->argv(),
        );

        // Row ID 0 holds the server defaults; only the uid row counts.
        $this->assertSame(2048, $this->probe([0, $this->withPmem('536870912', '2147483648')])->memoryLimitMb('wshell'));
    }

    public function test_memory_limit_is_null_when_unlimited_or_unknown(): void
    {
        $this->assertNull($this->probe([0, $this->withPmem('1073741824', '0')])->memoryLimitMb('wshell'));
        $this->assertNull($this->probe([0, '{"data":[{"ID":"0","PMEM":"1073741824"}]}'])->memoryLimitMb('wshell'));
        $this->assertNull($this->probe([1, $this->fixture()])->memoryLimitMb('wshell'));
        $this->assertNull($this->probe([0, 'Traceback (most recent call last):'])->memoryLimitMb('wshell'));
        $this->assertNull($this->probe([0, $this->fixture()])->memoryLimitMb('ghost'));
    }

    public function test_php_version_runs_inside_cagefs_with_the_launcher_environment_and_a_timeout(): void
    {
        $this->assertSame('8.2.35', $this->probe([0, '8.2.35'])->phpVersion('wshell', '/home/wshell/my app'));
        $this->assertSame([[
            '/usr/bin/timeout', '-k', '5', '10',
            '/usr/bin/env', '-i', 'HOME=/home/wshell', 'USER=wshell', 'LOGNAME=wshell', 'SHELL=/bin/bash',
            'PATH=/usr/local/bin:/usr/bin:/bin', 'LANG=C.UTF-8',
            '/usr/sbin/cagefs_enter_user', 'wshell',
            '/bin/bash', '-lc', 'cd -- "$1" && php -r "echo PHP_VERSION;"', 'x', '/home/wshell/my app',
        ]], $this->argv());
    }

    public function test_php_version_ignores_login_noise_before_the_version(): void
    {
        $this->assertSame('8.3.1', $this->probe([0, "Welcome back\n8.3.1"])->phpVersion('wshell', '/home/wshell'));
    }

    public function test_php_version_is_null_on_any_failure(): void
    {
        $this->assertNull($this->probe([1, ''])->phpVersion('wshell', '/home/wshell/missing'));
        $this->assertNull($this->probe([124, ''])->phpVersion('wshell', '/home/wshell'));
        $this->assertNull($this->probe([0, 'bash: php: command not found'])->phpVersion('wshell', '/home/wshell'));
        $this->assertNull($this->probe([0, '8.2.35'])->phpVersion('ghost', '/home/ghost'));
    }
}
