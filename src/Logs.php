<?php

declare(strict_types=1);

namespace WorkerSupervisor;

use InvalidArgumentException;
use RuntimeException;

/**
 * Reads and clears worker logs. Logs live in a root-only directory outside the
 * client's home, so this class (run as root by the core) is the only way a client
 * sees them. File names derive only from the validated user, worker name and an
 * integer process index; nothing caller-supplied becomes a path.
 */
final class Logs
{
    private const BLOCK = 65536;
    private const MAX_LINES = 2000;
    private const MAX_BYTES = 1048576;
    private const BACKUPS = 3;

    public function __construct(private readonly Paths $paths)
    {
    }

    /** @return list<array{process: string, stream: string, path: string, exists: bool}> */
    public function files(string $user, Worker $w): array
    {
        $streams = $w->redirectStderr ? ['out'] : ['out', 'err'];
        $list = [];
        for ($i = 0; $i < $w->processes; $i++) {
            foreach ($streams as $stream) {
                $path = $this->path($user, $w, $i, $stream);
                $list[] = [
                    'process' => sprintf('%02d', $i),
                    'stream' => $stream,
                    'path' => $path,
                    'exists' => is_file($path),
                ];
            }
        }

        return $list;
    }

    /** Reads backwards in blocks so cost is independent of file size. */
    public function tail(string $user, Worker $w, int $process, string $stream, int $lines): string
    {
        $lines = max(1, min(self::MAX_LINES, $lines));
        $path = $this->path($user, $w, $process, $stream);
        $this->refuseSymlink($path);

        if (!is_file($path)) {
            return '';
        }
        $h = fopen($path, 'rbe');
        if ($h === false) {
            throw new RuntimeException("Cannot open log file for process $process.");
        }

        try {
            $pos = (int) fstat($h)['size'];
            $buf = '';
            $newlines = 0;
            $need = $lines;
            while ($pos > 0 && $newlines < $need && strlen($buf) < self::MAX_BYTES) {
                $len = min(self::BLOCK, $pos);
                $pos -= $len;
                fseek($h, $pos);
                $chunk = fread($h, $len);
                if ($chunk === false) {
                    throw new RuntimeException("Cannot read log file for process $process.");
                }
                if ($buf === '' && str_ends_with($chunk, "\n")) {
                    // A trailing newline terminates the last line rather than starting a new one.
                    $need++;
                }
                $newlines += substr_count($chunk, "\n");
                $buf = $chunk . $buf;
            }
        } finally {
            fclose($h);
        }

        $terminated = str_ends_with($buf, "\n");
        $parts = explode("\n", $terminated ? substr($buf, 0, -1) : $buf);
        $out = implode("\n", array_slice($parts, -$lines));

        return $buf === '' ? '' : $out . ($terminated ? "\n" : '');
    }

    /**
     * Truncates in place so the descriptor supervisord holds stays valid; unlinking
     * would leave it writing to a deleted inode.
     */
    public function clear(string $user, Worker $w): void
    {
        foreach ($this->files($user, $w) as $f) {
            $this->refuseSymlink($f['path']);
            if ($f['exists']) {
                $h = fopen($f['path'], 'cbe');
                if ($h === false) {
                    throw new RuntimeException("Cannot truncate log file for process {$f['process']}.");
                }
                ftruncate($h, 0);
                fclose($h);
            }
            for ($n = 1; $n <= self::BACKUPS; $n++) {
                $backup = $f['path'] . '.' . $n;
                if (is_file($backup) || is_link($backup)) {
                    unlink($backup);
                }
            }
        }
    }

    public function removeAccount(string $user): void
    {
        Fs::removeTree($this->accountDir($user));
    }

    /**
     * Running workers keep logging: the descriptors supervisord holds follow the renamed directory.
     * A leftover directory under the new name belongs to no account and is replaced.
     */
    public function moveAccount(string $old, string $new): void
    {
        $from = $this->accountDir($old);
        $to = $this->accountDir($new);
        if (!is_dir($from) || is_link($from)) {
            return;
        }
        Fs::removeTree($to);
        if (!rename($from, $to)) {
            throw new RuntimeException("Cannot move $from.");
        }
    }

    private function accountDir(string $user): string
    {
        // An empty or dotted name would resolve to the parent holding every account's logs.
        if (preg_match('/^[a-z][a-z0-9]{0,15}$/D', $user) !== 1) {
            throw new InvalidArgumentException('Invalid account name.');
        }

        return $this->paths->logDir($user);
    }

    private function path(string $user, Worker $w, int $process, string $stream): string
    {
        if ($process < 0 || $process >= $w->processes) {
            throw new InvalidArgumentException("Process index $process is out of range.");
        }
        if ($stream !== 'out' && !($stream === 'err' && !$w->redirectStderr)) {
            throw new InvalidArgumentException("Invalid log stream '$stream'.");
        }

        return sprintf(
            '%s/%s_%02d%s.log',
            $this->paths->logDir($user),
            $w->name,
            $process,
            $stream === 'err' ? '.err' : '',
        );
    }

    private function refuseSymlink(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Refusing to follow a symlink in the log directory.');
        }
    }
}
