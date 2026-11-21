#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

bash -n "$ROOT/install.sh"
bash -n "$ROOT/offline-peer-sync"

grep -q '47001' "$ROOT/install.sh"
grep -q 'OnUnitActiveSec=5min' "$ROOT/offline-peer-sync.timer"
grep -q '\[knowledge\]' "$ROOT/offline-rsyncd.conf"
grep -q '\[media\]' "$ROOT/offline-rsyncd.conf"
grep -q 'qwen2.5:0.5b' "$ROOT/install.sh"
grep -q 'vlc' "$ROOT/install.sh"
grep -q 'kiwix' "$ROOT/install.sh"
grep -q 'jdownloader2' "$ROOT/install.sh"

grep -q 'ExecStart=/usr/bin/rsync .*--port=47001' "$ROOT/offline-rsync.service"
grep -q 'OnUnitActiveSec=5min' "$ROOT/offline-peer-sync.timer"

echo "Offline installer validation passed."
