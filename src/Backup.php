<?php

declare(strict_types=1);

namespace WorkerSupervisor;

use WorkerSupervisor\Supervisor\SupervisorClient;
use InvalidArgumentException;
use Throwable;

/**
 * Carries an account's workers through cPanel account backups, and through JetBackup, which
 * builds an account's configuration with cPanel's pkgacct.
 *
 * Backup: the pkgacct component (perl/Cpanel/Pkgacct/Components/WorkerSupervisor.pm) writes
 * export() as FILE at the root of the archive. Restore: the PkgAcct::Restore post hook finds it in
 * the extracted archive and calls restore(), which stages it as the account's "restoring" marker
 * and applies it at once. Workers whose folder does not exist yet stay staged and reconcile retries
 * them (resumeAll()): JetBackup restores the home directory after that hook. The marker goes with
 * the account: removing the account drops it, a username change moves it.
 *
 * The archive may come from another server, so its content is untrusted input: every worker goes
 * through the validation of a client save before it is stored, and a staged restore only ever
 * applies to the cPanel account (Identity) it was restored into.
 */
final class Backup
{
    public const FILE = 'worker_supervisor.json';

    private const FORMAT = 1;
    private const MAX_BYTES = 1048576;
    /** Far above any sane max_workers; bounds the work a crafted archive can cause. */
    private const MAX_WORKERS = 200;
    private const USER_PATTERN = '/^[a-z][a-z0-9]{0,15}$/D';
    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}$/D';
    /** Staged workers whose folder never appears are dropped after this long. */
    private const PENDING_TTL = 86400;
    /** Refusals a later restore of the home directory can lift. */
    private const WAIT_CODES = ['folder_missing', 'required_path_missing'];

    /** @var callable(string): ?CpUser */
    private $cpuserLoader;

    /** @var callable(int, string): void */
    private $log;

    /** @var callable(): int */
    private $now;

    /**
     * @param WorkerValidator $validator best with a NullProbe: restores read no informational issue,
     *                                   and a probe would run the account's shell once per worker
     * @param callable(string): ?CpUser $cpuserLoader
     * @param (callable(int, string): void)|null $log defaults to syslog
     * @param (callable(): int)|null $now
     */
    public function __construct(
        private readonly Paths $p,
        private readonly AccountStore $store,
        private readonly Settings $settings,
        private readonly IniRenderer $ini,
        private readonly SupervisorClient $sv,
        private readonly WorkerValidator $validator,
        callable $cpuserLoader,
        private readonly FeatureChecker $features,
        ?callable $log = null,
        ?callable $now = null,
    ) {
        $this->cpuserLoader = $cpuserLoader;
        $this->log = $log ?? static function (int $priority, string $message): void {
            openlog('worker_supervisor', LOG_PID, LOG_DAEMON);
            syslog($priority, $message);
        };
        $this->now = $now ?? static fn (): int => time();
    }

    /**
     * Only PkgAcct::Restore post: earlier stages have no extract_dir. data.user is the name the
     * account is restored as (the new one with `restorepkg --newuser`); the docs also allow the path
     * of the archive there, which is ignored.
     *
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: list<string>}|null
     */
    public static function route(array $payload): ?array
    {
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        if (($context['category'] ?? null) !== 'PkgAcct' || ($context['event'] ?? null) !== 'Restore' || ($context['stage'] ?? null) !== 'post') {
            return null;
        }
        $user = $data['user'] ?? null;
        $dir = $data['extract_dir'] ?? null;
        if (!is_string($user) || preg_match(self::USER_PATTERN, $user) !== 1
            || !is_string($dir) || !str_starts_with($dir, '/') || preg_match('~[\x00-\x1F\x7F]|/\.\.(/|$)~', $dir) === 1
        ) {
            return null;
        }

        return ['restore', [$user, $dir]];
    }

    /**
     * @param array<string, mixed> $payload
     * @return string|null the method that handled the event
     */
    public function handle(array $payload): ?string
    {
        $route = self::route($payload);
        if ($route === null) {
            return null;
        }
        [$method, $args] = $route;
        $this->$method(...$args);

        return $method;
    }

    /**
     * @return array{format: int, version: string, user: string, home: string, workers: list<array<string, mixed>>}|null
     *         null for an account the plugin never stored, or whose stored workers belong to an
     *         earlier account of the name
     */
    public function export(string $user): ?array
    {
        $u = ($this->cpuserLoader)($user);
        if ($u === null || !$this->store->exists($user)) {
            return null;
        }
        $a = $this->store->load($user);
        if (!$a->ownedBy($u->identity())) {
            return null;
        }

        return [
            'format' => self::FORMAT,
            'version' => Version::read($this->p->appDir() . '/VERSION'),
            'user' => $user,
            'home' => $u->home,
            'workers' => array_map(static fn (Worker $w) => $w->toArray(), array_values($a->workers)),
        ];
    }

    /**
     * Stages the workers file of an extracted archive and applies it. An archive without one
     * changes no worker but ends any earlier restore still waiting.
     *
     * @return array<string, mixed>|null see resume(); null when the archive has no workers file
     */
    public function restore(string $user, string $extractDir): ?array
    {
        $file = $extractDir . '/' . self::FILE;
        if (!file_exists($file) && !is_link($file)) {
            if ($this->store->marker($user, 'restoring') !== null) {
                $this->store->withLock($user, fn () => $this->store->unmark($user, 'restoring'));
            }

            return null;
        }
        try {
            $data = self::read($file);
        } catch (InvalidArgumentException $e) {
            ($this->log)(LOG_ERR, "Ignored the workers in the backup of '$user': " . $e->getMessage());

            return self::report(['error' => 'archive_invalid']);
        }
        // Binds the restore to this cPanel account, so a later account given the name never gets it.
        $identity = ($this->cpuserLoader)($user)?->identity();
        if ($identity === null) {
            ($this->log)(LOG_ERR, "Ignored the workers in the backup of '$user': the account or its system user cannot be read.");

            return self::report(['error' => 'account_unknown']);
        }

        $this->store->withLock($user, fn () => $this->writeStaged($user, [
            'staged_at' => ($this->now)(),
            'identity' => $identity->toArray(),
            'replace' => true,
            'home' => $data['home'],
            'workers' => $data['workers'],
        ]));

        return $this->resume($user);
    }

    /**
     * Applies $user's staged restore. The first pass makes the account's workers those of the
     * backup; later passes only add the workers that were waiting for their folder.
     *
     * @return array{restored: list<string>, unchanged: list<string>, removed: list<string>, pending: list<string>,
     *               skipped: array<string, list<string>>, expired: list<string>, error?: string}|null
     *         null when nothing is staged; error account_unknown or account_mismatch: it stays
     *         staged; account_replaced: dropped
     */
    public function resume(string $user): ?array
    {
        $staged = $this->readStaged($user);
        if ($staged === false) {
            // Read again under the lock: the hook may have been rewriting it.
            $this->store->withLock($user, fn () => $this->readStaged($user) === false && $this->drop($user));

            return null;
        }
        if ($staged === null) {
            return null;
        }
        $u = ($this->cpuserLoader)($user);
        // A system user lookup can fail for a moment: wait rather than take the account for another.
        if ($u === null || $u->home === '' || $u->home === '/' || $u->identity() === null) {
            return $this->store->withLock($user, fn () => $this->expire($user, $staged))
                ?? self::report(['error' => 'account_unknown', 'pending' => self::names($staged['workers'])]);
        }
        $featureOff = $this->featureOff($user);
        $limits = LimitResolver::resolve($this->settings, $u);

        try {
            $result = $this->store->transaction(
                $user,
                fn (Account $a): array => $this->apply($a, $u, $limits, $featureOff),
            );
        } catch (ServiceError $e) {
            ($this->log)(LOG_ERR, "Cannot restore the workers of '$user' from a backup: the stored workers belong to an earlier account of the name. Remove /var/cpanel/worker_supervisor/accounts/$user.json to apply it.");

            return self::report(['error' => $e->errorCode, 'pending' => self::names($staged['workers'])]);
        }
        if ($result === null) {
            return null;
        }
        [$plan, $report] = $result;

        foreach ($report['removed'] as $name) {
            $this->sv->remove($this->ini->programName($user, $name));
        }
        foreach ($plan as [$old, $w, $wasRunnable, $runnable, $iniChanged]) {
            $program = $this->ini->programName($user, $w->name);
            if ($old === null || $iniChanged) {
                $this->sv->apply($program, $runnable);
            } elseif ($wasRunnable && !$runnable) {
                // An autostart=false .ini reads the same either way: supervisord learns nothing from it.
                $this->sv->stop($program);
            } elseif (!$wasRunnable && $runnable) {
                $this->sv->apply($program, true);
            } elseif ($runnable && ($old->command !== $w->command || $old->folder !== $w->folder)) {
                // Only the launch file changed: supervisord sees no config change.
                $this->sv->restart($program);
            }
        }
        $this->logReport($user, $report);

        return $report;
    }

    /**
     * Every staged restore, for reconcile.
     *
     * @return array<string, array<string, mixed>> report per account
     */
    public function resumeAll(): array
    {
        $reports = [];
        foreach ($this->store->markedUsers('restoring') as $user) {
            if (preg_match(self::USER_PATTERN, $user) !== 1) {
                continue;
            }
            try {
                $report = $this->resume($user);
            } catch (Throwable $e) {
                ($this->log)(LOG_ERR, "Restoring the workers of '$user': " . $e->getMessage());
                $report = self::report(['error' => 'internal']);
            }
            if ($report !== null) {
                $reports[$user] = $report;
            }
        }

        return $reports;
    }

    /**
     * Runs under the account's lock.
     *
     * @return array{0: Account, 1: array{0: list<array{0: ?Worker, 1: Worker, 2: bool, 3: bool, 4: bool}>, 1: array}|null}
     * @throws ServiceError account_mismatch
     */
    private function apply(Account $a, CpUser $u, Limits $limits, ?bool $featureOff): array
    {
        $user = $u->user;
        $staged = $this->readStaged($user);
        if ($staged === false) {
            $this->drop($user);
        }
        if (!is_array($staged)) {
            return [$a, null];
        }
        $expired = $this->expire($user, $staged);
        if ($expired !== null) {
            return [$a, [[], $expired]];
        }
        $current = $u->identity();
        if (!$staged['identity']->equals($current)) {
            $this->store->unmark($user, 'restoring');
            ($this->log)(LOG_ERR, "Dropped the workers restored for '$user': the account was removed and the name now belongs to another account.");

            return [$a, [[], self::report(['error' => 'account_replaced', 'expired' => self::names($staged['workers'])])]];
        }
        if (!$a->ownedBy($current)) {
            throw new ServiceError('account_mismatch');
        }
        if ($a->identity === null) {
            $a = $a->withIdentity($current);
        }
        // Runnable states before the restore, as the account was stored.
        $before = $a;
        $a = $a->withFlags($u->suspended, $featureOff ?? $a->featureOff);

        $report = self::report([]);
        $targets = [];
        foreach ($staged['workers'] as $i => $record) {
            [$label, $w] = $this->worker($record, $i, $staged['home'], $u->home);
            if ($w === null) {
                $report['skipped'][$label] = ['input_invalid'];
            } elseif (isset($targets[$w->name]) || (!$staged['replace'] && isset($a->workers[$w->name]))) {
                // Later passes only add: a worker the client created meanwhile is theirs.
                $report['skipped'][$label] = ['name_taken'];
            } else {
                $targets[$w->name] = [$w, $label];
            }
        }

        if ($staged['replace']) {
            foreach (array_keys($a->workers) as $name) {
                if (!isset($targets[$name])) {
                    $this->ini->remove($user, $name);
                    $a = $a->withoutWorker($name);
                    $report['removed'][] = $name;
                }
            }
        }

        $accepted = [];
        $waiting = [];
        foreach ($targets as $name => [$w, $label]) {
            $existing = $a->workers[$name] ?? null;
            if ($existing !== null && $existing->toArray() === $w->toArray()) {
                $report['unchanged'][] = $name;
                continue;
            }
            $others = array_values(array_diff_key($a->workers, [$name => true]));
            $errors = [];
            foreach ($this->validator->validate($w, $u, $limits, $others, $existing) as $issue) {
                if ($issue->level === 'error') {
                    $errors[$issue->code] = true;
                }
            }
            $errors = array_keys($errors);
            if ($errors === []) {
                $a = $a->withWorker($w);
                $accepted[] = [$existing, $w];
                $report['restored'][] = $name;
            } elseif (array_diff($errors, self::WAIT_CODES) === []) {
                $waiting[] = $w->toArray();
                $report['pending'][] = $name;
            } else {
                // The label, not the name: a name the validator refused can hold anything.
                $report['skipped'][$label] = $errors;
            }
        }

        $plan = [];
        foreach ($accepted as [$old, $w]) {
            // cPanel's suspended flag counts even before the account flag has caught up.
            $held = $a->held($w) || $u->suspended;
            $runnable = $a->runnable($w) && !$held;
            $wasRunnable = $old !== null && $before->runnable($old) && !$u->suspended;
            $plan[] = [$old, $w, $wasRunnable, $runnable, $this->ini->write($a, $w, $runnable, $held)];
        }

        if ($waiting === []) {
            $this->store->unmark($user, 'restoring');
        } else {
            $this->writeStaged($user, [
                'staged_at' => $staged['staged_at'],
                'identity' => $current->toArray(),
                'replace' => false,
                'home' => $u->home,
                'workers' => $waiting,
            ]);
        }

        return [$a, [$plan, $report]];
    }

    /**
     * @return array{0: string, 1: ?Worker} a label for reports and logs (the name when it is a
     *                                      valid one, else the record's position), and the worker,
     *                                      null when the record is not one
     */
    private function worker(mixed $record, int $index, string $backupHome, string $home): array
    {
        $name = is_array($record) ? ($record['name'] ?? null) : null;
        $label = is_string($name) && preg_match(self::NAME_PATTERN, $name) === 1 ? $name : '#' . ($index + 1);
        if (!is_array($record)) {
            return [$label, null];
        }
        foreach ($record as $value) {
            if ($value !== null && !is_scalar($value)) {
                return [$label, null];
            }
        }
        try {
            $w = Worker::fromArray($record);
        } catch (InvalidArgumentException) {
            return [$label, null];
        }
        if (!in_array($w->desired, ['running', 'stopped'], true)) {
            return [$label, null];
        }
        if ($backupHome !== '/' && str_starts_with($backupHome, '/')
            && ($w->folder === $backupHome || str_starts_with($w->folder, $backupHome . '/'))
        ) {
            $w = $w->with(['folder' => $home . substr($w->folder, strlen($backupHome))]);
        }

        return [$label, $w];
    }

    /**
     * @return array{home: string, workers: list<mixed>}
     * @throws InvalidArgumentException
     */
    private static function read(string $file): array
    {
        if (is_link($file) || !is_file($file)) {
            throw new InvalidArgumentException(self::FILE . ' is not a regular file.');
        }
        $text = @file_get_contents($file, false, null, 0, self::MAX_BYTES + 1);
        if ($text === false || strlen($text) > self::MAX_BYTES) {
            throw new InvalidArgumentException(self::FILE . ' is unreadable or larger than 1 MB.');
        }
        $data = json_decode($text, true);
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT || !is_string($data['home'] ?? null)
            || !is_array($data['workers'] ?? null) || !array_is_list($data['workers'])
        ) {
            throw new InvalidArgumentException(self::FILE . ' is not a format ' . self::FORMAT . ' workers file.');
        }
        if (count($data['workers']) > self::MAX_WORKERS) {
            throw new InvalidArgumentException(self::FILE . ' lists more than ' . self::MAX_WORKERS . ' workers.');
        }

        return ['home' => $data['home'], 'workers' => $data['workers']];
    }

    /**
     * Takes no lock: callers inside a transaction already hold the account's.
     *
     * @return array{staged_at: int, identity: Identity, replace: bool, home: string, workers: list<mixed>}|false|null
     *         false when the marker cannot be read
     */
    private function readStaged(string $user): array|false|null
    {
        $marker = $this->store->marker($user, 'restoring');
        if ($marker === null) {
            return null;
        }
        $data = json_decode($marker['value'], true);
        try {
            if (!is_array($data) || !is_int($data['staged_at'] ?? null) || !is_bool($data['replace'] ?? null)
                || !is_string($data['home'] ?? null) || !is_array($data['workers'] ?? null) || !array_is_list($data['workers'])
                || !is_array($data['identity'] ?? null)
            ) {
                throw new InvalidArgumentException('unexpected content');
            }
            $data['identity'] = Identity::fromArray($data['identity']);
        } catch (InvalidArgumentException) {
            return false;
        }

        return $data;
    }

    /** Call under the account's lock. */
    private function drop(string $user): bool
    {
        $this->store->unmark($user, 'restoring');
        ($this->log)(LOG_ERR, "Dropped the unreadable staged restore of '$user'.");

        return true;
    }

    /**
     * Call under the account's lock.
     *
     * @param array<string, mixed> $data
     */
    private function writeStaged(string $user, array $data): void
    {
        $this->store->mark($user, 'restoring', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Drops a staged restore older than PENDING_TTL. Call under the account's lock.
     *
     * @param array{staged_at: int, workers: list<mixed>} $staged
     * @return array<string, mixed>|null the report when it expired
     */
    private function expire(string $user, array $staged): ?array
    {
        if (($this->now)() - $staged['staged_at'] <= self::PENDING_TTL) {
            return null;
        }
        $this->store->unmark($user, 'restoring');
        $names = self::names($staged['workers']);
        ($this->log)(LOG_ERR, "Gave up restoring the workers of '$user' from a backup after a day: " . implode(', ', $names) . '. Their folder or account never appeared.');

        return self::report(['expired' => $names]);
    }

    /** Null when unknown: the stored flag then stands until reconcile checks the feature. */
    private function featureOff(string $user): ?bool
    {
        try {
            if (!$this->features->featureRegistered()) {
                return null;
            }
            $on = $this->features->usersWithFeature([$user]);
        } catch (Throwable) {
            return null;
        }

        return isset($on[$user]) ? !$on[$user] : null;
    }

    /** @param array<string, mixed> $report */
    private function logReport(string $user, array $report): void
    {
        $parts = [];
        foreach (['restored', 'unchanged', 'removed', 'pending'] as $key) {
            if ($report[$key] !== []) {
                $parts[] = ($key === 'pending' ? 'waiting for their folder' : $key) . ': ' . implode(', ', $report[$key]);
            }
        }
        foreach ($report['skipped'] as $name => $codes) {
            $parts[] = "skipped $name (" . implode(', ', $codes) . ')';
        }
        if ($parts !== []) {
            ($this->log)($report['skipped'] === [] ? LOG_NOTICE : LOG_WARNING, "Restored the workers of '$user' from a backup: " . implode('; ', $parts) . '.');
        }
    }

    /**
     * @param list<mixed> $records
     * @return list<string>
     */
    private static function names(array $records): array
    {
        $names = [];
        foreach ($records as $i => $record) {
            $name = is_array($record) ? ($record['name'] ?? null) : null;
            $names[] = is_string($name) && preg_match(self::NAME_PATTERN, $name) === 1 ? $name : '#' . ($i + 1);
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function report(array $values): array
    {
        return $values + ['restored' => [], 'unchanged' => [], 'removed' => [], 'pending' => [], 'skipped' => [], 'expired' => []];
    }
}
