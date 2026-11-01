<?php

declare(strict_types=1);

namespace WorkerSupervisor;

/** Whether cPanel's Feature Manager grants the worker_supervisor feature. */
interface FeatureChecker
{
    /**
     * Callers change nothing when this is false: cPanel reports an unregistered feature as granted.
     *
     * @throws \RuntimeException when cPanel cannot be asked
     */
    public function featureRegistered(): bool;

    /**
     * @param string[] $users
     * @return array<string, bool> keyed by user; a user cPanel gave no answer for is left out
     * @throws \RuntimeException when cPanel cannot be asked
     */
    public function usersWithFeature(array $users): array;
}
