<?php

declare(strict_types=1);

namespace WorkerSupervisor;

final class CpUser
{
    private const EXT_NAME = 'worker_supervisor';

    /**
     * @param array<string, string> $ext
     * @param int|null $uid null when the system user cannot be looked up
     */
    private function __construct(
        public readonly string $user,
        public readonly string $home,
        public readonly string $owner,
        public readonly string $plan,
        public readonly string $featureList,
        public readonly bool $suspended,
        public readonly array $ext,
        public readonly ?int $uid,
        public readonly string $startdate,
    ) {
    }

    /** Null when the uid is unknown: such an account cannot be told apart from an earlier one of the name. */
    public function identity(): ?Identity
    {
        return $this->uid === null ? null : new Identity($this->uid, $this->startdate);
    }

    /** @param (callable(string): (array|false))|null $passwdLookup defaults to posix_getpwnam */
    public static function load(Paths $p, string $user, ?callable $passwdLookup = null): ?self
    {
        $raw = @file_get_contents($p->cpuserFile($user));
        if ($raw === false) {
            return null;
        }

        $kv = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $kv[$k] = $v;
        }

        // The extension's keys linger in the file after it is removed from the package.
        $ext = [];
        if (in_array(self::EXT_NAME, preg_split('/\s+/', $kv['_PACKAGE_EXTENSIONS'] ?? '', -1, PREG_SPLIT_NO_EMPTY), true)) {
            foreach ($kv as $k => $v) {
                if (str_starts_with($k, self::EXT_NAME . '_')) {
                    $ext[$k] = $v;
                }
            }
        }

        $pw = ($passwdLookup ?? 'posix_getpwnam')($user);
        $home = is_array($pw) && isset($pw['dir']) ? (string) $pw['dir'] : '/home/' . $user;
        $uid = is_array($pw) && is_int($pw['uid'] ?? null) ? $pw['uid'] : null;

        return new self(
            $user,
            $home,
            $kv['OWNER'] ?? '',
            $kv['PLAN'] ?? '',
            $kv['FEATURELIST'] ?? '',
            file_exists($p->suspendedFlag($user)),
            $ext,
            $uid,
            $kv['STARTDATE'] ?? '',
        );
    }
}
