<?php

declare(strict_types=1);

namespace WorkerSupervisor;

use WorkerSupervisor\Supervisor\SupervisorClient;
use RuntimeException;

/**
 * Root's actions from WHM, on any account.
 *
 * $user and $name arrive from a browser form: the user is checked against the cPanel username
 * pattern before it reaches a path, the worker name only ever matches a stored worker. An admin
 * stop leaves `desired` alone, so a release restores whatever the client had chosen; release is
 * the only way to clear the flag (start and restart refuse an admin-stopped worker like a client's).
 */
final class AdminService
{
    private const USER_PATTERN = '/^[a-z][a-z0-9]{0,15}$/D';
    private const LIMIT_KEYS = ['max_processes', 'max_workers', 'max_stopwaitsecs'];

    /** @var callable(string): ?CpUser */
    private $cpuserLoader;

    private readonly StatusView $view;

    /**
     * @param callable(string): ?CpUser $cpuserLoader
     * @param (callable(): int)|null $now
     */
    public function __construct(
        private readonly Service $svc,
        private readonly AccountStore $store,
        private readonly Settings $settings,
        private readonly SupervisorClient $sv,
        callable $cpuserLoader,
        private readonly IniRenderer $ini,
        ?callable $now = null,
    ) {
        $this->cpuserLoader = $cpuserLoader;
        $this->view = new StatusView($now);
    }

    /**
     * Rows are sorted by account, then in each account's worker order.
     *
     * @return array{
     *     health: array{running: bool, version: ?string, workers: int, processes: int, running_processes: int, failed: int},
     *     rows: list<array<string, mixed>>,
     *     unreadable: list<string>
     * } rows: `user`, `over_limit` (the account's), then StatusView::row; unreadable: accounts whose file is corrupt
     */
    public function overview(): array
    {
        $version = $this->sv->version();
        $procs = $version === null ? [] : StatusView::byProgram($this->sv->status());

        $rows = [];
        $unreadable = [];
        foreach ($this->store->users() as $user) {
            try {
                $account = $this->store->load($user);
            } catch (RuntimeException) {
                // One corrupt file must not hide every other account from root.
                $unreadable[] = $user;
                continue;
            }
            $u = ($this->cpuserLoader)($user);
            $overLimit = $u !== null && Service::usage($account, LimitResolver::resolve($this->settings, $u))['over_limit'];
            foreach ($account->workers as $w) {
                $procsOfWorker = $procs[$this->ini->programName($user, $w->name)] ?? [];
                $rows[] = ['user' => $user, 'over_limit' => $overLimit] + $this->view->row($w, $account, $procsOfWorker);
            }
        }

        return [
            'health' => [
                'running' => $version !== null,
                'version' => $version,
                'workers' => count($rows),
                'processes' => (int) array_sum(array_column($rows, 'processes')),
                'running_processes' => (int) array_sum(array_column($rows, 'running')),
                'failed' => count(array_keys(array_column($rows, 'status'), 'failed', true)),
            ],
            'rows' => $rows,
            'unreadable' => $unreadable,
        ];
    }

    /** @throws ServiceError account_unknown, worker_unknown */
    public function adminStop(string $user, string $name): void
    {
        $this->assertUser($user);
        $this->store->transaction($user, function (Account $a) use ($name): array {
            $w = $this->find($a, $name)->with(['admin_stopped' => true]);
            $a = $a->withWorker($w);
            $this->ini->write($a, $w, false, true);

            return [$a, null];
        });
        $this->sv->stop($this->ini->programName($user, $name));
    }

    /**
     * @return bool whether the worker is being started (false when the client had stopped it, the
     *              account cannot run workers, or the workers belong to an earlier account of the name)
     * @throws ServiceError account_unknown, worker_unknown
     */
    public function release(string $user, string $name): bool
    {
        $this->assertUser($user);
        $u = ($this->cpuserLoader)($user);
        [$start, $iniChanged] = $this->store->transaction($user, function (Account $a) use ($name, $u): array {
            $w = $this->find($a, $name)->with(['admin_stopped' => false]);
            $a = $a->withWorker($w);
            // As for a client start, cPanel's suspended flag counts before the account flag has caught up.
            $held = $a->held($w) || $u === null || $u->suspended || !$a->ownedBy($u->identity());
            $start = $a->runnable($w) && !$held;

            return [$a, [$start, $this->ini->write($a, $w, $start, $held)]];
        });

        // apply() rather than a bare start, which fails for a program supervisord has not loaded.
        if ($start || $iniChanged) {
            $this->sv->apply($this->ini->programName($user, $name), $start);
        }

        return $start;
    }

    /** @throws ServiceError account_unknown, worker_unknown, feature_off, suspended, admin_stopped */
    public function start(string $user, string $name): void
    {
        $this->assertUser($user);
        $this->svc->start($user, $name);
    }

    /** @throws ServiceError account_unknown, worker_unknown, feature_off, suspended, admin_stopped */
    public function restart(string $user, string $name): void
    {
        $this->assertUser($user);
        $this->svc->restart($user, $name);
    }

