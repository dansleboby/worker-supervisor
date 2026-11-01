# Changelog

All notable changes to this project are documented here. Versions follow [Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-11-01

- Workers are now part of account backups and come back on restore: a pkgacct component adds them to cPanel backups, transfers and JetBackup 5 backups, and a `PkgAcct::Restore` hook restores them in their saved state, validated against the limits of the restoring server. Workers whose folder is not restored yet (JetBackup restores the home directory last) are added by reconcile once it exists.
- The uninstaller removes the upcp marker of the WHM app.
- Core files are opened close-on-exec, so no lock reaches a client's processes.

## [1.0.0] - 2026-11-01

First public release.

- cPanel clients create, edit, start, stop, restart and delete their own supervisord workers, from templates (Laravel queue worker, Laravel Horizon, Symfony Messenger, custom command) in a simple, advanced or expert mode, and read or clear their logs.
- The same actions over SSH and in deploy scripts through the `WorkerSupervisor` UAPI module.
- Workers run as the account inside its CloudLinux CageFS and LVE, with graceful stops.
- Limits per package (max processes, max workers, max stop wait, allowed templates), server defaults and per-account overrides in WHM.
- WHM overview of every worker on the server, with logs, generated config and admin stop, start and restart.
- Import of hand-written `/etc/supervisord.d/*.ini` programs.
- Account suspension, termination, username change and package change handled by cPanel hooks; a 5-minute reconcile repairs drift.
- English and French interface.

[1.1.0]: https://github.com/dansleboby/worker-supervisor/releases/tag/v1.1.0
[1.0.0]: https://github.com/dansleboby/worker-supervisor/releases/tag/v1.0.0
