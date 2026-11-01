#!/bin/bash
# VM test for account backups: the pkgacct component puts the workers in the archive, and the
# PkgAcct::Restore hook brings them back after removeacct + restorepkg, in their saved state, also
# under another username; a failing export leaves the backup without workers but successful.
# When JetBackup 5 is installed and licensed, the same through a JetBackup backup and restore,
# over the live account and after removeacct.
# The account is removed and restored: its uid changes. Runs as root on the VM after dev/deploy.sh:
#   dev/vm/vm.sh ssh 'bash -s' < tests/vm/backup.sh

main() {
  set -Eeuo pipefail
  trap 'echo "FAIL: line $LINENO: $BASH_COMMAND"' ERR

  user=wshell
  other=wsvrestored
  home=/home/$user
  app=$home/wsv-backup-app
  dir=/root/wsv-backup-test
  wsv=/usr/local/cpanel/3rdparty/worker_supervisor/bin/wsv
  # Non-ASCII on purpose: it goes through the component, the archive and the restore.
  cafe="echo 'café' && exec sleep 7778"

  cleanup
  trap cleanup EXIT
  install -d -o "$user" -g "$user" "$app"
  touch "$app/artisan" && chown "$user:" "$app/artisan"

  save bk-run processes=2 command='exec sleep 7777'
  save bk-stop command='exec sleep 7779'
  save bk-cafe command="$cafe"
  uapi stop_worker name=bk-stop >/dev/null
  wait_for 20 "bk-run never reached RUNNING" running bk-run 2

  echo "--- pkgacct"
  mkdir -p "$dir" "$dir/broken" "$dir/inc"
  /scripts/pkgacct "$user" "$dir" >"$dir/pkgacct.log" 2>&1
  tar xzf "$dir/cpmove-$user.tar.gz" -O "cpmove-$user/worker_supervisor.json" >"$dir/archived.json"
  jq -e --arg cafe "$cafe" '.format == 1 and .home == "/home/wshell"
    and ([.workers[] | select(.name | startswith("bk-"))] | map({name, desired, processes, command}))
      == [{name: "bk-run", desired: "running", processes: 2, command: "exec sleep 7777"},
          {name: "bk-stop", desired: "stopped", processes: 1, command: "exec sleep 7779"},
          {name: "bk-cafe", desired: "running", processes: 1, command: $cafe}]' "$dir/archived.json" >/dev/null \
    || fail "the archive does not hold the workers: $(cat "$dir/archived.json")"
  echo "PASS: the archive holds the workers"

  echo "--- incremental pkgacct"
  incremental

  echo "--- removeacct + restorepkg --newuser $other"
  /scripts/removeacct --force "$user" >"$dir/remove.log" 2>&1
  [[ ! -e /var/cpanel/worker_supervisor/accounts/$user.json ]] || fail "the removal kept the workers"
  /scripts/restorepkg --newuser "$other" "$dir/cpmove-$user.tar.gz" >"$dir/restore-newuser.log" 2>&1
  check_restored "restorepkg --newuser" "$other"
  uapi_as "$other" get_worker name=bk-run | jq -e --arg f "/home/$other/wsv-backup-app" '.data.worker.folder == $f' >/dev/null \
    || fail "the folder did not move to /home/$other"
  /scripts/removeacct --force "$other" >"$dir/remove-other.log" 2>&1

  echo "--- restorepkg"
  /scripts/restorepkg "$dir/cpmove-$user.tar.gz" >"$dir/restore.log" 2>&1
  check_restored "restorepkg"

  echo "--- failing export"
  export_failure

  if jetbackup_ready; then
    jetbackup_test
  else
    echo "SKIP: JetBackup 5 is not installed or not licensed"
  fi

  echo "BACKUP OK"
}

# check_restored <what> [account]: bk-run running with 2 processes, bk-stop stopped, bk-cafe running
# with its command intact, nothing left waiting.
check_restored() {
  local res as=${2:-$user}
  wait_for 20 "bk-run not RUNNING after $1" running bk-run 2 "$as"
  wait_for 20 "bk-cafe not RUNNING after $1" running bk-cafe 1 "$as"
  res=$(uapi_as "$as" list_workers)
  jq -e '[.data.workers[] | select(.name == "bk-stop")][0].status == "stopped"' >/dev/null <<<"$res" \
    || fail "bk-stop is not stopped after $1: $res"
  uapi_as "$as" get_worker name=bk-cafe | jq -e --arg cafe "$cafe" '.data.worker.command == $cafe' >/dev/null \
    || fail "bk-cafe lost its command after $1"
  [[ ! -e /var/cpanel/worker_supervisor/accounts/$as.json.restoring ]] || fail "a restore is still waiting after $1"
  echo "PASS: $1 brought the workers back in their saved state"
}

# Incremental backups reuse their directory: the second run must replace the workers file.
incremental() {
  /scripts/pkgacct --incremental "$user" "$dir/inc" >"$dir/inc1.log" 2>&1
  save bk-inc command='exec sleep 7782'
  /scripts/pkgacct --incremental "$user" "$dir/inc" >"$dir/inc2.log" 2>&1
  uapi delete_worker name=bk-inc >/dev/null
  ! grep -qi "worker supervisor" "$dir/inc2.log" || fail "the second incremental run warned: $(grep -i 'worker supervisor' "$dir/inc2.log")"
  jq -e 'any(.workers[]; .name == "bk-inc")' "$dir/inc/cpmove-$user/worker_supervisor.json" >/dev/null \
    || fail "the second incremental run kept the first run's workers file"
  echo "PASS: an incremental backup refreshes the workers file"
}