    /**
     * @return array{ini: string, expert: string} the .ini rendered for the worker's current state,
     *                                            and the worker as expert text (folder and command live there)
     * @throws ServiceError account_unknown, worker_unknown
     */
    public function config(string $user, string $name): array
    {
        $this->assertUser($user);
        $a = $this->store->load($user);
        $w = $this->find($a, $name);

        return ['ini' => $this->ini->renderIni($user, $w, $a->runnable($w)), 'expert' => ExpertFormat::render($w)];
    }

    /** @return array{text: string, files: list<array{process: string, stream: string, exists: bool}>} */
    public function logs(string $user, string $name, int $process, string $stream, int $lines): array
    {
        $this->assertUser($user);

        return $this->svc->logs($user, $name, $process, $stream, $lines);
    }

    /** @return array{defaults: array<string, mixed>, overrides: array<string, array<string, mixed>>} as stored, overrides keyed by user */
    public function settings(): array
    {
        return ['defaults' => $this->settings->defaults()->toArray(), 'overrides' => $this->settings->overrides()];
    }

    /**
     * @return array{max_processes: int, max_workers: int, max_stopwaitsecs: int, templates: string[]}
     *         what the account gets now: override, else package, else server defaults
     * @throws ServiceError account_unknown
     */
    public function limits(string $user): array
    {
        $this->assertUser($user);

        return $this->svc->templates($user)['limits'];
    }

    /**
     * @param array<string, mixed> $input max_processes, max_workers, max_stopwaitsecs (integers >= 1,
     *                                    as int or digit string) and templates (list of template ids); all required
     * @throws ServiceError validation_failed
     */
    public function setDefaults(array $input): void
    {
        $issues = [];
        $limits = [];
        foreach (self::LIMIT_KEYS as $key) {
            $limits[$key] = self::limit($input[$key] ?? null) ?? self::invalid($issues, $key);
        }
        $limits['templates'] = self::templates($input['templates'] ?? null, $issues);
        if ($issues !== []) {
            throw new ServiceError('validation_failed', [], $issues);
        }

        $this->settings->transaction(static fn (Settings $s) => $s->setDefaults(Limits::fromArray($limits)));
    }

    /**
     * @param ?array<string, mixed> $input null removes the override; otherwise as setDefaults, except
     *                                     that a blank or missing limit and a null or missing templates
     *                                     list are not overridden (all of them: no override)
     * @throws ServiceError account_unknown, validation_failed
     */
    public function setOverride(string $user, ?array $input): void
    {
        $this->assertUser($user);
        $override = null;
        if ($input !== null) {
            // Removing stays possible for an account that is gone, so stale overrides can be cleaned up.
            if (($this->cpuserLoader)($user) === null) {
                throw new ServiceError('account_unknown');
            }
            $issues = [];
            $override = [];
            foreach (self::LIMIT_KEYS as $key) {
                $raw = $input[$key] ?? null;
                if ($raw !== null && !(is_string($raw) && trim($raw) === '')) {
                    $override[$key] = self::limit($raw) ?? self::invalid($issues, $key);
                }
            }
            if (($input['templates'] ?? null) !== null) {
                $override['templates'] = self::templates($input['templates'], $issues);
            }
            if ($issues !== []) {
                throw new ServiceError('validation_failed', [], $issues);
            }
        }

        $this->settings->transaction(static fn (Settings $s) => $s->setOverride($user, $override === [] ? null : $override));
    }

    private function assertUser(string $user): void
    {
        if (preg_match(self::USER_PATTERN, $user) !== 1) {
            throw new ServiceError('account_unknown');
        }
    }

    private function find(Account $a, string $name): Worker
    {
        return $a->workers[$name] ?? throw new ServiceError('worker_unknown', ['name' => $name]);
    }

    private static function limit(mixed $value): ?int
    {
        if (is_string($value)) {
            // Nine digits keep the cast clear of integer overflow.
            if (preg_match('/^\d{1,9}$/D', trim($value)) !== 1) {
                return null;
            }
            $value = (int) trim($value);
        }

        return is_int($value) && $value >= 1 ? $value : null;
    }

    /**
     * @param Issue[] $issues appended to
     * @return string[] known ids, deduplicated, in catalog order
     */
    private static function templates(mixed $value, array &$issues): array
    {
        if (!is_array($value) || array_filter($value, static fn ($id) => !is_string($id)) !== []) {
            self::invalid($issues, 'templates');

            return [];
        }

        $known = array_map(static fn (Template $t) => $t->id, Templates::all());
        foreach (array_unique(array_diff($value, $known)) as $id) {
            $issues[] = new Issue('error', 'templates', 'template_unknown', ['template' => $id]);
        }

        return array_values(array_intersect($known, $value));
    }

    /**
     * Records the issue and returns a placeholder; the caller throws before using it.
     *
     * @param Issue[] $issues appended to
     */
    private static function invalid(array &$issues, string $field): int
    {
        $issues[] = new Issue('error', $field, 'value_invalid', ['field' => $field]);

        return 0;
    }
}
