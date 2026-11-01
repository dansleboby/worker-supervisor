<?php

declare(strict_types=1);

namespace WorkerSupervisor;

/**
 * Single source of runtime file locations. The $root prefix exists so tests can
 * redirect every path into a temporary tree; production uses the default ''.
 */
final class Paths
{
    private const APP_DIR = '/usr/local/cpanel/3rdparty/worker_supervisor';
    private const DATA_DIR = '/var/cpanel/worker_supervisor';
    private const LOG_DIR = '/var/log/worker_supervisor';
    private const INI_DIR = '/etc/supervisord.d';

    public function __construct(private readonly string $root = '')
    {
    }

    public function appDir(): string
    {
        return $this->root . self::APP_DIR;
    }

    public function dataDir(): string
    {
        return $this->root . self::DATA_DIR;
    }

    public function accountFile(string $user): string
    {
        return $this->dataDir() . '/accounts/' . $user . '.json';
    }

    public function launchFile(string $user, string $name): string
    {
        return $this->launchDir($user) . '/' . $name;
    }

    /** Present while root holds the worker (suspension, admin stop, feature off, removal): bin/wsv-run refuses to start it. */
    public function holdFile(string $user, string $name): string
    {
        return $this->launchDir($user) . '/' . $name . '.hold';
    }

    public function launchDir(string $user): string
    {
        return $this->dataDir() . '/launch/' . $user;
    }

    public function settingsFile(): string
    {
        return $this->dataDir() . '/settings.json';
    }

    public function backupDir(): string
    {
        return $this->dataDir() . '/backups';
    }

    public function logDir(string $user): string
    {
        return $this->root . self::LOG_DIR . '/' . $user;
    }

    public function iniFile(string $user, string $name): string
    {
        return $this->iniDir() . '/wsv_' . $user . '_' . $name . '.ini';
    }

    public function iniDir(): string
    {
        return $this->root . self::INI_DIR;
    }

    public function cpuserFile(string $user): string
    {
        return $this->root . '/var/cpanel/users/' . $user;
    }

    public function suspendedFlag(string $user): string
    {
        return $this->root . '/var/cpanel/suspended/' . $user;
    }

    public function launcher(): string
    {
        return $this->appDir() . '/bin/wsv-run';
    }
}
