<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\Version;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'wsv-version-');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function test_reads_the_version_without_surrounding_whitespace(): void
    {
        file_put_contents($this->file, "1.2.3\n");

        $this->assertSame('1.2.3', Version::read($this->file));
    }

    public function test_accepts_a_prerelease_suffix(): void
    {
        file_put_contents($this->file, '1.1.0-rc.1');

        $this->assertSame('1.1.0-rc.1', Version::read($this->file));
    }

    public function test_missing_file_is_unknown(): void
    {
        unlink($this->file);

        $this->assertSame(Version::UNKNOWN, Version::read($this->file));
    }

    public function test_text_that_is_not_a_semantic_version_is_unknown(): void
    {
        foreach (['', 'v1.0.0', '1.0', '1.0.0 beta', "1.0.0\n2.0.0", '<b>1.0.0</b>'] as $text) {
            file_put_contents($this->file, $text);

            $this->assertSame(Version::UNKNOWN, Version::read($this->file), var_export($text, true));
        }
    }

    public function test_repository_version_file_names_a_release(): void
    {
        $this->assertNotSame(Version::UNKNOWN, Version::read(dirname(__DIR__, 2) . '/VERSION'));
    }
}
