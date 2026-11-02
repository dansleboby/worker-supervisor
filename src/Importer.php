<?php

declare(strict_types=1);

namespace WorkerSupervisor;

use WorkerSupervisor\Supervisor\SupervisorClient;
use RuntimeException;

/**
 * Turns a hand-written [program:x] block in /etc/supervisord.d/ into a managed worker.
 *
 * Root-only. File names arrive from a browser form, so they are only ever resolved against the
 * directory listing, never joined into a path. The parser is line-based like ExpertFormat:
 * parse_ini_string would interpret values such as %(program_name)s.
 *
 * Change codes returned by propose(): wrapper_replaced, directory_from_cd, logs_moved,
 * user_key_dropped, "key_dropped:<key>", and "always_on:<key>" for a stopasgroup or killasgroup
 * the file turns off.
 */
final class Importer
{
    private const KEPT = ['command', 'directory', 'numprocs', 'autostart', 'autorestart', 'startsecs', 'startretries', 'stopwaitsecs', 'redirect_stderr'];
    /** Set to true on every generated program (IniRenderer). */
    private const ALWAYS_ON = ['stopasgroup', 'killasgroup'];
    /** Form fields a file can leave out; propose() fills them with the Worker defaults. */
    private const DEFAULTED = ['processes', 'autostart', 'autorestart', 'startsecs', 'startretries', 'stopwaitsecs', 'redirect_stderr'];

    /** @var (callable(): int)|null */
    private $now;

    /** @var callable(string, string): bool */
    private $rename;

    /**
     * @param (callable(): int)|null $now
     * @param (callable(string, string): bool)|null $rename seam for the final file swap
     */
    public function __construct(
        private readonly Paths $p,
        private readonly Service $svc,
        private readonly SupervisorClient $sv,
        private readonly IniRenderer $ini,
        ?callable $now = null,
        ?callable $rename = null,
    ) {
        $this->now = $now;
        $this->rename = $rename ?? static fn (string $from, string $to): bool => @rename($from, $to);
    }

    /**
     * @return list<array{file: string, programs: list<array{name: string, suggested_user: string, importable: bool, reason: ?string}>}>
     *         files with at least one program, sorted by name; suggested_user is '' when unknown or root;
     *         reason is set when importable is false
     */
    public function candidates(): array
    {
        $out = [];
        $files = glob($this->p->iniDir() . '/*.ini') ?: [];
        sort($files);
        foreach ($files as $path) {
            $base = basename($path);
            if (str_starts_with($base, 'wsv_') || !is_file($path)) {
                continue;
            }
            $programs = [];
            foreach (self::sections((string) file_get_contents($path)) as $s) {
                if ($s['program'] !== null) {
                    $ok = $this->sv->acceptsUnmanaged($s['program']);
                    $programs[] = [
                        'name' => $s['program'],
                        'suggested_user' => self::suggestedUser($s['kv']),
                        'importable' => $ok,
                        'reason' => $ok ? null : 'program_name_unsupported',
                    ];
                }
            }
            if ($programs !== []) {
                $out[] = ['file' => $base, 'programs' => $programs];
            }
        }

        return $out;
    }

    /**
     * @return array{worker: array<string, mixed>, changes: list<string>, issues: list<Issue>} changes
     *         starts with the account warnings user_mismatch:<expected>:<chosen> and ran_as_root; issues:
     *         what saving this worker for $user would be refused for, warnings included
     * @throws ServiceError bad_request, account_unknown
     */
    public function propose(string $file, string $program, string $user): array
    {
        [, $section] = $this->locate($file, $program);
        [$worker, $changes] = self::convert($program, $section['kv']);
        array_unshift($changes, ...self::accountWarnings($section['kv'], $user));

        $issues = self::issues($this->svc->validate($user, $worker, 'form', null)['issues']);

        return ['worker' => $worker, 'changes' => $changes, 'issues' => $issues];
    }

