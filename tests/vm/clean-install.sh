#!/bin/bash
# Clean-install test, run on the host. Restores the VM to the cloudlinux-clean snapshot (cPanel,
# CloudLinux and CageFS only: no supervisor, plugin or test accounts), creates the test accounts,
# installs, runs the VM tests, checks each uninstall mode and that nothing of the plugin remains,
# then reinstalls so the VM is left usable. The VM's current state is lost: snapshot it first
# (dev/vm/vm.sh stop && dev/vm/vm.sh snapshot <name>).
#   tests/vm/clean-install.sh [--no-restore]
# --no-restore runs the same steps on the VM as it is.
set -Eeuo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.."

app=/usr/local/cpanel/3rdparty/worker_supervisor

main() {
  trap 'echo "FAIL: line $LINENO: $BASH_COMMAND (exit $?)"' ERR
  if [[ ${1:-} != --no-restore ]]; then
    dev/vm/vm.sh stop
    dev/vm/vm.sh restore cloudlinux-clean
    dev/vm/vm.sh start
  fi
  # sshd answers early in the boot and can still be restarted before it completes.
  local deadline=$((SECONDS + 300))
  until [[ $(vm 'systemctl is-system-running --wait' 2>/dev/null) =~ ^(running|degraded)$ ]]; do
    (( SECONDS < deadline )) || fail "the VM did not finish booting in 300 s"
    sleep 5
  done

  on_vm prepare
  dev/deploy.sh
  vm_test tests/vm/uapi.sh "UAPI OK"
  vm_test tests/vm/launcher.sh "LAUNCHER OK"
  vm_test tests/vm/backup.sh "BACKUP OK"
  on_vm check_uninstall
  echo "CLEAN INSTALL OK"
}

fail() {
  echo "FAIL: $*"
  exit 1
}

vm() { dev/vm/vm.sh ssh "$@"; }

# on_vm <function> [args...]: runs one of this file's functions as root on the VM.
on_vm() {
  {
    echo 'set -Eeuo pipefail'
    echo "trap 'echo \"FAIL: \$BASH_COMMAND\"' ERR"
    declare -p app
    declare -f
    printf '%q ' "$@"
    echo
  } | vm 'cd / && bash -s'
}

# vm_test <script> <last line on success>
vm_test() {
  local out
  out=$(vm 'bash -s' < "$1") || true
  echo "$out"
  [[ $(tail -n 1 <<<"$out") == "$2" ]] || fail "$1 did not end with '$2'"
}

# --- Run on the VM ---

prepare() {
  command -v jq >/dev/null || dnf -y -q install jq
  create_account wshell 1
  create_account wnoshell 0
}

# create_account <user> <hasshell>
create_account() {
  [[ ! -e /var/cpanel/users/$1 ]] || return 0
  local reply start=$SECONDS refreshed=0
  # After a restore cPanel renews its license (bound to the public IP) in the background at boot,
  # and refuses new accounts until then. Force a renewal only if that one did not do it.
  until reply=$(whmapi1 --output=json createacct username="$1" domain="$1.test" hasshell="$2" \
    password="$(openssl rand -base64 24)") && jq -e '.metadata.result == 1' >/dev/null <<<"$reply"; do
    (( SECONDS < start + 300 )) || fail "createacct $1: $reply"
    echo "createacct $1 refused, retrying: $(head -c 300 <<<"$reply" | tr '\n' ' ')"
    if (( !refreshed && SECONDS > start + 90 )) && grep -qi licen <<<"$reply"; then
      /usr/local/cpanel/cpkeyclt --force || true
      refreshed=1
    fi
    sleep 15
  done
  echo "created account $1"
}

# api <function> [key=value...]: whmapi1 JSON reply on stdout; fails unless it succeeded.
api() {
  local reply
  reply=$(whmapi1 --output=json "$@") || true
  jq -e '.metadata.result == 1' >/dev/null <<<"$reply" || fail "whmapi1 $1: $reply"
  printf '%s\n' "$reply"
}

