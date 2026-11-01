<?php

declare(strict_types=1);

namespace WorkerSupervisor;

/** Decides what an (untrusted) cPanel client may save. */
final class WorkerValidator
{
    public function __construct(private readonly AccountProbe $probe)
    {
    }

    /**
     * An edit ($original set) never adds a worker and may keep the processes it already has, so an
     * account a smaller package left above its limits can still edit, but not add or grow (spec 6.2).
     *
     * @param Worker[] $others the account's other workers, excluding the one being edited
     * @param ?Worker $original the stored worker being edited, null for a new one
     * @return Issue[]
     */
    public function validate(Worker $w, CpUser $u, Limits $l, array $others, ?Worker $original = null): array
    {
        $issues = [];
        $err = static function (string $field, string $code, array $params = []) use (&$issues): void {
            $issues[] = new Issue('error', $field, $code, $params);
        };

        if (preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/D', $w->name) !== 1) {
            $err('name', 'name_invalid');
        } else {
            foreach ($others as $o) {
                if ($o->name === $w->name) {
                    $err('name', 'name_taken');
                    break;
                }
            }
        }

        if ($original === null && count($others) >= $l->maxWorkers) {
            $err('name', 'workers_limit', ['max' => $l->maxWorkers]);
        }

        $template = Templates::get($w->template);
        if ($template === null) {
            $err('template', 'template_unknown');
        } elseif (!in_array($template->id, $l->templates, true)) {
            $err('template', 'template_not_allowed');
        }

        $folderOk = $this->checkFolder($w, $u, $template, $err);

        if (trim($w->command) === '') {
            $err('command', 'command_empty');
        } elseif (strlen($w->command) > 2000 || preg_match('/[\r\n\0]/', $w->command) === 1) {
            $err('command', 'command_invalid');
        }

        $remaining = $l->maxProcesses - array_sum(array_map(static fn (Worker $o) => $o->processes, $others));
        $max = max($remaining, $original?->processes ?? 0);
        if ($w->processes < 1 || $w->processes > $max) {
            $err('processes', 'processes_range', ['max' => max(0, $max)]);
        }
        if ($template?->fixedProcesses !== null && $w->processes !== $template->fixedProcesses) {
            $err('processes', 'processes_fixed', ['value' => $template->fixedProcesses]);
        }

        if ($w->stopwaitsecs < 1 || $w->stopwaitsecs > $l->maxStopwaitsecs) {
            $err('stopwaitsecs', 'stopwait_range', ['max' => $l->maxStopwaitsecs]);
        }
        if ($w->startsecs < 0 || $w->startsecs > 3600) {
            $err('startsecs', 'startsecs_range');
        }
        if ($w->startretries < 0 || $w->startretries > 100) {
            $err('startretries', 'startretries_range');
        }
        if (!in_array($w->autorestart, ['true', 'false', 'unexpected'], true)) {
            $err('autorestart', 'autorestart_invalid');
        }

        if ($template !== null) {
            if ($template->laravelRestart && $w->autorestart !== 'true') {
                $issues[] = new Issue('warning', 'autorestart', 'laravel_needs_autorestart');
            }

            if ($template->memoryFlagPattern !== null
                && @preg_match($template->memoryFlagPattern, $w->command, $m) === 1
                && isset($m[1])
            ) {
                $limit = $this->probe->memoryLimitMb($u->user);
                $total = (int) $m[1] * $w->processes;
                if ($limit !== null && $total > $limit) {
                    $issues[] = new Issue('warning', 'command', 'memory_over_limit', [
                        'total_mb' => $total,
                        'limit_mb' => $limit,
                    ]);
                }
            }
        }

        if ($folderOk) {
            $version = $this->probe->phpVersion($u->user, $w->folder);
            if ($version !== null) {
                $issues[] = new Issue('info', 'folder', 'php_version', ['version' => $version]);
            }
        }

        return $issues;
    }

    /** @param callable(string, string, array=): void $err */
    private function checkFolder(Worker $w, CpUser $u, ?Template $template, callable $err): bool
    {
        if ($w->folder === '' || $w->folder[0] !== '/' || preg_match('/[\x00-\x1F\x7F]/', $w->folder) === 1) {
            $err('folder', 'folder_invalid');
            return false;
        }

        $real = realpath($w->folder);
        if ($real === false || !is_dir($real)) {
            $err('folder', 'folder_missing');
            return false;
        }

        // realpath resolves symlinks and "..", so this catches escapes through either.
        $home = realpath($u->home) ?: $u->home;
        if ($real !== $home && !str_starts_with($real, rtrim($home, '/') . '/')) {
            $err('folder', 'folder_outside_home');
            return false;
        }

        $ok = true;
        foreach ($template?->requiredPaths ?? [] as $path) {
            if (!file_exists($real . '/' . $path)) {
                $err('folder', 'required_path_missing', ['path' => $path]);
                $ok = false;
            }
        }

        return $ok;
    }
}
