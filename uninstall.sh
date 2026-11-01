#!/bin/bash
# Worker Supervisor uninstaller: undoes install.sh, in reverse order where it matters.
#   ./uninstall.sh [--keep-workers] [--purge]
# Safe to re-run: every step skips what is already gone. A failed step is reported and the run goes
# on (exit 1 at the end), except the hooks: while they cannot be removed, nothing else is touched.
set -uo pipefail

src=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
app=/usr/local/cpanel/3rdparty/worker_supervisor
data=/var/cpanel/worker_supervisor
logs=/var/log/worker_supervisor
hook=/usr/local/cpanel/3rdparty/bin/worker_supervisor_hook
php=/usr/local/cpanel/3rdparty/bin/php
cpbin=/usr/local/cpanel/bin

failed=0
die() { echo "uninstall.sh: $*" >&2; exit 1; }
warn() { echo "uninstall.sh: warning: $*" >&2; failed=1; }
say() { echo "- $*"; }

# remove <path>...: reports each path it deletes; absent paths are skipped silently.
remove() {
  local p
  for p; do
    [[ -e $p || -L $p ]] || continue
    if rm -rf -- "$p"; then say "removed $p"; else warn "cannot remove $p"; fi
  done
}

# Directories install.sh deploys through a .new/.old swap, with any leftover of an interrupted swap.
remove_deployed() {
  local d
  for d; do remove "$d" "${d%/*}/.${d##*/}.new" "${d%/*}/.${d##*/}.old"; done
}

# api <function> [key=value...]: whmapi1 JSON reply on stdout; fails, with a warning, unless it succeeded.
api() {
  local reply
  reply=$("$cpbin/whmapi1" --output=json "$@")
  if ! "$php" -r 'exit((json_decode(stream_get_contents(STDIN), true)["metadata"]["result"] ?? 0) == 1 ? 0 : 1);' <<<"$reply"; then
    warn "whmapi1 $1 failed: $reply"
    return 1
  fi
  printf '%s\n' "$reply"
}

keep_workers=0 purge=0
for arg; do
  case $arg in
    --keep-workers) keep_workers=1 ;;
    --purge) purge=1 ;;
    *) die "usage: uninstall.sh [--keep-workers] [--purge]" ;;
  esac
done
(( keep_workers && purge )) && die "--keep-workers cannot be combined with --purge: kept workers start from the launch files in $data"
[[ $EUID -eq 0 ]] || die "must run as root"

