<?php

declare(strict_types=1);

namespace WorkerSupervisor;

use RuntimeException;

/** An expected refusal reported to the client as a code (see CODES), never a crash. */
final class ServiceError extends RuntimeException
{
    public const CODES = [
        'account_unknown',
        // The stored workers belong to an earlier cPanel account of the same name.
        'account_mismatch',
        'worker_unknown',
        'validation_failed',
        'admin_stopped',
        'suspended',
        'feature_off',
        'bad_request',
        'internal',
    ];

    /**
     * @param array<string, scalar> $params
     * @param Issue[] $issues set for validation_failed
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly array $params = [],
        public readonly array $issues = [],
    ) {
        parent::__construct($errorCode);
    }
}