    /**
     * Everything that can be refused or can fail is done before the first irreversible step: the
     * name checks, the validation, the backup and the rewritten file all precede the save, and the
     * file swap follows it with a rollback of the save. Only then is the old program removed.
     *
     * @param array<string, mixed> $worker form values, as edited in the preview
     * @throws ServiceError bad_request (reason in params), account_unknown, validation_failed
     * @throws RuntimeException a file step failed; nothing is left changed
     */
    public function apply(string $file, string $program, string $user, array $worker): void
    {
        [$path, $section, $sections, $lines] = $this->locate($file, $program);

        if (!$this->sv->acceptsUnmanaged($program)) {
            throw new ServiceError('bad_request', ['reason' => 'program_name_unsupported']);
        }
        if ($program === $this->ini->programName($user, (string) ($worker['name'] ?? ''))) {
            throw new ServiceError('bad_request', ['reason' => 'program_name_collision']);
        }
        $checked = $this->svc->validate($user, $worker, 'form', null);
        $issues = self::issues($checked['issues']);
        if ($checked['worker'] === null || array_filter($issues, static fn (Issue $i) => $i->level === 'error') !== []) {
            throw new ServiceError('validation_failed', [], $issues);
        }

        $remaining = array_filter($sections, static fn (array $s) => $s['start'] !== $section['start']);
        $backup = $this->backup($path);
        $tmp = null;
        try {
            if ($remaining !== []) {
                $kept = array_merge(array_slice($lines, 0, $section['start']), array_slice($lines, $section['end']));
                $tmp = self::prepare($path, implode("\n", $kept));
            }
            $saved = $this->svc->save($user, $worker, 'form', null);
        } catch (\Throwable $e) {
            self::discard($backup, $tmp);
            throw $e;
        }

        $swapped = $tmp === null ? @unlink($path) : ($this->rename)($tmp, $path);
        if (!$swapped) {
            try {
                $this->svc->delete($user, (string) $saved['worker']['name']);
            } catch (\Throwable) {
                // The original error is the one to report; the stray worker shows in the overview.
            }
            self::discard($backup, $tmp);
            throw new RuntimeException("Cannot update $path");
        }
        $this->sv->removeUnmanaged($program);
    }

    /**
     * @param list<array{level: string, field: string, code: string, params: array<string, scalar>, line: ?int}> $rows
     * @return list<Issue>
     */
    private static function issues(array $rows): array
    {
        return array_map(static fn (array $i) => new Issue($i['level'], $i['field'], $i['code'], $i['params'], $i['line']), $rows);
    }

    /**
     * @param array<string, string> $kv
     * @return list<string>
     */
    private static function accountWarnings(array $kv, string $chosen): array
    {
        $wrapped = self::unwrap($kv['command'] ?? '');
        $ranAs = $wrapped['user'] ?? ($kv['user'] ?? 'root');
        if ($wrapped === null && $ranAs === 'root') {
            return ['ran_as_root'];
        }

        return $ranAs === $chosen ? [] : ['user_mismatch:' . $ranAs . ':' . $chosen];
    }

    /**
     * @return array{0: string, 1: array, 2: list<array>, 3: list<string>} path, section, all sections, lines
     * @throws ServiceError bad_request
     */
    private function locate(string $file, string $program): array
    {
        $known = false;
        foreach ($this->candidates() as $c) {
            $known = $known || $c['file'] === $file;
        }
        if (!$known) {
            throw new ServiceError('bad_request');
        }

        $path = $this->p->iniDir() . '/' . $file;
        $text = (string) file_get_contents($path);
        $sections = self::sections($text);
        foreach ($sections as $s) {
            if ($s['program'] === $program) {
                return [$path, $s, $sections, self::lines($text)];
            }
        }

        throw new ServiceError('bad_request');
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        return preg_split('/\r\n|\n|\r/', $text) ?: [];
    }

