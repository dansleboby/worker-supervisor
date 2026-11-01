<?php

declare(strict_types=1);

namespace WorkerSupervisor;

use InvalidArgumentException;
use Throwable;

/**
 * Wire protocol of bin/wsv. stdin is untrusted client text apart from "user", which the caller
 * authenticated; stdout reaches the client, so only codes and validation issues ever go there.
 */
final class Cli
{
    /** Root-only actions: take no stdin and no user. */
    private const ROOT_ACTIONS = ['overview', 'reconcile'];

    /**
     * Root acting on any account, for scripts and hooks: same request as user actions, with "user"
     * naming the target account. Only root running wsv itself reaches them: the cPanel admin module
     * forwards a fixed list of user actions.
     */
    private const ADMIN_ACTIONS = ['admin_stop', 'admin_release', 'admin_start', 'admin_restart', 'admin_config'];

    /** Root reading an account for its backup (the pkgacct component): {"user": ...} on stdin. */
    private const BACKUP_ACTIONS = ['backup_export'];

    private const USER_ACTIONS = [
        'list', 'get', 'validate', 'save', 'delete', 'start', 'stop', 'restart', 'logs', 'clear_logs', 'templates',
    ];

    /** Lets bin/wsv skip reading stdin, so `wsv reconcile` from a terminal does not wait for EOF. */
    public static function needsStdin(string $action): bool
    {
        return !in_array($action, self::ROOT_ACTIONS, true);
    }

    /**
     * @param array{service: Service, admin: AdminService, lifecycle: Lifecycle, backup: Backup} $services
     * @param (callable(Throwable): void)|null $onError receives unexpected throwables and the cause of a
     *                                           bad_request, for stderr
     * @return string one JSON line, newline included
     */
    public static function run(string $action, string $stdin, array $services, ?callable $onError = null): string
    {
        try {
            if (in_array($action, self::ROOT_ACTIONS, true)) {
                return self::ok(self::root($action, $services));
            }
            if (in_array($action, self::BACKUP_ACTIONS, true)) {
                [$user] = self::request($stdin);

                return self::ok($services['backup']->export($user));
            }
            if (in_array($action, self::ADMIN_ACTIONS, true)) {
                [$user, $args] = self::request($stdin);

                return self::ok(self::admin($action, $user, self::name($args), $services['admin']));
            }
            if (!in_array($action, self::USER_ACTIONS, true)) {
                throw new InvalidArgumentException('Unknown action.');
            }
            [$user, $args] = self::request($stdin);

            return self::ok(self::user($action, $user, $args, $services['service']));
        } catch (ServiceError $e) {
            return self::error($e->errorCode, $e->params, array_map(static fn (Issue $i) => $i->toArray(), $e->issues));
        } catch (InvalidArgumentException $e) {
            // Mostly a malformed request, but an internal bug can surface as one too.
            if ($onError !== null) {
                $onError($e);
            }

            return self::error('bad_request');
        } catch (Throwable $e) {
            if ($onError !== null) {
                $onError($e);
            }

            return self::error('internal');
        }
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private static function request(string $stdin): array
    {
        $req = json_decode($stdin, true);
        if (!is_array($req) || !is_string($req['user'] ?? null) || preg_match('/^[a-z][a-z0-9]{0,15}$/D', $req['user']) !== 1) {
            throw new InvalidArgumentException('Request must be an object with a valid user.');
        }
        $args = $req['args'] ?? [];
        if (!is_array($args)) {
            throw new InvalidArgumentException('args must be an object.');
        }

        return [$req['user'], $args];
    }

    /** @param array<string, mixed> $args */
    private static function user(string $action, string $user, array $args, Service $svc): mixed
    {
        switch ($action) {
            case 'list':
                return $svc->listWorkers($user);
            case 'templates':
                return $svc->templates($user);
            case 'get':
                return $svc->getWorker($user, self::name($args));
            case 'validate':
                return $svc->validate($user, self::input($args), self::mode($args), self::originalName($args));
            case 'save':
                return $svc->save($user, self::input($args), self::mode($args), self::originalName($args));
            case 'delete':
                $svc->delete($user, self::name($args));
                return null;
            case 'start':
                $svc->start($user, self::name($args));
                return null;
            case 'stop':
                $svc->stop($user, self::name($args));
                return null;
            case 'restart':
                $svc->restart($user, self::name($args));
                return null;
            case 'clear_logs':
                $svc->clearLogs($user, self::name($args));
                return null;
            case 'logs':
                return $svc->logs(
                    $user,
                    self::name($args),
                    self::int($args, 'process', 0),
                    self::string($args, 'stream', 'out'),
                    self::int($args, 'lines', 200),
                );
        }
        throw new InvalidArgumentException('Unknown action.');
    }

    /** @param array{service: Service, admin: AdminService, lifecycle: Lifecycle, backup: Backup} $services */
    private static function root(string $action, array $services): mixed
    {
        switch ($action) {
            case 'overview':
                return $services['admin']->overview();
            case 'reconcile':
                return $services['lifecycle']->reconcile() + ['restores' => $services['backup']->resumeAll()];
        }
        throw new InvalidArgumentException('Unknown action.');
    }

    private static function admin(string $action, string $user, string $name, AdminService $admin): mixed
    {
        switch ($action) {
            case 'admin_stop':
                $admin->adminStop($user, $name);
                return null;
            case 'admin_release':
                return ['started' => $admin->release($user, $name)];
            case 'admin_start':
                $admin->start($user, $name);
                return null;
            case 'admin_restart':
                $admin->restart($user, $name);
                return null;
            case 'admin_config':
                return $admin->config($user, $name);
        }
        throw new InvalidArgumentException('Unknown action.');
    }

    /** @param array<string, mixed> $args */
    private static function name(array $args): string
    {
        $name = self::string($args, 'name', '');
        if ($name === '') {
            throw new InvalidArgumentException('name is required.');
        }

        return $name;
    }

    /** @return array<string, mixed> */
    private static function input(array $args): array
    {
        if (!is_array($args['input'] ?? null)) {
            throw new InvalidArgumentException('input must be an object.');
        }

        return $args['input'];
    }

    private static function mode(array $args): string
    {
        return self::string($args, 'mode', 'form');
    }

    /** Absent and '' both mean "new worker"; Service treats only null that way. */
    private static function originalName(array $args): ?string
    {
        $name = self::string($args, 'original_name', '');

        return $name === '' ? null : $name;
    }

    private static function string(array $args, string $key, string $default): string
    {
        $v = $args[$key] ?? $default;
        if (!is_string($v)) {
            throw new InvalidArgumentException($key . ' must be a string.');
        }

        return $v;
    }

    private static function int(array $args, string $key, int $default): int
    {
        $v = $args[$key] ?? $default;
        if (!is_int($v)) {
            throw new InvalidArgumentException($key . ' must be an integer.');
        }

        return $v;
    }

    private static function ok(mixed $data): string
    {
        return self::encode(['ok' => true, 'data' => $data]);
    }

    /**
     * @param array<string, scalar> $params
     * @param list<array> $issues
     */
    private static function error(string $code, array $params = [], array $issues = []): string
    {
        return self::encode(['ok' => false, 'error' => ['code' => $code, 'params' => (object) $params, 'issues' => $issues]]);
    }

    private static function encode(array $payload): string
    {
        // Client text can hold invalid UTF-8 (log tails, commands); substitute rather than fail the whole reply.
        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException(json_last_error_msg());
        }

        return $json . "\n";
    }
}