# Hooks first: the hook script requires the core, and a missing core is a PHP fatal its own catch
# never sees.
hooks=$("$cpbin/manage_hooks" list --output JSON | "$php" -r '
  $all = json_decode(stream_get_contents(STDIN), true);
  if (!is_array($all)) { exit(1); }
  foreach ($all as $category => $events) {
    foreach (is_array($events) ? $events : [] as $event => $list) {
      foreach (is_array($list) ? $list : [] as $h) {
        if (($h["hook"] ?? "") === $argv[1]) { echo $category, " ", $event, " ", $h["stage"], "\n"; }
      }
    }
  }' "$hook" | sort -u) || die "cannot list the registered hooks; nothing was changed"
while read -r category event stage; do
  [[ -n $category ]] || continue
  # One delete removes every registration matching category, event and stage.
  out=$("$cpbin/manage_hooks" delete script "$hook" --manual 1 --category "$category" --event "$event" \
    --stage "$stage" 2>&1) || die "manage_hooks delete $category $event $stage failed: $out"
  say "removed hook $category::$event ($stage)"
done <<<"$hooks"
remove "$hook" "$hook.new"
remove /etc/cron.d/worker_supervisor

# Client entry points: the cPanel icon and pages, UAPI and the admin module.
plugin=0
for f in /usr/local/cpanel/whostmgr/addonfeatures/worker_supervisor \
  /usr/local/cpanel/base/frontend/jupiter/dynamicui/dynamicui_worker_supervisor.conf \
  /usr/local/cpanel/base/frontend/jupiter/assets/application_icons/worker_supervisor.svg; do
  [[ -e $f ]] && plugin=1
done
if (( plugin )); then
  # Reads the same install.json as install_plugin to know which link, icon and feature to drop.
  if out=$(/usr/local/cpanel/scripts/uninstall_plugin "$src/cpanel" --theme=jupiter 2>&1); then
    say "removed the cPanel plugin (icon and Feature Manager entry)"
  else
    warn "uninstall_plugin failed: $out"
  fi
fi
remove_deployed /usr/local/cpanel/base/frontend/jupiter/worker_supervisor
remove /usr/local/cpanel/Cpanel/API/WorkerSupervisor.pm /usr/local/cpanel/Cpanel/API/.WorkerSupervisor.pm.new \
  /var/cpanel/perl/Cpanel/Admin/Modules/WorkerSupervisor
remove /var/cpanel/perl/Cpanel/Pkgacct/Components/WorkerSupervisor.pm \
  /var/cpanel/perl/Cpanel/Pkgacct/Components/.WorkerSupervisor.pm.new

# WHM: the pages go before the AppConfig, whose removal rebuilds WHM's plugin cache.
remove_deployed /usr/local/cpanel/whostmgr/docroot/cgi/worker_supervisor
remove /usr/local/cpanel/whostmgr/docroot/addon_plugins/worker_supervisor.png
if [[ $("$cpbin/is_registered_with_appconfig" whostmgr worker_supervisor) == 1 ]]; then
  # From /: a readable file named like the app in the working directory would be parsed instead.
  # Exits 0 even when it cannot delete the entry, hence the second check.
  out=$(cd / && "$cpbin/unregister_appconfig" worker_supervisor 2>&1)
  if [[ $("$cpbin/is_registered_with_appconfig" whostmgr worker_supervisor) == 1 ]]; then
    warn "unregister_appconfig failed: $out"
  else
    say "unregistered the WHM AppConfig"
  fi
fi
# upcp records the cPanel version it last saw for every registered AppConfig app.
remove /var/cpanel/version/addons_update/whostmgr_worker_supervisor

# With every entry point gone, wait out a reconcile, hook or queued supervisorctl job that could
# still rewrite a program file.
core="($app/bin/wsv|$hook)( |\$)|/usr/bin/flock /run/worker_supervisor/"
deadline=$((SECONDS + 120))
while pgrep -f "$core" >/dev/null; do
  if (( SECONDS >= deadline )); then
    warn "still running after 120 s: $(pgrep -af "$core" | tr '\n' ';')"
    break
  fi
  sleep 1
done

shopt -s nullglob
inis=(/etc/supervisord.d/wsv_*.ini)
if (( keep_workers )); then
  (( ${#inis[@]} == 0 )) || say "kept ${#inis[@]} managed program(s) in /etc/supervisord.d with their launcher and data"
else
  supervisord=0
  systemctl is-active --quiet supervisord && supervisord=1
  programs=()
  for ini in "${inis[@]}"; do
    program=${ini##*/wsv_}
    programs+=("${program%.ini}")
  done
  if (( supervisord && ${#programs[@]} > 0 )); then
    # Graceful, so each stop can wait up to its worker's stopwaitsecs: several at a time keeps the
    # total near the longest one. A program that is not loaded just fails here.
    printf '%s:*\n' "${programs[@]}" | xargs -P 8 -n 1 supervisorctl stop >/dev/null 2>&1
  fi
  for i in "${!inis[@]}"; do
    ini=${inis[i]} program=${programs[i]}
    if (( supervisord )); then
      # `remove` works with the file still in place: a run interrupted before the unlink finds the
      # file again and finishes the job.
      supervisorctl remove "$program" >/dev/null 2>&1
      supervisorctl status "$program:*" >/dev/null 2>&1
      # 4: no such group.
      if (( $? != 4 )); then
        warn "$program is still loaded in supervisord; kept $ini"
        continue
      fi
    fi
    if rm -f -- "$ini"; then say "stopped and removed program $program"; else warn "cannot remove $ini"; fi
  done
  remove /etc/supervisord.d/wsv_*.ini.tmp.*
  # Until a reread, supervisord keeps the removed programs' parsed config (`supervisorctl avail`).
  # Unlike update, reread starts or stops nothing, so pending edits of other programs stay pending.
  if (( supervisord && ${#inis[@]} > 0 )); then
    supervisorctl reread >/dev/null || warn "supervisorctl reread failed"
  fi
fi

# Packages first, files second: delpkgext strips the extension's keys from a package and from every
# account on it, and finds those keys through the extension file. (editpkg leaves the accounts alone:
# its remove_missing_extensions takes extension names, not a flag.) It finds the accounts through
# /etc/userplans, which cPanel refreshes in the background, hence updateuserdomains first.
extensions_ok=0
/usr/local/cpanel/scripts/updateuserdomains --force || warn "updateuserdomains failed"
if listing=$(api listpkgs); then
  extensions_ok=1
  packages=$("$php" -r '
    foreach (json_decode(stream_get_contents(STDIN), true)["data"]["pkg"] ?? [] as $p) {
      if (preg_match("/(^|\s)worker_supervisor(\s|$)/", (string) ($p["_PACKAGE_EXTENSIONS"] ?? "")) === 1) {
        echo $p["name"], "\n";
      }
    }' <<<"$listing")
  while IFS= read -r package; do
    [[ -n $package ]] || continue
    if api delpkgext name="$package" _DELETE_EXTENSIONS=worker_supervisor >/dev/null; then
      say "removed the Worker Supervisor limits from package $package and its accounts"
    else
      extensions_ok=0
    fi
  done <<<"$packages"
fi
if (( extensions_ok )); then
  # Out of delpkgext's reach: accounts whose current package does not list the extension.
  stale=$(grep -lE '^_PACKAGE_EXTENSIONS=(.* )?worker_supervisor( |$)' /var/cpanel/users/* 2>/dev/null | sed 's#.*/##' | tr '\n' ' ')
  [[ -z $stale ]] || say "note: inert Worker Supervisor package keys remain in these accounts: $stale"
  remove /var/cpanel/packages/extensions/worker_supervisor /var/cpanel/packages/extensions/worker_supervisor.tt2 \
    /var/cpanel/template_compiles/var/cpanel/packages/extensions/worker_supervisor.tt2
else
  warn "kept the package extension files so a re-run can still clean the packages"
fi

if (( keep_workers && ${#inis[@]} > 0 )) && [[ -d $app ]]; then
  # The kept programs still start through bin/wsv-run.
  if find "$app" -mindepth 1 -depth ! -path "$app/bin" ! -path "$app/bin/wsv-run" -delete; then
    say "removed $app except bin/wsv-run"
  else
    warn "cannot clean $app"
  fi
else
  remove "$app"
fi
remove /run/worker_supervisor

if (( purge )); then
  remove "$data" "$logs"
else
  for d in "$data" "$logs"; do
    [[ ! -e $d ]] || say "kept $d (--purge deletes it)"
  done
fi

/usr/local/cpanel/scripts/restartsrv_cpsrvd >/dev/null 2>&1 || warn "restartsrv_cpsrvd failed"

if (( failed )); then
  echo "Worker Supervisor uninstall finished with warnings; fix them and run it again." >&2
  exit 1
fi
echo "Worker Supervisor uninstalled. supervisor stays installed."
