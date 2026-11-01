# Worker Supervisor for cPanel - Design

Date: 2026-10-31
Status: approved by owner 2026-10-31

Calls marked **(my call)** were made by Claude during brainstorming and not
explicitly chosen by the owner. Everything else was decided by the owner.

## 1. Intent

Hosting clients on cPanel servers need processes that stay up with no web
traffic - mainly Laravel `queue:work`. Today only root can provide that, by
hand-writing files like this one in `/etc/supervisord.d/`:

```ini
[program:acme-laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=/bin/su - acme -c "cd /home/acme/app.example.com && exec /usr/local/bin/php artisan queue:work --queue=emails,default --sleep=3 --tries=3 --max-time=3600 --memory=1024"
directory=/home/acme/app.example.com/
autostart=true
autorestart=true
startsecs=10
startretries=30
numprocs=5
user=root
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/home/acme/app.example.com/worker%(process_num)02d.log
stopwaitsecs=3600
```

The plugin lets each cPanel client create, read, update and delete their own
supervisor workers from their cPanel, the way root does today, scoped to their
account. The `su -` wrapper exists so the worker enters the account's CloudLinux
LVE and CageFS.

Rollout: the owner's own servers first, then published free and open source.

No existing open-source equivalent was found. The closest product is
Oderland's proprietary "Queue Workers" cPanel extension (Laravel Queue,
Horizon, Symfony Messenger, Craft CMS; 2 workers per account; `uapi
QueueWorkers` over SSH).

## 2. Goals

1. A cPanel client can manage their own workers without root: create, edit,
   delete, start, stop, restart, read logs.
2. Workers survive reboots and crashes (supervisord), and Laravel's
   `php artisan queue:restart` in a deploy script keeps working as the restart
   mechanism.
3. Root keeps control from WHM: who gets the feature, how much they get, a view
   of every worker on the server.
4. Existing hand-written `.ini` files on the owner's servers can be brought
   under the plugin.
5. Installable by any cPanel host with one command; removable cleanly.

## 3. Non-goals

