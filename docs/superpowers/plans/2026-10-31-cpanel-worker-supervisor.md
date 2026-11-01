# Worker Supervisor for cPanel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A cPanel/WHM plugin that lets cPanel clients create, edit, delete, start, stop and read logs of their own supervisord workers, scoped to their account and CloudLinux cage, with root control from WHM.

**Architecture:** A dependency-free PHP core (validation, limits, storage, `.ini` generation, `supervisorctl`, logs) runs as root with cPanel's bundled PHP. cPanel users reach it only through a custom UAPI module and a Perl admin module that forward JSON to the core with the caller's username; WHM pages and hooks call the core directly as root. Supervisord starts each worker through a root-owned bash launcher that enters the account with `cagefs_enter_user --no-fork`.

**Tech Stack:** PHP 8.1+ (runs on `/usr/local/cpanel/3rdparty/bin/php`, 8.4 on the VM), PHPUnit 11 (dev only, run on host PHP 8.5), Perl glue (cPanel `Cpanel::API`, `Cpanel::Admin::Base`), bash, vanilla JS/CSS (no build step), supervisor 4.2.5 (EPEL), cPanel 11.138 Jupiter, CloudLinux 9.

**Spec:** `docs/superpowers/specs/2026-10-31-cpanel-worker-supervisor-design.md`

**cPanel mechanism reference** (official-doc research, 2026-10-31): the cPanel developer docs at https://api.docs.cpanel.net/. Facts used by this plan are restated in the tasks.

## Global Constraints

- Supported servers: cPanel on CloudLinux 8 and 9 only; `install.sh` refuses a server without `/usr/sbin/cagefs_enter_user`, `/usr/sbin/lvectl` and a `CloudLinux` `/etc/redhat-release`.
- Supervisor from EPEL (`dnf install supervisor`), service `supervisord` enabled; managed files `/etc/supervisord.d/wsv_<user>_<name>.ini`; socket `/run/supervisor/supervisor.sock`.
- Feature name `worker_supervisor`; UAPI module `WorkerSupervisor`; admin namespace `WorkerSupervisor`, module `Workers`; display name "Worker Supervisor".
- Supervisor program name `<user>_<name>` (cPanel usernames contain no `_`).
- Client-supplied text (command, folder) never appears in a supervisor `.ini`.
- Logs: `/var/log/worker_supervisor/<user>/` (root:root 0700), never inside a home.
- Workers start through `/usr/sbin/cagefs_enter_user --no-fork <user> /bin/bash -lc ...` with `env -i HOME USER LOGNAME SHELL=/bin/bash PATH=/usr/local/bin:/usr/bin:/bin`.
- Plugin-owned `.ini` keys (never client-editable): `user`, `process_name`, `stdout_logfile*`, `stderr_logfile*`, `stopasgroup`, `killasgroup`, `stopsignal`, program name.
- Client-editable keys: `command`, `directory` (folder), `numprocs`, `autostart`, `autorestart` (`true|unexpected|false`), `startsecs`, `startretries`, `stopwaitsecs`, `redirect_stderr`.
- Server default limits (initial values, root can change): max processes 10, max workers 5, max stop wait 3600 s, all templates allowed.
- Templates: `laravel-queue`, `laravel-horizon`, `symfony-messenger`, `custom`.
- Limit precedence: per-account override > package extension values (only when the account's `OWNER=root`) > server defaults.
- cPanel UI in English and French by the user's locale (UAPI `Locale::get_attributes`, match on prefix). WHM pages in English only (no documented way to read root's locale).
- Code, identifiers, comments, commit messages in English; ASCII punctuation in code. French only in `lang/fr.php`.
- License MIT, `Copyright (c) 2026 Gilbert Paquin`.
- Never block a cPanel/WHM request on a supervisor stop: stop/restart/update run detached (`setsid -f`).

## Review Focus

1. A command containing `"`, `'`, `%`, `$VAR`, `;` or `&&` (e.g. `php artisan queue:work --queue="high,default"`) must run exactly as typed and cannot alter the supervisor config - test in Task 7 (renderer + launcher VM test).
2. A folder that is a symlink to outside the home (`ln -s /etc ~/app`), contains `..`, or has spaces must be rejected / accepted correctly - test in Task 4.
3. Stopping or restarting a worker with `stopwaitsecs=3600` while a job runs must return to the UI immediately with "Stopping" - test in Task 10.
4. An account terminated or renamed while its workers run must leave no supervisor program, `.ini`, launch file or log dir behind (reconcile also catches missed hooks) - test in Task 18.
5. Expert text with CRLF line endings, duplicate keys, a missing `[program:x]` header or a second section must produce line-numbered errors, never an exception - test in Task 5.

---

## Phase 1 - Core (host unit tests)

### Task 1: Repository scaffold and Paths

**Files:**
- Create: `.gitignore`, `LICENSE`, `README.md` (stub), `composer.json`, `phpunit.xml`, `src/Paths.php`, `tests/Unit/PathsTest.php`
- Existing (commit as-is): `dev/vm/vm.sh`, `docs/superpowers/specs/...`, `docs/superpowers/plans/...`