    /**
     * Mirrors supervisor's own parser: a header is a non-indented line starting with [name] (a trailing
     * comment is fine), and an indented line continues the previous value.
     *
     * @return list<array{program: ?string, start: int, end: int, kv: array<string, string>}>
     *         line range [start, end) of each section; the first occurrence of a key wins
     */
    private static function sections(string $text): array
    {
        $sections = [];
        $lines = self::lines($text);
        $last = null;
        foreach ($lines as $i => $raw) {
            $line = trim($raw);
            $indented = $raw !== '' && ($raw[0] === ' ' || $raw[0] === "\t");
            if (!$indented && preg_match('/^\[([^\]]+)\]/', $raw, $m) === 1) {
                $program = preg_match('/^program:(.+)$/', $m[1], $n) === 1 ? trim($n[1]) : null;
                $sections[] = ['program' => $program === '' ? null : $program, 'start' => $i, 'end' => count($lines), 'kv' => []];
                $last = null;
                continue;
            }
            $current = count($sections) - 1;
            if ($current < 0 || $line === '') {
                continue;
            }
            if ($indented) {
                if ($last !== null) {
                    $sections[$current]['kv'][$last] .= "\n" . $line;
                }
                continue;
            }
            if ($line[0] === ';' || $line[0] === '#') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq !== false) {
                $key = strtolower(trim(substr($line, 0, $eq)));
                $last = isset($sections[$current]['kv'][$key]) ? null : $key;
                $sections[$current]['kv'][$key] ??= trim(substr($line, $eq + 1));
            }
        }
        for ($k = 0; $k < count($sections) - 1; $k++) {
            $sections[$k]['end'] = $sections[$k + 1]['start'];
        }

