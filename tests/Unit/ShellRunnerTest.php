<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\Supervisor\ShellRunner;
use PHPUnit\Framework\TestCase;

final class ShellRunnerTest extends TestCase
{
    private string $out;

    protected function setUp(): void
    {
        $this->out = sys_get_temp_dir() . '/wsv_runner_' . bin2hex(random_bytes(4));
        // What a caller could leave behind: supervisorctl honours PYTHONPATH, PHP honours PHPRC.
        putenv('PYTHONPATH=/tmp/evil');
        putenv('PHPRC=/tmp/evil');
    }

    protected function tearDown(): void
    {
        putenv('PYTHONPATH');
        putenv('PHPRC');
        @unlink($this->out);
    }

    public function test_run_passes_only_a_minimal_environment(): void
    {
        [$code, $out] = (new ShellRunner())->run(['/usr/bin/env']);

        $this->assertSame(0, $code);
        $this->assertSame(['LANG=C', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin'], $this->sorted($out));
    }

    public function test_detach_passes_only_a_minimal_environment(): void
    {
        (new ShellRunner())->detach(['/bin/sh', '-c', '/usr/bin/env > "$0"', $this->out]);

        $this->assertSame(['LANG=C', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin'], $this->sorted((string) file_get_contents($this->out)));
    }

    /** @return list<string> */
    private function sorted(string $env): array
    {
        $lines = preg_split('/\n/', trim($env), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // sh adds its own bookkeeping variables.
        $lines = array_values(array_filter($lines, static fn (string $l) => preg_match('/^(PWD|SHLVL|_)=/', $l) !== 1));
        sort($lines);

        return $lines;
    }
}