# An account file wsv cannot read: pkgacct warns, succeeds, and the archive has no workers file.
export_failure() {
  local file=/var/cpanel/worker_supervisor/accounts/$user.json
  cp -p "$file" "$dir/account.json"
  printf '{not json' >"$file"
  /scripts/pkgacct "$user" "$dir/broken" >"$dir/pkgacct-broken.log" 2>&1 || { cp -p "$dir/account.json" "$file"; fail "pkgacct failed"; }
  cp -p "$dir/account.json" "$file"
  grep -q "Worker Supervisor: the workers of $user are not in this backup" "$dir/pkgacct-broken.log" \
    || fail "pkgacct did not warn: $(grep -i worker "$dir/pkgacct-broken.log")"
  ! tar tzf "$dir/broken/cpmove-$user.tar.gz" | grep -q worker_supervisor.json || fail "the broken export left a workers file"
  echo "PASS: a failing export only warns"
}

jetbackup_ready() {
  command -v jetbackup5api >/dev/null && jb -F listBackupJobs >/dev/null 2>&1
}

jetbackup_test() {
  local dest job acct snap before

  echo "--- JetBackup backup"
  mkdir -p /backup/wsv-test
  dest=$(jb -F listDestinations | jq -r '.data.destinations[] | select(.name == "wsv-test") | ._id')
  [[ -n $dest ]] || dest=$(jb -F manageDestination -D "action=create&type=Local&name=wsv-test&options[path]=/backup/wsv-test" | jq -r .data._id)
  job=$(jb -F listBackupJobs | jq -r '.data.jobs[] | select(.name == "wsv-test") | ._id')
  [[ -n $job ]] || job=$(jb -F manageBackupJob -D "action=create&name=wsv-test&type=1&contains=511&destination[0]=$dest&structure=1" | jq -r .data._id)
  before=$(logs 1)
  jb -F runBackupJobManually -D "_id=$job" | jq -e '.success == 1' >/dev/null || fail "cannot run the JetBackup job"
  wait_for 600 "the JetBackup job did not finish" logged 1 "$before"
  acct=$(jb -F listAccounts | jq -r --arg u "$user" '.data.accounts[] | select(.username == $u) | ._id')
  snap=$(jb -F listBackups -D "type=1&contains=511&account_id=$acct" | jq -r '.data.backups | sort_by(.created) | last | .parent_id')
  [[ -n $snap && $snap != null ]] || fail "no JetBackup snapshot for $user"
  echo "PASS: JetBackup backed up $user"

  echo "--- JetBackup restore over the live account"
  save bk-extra command='exec sleep 7780'
  jetbackup_restore "$snap"
  "$wsv" reconcile >/dev/null
  uapi list_workers | jq -e 'all(.data.workers[]; .name != "bk-extra")' >/dev/null \
    || fail "the JetBackup restore kept a worker created after the backup"
  check_restored "a JetBackup restore over the live account"

  echo "--- JetBackup restore after removeacct"
  /scripts/removeacct --force "$user" >"$dir/remove-jb.log" 2>&1
  jetbackup_restore "$snap"
  # JetBackup restores the home directory after the restore hook: reconcile applies what waited.
  "$wsv" reconcile >/dev/null
  check_restored "a JetBackup restore after removeacct"
}

# jetbackup_restore <snapshot id>: queues a full restore and waits for it.
jetbackup_restore() {
  local before
  before=$(logs 3)
  jb -F addQueueItems -D "type=2&snapshot_id=$1" | jq -e '.success == 1' >/dev/null || fail "cannot queue the JetBackup restore"
  wait_for 600 "the JetBackup restore did not finish" logged 3 "$before"
}

# logs <type>: how many JetBackup logs of that type exist (1: backup job, 3: restore).
logs() {
  jb -F listLogs -D "limit=1000" | jq --argjson t "$1" '[.data.logs[]? | select(.type == $t)] | length'
}

# logged <type> <count before>: a log of that type was added and every one of them has ended.
logged() {
  jb -F listLogs -D "limit=1000" | jq -e --argjson t "$1" --argjson n "$2" \
    '[.data.logs[]? | select(.type == $t)] | length > $n and all(.[]; (.end_time // "") != "")' >/dev/null
}

jb() {
  local func=$2
  shift 2
  jetbackup5api -O json -F "$func" "$@"
}

# save <name> [key=value...]: a custom worker in $app.
save() {
  local name=$1 res
  shift
  res=$(uapi save_worker name="$name" template=custom folder="$app" startsecs=1 stopwaitsecs=5 autostart=1 "$@")
  jq -e '.status == 1' >/dev/null <<<"$res" || fail "save_worker $name: $res"
}

# running <name> <processes> [account]
running() {
  [[ $(supervisorctl status "${3:-$user}_$1:*" 2>/dev/null | grep -c RUNNING) == "$2" ]]
}

uapi() {
  uapi_as "$user" "$@"
}

uapi_as() {
  local as=$1
  shift
  /usr/local/cpanel/bin/uapi --user="$as" --output=json WorkerSupervisor "$@" | jq -c .result
}

wait_for() {
  local deadline=$((SECONDS + $1)) msg=$2
  shift 2
  until "$@"; do
    (( SECONDS < deadline )) || fail "$msg"
    sleep 1
  done
}

fail() {
  echo "FAIL: $*"
  exit 1
}

cleanup() {
  local name
  for name in bk-run bk-stop bk-cafe bk-extra bk-inc; do
    printf '{"user":"%s","args":{"name":"%s"}}' "$user" "$name" | "$wsv" delete >/dev/null 2>&1 || true
  done
  rm -rf -- "$dir" "$app"
  if [[ -e /var/cpanel/users/$other ]]; then
    /scripts/removeacct --force "$other" >/dev/null 2>&1 || true
  fi
}

main "$@"
