<?php

declare(strict_types=1);

namespace WorkerSupervisor;

final class Limits
{
    /** @param string[] $templates allowed template ids */
    public function __construct(
        public readonly int $maxProcesses,
        public readonly int $maxWorkers,
        public readonly int $maxStopwaitsecs,
        public readonly array $templates,
    ) {
    }

    public static function serverDefaults(): self
    {
        return new self(10, 5, 3600, array_map(static fn (Template $t) => $t->id, Templates::all()));
    }

    /** @return array{max_processes: int, max_workers: int, max_stopwaitsecs: int, templates: string[]} */
    public function toArray(): array
    {
        return [
            'max_processes' => $this->maxProcesses,
            'max_workers' => $this->maxWorkers,
            'max_stopwaitsecs' => $this->maxStopwaitsecs,
            'templates' => $this->templates,
        ];
    }

    /** Missing or malformed fields fall back to the server defaults. */
    public static function fromArray(array $a): self
    {
        $d = self::serverDefaults();
        return new self(
            is_numeric($a['max_processes'] ?? null) ? (int) $a['max_processes'] : $d->maxProcesses,
            is_numeric($a['max_workers'] ?? null) ? (int) $a['max_workers'] : $d->maxWorkers,
            is_numeric($a['max_stopwaitsecs'] ?? null) ? (int) $a['max_stopwaitsecs'] : $d->maxStopwaitsecs,
            is_array($a['templates'] ?? null) ? array_values(array_map('strval', $a['templates'])) : $d->templates,
        );
    }
}