**Interfaces:**
- Produces: `WorkerSupervisor\Paths` (PSR-4 `WorkerSupervisor\` => `src/`), constructed `new Paths(string $root = '')`; every method returns `$root . <absolute path>`:
  `appDir()` `/usr/local/cpanel/3rdparty/worker_supervisor`, `dataDir()` `/var/cpanel/worker_supervisor`, `accountFile(string $user)` `<data>/accounts/<user>.json`, `launchFile(string $user, string $name)` `<data>/launch/<user>/<name>`, `settingsFile()` `<data>/settings.json`, `backupDir()` `<data>/backups`, `logDir(string $user)` `/var/log/worker_supervisor/<user>`, `iniFile(string $user, string $name)` `/etc/supervisord.d/wsv_<user>_<name>.ini`, `iniDir()` `/etc/supervisord.d`, `cpuserFile(string $user)` `/var/cpanel/users/<user>`, `suspendedFlag(string $user)` `/var/cpanel/suspended/<user>`, `launcher()` `<app>/bin/wsv-run`.

- [ ] **Step 1:** `git init`; `.gitignore` with `.superpowers/`, `vendor/`, `build/`, `.phpunit.cache/`; MIT `LICENSE`; `composer.json` (`"require": {"php": ">=8.1"}`, `"require-dev": {"phpunit/phpunit": "^11"}`, PSR-4 autoload for `src/` and `tests/`); `phpunit.xml` (suite `Unit` = `tests/Unit`). Run `composer install`.
- [ ] **Step 2: Write the failing test** `PathsTest::test_paths_are_prefixed_by_root` asserting `(new Paths('/tmp/x'))->iniFile('bob','queue') === '/tmp/x/etc/supervisord.d/wsv_bob_queue.ini'` and `launchFile('bob','queue') === '/tmp/x/var/cpanel/worker_supervisor/launch/bob/queue'`.
- [ ] **Step 3:** Run `vendor/bin/phpunit --filter PathsTest` - expect FAIL (class not found).
- [ ] **Step 4:** Implement `src/Paths.php`.
- [ ] **Step 5:** Run `vendor/bin/phpunit` - expect PASS.
- [ ] **Step 6: Commit** `chore: scaffold repository, test harness and runtime paths` (include the spec, plan and `dev/vm/vm.sh`).

### Task 2: Worker model and template catalog

**Files:**
- Create: `src/Worker.php`, `src/Template.php`, `src/Templates.php`, `tests/Unit/WorkerTest.php`, `tests/Unit/TemplatesTest.php`

**Interfaces:**
- Produces: `final class Worker` (readonly public props): `string $name, string $template, string $folder, string $command, int $processes, bool $autostart, string $autorestart, int $startsecs, int $startretries, int $stopwaitsecs, bool $redirectStderr, string $desired /* running|stopped */, bool $adminStopped`. `Worker::fromArray(array $a): Worker` (snake_case keys, missing keys take defaults below), `toArray(): array`, `with(array $changes): Worker`.
- Defaults: `processes 1, autostart true, autorestart 'true', startsecs 10, startretries 30, stopwaitsecs 3600, redirect_stderr true, desired 'running', admin_stopped false`.
- Produces: `final class Template` (readonly): `string $id, string $command, string[] $requiredPaths, ?int $fixedProcesses, bool $laravelRestart, ?string $memoryFlagPattern`. `Templates::all(): Template[]`, `Templates::get(string $id): ?Template`.

| id | command | requiredPaths | fixedProcesses | laravelRestart | memoryFlagPattern |
|---|---|---|---|---|---|
| `laravel-queue` | `php artisan queue:work --sleep=3 --tries=3 --max-time=3600` | `artisan` | null | true | `/--memory=(\d+)/` (MB) |
| `laravel-horizon` | `php artisan horizon` | `artisan`, `vendor/laravel/horizon` | 1 | true | null |
| `symfony-messenger` | `php bin/console messenger:consume async --time-limit=3600` | `bin/console` | null | false | `/--memory-limit=(\d+)M/i` |
| `custom` | `` | (none) | null | false | null |

- [ ] **Step 1: Write failing tests:** `test_from_array_applies_defaults` (only name/template/folder/command given -> `processes===1`, `stopwaitsecs===3600`, `desired==='running'`); `test_round_trip` (`Worker::fromArray($w->toArray()) == $w`); `test_catalog_ids` (`array_map(fn($t)=>$t->id, Templates::all()) === ['laravel-queue','laravel-horizon','symfony-messenger','custom']`); `test_horizon_is_single_process` (`Templates::get('laravel-horizon')->fixedProcesses === 1`).
- [ ] **Step 2:** Run `vendor/bin/phpunit --filter 'WorkerTest|TemplatesTest'` - FAIL.
- [ ] **Step 3:** Implement the three classes.
- [ ] **Step 4:** Run - PASS.
- [ ] **Step 5: Commit** `feat(core): add worker model and template catalog`.

### Task 3: Account facts, settings and limit resolution

**Files:**
- Create: `src/CpUser.php`, `src/Limits.php`, `src/Settings.php`, `src/LimitResolver.php`, `tests/Unit/LimitResolverTest.php`, `tests/Unit/CpUserTest.php`, `tests/fixtures/cpusers/{plain,withext,reseller}`

**Interfaces:**
- Produces: `CpUser::load(Paths $p, string $user, callable $passwdLookup = 'posix_getpwnam'): ?CpUser` (null when the cpuser file is missing). Readonly props: `string $user, string $home, string $owner, string $plan, string $featureList, bool $suspended /* suspendedFlag exists */, array $ext /* worker_supervisor_* keys, only when _PACKAGE_EXTENSIONS lists worker_supervisor */`.
- Produces: `final class Limits` (readonly): `int $maxProcesses, int $maxWorkers, int $maxStopwaitsecs, string[] $templates`; `Limits::serverDefaults(): Limits` (10, 5, 3600, all four ids).
- Produces: `Settings::load(Paths $p): Settings`, `save(): void`, `defaults(): Limits`, `setDefaults(Limits $l)`, `override(string $user): ?array` (partial: any of `max_processes|max_workers|max_stopwaitsecs|templates`), `setOverride(string $user, ?array $o)`, `renameUser(string $old, string $new)`, `forgetUser(string $user)`. Missing file -> server defaults, no overrides.
- Produces: `LimitResolver::resolve(Settings $s, CpUser $u): Limits`.
- Package extension keys read from `$ext`: `worker_supervisor_max_processes`, `worker_supervisor_max_workers`, `worker_supervisor_max_stopwaitsecs`, `worker_supervisor_tpl_laravel_queue`, `worker_supervisor_tpl_laravel_horizon`, `worker_supervisor_tpl_symfony_messenger`, `worker_supervisor_tpl_custom` (`1`/`0`).

- [ ] **Step 1: Write failing tests** (fixtures are `key=value` cpuser files under a temp root built in `setUp`):
  - `test_defaults_when_no_extension_and_no_override` -> `maxProcesses===10`.
  - `test_root_owned_package_extension_applies` (fixture `withext`: `OWNER=root`, `_PACKAGE_EXTENSIONS=worker_supervisor`, `worker_supervisor_max_processes=20`, `worker_supervisor_tpl_custom=0`) -> `maxProcesses===20`, `!in_array('custom', $l->templates)`.
  - `test_reseller_owned_package_is_ignored` (fixture `reseller`: `OWNER=resellerx`, same ext values) -> `maxProcesses===10`.
  - `test_override_beats_extension` (override `['max_processes'=>3]` on `withext`) -> `3`; other fields still from extension.
  - `test_cpuser_reads_suspended_flag_and_home` (create `suspendedFlag`, stub passwd lookup returning `['dir'=>'/home/bob']`).
- [ ] **Step 2:** Run `vendor/bin/phpunit --filter 'LimitResolverTest|CpUserTest'` - FAIL.
- [ ] **Step 3:** Implement. `Settings` writes JSON atomically (temp file + `rename`), mode 0600.
- [ ] **Step 4:** Run - PASS.
- [ ] **Step 5: Commit** `feat(core): resolve per-account limits from overrides, package extension and defaults`.

### Task 4: Worker validation

**Files:**
- Create: `src/Issue.php`, `src/WorkerValidator.php`, `src/AccountProbe.php` (interface), `tests/Unit/WorkerValidatorTest.php`

**Interfaces:**
- Consumes: `Worker`, `Templates`, `Limits`, `CpUser`.
- Produces: `final class Issue` (readonly): `string $level /* error|warning|info */, string $field, string $code, array $params = [], ?int $line = null`; `toArray()`; `public const CODES` = every code below (Task 5 appends its codes; Task 13 tests that each has a message).
- Produces: `interface AccountProbe { public function memoryLimitMb(string $user): ?int; public function phpVersion(string $user, string $folder): ?string; }` (real impl in Task 10).
- Produces: `WorkerValidator::__construct(AccountProbe $probe)`; `validate(Worker $w, CpUser $u, Limits $l, Worker[] $others): Issue[]` where `$others` are the account's other workers (excluding the one being edited).
- Error codes (field): `name_invalid` (name, regex `^[a-z0-9][a-z0-9-]{0,31}$`), `name_taken` (name), `template_unknown`, `template_not_allowed` (template), `folder_outside_home`, `folder_missing`, `required_path_missing` {path} (folder), `command_empty`, `command_invalid` (command: longer than 2000 chars, contains `\n`, `\r` or NUL), `processes_range` {max} (processes: 1..remaining = `maxProcesses - sum(others.processes)`), `processes_fixed` {value} (Horizon != 1), `workers_limit` {max} (new worker when `count(others) >= maxWorkers`), `stopwait_range` {max} (1..maxStopwaitsecs), `startsecs_range` (0..3600), `startretries_range` (0..100), `autorestart_invalid`.
- Warning codes: `memory_over_limit` {total_mb, limit_mb} (template memory flag x processes > `memoryLimitMb`), `laravel_needs_autorestart` (laravelRestart template and autorestart != `true`).
- Info code: `php_version` {version} (from `phpVersion`, when non-null).
- Folder rule: `realpath($folder)` must equal `$u->home` or start with `$u->home . '/'`; checks run only when the folder exists.

- [ ] **Step 1: Write failing tests** using a temp home and a stub `AccountProbe`:
  - `test_valid_laravel_worker_has_no_errors` (folder with `artisan`) -> no `error` issues, one `info` `php_version`.
  - `test_symlink_escaping_home_is_rejected` (`symlink('/etc', "$home/app")`) -> code `folder_outside_home`.
  - `test_dotdot_folder_is_rejected` (`"$home/app/../../etc"`) -> `folder_outside_home`.
  - `test_folder_with_spaces_is_accepted` (`"$home/my app"` with `artisan`) -> no errors.
  - `test_missing_artisan` -> `required_path_missing` with `params['path']==='artisan'`.
  - `test_processes_count_against_other_workers` (others use 8 of 10, request 3) -> `processes_range` with `params['max']===2`.
  - `test_horizon_requires_one_process` -> `processes_fixed`.
  - `test_memory_warning` (command `--memory=1024`, processes 5, probe limit 2048) -> warning `memory_over_limit` `total_mb===5120`.
  - `test_command_with_newline_is_rejected` -> `command_invalid`.
  - `test_name_taken` and `test_workers_limit`.
- [ ] **Step 2:** Run `vendor/bin/phpunit --filter WorkerValidatorTest` - FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run - PASS.
- [ ] **Step 5: Commit** `feat(core): validate workers against account limits and folder rules`.

### Task 5: Expert text format

**Files:**
- Create: `src/ExpertFormat.php`, `src/ParseResult.php`, `tests/Unit/ExpertFormatTest.php`

**Interfaces:**
- Consumes: `Worker`, `Issue`.
- Produces: `ExpertFormat::render(Worker $w): string` - exactly:
  ```
  [program:<name>]
  command=<command>
  directory=<folder>
  numprocs=<processes>
  autostart=<true|false>
  autorestart=<true|unexpected|false>
  startsecs=<n>
  startretries=<n>
  stopwaitsecs=<n>
  redirect_stderr=<true|false>
  ```
- Produces: `ExpertFormat::parse(string $text, Worker $base): ParseResult` with readonly `?Worker $worker` (base merged with parsed values, null on any error) and `Issue[] $issues` (all `field='expert'`, `line` set).
- Parse error codes (append to `Issue::CODES`): `expert_no_header`, `expert_extra_section`, `expert_syntax` (line without `=`), `expert_key_forbidden` {key} (plugin-owned keys from Global Constraints), `expert_key_unknown` {key}, `expert_key_duplicate` {key}, `expert_value_invalid` {key} (non-integer for integer keys, not `true|false` for booleans). Accepts `\r\n`, blank lines, `;`/`#` comment lines, surrounding spaces.

- [ ] **Step 1: Write failing tests:** `test_render_then_parse_round_trips`; `test_crlf_is_accepted`; `test_forbidden_user_key_reports_line` (`user=root` on line 9 -> `expert_key_forbidden`, `line===9`, `params['key']==='user'`); `test_duplicate_key`; `test_missing_header`; `test_second_section`; `test_non_integer_numprocs`; `test_unknown_key`; `test_never_throws_on_garbage` (random bytes -> issues, no exception).
- [ ] **Step 2:** Run `vendor/bin/phpunit --filter ExpertFormatTest` - FAIL.
- [ ] **Step 3:** Implement (line-based parser; do not use `parse_ini_string`, it interprets values).
- [ ] **Step 4:** Run - PASS.
- [ ] **Step 5: Commit** `feat(core): add expert text format with line-numbered errors`.

### Task 6: Account store

**Files:**
- Create: `src/Account.php`, `src/AccountStore.php`, `tests/Unit/AccountStoreTest.php`

**Interfaces:**
- Produces: `final class Account`: `string $user`, `array<string,Worker> $workers` (keyed by name), `bool $suspended`, `bool $featureOff`; `withWorker(Worker $w, ?string $replacing = null): Account`, `withoutWorker(string $name): Account`, `runnable(Worker $w): bool` (= `desired==='running' && !adminStopped && !suspended && !featureOff`).
- Produces: `AccountStore::__construct(Paths $p)`; `load(string $user): Account` (empty account if no file); `save(Account $a): void` (atomic, 0600, JSON `{"version":1,"suspended":..,"feature_off":..,"workers":[...]}`); `transaction(string $user, callable $fn): mixed` (exclusive `flock` on `<accountFile>.lock`, passes loaded `Account`, saves the returned `Account`, returns `$fn`'s second return value - signature `fn(Account $a): array{0: Account, 1: mixed}`); `delete(string $user)`; `rename(string $old, string $new)`; `users(): string[]`.

- [ ] **Step 1: Write failing tests:** `test_load_missing_returns_empty`; `test_save_and_load_round_trip`; `test_file_mode_is_0600`; `test_transaction_saves_returned_account`; `test_rename_moves_file`; `test_runnable_rules` (each of adminStopped / suspended / featureOff / desired stopped -> false).
- [ ] **Step 2:** Run `vendor/bin/phpunit --filter AccountStoreTest` - FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run - PASS.
- [ ] **Step 5: Commit** `feat(core): persist account worker data with locking`.

### Task 7: Supervisor config renderer, launcher, installer v1 and VM deploy

**Files:**
- Create: `src/IniRenderer.php`, `bin/wsv-run`, `install.sh`, `dev/deploy.sh`, `tests/Unit/IniRendererTest.php`, `tests/vm/launcher.sh`

**Interfaces:**
- Consumes: `Paths`, `Worker`, `Account::runnable`.
- Produces: `IniRenderer::__construct(Paths $p)`; `programName(string $user, string $name): string` (`<user>_<name>`); `renderIni(string $user, Worker $w, bool $runnable): string`; `renderLaunch(Worker $w): string` (`<folder>\n<command>\n`); `write(string $user, Worker $w, bool $runnable): bool` (writes ini 0644 and launch file 0600 under a 0700 dir, creates `logDir` 0700; returns true when the ini content changed); `remove(string $user, string $name): void` (ini, launch file, logs of that worker).
- `.ini` content (exact; `<n>` = `%(process_num)02d`):
  ```
  ; Managed by Worker Supervisor - do not edit, changes are overwritten.
  [program:<user>_<name>]
  command=/usr/local/cpanel/3rdparty/worker_supervisor/bin/wsv-run <user> <name>
  process_name=%(program_name)s_%(process_num)02d
  numprocs=<processes>
  user=root
  autostart=<autostart && runnable>
  autorestart=<autorestart>
  startsecs=<startsecs>
  startretries=<startretries>
  stopwaitsecs=<stopwaitsecs>
  stopsignal=TERM
  stopasgroup=true
  killasgroup=true
  redirect_stderr=<true|false>
  stdout_logfile=/var/log/worker_supervisor/<user>/<name>_%(process_num)02d.log
  stdout_logfile_maxbytes=10MB
  stdout_logfile_backups=3
  ```
  plus, only when `redirect_stderr=false`: `stderr_logfile=/var/log/worker_supervisor/<user>/<name>_%(process_num)02d.err.log`, `stderr_logfile_maxbytes=10MB`, `stderr_logfile_backups=3`.
- `bin/wsv-run` (root, 0755) - exact body, the quoting is the security boundary:
  ```bash
  #!/bin/bash
  # Started by supervisord as root. Args are plugin-generated; folder and command come
  # from the root-only launch file and are passed to bash as positional parameters, so
  # supervisord never parses client text.
  set -euo pipefail
  user=$1 name=$2
  [[ $user =~ ^[a-z][a-z0-9]{0,15}$ && $name =~ ^[a-z0-9][a-z0-9-]{0,31}$ ]] || exit 64
  launch=/var/cpanel/worker_supervisor/launch/$user/$name
  { IFS= read -r folder; IFS= read -r command; } < "$launch"
  home=$(getent passwd "$user" | cut -d: -f6)
  exec /usr/bin/env -i HOME="$home" USER="$user" LOGNAME="$user" SHELL=/bin/bash \
    PATH=/usr/local/bin:/usr/bin:/bin LANG=C.UTF-8 \
    /usr/sbin/cagefs_enter_user --no-fork "$user" \
    /bin/bash -lc 'cd -- "$1" && eval "exec $2"' wsv-run "$folder" "$command"
  ```
- `install.sh` v1 (idempotent, re-run = upgrade): must run as root; refuse non-CloudLinux (Global Constraints); `dnf -y install supervisor` if missing; `systemctl enable --now supervisord`; copy `src/ bin/ lang/` to `appDir()` (root:root, dirs 0755, files 0644, `bin/*` 0755); create `dataDir()` 0700 with `accounts launch backups` subdirs and `/var/log/worker_supervisor` 0700. Later tasks append registration steps.
- `dev/deploy.sh`: `tar --exclude=.git --exclude=vendor --exclude=.superpowers -czf - . | dev/vm/vm.sh ssh 'rm -rf /root/wsv-src && mkdir /root/wsv-src && tar xzf - -C /root/wsv-src && /root/wsv-src/install.sh'`.

- [ ] **Step 1: Write failing tests:** `test_ini_never_contains_client_text` (command `php x "a" %(here)s $HOME; echo` and folder `/home/bob/my app` -> neither string occurs in `renderIni`); `test_ini_matches_expected` (full expected text for a fixture worker); `test_not_runnable_forces_autostart_false`; `test_stderr_log_only_when_not_redirected`; `test_write_reports_change` (second identical write returns false); `test_remove_deletes_ini_launch_and_logs`.
- [ ] **Step 2:** Run `vendor/bin/phpunit --filter IniRendererTest` - FAIL.
- [ ] **Step 3:** Implement `IniRenderer`, `bin/wsv-run`, `install.sh` v1, `dev/deploy.sh`.
- [ ] **Step 4:** Run - PASS.
- [ ] **Step 5: VM test** `tests/vm/launcher.sh` (run with `dev/deploy.sh && dev/vm/vm.sh ssh 'bash -s' < tests/vm/launcher.sh`). The CLI does not exist yet, so the script writes the files itself with `IniRenderer` via a short `php -r` and `supervisorctl update`. Probe worker for user `wshell`, folder `/home/wshell/probe dir`, holding `probe.sh` that prints `$HOME`, `$#` and each argument on one line, traps TERM to sleep 5 s and print `clean`, then idles. Command: `./probe.sh "a b" '%(x)s' "$USER" ; echo never`. Assert: the log shows `HOME=/home/wshell`, three arguments `a b`, `%(x)s`, `wshell`; the process is listed under `wshell` in `lveps -p`; its mount namespace differs from PID 1; `supervisorctl stop` takes about 5 s and the log ends with `clean`; no `wshell` processes remain. Prints `LAUNCHER OK`.
- [ ] **Step 6: Commit** `feat(core): render managed supervisor config and launch workers inside CageFS`.

### Task 8: Supervisor client

**Files:**
- Create: `src/Supervisor/SupervisorClient.php` (interface), `src/Supervisor/Supervisorctl.php`, `src/Supervisor/ProcessStatus.php`, `src/Supervisor/Runner.php` (interface), `src/Supervisor/ShellRunner.php`, `tests/Unit/SupervisorctlTest.php`, `tests/fixtures/supervisorctl-status.txt`

**Interfaces:**
- Produces: `interface Runner { public function run(array $argv): array{0:int,1:string}; public function detach(array $argv): void; }` (`ShellRunner::detach` uses `setsid -f` with stdio to `/dev/null`).
- Produces: `interface SupervisorClient { public function status(): ProcessStatus[]; public function version(): ?string; public function apply(string $program): void; public function remove(string $program): void; public function start(string $program): void; public function stop(string $program): void; public function restart(string $program): void; }` (`version()` runs `version` synchronously, null when supervisord is down).
- `Supervisorctl::__construct(Runner $r)`. Base argv `['/usr/bin/supervisorctl','-c','/etc/supervisord.conf']`. `status()` runs `status` synchronously and parses lines `<group>:<process> <STATE> <description>`. `apply` = detached `bash -c` sequence `reread` then `update <program>`; `remove` = detached `stop <program>:*` then `remove <program>`; `start`/`stop`/`restart` = detached `<verb> <program>:*`.
- `ProcessStatus` (readonly): `string $program, string $process, string $state /* RUNNING STARTING BACKOFF STOPPING STOPPED EXITED FATAL UNKNOWN */, ?int $pid, ?int $uptimeSeconds, string $description`.

- [ ] **Step 1: Write failing tests** with a recording fake `Runner`: `test_parses_status_fixture` (fixture lines for RUNNING `pid 123, uptime 2:14:03`, FATAL `Exited too quickly (process log may have details)`, STOPPED `Oct 31 10:00 AM` -> `uptimeSeconds===8043` for the first); `test_stop_is_detached` (fake records `detach` with argv ending `['stop','bob_queue:*']`, and no synchronous `run`); `test_apply_rereads_then_updates`.
- [ ] **Step 2:** Run `vendor/bin/phpunit --filter SupervisorctlTest` - FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run - PASS.
- [ ] **Step 5: Commit** `feat(core): add supervisorctl client with detached control actions`.

### Task 9: Logs

**Files:**
- Create: `src/Logs.php`, `tests/Unit/LogsTest.php`

**Interfaces:**
- Produces: `Logs::__construct(Paths $p)`; `files(string $user, Worker $w): array` (list of `['process'=>'00','stream'=>'out|err','path'=>...]` for `0..processes-1`, `err` only when `!redirectStderr`); `tail(string $user, Worker $w, int $process, string $stream, int $lines): string` (reads backwards in 64 KiB blocks, never the whole file; `$lines` clamped 1..2000); `clear(string $user, Worker $w): void` (truncate all current files, delete `.1..3` backups); `path(...)` builds names only from validated user/name/index - no caller-supplied paths.

- [ ] **Step 1: Write failing tests:** `test_tail_returns_last_lines`; `test_tail_on_large_file_reads_little` (50 MB sparse-ish file, assert result correct and elapsed < 0.5 s); `test_process_index_out_of_range_throws`; `test_clear_truncates_and_drops_backups`.
- [ ] **Step 2:** Run `vendor/bin/phpunit --filter LogsTest` - FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run - PASS.
- [ ] **Step 5: Commit** `feat(core): read and clear worker logs safely`.

### Task 10: User service and status view

**Files:**
- Create: `src/Service.php`, `src/StatusView.php`, `src/ServiceError.php`, `src/LveProbe.php` (implements `AccountProbe`), `tests/Unit/ServiceTest.php`, `tests/Unit/StatusViewTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: `ServiceError extends RuntimeException` with `string $errorCode`, `array $params`, `Issue[] $issues`; `public const CODES` = `account_unknown, worker_unknown, validation_failed, admin_stopped, suspended, feature_off, internal`.
- Produces: `Service::__construct(Paths $p, AccountStore $store, Settings $settings, IniRenderer $ini, SupervisorClient $sv, Logs $logs, WorkerValidator $validator, callable $cpuserLoader)`. User-scoped methods (all take `string $user` first, throw `ServiceError` with code `account_unknown` when `CpUser::load` is null):
  - `listWorkers(string $user): array` -> `['usage'=>['processes'=>int,'max_processes'=>int,'workers'=>int,'max_workers'=>int,'over_limit'=>bool], 'workers'=>StatusView row[]]` (`over_limit` when a smaller package left the account above a limit; spec 6.2)
  - `getWorker(string $user, string $name): array` -> `['worker'=>Worker::toArray(), 'expert'=>string]`
  - `validate(string $user, array $input, string $mode /* form|expert */, ?string $originalName): array` -> `['issues'=>Issue[] as arrays, 'worker'=>?array, 'expert'=>?string]`
  - `save(string $user, array $input, string $mode, ?string $originalName): array` (throws `validation_failed` with issues; on rename removes the old program; writes ini/launch; `apply`; starts when `runnable`)
  - `delete`, `start`, `stop`, `restart` (`string $user, string $name`) - `start`/`restart` throw `admin_stopped`, `suspended`, `feature_off`; `start` sets `desired=running`, `stop` sets `desired=stopped`; both rewrite the ini so autostart follows.
  - `logs(string $user, string $name, int $process, string $stream, int $lines): array` -> `['text'=>string,'files'=>array]`; `clearLogs(string $user, string $name): void`.
  - `templates(string $user): array` -> allowed `Template`s as arrays with `id, command, fixed_processes` plus `limits`.
- Produces: `StatusView::row(Worker $w, Account $a, ProcessStatus[] $procs): array` -> `name, template, folder, processes, running, status, reason, since` where `status` in `running|starting|stopping|stopped|failed|suspended|admin_stopped|feature_off` (precedence: feature_off > suspended > admin_stopped > any FATAL = failed > any STOPPING = stopping > any STARTING/BACKOFF = starting > all RUNNING = running > stopped), `reason` = `['code'=>'exited_too_quickly','params'=>['retries'=>startretries]]` for FATAL, `since` = now - max uptime.
- `LveProbe::memoryLimitMb` uses `lvectl` JSON for the user's LVE (check exact flags on the VM, e.g. `lvectl list-user --json`, record the command in a comment); `phpVersion` runs `cagefs_enter_user <user> /bin/bash -lc 'cd -- "$1" && php -r "echo PHP_VERSION;"' x <folder>` with a 10 s timeout.

- [ ] **Step 1: Write failing tests** (fake `SupervisorClient` recording calls, stub probe, temp root):
  - `test_save_new_worker_writes_ini_and_applies` (calls `apply('bob_queue')` then `start`).
  - `test_save_with_errors_throws_validation_failed_and_writes_nothing`.
  - `test_stop_returns_immediately_and_disables_autostart` (fake `stop` is non-blocking; ini now has `autostart=false`; account `desired==='stopped'`).
  - `test_start_refused_when_admin_stopped` -> code `admin_stopped`.
  - `test_rename_removes_old_program` (`remove('bob_old')` then `apply('bob_new')`).
  - `test_expert_mode_save_uses_parsed_values`.
  - `test_list_usage_counts_processes`; `test_usage_over_limit_after_downgrade` (12 processes saved, limit now 10 -> `over_limit===true`, existing workers untouched, saving a new worker fails `processes_range`).
  - StatusView: `test_fatal_maps_to_failed_with_reason`, `test_precedence_suspended_over_failed`.
- [ ] **Step 2:** Run `vendor/bin/phpunit --filter 'ServiceTest|StatusViewTest'` - FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run - PASS.
- [ ] **Step 5: VM check (spec 9.2.1):** after Task 11 deploys, run a `custom` worker in `wshell` that allocates memory past the LVE PMEM limit (`php -d memory_limit=-1 -r '$a=[]; while(true){$a[]=str_repeat("x",1<<20); usleep(1000);}'`) and one plain `sleep 3600` worker; record in `README.md` (section "Known limits") what supervisord shows when LVE kills the process and that the idle worker is still alive after 1 h. Pin the exact `lvectl` command used by `LveProbe` in a comment.
- [ ] **Step 6: Commit** `feat(core): add user-scoped worker service and status view`.

### Task 11: CLI entry point

**Files:**
- Create: `bin/wsv`, `src/Cli.php`, `src/Container.php`, `tests/Unit/CliTest.php`

**Interfaces:**
- Produces: `bin/wsv` (`#!/usr/local/cpanel/3rdparty/bin/php`, requires `<appDir>/src` autoloader - a tiny PSR-4 autoloader in `src/autoload.php`, no Composer at runtime).
- Contract: `wsv <action>`; stdin JSON `{"user": "<cpanel user>", "args": {...}}`; stdout one JSON line `{"ok":true,"data":...}` or `{"ok":false,"error":{"code":"...","params":{...},"issues":[...]}}`; exit 0 whenever JSON was written, 1 on unexpected exception (still JSON, code `internal`, details to `/usr/local/cpanel/logs/error_log` style stderr).
- User actions (map 1:1 to `Service`): `list`, `get`, `validate`, `save`, `delete`, `start`, `stop`, `restart`, `logs`, `clear_logs`, `templates`. Args use snake_case names of the `Service` parameters (`name`, `input`, `mode`, `original_name`, `process`, `stream`, `lines`).
- `Container::build(Paths $p): array` wires real implementations; `Cli::run(string $action, string $stdin, array $services): string`.

- [ ] **Step 1: Write failing tests:** `test_unknown_action_is_error_json`; `test_service_error_is_mapped`; `test_list_returns_ok_json` (container with fakes).
- [ ] **Step 2:** Run `vendor/bin/phpunit --filter CliTest` - FAIL.
- [ ] **Step 3:** Implement; extend `install.sh` to install `bin/wsv`.
- [ ] **Step 4:** Run - PASS; `dev/deploy.sh` then `echo '{"user":"wshell","args":{}}' | dev/vm/vm.sh ssh /usr/local/cpanel/3rdparty/worker_supervisor/bin/wsv list` prints `{"ok":true,...}`.
- [ ] **Step 5: Commit** `feat(core): add JSON CLI entry point`.

## Phase 2 - cPanel integration

### Task 12: Perl glue - admin module and UAPI module

**Files:**
- Create: `perl/Cpanel/Admin/Modules/WorkerSupervisor/Workers.pm`, `perl/Cpanel/API/WorkerSupervisor.pm`, `tests/vm/uapi.sh`; Modify: `install.sh`

**Interfaces:**
- Consumes: `bin/wsv` contract (Task 11).
- Produces: admin module `Cpanel::Admin::Modules::WorkerSupervisor::Workers` (`use parent 'Cpanel::Admin::Base'`), installed to `/var/cpanel/perl/Cpanel/Admin/Modules/WorkerSupervisor/Workers.pm`. `_actions` = `LIST GET VALIDATE SAVE DELETE START STOP RESTART LOGS CLEAR_LOGS TEMPLATES`. Each action: `$self->cpuser_has_feature_or_die('worker_supervisor')`; user = `$self->get_caller_username()` (never from args); one hashref arg; runs `wsv <lowercase action>` with `{"user":..,"args":..}` on stdin, 30 s timeout; returns the decoded JSON hashref unchanged (validation errors are data, not exceptions).
- Produces: UAPI `Cpanel::API::WorkerSupervisor` installed to `/usr/local/cpanel/Cpanel/API/WorkerSupervisor.pm`: functions `list_workers get_worker validate_worker save_worker delete_worker start_worker stop_worker restart_worker get_logs clear_logs list_templates`. Each: `main::hasfeature('worker_supervisor')` else `$result->error('_ERROR_FEATURE', ...)`; reads params (`name`, `original_name`, `mode`, `expert_text`, worker fields as flat params, `process`, `stream`, `lines`); calls `Cpanel::AdminBin::Call::call('WorkerSupervisor','Workers',<ACTION>, \%args)`; on `ok` -> `$result->data($data); return 1`; on error -> `$result->data({error=>$err}); $result->raw_error($err->{code}); return 0`. No logic beyond argument mapping.
- `install.sh`: copy both modules (root, 0644), then restart `cpsrvd` (`/usr/local/cpanel/scripts/restartsrv_cpsrvd`) so the modules load.

- [ ] **Step 1: Write the VM test** `tests/vm/uapi.sh`: as root `uapi --user=wshell --output=json WorkerSupervisor list_workers` -> `status==1`; save a `custom` worker `sleep 600` in `/home/wshell`; `list_workers` shows it `running` within 15 s; as the user inside CageFS (`cagefs_enter_user wshell /usr/local/cpanel/bin/uapi --output=json WorkerSupervisor list_workers`) -> same; with feature disabled for `wnoshell` (`whmapi1 add_override_features_for_user user=wnoshell features='{"worker_supervisor":"0"}'`) -> `status==0`. Ends with `UAPI OK`.
- [ ] **Step 2:** Run `dev/deploy.sh && dev/vm/vm.sh ssh 'bash -s' < tests/vm/uapi.sh` - FAIL (module missing).
- [ ] **Step 3:** Implement both modules; `perl -c` them on the VM with `/usr/local/cpanel/3rdparty/bin/perl -I/usr/local/cpanel -c`.
- [ ] **Step 4:** Rerun - prints `UAPI OK`. Also confirm `allowed_parents`/caller behaviour from `uapi` CLI works (admin module method has no `.conf`).
- [ ] **Step 5: Commit** `feat(cpanel): add UAPI and admin modules forwarding to the core`.

### Task 13: cPanel plugin registration, i18n and the workers list page

**Files:**
- Create: `cpanel/install.json`, `cpanel/worker_supervisor.svg`, `cpanel/worker_supervisor.png`, `cpanel/pages/index.live.php`, `cpanel/pages/_bootstrap.php`, `cpanel/pages/assets/app.js`, `cpanel/pages/assets/app.css`, `lang/en.php`, `lang/fr.php`, `src/I18n.php`, `tests/Unit/I18nTest.php`; Modify: `install.sh`

**Interfaces:**
- `install.json`: one `link` object `{"type":"link","id":"worker_supervisor","group_id":"advanced","name":"$LANG{'Worker Supervisor'}","icon":"worker_supervisor.svg","order":100,"uri":"worker_supervisor/index.live.php","featuremanager":1,"feature":"worker_supervisor","description":"Worker Supervisor"}`.
- `install.sh`: tar `cpanel/install.json` + icons into a temp dir and run `/usr/local/cpanel/scripts/install_plugin <tar> --theme=jupiter`; copy `cpanel/pages/*` to `/usr/local/cpanel/base/frontend/jupiter/worker_supervisor/`.
- `I18n::__construct(string $langDir, string $locale)` (`fr*` -> `fr`, else `en`); `t(string $key, array $params = []): string` (`{name}` placeholders); `catalog(): array`. Keys: page strings plus `issue.<code>` for every Issue code (Tasks 4, 5), `error.<code>` for every ServiceError code, `status.<status>`, `reason.exited_too_quickly`, `template.<id>`, `deploy_hint.<id>`.
- `_bootstrap.php`: `require '/usr/local/cpanel/php/cpanel.php'`; `$cpanel = new CPANEL()`; locale via `$cpanel->uapi('Locale','get_attributes')['cpanelresult']['result']['data']['locale']`; feature guard `if (!$cpanel->cpanelfeature('worker_supervisor'))` -> render "not available" and `end()`; exposes `$i18n`.
- `app.js`: `api(fn, params)` POSTs form-encoded to `location.pathname.match(/^\/cpsess\d+\//)[0] + 'execute/WorkerSupervisor/' + fn` and returns `{status, data, errors}`; i18n catalog injected as `window.WSV_I18N`.
- `index.live.php`: `$cpanel->header('Worker Supervisor')`, usage bar, table (name + folder, status pill with reason, processes running/total, up since, actions Start/Stop, Restart, Logs, Edit, Delete per status), "+ New worker" link to `edit.live.php`, auto-refresh status every 5 s via `list_workers`; `$cpanel->footer()`; `$cpanel->end()`. Layout per spec 5.1 (mockup `worker-list.html` in `.superpowers/brainstorm/`).

- [ ] **Step 1: Write failing test** `I18nTest::test_french_has_every_english_key` (`array_keys(fr) == array_keys(en)`), `test_every_issue_code_has_a_message` (codes collected by reflection of a constant list `Issue::CODES` you add), `test_fr_locale_prefix` (`fr_ca` -> French).
- [ ] **Step 2:** Run `vendor/bin/phpunit --filter I18nTest` - FAIL.
- [ ] **Step 3:** Implement I18n, catalogs, plugin files, list page, installer steps.
- [ ] **Step 4:** Run - PASS. Deploy; open `dev/vm/vm.sh whm wshell` link; check: icon under Advanced, list shows the worker from Task 12, Stop returns at once and the pill moves to Stopping then Stopped, French labels after switching the account locale to `fr`. Disable the feature for `wshell` in Feature Manager: icon gone, direct URL shows "not available".
- [ ] **Step 5: Commit** `feat(cpanel): register plugin and add workers list page`.

### Task 14: Create / edit page (Simple, Advanced, Expert)

**Files:**
- Create: `cpanel/pages/edit.live.php`, `cpanel/pages/assets/edit.js`; Modify: `cpanel/pages/assets/app.css`, `lang/*.php`

**Interfaces:**
- Consumes: UAPI `list_templates`, `get_worker`, `validate_worker`, `save_worker`.
- Behaviour (spec 5.3): template select prefills command and (Horizon) locks processes to 1; Simple fields name/processes/folder/command; "Advanced" disclosure with autostart, autorestart, startsecs, startretries, stopwaitsecs, redirect_stderr; tabs Form / Expert. Switching Form -> Expert calls `validate_worker` (mode form) and shows `expert`; Expert -> Form calls `validate_worker` (mode expert) and refuses to switch while any `error` issue exists. Live checks: debounced 400 ms `validate_worker` on input, issues listed under the form (error red, warning amber, info neutral; expert issues show "line N"). Save disabled while errors exist. `?name=` loads an existing worker; save passes `original_name`. Deploy hint under the template (`deploy_hint.<id>`).

- [ ] **Step 1:** Implement page and script.
- [ ] **Step 2: VM check** (deploy, login as `wshell`): create a Laravel queue worker in a folder without `artisan` -> `required_path_missing` shown, Save disabled; add `artisan` -> info shows PHP version; Expert: add `user=root` -> "line N: user is set by the plugin"; switch back blocked; remove line -> switch works and form fields match; Save -> list shows Starting then Running (a dummy `artisan` script `while true; do sleep 5; done` is fine).
- [ ] **Step 3: Commit** `feat(cpanel): add create and edit worker page with expert mode`.

### Task 15: Log viewer page

**Files:**
- Create: `cpanel/pages/logs.live.php`, `cpanel/pages/assets/logs.js`; Modify: `lang/*.php`

**Interfaces:**
- Consumes: UAPI `get_logs`, `clear_logs`.
- Behaviour (spec 5.2): process tabs from `files`, stream toggle when stderr is separate, last 200 lines (choices 200/1000/2000), auto-refresh 3 s toggle, Clear (confirm in-page, no `window.confirm`), Download = current text as a file built client-side (Blob) - no new endpoint.

- [ ] **Step 1:** Implement.
- [ ] **Step 2: VM check:** worker printing a line per second; tabs for 2 processes; auto-refresh shows new lines; Clear empties; Download saves the visible text.
- [ ] **Step 3: Commit** `feat(cpanel): add log viewer`.

## Phase 3 - WHM side and lifecycle

### Task 16: Package extension

**Files:**
- Create: `whm/package-extension/worker_supervisor`, `whm/package-extension/worker_supervisor.tt2`; Modify: `install.sh`

**Interfaces:**
- Defaults file (exact keys, all prefixed): `worker_supervisor_max_processes=10`, `worker_supervisor_max_workers=5`, `worker_supervisor_max_stopwaitsecs=3600`, `worker_supervisor_tpl_laravel_queue=1`, `worker_supervisor_tpl_laravel_horizon=1`, `worker_supervisor_tpl_symfony_messenger=1`, `worker_supervisor_tpl_custom=1`, `_NAME=Worker Supervisor`.
- `.tt2`: `propertyEditor` rows for each key, values `[% data.defaults.<key> | html %]`, checkboxes saving `1`/`0` (hidden `0` input before each checkbox), help line "Templates simplify the form; they are not a security limit." (spec 5.5).
- `install.sh`: `mkdir -p /var/cpanel/packages/extensions` and copy both files.
- Consumed by `CpUser`/`LimitResolver` (Task 3) through the cpuser file.

- [ ] **Step 1:** Implement.
- [ ] **Step 2: VM check:** WHM > Edit a Package `default` shows "Worker Supervisor"; enable it with max processes 3 -> `/var/cpanel/users/wshell` gains the keys; `uapi --user=wshell WorkerSupervisor list_workers` reports `max_processes` 3. (Reseller-owned packages are covered by the Task 3 unit test.)
- [ ] **Step 3: Commit** `feat(whm): add package extension for worker limits`.

### Task 17: WHM overview, settings and admin actions

**Files:**
- Create: `whm/worker_supervisor.conf` (AppConfig), `whm/cgi/index.php`, `whm/cgi/settings.php`, `whm/cgi/_bootstrap.php`, `whm/cgi/assets/whm.js`, `whm/worker_supervisor.png`, `src/AdminService.php`, `tests/Unit/AdminServiceTest.php`; Modify: `install.sh`, `src/Cli.php` (admin actions for hooks/cron reuse)

**Interfaces:**
- AppConfig: `name=worker_supervisor`, `service=whostmgr`, `user=root`, `url=/cgi/worker_supervisor/`, `acls=all`, `displayname=Worker Supervisor`, `entryurl=worker_supervisor/index.php`, `icon=worker_supervisor.png`, `target=_self`. Register with `/usr/local/cpanel/bin/register_appconfig`; icon to `/usr/local/cpanel/whostmgr/docroot/addon_plugins/`; CGI files to `/usr/local/cpanel/whostmgr/docroot/cgi/worker_supervisor/` (0755).
- `_bootstrap.php`: refuse unless `getenv('REMOTE_USER') === 'root'` (or `$_ENV`); `require '/usr/local/cpanel/php/WHM.php'`; `WHM::header('Worker Supervisor', 0, 0)` / `WHM::footer()`. Actions are POSTs to the same page with `action=`; the session token in the URL path is the CSRF guard.
- `AdminService::__construct(Service $svc, AccountStore $store, Settings $settings, SupervisorClient $sv, callable $cpuserLoader)`: `overview(): array` (health: supervisord running/version via `supervisorctl version`, counts; rows: `user` + StatusView row, filters done client-side), `adminStop(string $user, string $name)` (sets `adminStopped`, rewrites ini, detached stop), `release(string $user, string $name)` (clears it, starts when runnable), `start/restart(...)` (bypass client refusal except suspended/feature_off), `config(string $user, string $name): array` (`ini` text + `expert` text), `logs(...)` (delegates), `setDefaults(array)`, `setOverride(string $user, ?array)`.

- [ ] **Step 1: Spike (record result in a comment in `_bootstrap.php`):** confirm how WHM executes PHP under `cgi/` (shebang `#!/usr/local/cpanel/3rdparty/bin/php -q` + 0755 vs. `.php` handler) and whether `entryurl` is relative to `cgi/`. Use a hello page on the VM, then delete it.
- [ ] **Step 2: Write failing tests:** `test_admin_stop_blocks_client_start` (after `adminStop`, `Service::start` throws `admin_stopped`); `test_release_restarts_runnable_worker`; `test_override_is_persisted`.
- [ ] **Step 3:** Run `vendor/bin/phpunit --filter AdminServiceTest` - FAIL.
- [ ] **Step 4:** Implement `AdminService`, pages (overview per spec 6.3 and mockup `whm-side.html`; settings page with server defaults form and per-account override editor), installer registration.
- [ ] **Step 5:** Run - PASS. VM check: WHM > Plugins > Worker Supervisor lists workers of both accounts with health line; Admin stop shows "Stopped by administrator" to the client and the client's Start is refused; Config shows the generated ini; a reseller session cannot open the page.
- [ ] **Step 6: Commit** `feat(whm): add overview, settings and admin worker actions`.

### Task 18: Account lifecycle hooks and reconcile job

**Files:**
- Create: `src/Lifecycle.php`, `bin/wsv-hook`, `cron/worker_supervisor`, `tests/Unit/LifecycleTest.php`, `tests/fixtures/hooks/*.json`; Modify: `install.sh`, `src/Cli.php`

**Interfaces:**
- Produces: `Lifecycle::__construct(AccountStore $store, Settings $settings, IniRenderer $ini, SupervisorClient $sv, Logs $logs, callable $cpuserLoader, FeatureChecker $features)`; `suspend(string $user)`, `unsuspend(string $user)`, `remove(string $user)` (stop + remove programs, ini, launch files, logs, account file, override), `rename(string $old, string $new)` (remove old programs, move data/launch/log dirs, `Settings::renameUser`, re-render under new program names, apply), `packageChanged(string $user)` (no-op beyond re-reading limits; over-limit workers keep running), `reconcile(): array` (report of changes).
- Produces: `interface FeatureChecker { public function featureRegistered(): bool; public function usersWithFeature(string[] $users): array<string,bool>; }`, real impl `WhmapiFeatureChecker` using `/usr/local/cpanel/bin/whmapi1 --output=json get_feature_names` and `get_users_features_settings` (cpuser_setting wins over feature_list_setting; if `featureRegistered()` is false, change nothing - `verify_user_has_feature` returns 1 for unregistered features).
- `reconcile()`: for every account file: account gone -> `remove`; suspended flag differs -> `suspend`/`unsuspend`; feature state differs -> set `featureOff` and re-render/stop/start; any `wsv_*.ini` in `iniDir()` without a matching worker -> delete and `remove` program; ini content differs from `renderIni` -> rewrite + `apply`.
- `bin/wsv-hook`: `#!/usr/local/cpanel/3rdparty/bin/php -q`, reads stdin JSON, dispatches by `context.event`/stage, prints `1 ok` (never blocks: `blocking` not set). Installed to `/usr/local/cpanel/3rdparty/bin/worker_supervisor_hook` (root, 0755) and registered with `manage_hooks add script ... --manual 1 --category Whostmgr --event <E> --stage <S>` for: `Accounts::suspendacct` post, `Accounts::unsuspendacct` post, `Accounts::Remove` pre (user still exists), `Accounts::Modify` pre and post (rename: detect from captured payload), `Accounts::change_package` post.
- `cron/worker_supervisor` -> `/etc/cron.d/worker_supervisor`: `*/5 * * * * root /usr/local/cpanel/3rdparty/worker_supervisor/bin/wsv reconcile >/dev/null 2>&1`.

- [ ] **Step 1: Capture real payloads:** on the VM set `/var/cpanel/debughooks` to `logdata`, register a logging stub hook for the six events, then suspend/unsuspend `wnoshell`, rename it (`whmapi1 modifyacct user=wnoshell newuser=wnoshell2`) and back, change its package, create and remove a throwaway account; copy the payloads from `/usr/local/cpanel/logs/error_log` into `tests/fixtures/hooks/` (names `suspendacct-post.json`, `modify-pre.json`, ...). Reset `debughooks`. Note the old/new username fields in a comment at the top of `bin/wsv-hook`.
- [ ] **Step 2: Write failing tests** (fakes for supervisor and features, fixtures for payloads):
  - `test_remove_leaves_nothing` (ini, launch file, log dir, account file, override all gone; `remove(program)` called for each worker).
  - `test_rename_moves_everything` (old program removed, new `<new>_<name>` applied, logs dir moved).
  - `test_reconcile_removes_workers_of_vanished_account`.
  - `test_reconcile_deletes_orphan_wsv_ini`.
  - `test_reconcile_feature_off_stops_and_feature_on_restores`.
  - `test_reconcile_does_nothing_when_feature_not_registered`.
  - `test_hook_dispatch_from_fixtures` (each fixture routes to the right method).
- [ ] **Step 3:** Run `vendor/bin/phpunit --filter LifecycleTest` - FAIL.
- [ ] **Step 4:** Implement; installer registers hooks (idempotent: `manage_hooks list --output JSON` first) and cron.
- [ ] **Step 5:** Run - PASS. VM check: suspend `wshell` -> its workers stop and show Suspended; unsuspend -> back; terminate a throwaway account with a running worker -> `supervisorctl status` and `/etc/supervisord.d/` have nothing of it.
- [ ] **Step 6: Commit** `feat: handle account lifecycle and reconcile drift`.

### Task 19: Import of hand-written supervisor files

**Files:**
- Create: `src/Importer.php`, `whm/cgi/import.php`, `tests/Unit/ImporterTest.php`, `tests/fixtures/import/acme.ini`

**Interfaces:**
- Produces: `Importer::__construct(Paths $p, ...)`; `candidates(): array` (files in `iniDir()` not starting with `wsv_`, each with its programs); `propose(string $file, string $program, string $user): array` -> `['worker'=>Worker::toArray(), 'changes'=>string[] /* codes */, 'issues'=>Issue[]]` where the command is extracted from an `su [-s shell] - <user> -c "<cmd>"` wrapper when present (the `cd <dir> &&` prefix becomes the folder), change codes: `wrapper_replaced`, `logs_moved`, `user_key_dropped`, `directory_from_cd`, `key_dropped` {key}; `apply(string $file, string $program, string $user, array $worker): void` (copy original to `backupDir()/<timestamp>-<basename>`, remove the program block - delete the file if it was the only one, save worker via `Service`, apply both programs).
- Fixture `acme.ini` = the owner's file from the spec, section 1.

- [ ] **Step 1: Write failing tests:** `test_propose_from_acme` (folder `/home/acme/app.example.com`, command starting `/usr/local/bin/php artisan queue:work --queue=emails,default`, processes 5, startsecs 10, startretries 30, stopwaitsecs 3600, changes include `wrapper_replaced` and `logs_moved`); `test_apply_backs_up_original`; `test_candidates_skip_managed_files`.
- [ ] **Step 2:** Run `vendor/bin/phpunit --filter ImporterTest` - FAIL.
- [ ] **Step 3:** Implement importer and WHM page (pick file/program, pick account, preview changes, apply).
- [ ] **Step 4:** Run - PASS. VM check with the fixture placed in `/etc/supervisord.d/` for `wshell`: import preview, apply, worker appears in the client's list, backup exists, old program gone from `supervisorctl status`.
- [ ] **Step 5: Commit** `feat(whm): import hand-written supervisor programs`.

### Task 20: Uninstaller, README and clean-install test

**Files:**
- Create: `uninstall.sh`, `tests/vm/clean-install.sh`; Modify: `README.md`, `install.sh`

**Interfaces:**
- `uninstall.sh [--keep-workers] [--purge]`: unless `--keep-workers`, stop and remove every managed program and `wsv_*.ini`; remove the extension from every package that lists it (`whmapi1 listpkgs`, then `editpkg name=<pkg> _PACKAGE_EXTENSIONS=<list without worker_supervisor> remove_missing_extensions=1`) before deleting the extension files; `uninstall_plugin`, `unregister_appconfig worker_supervisor`, `manage_hooks delete` for each registration, delete Perl modules, cron file, cPanel/WHM page dirs, `appDir()`; restart cpsrvd; keep `dataDir()` and logs unless `--purge`.
- `README.md`: what it does, requirements (CloudLinux 8/9 + cPanel; state that only CloudLinux 9 has been tested until a CloudLinux 8 VM run is done - spec 9.2.2), install/uninstall commands, enabling via Feature Manager and package extension, security model summary (spec 7), how deploys restart workers per template, development (`dev/vm/vm.sh`, `dev/deploy.sh`, `composer test`).
- `tests/vm/clean-install.sh` (run on the host): `dev/vm/vm.sh stop && dev/vm/vm.sh restore cloudlinux-clean && dev/vm/vm.sh start`, wait for SSH, create test accounts, `dev/deploy.sh`, run `tests/vm/uapi.sh`, run `uninstall.sh --purge` and assert nothing of the plugin remains (file list + `manage_hooks list` + `show_appconfig`), reinstall to leave the VM usable. Ends with `CLEAN INSTALL OK`.

- [ ] **Step 1:** Implement `uninstall.sh` and the clean-install script.
- [ ] **Step 2:** Run `tests/vm/clean-install.sh` - prints `CLEAN INSTALL OK`.
- [ ] **Step 3:** Write README.
- [ ] **Step 4:** Run `vendor/bin/phpunit` - all PASS.
- [ ] **Step 5: Commit** `feat: add uninstaller, clean-install test and README`.
