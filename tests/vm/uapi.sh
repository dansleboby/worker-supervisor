#!/bin/bash
# VM test for the Perl glue: UAPI WorkerSupervisor -> admin module -> bin/wsv. Called as root
# with --user and from inside the account's CageFS; refused for an account without the feature.
# Runs as root on the VM after dev/deploy.sh:
#   dev/vm/vm.sh ssh 'bash -s' < tests/vm/uapi.sh

main() {
  set -Eeuo pipefail
  trap 'echo "FAIL: line $LINENO: $BASH_COMMAND"' ERR

  user=wshell
  name=uapi-probe
  program=${user}_$name
  ini=/etc/supervisord.d/wsv_${program}.ini
  logs=/var/log/worker_supervisor/$user
  # Non-ASCII on purpose: it crosses UAPI, the admin protocol and wsv's JSON in both directions.
  command="echo 'probe café' && exec sleep 600"
  noaccess=wnoshell
  overridden=0

  cleanup
  trap cleanup EXIT

  res=$(uapi "$user" list_workers)
  expect "$res" "list_workers as root" '.status == 1 and (.data.workers | type == "array")'

  res=$(uapi "$user" save_worker name="$name" template=custom folder="/home/$user" command="$command" \
    processes=1 startsecs=1 stopwaitsecs=5 autostart=1)
  expect "$res" "save_worker" '.status == 1 and .data.worker.name == "uapi-probe"'

  wait_for 15 "$name never reached running through list_workers" listed_running

  # /usr/local/cpanel/bin/uapi cannot run inside CageFS; /usr/bin/uapi there is the proxyexec wrapper.
  res=$(cagefs_enter_user "$user" /usr/bin/uapi --output=json WorkerSupervisor list_workers | jq -c .result)
  expect "$res" "list_workers inside CageFS" \
    '.status == 1 and any(.data.workers[]; .name == "uapi-probe" and .status == "running")'

  res=$(uapi "$user" get_worker name="$name")
  expect "$res" "get_worker command round trip" --arg cmd "$command" '.status == 1 and .data.worker.command == $cmd'

  wait_for 10 "log never showed the probe line through get_logs" logs_show 'probe café'

  res=$(uapi "$user" save_worker name='Bad Name' template=custom folder="/home/$user" command=true)
  expect "$res" "validation failure mapping" \
    '.status == 0 and .errors == ["validation_failed"] and .data.error.code == "validation_failed"
     and any(.data.error.issues[]; .field == "name" and .code == "name_invalid")'

  res=$(uapi "$user" get_worker name=no-such-worker)
  expect "$res" "unknown worker mapping" \
    '.status == 0 and .errors == ["worker_unknown"] and .data.error.params.name == "no-such-worker"'

  out=$(whmapi1 --output=json add_override_features_for_user user="$noaccess" features='{"worker_supervisor":"0"}')
  [[ $(jq -r .metadata.result <<<"$out") == 1 ]] || fail "add_override_features_for_user: $out"
  overridden=1
  res=$(uapi "$noaccess" list_workers)
  expect "$res" "list_workers without the feature" \
    '.status == 0 and .errors == ["feature_disabled"] and .data.error.code == "feature_disabled"'

  res=$(uapi "$user" delete_worker name="$name")
  expect "$res" "delete_worker" '.status == 1'
  wait_for 20 "$program still known to supervisord after delete_worker" program_gone
  [[ ! -e $ini ]] || fail "delete_worker left $ini behind"
  ! pgrep -u "$user" -f '^sleep 600$' >/dev/null || fail "a $user 'sleep 600' survived delete_worker"

  trap - EXIT
  cleanup
  out=$(whmapi1 --output=json verify_user_has_feature user="$noaccess" feature=worker_supervisor)
  [[ $(jq -r .data.has_feature <<<"$out") == 1 ]] || fail "cleanup left the feature off for $noaccess: $out"

  echo "UAPI OK"
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

# uapi <user> <function> [key=value...]: prints the call's result object as one JSON line.
uapi() {
  local user=$1
  shift
  /usr/local/cpanel/bin/uapi --user="$user" --output=json WorkerSupervisor "$@" | jq -c .result
}

# expect <json> <failure message> [jq options...] <filter>
expect() {
  local json=$1 msg=$2
  shift 2
  jq -e "$@" >/dev/null <<<"$json" || fail "$msg: $json"
}

listed_running() {
  uapi "$user" list_workers \
    | jq -e --arg name "$name" 'any(.data.workers[]; .name == $name and .status == "running")' >/dev/null
}

logs_show() {
  uapi "$user" get_logs name="$name" process=0 stream=out lines=20 \
    | jq -e --arg want "$1" '.status == 1 and (.data.text | contains($want))' >/dev/null
}

program_gone() {
  ! supervisorctl status "$program:*" >/dev/null 2>&1
}

cleanup() {
  # Straight to wsv so cleanup does not depend on the modules under test.
  printf '{"user":"%s","args":{"name":"%s"}}' "$user" "$name" \
    | /usr/local/cpanel/3rdparty/worker_supervisor/bin/wsv delete >/dev/null 2>&1 || true
  if (( overridden )); then
    whmapi1 remove_override_features_for_user user="$noaccess" features='["worker_supervisor"]' >/dev/null || true
    overridden=0
  fi
  local deadline=$((SECONDS + 20))
  until program_gone || (( SECONDS >= deadline )); do sleep 0.5; done
  # `remove` leaves the group in supervisord's loaded config (`avail`) until the next update.
  supervisorctl update >/dev/null || true
  rm -f -- "$logs/${name}"_*.log*
}

main "$@" </dev/null
