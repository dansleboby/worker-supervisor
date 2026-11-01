<?php

declare(strict_types=1);

namespace WorkerSupervisor;

final class ParseResult
{
    /** @param Issue[] $issues */
    public function __construct(
        public readonly ?Worker $worker,
        public readonly array $issues,
    ) {
    }
}
