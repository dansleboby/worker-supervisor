<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\Logs;
use WorkerSupervisor\Paths;
use WorkerSupervisor\Worker;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LogsTest extends TestCase
{
    private string $root;
    private Logs $logs;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wsv_logs_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var/log/worker_supervisor/bob', 0700, true);
        $this->logs = new Logs(new Paths($this->root));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function worker(int $processes = 1, bool $redirect = true): Worker
    {
        return new Worker('queue', 'custom', '/home/bob', 'php artisan queue:work', $processes, redirectStderr: $redirect);
    }

    private function logFile(string $file): string
    {
        return $this->root . '/var/log/worker_supervisor/bob/' . $file;
    }

    public function test_files_lists_out_and_err_streams(): void
    {
        $files = $this->logs->files('bob', $this->worker(2, false));

        $this->assertCount(4, $files);
        $this->assertSame('00', $files[0]['process']);
        $this->assertSame('out', $files[0]['stream']);
        $this->assertSame($this->logFile('queue_00.log'), $files[0]['path']);
        $this->assertFalse($files[0]['exists']);
        $this->assertSame($this->logFile('queue_01.err.log'), $files[3]['path']);

        $this->assertCount(2, $this->logs->files('bob', $this->worker(2, true)));
    }

    public function test_tail_returns_last_lines(): void
    {
        file_put_contents($this->logFile('queue_00.log'), "a\nb\nc\nd\n");

        $this->assertSame("c\nd\n", $this->logs->tail('bob', $this->worker(), 0, 'out', 2));
        $this->assertSame("a\nb\nc\nd\n", $this->logs->tail('bob', $this->worker(), 0, 'out', 50));
    }

    public function test_tail_counts_unterminated_last_line(): void
    {
        file_put_contents($this->logFile('queue_00.log'), "a\nb\nc");

        $this->assertSame("b\nc", $this->logs->tail('bob', $this->worker(), 0, 'out', 2));
    }

    public function test_tail_on_missing_file_is_empty(): void
    {
        $this->assertSame('', $this->logs->tail('bob', $this->worker(), 0, 'out', 10));
    }

    public function test_tail_on_large_file_reads_little(): void
    {
        $path = $this->logFile('queue_00.log');
        $size = 50 * 1024 * 1024;
        $h = fopen($path, 'c');
        ftruncate($h, $size);
        fseek($h, $size - 28);
        fwrite($h, "line-1\nline-2\nline-3\nline-4\n");
        fclose($h);
        clearstatcache();

        $start = microtime(true);
        $out = $this->logs->tail('bob', $this->worker(), 0, 'out', 2);
        $elapsed = microtime(true) - $start;

        $this->assertStringEndsWith("line-3\nline-4\n", $out);
        $this->assertLessThan(0.5, $elapsed);
    }

    public function test_tail_spans_multiple_blocks(): void
    {
        $content = '';
        for ($i = 1; $i <= 20000; $i++) {
            $content .= "line $i\n";
        }
        file_put_contents($this->logFile('queue_00.log'), $content);

        $out = $this->logs->tail('bob', $this->worker(), 0, 'out', 2000);

        $this->assertSame(2000, substr_count($out, "\n"));
        $this->assertStringStartsWith("line 18001\n", $out);
        $this->assertStringEndsWith("line 20000\n", $out);
    }

    public function test_tail_caps_bytes_when_file_has_no_newlines(): void
    {
        file_put_contents($this->logFile('queue_00.log'), str_repeat('x', 5 * 1024 * 1024));

        $start = microtime(true);
        $out = $this->logs->tail('bob', $this->worker(), 0, 'out', 100);
        $elapsed = microtime(true) - $start;

        $this->assertLessThanOrEqual(1048576, strlen($out));
        $this->assertGreaterThan(0, strlen($out));
        $this->assertLessThan(0.5, $elapsed);
    }

    public function test_process_index_out_of_range_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->logs->tail('bob', $this->worker(2), 2, 'out', 10);
    }

    public function test_invalid_stream_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->logs->tail('bob', $this->worker(1, true), 0, 'err', 10);
    }

    public function test_unknown_stream_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->logs->tail('bob', $this->worker(1, false), 0, '../x', 10);
    }

    public function test_symlink_is_refused(): void
    {
        file_put_contents($this->root . '/secret', "secret\n");
        symlink($this->root . '/secret', $this->logFile('queue_00.log'));

        $this->expectException(RuntimeException::class);
        $this->logs->tail('bob', $this->worker(), 0, 'out', 10);
    }

    public function test_clear_truncates_and_drops_backups(): void
    {
        $w = $this->worker(1, false);
        file_put_contents($this->logFile('queue_00.log'), "x\n");
        file_put_contents($this->logFile('queue_00.err.log'), "y\n");
        file_put_contents($this->logFile('queue_00.log.1'), "old\n");
        file_put_contents($this->logFile('queue_00.err.log.3'), "old\n");
        $inode = fileinode($this->logFile('queue_00.log'));

        $this->logs->clear('bob', $w);
        clearstatcache();

        $this->assertSame(0, filesize($this->logFile('queue_00.log')));
        $this->assertSame(0, filesize($this->logFile('queue_00.err.log')));
        $this->assertSame($inode, fileinode($this->logFile('queue_00.log')));
        $this->assertFileDoesNotExist($this->logFile('queue_00.log.1'));
        $this->assertFileDoesNotExist($this->logFile('queue_00.err.log.3'));
    }

    public function test_clear_does_not_create_missing_files(): void
    {
        $this->logs->clear('bob', $this->worker());

        $this->assertFileDoesNotExist($this->logFile('queue_00.log'));
    }
}
