#!/bin/bash
# VM test for reading worker logs from the home directory: the account's log directory is root's
# and its group's (2750), ~/logs/worker_supervisor links to it, and inside CageFS the account sees
# its own directory only, read-only. Uses bin/wsv directly, not UAPI. Runs as root on the VM after
# dev/deploy.sh:
#   dev/vm/vm.sh ssh 'bash -s' < tests/vm/logs.sh

main() {
  set -Eeuo pipefail
  trap 'echo "FAIL: line $LINENO: $BASH_COMMAND"' ERR

  user=wshell
  home=/home/$user
  app=$home/wsv-logs-app
  dir=/var/log/worker_supervisor/$user
  link=$home/logs/worker_supervisor
  wsv=/usr/local/cpanel/3rdparty/worker_supervisor/bin/wsv

  cleanup
  trap cleanup EXIT
  install -d -o "$user" -g "$user" "$app"
  printf '{"user":"%s","args":{"input":{"name":"logs-probe","template":"custom","folder":"%s","command":"while true; do echo probe-line; sleep 1; done","startsecs":1,"stopwaitsecs":5}}}' \
    "$user" "$app" | "$wsv" save | jq -e '.ok' >/dev/null || fail "cannot save logs-probe"
  wait_for 20 "logs-probe wrote nothing" grep -q probe-line "$dir/logs-probe_00.log"

  [[ $(stat -c '%a %U %G' /var/log/worker_supervisor) == "711 root root" ]] || fail "logs root: $(stat -c '%a %U %G' /var/log/worker_supervisor)"
  [[ $(stat -c '%a %U %G' "$dir") == "2750 root $user" ]] || fail "$dir: $(stat -c '%a %U %G' "$dir")"
  [[ $(stat -c '%G' "$dir/logs-probe_00.log") == "$user" ]] || fail "the log file is not the account's group"
  echo "PASS: the log directory is root's and readable by the account's group only"

  [[ $(readlink "$link") == "$dir" && $(stat -c '%U' -- "$link") == "$user" ]] || fail "no link made by $user: $(ls -la "$home/logs")"
  echo "PASS: ~/logs/worker_supervisor links to it, made by the account"

  grep -qxF '%/var/log/worker_supervisor' /etc/cagefs/cagefs.mp || fail "cagefs.mp lacks the logs mount"
  cagefs_enter_user "$user" /bin/sh -c "grep -q probe-line $link/logs-probe_00.log" || fail "the account cannot read its logs inside CageFS"
  [[ $(cagefs_enter_user "$user" /bin/sh -c 'ls /var/log/worker_supervisor') == "$user" ]] || fail "CageFS shows other accounts' log directories"
  if cagefs_enter_user "$user" /bin/sh -c "echo x >> $link/logs-probe_00.log" 2>/dev/null; then fail "the account can write its logs"; fi
  if runuser -u "$user" -- /bin/sh -c "ls /var/log/worker_supervisor/" >/dev/null 2>&1; then fail "the account can list the logs root outside CageFS"; fi
  echo "PASS: inside CageFS the account reads its own logs only, read-only"

  # A link the client removed comes back; one of their own stays.
  rm -f "$link"
  "$wsv" reconcile | jq -e --arg u "$user" '.data.linked | index($u) != null' >/dev/null || fail "reconcile did not restore the link"
  [[ $(readlink "$link") == "$dir" ]] || fail "the link was not restored"
  echo "PASS: reconcile restores a removed link"

  echo "--- the account's processes survive"
  setsid cagefs_enter_user "$user" /bin/sh -c 'exec sleep 7802' </dev/null >/dev/null 2>&1 &
  wait_for 10 "the test process did not start" survivor_running
  survivor=$(pgrep -f '^sleep 7802$')
  printf '{"user":"%s","args":{"input":{"name":"logs-second","template":"custom","folder":"%s","command":"exec sleep 7803","startsecs":1,"stopwaitsecs":5}}}' \
    "$user" "$app" | "$wsv" save | jq -e '.ok' >/dev/null || fail "cannot save logs-second"
  rm -f "$link"
  "$wsv" reconcile >/dev/null
  sleep 2
  [[ $(pgrep -f '^sleep 7802$') == "$survivor" ]] || fail "saving a worker or reconcile killed the account's processes (CageFS remount?)"
  kill "$survivor"
  echo "PASS: saving a worker and reconcile leave the account's other processes alone"

  echo "--- an account created after install"
  new=wsvlogsnew
  whmapi1 --output=json createacct username="$new" domain="$new.test" hasshell=1 password="$(openssl rand -base64 24)" \
    | jq -e '.metadata.result == 1' >/dev/null || fail "createacct $new"
  [[ $(stat -c '%a %G' "/var/log/worker_supervisor/$new") == "2750 $new" ]] || fail "the Accounts::Create hook did not prepare the log directory"
  install -d -o "$new" -g "$new" "/home/$new/wsv-logs-app"
  printf '{"user":"%s","args":{"input":{"name":"logs-new","template":"custom","folder":"/home/%s/wsv-logs-app","command":"while true; do echo new-line; sleep 1; done","startsecs":1,"stopwaitsecs":5}}}' \
    "$new" "$new" | "$wsv" save | jq -e '.ok' >/dev/null || fail "cannot save logs-new"
  wait_for 20 "$new cannot read its logs inside CageFS" \
    cagefs_enter_user "$new" /bin/sh -c "grep -q new-line /home/$new/logs/worker_supervisor/logs-new_00.log"
  echo "PASS: an account created after install reads its logs inside CageFS, no remount"

  other=wnoshell
  install -d -o "$other" -g "$other" "/home/$other/wsv-logs-app"
  printf '{"user":"%s","args":{"input":{"name":"logs-new","template":"custom","folder":"/home/%s/wsv-logs-app","command":"exec sleep 7804","startsecs":1,"stopwaitsecs":5}}}' \
    "$other" "$other" | "$wsv" save | jq -e '.ok' >/dev/null || fail "cannot save logs-new for $other"

  echo "--- logs of an earlier account of the name"
  file=/var/cpanel/worker_supervisor/accounts/$other.json
  cp -p "$file" "$dir.account.json"
  jq '.identity.uid = 4242' "$dir.account.json" >"$file"
  "$wsv" reconcile >/dev/null || true
  stat_other=$(stat -c '%a %G' "/var/log/worker_supervisor/$other")
  cp -p "$dir.account.json" "$file"
  rm -f "$dir.account.json"
  [[ $stat_other == "700 root" ]] || fail "the logs of an earlier account stayed readable: $stat_other"
  "$wsv" reconcile >/dev/null || true
  [[ $(stat -c '%a %G' "/var/log/worker_supervisor/$other") == "2750 $other" ]] || fail "the logs did not come back to $other"
  echo "PASS: the logs of an earlier account of the name are root's alone"

  echo "LOGS OK"
}

survivor_running() {
  pgrep -f '^sleep 7802$' >/dev/null
}

wait_for() {
  local deadline=$((SECONDS + $1)) msg=$2
  shift 2
  until "$@" 2>/dev/null; do
    (( SECONDS < deadline )) || fail "$msg"
    sleep 1
  done
}

fail() {
  echo "FAIL: $*"
  exit 1
}

cleanup() {
  printf '{"user":"%s","args":{"name":"logs-probe"}}' "$user" | "$wsv" delete >/dev/null 2>&1 || true
  printf '{"user":"%s","args":{"name":"logs-second"}}' "$user" | "$wsv" delete >/dev/null 2>&1 || true
  pkill -f '^sleep 7802$' || true
  if [[ -e /var/cpanel/users/wsvlogsnew ]]; then
    /scripts/removeacct --force wsvlogsnew >/dev/null 2>&1 || true
  fi
  printf '{"user":"wnoshell","args":{"name":"logs-new"}}' | "$wsv" delete >/dev/null 2>&1 || true
  rm -rf -- "$app" /home/wnoshell/wsv-logs-app
}

main "$@"
