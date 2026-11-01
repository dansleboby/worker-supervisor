<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\FeatureChecker;
use RuntimeException;

final class FakeFeatures implements FeatureChecker
{
    public bool $registered = true;
    public bool $fail = false;
    /** @var array<string, bool> */
    public array $enabled = [];
    public int $calls = 0;

    public function featureRegistered(): bool
    {
        $this->calls++;
        if ($this->fail) {
            throw new RuntimeException('whmapi1 failed');
        }

        return $this->registered;
    }

    public function usersWithFeature(array $users): array
    {
        $this->calls++;

        return array_intersect_key($this->enabled, array_flip($users));
    }
}
