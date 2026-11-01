<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\ExpertFormat;
use WorkerSupervisor\Worker;
use PHPUnit\Framework\TestCase;

final class ExpertFormatTest extends TestCase
{
    private function base(): Worker
    {
        return Worker::fromArray([
            'name' => 'queue',
            'template' => 'laravel-queue',
            'folder' => 'app',
            'command' => 'php artisan queue:work',
            'processes' => 2,
            'autorestart' => 'unexpected',
            'desired' => 'stopped',
            'admin_stopped' => true,
        ]);
    }

    /** @param \WorkerSupervisor\Issue[] $issues */
    private function codes(array $issues): array
    {
        return array_map(static fn ($i) => $i->code, $issues);
    }

    public function test_render_then_parse_round_trips(): void
    {
        $w = $this->base();
        $text = ExpertFormat::render($w);

        $this->assertStringStartsWith("[program:queue]\ncommand=php artisan queue:work\ndirectory=app\nnumprocs=2\n", $text);
        $r = ExpertFormat::parse($text, $w);

        $this->assertSame([], $r->issues);
        $this->assertEquals($w, $r->worker);
    }

    public function test_crlf_is_accepted(): void
    {
        $text = str_replace("\n", "\r\n", "; note\n\n  [program:queue]  \n # c\n numprocs = 4 \ncommand=run it\n");
        $r = ExpertFormat::parse($text, $this->base());

        $this->assertSame([], $r->issues);
        $this->assertSame(4, $r->worker->processes);
        $this->assertSame('run it', $r->worker->command);
        $this->assertSame('app', $r->worker->folder);
        $this->assertSame('laravel-queue', $r->worker->template);
        $this->assertSame('stopped', $r->worker->desired);
    }

    public function test_forbidden_user_key_reports_line(): void
    {
        $text = ExpertFormat::render($this->base()) . "user=root\n";
        $r = ExpertFormat::parse($text, $this->base());

        $this->assertNull($r->worker);
        $this->assertSame(['expert_key_forbidden'], $this->codes($r->issues));
        $this->assertSame(11, $r->issues[0]->line);
        $this->assertSame('user', $r->issues[0]->params['key']);
        $this->assertSame('expert', $r->issues[0]->field);

        $text = "[program:q]\ncommand=x\ndirectory=a\nnumprocs=1\nautostart=true\nautorestart=true\nstartsecs=1\nstartretries=1\nuser=root\n";
        $this->assertSame(9, ExpertFormat::parse($text, $this->base())->issues[0]->line);
    }

    public function test_logfile_keys_are_forbidden(): void
    {
        $r = ExpertFormat::parse("[program:q]\nstdout_logfile=/x\nstderr_logfile_maxbytes=1\n", $this->base());

        $this->assertSame(['expert_key_forbidden', 'expert_key_forbidden'], $this->codes($r->issues));
    }

    public function test_duplicate_key(): void
    {
        $r = ExpertFormat::parse("[program:q]\nnumprocs=1\nnumprocs=2\n", $this->base());

        $this->assertNull($r->worker);
        $this->assertSame(['expert_key_duplicate'], $this->codes($r->issues));
        $this->assertSame(3, $r->issues[0]->line);
    }

    public function test_missing_header(): void
    {
        $r = ExpertFormat::parse("numprocs=1\ncommand=x\n", $this->base());

        $this->assertNull($r->worker);
        $this->assertSame(['expert_no_header'], $this->codes($r->issues));
        $this->assertSame(1, $r->issues[0]->line);
        $this->assertSame(['expert_no_header'], $this->codes(ExpertFormat::parse('', $this->base())->issues));
    }

    public function test_second_section(): void
    {
        $r = ExpertFormat::parse("[program:a]\ncommand=x\n[program:b]\n", $this->base());

        $this->assertSame(['expert_extra_section'], $this->codes($r->issues));
        $this->assertSame(3, $r->issues[0]->line);
    }

    public function test_line_without_equals_is_syntax_error(): void
    {
        $r = ExpertFormat::parse("[program:a]\ncommand\n", $this->base());

        $this->assertSame(['expert_syntax'], $this->codes($r->issues));
        $this->assertSame(2, $r->issues[0]->line);
    }

    public function test_non_integer_numprocs(): void
    {
        $r = ExpertFormat::parse("[program:a]\nnumprocs=two\nstartsecs=-1\nautostart=yes\nautorestart=sometimes\n", $this->base());

        $this->assertSame(array_fill(0, 4, 'expert_value_invalid'), $this->codes($r->issues));
        $this->assertSame('numprocs', $r->issues[0]->params['key']);
    }

    public function test_unknown_key(): void
    {
        $r = ExpertFormat::parse("[program:a]\nenvironment=A=1\n", $this->base());

        $this->assertSame(['expert_key_unknown'], $this->codes($r->issues));
        $this->assertSame('environment', $r->issues[0]->params['key']);
    }

    public function test_never_throws_on_garbage(): void
    {
        mt_srand(42);
        for ($i = 0; $i < 200; $i++) {
            $text = random_bytes(mt_rand(0, 200));
            $r = ExpertFormat::parse($text, $this->base());
            $this->assertTrue($r->worker !== null || $r->issues !== []);
        }
    }
}