check_uninstall() {
  local src=/root/wsv-src pids version out
  version=$(<"$src/VERSION")
  [[ $("$app/bin/wsv" --version) == "$version" ]] || fail "wsv --version printed '$("$app/bin/wsv" --version)', not $version"
  echo "PASS: wsv --version prints $version"

  # A running two-process worker, and the extension on a package with an account on it.
  uapi wshell save_worker name=keeper template=custom folder=/home/wshell command='exec sleep 4242' \
    processes=2 startsecs=1 stopwaitsecs=5 autostart=1 | jq -e '.status == 1' >/dev/null
  wait_for 20 "keeper never reached RUNNING" keeper_running
  plan=$(sed -n 's/^PLAN=//p' /var/cpanel/users/wnoshell)
  api addpkg name=wsvclean _PACKAGE_EXTENSIONS=worker_supervisor worker_supervisor_max_processes=4 >/dev/null
  api changepackage user=wnoshell pkg=wsvclean >/dev/null
  extension_on_wnoshell

  echo "--- uninstall.sh --keep-workers"
  pids=$(keeper_pids)
  "$src/uninstall.sh" --keep-workers
  assert_removed keep
  [[ $(keeper_pids) == "$pids" ]] || fail "keeper was restarted or stopped: '$pids' -> '$(keeper_pids)'"
  [[ $(cd "$app" && find . -mindepth 1 | sort | tr '\n' ' ') == './bin ./bin/wsv-run ' ]] \
    || fail "$app holds more than bin/wsv-run: $(find "$app")"
  kill "${pids%% *}"
  wait_for 20 "supervisord did not restart keeper through the kept launcher" keeper_restarted "${pids%% *}"
  echo "PASS: keeper kept running and restarts through the kept launcher"

  # Stands in for an install of an older release.
  echo 0.9.0 >"$app/VERSION"
  out=$("$src/install.sh")
  grep -qxF "Worker Supervisor upgraded from 0.9.0 to $version." <<<"$out" || fail "install.sh did not report the upgrade: $out"
  [[ $(<"$app/VERSION") == "$version" ]] || fail "the installed VERSION is not $version"
  echo "PASS: install.sh reports the upgrade and records $version"
  keeper_running || fail "keeper not running after reinstall"
  api editpkg name=wsvclean _PACKAGE_EXTENSIONS=worker_supervisor >/dev/null
  extension_on_wnoshell

  echo "--- uninstall.sh"
  "$src/uninstall.sh"
  assert_removed data
  jq -e '.workers | any(.name == "keeper")' /var/cpanel/worker_supervisor/accounts/wshell.json >/dev/null \
    || fail "the data of keeper was not kept"
  [[ -e /var/log/worker_supervisor/wshell/keeper_00.log ]] || fail "the logs of keeper were not kept"
  echo "PASS: data and logs kept"
  "$src/install.sh"
  wait_for 20 "reinstall did not bring keeper back" keeper_running
  echo "PASS: reinstall brought keeper back from the kept data"
  api editpkg name=wsvclean _PACKAGE_EXTENSIONS=worker_supervisor >/dev/null
  extension_on_wnoshell

  echo "--- uninstall.sh --purge"
  "$src/uninstall.sh" --purge
  assert_removed purge
  echo "--- uninstall.sh --purge, again"
  "$src/uninstall.sh" --purge
  assert_removed purge
  if "$src/uninstall.sh" --keep-workers --purge 2>/dev/null; then fail "--keep-workers --purge was accepted"; fi
  echo "PASS: --keep-workers --purge refused"

  api changepackage user=wnoshell pkg="$plan" >/dev/null
  # killpkg checks /etc/userplans, which cPanel otherwise refreshes in the background.
  /usr/local/cpanel/scripts/updateuserdomains --force
  api killpkg pkgname=wsvclean >/dev/null
  out=$("$src/install.sh")
  grep -qxF "Worker Supervisor $version installed." <<<"$out" || fail "install.sh did not report a fresh install: $out"
  uapi wshell list_workers | jq -e '.status == 1 and .data.workers == []' >/dev/null \
    || fail "list_workers after the final reinstall: $(uapi wshell list_workers)"
  echo "PASS: final reinstall answers with no workers"
}

