<?php

declare(strict_types=1);

namespace WorkerSupervisor;

use WorkerSupervisor\Supervisor\Runner;
use RuntimeException;

/**
 * Lets a client read their worker logs from their own home, read-only: ~/logs/worker_supervisor
 * links to /var/log/worker_supervisor/<account>/, which is root's and readable by the account's
 * group only. supervisord writes there as root, so the logs never live in a client-writable
 * place (a client could otherwise swap a log for a symlink root then writes through). CageFS
 * shows each account its own directory only, through the CAGEFS_LINE mount install.sh adds.
 */
final class LogAccess
{
    public const HOME_LINK = 'logs/worker_supervisor';
    public const CAGEFS_LINE = '%/var/log/worker_supervisor';

    /**
     * Run as the account: $1 home, $2 target, $3 the logs root with a trailing slash. A link of
     * ours (into $3) is replaced; anything else of the client's is left, exit 3.
     */
    private const LINK_SCRIPT = <<<'SH'
        link="$1/logs/worker_supervisor"
        if [ -L "$link" ]; then
          case "$(readlink "$link")" in "$3"*) rm -f "$link" ;; *) exit 3 ;; esac
        elif [ -e "$link" ]; then
          exit 3
        fi
        mkdir -p "$1/logs" && ln -s "$2" "$link"
        SH;

    /** @var callable(string): (array|false) */
    private $passwdLookup;

    /**
     * @param (callable(string): (array|false))|null $passwdLookup defaults to posix_getpwnam
     * @param string $enterUser runs the link script inside the account's CageFS and LVE when
     *                          present, as the launcher does; runuser otherwise
     */
    public function __construct(
        private readonly Paths $p,
        private readonly Runner $runner,
        ?callable $passwdLookup = null,
        private readonly string $cagefsMpFile = '/etc/cagefs/cagefs.mp',
        private readonly string $enterUser = '/usr/sbin/cagefs_enter_user',
    ) {
        $this->passwdLookup = $passwdLookup ?? 'posix_getpwnam';
    }

    /**
     * Creates the account's log directory if needed and gives it to the account's group: 2750, so
     * the files supervisord creates there get that group too. Root-only (0700) when the account
     * has no system user.
     *
     * CageFS shows the account only the directories that existed when it mounted the account's
     * cage, hence prepareAll() at install and the account-creation hook. A directory made later
     * shows inside CageFS after the account's next remount, never forced here: a CageFS remount
     * kills the account's processes, workers included (checked on CloudLinux 9).
     */
    public function secure(string $user): void
    {
        $dir = $this->p->logDir($user);
        $base = dirname($dir);
        foreach ([$base => 0711, $dir => 0700] as $path => $mode) {
            if (!is_dir($path) && !@mkdir($path, $mode, true) && !is_dir($path)) {
                throw new RuntimeException("Cannot create $path.");
            }
        }
        self::chmod($base, 0711);

        $pw = ($this->passwdLookup)($user);
        $gid = is_array($pw) && isset($pw['gid']) ? (int) $pw['gid'] : null;
        if ($gid === null) {
            self::chmod($dir, 0700);

            return;
        }
        $this->give($dir, $gid, 02750);
    }

    /**
     * A log directory for every cPanel account, so CageFS includes it the next time it mounts each
     * cage, before the account has any worker.
     *
     * @return list<string> the accounts
     */
    public function prepareAll(): array
    {
        $users = [];
        foreach (glob(dirname($this->p->cpuserFile('x')) . '/*') ?: [] as $file) {
            $user = basename($file);
            if (preg_match('/^[a-z][a-z0-9]{0,15}$/D', $user) === 1 && is_file($file)) {
                $this->secure($user);
                $users[] = $user;
            }
        }

        return $users;
    }

    /**
     * Root's alone again, for logs of an earlier account of the name: the account that now has the
     * name must not read them.
     */
    public function restrict(string $user): void
    {
        $dir = $this->p->logDir($user);
        if (is_dir($dir)) {
            $this->give($dir, 0, 0700);
        }
    }

    private function give(string $dir, int $gid, int $mode): void
    {
        self::chgrp($dir, $gid);
        self::chmod($dir, $mode);
        foreach (scandir($dir) ?: [] as $entry) {
            $file = $dir . '/' . $entry;
            if (!is_link($file) && is_file($file)) {
                self::chgrp($file, $gid);
            }
        }
    }

    /**
     * Makes ~/logs/worker_supervisor point at the account's log directory. Root only looks; the
     * account itself writes in its home.
     *
     * @return bool whether a link was made or repaired
     */
    public function link(CpUser $u): bool
    {
        if ($u->home === '' || $u->home === '/') {
            return false;
        }
        $target = $this->p->logDir($u->user);
        $prefix = dirname($target) . '/';
        $link = $u->home . '/' . self::HOME_LINK;
        clearstatcache(true, $link);
        if (is_link($link)) {
            $current = (string) readlink($link);
            // Ours and right, or the client's own: either way nothing to do.
            if ($current === $target || !str_starts_with($current, $prefix)) {
                return false;
            }
        } elseif (file_exists($link)) {
            return false;
        }
        $as = is_executable($this->enterUser)
            ? [$this->enterUser, '--no-fork', $u->user]
            : ['/usr/sbin/runuser', '-u', $u->user, '--'];
        [$code] = $this->runner->run([...$as, '/bin/sh', '-c', self::LINK_SCRIPT, 'sh', $u->home, $target, $prefix]);

        return $code === 0;
    }

    /** @return bool|null null without CageFS */
    public function cagefsMounted(): ?bool
    {
        if (!is_file($this->cagefsMpFile)) {
            return null;
        }
        $lines = array_map('trim', file($this->cagefsMpFile) ?: []);

        return in_array(self::CAGEFS_LINE, $lines, true);
    }

    private static function chmod(string $path, int $mode): void
    {
        clearstatcache(true, $path);
        if ((fileperms($path) & 07777) !== $mode && !@chmod($path, $mode)) {
            throw new RuntimeException("Cannot chmod $path.");
        }
    }

    private static function chgrp(string $path, int $gid): void
    {
        clearstatcache(true, $path);
        if (filegroup($path) !== $gid && !@chgrp($path, $gid)) {
            throw new RuntimeException("Cannot chgrp $path.");
        }
    }
}
