<?php

declare(strict_types=1);

namespace WorkerSupervisor;

use InvalidArgumentException;

/**
 * One supervisord program definition owned by a cPanel account.
 *
 * fromArray/toArray use the snake_case keys stored in the account JSON. Types are
 * cast but not range-checked; validation happens before a worker is written.
 */
final class Worker
{
    public function __construct(
        public readonly string $name,
        public readonly string $template,
        public readonly string $folder,
        public readonly string $command,
        public readonly int $processes = 1,
        public readonly bool $autostart = true,
        public readonly string $autorestart = 'true',
        public readonly int $startsecs = 10,
        public readonly int $startretries = 30,
        public readonly int $stopwaitsecs = 3600,
        public readonly bool $redirectStderr = true,
        public readonly string $desired = 'running',
        public readonly bool $adminStopped = false,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            name: self::required($a, 'name'),
            template: self::required($a, 'template'),
            folder: self::required($a, 'folder'),
            command: self::required($a, 'command'),
            processes: (int) ($a['processes'] ?? 1),
            autostart: self::toBool($a['autostart'] ?? true),
            autorestart: (string) ($a['autorestart'] ?? 'true'),
            startsecs: (int) ($a['startsecs'] ?? 10),
            startretries: (int) ($a['startretries'] ?? 30),
            stopwaitsecs: (int) ($a['stopwaitsecs'] ?? 3600),
            redirectStderr: self::toBool($a['redirect_stderr'] ?? true),
            desired: (string) ($a['desired'] ?? 'running'),
            adminStopped: self::toBool($a['admin_stopped'] ?? false),
        );
    }

    /** @param array<string, mixed> $a */
    private static function required(array $a, string $key): string
    {
        if (!array_key_exists($key, $a)) {
            throw new InvalidArgumentException("Worker is missing required key '$key'.");
        }

        return (string) $a[$key];
    }

    /**
     * Accepts real booleans and the string forms that UAPI and HTML forms send.
     * Anything else is rejected rather than guessed.
     */
    private static function toBool(mixed $v): bool
    {
        if (is_bool($v) || is_int($v)) {
            return (bool) $v;
        }

        $parsed = is_string($v) ? filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        if ($parsed === null) {
            throw new InvalidArgumentException('Expected a boolean, got ' . var_export($v, true) . '.');
        }

        return $parsed;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'template' => $this->template,
            'folder' => $this->folder,
            'command' => $this->command,
            'processes' => $this->processes,
            'autostart' => $this->autostart,
            'autorestart' => $this->autorestart,
            'startsecs' => $this->startsecs,
            'startretries' => $this->startretries,
            'stopwaitsecs' => $this->stopwaitsecs,
            'redirect_stderr' => $this->redirectStderr,
            'desired' => $this->desired,
            'admin_stopped' => $this->adminStopped,
        ];
    }

    /**
     * @param array<string, mixed> $changes snake_case keys, as accepted by fromArray
     */
    public function with(array $changes): self
    {
        return self::fromArray(array_merge($this->toArray(), $changes));
    }
}
