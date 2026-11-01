<?php

declare(strict_types=1);

namespace WorkerSupervisor;

use WorkerSupervisor\Supervisor\Runner;
use RuntimeException;

final class WhmapiFeatureChecker implements FeatureChecker
{
    private const WHMAPI = '/usr/local/cpanel/bin/whmapi1';
    private const FEATURE = 'worker_supervisor';

    public function __construct(private readonly Runner $runner)
    {
    }

    public function featureRegistered(): bool
    {
        foreach ((array) ($this->call(['get_feature_names'])['feature'] ?? []) as $f) {
            if (is_array($f) && ($f['id'] ?? null) === self::FEATURE) {
                return true;
            }
        }

        return false;
    }

    public function usersWithFeature(array $users): array
    {
        if ($users === []) {
            return [];
        }

        $args = ['get_users_features_settings', 'feature=' . self::FEATURE];
        foreach (array_values($users) as $i => $user) {
            $args[] = 'user-' . ($i + 1) . '=' . $user;
        }

        $result = [];
        foreach ((array) ($this->call($args)['users_features_settings'] ?? []) as $row) {
            if (!is_array($row) || ($row['feature'] ?? null) !== self::FEATURE || !is_string($row['user'] ?? null)) {
                continue;
            }
            // A per-account override (cpuser_setting) is null unless set, and then beats the feature list.
            $setting = $row['cpuser_setting'] ?? $row['feature_list_setting'] ?? null;
            if ($setting !== null) {
                $result[$row['user']] = (string) $setting === '1';
            }
        }

        return $result;
    }

    /**
     * @param list<string> $args
     * @return array<string, mixed> the reply's data
     */
    private function call(array $args): array
    {
        [$code, $out] = $this->runner->run([self::WHMAPI, '--output=json', ...$args]);
        $reply = json_decode($out, true);
        if ($code !== 0 || !is_array($reply) || ($reply['metadata']['result'] ?? null) !== 1) {
            $reason = is_array($reply) ? (string) ($reply['metadata']['reason'] ?? '') : "exit $code";
            throw new RuntimeException("whmapi1 {$args[0]} failed: $reason");
        }

        return is_array($reply['data'] ?? null) ? $reply['data'] : [];
    }
}
