<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\Fs;
use PHPUnit\Framework\TestCase;

final class FsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wsv_fs_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/tree/sub', 0777, true);
        mkdir($this->root . '/outside', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function test_remove_tree_unlinks_symlinks_without_following_them(): void
    {
        file_put_contents($this->root . '/tree/a.log', 'x');
        file_put_contents($this->root . '/tree/sub/b.log', 'x');
        file_put_contents($this->root . '/outside/keep', 'x');
        symlink($this->root . '/outside', $this->root . '/tree/link');

        Fs::removeTree($this->root . '/tree');

        $this->assertDirectoryDoesNotExist($this->root . '/tree');
        $this->assertFileExists($this->root . '/outside/keep');
    }

    public function test_remove_tree_of_missing_path_is_a_no_op(): void
    {
        Fs::removeTree($this->root . '/missing');

        $this->assertDirectoryExists($this->root . '/outside');
    }
}
