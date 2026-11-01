<?php

declare(strict_types=1);

namespace WorkerSupervisor;

use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * Generates a worker's supervisord program file and its root-only launch file.
 *
 * Security boundary: the .ini carries only validated identifiers, integers and fixed
 * enum values. The client's folder and command go to the launch file read by
 * bin/wsv-run, so supervisord never splits or %-expands client text.
 */
final class IniRenderer
{
    // Must match bin/wsv-run, which refuses anything else.
    private const USER_PATTERN = '/^[a-z][a-z0-9]{0,15}$/D';
    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}$/D';
    private const AUTORESTART = ['true', 'unexpected', 'false'];

    /** Paths written into the .ini are read by the real supervisord, so never test-prefixed. */
    private readonly Paths $runtime;

    public function __construct(private readonly Paths $paths)
    {
        $this->runtime = new Paths();
    }

    public function programName(string $user, string $name): string
    {
        return $user . '_' . $name;
    }

    public function renderIni(string $user, Worker $w, bool $runnable): string
    {
        $this->assertIdentifiers($user, $w->name);
        if (!in_array($w->autorestart, self::AUTORESTART, true)) {
            throw new InvalidArgumentException("Invalid autorestart value for worker '{$w->name}'.");
        }

        $log = $this->runtime->logDir($user) . '/' . $w->name . '_%(process_num)02d';
        $lines = [
            '; Managed by Worker Supervisor - do not edit, changes are overwritten.',
            '[program:' . $this->programName($user, $w->name) . ']',
            'command=' . $this->runtime->launcher() . ' ' . $user . ' ' . $w->name,
            'process_name=%(program_name)s_%(process_num)02d',
            'numprocs=' . $w->processes,
            'user=root',
            'autostart=' . self::flag($w->autostart && $runnable),
            'autorestart=' . $w->autorestart,
            'startsecs=' . $w->startsecs,
            'startretries=' . $w->startretries,
            'stopwaitsecs=' . $w->stopwaitsecs,
            'stopsignal=TERM',
            'stopasgroup=true',
            'killasgroup=true',
            'redirect_stderr=' . self::flag($w->redirectStderr),
            'stdout_logfile=' . $log . '.log',
            'stdout_logfile_maxbytes=10MB',
            'stdout_logfile_backups=3',
        ];
        if (!$w->redirectStderr) {
            array_push(
                $lines,
                'stderr_logfile=' . $log . '.err.log',
                'stderr_logfile_maxbytes=10MB',
                'stderr_logfile_backups=3',
            );
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * One line each, read by bin/wsv-run: folder, command, and the uid of the account the workers
     * belong to, which the launcher compares with the account's current uid.
     *
     * @param int|null $uid null (an empty line) for an account stored before identities were recorded
     */
    public function renderLaunch(Worker $w, ?int $uid): string
    {
        if (preg_match('/[\n\0]/', $w->folder . $w->command) === 1) {
            throw new InvalidArgumentException("Folder and command of worker '{$w->name}' must be single lines.");
        }

        return $w->folder . "\n" . $w->command . "\n" . $uid . "\n";
    }

    /**
     * Writes the launch file before the .ini so supervisord can never start a program
     * whose launch file is missing. A command or folder change alone leaves the .ini
     * untouched: running processes then need a restart, not a `supervisorctl update`.
     *
     * $held writes the hold marker that makes bin/wsv-run refuse to start the worker, so a
     * supervisorctl job queued before root's stop cannot start it after. A hold is set before
     * the .ini changes and lifted after, so a failed write leaves the worker held.
     *
     * @param bool $held stopped for a reason of root's (Account::held(), cPanel suspension, removal), not by the client
     * @return bool true when the .ini content changed
     */
    public function write(Account $a, Worker $w, bool $runnable, bool $held): bool
    {
        if ($runnable && $held) {
            throw new LogicException("Worker '{$w->name}' cannot be both runnable and held.");
        }
        $user = $a->user;
        $ini = $this->renderIni($user, $w, $runnable);
        $launch = $this->renderLaunch($w, $a->identity?->uid);

        $this->ensureDir($this->paths->logDir($user), 0700);
        $launchFile = $this->paths->launchFile($user, $w->name);
        $this->ensureDir(dirname($launchFile), 0700);
        $holdFile = $this->paths->holdFile($user, $w->name);
        if ($held) {
            $this->putIfChanged($holdFile, '', 0600);
        }
        $this->putIfChanged($launchFile, $launch, 0600);

        $iniFile = $this->paths->iniFile($user, $w->name);
        $this->ensureDir(dirname($iniFile), 0755);
        $changed = $this->putIfChanged($iniFile, $ini, 0644);

        if (!$held && is_file($holdFile) && !@unlink($holdFile)) {
            throw new RuntimeException("Cannot remove $holdFile.");
        }

        return $changed;
    }

    /** The .ini goes first: if a later unlink fails, the next `supervisorctl update` still drops the program. */
    public function remove(string $user, string $name): void
    {
        $this->assertIdentifiers($user, $name);

        $files = [$this->paths->iniFile($user, $name), $this->paths->launchFile($user, $name), $this->paths->holdFile($user, $name)];
        $logDir = $this->paths->logDir($user);
        // Names cannot contain '_', so this never matches another worker's logs.
        $logPattern = '/^' . preg_quote($name, '/') . '_\d{2,}(\.err)?\.log(\.\d+)?$/D';
        foreach (is_dir($logDir) ? (scandir($logDir) ?: []) : [] as $entry) {
            if (preg_match($logPattern, $entry) === 1) {
                $files[] = $logDir . '/' . $entry;
            }
        }

        foreach ($files as $f) {
            if ((is_file($f) || is_link($f)) && !@unlink($f)) {
                throw new RuntimeException("Cannot remove $f.");
            }
        }
    }

    /**
     * Program files present in the include directory, whether or not a stored worker still owns them.
     * Neither part can contain '_', so the file name splits unambiguously.
     *
     * @return list<array{0: string, 1: string}> user and worker name
     */
    public function managed(): array
    {
        $found = [];
        $dir = $this->paths->iniDir();
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $entry) {
            if (preg_match('/^wsv_([a-z][a-z0-9]{0,15})_([a-z0-9][a-z0-9-]{0,31})\.ini$/D', $entry, $m) === 1) {
                $found[] = [$m[1], $m[2]];
            }
        }

        return $found;
    }

    /** Drops the account's launch directory with anything left in it; remove() its workers first. */
    public function removeAccount(string $user): void
    {
        $this->assertUser($user);
        Fs::removeTree($this->paths->launchDir($user));
    }

    private function assertIdentifiers(string $user, string $name): void
    {
        $this->assertUser($user);
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('Invalid worker name.');
        }
    }

    private function assertUser(string $user): void
    {
        if (preg_match(self::USER_PATTERN, $user) !== 1) {
            throw new InvalidArgumentException('Invalid account name.');
        }
    }

    private static function flag(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    private function putIfChanged(string $file, string $content, int $mode): bool
    {
        if (is_file($file) && @file_get_contents($file) === $content) {
            return false;
        }

        // The suffix keeps the temp file out of supervisord's `*.ini` include glob.
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));
        $fh = @fopen($tmp, 'xe');
        if ($fh === false) {
            throw new RuntimeException("Cannot create $tmp.");
        }
        chmod($tmp, $mode);
        $ok = fwrite($fh, $content) === strlen($content) && fflush($fh);
        fclose($fh);
        if (!$ok || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot write $file.");
        }

        return true;
    }

    private function ensureDir(string $dir, int $mode): void
    {
        if (!is_dir($dir) && !@mkdir($dir, $mode, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create $dir.");
        }
    }
}
