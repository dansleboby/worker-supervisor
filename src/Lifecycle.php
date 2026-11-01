<?php

declare(strict_types=1);

namespace WorkerSupervisor;

use WorkerSupervisor\Supervisor\ProcessStatus;
use WorkerSupervisor\Supervisor\SupervisorClient;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Follows cPanel account events: bin/wsv-hook calls one method per Whostmgr hook, and reconcile()
 * (cron, every 5 minutes) repairs whatever a hook missed, including feature-list edits, which
 * have no hook.
 *
 * Nothing of a client is destroyed until cPanel has confirmed the account is gone (accountGone()):
 * pre hooks only stop workers and leave markers, since a removal or rename can still be aborted
 * after them. As in Service, files change under the account's lock so they never interleave with
 * a client's transaction, and supervisord is told afterwards through calls that return at once.
 *
 * Workers belong to an Identity, not to a name: data whose identity is not the current account's
 * (an earlier account of the name, left by missed hooks) is never started, moved or deleted here.
 */
final class Lifecycle
{
    private const USER_PATTERN = '/^[a-z][a-z0-9]{0,15}$/D';

    /**
     * Longer than a removal or username change is expected to take: until then a marker means the
     * operation may still be running, after it the operation is taken as aborted or stuck.
     */
    private const GRACE = 3600;

    /** @var callable(string): ?CpUser */
    private $cpuserLoader;

    /** @var callable(string): (array|false) */
    private $passwdLookup;

    /** @var callable(int, string): void */
    private $log;

    /**
     * @param callable(string): ?CpUser $cpuserLoader
     * @param (callable(string): (array|false))|null $passwdLookup defaults to posix_getpwnam
     * @param (callable(int, string): void)|null $log priority (LOG_*) and message; defaults to syslog,
     *                                                since cron discards reconcile's output
     */
    public function __construct(
        private readonly AccountStore $store,
        private readonly Settings $settings,
        private readonly IniRenderer $ini,
        private readonly SupervisorClient $sv,
        private readonly Logs $logs,
        callable $cpuserLoader,
        private readonly FeatureChecker $features,
        ?callable $passwdLookup = null,
        ?callable $log = null,
    ) {
        $this->cpuserLoader = $cpuserLoader;
        $this->passwdLookup = $passwdLookup ?? 'posix_getpwnam';
        $this->log = $log ?? static function (int $priority, string $message): void {
            openlog('worker_supervisor', LOG_PID, LOG_DAEMON);
            syslog($priority, $message);
        };
    }

