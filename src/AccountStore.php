<?php

declare(strict_types=1);

namespace WorkerSupervisor;

use JsonException;
use RuntimeException;

/** Root-owned per-account JSON persistence. Use transaction() for any read-modify-write. */
final class AccountStore
{
    private const MARKERS = ['renaming', 'removing'];

    public function __construct(private readonly Paths $paths)
    {
    }

    /** @throws RuntimeException when the file exists but cannot be read or parsed */
    public function load(string $user): Account
    {
        $file = $this->paths->accountFile($user);
        if (!is_file($file)) {
            return new Account($user);
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException("Cannot read $file.");
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new RuntimeException('not an object');
            }
            $workers = [];
            foreach ((array) ($data['workers'] ?? []) as $row) {
                $w = Worker::fromArray((array) $row);
                $workers[$w->name] = $w;
            }
            $identity = isset($data['identity']) ? Identity::fromArray((array) $data['identity']) : null;
        } catch (JsonException | RuntimeException | \InvalidArgumentException $e) {
            // Never fall back to an empty account: the next save would wipe the client's workers.
            throw new RuntimeException("Corrupt account file $file: " . $e->getMessage(), 0, $e);
        }

        return new Account($user, $workers, (bool) ($data['suspended'] ?? false), (bool) ($data['feature_off'] ?? false), $identity);
    }

    public function save(Account $a): void
    {
        $file = $this->paths->accountFile($a->user);
        $this->ensureDir(dirname($file));

        $json = json_encode([
            'version' => 1,
            'identity' => $a->identity?->toArray(),
            'suspended' => $a->suspended,
            'feature_off' => $a->featureOff,
            'workers' => array_map(static fn (Worker $w) => $w->toArray(), array_values($a->workers)),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));
        $fh = fopen($tmp, 'xe');
        if ($fh === false) {
            throw new RuntimeException("Cannot create $tmp.");
        }
        // Restrict before any content lands so the data is never briefly world-readable.
        chmod($tmp, 0600);
        $ok = fwrite($fh, $json . "\n") !== false && fflush($fh);
        fclose($fh);
        if (!$ok || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot write $file.");
        }
    }

    /**
     * @param callable(Account): array{0: Account, 1: mixed} $fn
     * @return mixed the second element returned by $fn
     */
    public function transaction(string $user, callable $fn): mixed
    {
        return $this->withLock($user, function () use ($user, $fn): mixed {
            [$account, $result] = $fn($this->load($user));
            $this->save($account);

            return $result;
        });
    }

    /**
     * Holds the account's lock around $fn without loading or saving, for callers that move or
     * delete the account's files.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function withLock(string $user, callable $fn): mixed
    {
        return $this->withLocks([$user], $fn);
    }

    /**
     * Locks are taken in sorted order, so two callers locking the same accounts cannot deadlock.
     *
     * @template T
     * @param string[] $users
     * @param callable(): T $fn
     * @return T
     */
    public function withLocks(array $users, callable $fn): mixed
    {
        $users = array_unique($users);
        sort($users);

        $handles = [];
        try {
            foreach ($users as $user) {
                $handles[] = $this->lock($user);
            }

            return $fn();
        } finally {
            foreach (array_reverse($handles) as $h) {
                fclose($h); // closing releases the flock
            }
        }
    }

    public function exists(string $user): bool
    {
        return is_file($this->paths->accountFile($user));
    }

    /**
     * Call under withLock(). The lock file stays: a process blocked on it would otherwise go on to
     * lock an unlinked inode while the next caller locks a new file.
     */
    public function delete(string $user): void
    {
        $file = $this->paths->accountFile($user);
        if (is_file($file) && !unlink($file)) {
            throw new RuntimeException("Cannot remove $file.");
        }
        foreach (self::MARKERS as $kind) {
            $this->unmark($user, $kind);
        }
    }

    /** Call holding both accounts' locks (withLocks), or a transaction could create the target in between. */
    public function rename(string $old, string $new): void
    {
        $from = $this->paths->accountFile($old);
        $to = $this->paths->accountFile($new);
        if (is_file($to)) {
            throw new RuntimeException("Account file for '$new' already exists.");
        }
        if (!is_file($from)) {
            throw new RuntimeException("No account file for '$old'.");
        }

        if (!rename($from, $to)) {
            throw new RuntimeException("Cannot rename $from.");
        }
        foreach (self::MARKERS as $kind) {
            $this->unmark($old, $kind);
        }
    }

    /**
     * Records a cPanel operation in progress on the account (renaming, removing) for the processes
     * that run after this one: the post hook and reconcile. Call under withLock().
     */
    public function mark(string $user, string $kind, string $value = ''): void
    {
        $file = $this->markerPath($user, $kind);
        if (file_put_contents($file, $value) === false) {
            throw new RuntimeException("Cannot write $file.");
        }
    }

    /** @return array{since: int, value: string}|null since is when mark() was last called */
    public function marker(string $user, string $kind): ?array
    {
        $file = $this->markerPath($user, $kind);
        clearstatcache(true, $file);
        $since = @filemtime($file);
        $value = @file_get_contents($file);

        return $since === false || $value === false ? null : ['since' => $since, 'value' => $value];
    }

    public function unmark(string $user, string $kind): void
    {
        $file = $this->markerPath($user, $kind);
        if (is_file($file) && !unlink($file)) {
            throw new RuntimeException("Cannot remove $file.");
        }
    }

    /** @return string[] */
    public function users(): array
    {
        $users = [];
        foreach (glob(dirname($this->paths->accountFile('x')) . '/*.json') ?: [] as $f) {
            $users[] = basename($f, '.json');
        }
        sort($users);

        return $users;
    }

    /** @return resource */
    private function lock(string $user)
    {
        $lock = $this->paths->accountFile($user) . '.lock';
        $this->ensureDir(dirname($lock));

        // 'e' (close-on-exec): Service::save runs the account's login scripts while this lock is held.
        $lh = fopen($lock, 'ce');
        if ($lh === false) {
            throw new RuntimeException("Cannot open $lock.");
        }
        chmod($lock, 0600);
        if (!flock($lh, LOCK_EX)) {
            fclose($lh);
            throw new RuntimeException("Cannot lock $lock.");
        }

        return $lh;
    }

    private function markerPath(string $user, string $kind): string
    {
        if (!in_array($kind, self::MARKERS, true)) {
            throw new \InvalidArgumentException("Unknown marker '$kind'.");
        }

        return $this->paths->accountFile($user) . '.' . $kind;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create $dir.");
        }
    }
}
