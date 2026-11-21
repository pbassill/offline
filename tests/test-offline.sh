#!/usr/bin/env bash
#
# Offline test suite. Changes nothing outside a temporary directory.
#
#   tests/test-offline.sh
#
# Static checks always run. Functional checks run when the tools they need
# (php, rsync, curl) are present, and say so when they are skipped.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/offline-tests.XXXXXX")"
PASSED=0
FAILED=0
PIDS=()

cleanup() {
  local pid
  for pid in "${PIDS[@]:-}"; do
    if [[ -n "$pid" ]]; then
      kill "$pid" 2>/dev/null || true
    fi
  done
  if [[ -d "$WORK" && "$WORK" == */offline-tests.* ]]; then
    rm -rf -- "$WORK"
  fi
}
trap cleanup EXIT

pass() { PASSED=$((PASSED + 1)); printf '  ok    %s\n' "$1"; }
fail() { FAILED=$((FAILED + 1)); printf '  FAIL  %s\n' "$1"; }
note() { printf '  skip  %s\n' "$1"; }
check() {
  local description="$1"
  shift
  if "$@" >/dev/null 2>&1; then pass "$description"; else fail "$description"; fi
}
refute() {
  local description="$1"
  shift
  if "$@" >/dev/null 2>&1; then fail "$description"; else pass "$description"; fi
}
have() { command -v "$1" >/dev/null 2>&1; }

echo "Static checks"

check "install.sh parses" bash -n "$ROOT/install.sh"
if have shellcheck; then
  check "install.sh passes shellcheck" shellcheck -x "$ROOT/install.sh"
  check "test suite passes shellcheck" shellcheck -x "$ROOT/tests/test-offline.sh"
else
  note "shellcheck not installed"
fi

# What the installer must still provide.
check "installer installs VLC" grep -q 'vlc' "$ROOT/install.sh"
check "installer installs Kiwix" grep -q 'kiwix' "$ROOT/install.sh"
check "installer installs JDownloader" grep -q 'jdownloader2' "$ROOT/install.sh"
check "default model is qwen2.5:0.5b" grep -q 'qwen2.5:0.5b' "$ROOT/config/offline.conf"
check "sharing uses port 47001" grep -q 'ExecStart=/usr/bin/rsync .*--port=47001' "$ROOT/systemd/offline-rsync.service"
check "peer scan runs every five minutes" grep -q 'OnUnitActiveSec=5min' "$ROOT/systemd/offline-peer-sync.timer"
check "knowledge module is shared" grep -q '^\[knowledge\]' "$ROOT/config/rsyncd.conf"
check "media module is shared" grep -q '^\[media\]' "$ROOT/config/rsyncd.conf"