    /**
     * Maps a Whostmgr hook payload to the method handling it. Field names come from payloads
     * captured on cPanel 11.138 (tests/fixtures/hooks/).
     *
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: list<string>}|null null when the event needs nothing
     */
    public static function route(array $payload): ?array
    {
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $args = is_array($data['args'] ?? null) ? $data['args'] : [];
        if (($context['category'] ?? null) !== 'Whostmgr') {
            return null;
        }

        $user = $data['user'] ?? null;
        $renamed = isset($data['newuser']) && $data['newuser'] !== $user;
        $route = match ([$context['event'] ?? null, $context['stage'] ?? null]) {
            // data.user is whoever acted (root or a reseller); the account is in data.args.
            ['Accounts::suspendacct', 'post'] => ['suspend', [$args['user'] ?? null]],
            ['Accounts::unsuspendacct', 'post'] => ['unsuspend', [$args['user'] ?? null]],
            ['Accounts::Remove', 'pre'] => ['prepareRemove', [$user]],
            ['Accounts::Remove', 'post'] => ['finishRemove', [$user]],
            ['Accounts::Modify', 'pre'] => $renamed ? ['prepareRename', [$user, $data['newuser']]] : null,
            ['Accounts::Modify', 'post'] => $renamed ? ['rename', [$user, $data['newuser']]] : null,
            ['Accounts::change_package', 'post'] => ['packageChanged', [$user]],
            default => null,
        };
        if ($route === null) {
            return null;
        }
        foreach ($route[1] as $name) {
            if (!is_string($name) || preg_match(self::USER_PATTERN, $name) !== 1) {
                return null;
            }
        }

        return $route;
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
     * Gone means both the cPanel user file and the system user are missing: either alone can be a
     * transient state of a cPanel operation, and acting on it would destroy a live client's data.
     */
    public function accountGone(string $user): bool
    {
        return ($this->cpuserLoader)($user) === null && ($this->passwdLookup)($user) === false;
    }

    public function suspend(string $user): void
    {
        $this->assertUser($user);
        if ($this->store->exists($user)) {
            $this->sync($user, true, null);
        }
    }

    public function unsuspend(string $user): void
    {
        $this->assertUser($user);
        if ($this->store->exists($user)) {
            $this->sync($user, false, null);
        }
    }

    /**
     * Limits are read on every request, so only the feature (the package's feature list) needs applying.
     *
     * @throws RuntimeException when cPanel's feature settings cannot be read; nothing is changed then
     */
    public function packageChanged(string $user): void
    {
        $this->assertUser($user);
        if (!$this->store->exists($user)) {
            return;
        }
        $featureOff = null;
        if ($this->features->featureRegistered()) {
            $on = $this->features->usersWithFeature([$user])[$user] ?? null;
            $featureOff = $on === null ? null : !$on;
        }
        $this->sync($user, null, $featureOff);
    }

    /**
     * Remove pre: stops the workers and keeps every file. Another plugin's blocking pre hook, or
     * cPanel itself, can still abort the removal; finishRemove() or reconcile() settles it.
     */
    public function prepareRemove(string $user): void
    {
        $this->assertUser($user);
        if (!$this->store->exists($user)) {
            return;
        }
        $jobs = $this->store->withLock($user, function () use ($user): array {
            if (!$this->store->exists($user)) {
                return [];
            }
            $a = $this->store->load($user);
            $this->store->mark($user, 'removing');
            $jobs = [];
            foreach ($a->workers as $w) {
                $jobs[] = [$w->name, $a->runnable($w), $this->ini->write($a, $w, false, true)];
            }

            return $jobs;
        });
        foreach ($jobs as [$name, $was, $iniChanged]) {
            $program = $this->ini->programName($user, $name);
            if ($iniChanged) {
                $this->sv->apply($program, false);
            } elseif ($was) {
                $this->sv->stop($program);
            }
        }
    }

    /** Remove post: cPanel runs it after a partial removal too, so it removes only a gone account. */
    public function finishRemove(string $user): void
    {
        $this->assertUser($user);
        if ($this->accountGone($user)) {
            $this->remove($user);
        } elseif ($this->store->exists($user)) {
            ($this->log)(LOG_WARNING, "Account '$user' still exists after its removal; its workers stay stopped"
                . ' until reconcile removes them or, after ' . self::GRACE . 's, restores them.');
        }
    }

    /**
     * Stops and forgets everything of the account: programs, .ini, launch files, logs, data and
     * override. Callers decide the account is gone (accountGone()) first.
     */
    public function remove(string $user): void
    {
        $this->assertUser($user);
        // Every removed cPanel account comes through here; most never used the plugin and must not get a lock file.
        $hasData = $this->store->exists($user) || $this->iniNames($user) !== [];
        $names = $hasData ? $this->removeFiles($user) : [];

        foreach ($names as $name) {
            $this->sv->remove($this->ini->programName($user, $name));
        }
        if ($hasData) {
            ($this->log)(LOG_NOTICE, "Removed the worker data of account '$user' ("
                . ($names === [] ? 'no workers' : 'workers: ' . implode(', ', $names)) . ')');
        }
        // Last, so a settings failure cannot leave supervisord out of step with the files.
        $this->settings->transaction(static fn (Settings $s) => $s->forgetUser($user));
    }

    /**
     * Modify pre: between cPanel renaming the account and the post-modify hook, the old name is gone;
     * the marker keeps reconcile() from removing it and lets it finish the rename if the hook is missed.
     * It records the account's identity, which rename() requires of the new name.
     */
    public function prepareRename(string $old, string $new): void
    {
        $this->assertUser($old);
        $this->assertUser($new);
        if (!$this->store->exists($old)) {
            return;
        }
        $identity = ($this->cpuserLoader)($old)?->identity();
        $this->store->withLock($old, function () use ($old, $new, $identity): void {
            if ($this->store->exists($old)) {
                $this->store->mark($old, 'renaming', json_encode(
                    ['to' => $new, 'identity' => $identity?->toArray()],
                    JSON_THROW_ON_ERROR,
                ));
            }
        });
    }

    /**
     * Modify post: moves the account's workers to the new name once cPanel has renamed the system
     * user and moved the home directory. The new name must be the same cPanel account: same identity
     * as recorded in the account file and in the rename marker.
     *
     * @return bool false when cPanel has not completed the rename (nothing is changed then)
     * @throws RuntimeException when the new name is another cPanel account (a stale marker), or when
     *                          it already has worker data: that data belonged to an earlier account of
     *                          the name, so its workers are stopped by administrator. Either way the old
     *                          name's data is kept for root to resolve
     */
    public function rename(string $old, string $new): bool
    {
        $this->assertUser($old);
        $this->assertUser($new);
        if ($old === $new || (!$this->store->exists($old) && $this->settings->override($old) === null)) {
            return true;
        }
        $u = ($this->cpuserLoader)($new);
        if ($u === null || !$this->accountGone($old)) {
            ($this->log)(LOG_WARNING, "Not renaming account '$old' to '$new': "
                . ($u === null ? "'$new' is not a cPanel account" : "'$old' still exists") . '.');

            return false;
        }

        $current = $u->identity();
        [$outcome, $jobs] = $this->store->withLocks([$old, $new], function () use ($old, $new, $u, $current): array {
            if (!$this->store->exists($old)) {
                return ['moved', []];
            }
            $a = $this->store->load($old);
            $marker = $this->store->marker($old, 'renaming');
            foreach (array_filter([$a->identity, $marker === null ? null : self::renameMarker($marker['value'])[1]]) as $expected) {
                if (!$expected->equals($current)) {
                    return [$current === null ? 'unknown' : 'foreign', [$expected]];
                }
            }
            if ($this->store->exists($new)) {
                return ['refused', $this->quarantine($new)];
            }

            // Logs first: remove() below also deletes the logs it finds under the old name.
            $this->logs->moveAccount($old, $new);
            foreach ($a->workers as $w) {
                $this->ini->remove($old, $w->name);
            }
            $this->ini->removeAccount($old);
            $this->store->rename($old, $new);

            $workers = array_map(fn (Worker $w): Worker => $this->rehome($w, $old, $u), $a->workers);
            $renamed = new Account($new, $workers, $a->suspended, $a->featureOff, $current ?? $a->identity);
            $this->store->save($renamed);

            $jobs = [];
            foreach ($renamed->workers as $w) {
                $held = $renamed->held($w) || $u->suspended;
                $runnable = $renamed->runnable($w) && !$held;
                $this->ini->write($renamed, $w, $runnable, $held);
                $jobs[] = [$w->name, $runnable];
            }

            return ['moved', $jobs];
        });

        if ($outcome === 'unknown') {
            ($this->log)(LOG_WARNING, "Not renaming account '$old' to '$new' yet: the uid of '$new' cannot be read.");

            return false;
        }
        if ($outcome === 'foreign') {
            throw new RuntimeException("Not renaming account '$old' to '$new': '$new' is another cPanel account ($current)"
                . " than the one the workers belong to ({$jobs[0]}); the worker data of '$old' is kept for root to resolve.");
        }
        if ($outcome === 'refused') {
            foreach ($jobs as [$name, $was, $iniChanged]) {
                $program = $this->ini->programName($new, $name);
                if ($iniChanged) {
                    $this->sv->apply($program, false);
                } elseif ($was) {
                    $this->sv->stop($program);
                }
            }
            throw new RuntimeException("Not renaming account '$old' to '$new': worker data for '$new' already exists,"
                . ' left by an earlier account of that name; its workers are now stopped by administrator.');
        }

        foreach ($jobs as [$name, $runnable]) {
            $this->sv->remove($this->ini->programName($old, $name));
            $this->sv->apply($this->ini->programName($new, $name), $runnable);
        }
        ($this->log)(LOG_NOTICE, "Renamed account '$old' to '$new' (" . count($jobs) . ' workers)');
        $this->settings->transaction(static fn (Settings $s) => $s->renameUser($old, $new));

        return true;
    }

    /**
     * Errors, removals, stray processes and orphan cleanups are also written to the log.
     *
     * @return array{
     *     removed: list<string>, renamed: list<string>, restored: list<string>, suspended: list<string>,
     *     unsuspended: list<string>, feature_off: list<string>, feature_on: list<string>,
     *     rewritten: list<string>, stopped: list<string>, loaded: list<string>, orphans: list<string>,
     *     features: string, supervisord: string, errors: list<string>
     * } accounts per change ("old -> new" for renamed); rewritten, stopped (ran although not runnable),
     *   loaded (supervisord did not have them) and orphans are program names; features is checked,
     *   unregistered (flags left alone) or failed; supervisord is checked or down (processes not checked)
     */
    public function reconcile(): array
    {
        $report = [
            'removed' => [], 'renamed' => [], 'restored' => [], 'suspended' => [], 'unsuspended' => [],
            'feature_off' => [], 'feature_on' => [], 'rewritten' => [], 'stopped' => [], 'loaded' => [],
            'orphans' => [], 'features' => 'checked', 'supervisord' => 'checked', 'errors' => [],
        ];
        $fail = function (string $message) use (&$report): void {
            $report['errors'][] = $message;
            ($this->log)(LOG_ERR, $message);
        };

        $users = [];
        foreach ($this->store->users() as $user) {
            if (preg_match(self::USER_PATTERN, $user) === 1) {
                $users[] = $user;
            } else {
                $fail("Ignored account file with an invalid name: $user");
            }
        }

        $featureOff = [];
        try {
            if ($this->features->featureRegistered()) {
                $featureOff = array_map(static fn (bool $on): bool => !$on, $this->features->usersWithFeature($users));
            } else {
                $report['features'] = 'unregistered';
            }
        } catch (Throwable $e) {
            $report['features'] = 'failed';
            $fail('Feature check: ' . $e->getMessage());
        }

        // One snapshot for the whole run. With supervisord down every program would look unloaded.
        $procs = null;
        if ($this->sv->version() !== null) {
            $procs = StatusView::byProgram($this->sv->status());
        } else {
            $report['supervisord'] = 'down';
        }

        foreach ($users as $user) {
            $touched = [];
            try {
                if ($this->accountGone($user)) {
                    $this->vanished($user, $report);
                    continue;
                }
                $removing = $this->store->marker($user, 'removing');
                if ($removing === null) {
                    [$changes, $touched] = $this->sync($user, null, $featureOff[$user] ?? null);
                    foreach ($changes as $key => $values) {
                        array_push($report[$key], ...$values);
                    }
                    $renaming = $this->store->marker($user, 'renaming');
                    if ($renaming !== null && $renaming['since'] < time() - self::GRACE) {
                        // The account still exists, so that username change was aborted.
                        $this->store->withLock($user, fn () => $this->store->unmark($user, 'renaming'));
                    }
                } elseif ($removing['since'] < time() - self::GRACE && ($this->cpuserLoader)($user) !== null) {
                    [$changes, $touched] = $this->sync($user, null, $featureOff[$user] ?? null, true);
                    if (isset($changes['errors'])) {
                        array_push($report['errors'], ...$changes['errors']);
                    } elseif ($changes !== []) {
                        $report['restored'][] = $user;
                        ($this->log)(LOG_WARNING, "The removal of account '$user' did not complete; its workers are restored.");
                    }
                }
            } catch (Throwable $e) {
                $fail("$user: " . $e->getMessage());
            }

            if ($procs !== null) {
                try {
                    $this->enforce($user, $procs, $touched, $report);
                } catch (Throwable $e) {
                    $fail("$user: " . $e->getMessage());
                }
            }
        }

        foreach ($this->ini->managed() as [$user, $name]) {
            try {
                $orphan = $this->store->withLock($user, function () use ($user, $name): bool {
                    // Service writes the .ini before saving the account, both under this lock.
                    if (isset($this->store->load($user)->workers[$name])) {
                        return false;
                    }
                    $this->ini->remove($user, $name);

                    return true;
                });
                if ($orphan) {
                    $program = $this->ini->programName($user, $name);
                    $this->sv->remove($program);
                    $report['orphans'][] = $program;
                    ($this->log)(LOG_NOTICE, "Removed orphan program file of '$program' (no such worker).");
                }
            } catch (Throwable $e) {
                $fail("$user: " . $e->getMessage());
            }
        }

        return $report;
    }

    /**
     * An account with a rename marker is never removed: either the rename is finished here (the
     * post-modify hook was missed) or root is told.
     *
     * @param array<string, mixed> $report
     * @throws RuntimeException when a stuck rename needs root
     */
    private function vanished(string $user, array &$report): void
    {
        $renaming = $this->store->marker($user, 'renaming');
        if ($renaming === null) {
            $this->remove($user);
            $report['removed'][] = $user;
            return;
        }

        [$target] = self::renameMarker($renaming['value']);
        if (preg_match(self::USER_PATTERN, $target) === 1 && ($this->cpuserLoader)($target) !== null) {
            if ($this->rename($user, $target)) {
                $report['renamed'][] = "$user -> $target";
            }
            return;
        }
        if ($renaming['since'] < time() - self::GRACE) {
            throw new RuntimeException("Account '$user' is gone but was being renamed to '$target', which is not a"
                . ' cPanel account; its worker data is kept for root to resolve.');
        }
    }

    /**
     * Brings the account's flags, .ini files and supervisord in line. A worker is started or stopped
     * only when its runnable state changes or its .ini had to be rewritten, so a worker that failed
     * or that the client stopped is left alone. Nothing happens while a removal is pending.
     *
     * Workers of an earlier account of the name are held instead and reported as an error (also
     * logged, since the hooks call this too); their data is left for root. An account stored before
     * identities were recorded gets the current one.
     *
     * @param bool|null $suspended null reads cPanel's flag, under the lock so a hook cannot be overtaken
     * @param bool|null $featureOff null keeps the stored flag
     * @param bool $afterRemoval ends a pending removal: every worker counts as stopped, as prepareRemove() left it
     * @return array{0: array<string, list<string>>, 1: list<string>} report entries (none when nothing
     *         was synced), and the programs a supervisord job was sent for
     */
    private function sync(string $user, ?bool $suspended, ?bool $featureOff, bool $afterRemoval = false): array
    {
        $result = $this->store->withLock($user, function () use ($user, $suspended, $featureOff, $afterRemoval): ?array {
            if (!$this->store->exists($user) || (!$afterRemoval && $this->store->marker($user, 'removing') !== null)) {
                return null;
            }
            $u = ($this->cpuserLoader)($user);
            if ($suspended === null) {
                if ($u === null) {
                    return null;
                }
                $suspended = $u->suspended;
            }

            $before = $this->store->load($user);
            $current = $u?->identity();
            if (!$before->ownedBy($current)) {
                if ($current === null) {
                    return null;
                }
                $jobs = [];
                foreach ($before->workers as $w) {
                    $jobs[] = [$w->name, false, false, $this->ini->write($before, $w, false, true)];
                }

                return [$before, $before, $jobs, $current];
            }

            $after = $before->withFlags($suspended, $featureOff ?? $before->featureOff);
            if ($before->identity === null && $current !== null) {
                $after = $after->withIdentity($current);
            }
            if ($after->suspended !== $before->suspended || $after->featureOff !== $before->featureOff
                || $after->identity !== $before->identity) {
                $this->store->save($after);
            }
            if ($afterRemoval) {
                $this->store->unmark($user, 'removing');
            }

            $jobs = [];
            foreach ($after->workers as $w) {
                $now = $after->runnable($w);
                $jobs[] = [$w->name, !$afterRemoval && $before->runnable($w), $now, $this->ini->write($after, $w, $now, $after->held($w))];
            }

            return [$before, $after, $jobs, null];
        });
        if ($result === null) {
            return [[], []];
        }

        [$before, $after, $jobs, $foreign] = $result;
        $change = ['rewritten' => []];
        if ($foreign !== null) {
            $message = "The workers stored for '$user' belong to an earlier account of that name ({$before->identity};"
                . " the account now is $foreign): they are held, never started, and kept for root to resolve.";
            ($this->log)(LOG_ERR, $message);
            $change['errors'] = [$message];
        } elseif ($after->identity !== $before->identity) {
            ($this->log)(LOG_NOTICE, "Recorded the identity of account '$user' ({$after->identity}).");
        }
        $touched = [];
        foreach ($jobs as [$name, $was, $now, $iniChanged]) {
            $program = $this->ini->programName($user, $name);
            if ($iniChanged) {
                // `update` stops a changed group and re-adds it, started only when autostart allows.
                $this->sv->apply($program, $now);
                $change['rewritten'][] = $program;
                $touched[] = $program;
            } elseif ($was !== $now) {
                $now ? $this->sv->apply($program, true) : $this->sv->stop($program);
                $touched[] = $program;
            }
        }

        if ($after->suspended !== $before->suspended) {
            $change[$after->suspended ? 'suspended' : 'unsuspended'] = [$user];
        }
        if ($after->featureOff !== $before->featureOff) {
            $change[$after->featureOff ? 'feature_off' : 'feature_on'] = [$user];
        }

        return [$change, $touched];
    }

    /**
     * Repairs, from the run's status snapshot, what sync() cannot see: a worker that runs although
     * it must not, and a program supervisord has not loaded. Detached jobs are serialized but not
     * ordered, so a start queued before root's stop can run after it (bin/wsv-run then refuses a held
     * worker, but a client stop has no hold). Programs sync() just sent a job for are left to it.
     *
     * @param array<string, list<ProcessStatus>> $procs keyed by program
     * @param list<string> $touched
     * @param array<string, mixed> $report
     */
    private function enforce(string $user, array $procs, array $touched, array &$report): void
    {
        $u = ($this->cpuserLoader)($user);
        if ($u === null) {
            return;
        }
        $wanted = $this->store->withLock($user, function () use ($user, $u): array {
            if (!$this->store->exists($user)) {
                return [];
            }
            try {
                $a = $this->store->load($user);
            } catch (RuntimeException) {
                return []; // sync() has reported it
            }
            $current = $u->identity();
            if (!$a->ownedBy($current) && $current === null) {
                return [];
            }
            $hold = $u->suspended || !$a->ownedBy($current) || $this->store->marker($user, 'removing') !== null;
            $wanted = [];
            foreach ($a->workers as $w) {
                $wanted[$w->name] = $a->runnable($w) && !$hold;
            }

            return $wanted;
        });

        foreach ($wanted as $name => $runnable) {
            $program = $this->ini->programName($user, $name);
            if (in_array($program, $touched, true)) {
                continue;
            }
            if (!isset($procs[$program])) {
                $this->sv->apply($program, $runnable);
                $report['loaded'][] = $program;
                ($this->log)(LOG_NOTICE, "Loaded '$program', which supervisord did not have.");
            } elseif (!$runnable && StatusView::active($procs[$program])) {
                $this->sv->stop($program);
                $report['stopped'][] = $program;
                ($this->log)(LOG_WARNING, "Stopped '$program', which was running although it must not run.");
            }
        }
    }

    /**
     * Marks every worker stopped by administrator and renders it not runnable. Call under the lock.
     *
     * @return list<array{0: string, 1: bool, 2: bool}> name, was runnable, .ini changed
     */
    private function quarantine(string $user): array
    {
        $a = $this->store->load($user);
        $stopped = $a;
        $jobs = [];
        foreach ($a->workers as $w) {
            $held = $w->with(['admin_stopped' => true]);
            $stopped = $stopped->withWorker($held);
            $jobs[] = [$w->name, $a->runnable($w), $this->ini->write($a, $held, false, true)];
        }
        $this->store->save($stopped);

        return $jobs;
    }

    /**
     * @return array{0: string, 1: ?Identity} the new name, and the account's identity when the rename
     *                                         began (null in a marker written before identities were recorded)
     */
    private static function renameMarker(string $value): array
    {
        $data = json_decode($value, true);
        if (!is_array($data)) {
            return [$value, null];
        }
        $identity = is_array($data['identity'] ?? null) ? Identity::fromArray($data['identity']) : null;

        return [is_string($data['to'] ?? null) ? $data['to'] : '', $identity];
    }

    /**
     * cPanel moves the home directory with the username (/home/old to /home/new), and the validator
     * keeps folders inside the home. Commands are the client's own text and are left alone.
     */
    private function rehome(Worker $w, string $old, CpUser $u): Worker
    {
        if ($u->home === '' || $u->home === '/') {
            return $w;
        }
        $oldHome = dirname($u->home) . '/' . $old;
        if ($w->folder !== $oldHome && !str_starts_with($w->folder, $oldHome . '/')) {
            return $w;
        }

        return $w->with(['folder' => $u->home . substr($w->folder, strlen($oldHome))]);
    }

    /** @return list<string> names of the workers whose files were removed */
    private function removeFiles(string $user): array
    {
        return $this->store->withLock($user, function () use ($user): array {
            try {
                $names = array_keys($this->store->load($user)->workers);
            } catch (RuntimeException) {
                // An unreadable account file still leaves files to clean up; the .ini files name them.
                $names = [];
            }
            $names = array_values(array_unique([...$names, ...$this->iniNames($user)]));

            foreach ($names as $name) {
                $this->ini->remove($user, $name);
            }
            $this->ini->removeAccount($user);
            $this->logs->removeAccount($user);
            $this->store->delete($user);

            return $names;
        });
    }

    /** @return list<string> worker names of the account's .ini files */
    private function iniNames(string $user): array
    {
        $names = [];
        foreach ($this->ini->managed() as [$owner, $name]) {
            if ($owner === $user) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function assertUser(string $user): void
    {
        if (preg_match(self::USER_PATTERN, $user) !== 1) {
            throw new InvalidArgumentException('Invalid account name.');
        }
    }
}
