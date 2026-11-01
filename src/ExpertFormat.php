<?php

declare(strict_types=1);

namespace WorkerSupervisor;

use InvalidArgumentException;

/**
 * Expert mode: a worker as a plain supervisor [program:x] block.
 *
 * The text is untrusted client input. parse() never throws; every problem is a
 * line-numbered Issue. Hand-rolled on purpose: parse_ini_string interprets values.
 */
final class ExpertFormat
{
    /** text key => [Worker array key, kind] */
    private const KEYS = [
        'command' => ['command', 'string'],
        'directory' => ['folder', 'string'],
        'numprocs' => ['processes', 'int'],
        'autostart' => ['autostart', 'bool'],
        'autorestart' => ['autorestart', 'autorestart'],
        'startsecs' => ['startsecs', 'int'],
        'startretries' => ['startretries', 'int'],
        'stopwaitsecs' => ['stopwaitsecs', 'int'],
        'redirect_stderr' => ['redirect_stderr', 'bool'],
    ];

    /** Owned by the plugin; a client must not set them. */
    private const FORBIDDEN = ['user', 'process_name', 'stopasgroup', 'killasgroup', 'stopsignal'];

    public static function render(Worker $w): string
    {
        return implode("\n", [
            '[program:' . $w->name . ']',
            'command=' . $w->command,
            'directory=' . $w->folder,
            'numprocs=' . $w->processes,
            'autostart=' . ($w->autostart ? 'true' : 'false'),
            'autorestart=' . $w->autorestart,
            'startsecs=' . $w->startsecs,
            'startretries=' . $w->startretries,
            'stopwaitsecs=' . $w->stopwaitsecs,
            'redirect_stderr=' . ($w->redirectStderr ? 'true' : 'false'),
        ]) . "\n";
    }

    public static function parse(string $text, Worker $base): ParseResult
    {
        $issues = [];
        $changes = [];
        $seen = [];
        $name = null;
        $noHeaderReported = false;

        $lines = preg_split('/\r\n|\n|\r/', $text);
        foreach ($lines === false ? [] : $lines as $i => $raw) {
            $n = $i + 1;
            $line = trim($raw);
            if ($line === '' || $line[0] === ';' || $line[0] === '#') {
                continue;
            }

            if ($line[0] === '[') {
                if ($name !== null) {
                    $issues[] = self::issue('expert_extra_section', $n);
                } elseif (preg_match('/^\[program:(.*)\]$/', $line, $m) === 1 && trim($m[1]) !== '') {
                    $name = trim($m[1]);
                } elseif (!$noHeaderReported) {
                    $issues[] = self::issue('expert_no_header', $n);
                    $noHeaderReported = true;
                }
                continue;
            }

            if ($name === null && !$noHeaderReported) {
                $issues[] = self::issue('expert_no_header', $n);
                $noHeaderReported = true;
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                $issues[] = self::issue('expert_syntax', $n);
                continue;
            }

            $key = strtolower(trim(substr($line, 0, $eq)));
            $value = trim(substr($line, $eq + 1));

            if (
                in_array($key, self::FORBIDDEN, true)
                || str_starts_with($key, 'stdout_logfile')
                || str_starts_with($key, 'stderr_logfile')
            ) {
                $issues[] = self::issue('expert_key_forbidden', $n, $key);
                continue;
            }
            if (!isset(self::KEYS[$key])) {
                $issues[] = self::issue('expert_key_unknown', $n, $key);
                continue;
            }
            if (isset($seen[$key])) {
                $issues[] = self::issue('expert_key_duplicate', $n, $key);
                continue;
            }
            $seen[$key] = true;

            [$field, $kind] = self::KEYS[$key];
            $parsed = self::convert($kind, $value);
            if ($parsed === null) {
                $issues[] = self::issue('expert_value_invalid', $n, $key);
                continue;
            }
            $changes[$field] = $parsed;
        }

        if ($name === null && !$noHeaderReported) {
            $issues[] = self::issue('expert_no_header', 1);
        }
        if ($issues !== []) {
            return new ParseResult(null, $issues);
        }

        try {
            $worker = $base->with(['name' => $name] + $changes);
        } catch (InvalidArgumentException) {
            return new ParseResult(null, [self::issue('expert_value_invalid', 1)]);
        }

        return new ParseResult($worker, []);
    }

    private static function convert(string $kind, string $value): string|int|bool|null
    {
        switch ($kind) {
            case 'int':
                return preg_match('/^\d+$/', $value) === 1 ? (int) $value : null;
            case 'bool':
                $v = strtolower($value);
                return $v === 'true' ? true : ($v === 'false' ? false : null);
            case 'autorestart':
                $v = strtolower($value);
                return in_array($v, ['true', 'unexpected', 'false'], true) ? $v : null;
            default:
                return $value;
        }
    }

    private static function issue(string $code, int $line, ?string $key = null): Issue
    {
        return new Issue('error', 'expert', $code, $key === null ? [] : ['key' => $key], $line);
    }
}
