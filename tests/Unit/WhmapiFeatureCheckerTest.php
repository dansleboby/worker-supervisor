<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\Supervisor\Runner;
use WorkerSupervisor\WhmapiFeatureChecker;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WhmapiFeatureCheckerTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/whmapi1/';

    /** @param list<array{0: int, 1: string}> $replies */
    private function runner(array $replies): Runner
    {
        return new class ($replies) implements Runner {
            /** @var list<list<string>> */
            public array $argv = [];

            public function __construct(private array $replies)
            {
            }

            public function run(array $argv): array
            {
                $this->argv[] = $argv;

                return array_shift($this->replies) ?? [1, ''];
            }

            public function detach(array $argv): void
            {
            }
        };
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . $name);
    }

    public function test_feature_registered_reads_get_feature_names(): void
    {
        $runner = $this->runner([[0, self::fixture('get_feature_names.json')]]);

        $this->assertTrue((new WhmapiFeatureChecker($runner))->featureRegistered());
        $this->assertSame([['/usr/local/cpanel/bin/whmapi1', '--output=json', 'get_feature_names']], $runner->argv);
    }

    public function test_feature_not_registered(): void
    {
        $json = str_replace('"worker_supervisor"', '"something_else"', self::fixture('get_feature_names.json'));

        $this->assertFalse((new WhmapiFeatureChecker($this->runner([[0, $json]])))->featureRegistered());
    }

    public function test_account_override_wins_over_feature_list(): void
    {
        $runner = $this->runner([[0, self::fixture('get_users_features_settings.json')]]);

        $result = (new WhmapiFeatureChecker($runner))->usersWithFeature(['wshell', 'wnoshell']);

        $this->assertSame(['wshell' => true, 'wnoshell' => false, 'nosuchuser' => true], $result);
        $this->assertSame(
            ['/usr/local/cpanel/bin/whmapi1', '--output=json', 'get_users_features_settings', 'feature=worker_supervisor', 'user-1=wshell', 'user-2=wnoshell'],
            $runner->argv[0],
        );
    }

    public function test_rows_without_a_setting_are_left_out(): void
    {
        $json = '{"metadata":{"result":1},"data":{"users_features_settings":['
            . '{"user":"bob","feature":"worker_supervisor","feature_list_setting":null,"cpuser_setting":null},'
            . '{"user":"eve","feature":"other","feature_list_setting":"1","cpuser_setting":null}]}}';

        $this->assertSame([], (new WhmapiFeatureChecker($this->runner([[0, $json]])))->usersWithFeature(['bob']));
    }

    public function test_no_users_needs_no_call(): void
    {
        $runner = $this->runner([]);

        $this->assertSame([], (new WhmapiFeatureChecker($runner))->usersWithFeature([]));
        $this->assertSame([], $runner->argv);
    }

    public function test_api_failure_throws(): void
    {
        $checker = new WhmapiFeatureChecker($this->runner([[0, self::fixture('get_users_features_settings-unknown-feature.json')]]));

        $this->expectException(RuntimeException::class);
        $checker->usersWithFeature(['bob']);
    }

    public function test_unparsable_output_throws(): void
    {
        $this->expectException(RuntimeException::class);
        (new WhmapiFeatureChecker($this->runner([[255, 'Permission denied']])))->featureRegistered();
    }
}
