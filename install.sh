#!/bin/bash
# Worker Supervisor installer. Idempotent: re-running it upgrades in place.
set -euo pipefail

src=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
app=/usr/local/cpanel/3rdparty/worker_supervisor
data=/var/cpanel/worker_supervisor
logs=/var/log/worker_supervisor

die() { echo "install.sh: $*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "must run as root"
version=$(cat "$src/VERSION" 2>/dev/null || true)
[[ $version =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] || die "VERSION does not hold a semantic version"
previous=$(cat "$app/VERSION" 2>/dev/null || true)
if [[ ! -x /usr/sbin/cagefs_enter_user || ! -x /usr/sbin/lvectl ]] || ! grep -q CloudLinux /etc/redhat-release 2>/dev/null; then
  die "CloudLinux with LVE and CageFS is required"
fi

rpm -q supervisor >/dev/null 2>&1 || dnf -y install supervisor

# includes_programs <supervisord.conf>: whether its [include] files cover supervisord.d/*.ini (relative
# to /etc), where the generated programs go. EPEL's default does; a hand-edited config may not.
includes_programs() {
  awk '
    function scan(value,   n, t, i) {
      n = split(value, t, /[ \t]+/)
      for (i = 1; i <= n; i++) {
        if (t[i] ~ /^[;#]/) return
        if (t[i] == "supervisord.d/*.ini" || t[i] == "/etc/supervisord.d/*.ini") found = 1
      }
    }
    /^[ \t]*[;#]/ { next }
    /^\[/ { inc = ($0 ~ /^\[include\][ \t]*$/); files = 0; next }
    inc && /^files[ \t]*[=:]/ { files = 1; sub(/^files[ \t]*[=:]/, ""); scan($0); next }
    inc && files && /^[ \t]+[^ \t]/ { scan($0); next }
    { files = 0 }
    END { exit !found }
  ' "$1"
}
includes_programs /etc/supervisord.conf \
  || die "/etc/supervisord.conf does not load /etc/supervisord.d/*.ini: add 'files = supervisord.d/*.ini' to its [include] section, restart supervisord, then run install.sh again"
systemctl enable --now supervisord

# deploy_dir <from> <to> <file mode>: stage then swap, so files dropped by an upgrade disappear and a
# wsv-run being read by bash is never rewritten in place (only unlinked).
deploy_dir() {
  local from=$1 to=$2 mode=$3
  local new=${to%/*}/.${to##*/}.new old=${to%/*}/.${to##*/}.old
  rm -rf "$new" "$old"
  cp -R "$from" "$new"
  chown -R root:root "$new"
  find "$new" -type d -exec chmod 0755 {} +
  find "$new" -type f -exec chmod "$mode" {} +
  [[ ! -e $to ]] || mv "$to" "$old"
  mv "$new" "$to"
  rm -rf "$old"
}

install -d -m 0755 -o root -g root "$app"
deploy_dir "$src/src" "$app/src" 0644
deploy_dir "$src/bin" "$app/bin" 0755
deploy_dir "$src/lang" "$app/lang" 0644

install -d -m 0700 -o root -g root "$data" "$data/accounts" "$data/launch" "$data/backups"
# Crossable, not listable: each account reads its own log directory (root's, its group's) from
# ~/logs/worker_supervisor; reconcile below sets those directories and links up.
install -d -m 0711 -o root -g root "$logs"
chmod 0711 "$logs"

# Installed last so they only go live once the core they forward to is in place.
install_module() {
  local from=$1 to=$2 dir=${2%/*}
  # umask covers missing parents too: install -d applies -m to the leaf only.
  (umask 022 && install -d -m 0755 -o root -g root "$dir")
  # Renamed into place: cpsrvd and uapi load modules at any time. Only .pm files may stay in
  # /usr/local/cpanel/Cpanel/API, so the staging file is transient.
  install -m 0644 -o root -g root "$from" "$dir/.${to##*/}.new"
  mv -f "$dir/.${to##*/}.new" "$to"
}
install_module "$src/perl/Cpanel/Admin/Modules/WorkerSupervisor/Workers.pm" \
  /var/cpanel/perl/Cpanel/Admin/Modules/WorkerSupervisor/Workers.pm
install_module "$src/perl/Cpanel/API/WorkerSupervisor.pm" /usr/local/cpanel/Cpanel/API/WorkerSupervisor.pm
# Custom pkgacct component: adds the account's workers to cPanel and JetBackup account backups.
install_module "$src/perl/Cpanel/Pkgacct/Components/WorkerSupervisor.pm" \
  /var/cpanel/perl/Cpanel/Pkgacct/Components/WorkerSupervisor.pm

# install_plugin adds the icon and the Feature Manager entry but copies no pages, so the pages go
# first: the icon never links to a missing page.
theme=/usr/local/cpanel/base/frontend/jupiter
[[ -d $theme ]] || die "the cPanel Jupiter theme is required"
deploy_dir "$src/cpanel/pages" "$theme/worker_supervisor" 0644
# Gotchas: an existing /usr/local/cpanel/whostmgr/addonfeatures entry is never rewritten (a renamed
# plugin needs it removed first), and Jupiter's icon sprite keeps only <style> class rules of the SVG.
plugin=$(mktemp -d)
trap 'rm -rf "$plugin"' EXIT
tar -C "$src/cpanel" -czf "$plugin/worker_supervisor.tar.gz" install.json worker_supervisor.svg worker_supervisor.png
out=$(/usr/local/cpanel/scripts/install_plugin "$plugin/worker_supervisor.tar.gz" --theme=jupiter 2>&1) \
  || die "install_plugin failed: $out"

# Package extension: the directory does not exist until the first package is created.
install -d -m 0755 -o root -g root /var/cpanel/packages/extensions
install -m 0644 -o root -g root "$src/whm/package-extension/worker_supervisor" /var/cpanel/packages/extensions/worker_supervisor
install -m 0644 -o root -g root "$src/whm/package-extension/worker_supervisor.tt2" /var/cpanel/packages/extensions/worker_supervisor.tt2

# WHM pages and icon before the AppConfig, so the menu link never points to a missing page.
# register_appconfig copies the file to /var/cpanel/apps, rebuilds WHM's menu cache and restarts
# cpsrvd; re-registering replaces the entry.
deploy_dir "$src/whm/cgi" /usr/local/cpanel/whostmgr/docroot/cgi/worker_supervisor 0755
install -m 0644 -o root -g root "$src/whm/worker_supervisor.png" /usr/local/cpanel/whostmgr/docroot/addon_plugins/worker_supervisor.png
out=$(/usr/local/cpanel/bin/register_appconfig "$src/whm/worker_supervisor.conf" 2>&1) \
  || die "register_appconfig failed: $out"

/usr/local/cpanel/scripts/restartsrv_cpsrvd >/dev/null

# Account hooks run the core in-process. None is `blocking` and the script always reports success:
# a plugin failure must never stop an account action; the cron reconcile repairs what a hook missed.
hook=/usr/local/cpanel/3rdparty/bin/worker_supervisor_hook
install -m 0755 -o root -g root "$src/bin/wsv-hook" "$hook.new"
mv -f "$hook.new" "$hook"
registered=$(/usr/local/cpanel/bin/manage_hooks list --output JSON | /usr/local/cpanel/3rdparty/bin/php -r '
  foreach (json_decode(stream_get_contents(STDIN), true) ?? [] as $category => $events) {
    foreach (is_array($events) ? $events : [] as $event => $hooks) {
      foreach (is_array($hooks) ? $hooks : [] as $h) {
        if (($h["hook"] ?? "") === $argv[1]) { echo $category, ":", $event, ":", $h["stage"], "\n"; }
      }
    }
  }' "$hook")
# <category>:<event>:<stage>; PkgAcct:Restore:post restores the workers of a restored account.
for spec in Whostmgr:Accounts::Create:post Whostmgr:Accounts::suspendacct:post Whostmgr:Accounts::unsuspendacct:post \
  Whostmgr:Accounts::Remove:pre Whostmgr:Accounts::Remove:post Whostmgr:Accounts::Modify:pre \
  Whostmgr:Accounts::Modify:post Whostmgr:Accounts::change_package:post PkgAcct:Restore:post; do
  grep -qxF "$spec" <<<"$registered" && continue
  event=${spec#*:}
  out=$(/usr/local/cpanel/bin/manage_hooks add script "$hook" --manual 1 --category "${spec%%:*}" \
    --event "${event%:*}" --stage "${spec##*:}" 2>&1) || die "manage_hooks add $spec failed: $out"
done
install -m 0644 -o root -g root "$src/cron/worker_supervisor" /etc/cron.d/worker_supervisor

# CageFS shows each account its own log directory only (a % mount), so ~/logs/worker_supervisor
# also works in SSH, Terminal and cron. cagefs.mp has no drop-in directory. CageFS applies the line,
# and sees the directories, when it next mounts each cage: never remounted here, since a remount
# kills the account's processes (workers, SSH sessions, cron jobs). Every account gets its directory
# now; the Accounts::Create hook does the same for new accounts.
mp=/etc/cagefs/cagefs.mp
mount_line='%/var/log/worker_supervisor'
cagefs_line_added=0
if [[ -f $mp ]] && ! grep -qxF "$mount_line" "$mp"; then
  [[ -z $(tail -c 1 "$mp") ]] || echo >>"$mp"
  printf '%s\n' "$mount_line" >>"$mp"
  cagefs_line_added=1
fi
"$app/bin/wsv" logs_prepare >/dev/null \
  || echo "install.sh: warning: wsv logs_prepare failed, see journalctl -t worker_supervisor" >&2

# Workers kept in the data directory by an earlier uninstall come back now, not at the next cron run.
# An upgrade also gets every account's identity recorded and every launch file rewritten with the
# uid bin/wsv-run checks.
"$app/bin/wsv" reconcile >/dev/null \
  || echo "install.sh: warning: wsv reconcile failed, see journalctl -t worker_supervisor" >&2

# Last: the installed VERSION names the most recent install that completed.
install -m 0644 -o root -g root "$src/VERSION" "$app/VERSION"

if [[ -z $previous ]]; then
  echo "Worker Supervisor $version installed."
elif [[ $previous == "$version" ]]; then
  echo "Worker Supervisor $version reinstalled."
else
  echo "Worker Supervisor upgraded from $previous to $version."
fi
if (( cagefs_line_added )); then
  cat <<'CAGEFS'

Clients can read their worker logs in ~/logs/worker_supervisor/. Inside CageFS (SSH, Terminal),
existing accounts see them after their cage is next mounted, at the latest on the next reboot; new
accounts see them at once. To show them now, run: cagefsctl --remount-all
That restarts the processes of every account in CageFS (workers, SSH sessions, cron jobs): choose a
quiet moment.
CAGEFS
fi
cat <<'NOTICE'

Every account can use it now: cPanel turns a plugin feature on in every feature list that does not
turn it off. To restrict it, open WHM > Packages > Feature Manager and turn "Worker Supervisor" off
in the feature lists that should not have it, or in the "disabled" list to turn it off everywhere.
Accounts that lose the feature have their workers stopped within 5 minutes.
NOTICE
