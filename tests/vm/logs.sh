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

  echo "--- a log directory made after the CageFS mount"
  other=wnoshell
  # As for an account whose first worker comes after its cage was mounted.
  rm -rf "/var/log/worker_supervisor/$other"
  rm -f "/home/$other/logs/worker_supervisor"
  cagefsctl --remount "$other" >/dev/null
  install -d -o "$other" -g "$other" "/home/$other/wsv-logs-app"
  printf '{"user":"%s","args":{"input":{"name":"logs-new","template":"custom","folder":"/home/%s/wsv-logs-app","command":"while true; do echo new-line; sleep 1; done","startsecs":1,"stopwaitsecs":5}}}' \
    "$other" "$other" | "$wsv" save | jq -e '.ok' >/dev/null || fail "cannot save logs-new"
  wait_for 20 "$other cannot read its new logs inside CageFS" \
    cagefs_enter_user "$other" /bin/sh -c "grep -q new-line /home/$other/logs/worker_supervisor/logs-new_00.log"
  echo "PASS: a new account sees its logs inside CageFS at once"

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
  printf '{"user":"wnoshell","args":{"name":"logs-new"}}' | "$wsv" delete >/dev/null 2>&1 || true
  rm -rf -- "$app" /home/wnoshell/wsv-logs-app
}

main "$@"
