#!/bin/bash
# VM test for bin/wsv-run: a probe worker started by supervisord must receive client text
# verbatim, run as the account inside its CageFS and LVE, stop gracefully and leave no
# process behind; a compound command must run; a non-cPanel user, a worker root holds and
# a launch file stored for another uid must be refused.
# Runs as root on the VM after dev/deploy.sh:
#   dev/vm/vm.sh ssh 'bash -s' < tests/vm/launcher.sh

main() {
  set -Eeuo pipefail
  trap 'echo "FAIL: line $LINENO: $BASH_COMMAND"' ERR

  user=wshell
  launcher=/usr/local/cpanel/3rdparty/worker_supervisor/bin/wsv-run
  name=launcher-probe
  program=${user}_$name
  folder="/home/$user/probe dir"
  ini=/etc/supervisord.d/wsv_${program}.ini
  launch=/var/cpanel/worker_supervisor/launch/$user/$name
  log=/var/log/worker_supervisor/$user/${name}_00.log
  # $USER must reach the caged bash unexpanded; %(x)s must survive supervisord.
  command=$'./probe.sh "a b" \'%(x)s\' "$USER"'
  name2=launcher-compound
  program2=${user}_$name2
  ini2=/etc/supervisord.d/wsv_${program2}.ini
  launch2=/var/cpanel/worker_supervisor/launch/$user/$name2
  log2=/var/log/worker_supervisor/$user/${name2}_00.log
  # A list with a builtin and an assignment prefix: none of these survive a plain `exec`.
  command2='cd sub && FOO=bar ./probe2.sh'
  nobody_launch=/var/cpanel/worker_supervisor/launch/nobody/probe
  name3=launcher-hold
  ini3=/etc/supervisord.d/wsv_${user}_${name3}.ini
  launch3=/var/cpanel/worker_supervisor/launch/$user/$name3
  hold3=$launch3.hold

  cleanup
  trap cleanup EXIT

  runuser -u "$user" -- bash -c 'mkdir -p -- "$1" && cat > "$1/probe.sh" && chmod 0755 "$1/probe.sh"' _ "$folder" <<'PROBE'
#!/bin/bash
echo "HOME=$HOME"
echo "ARGC=$#"
for arg in "$@"; do echo "ARG=$arg"; done
trap 'sleep 5; echo clean; exit 0' TERM
# Backgrounded so the TERM only reaches it through the process group (stopasgroup).
sleep 1000 &
wait
PROBE

  baseline=$(pgrep -u "$user" | sort || true)

  renderer write "$user" "$name" "$folder" "$command"
  ! grep -qF 'probe dir' "$ini" || fail "ini contains the client folder"
  ! grep -qF '%(x)s' "$ini" || fail "ini contains client command text"
  supervisorctl update >/dev/null

  wait_for 20 "$program never reached RUNNING" bash -c "supervisorctl status '$program:*' | grep -q RUNNING"
  wait_for 10 "probe output never reached $log" bash -c "[[ \$(grep -c '^ARG=' '$log' 2>/dev/null) -ge 3 ]]"

  expected=$'HOME=/home/wshell\nARGC=3\nARG=a b\nARG=%(x)s\nARG=wshell'
  actual=$(grep -E '^(HOME|ARGC|ARG)=' "$log")
  [[ $actual == "$expected" ]] || fail "probe output differs:"$'\n'"$actual"

  pid=$(supervisorctl pid "$program:${program}_00")
  [[ $pid =~ ^[1-9][0-9]*$ ]] || fail "no pid for $program: $pid"
  # A shebang script's argv[1] is its path; a bash that forked it instead would show '-c'.
  mapfile -d '' -t argv < "/proc/$pid/cmdline"
  [[ ${argv[1]:-} == *probe.sh ]] || fail "worker $pid is '${argv[*]}', not probe.sh exec'd in place"
  # Assigned before comparing so a vanished pid aborts instead of comparing empty strings.
  owner=$(stat -c %U "/proc/$pid")
  [[ $owner == "$user" ]] || fail "worker $pid runs as $owner, expected $user"
  lve=$(lveps -p -o id:16,pid:10 | awk -v pid="$pid" 'NF == 2 && $2 == "---" { lve = $1 } NF == 1 && $1 == pid { print lve }')
  [[ $lve == "$user" ]] || fail "worker $pid is in LVE '$lve', expected $user"
  mnt=$(readlink "/proc/$pid/ns/mnt")
  [[ $mnt != "$(readlink /proc/1/ns/mnt)" ]] || fail "worker $pid shares the host mount namespace (no CageFS)"
  child=$(pgrep -P "$pid" -x sleep) || fail "probe child sleep not found"

  start=$(date +%s%N)
  supervisorctl stop "$program:*" >/dev/null
  elapsed=$(( ($(date +%s%N) - start) / 1000000 ))
  (( elapsed >= 4500 && elapsed <= 9000 )) || fail "stop took ${elapsed} ms, expected about 5000"
  [[ $(tail -n 1 "$log") == clean ]] || fail "log does not end with 'clean': $(tail -n 1 "$log")"

  ! kill -0 "$pid" 2>/dev/null || fail "worker $pid still alive"
  ! kill -0 "$child" 2>/dev/null || fail "orphaned child $child still alive"
  leftover=$(comm -13 <(echo "$baseline") <(pgrep -u "$user" | sort || true))
  [[ -z $leftover ]] || fail "$user processes remain: $(ps -o pid=,args= -p "${leftover//$'\n'/,}")"

  runuser -u "$user" -- bash -c 'mkdir -p -- "$1/sub" && cat > "$1/sub/probe2.sh" && chmod 0755 "$1/sub/probe2.sh"' _ "$folder" <<'PROBE'
#!/bin/bash
echo "FOO=$FOO"
echo "PWD=$PWD"
exec sleep 1000
PROBE
  renderer write "$user" "$name2" "$folder" "$command2"
  supervisorctl update >/dev/null
  wait_for 20 "$program2 never reached RUNNING (see $log2)" bash -c "supervisorctl status '$program2:*' | grep -q RUNNING"
  wait_for 10 "compound probe output never reached $log2" bash -c "grep -q '^PWD=' '$log2' 2>/dev/null"
  actual=$(grep -E '^(FOO|PWD)=' "$log2")
  [[ $actual == $'FOO=bar\nPWD=/home/wshell/probe dir/sub' ]] || fail "compound probe output differs:"$'\n'"$actual"
  supervisorctl stop "$program2:*" >/dev/null

  # nobody passes the name pattern and cagefs_enter_user accepts it. The launch file
  # exists so only the cPanel account check can explain the refusal.
  install -d -m 0700 "${nobody_launch%/*}"
  printf '/\ntrue\n' > "$nobody_launch"
  rc=0
  timeout 20 "$launcher" nobody probe >/dev/null 2>&1 || rc=$?
  (( rc == 64 )) || fail "wsv-run nobody exited $rc, expected 64"

  # Run by hand: the hold must stop the launcher whatever started it, even a stale supervisorctl job.
  renderer write "$user" "$name3" "$folder" true
  rc=0
  timeout 20 "$launcher" "$user" "$name3" >/dev/null 2>&1 || rc=$?
  (( rc == 0 )) || fail "wsv-run $name3 exited $rc before the hold, expected 0"
  renderer hold "$user" "$name3" "$folder" true
  [[ -f $hold3 ]] || fail "IniRenderer did not write $hold3"
  rc=0
  err=$(timeout 20 "$launcher" "$user" "$name3" 2>&1 >/dev/null) || rc=$?
  (( rc == 64 )) || fail "wsv-run of a held worker exited $rc, expected 64"
  [[ $err == *held* ]] || fail "wsv-run of a held worker said: $err"

  # The launch file names the uid of the account the workers belong to: an earlier account of the name.
  renderer write "$user" "$name3" "$folder" true
  [[ $(sed -n 3p "$launch3") == "$(id -u "$user")" ]] || fail "$launch3 does not carry the uid of $user"
  sed -i "3s/.*/$(( $(id -u "$user") + 100000 ))/" "$launch3"
  rc=0
  err=$(timeout 20 "$launcher" "$user" "$name3" 2>&1 >/dev/null) || rc=$?
  (( rc == 64 )) || fail "wsv-run with another uid in the launch file exited $rc, expected 64"
  [[ $err == *"not the one"* ]] || fail "wsv-run with another uid said: $err"

  trap - EXIT
  cleanup
  for f in "$ini" "$launch" "$log" "$ini2" "$launch2" "$log2" "$nobody_launch" "$ini3" "$launch3" "$hold3"; do
    [[ ! -e $f ]] || fail "cleanup left $f behind"
  done
  for p in "$program" "$program2"; do
    ! supervisorctl status "$p:*" >/dev/null 2>&1 || fail "$p still known to supervisord"
    ! supervisorctl avail | grep -q "^$p:" || fail "$p still available in supervisord"
  done

  echo "LAUNCHER OK"
}

fail() {
  echo "FAIL: $*"
  exit 1
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

# renderer write|hold <user> <name> <folder> <command> | renderer remove <user> <name>
# hold writes the worker as root leaves it when it stops it (suspension, admin stop, feature off).
renderer() {
  local code
  code=$(cat <<'PHP'
spl_autoload_register(static function (string $class): void {
    require '/usr/local/cpanel/3rdparty/worker_supervisor/src/' . substr($class, strlen('WorkerSupervisor\\')) . '.php';
});
[, $action, $user, $name] = $argv;
$renderer = new WorkerSupervisor\IniRenderer(new WorkerSupervisor\Paths());
$account = new WorkerSupervisor\Account($user, identity: new WorkerSupervisor\Identity(posix_getpwnam($user)['uid'], ''));
if ($action !== 'remove') {
    $worker = new WorkerSupervisor\Worker(
        name: $name,
        template: 'custom',
        folder: $argv[4],
        command: $argv[5],
        autorestart: 'false',
        startsecs: 1,
        startretries: 0,
        stopwaitsecs: 30,
    );
    $held = $action === 'hold';
    $renderer->write($account, $worker, !$held, $held);
} else {
    $renderer->remove($user, $name);
}
PHP
)
  /usr/local/cpanel/3rdparty/bin/php -r "$code" -- "$@"
}

cleanup() {
  renderer remove "$user" "$name" || true
  renderer remove "$user" "$name2" || true
  renderer remove "$user" "$name3" || true
  supervisorctl update >/dev/null || true
  [[ -z ${child:-} ]] || kill -KILL "$child" 2>/dev/null || true
  rm -rf -- "$folder" "${nobody_launch%/*}"
  rmdir "${launch%/*}" "${log%/*}" 2>/dev/null || true
}

main "$@" </dev/null
