<?php

declare(strict_types=1);

namespace WorkerSupervisor;

use WorkerSupervisor\Supervisor\SupervisorClient;
use InvalidArgumentException;

/**
 * Every action a cPanel user can take on their own workers. Runs as root.
 *
 * $user is authenticated by the caller; everything else is untrusted. Worker names that reach
 * the filesystem or supervisord come from the stored account, never straight from input. Stored
 * workers are only ever shown to or run by the cPanel account they belong to (owned()), not by a
 * later account given the same name.
 * A mutation is one AccountStore transaction, with the .ini and launch files written under its
 * lock so concurrent requests cannot interleave them; supervisord is told only after the account
 * is saved, through calls that return at once.
 */
final class Service
{
    private const FORM_KEYS = [
        'name', 'template', 'folder', 'command', 'processes', 'autostart', 'autorestart',
        'startsecs', 'startretries', 'stopwaitsecs', 'redirect_stderr',
    ];
    private const INT_KEYS = ['processes', 'startsecs', 'startretries', 'stopwaitsecs'];

    /** @var callable(string): ?CpUser */
    private $cpuserLoader;

    private readonly StatusView $view;

    /**
     * @param Paths $p part of the Container contract; nothing here needs it yet
     * @param callable(string): ?CpUser $cpuserLoader
     * @param (callable(): int)|null $now
     */
    public function __construct(
        Paths $p,
        private readonly AccountStore $store,
        private readonly Settings $settings,
        private readonly IniRenderer $ini,
        private readonly SupervisorClient $sv,
        private readonly Logs $logs,
        private readonly WorkerValidator $validator,
        callable $cpuserLoader,
        ?callable $now = null,
    ) {
        $this->cpuserLoader = $cpuserLoader;
        $this->view = new StatusView($now);
    }

    /** @return array{usage: array{processes: int, max_processes: int, workers: int, max_workers: int, over_limit: bool}, workers: list<array>} */
    public function listWorkers(string $user): array
    {
        $u = $this->cpuser($user);
        $limits = LimitResolver::resolve($this->settings, $u);
        $account = $this->load($u);
        $procs = StatusView::byProgram($this->sv->status());

        $rows = [];
        foreach ($account->workers as $w) {
            $rows[] = $this->view->row($w, $account, $procs[$this->ini->programName($user, $w->name)] ?? []);
        }

        return ['usage' => self::usage($account, $limits), 'workers' => $rows];
    }

    /** @return array{processes: int, max_processes: int, workers: int, max_workers: int, over_limit: bool} */
    public static function usage(Account $account, Limits $limits): array
    {
        $processes = (int) array_sum(array_map(static fn (Worker $w) => $w->processes, $account->workers));
        $workers = count($account->workers);

        return [
            'processes' => $processes,
            'max_processes' => $limits->maxProcesses,
            'workers' => $workers,
            'max_workers' => $limits->maxWorkers,
            // A smaller package never stops running workers; it only blocks adding and growing.
            'over_limit' => $processes > $limits->maxProcesses || $workers > $limits->maxWorkers,
        ];
    }

    /** @return array{worker: array<string, mixed>, expert: string} */
    public function getWorker(string $user, string $name): array
    {
        $w = $this->find($this->load($this->cpuser($user)), $name);

        return ['worker' => $w->toArray(), 'expert' => ExpertFormat::render($w)];
    }

    /**
     * Never throws for bad input: problems come back as issues.
     *
     * @param array<string, mixed> $input form: Worker snake_case keys; expert: {template, expert_text}
     * @return array{issues: list<array>, worker: ?array<string, mixed>, expert: ?string}
     */
    public function validate(string $user, array $input, string $mode, ?string $originalName): array
    {
        $u = $this->cpuser($user);
        $account = $this->load($u);
        $original = $originalName === null ? null : $this->find($account, $originalName);
        [$w, $issues] = $this->check($u, $account, $input, $mode, $original);

        return $this->result($w, $issues);
    }