# assert_removed keep|data|purge: nothing of the plugin is left beyond what the mode keeps.
assert_removed() {
  local mode=$1 allowed=() found
  case $mode in
    keep) allowed=("$app" /etc/supervisord.d/wsv_wshell_keeper.ini /var/cpanel/worker_supervisor /var/log/worker_supervisor) ;;
    data) allowed=(/var/cpanel/worker_supervisor /var/log/worker_supervisor) ;;
  esac

  # Everything named after the plugin, outside the source copy, CageFS copies, cPanel's
  # notification history (which keeps the AppConfig registration notice) and account backups
  # (JetBackup's destinations and workspace on a VM that has it), which keep worker_supervisor.json.
  found=$(find / -xdev \( -path /proc -o -path /root/wsv-src -o -path /home/virtfs -o -path /var/cagefs \
    -o -path /usr/share/cagefs-skeleton -o -path /var/cpanel/user_notifications -o -path /backup \
    -o -path /usr/local/jetapps \) -prune \
    -o \( -iname '*worker_supervisor*' -o -iname '*workersupervisor*' -o -name 'wsv_*' \) -print \
    | grep -vxF -f <(printf '%s\n' "${allowed[@]}" /nonexistent) || true)
  [[ -z $found ]] || fail "left behind: $found"
  [[ ! -e /run/worker_supervisor ]] || fail "left behind: /run/worker_supervisor"
  echo "PASS: no plugin files left ($mode)"

  ! /usr/local/cpanel/bin/manage_hooks list --output JSON | grep -qF worker_supervisor_hook \
    || fail "hooks still registered"
  echo "PASS: no hooks registered"

  [[ $(/usr/local/cpanel/bin/is_registered_with_appconfig whostmgr worker_supervisor) == 0 ]] \
    || fail "AppConfig still registered"
  ! /usr/local/cpanel/bin/show_appconfig | grep -q worker_supervisor || fail "show_appconfig lists the plugin"
  ! grep -q worker_supervisor /var/cpanel/pluginscache.yaml || fail "WHM plugin cache lists the plugin"
  echo "PASS: AppConfig unregistered"

  wait_for 60 "the Jupiter icon sprite or the UAPI spec still lists the plugin" caches_clean
  echo "PASS: icon sprite and UAPI spec rebuilt without the plugin"

  found=$(grep -rl worker_supervisor /var/cpanel/packages /var/cpanel/users /var/cpanel/features || true)
  [[ -z $found ]] || fail "package extension keys left in: $found"
  echo "PASS: no package extension keys in packages, accounts or feature lists"

  uapi wshell list_workers 2>/dev/null | jq -e '.status == 0 and (.errors[0] | contains("Failed to load module"))' >/dev/null \
    || fail "UAPI WorkerSupervisor still answers"
  echo "PASS: UAPI module gone"

  rpm -q supervisor >/dev/null && systemctl is-active --quiet supervisord || fail "supervisor was removed or stopped"
  if [[ $mode == keep ]]; then
    keeper_running || fail "keeper stopped"
  else
    ! supervisorctl avail | grep -q '^wshell_keeper' || fail "wshell_keeper still known to supervisord"
    ! pgrep -f '^sleep 4242$' >/dev/null || fail "a keeper process survived"
    echo "PASS: managed programs stopped and removed"
  fi
  if [[ $mode == purge ]]; then
    [[ ! -e /var/cpanel/worker_supervisor && ! -e /var/log/worker_supervisor ]] || fail "data or logs left"
    echo "PASS: data and logs purged"
  else
    [[ -d /var/cpanel/worker_supervisor && -d /var/log/worker_supervisor ]] || fail "data or logs not kept"
  fi
}

# Rebuilt by cPanel's task queue a few seconds after the uninstall.
caches_clean() {
  ! grep -q worker_supervisor /usr/local/cpanel/base/frontend/jupiter/assets/application_icons/sprites/icon_spritemap.css \
    && ! grep -q WorkerSupervisor /var/cpanel/api_spec/cpanel_uapi.json
}

extension_on_wnoshell() {
  wait_for 10 "wnoshell did not get the package extension keys" \
    grep -qx 'worker_supervisor_max_processes=[0-9]*' /var/cpanel/users/wnoshell
}

# uapi <user> <function> [key=value...]: the call's result object as one JSON line.
uapi() {
  local user=$1
  shift
  /usr/local/cpanel/bin/uapi --user="$user" --output=json WorkerSupervisor "$@" | jq -c .result
}

keeper_pids() {
  { pgrep -f '^sleep 4242$' || true; } | sort -n | tr '\n' ' '
}

keeper_running() {
  [[ $(supervisorctl status 'wshell_keeper:*' | grep -c RUNNING) == 2 ]]
}

# keeper_restarted <old pid>
keeper_restarted() {
  ! kill -0 "$1" 2>/dev/null && keeper_running
}

# wait_for <seconds> <failure message> <command...>
wait_for() {
  local deadline=$((SECONDS + $1)) msg=$2
  shift 2
  until "$@"; do
    (( SECONDS < deadline )) || fail "$msg"
    sleep 0.5
  done
}

main "$@"