# Hardening that must not regress.
refute "installer never pipes a download into a shell" grep -Eq 'curl[^|]*\|[[:space:]]*(sudo[[:space:]]+)?(ba)?sh' "$ROOT/install.sh"
refute "installer never opens a port to every address" grep -Eq 'ufw allow [0-9]+(/tcp|/udp)?[[:space:]]*$' "$ROOT/install.sh"
refute "installer never sources os-release or settings" grep -Eq '^[[:space:]]*(\.|source)[[:space:]]+/etc/' "$ROOT/install.sh"
refute "sharing daemon does not run as root" grep -Eq '^(uid|gid)[[:space:]]*=[[:space:]]*(root|0)' "$ROOT/config/rsyncd.conf"
check "sharing requires the house key" grep -q '^auth users = offline' "$ROOT/config/rsyncd.conf"
check "sharing is read-only" grep -q '^read only = yes' "$ROOT/config/rsyncd.conf"
check "sharing is limited to private addresses" grep -q '^hosts deny = \*' "$ROOT/config/rsyncd.conf"
check "sharing keeps its lock where an unprivileged daemon can write" grep -q '^lock file = /run/offline/' "$ROOT/config/rsyncd.conf"
check "sharing refuses symlink-following options" grep -q '^refuse options = .*copy-links' "$ROOT/config/rsyncd.conf"
for unit in "$ROOT"/systemd/*.service; do
  name="$(basename "$unit")"
  check "$name runs as the offline account" grep -q '^User=offline$' "$unit"
  check "$name cannot gain privileges" grep -q '^NoNewPrivileges=yes$' "$unit"
  check "$name has a read-only system" grep -q '^ProtectSystem=strict$' "$unit"
  check "$name is confined to chosen networks" grep -q '^IPAddressDeny=any$' "$unit"
done
check "portal PHP pool disables program execution" grep -q 'disable_functions.*proc_open' "$ROOT/config/php-fpm-offline-portal.conf"
check "portal PHP pool is confined by open_basedir" grep -q 'open_basedir' "$ROOT/config/php-fpm-offline-portal.conf"
check "portal sends a Content-Security-Policy" grep -q "default-src 'none'" "$ROOT/config/apache-offline-portal.conf"
refute "portal loads nothing from another site" grep -rEq '(src|href)="https?://' "$ROOT/portal/app" "$ROOT/portal/public/index.php" "$ROOT/portal/public/library.php" "$ROOT/portal/public/media.php" "$ROOT/portal/public/play.php" "$ROOT/portal/public/peers.php"
refute "portal has no inline styles or scripts" grep -rEq 'style="|<script>|onclick=' "$ROOT/portal/app" "$ROOT/portal/public"/*.php
check "vendored assets match their checksums" bash -c "cd '$ROOT/portal/public/assets' && sha256sum --quiet -c CHECKSUMS"
check "the map viewer may only talk to this laptop" grep -q "connect-src 'self'; worker-src 'self'" "$ROOT/portal/public/maps.php"
check "the map tool is pinned by checksum for both architectures" bash -c "grep -Eq '^readonly PMTILES_SHA256_AMD64=\"[0-9a-f]{64}\"' '$ROOT/install.sh' && grep -Eq '^readonly PMTILES_SHA256_ARM64=\"[0-9a-f]{64}\"' '$ROOT/install.sh'"
check "map fonts and symbols are pinned by commit" grep -Eq "BASEMAP_ASSETS_COMMIT = '[0-9a-f]{40}'" "$ROOT/lib/maps.php"

# Every application unit must keep the three rules in lib/apps.php.
APP_COUNT=0
for unit in "$ROOT"/apps/*.container; do
  name="$(basename "$unit" .container)"
  APP_COUNT=$((APP_COUNT + 1))
  check "app $name is pinned by image digest" grep -Eq '^Image=[a-z0-9./-]+:[A-Za-z0-9._-]+@sha256:[0-9a-f]{64}$' "$unit"
  check "app $name cannot gain privileges" grep -q '^NoNewPrivileges=true$' "$unit"
  check "app $name runs in its own user namespace" grep -Eq '^PodmanArgs=.*--uidmap=0:[1-9][0-9]{6}:65536 --gidmap=0:[1-9][0-9]{6}:65536' "$unit"
  check "app $name never pulls an image by itself" grep -q -- '--pull=never' "$unit"
  check "app $name has a memory limit" grep -Eq -- '--memory=[0-9]+[mg] ' "$unit"
  refute "app $name publishes no port beyond this laptop" grep -Ev '^PublishPort=127\.0\.0\.1:' <(grep '^PublishPort=' "$unit" || true)
  refute "app $name mounts shared content read-only" grep -E '^Volume=/srv/offline/(media|knowledge|maps)[:/]' <(grep -v ':ro$' "$unit")
  check "app $name is in the index" grep -q "^$name	" "$ROOT/apps/apps.tsv"
done
check "all sixteen applications are shipped" test "$APP_COUNT" -eq 16
check "only one application shares the host network" bash -c "[[ \$(grep -l '^Network=host' '$ROOT'/apps/*.container | wc -l) -eq 1 ]]"
check "user namespace ranges do not overlap" bash -c "[[ \$(grep -oh 'uidmap=0:[0-9]*' '$ROOT'/apps/*.container | sort | uniq -d | wc -l) -eq 0 ]]"
if [[ -x /usr/libexec/podman/quadlet ]]; then
  QUADLET_TMP="$(mktemp -d "$WORK/quadlet.XXXXXX")"
  for unit in "$ROOT"/apps/*.container; do cp "$unit" "$QUADLET_TMP/offline-app-$(basename "$unit")"; done
  refute "Podman accepts every application unit" bash -c "QUADLET_UNIT_DIRS='$QUADLET_TMP' /usr/libexec/podman/quadlet -dryrun 2>&1 | grep -Eiq 'unsupported|converting|error'"
else
  note "Podman not installed: application units not validated by its generator"
fi

if have systemd-analyze; then
  for unit in "$ROOT"/systemd/offline-*; do
    problems="$(systemd-analyze verify "$unit" 2>&1 | grep -v -E 'No such file|Failed to resolve|not executable|/usr/local/bin/offline|kiwix-serve' || true)"
    if [[ -z "$problems" ]]; then pass "$(basename "$unit") is valid systemd syntax"; else fail "$(basename "$unit"): $problems"; fi
  done
else
  note "systemd-analyze not installed"
fi

if ! have php; then
  note "php not installed: functional checks not run"
  echo
  echo "$PASSED passed, $FAILED failed."
  ((FAILED == 0))
  exit
fi

echo
echo "PHP"
while IFS= read -r -d '' file; do
  check "${file#"$ROOT"/} parses" php -l "$file"
done < <(find "$ROOT/lib" "$ROOT/portal" "$ROOT/bin" -type f \( -name '*.php' -o -name offline \) -print0)

# --------------------------------------------------------------------------
# A scratch laptop ("a") and a scratch peer ("b")
# --------------------------------------------------------------------------

make_root() {
  mkdir -p "$1"/etc/offline "$1"/usr/local/lib/offline "$1"/srv/offline/knowledge/zim \
    "$1"/srv/offline/media/movies "$1"/srv/offline/media/music "$1"/var/lib/offline "$1"/run/offline \
    "$1"/srv/offline/maps "$1"/srv/offline/radio "$1"/srv/offline/apps "$1"/sys/class/power_supply/BAT0 \
    "$1"/sys/bus/usb/devices/1-1 "$1"/sys/bus/usb/devices/1-2
  cp "$ROOT/config/catalogue.tsv" "$ROOT/config/maps.tsv" "$1/usr/local/lib/offline/"
  cp -r "$ROOT/apps" "$ROOT/data" "$1/usr/local/lib/offline/"
  echo 0bda >"$1/sys/bus/usb/devices/1-1/idVendor"; echo 2838 >"$1/sys/bus/usb/devices/1-1/idProduct"
  echo 10c4 >"$1/sys/bus/usb/devices/1-2/idVendor"; echo ea60 >"$1/sys/bus/usb/devices/1-2/idProduct"
  cp "$ROOT/config/offline.conf" "$1/etc/offline/"
  echo Battery >"$1/sys/class/power_supply/BAT0/type"
  echo 84 >"$1/sys/class/power_supply/BAT0/capacity"
  echo Discharging >"$1/sys/class/power_supply/BAT0/status"
}
# A file with the ZIM magic number: enough for everything except the reader.
make_zim() { printf 'ZIM\004' >"$1"; head -c "${2:-2000}" /dev/urandom >>"$1"; }
offline() { OFFLINE_PREFIX="$A" "$ROOT/bin/offline" "$@"; }

A="$WORK/a"
B="$WORK/b"
make_root "$A"
make_root "$B"

echo
echo "Command"
check "help is shown" offline help
refute "unknown commands are rejected" offline frobnicate
check "catalogue lists NOMAD's medicine collection" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' catalogue medicine-comprehensive | grep -q wikipedia_en_medicine_maxi"
check "a comprehensive pack brings its lower tiers" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' catalogue medicine-comprehensive | grep -q nhs.uk_en_medicines"
check "a tier on its own covers every subject" bash -c "[[ \$(OFFLINE_PREFIX='$A' '$ROOT/bin/offline' catalogue essential | grep -c '^Pack: ') -eq 6 ]]"
refute "an essential pack does not bring higher tiers" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' catalogue medicine-essential | grep -q medlineplus"
refute "an unknown pack is rejected" offline catalogue no-such-pack

check "a house key can be created" offline key new
KEY="$(head -n 1 "$A/etc/offline/house.key")"
check "the key has the expected form" grep -Eq '^([0-9A-HJKMNP-TV-Z]{5}-){4}[0-9A-HJKMNP-TV-Z]{5}$' "$A/etc/offline/house.key"
check "the key file is not world-readable" bash -c "[[ \$(stat -c %a '$A/etc/offline/house.key') == 640 ]]"
refute "an existing key is not replaced by accident" offline key new
refute "a malformed key is rejected" offline key set not-a-key
check "a key can be entered on standard input" bash -c "echo '$KEY' | tr 'A-Z' 'a-z' | OFFLINE_PREFIX='$B' '$ROOT/bin/offline' key set -"
check "both laptops now hold the same key" cmp -s "$A/etc/offline/house.key" "$B/etc/offline/house.key"

make_zim "$A/srv/offline/knowledge/zim/testwiki_en_all_2026-01.zim"
check "status is valid JSON" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' status --json | php -r 'exit(is_array(json_decode(stream_get_contents(STDIN), true)) ? 0 : 1);'"
check "status reports the battery" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' status | grep -q 'battery 84 %'"
check "status never contains the house key" bash -c "! OFFLINE_PREFIX='$A' '$ROOT/bin/offline' status --json | grep -q '$KEY'"
check "status can be written for the portal" offline status --write

# Kiwix catalogue lookups, against a recorded feed.
sed -i "s#^CATALOGUE_URL=.*#CATALOGUE_URL=file://$ROOT/tests/fixtures/kiwix-feed.xml#" "$A/etc/offline/offline.conf"
PLAN="$(offline get wikipedia_en_medicine_maxi testwiki_en_all evil_en --dry-run 2>&1 || true)"
check "the newest edition is chosen" grep -q 'wikipedia_en_medicine_maxi_2026-10.zim' <<<"$PLAN"
refute "an older edition is not chosen" grep -q 'wikipedia_en_medicine_maxi_2026-06.zim' <<<"$PLAN"
refute "a different flavour is not confused with it" grep -q 'nopic' <<<"$PLAN"
check "an installed title is offered its newer edition" grep -q 'testwiki_en_all_2026-09.zim.*replaces 2026-01' <<<"$PLAN"
refute "a catalogue entry pointing at plain http is ignored" grep -q 'evil_en_2026-01.zim' <<<"$PLAN"
check "a download from another site is refused" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' get https://example.com/x_2026-01.zim --dry-run 2>&1 | grep -q 'only dated ZIM files from kiwix.org'"
check "a plain http address is refused" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' get http://download.kiwix.org/zim/x_2026-01.zim --dry-run 2>&1 | grep -q 'only https'"

make_zim "$A/srv/offline/knowledge/zim/testwiki_en_all_2025-06.zim"
check "prune removes a superseded edition" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' prune | grep -q 'removed testwiki_en_all_2025-06.zim'"
check "prune keeps the newest edition" test -f "$A/srv/offline/knowledge/zim/testwiki_en_all_2026-01.zim"

echo
echo "Maps, applications and radio"
check "map regions are listed" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' maps | grep -q 'United Kingdom'"
check "NOMAD's regional maps are listed" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' maps | grep -q 'California'"
refute "a malformed map area is rejected" offline maps get --bbox 10,10,5,5 --name bad --dry-run
refute "a map name with path characters is rejected" offline maps get --bbox -5,50,-4,51 --name ../../etc --dry-run
refute "an unknown map region is rejected" offline maps get atlantis --dry-run
check "sixteen applications are on offer" bash -c "[[ \$(OFFLINE_PREFIX='$A' '$ROOT/bin/offline' apps | grep -c 'localhost\|this laptop only') -eq 16 ]]"
refute "an unknown application is rejected" offline apps add no-such-app
check "applications can be prepared" offline apps add cyberchef flatnotes vaultwarden
check "an app proxy forwards to this laptop only" grep -q 'ProxyPass / http://127.0.0.1:18100/' "$A/etc/apache2/sites-available/offline-app-cyberchef.conf"
check "an app proxy keeps the home-network rule" grep -q 'Require ip 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16' "$A/etc/apache2/sites-available/offline-app-cyberchef.conf"
check "an app that needs TLS gets it" grep -q 'SSLEngine on' "$A/etc/apache2/sites-available/offline-app-vaultwarden.conf"
check "a generated sign-in is private" bash -c "[[ \$(stat -c %a '$A/etc/offline/apps/flatnotes.env') == 600 ]]"
refute "a generated sign-in holds no placeholder" grep -q '@' "$A/etc/offline/apps/flatnotes.env"
check "a light app starts at boot" grep -q '^WantedBy=multi-user.target' "$A/etc/containers/systemd/offline-app-cyberchef.container"
refute "a heavy app waits to be asked" grep -q '^WantedBy=' "$A/etc/containers/systemd/offline-app-vaultwarden.container"
check "all sixteen PMR446 channels are known" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' radio channels | grep -q '446.00625' && OFFLINE_PREFIX='$A' '$ROOT/bin/offline' radio channels | grep -q '446.19375'"
check "a plugged-in receiver is recognised" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' radio | grep -q 'RTL-SDR receiver'"
check "a plugged-in mesh node is recognised" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' radio | grep -q 'CP210x serial adaptor'"
refute "a channel outside PMR446 is rejected" offline radio listen pmr 17
refute "a frequency a dongle cannot tune is rejected" offline radio listen nfm 9999
check "the channel file for handsets has sixteen rows" bash -c "[[ \$(grep -c 'PMR446 channel' '$ROOT/config/radio/chirp-pmr446.csv') -eq 16 ]]"

# --------------------------------------------------------------------------
# Peer sync against a real rsync daemon using the shipped configuration
# --------------------------------------------------------------------------

echo
echo "Peer sync"
if have rsync; then
  make_zim "$B/srv/offline/knowledge/zim/testwiki_en_all_2026-05.zim" 3000
  make_zim "$B/srv/offline/knowledge/zim/testwiki_en_all_2025-01.zim" 3000
  make_zim "$B/srv/offline/knowledge/zim/survival_en_2025-03.zim" 3000
  echo "not a zim" >"$B/srv/offline/knowledge/zim/fake_en_2026-02.zim"
  head -c 5000 /dev/urandom >"$B/srv/offline/media/movies/holiday.mp4"
  echo '<script>alert(1)</script>' >"$B/srv/offline/media/movies/page.html"
  printf '#!/bin/sh\n' >"$B/srv/offline/media/movies/run.sh"
  mkdir -p "$B/srv/offline/media/.hidden"
  echo secret >"$B/srv/offline/media/.hidden/private.mp4"
  ln -s /etc/passwd "$B/srv/offline/media/movies/passwd.txt"
  printf 'PMTiles-test' >"$B/srv/offline/maps/harbour.pmtiles"
  mkdir -p "$B/srv/offline/maps/assets/fonts/Noto Sans Regular"
  echo glyphs >"$B/srv/offline/maps/assets/fonts/Noto Sans Regular/0-255.pbf"
  echo '<script>' >"$B/srv/offline/maps/index.html"

  PORT=$((40000 + RANDOM % 20000))
  {
    # The daemon normally runs as "offline"; only the test, when run as root, pins the uid.
    if ((EUID == 0)); then printf 'uid = 0\ngid = 0\n'; fi
    sed -e "s#/srv/offline#$B/srv/offline#" -e "s#/etc/offline/rsyncd.secrets#$B/etc/offline/rsyncd.secrets#" \
      -e "s#/run/offline/#$B/run/offline/#" "$ROOT/config/rsyncd.conf"
  } >"$B/rsyncd.conf"
  rsync --daemon --no-detach --config="$B/rsyncd.conf" --port="$PORT" --address=127.0.0.1 --log-file="$B/rsyncd.log" &
  PIDS+=("$!")
  sleep 1
  peer() { OFFLINE_PREFIX="$A" OFFLINE_TEST_PEERS="127.0.0.1:$PORT" "$ROOT/bin/offline" "$@"; }

  check "a dry run fetches nothing" bash -c "OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$PORT' '$ROOT/bin/offline' sync --dry-run && [[ ! -e '$A/srv/offline/media/movies/holiday.mp4' ]]"
  check "sync completes" peer sync
  check "a newer edition is fetched" test -f "$A/srv/offline/knowledge/zim/testwiki_en_all_2026-05.zim"
  check "a new title is fetched" test -f "$A/srv/offline/knowledge/zim/survival_en_2025-03.zim"
  check "media is fetched" cmp -s "$B/srv/offline/media/movies/holiday.mp4" "$A/srv/offline/media/movies/holiday.mp4"
  check "the edition it replaces is pruned" test ! -e "$A/srv/offline/knowledge/zim/testwiki_en_all_2026-01.zim"
  check "an older edition is not fetched" test ! -e "$A/srv/offline/knowledge/zim/testwiki_en_all_2025-01.zim"
  check "a map region is fetched" cmp -s "$B/srv/offline/maps/harbour.pmtiles" "$A/srv/offline/maps/harbour.pmtiles"
  check "map viewer fonts are fetched" test -f "$A/srv/offline/maps/assets/fonts/Noto Sans Regular/0-255.pbf"
  check "a web page among the maps is not fetched" test ! -e "$A/srv/offline/maps/index.html"
  check "a web page is not fetched" test ! -e "$A/srv/offline/media/movies/page.html"
  check "a script is not fetched" test ! -e "$A/srv/offline/media/movies/run.sh"
  check "a hidden folder is not fetched" test ! -e "$A/srv/offline/media/.hidden"
  check "a symbolic link is not fetched" test ! -e "$A/srv/offline/media/movies/passwd.txt"
  check "a fake ZIM is discarded" test ! -e "$A/srv/offline/knowledge/zim/fake_en_2026-02.zim"
  check "the fake ZIM is remembered and not requested again" bash -c "OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$PORT' '$ROOT/bin/offline' sync | grep -q 'nothing new to fetch'"
  check "the peer is recorded as trusted" grep -q '"auth": "ok"' "$A/var/lib/offline/peers.json"

  export RSYNC_PASSWORD="$KEY"
  refute "the daemon refuses to follow symbolic links" rsync -L --list-only "rsync://offline@127.0.0.1:$PORT/media/movies/"
  refute "the daemon refuses uploads" rsync "$ROOT/README.md" "rsync://offline@127.0.0.1:$PORT/media/"
  refute "the daemon hides dot-folders" rsync --list-only "rsync://offline@127.0.0.1:$PORT/media/.hidden/"
  unset RSYNC_PASSWORD
  refute "the daemon refuses a client with no key" bash -c "RSYNC_PASSWORD=wrong rsync --list-only 'rsync://offline@127.0.0.1:$PORT/media/'"
  # A laptop on an older version offers no maps module; the rest must still sync.
  sed -i "s/^SYNC_MODULES=.*/SYNC_MODULES=\"knowledge media maps nosuch\"/" "$A/etc/offline/offline.conf"
  sed '/^\[maps\]/,$d' "$B/rsyncd.conf" >"$B/rsyncd-old.conf"
  OLDPORT=$((40000 + RANDOM % 20000))
  rsync --daemon --no-detach --config="$B/rsyncd-old.conf" --port="$OLDPORT" --address=127.0.0.1 --log-file="$B/rsyncd-old.log" &
  PIDS+=("$!")
  sleep 1
  head -c 4000 /dev/urandom >"$B/srv/offline/media/music/later.flac"
  check "an older laptop without the maps module still syncs" bash -c "OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$OLDPORT' '$ROOT/bin/offline' sync >/dev/null; [[ -f '$A/srv/offline/media/music/later.flac' ]]"
  echo "AAAAA-AAAAA-AAAAA-AAAAA-AAAAA" >"$A/etc/offline/house.key"
  check "a laptop with a different key is told so" bash -c "OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$PORT' '$ROOT/bin/offline' sync | grep -q 'refused this laptop'"
  echo "$KEY" >"$A/etc/offline/house.key"
