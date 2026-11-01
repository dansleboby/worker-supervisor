<?php

declare(strict_types=1);

namespace WorkerSupervisor;

final class Settings
{
    private const OVERRIDE_KEYS = ['max_processes', 'max_workers', 'max_stopwaitsecs', 'templates'];

    /** @param array<string, array> $overrides */
    private function __construct(
        private readonly Paths $paths,
        private Limits $defaults,
        private array $overrides,
    ) {
    }

    public static function load(Paths $p): self
    {
        $raw = @file_get_contents($p->settingsFile());
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            return new self($p, Limits::serverDefaults(), []);
        }

        $overrides = [];
        foreach (is_array($data['overrides'] ?? null) ? $data['overrides'] : [] as $user => $o) {
            if (is_array($o)) {
                $overrides[(string) $user] = self::filter($o);
            }
        }
        $defaults = is_array($data['defaults'] ?? null) ? Limits::fromArray($data['defaults']) : Limits::serverDefaults();

        return new self($p, $defaults, $overrides);
    }

    /**
     * Locked read-modify-write: reloads this instance from disk, runs $fn on it, then saves if $fn
     * changed anything. WHM and the account hooks write the file concurrently, so every change goes
     * through here.
     *
     * load() reads an unparsable file as server defaults with no overrides; saving over it would
     * erase root's settings, so a change to such a file is refused instead.
     *
     * @template T
     * @param callable(self): T $fn
     * @return T
     * @throws \RuntimeException when there is a change to save and the file exists but cannot be parsed
     */
    public function transaction(callable $fn): mixed
    {
        $this->ensureDir();
        $file = $this->paths->settingsFile();
        $lock = $file . '.lock';
        $lh = fopen($lock, 'ce');
        if ($lh === false) {
            throw new \RuntimeException("Cannot open $lock");
        }
        try {
            chmod($lock, 0600);
            if (!flock($lh, LOCK_EX)) {
                throw new \RuntimeException("Cannot lock $lock");
            }
            $fresh = self::load($this->paths);
            $this->defaults = $fresh->defaults;
            $this->overrides = $fresh->overrides;
            $before = $this->snapshot();

            $result = $fn($this);
            if ($this->snapshot() === $before) {
                return $result;
            }
            if (is_file($file) && !is_array(json_decode((string) @file_get_contents($file), true))) {
                throw new \RuntimeException("Not saving the settings: $file cannot be parsed. Fix or remove it first.");
            }
            $this->save();

            return $result;
        } finally {
            fclose($lh); // closing releases the flock
        }
    }

    /** Unlocked: use transaction() unless nothing else can be writing. */
    public function save(): void
    {
        $file = $this->paths->settingsFile();
        $this->ensureDir();

        $json = json_encode(
            ['defaults' => $this->defaults->toArray(), 'overrides' => (object) $this->overrides],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $tmp = $file . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $json . "\n") === false || !chmod($tmp, 0600) || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write $file");
        }
    }

    public function defaults(): Limits
    {
        return $this->defaults;
    }

    public function setDefaults(Limits $l): void
    {
        $this->defaults = $l;
    }

    public function override(string $user): ?array
    {
        return $this->overrides[$user] ?? null;
    }

    /** @return array<string, array> keyed by user */
    public function overrides(): array
    {
        return $this->overrides;
    }

    public function setOverride(string $user, ?array $o): void
    {
        if ($o === null) {
            unset($this->overrides[$user]);
            return;
        }
        $this->overrides[$user] = self::filter($o);
    }

    public function renameUser(string $old, string $new): void
    {
        if (isset($this->overrides[$old])) {
            $this->overrides[$new] = $this->overrides[$old];
            unset($this->overrides[$old]);
        }
    }

    public function forgetUser(string $user): void
    {
        unset($this->overrides[$user]);
    }

    /** @return array{defaults: array, overrides: array<string, array>} */
    private function snapshot(): array
    {
        return ['defaults' => $this->defaults->toArray(), 'overrides' => $this->overrides];
    }

    private function ensureDir(): void
    {
        $dir = dirname($this->paths->settingsFile());
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create $dir");
        }
    }

    private static function filter(array $o): array
    {
        return array_intersect_key($o, array_flip(self::OVERRIDE_KEYS));
    }
}
