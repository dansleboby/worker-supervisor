#!/usr/bin/env bash
# Copies the working tree to the dev VM and runs the installer there.
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
tar --exclude=.git --exclude=vendor --exclude=.superpowers -czf - . | dev/vm/vm.sh ssh 'rm -rf /root/wsv-src && mkdir /root/wsv-src && tar xzf - -C /root/wsv-src && /root/wsv-src/install.sh'