- Managing system or root services.
- A web terminal or arbitrary shell access.
- Exposing supervisor settings beyond the list in section 5.4.
- Servers without CloudLinux in v1 (owner's decision). Plain AlmaLinux / Rocky
  and Ubuntu-based cPanel servers are later versions.
- Reseller control in v1 **(my call)** - see 6.2.
- Email alerts on failure, and including workers in cPanel backups / account
  transfers: later versions **(my call)**.

## 4. Anti-goals (failure even if it "works")

- Any path by which a client gets code or file writes executed as root. The
  client is untrusted.
- A client seeing or affecting another account's workers.
- Workers escaping the account's CloudLinux LVE / CageFS.
- Requiring the client to know supervisor syntax for the common case.

## 5. The cPanel side (client)

### 5.1 Main page "Worker Supervisor"

- Usage bar: processes used vs. the account's limit (e.g. 7 of 10).
- One row per worker: name, folder/domain it belongs to, status (Running,
  Starting, Stopped, Failed, Suspended), processes running / configured, up
  since, actions (Start/Stop, Restart, Logs, Edit, Delete).
- A failed worker shows the reason in plain words (e.g. "exited too quickly 30
  times") and points to the log.
- "+ New worker" button.

### 5.2 Log viewer

- One tab per process (00, 01, ...), last N lines (default 200), auto-refresh,
  Download, Clear.
- Logs are stored where the client cannot write (see 7.3); the viewer is the
  client's only access to them.

### 5.3 Create / edit worker - three modes, one worker

All three modes edit the same worker; the client can switch freely and data is
kept in sync.

**Simple (default):**
- Template (drop-down) - see 5.5.
- Name.
- Processes (numprocs).
- Application folder (must be inside the client's home).
- Command (single text box, prefilled by the template, editable).

**Advanced (expands the same form):**
- Start with server (autostart).
- Restart if it stops (autorestart: always / on unexpected exit / never).
- Running after N seconds (startsecs).
- Start retries (startretries).
- Stop wait seconds (stopwaitsecs).
- Errors in same log (redirect_stderr).

**Expert:**
- The same worker shown as a plain supervisor `[program:x]` block, editable.
- Only the keys from Simple + Advanced are accepted. Any other key, or any
  plugin-owned key (5.4), is rejected with a line-numbered error.
- Switching Expert -> Form parses the text; it must be valid to switch.

**Live checks (shown under the form, enforced again server-side):**
- Folder exists, resolves inside the client's home, and contains the file the
  template needs (`artisan`, `bin/console`).
- Processes and stop wait within the account's limits.
- Warning (not error) when the command's memory flag x processes exceeds the
  account's LVE physical-memory limit (default PMEM on the test VM: 1024M).
- Laravel templates: warning if "Restart if it stops" is not "always", since
  `queue:restart` relies on it **(my call)**.
- Expert: syntax errors and forbidden keys with line numbers.

### 5.4 Settings the plugin owns (never editable, hidden in all modes)

- `user`, and the wrapper that runs the command as the account via `su -`
  (entering LVE / CageFS).
- `process_name` (`%(program_name)s_%(process_num)02d`).
- Log file paths and rotation.
- `stopasgroup=true`, `killasgroup=true` - without them a stop through `su`
  can leave orphaned PHP processes **(my call)**.
- Program name, prefixed with the account (`acme_laravel-worker`) so two
  accounts never collide.

### 5.5 Templates in v1

| Template | Prefilled command | Required file | Notes |
|---|---|---|---|
| Laravel queue worker | `php artisan queue:work --sleep=3 --tries=3 --max-time=3600` | `artisan` | Deploys use `php artisan queue:restart` |
| Laravel Horizon | `php artisan horizon` | `artisan` + `vendor/laravel/horizon` | Processes fixed to 1; Horizon spawns its own children, which the plugin's process count cannot see - say so in the UI. Deploys use `horizon:terminate` |
| Symfony Messenger | `php bin/console messenger:consume async --time-limit=3600` | `bin/console` | Deploys use `messenger:stop-workers` |
| Custom command | empty | none | Any command, run as the account |

WHM decides which templates a package may use (6.2). This is a convenience to
keep the form simple, not a security boundary: any template runs the client's
own code (`artisan` is theirs), and the command box stays editable. The WHM
form says so **(my call)**. Default parameter values in the table are **(my
call)**.

### 5.6 SSH / script control

The backend is exposed as a cPanel UAPI module, so clients can script it over
SSH, e.g. `uapi WorkerSupervisor restart name=laravel-worker` **(my call,
borrowed from Oderland)**. Same permissions and limits as the UI.

## 6. The WHM side (root)

### 6.1 Access

The plugin registers a cPanel feature. Root turns it on or off per feature list
in WHM's Feature Manager, so it follows packages like any cPanel feature.

### 6.2 Limits on the package

Added to WHM's Add/Edit Package form (package extension):

- Max processes (all workers of the account combined).
- Max workers.
- Max stop wait (seconds).
- Templates allowed.

Per-account override from the WHM overview page. Root also sets server-wide
default limits there, used for packages with no values.

Resellers have no control in v1 **(my call)**: limit values on packages owned
by a reseller are ignored and the server defaults apply; the WHM overview page
is root-only. Reseller support (caps per reseller, overview of their own
accounts) is a later version.

CPU and memory are not
plugin limits: workers run inside the account's LVE, which already caps them
(`/etc/pam.d/su` on CloudLinux loads `pam_lve.so` - confirmed on the test VM).

Moving an account to a smaller package: running workers keep running; the
client cannot add or grow workers until under the limit; client page and WHM
overview show the overage **(my call)**.

### 6.3 Overview page (WHM > Plugins > Worker Supervisor)

- Health line: supervisord version and running state, worker / process / failed
  counts.
- Every worker on the server: account, worker, status, processes, actions
  (Logs, Config, Start/Stop/Restart). Filters: All / Failed / Stopped /
  Suspended, search by account.
- Root can view any worker's logs and generated config.
- When root stops a worker it becomes "Stopped by administrator": the client
  sees it but cannot start it until root releases it **(my call)**.
- A stopped worker stays stopped across reboots; stored state wins over
  autostart **(my call)**.

### 6.4 Account lifecycle (cPanel hooks)

- Suspend account -> its workers stop and show "Suspended"; unsuspend -> they
  return to their previous state.
- Terminate account -> its workers and logs are removed.
- Username change -> workers renamed (program prefix, log folder, data).
- Package change -> limits re-checked (6.2).
- Feature turned off for an account (feature list or package change) -> its
  workers stop as if suspended and come back when the feature is turned on
  again **(my call)**. There is no cPanel hook for every feature-list edit, so
  a periodic reconcile job catches what the hooks miss.

### 6.5 Import existing files

Button on the overview page: finds hand-written `.ini` files in
`/etc/supervisord.d/`, lets root pick the owning account, shows the converted
managed worker (and what changes: wrapper, log location, rejected keys) before
applying, keeps a backup of the original **(my call: conversion rather than
leaving the file as-is, because the original pattern has the security issues
in 7.2 and 7.3)**.

## 7. Security model

The client is untrusted. Root-side code only accepts a fixed set of actions
with typed, validated arguments, and enforces every limit itself (never trusts
the UI).

### 7.1 Execution

Workers run as the account through CloudLinux's
`/usr/sbin/cagefs_enter_user --no-fork <user> /bin/bash -lc "<script>"`, started
by the root wrapper with a clean environment (`env -i HOME USER LOGNAME SHELL
PATH`). Verified on the test VM (2026-10-31):

- The process lands in the account's CageFS (own mount namespace, filtered
  `/etc/passwd`, only its own `/home`) and in its LVE.
- `--no-fork` execs in place: the worker is supervisord's direct child and
  process-group leader, so `stopasgroup` / `killasgroup` reach every child and
  `stopwaitsecs` is honoured (TERM -> 10 s graceful finish -> clean exit, no
  orphans).
- Works for accounts with `noshell` as login shell, because bash is invoked
  explicitly. This gives such clients nothing new: inside CageFS they can
  already run any command through cron.
- An account with CageFS disabled by root runs uncaged, exactly like its cron
  jobs.

`su - <user> -c` (the current hand-written pattern) is rejected: on stop, `su`
sends TERM, waits about 2 seconds, then SIGKILLs the child, so a Laravel job is
cut off regardless of `stopwaitsecs`; and the worker sits in a different
process group, so its children are orphaned (both reproduced on the VM).

The bash script is `cd -- <folder> && exec /bin/bash -c <client command>`.
For a single simple command, `bash -c` execs it directly, so the client's
process replaces bash and receives supervisord's signals itself. Compound
commands (`a && b`, `FOO=1 cmd`, `source ... && node app.js`) also work, but
bash stays the group leader; `stopasgroup` still signals every process, yet
supervisord treats the worker as stopped as soon as bash exits, so graceful
stop is only guaranteed for a single command. The README recommends the folder
field instead of `cd` and a single command.

### 7.2 Client text never reaches supervisord's config parser **(my call)**

Supervisord splits `command=` itself and expands `%(...)s` in it. Embedding
client text inside `su -c "..."` (the current hand-written pattern) lets a
crafted command add arguments to `su`, which runs as root. Instead:

- The generated `.ini` points `command=` at a root-owned wrapper with only
  plugin-generated, validated arguments (account, worker id).
- The wrapper reads the stored command and folder from the plugin's root-owned
  data, then runs `cagefs_enter_user --no-fork <user> /bin/bash -lc ...`
  passing folder and command as separate positional arguments after the
  username, doing the `cd` as the user (see 7.1).
- No `directory=` pointing into the client's home (supervisord would `chdir` as
  root into a client-controlled path).

### 7.3 Logs **(my call)**

Supervisord writes `stdout_logfile` as root. A log file in a client-writable
folder (as in the current production file) can be replaced by a symlink to a
system file, which supervisord would then append to as root. Logs therefore
live in a root-owned directory per account, outside the home; the client reads
them only through the plugin. Rotation via supervisord's
`stdout_logfile_maxbytes` / `_backups`, defaults chosen by the builder.

### 7.4 Input validation

- Worker name: short lowercase slug.
- Folder: real path inside `/home/<user>/`, no symlink escapes.
- Numbers within package limits; booleans and enums only from fixed lists.
- Expert mode: allow-list of keys.

The owner should also review the existing production `.ini` files for the
issues in 7.2 and 7.3.

## 8. Packaging, platforms, stack

- **Platforms:** cPanel on CloudLinux 8 and 9 only (owner's decision). The
  installer refuses servers without CloudLinux, LVE and CageFS. Minimum cPanel
  version left to the builder.
- **Supervisor:** installed from EPEL by the installer if missing; service
  enabled. Managed files go in `/etc/supervisord.d/`.
- **Install / uninstall:** one install script registers everything (cPanel
  page, WHM page, feature, package extension, hooks, UAPI module, privileged
  helper). Uninstall removes all of it; managed workers are stopped and their
  `.ini` removed unless a keep flag is passed **(my call)**.
- **Languages:** English and French, following the cPanel user's locale.
- **Stack (my call):** PHP everywhere the team will maintain logic: the cPanel
  and WHM pages and the core (validation, limits, `.ini` generation,
  `supervisorctl` calls, logs). The core runs as root with cPanel's bundled PHP
  (`/usr/local/cpanel/3rdparty/bin/php`), never the client's PHP. Perl only
  where cPanel requires it (custom UAPI module, admin binary entry point), as
  thin glue that forwards to the PHP core with no logic of its own.
- **Source of truth (my call):** a root-owned data file per account holds the
  worker definitions; `.ini` files are generated from it.
- **Open source (my call):** public GitHub repository, MIT license, display
  name "Worker Supervisor". Repository name left to the owner.

## 9. Test environment

Local QEMU/KVM VM, managed with `dev/vm/vm.sh`
(`start|stop|status|ssh|whm [user]|snapshot|restore|snapshots|destroy`).

- CloudLinux 9.8, CloudLinux kernel, LVE loaded, cgroup v1, CageFS enabled for
  all users. cPanel & WHM 11.138, trial license valid until about 2026-11-15
  (bound to the public IP); a cPanel developer license should be
  requested before then.
- Snapshots: `os-clean`, `cpanel-installed`, `cloudlinux-clean`.
- Ports on 127.0.0.1: SSH 2222, WHM 2087, cPanel 2083, webmail 2096,
  HTTP 8080, HTTPS 8443.
- Default LVE limits on the VM: PMEM 1024M, NPROC 100, EP 20.

### 9.1 Verified on the VM (2026-10-31)

1. CageFS + LVE entry, graceful stop, no orphans: see 7.1.
2. `php` (`/usr/local/bin/php`, cPanel's MultiPHP wrapper) follows the
   folder: account default (8.2) in the home folder, the vhost's version (8.3)
   inside `public_html` and its subfolders. The live check should display the
   resolved version, since apps outside a docroot get the account default.
3. `uapi` works from inside CageFS (through CageFS `proxyexec`).
4. EL9: `supervisor-4.2.5` from EPEL (already enabled on CloudLinux),
   includes `/etc/supervisord.d/*.ini`, socket `/run/supervisor/supervisor.sock`.

### 9.2 Still to verify during the build

1. Long-running workers against LVE limits (PMEM 1024M, NPROC 100 by default
   on the VM): what happens at the memory limit, and whether anything on a
   CloudLinux server reaps long-running PHP processes.
2. Supervisor version and paths on CloudLinux 8.

## 10. Left to the builder

- Exact UI implementation within cPanel's Jupiter theme and WHM.
- Data file format and location, wrapper and helper file locations.
- Log rotation defaults, log viewer line count options.
- Initial values of the server-wide default limits.
- Log size quota per account.
- Locking for concurrent edits.
- Minimum supported cPanel version.
- Test strategy (the VM is available; snapshots allow clean-install tests).
