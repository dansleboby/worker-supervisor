#!/usr/bin/env bash
# Local cPanel & WHM dev VM: AlmaLinux 9 cloud image on QEMU/KVM, user-mode networking.
# Usage: dev/vm/vm.sh create|start|stop|status|ssh [cmd...]|whm [cpanel-user]|snapshot <name>|restore <name>|snapshots|destroy
set -euo pipefail

VM_DIR="${VM_DIR:-$HOME/VMs/cpanel-dev}"
VM_FQDN="${VM_FQDN:-whm.cpanel-dev.test}"
VM_CPUS="${VM_CPUS:-4}"
VM_MEM="${VM_MEM:-8G}"
VM_DISK_SIZE="${VM_DISK_SIZE:-80G}"

IMAGE_BASE_URL=https://repo.almalinux.org/almalinux/9/cloud/x86_64/images
# ext4 variant: cPanel quotas on an XFS root need a rootflags=uquota kernel arg + reboot.
IMAGE_NAME=AlmaLinux-9-GenericCloud-ext4-latest.x86_64.qcow2

SSH_PORT=2222
# host:guest, bound to 127.0.0.1 only. 2087 WHM, 2083 cPanel, 2096 webmail.
FORWARDS=(2222:22 2082:2082 2083:2083 2086:2086 2087:2087 2095:2095 2096:2096 8080:80 8443:443)

BASE="$VM_DIR/base.qcow2"
DISK="$VM_DIR/disk.qcow2"
SEED="$VM_DIR/seed.img"
KEY="$VM_DIR/id_ed25519"
PIDFILE="$VM_DIR/qemu.pid"
MONITOR="$VM_DIR/monitor.sock"

die() { echo "error: $*" >&2; exit 1; }

is_running() { [[ -f $PIDFILE ]] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; }

monitor_cmd() {
  python3 -c 'import socket,sys; s=socket.socket(socket.AF_UNIX); s.connect(sys.argv[1]); s.sendall(sys.argv[2].encode()+b"\n")' \
    "$MONITOR" "$1"
}

cmd_create() {
  [[ -e $DISK ]] && die "$DISK already exists (run destroy first)"
  mkdir -p "$VM_DIR"

  if [[ ! -f $BASE ]]; then
    curl -fL --progress-bar -o "$BASE.part" "$IMAGE_BASE_URL/$IMAGE_NAME"
    expected=$(curl -fsSL "$IMAGE_BASE_URL/CHECKSUM" | awk -v f="$IMAGE_NAME" '$2 == f {print $1}')
    [[ -n $expected ]] || die "no checksum found for $IMAGE_NAME"
    echo "$expected  $BASE.part" | sha256sum -c --quiet || die "checksum mismatch"
    mv "$BASE.part" "$BASE"
  fi

  [[ -f $KEY ]] || ssh-keygen -q -t ed25519 -N '' -C "cpanel-dev-vm" -f "$KEY"
  rm -f "$VM_DIR/known_hosts"

  # Full copy rather than a backing-file overlay so base.qcow2 can be swapped without breaking the VM.
  qemu-img convert -O qcow2 "$BASE" "$DISK"
  qemu-img resize -q "$DISK" "$VM_DISK_SIZE"

  local tmp
  tmp=$(mktemp -d)
  cat >"$tmp/meta-data" <<EOF
instance-id: cpanel-dev-$(date +%s)
local-hostname: ${VM_FQDN%%.*}
EOF
  # cPanel refuses to install with SELinux on; EL9 only fully disables it via the kernel arg.
  cat >"$tmp/user-data" <<EOF
#cloud-config
hostname: ${VM_FQDN%%.*}
fqdn: $VM_FQDN
prefer_fqdn_over_hostname: true
manage_etc_hosts: true
disable_root: false
ssh_pwauth: false
ssh_authorized_keys:
  - $(cat "$KEY.pub")
timezone: America/Toronto
runcmd:
  - sed -i 's/^SELINUX=.*/SELINUX=disabled/' /etc/selinux/config
  - grubby --update-kernel ALL --args selinux=0
power_state:
  mode: reboot
  condition: true
EOF
  rm -f "$SEED"
  truncate -s 2M "$SEED"
  mkfs.vfat -n CIDATA "$SEED" >/dev/null
  mcopy -i "$SEED" "$tmp/user-data" "$tmp/meta-data" ::
  rm -rf "$tmp"

  echo "created $DISK ($VM_DISK_SIZE). Next: $0 start"
}

