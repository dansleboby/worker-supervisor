# Worker Supervisor - account backups and restores (1.1.0)

## Goal

An account's workers come back when the account is restored from a cPanel backup (WHM Backup
Restoration, `restorepkg`, Transfer Tool) or from JetBackup 5, exactly as they were at backup
time, without root recreating them by hand.

## Facts verified on the VM (cPanel 11.138, JetBackup 5.4.1.5, 2026-11-01)

- A custom pkgacct component in `/var/cpanel/perl/Cpanel/Pkgacct/Components/<Name>.pm`
  (subclass of `Cpanel::Pkgacct::Component`, `perform()`, `get_work_dir()`, `get_user()`) runs
  as root (euid 0) on `scripts/pkgacct`; a file it writes to the work dir lands at the root of the
  cpmove archive. A file written there by a `PkgAcct::Create` pre hook does not.
- `PkgAcct::Restore` `post` runs as root with `data.user`, `data.olduser` and `data.extract_dir`
  (`.../safe_to_read_archive/contents`, root-only); the component's file is in `extract_dir`.
  `postExtract` has no `extract_dir`.
- `restorepkg` over an existing account (`--force`) and after `removeacct` (new uid; STARTDATE
  restored from the archive) both run `Restore post`, after the home directory is restored.
- JetBackup 5 builds an account's "Panel Config" item with cPanel's pkgacct: custom components
  run and their files are stored in the snapshot's `config/` directory. Its account restore runs
  cPanel's restore, so `Restore post` fires with the file in `extract_dir`, but before JetBackup
  restores the home directory.

## Design

- Backup: component `Cpanel::Pkgacct::Components::WorkerSupervisor` runs `wsv backup_export`
  for the account and writes the reply to `worker_supervisor.json` in the work dir. Nothing is
  written for an account the plugin never stored. The component never dies: a failure is a
  warning in the pkgacct output and the backup goes on.
- Format: `{"format": 1, "version", "user", "home", "workers": [Worker::toArray()...]}`. Desired
  state and admin stops travel with the workers.
- Restore: the `PkgAcct::Restore post` hook stages the file as the account's `restoring` marker
  (`accounts/<user>.json.restoring`, bound to the account's Identity; removing the account drops
  it, a username change moves it) and applies it at once:
  - the account's workers become the backup's: missing ones are created, changed ones updated,
    ones absent from the backup deleted, identical ones left running untouched;
  - folders under the backup's home move to the account's home (other username, other home
    path);
  - every worker goes through the same validation as a client save (limits, templates, folder
    inside the home); one refused for limits or templates is skipped and logged;
  - one whose folder or required files do not exist yet (JetBackup restores the home after the
    hook) stays staged, and reconcile retries it every 5 minutes for 24 hours.
- The archive can come from another server, so its content is treated as untrusted input:
  size and count caps (1 MB, 200 workers), strict JSON shape, validation as above with a
  validator that spawns nothing (NullProbe), names logged only when valid. Admin stops and stopped
  states only remove privileges.
- The component writes as root only into a work dir whose every ancestor is root's and closed to
  others, with O_EXCL | O_NOFOLLOW; it never fails the backup.
- No JetBackup-specific code: its backup and restore go through the same component and hook.

## Left out

- A pkgacct that does not run as root: the component skips. A client's own full backup
  (`Backup::fullbackup_to_homedir`) does run it as root and includes the workers (checked).
- Restoring only the home directory (JetBackup) does not touch the workers: they are part of
  the account configuration.
- Transfer Tool: same restore code, not tested (needs two servers).