    /**
     * @param array<string, mixed> $input see validate()
     * @return array{issues: list<array>, worker: array<string, mixed>, expert: string} issues hold warnings and info only
     * @throws ServiceError validation_failed, carrying every issue
     */
    public function save(string $user, array $input, string $mode, ?string $originalName): array
    {
        $u = $this->cpuser($user);
        [$old, $new, $issues, $runnable, $iniChanged] = $this->store->transaction(
            $user,
            function (Account $a) use ($u, $user, $input, $mode, $originalName): array {
                $a = $this->owned($a, $u);
                $old = $originalName === null ? null : $this->find($a, $originalName);
                [$w, $issues] = $this->check($u, $a, $input, $mode, $old);
                if ($w === null || array_filter($issues, static fn (Issue $i) => $i->level === 'error') !== []) {
                    throw new ServiceError('validation_failed', [], $issues);
                }

                $a = $a->withWorker($w, $old?->name);
                // Old files go first: if the write below fails, nothing is left that supervisord would load.
                if ($old !== null && $old->name !== $w->name) {
                    $this->ini->remove($user, $old->name);
                }
                // cPanel's suspended flag counts before the suspend hook has updated the account flag.
                $held = $a->held($w) || $u->suspended;
                $runnable = $a->runnable($w) && !$held;

                return [$a, [$old, $w, $issues, $runnable, $this->ini->write($a, $w, $runnable, $held)]];
            },
        );

        $program = $this->ini->programName($user, $new->name);
        $renamed = $old !== null && $old->name !== $new->name;
        if ($renamed) {
            $this->sv->remove($this->ini->programName($user, $old->name));
        }
        if ($old === null || $renamed || $iniChanged) {
            // `update` restarts a changed group by itself.
            $this->sv->apply($program, $runnable);
        } elseif ($runnable && ($old->command !== $new->command || $old->folder !== $new->folder)) {
            // Only the launch file changed: supervisord sees no config change, so it needs a restart.
            $this->sv->restart($program);
        }

        return $this->result($new, $issues);
    }

    public function delete(string $user, string $name): void
    {
        $u = $this->cpuser($user);
        $this->store->transaction($user, function (Account $a) use ($u, $user, $name): array {
            $a = $this->owned($a, $u);
            $this->find($a, $name);
            $this->ini->remove($user, $name);

            return [$a->withoutWorker($name), null];
        });
        $this->sv->remove($this->ini->programName($user, $name));
    }

    /**
     * Always through apply(): a bare start fails for a program supervisord lost or never loaded.
     *
     * @throws ServiceError feature_off, suspended, admin_stopped
     */
    public function start(string $user, string $name): void
    {
        $this->setDesired($user, $name, 'running');
        $this->sv->apply($this->ini->programName($user, $name), true);
    }

    public function stop(string $user, string $name): void
    {
        $this->setDesired($user, $name, 'stopped');
        $this->sv->stop($this->ini->programName($user, $name));
    }

    /**
     * Restarting a stopped worker starts it, so desired becomes running either way.
     *
     * @throws ServiceError feature_off, suspended, admin_stopped
     */
    public function restart(string $user, string $name): void
    {
        $iniChanged = $this->setDesired($user, $name, 'running');
        $program = $this->ini->programName($user, $name);
        if ($iniChanged) {
            $this->sv->apply($program, true);
        } else {
            $this->sv->restart($program);
        }
    }

    /** @return array{text: string, files: list<array{process: string, stream: string, exists: bool}>} */
    public function logs(string $user, string $name, int $process, string $stream, int $lines): array
    {
        $w = $this->find($this->load($this->cpuser($user)), $name);

        return [
            'text' => $this->logs->tail($user, $w, $process, $stream, $lines),
            // Server paths are of no use to a client that can only read logs through this call.
            'files' => array_map(
                static fn (array $f) => array_diff_key($f, ['path' => true]),
                $this->logs->files($user, $w),
            ),
        ];
    }

    public function clearLogs(string $user, string $name): void
    {
        $this->logs->clear($user, $this->find($this->load($this->cpuser($user)), $name));
    }

    /** @return array{templates: list<array{id: string, command: string, fixed_processes: ?int}>, limits: array} */
    public function templates(string $user): array
    {
        $limits = LimitResolver::resolve($this->settings, $this->cpuser($user));
        $list = [];
        foreach (Templates::all() as $t) {
            if (in_array($t->id, $limits->templates, true)) {
                $list[] = ['id' => $t->id, 'command' => $t->command, 'fixed_processes' => $t->fixedProcesses];
            }
        }

        return ['templates' => $list, 'limits' => $limits->toArray()];
    }

    private function cpuser(string $user): CpUser
    {
        $u = ($this->cpuserLoader)($user);
        // The validator keeps folders inside the home; a home of '' or '/' would admit any path.
        if (!$u instanceof CpUser || $u->home === '' || $u->home === '/') {
            throw new ServiceError('account_unknown');
        }

        return $u;
    }

    /** @throws ServiceError account_mismatch */
    private function load(CpUser $u): Account
    {
        return $this->owned($this->store->load($u->user), $u);
    }

