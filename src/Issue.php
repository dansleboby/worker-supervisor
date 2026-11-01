<?php

declare(strict_types=1);

namespace WorkerSupervisor;

final class Issue
{
    public const CODES = [
        'name_invalid',
        'name_taken',
        'template_unknown',
        'template_not_allowed',
        'folder_invalid',
        'folder_outside_home',
        'folder_missing',
        'required_path_missing',
        'command_empty',
        'command_invalid',
        'processes_range',
        'processes_fixed',
        'workers_limit',
        'stopwait_range',
        'startsecs_range',
        'startretries_range',
        'autorestart_invalid',
        'memory_over_limit',
        'laravel_needs_autorestart',
        'php_version',
        'expert_no_header',
        'expert_extra_section',
        'expert_syntax',
        'expert_key_forbidden',
        'expert_key_unknown',
        'expert_key_duplicate',
        'expert_value_invalid',
        'value_invalid',
        'input_invalid',
    ];

    /** @param array<string, scalar> $params */
    public function __construct(
        public readonly string $level,
        public readonly string $field,
        public readonly string $code,
        public readonly array $params = [],
        public readonly ?int $line = null,
    ) {
    }

    /** @return array{level: string, field: string, code: string, params: array<string, scalar>, line: ?int} */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'field' => $this->field,
            'code' => $this->code,
            'params' => $this->params,
            'line' => $this->line,
        ];
    }
}