cmd_start() {
  is_running && { echo "already running (pid $(cat "$PIDFILE"))"; return; }
  [[ -f $DISK ]] || die "no disk, run create first"

  local fwd="" f
  for f in "${FORWARDS[@]}"; do
    fwd+=",hostfwd=tcp:127.0.0.1:${f%%:*}-:${f##*:}"
  done

  qemu-system-x86_64 \
    -name cpanel-dev \
    -machine q35,accel=kvm -cpu host -smp "$VM_CPUS" -m "$VM_MEM" \
    -drive file="$DISK",if=virtio,format=qcow2,discard=unmap \
    -drive file="$SEED",if=virtio,format=raw,readonly=on \
    -netdev user,id=net0"$fwd" -device virtio-net-pci,netdev=net0 \
    -device virtio-rng-pci \
    -display none -serial file:"$VM_DIR/console.log" \
    -monitor unix:"$MONITOR",server,nowait \
    -pidfile "$PIDFILE" -daemonize

  echo "started (pid $(cat "$PIDFILE")), console log: $VM_DIR/console.log"
  echo "ssh: $0 ssh | WHM: https://127.0.0.1:2087 | cPanel: https://127.0.0.1:2083"
}

cmd_stop() {
  is_running || { echo "not running"; return; }
  monitor_cmd system_powerdown
  # cPanel's service stack (MySQL, Exim, Dovecot...) can take well over a minute to stop.
  local i
  for i in $(seq 180); do
    is_running || { echo "stopped"; rm -f "$PIDFILE"; return; }
    sleep 1
  done
  echo "guest did not power off in 180s, killing" >&2
  kill "$(cat "$PIDFILE")"
  rm -f "$PIDFILE"
}

cmd_status() {
  if is_running; then echo "running (pid $(cat "$PIDFILE"))"; else echo "stopped"; fi
}

cmd_ssh() { vm_ssh "$@"; }

# One-time root login URL; the guest reports its own hostname, which the host cannot resolve.
cmd_whm() {
  local service=whostmgrd port=2087 user=root
  if [[ -n ${1:-} ]]; then service=cpaneld port=2083 user=$1; fi
  vm_ssh "whmapi1 --output=json create_user_session user=$user service=$service" |
    python3 -c 'import json,sys; d=json.load(sys.stdin); print(d["data"]["url"]) if d["metadata"]["result"] else sys.exit(d["metadata"]["reason"])' |
    sed -E "s#^https://[^/]+#https://127.0.0.1:$port#"
}

vm_ssh() {
  ssh -i "$KEY" -p "$SSH_PORT" \
    -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile="$VM_DIR/known_hosts" \
    -o IdentitiesOnly=yes -o LogLevel=ERROR \
    root@127.0.0.1 "$@"
}

# Internal qcow2 snapshots: the VM must be stopped.
cmd_snapshot() {
  [[ -n ${1:-} ]] || die "usage: snapshot <name>"
  is_running && die "stop the VM first"
  qemu-img snapshot -c "$1" "$DISK" && echo "snapshot '$1' created"
}

cmd_restore() {
  [[ -n ${1:-} ]] || die "usage: restore <name>"
  is_running && die "stop the VM first"
  qemu-img snapshot -a "$1" "$DISK" && echo "restored '$1'"
}

# -U: read-only listing is safe while QEMU holds the image lock.
cmd_snapshots() { qemu-img snapshot -U -l "$DISK"; }

cmd_destroy() {
  is_running && die "stop the VM first"
  rm -f "$DISK" "$SEED" "$VM_DIR/known_hosts" "$VM_DIR/console.log"
  echo "destroyed VM disk (base image and SSH key kept in $VM_DIR)"
}

case "${1:-}" in
  create | start | stop | status | snapshots | destroy) "cmd_$1" ;;
  ssh | whm | snapshot | restore) c=$1; shift; "cmd_$c" "$@" ;;
  *) sed -n '3p' "$0" | sed 's/^# //'; exit 1 ;;
esac