    /**
     * Also records the identity of an account that has none yet: a new one, or one stored before
     * identities were recorded.
     *
     * @throws ServiceError account_mismatch when the workers belong to an earlier account of the name
     */
    private function owned(Account $a, CpUser $u): Account
    {
        $current = $u->identity();
        if (!$a->ownedBy($current)) {
            throw new ServiceError('account_mismatch');
        }

        return $a->identity === null && $current !== null ? $a->withIdentity($current) : $a;
    }

    private function find(Account $a, string $name): Worker
    {
        return $a->workers[$name] ?? throw new ServiceError('worker_unknown', ['name' => $name]);
    }

    /** @return bool whether the .ini changed */
    private function setDesired(string $user, string $name, string $desired): bool
    {
        $u = $this->cpuser($user);

        return $this->store->transaction($user, function (Account $a) use ($u, $name, $desired): array {
            $a = $this->owned($a, $u);
            $w = $this->find($a, $name);
            if ($desired === 'running') {
                // cPanel's suspended flag counts even before the account flag has caught up.
                $refusal = match (true) {
                    $a->featureOff => 'feature_off',
                    $a->suspended || $u->suspended => 'suspended',
                    $w->adminStopped => 'admin_stopped',
                    default => null,
                };
                if ($refusal !== null) {
                    throw new ServiceError($refusal, ['name' => $name]);
                }
            }

            $w = $w->with(['desired' => $desired]);
            $a = $a->withWorker($w);
            $held = $a->held($w) || $u->suspended;

            return [$a, $this->ini->write($a, $w, $a->runnable($w) && !$held, $held)];
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: ?Worker, 1: Issue[]}
     */
    private function check(CpUser $u, Account $a, array $input, string $mode, ?Worker $original): array
    {
        [$w, $issues] = match ($mode) {
            'form' => $this->fromForm($input, $original),
            'expert' => $this->fromExpert($input, $original),
            default => [null, [self::inputInvalid('Mode must be form or expert.')]],
        };
        if ($w === null) {
            return [null, $issues];
        }

        $others = array_values($original === null ? $a->workers : array_diff_key($a->workers, [$original->name => true]));

        return [$w, $this->validator->validate($w, $u, LimitResolver::resolve($this->settings, $u), $others, $original)];
    }

    /**
     * desired and admin_stopped are not form keys: an edit keeps the stored values, a new worker
     * gets the defaults. Keys missing from an edit keep their stored values too.
     *
     * @return array{0: ?Worker, 1: Issue[]}
     */
    private function fromForm(array $input, ?Worker $original): array
    {
        $fields = array_intersect_key($input, array_flip(self::FORM_KEYS));

        // Worker::fromArray casts, so garbage would otherwise become 0 silently.
        $issues = [];
        foreach ($fields as $key => $value) {
            $integer = is_int($value) || (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1);
            if (!is_scalar($value) || (in_array($key, self::INT_KEYS, true) && !$integer)) {
                $issues[] = new Issue('error', $key, 'value_invalid', ['field' => $key]);
            }
        }
        if ($issues !== []) {
            return [null, $issues];
        }

        try {
            return [Worker::fromArray($fields + ($original?->toArray() ?? [])), []];
        } catch (InvalidArgumentException $e) {
            return [null, [self::inputInvalid($e->getMessage())]];
        }
    }

    /**
     * The template is not part of the [program:x] text, so it travels beside it.
     *
     * @return array{0: ?Worker, 1: Issue[]}
     */
    private function fromExpert(array $input, ?Worker $original): array
    {
        $text = $input['expert_text'] ?? '';
        $template = $input['template'] ?? $original?->template ?? '';

        $issues = [];
        foreach (['expert_text' => $text, 'template' => $template] as $key => $value) {
            if (!is_string($value)) {
                $issues[] = new Issue('error', $key, 'value_invalid', ['field' => $key]);
            }
        }
        if ($issues !== []) {
            return [null, $issues];
        }

        $base = $original?->with(['template' => $template]) ?? new Worker('', $template, '', '');
        $parsed = ExpertFormat::parse($text, $base);

        return [$parsed->worker, $parsed->issues];
    }

    /** @param Issue[] $issues */
    private function result(?Worker $w, array $issues): array
    {
        return [
            'issues' => array_map(static fn (Issue $i) => $i->toArray(), $issues),
            'worker' => $w?->toArray(),
            'expert' => $w === null ? null : ExpertFormat::render($w),
        ];
    }

    private static function inputInvalid(string $message): Issue
    {
        return new Issue('error', 'input', 'input_invalid', ['message' => $message]);
    }
}