else
  note "rsync not installed: peer sync checks not run"
fi

# --------------------------------------------------------------------------
# Portal, served with the same PHP restrictions as the production pool
# --------------------------------------------------------------------------

echo
echo "Portal"
if have curl; then
  offline status --write
  mkdir -p "$A/srv/offline/media/movies/Trips"
  head -c 2000 /dev/urandom >"$A/srv/offline/media/movies/Trips/Gower <2025>.mp4"
  mkdir -p "$WORK/site/app" "$WORK/site/public"
  cp -r "$ROOT/portal/app/." "$WORK/site/app/"
  cp -r "$ROOT/portal/public/." "$WORK/site/public/"
  printf '<?php define("OFFLINE_PREFIX", "%s"); require "%s/lib/core.php";\n' "$A" "$ROOT" >"$WORK/prepend.php"
  DISABLED="$(sed -n 's/^php_admin_value\[disable_functions\] = //p' "$ROOT/config/php-fpm-offline-portal.conf")"
  WEB=$((40000 + RANDOM % 20000))
  php -d auto_prepend_file="$WORK/prepend.php" -d disable_functions="$DISABLED" -d allow_url_fopen=0 \
    -d open_basedir="$WORK/:$ROOT/lib/:$ROOT/config/" -d display_errors=1 -d error_reporting=-1 \
    -S "127.0.0.1:$WEB" -t "$WORK/site/public" >"$WORK/php.log" 2>&1 &
  PIDS+=("$!")
  sleep 1
  code() { curl -s -o "$WORK/body.html" -w '%{http_code}' "$@"; }
  printf 'PMTiles-test' >"$A/srv/offline/maps/world.pmtiles"
  mkdir -p "$A/srv/offline/maps/assets/sprites/v4" && echo '{}' >"$A/srv/offline/maps/assets/sprites/v4/light.json"
  for page in "" library.php media.php "media.php?p=movies" "media.php?p=movies/Trips" "play.php?f=movies/holiday.mp4" peers.php guide.php \
    maps.php apps.php radio.php remedies.php "remedies.php?c=cough" "remedies.php?c=../../etc"; do
    if [[ "$(code "http://127.0.0.1:$WEB/$page")" == 200 ]] && ! grep -Eq 'Warning|Notice|Fatal error|Deprecated' "$WORK/body.html"; then
      pass "/$page renders without PHP errors"
    else
      fail "/$page renders without PHP errors"
    fi
  done
  check "folder traversal is refused" bash -c "[[ \$(curl -s -o /dev/null -w '%{http_code}' 'http://127.0.0.1:$WEB/media.php?p=../../etc') == 404 ]]"
  check "file traversal is refused" bash -c "[[ \$(curl -s -o /dev/null -w '%{http_code}' 'http://127.0.0.1:$WEB/play.php?f=../../../etc/passwd') == 404 ]]"
  check "POST is refused" bash -c "[[ \$(curl -s -o /dev/null -w '%{http_code}' -X POST 'http://127.0.0.1:$WEB/') == 405 ]]"
  curl -s "http://127.0.0.1:$WEB/media.php?p=movies/Trips" >"$WORK/body.html"
  check "file names are escaped in pages" grep -q 'Gower &lt;2025&gt;.mp4' "$WORK/body.html"
  refute "raw file names never reach a page" grep -q 'Gower <2025>' "$WORK/body.html"
  check "the map page lists the installed region" bash -c "curl -s 'http://127.0.0.1:$WEB/maps.php' | grep -q 'files/maps/world.pmtiles'"
  check "the map page widens its policy only to this laptop" bash -c "curl -sI 'http://127.0.0.1:$WEB/maps.php' | grep -qi \"connect-src 'self'; worker-src 'self'\""
  refute "other pages keep the stricter policy" bash -c "curl -sI 'http://127.0.0.1:$WEB/apps.php' | grep -qi 'connect-src'"
  check "the apps page shows what is installed" bash -c "curl -s 'http://127.0.0.1:$WEB/apps.php' | grep -q 'sudo offline apps start vaultwarden'"
  check "the radio page shows the plugged-in receiver" bash -c "curl -s 'http://127.0.0.1:$WEB/radio.php' | grep -q 'RTL-SDR receiver'"
  refute "generated sign-ins never reach a page" bash -c "curl -s 'http://127.0.0.1:$WEB/apps.php' | grep -q \"\$(cat '$A/etc/offline/apps/flatnotes.login')\""
  check "pages carry a Content-Security-Policy" bash -c "curl -sI 'http://127.0.0.1:$WEB/' | grep -qi \"content-security-policy: default-src 'none'\""
  refute "the house key never reaches a page" grep -rq "$KEY" "$WORK/body.html" "$A/run/offline/status.json"
else
  note "curl not installed: portal checks not run"
fi

echo
echo "$PASSED passed, $FAILED failed."
((FAILED == 0))
