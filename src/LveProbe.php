<?php

declare(strict_types=1);

namespace WorkerSupervisor;

use WorkerSupervisor\Supervisor\Runner;

/** CloudLinux implementation of AccountProbe. Every failure degrades to null: the probe only feeds hints. */
final class LveProbe implements AccountProbe
{
    // -k: the probes run client code (login scripts, php); TERM alone may not end them.
    private const TIMEOUT = ['/usr/bin/timeout', '-k', '5', '10'];

    /** @var callable(string): (array|false) */
    private $passwdLookup;

    /** @param (callable(string): (array|false))|null $passwdLookup defaults to posix_getpwnam */
    public function __construct(private readonly Runner $runner, ?callable $passwdLookup = null)
    {
        $this->passwdLookup = $passwdLookup ?? 'posix_getpwnam';
    }

    /**
     * `lvectl paneluserlimits <uid> --json --bytes` (lvectl 6.6.44, CloudLinux 9) prints the configured
     * limits whether or not the LVE is loaded right now, as
     * {"data":[{"ID":"0",...defaults...},{"ID":"1001","PMEM":"1073741824",...,"PACKAGE":"default"}]}.
     * `limits`/`list-user` only show loaded LVEs. PMEM "0" means unlimited.
     */
    public function memoryLimitMb(string $user): ?int
    {
        $uid = $this->passwd($user)['uid'] ?? null;
        if (!is_int($uid)) {
            return null;
        }

        [$code, $out] = $this->runner->run([
            ...self::TIMEOUT,
            '/usr/sbin/lvectl', 'paneluserlimits', (string) $uid, '--json', '--bytes',
        ]);
        $data = $code === 0 ? json_decode($out, true) : null;

        foreach (is_array($data['data'] ?? null) ? $data['data'] : [] as $row) {
            if (is_array($row) && ($row['ID'] ?? null) === (string) $uid) {
                $bytes = $row['PMEM'] ?? null;
                return is_string($bytes) && ctype_digit($bytes) && $bytes !== '0'
                    ? intdiv((int) $bytes, 1048576)
                    : null;
            }
        }

        return null;
    }

    /** Same environment and CageFS entry as bin/wsv-run, so it reports the PHP the worker will get. */
    public function phpVersion(string $user, string $folder): ?string
    {
        $home = $this->passwd($user)['dir'] ?? null;
        if (!is_string($home) || $home === '') {
            return null;
        }

        [$code, $out] = $this->runner->run([
            ...self::TIMEOUT,
            '/usr/bin/env', '-i', 'HOME=' . $home, 'USER=' . $user, 'LOGNAME=' . $user, 'SHELL=/bin/bash',
            'PATH=/usr/local/bin:/usr/bin:/bin', 'LANG=C.UTF-8',
            '/usr/sbin/cagefs_enter_user', $user,
            '/bin/bash', '-lc', 'cd -- "$1" && php -r "echo PHP_VERSION;"', 'x', $folder,
        ]);

        // Login scripts may print before php does; the version is the last thing on stdout.
        return $code === 0 && preg_match('/(?:^|\n)(\d+\.\d+\.\d+\S*)\s*$/D', $out, $m) === 1 ? $m[1] : null;
    }

    private function passwd(string $user): array
    {
        $pw = ($this->passwdLookup)($user);

        return is_array($pw) ? $pw : [];
    }
}