        return $sections;
    }

    /** @param array<string, string> $kv */
    private static function suggestedUser(array $kv): string
    {
        $wrapped = self::unwrap($kv['command'] ?? '');
        $user = $wrapped['user'] ?? ($kv['user'] ?? '');

        return $user === 'root' ? '' : $user;
    }

    /** @return ?array{user: string, command: string} */
    private static function unwrap(string $command): ?array
    {
        $re = '~^(?:/usr/bin/|/bin/|/usr/sbin/|/sbin/)?(?:su|runuser)\s+(?:-s\s+\S+\s+)?(?:-l?\s+)?([a-z_][a-z0-9_-]*)\s+(?:-s\s+\S+\s+)?-c\s+(["\'])(.*)\2\s*$~s';
        if (preg_match($re, trim($command), $m) !== 1) {
            return null;
        }
        $inner = $m[2] === '"' ? str_replace(['\\"', '\\\\'], ['"', '\\'], $m[3]) : $m[3];

        return ['user' => $m[1], 'command' => trim($inner)];
    }

    /**
     * @param array<string, string> $kv
     * @return array{0: array<string, mixed>, 1: list<string>} Worker form values, change codes
     */
    private static function convert(string $program, array $kv): array
    {
        $changes = [];
        $command = $kv['command'] ?? '';
        $folder = rtrim($kv['directory'] ?? '', '/');
        $cdUsed = false;

        $wrapped = self::unwrap($command);
        if ($wrapped !== null) {
            $changes[] = 'wrapper_replaced';
            $command = $wrapped['command'];
            if (preg_match('~^cd\s+("[^"]+"|\'[^\']+\'|\S+)\s*&&\s*(.*)$~s', $command, $m) === 1) {
                $cd = trim($m[1], '"\'');
                $command = $m[2];
                $folder = rtrim($cd, '/');
                $changes[] = 'directory_from_cd';
                $cdUsed = true;
            }
            $command = preg_replace('/^exec\s+/', '', $command) ?? $command;
        }

        $worker = [
            'name' => self::proposeName($program),
            'template' => self::detectTemplate($command),
            'folder' => $folder,
            'command' => $command,
        ];

        foreach (['numprocs' => 'processes', 'startsecs' => 'startsecs', 'startretries' => 'startretries', 'stopwaitsecs' => 'stopwaitsecs'] as $key => $field) {
            if (isset($kv[$key])) {
                if (preg_match('/^\d{1,9}$/D', $kv[$key]) === 1) {
                    $worker[$field] = (int) $kv[$key];
                } else {
                    $changes[] = 'key_dropped:' . $key;
                }
            }
        }
        foreach (['autostart', 'redirect_stderr'] as $key) {
            if (isset($kv[$key])) {
                $bool = filter_var($kv[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($bool === null) {
                    $changes[] = 'key_dropped:' . $key;
                } else {
                    $worker[$key] = $bool;
                }
            }
        }
        if (isset($kv['autorestart'])) {
            $v = strtolower($kv['autorestart']);
            if (in_array($v, ['true', 'unexpected', 'false'], true)) {
                $worker['autorestart'] = $v;
            } else {
                $changes[] = 'key_dropped:autorestart';
            }
        }

        $logs = false;
        foreach (array_keys($kv) as $key) {
            if (str_starts_with($key, 'stdout_logfile') || str_starts_with($key, 'stderr_logfile')) {
                $logs = true;
            } elseif ($key === 'user') {
                $changes[] = 'user_key_dropped';
            } elseif (in_array($key, self::ALWAYS_ON, true)) {
                if (filter_var($kv[$key], FILTER_VALIDATE_BOOLEAN) !== true) {
                    $changes[] = 'always_on:' . $key;
                }
            } elseif ($key === 'directory') {
                // A directory that disagrees with the cd is the one that gets lost.
                if ($cdUsed && rtrim($kv['directory'], '/') !== $folder) {
                    $changes[] = 'key_dropped:directory';
                }
            } elseif (!in_array($key, self::KEPT, true)) {
                $changes[] = 'key_dropped:' . $key;
            }
        }
        if ($logs) {
            $changes[] = 'logs_moved';
        }
        $worker += array_intersect_key((new Worker('', 'custom', '', ''))->toArray(), array_flip(self::DEFAULTED));

        return [$worker, array_values(array_unique($changes))];
    }

    private static function proposeName(string $program): string
    {
        $name = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($program)), '-');
        $name = rtrim(substr($name, 0, 32), '-');

        return $name === '' ? 'worker' : $name;
    }

    private static function detectTemplate(string $command): string
    {
        return match (true) {
            str_contains($command, 'artisan queue:work') => 'laravel-queue',
            str_contains($command, 'artisan horizon') => 'laravel-horizon',
            str_contains($command, 'messenger:consume') => 'symfony-messenger',
            default => 'custom',
        };
    }

    /** @return string the backup path */
    private function backup(string $path): string
    {
        $dir = $this->p->backupDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create $dir");
        }
        $stamp = date('Ymd-His', $this->now === null ? time() : ($this->now)());
        $target = $dir . '/' . $stamp . '-' . basename($path);
        for ($n = 2; file_exists($target); $n++) {
            $target = $dir . '/' . $stamp . '-' . $n . '-' . basename($path);
        }
        // Created 0600 before any content lands in it: the original may hold secrets in environment=.
        if (!@touch($target) || !@chmod($target, 0600) || !@copy($path, $target)) {
            @unlink($target);
            throw new RuntimeException("Cannot write $target");
        }

        return $target;
    }

    /** Writes the replacement next to the original, same mode, ready for an atomic rename. */
    private static function prepare(string $path, string $content): string
    {
        $tmp = $path . '.wsv-tmp';
        $mode = @fileperms($path);
        if ($mode === false || @file_put_contents($tmp, rtrim($content, "\n") . "\n") === false || !@chmod($tmp, $mode & 0777)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot write $tmp");
        }

        return $tmp;
    }

    private static function discard(string $backup, ?string $tmp): void
    {
        @unlink($backup);
        if ($tmp !== null) {
            @unlink($tmp);
        }
    }
}
