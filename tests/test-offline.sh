#!/usr/bin/env bash
#
# Offline test suite. Changes nothing outside a temporary directory.
#
#   tests/test-offline.sh
#
# Static checks always run. Functional checks run when the tools they need
# (php, rsync, stunnel, curl) are present, and say so when they are skipped.

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
  # The site archiver starts a container, which only root can do; the container is where it is confined.
  if [[ "$name" == offline-archive.service ]]; then
    check "$name confines the crawl in the container instead" grep -q 'confined by the container' "$unit"
    continue
  fi
  check "$name runs as the offline account" grep -q '^User=offline$' "$unit"
  check "$name cannot gain privileges" grep -q '^NoNewPrivileges=yes$' "$unit"
  check "$name has a read-only system" grep -q '^ProtectSystem=strict$' "$unit"
  # The download queue exists to fetch from the internet; every other job is held to chosen networks.
  if [[ "$name" != offline-queue.service ]]; then
    check "$name is confined to chosen networks" grep -q '^IPAddressDeny=any$' "$unit"
  fi
done
check "portal PHP pool disables program execution" grep -q 'disable_functions.*proc_open' "$ROOT/config/php-fpm-offline-portal.conf"
check "portal PHP pool is confined by open_basedir" grep -q 'open_basedir' "$ROOT/config/php-fpm-offline-portal.conf"
check "portal sends a Content-Security-Policy" grep -q "default-src 'none'" "$ROOT/config/apache-offline-portal.conf"
check "the web server and the pages agree on the policy" bash -c "grep -q \"media-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'\" '$ROOT/config/apache-offline-portal.conf' && grep -q \"media-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'\" '$ROOT/portal/app/bootstrap.php'"
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
  refute "app $name publishes no port beyond this system" grep -Ev '^PublishPort=127\.0\.0\.1:' <(grep '^PublishPort=' "$unit" || true)
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

# Keyed, encrypted sharing; servers; family broadcast.
check "the sharing daemon listens on this machine only" grep -q '^address = 127.0.0.1' "$ROOT/config/rsyncd.conf"
check "the network listener demands a pre-shared key" grep -q '^ciphers = PSK' "$ROOT/config/stunnel.conf"
check "the network listener speaks TLS 1.3 only" grep -q '^sslVersionMin = TLSv1.3' "$ROOT/config/stunnel.conf"
check "the broadcast inbox can be written but not read" bash -c "sed -n '/^\\[inbox\\]/,\$p' '$ROOT/config/rsyncd.conf' | grep -q 'write only = yes'"
check "the broadcast inbox takes envelopes only" bash -c "sed -n '/^\\[inbox\\]/,\$p' '$ROOT/config/rsyncd.conf' | grep -q 'filter = + /\*.json - \*'"
refute "no content module accepts uploads" bash -c "sed '/^\\[inbox\\]/,\$d' '$ROOT/config/rsyncd.conf' | grep -q 'read only = no'"
check "the installer installs stunnel" grep -q 'stunnel4' "$ROOT/install.sh"
check "the installer closes the old unencrypted port" grep -q 'ufw delete allow from .* to any port 47001' "$ROOT/install.sh"
check "the installer knows the server role" grep -q -- '--role' "$ROOT/install.sh"
check "an internet server keeps its portal private" grep -q 'SCOPE=local' "$ROOT/install.sh"
for unit in offline-tls.service offline-broadcast.path offline-broadcast.service offline-broadcast-poll.timer offline-queue.timer offline-archive.service; do
  check "$unit is shipped" test -f "$ROOT/systemd/$unit"
done
for unit in offline-tls offline-broadcast offline-broadcast-poll offline-queue; do
  check "$unit runs as the unprivileged account" grep -q '^User=offline' "$ROOT/systemd/$unit.service"
  check "$unit cannot gain privileges" grep -q '^NoNewPrivileges=yes' "$ROOT/systemd/$unit.service"
done
check "the broadcast job sees serial ports and terminals only" grep -q '^DevicePolicy=closed' "$ROOT/systemd/offline-broadcast.service"
check "the household pack holds all of Wikipedia with images" grep -q '^household	wikipedia_en_all_maxi	' "$ROOT/config/catalogue.tsv"
check "the household pack holds WikiMed" grep -q '^household	wikipedia_en_medicine_maxi	' "$ROOT/config/catalogue.tsv"
check "the household pack holds Wikivoyage" grep -q '^household	wikivoyage_en_all_maxi	' "$ROOT/config/catalogue.tsv"
check "six continents are on the map list" bash -c "[[ \$(grep -c '	continents	' '$ROOT/config/maps.tsv') -eq 6 ]]"
check "the four UK nations are on the map list" bash -c "[[ \$(grep -c '	uk-nations	' '$ROOT/config/maps.tsv') -eq 4 ]]"
refute "every site to archive is reached over https" bash -c "grep -v '^#' '$ROOT/config/sites.tsv' | cut -f3 | grep -qv '^https://'"
if have shellcheck; then
  check "the desktop scripts pass shellcheck" shellcheck "$ROOT/desktop/offline-alert-agent" "$ROOT/desktop/offline-broadcast-send"
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
# A scratch system ("a") and a scratch peer ("b")
# --------------------------------------------------------------------------

make_root() {
  mkdir -p "$1"/etc/offline "$1"/usr/local/lib/offline "$1"/srv/offline/knowledge/zim \
    "$1"/srv/offline/media/movies "$1"/srv/offline/media/music "$1"/var/lib/offline "$1"/run/offline \
    "$1"/var/lib/offline/broadcasts "$1"/var/lib/offline/inbox "$1"/var/lib/offline/outbox "$1"/srv/offline/staging \
    "$1"/srv/offline/maps "$1"/srv/offline/radio "$1"/srv/offline/apps "$1"/sys/class/power_supply/BAT0 \
    "$1"/sys/bus/usb/devices/1-1 "$1"/sys/bus/usb/devices/1-2
  cp "$ROOT/config/catalogue.tsv" "$ROOT/config/maps.tsv" "$ROOT/config/sites.tsv" "$1/usr/local/lib/offline/"
  cp -r "$ROOT/apps" "$ROOT/data" "$1/usr/local/lib/offline/"
  echo 0bda >"$1/sys/bus/usb/devices/1-1/idVendor"; echo 2838 >"$1/sys/bus/usb/devices/1-1/idProduct"
  echo 10c4 >"$1/sys/bus/usb/devices/1-2/idVendor"; echo ea60 >"$1/sys/bus/usb/devices/1-2/idProduct"
  cp "$ROOT/config/offline.conf" "$1/etc/offline/"
  # The scratch machines have a mesh node "plugged in"; keep the real radio command out of most checks.
  sed -i 's/^MESH_GATEWAY=.*/MESH_GATEWAY=no/' "$1/etc/offline/offline.conf"
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
check "both systems now hold the same key" cmp -s "$A/etc/offline/house.key" "$B/etc/offline/house.key"
check "a transport key is derived beside the house key" grep -Eq '^offline:[0-9a-f]{64}$' "$A/etc/offline/sync.psk"
check "the transport key is private to the service account" bash -c "[[ \$(stat -c %a '$A/etc/offline/sync.psk') == 600 ]]"
refute "the transport key is not the house key" grep -q "$KEY" "$A/etc/offline/sync.psk"
check "both systems derive the same transport key" cmp -s "$A/etc/offline/sync.psk" "$B/etc/offline/sync.psk"
rm "$B/etc/offline/sync.psk"
check "an upgraded system regains its transport key" bash -c "OFFLINE_PREFIX='$B' '$ROOT/bin/offline' key refresh && cmp -s '$A/etc/offline/sync.psk' '$B/etc/offline/sync.psk'"

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
check "the continents are listed with their sizes" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' maps | grep -Eq 'south-america .*~8\\.3 GiB'"
check "the household pack is on offer" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' catalogue household | grep -q wikivoyage_en_all_maxi"
check "the UK pack is on offer" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' catalogue uk | grep -q nhs.uk_en_medicines"
check "NOMAD's regional maps are listed" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' maps | grep -q 'California'"
refute "a malformed map area is rejected" offline maps get --bbox 10,10,5,5 --name bad --dry-run
refute "a map name with path characters is rejected" offline maps get --bbox -5,50,-4,51 --name ../../etc --dry-run
refute "an unknown map region is rejected" offline maps get atlantis --dry-run
check "sixteen applications are on offer" bash -c "[[ \$(OFFLINE_PREFIX='$A' '$ROOT/bin/offline' apps | grep -c 'localhost\|this system only') -eq 16 ]]"
refute "an unknown application is rejected" offline apps add no-such-app
check "applications can be prepared" offline apps add cyberchef flatnotes vaultwarden
check "an app proxy forwards to this system only" grep -q 'ProxyPass / http://127.0.0.1:18100/' "$A/etc/apache2/sites-available/offline-app-cyberchef.conf"
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
echo "Servers, queue and staging"
check "a central server can be named" offline server add files.example.org
check "a second server can be named, with its own port" offline server add 203.0.113.9:47443
check "the servers are recorded in the settings" grep -q '^SERVERS="files.example.org 203.0.113.9:47443"' "$A/etc/offline/offline.conf"
check "the servers are listed" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' server | grep -q '203.0.113.9 *47443'"
refute "a server name with shell characters is rejected" offline server add 'bad;host'
check "a server can be removed" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' server remove 203.0.113.9 && OFFLINE_PREFIX='$A' '$ROOT/bin/offline' server remove files.example.org && grep -q '^SERVERS=\"\"' '$A/etc/offline/offline.conf'"
check "UK websites to archive are listed" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' queue sites | grep -q 'nhs-conditions'"
check "a group of websites can be queued" bash -c "[[ \$(OFFLINE_PREFIX='$A' '$ROOT/bin/offline' queue site uk | grep -c '^Queued') -eq 3 ]]"
refute "a website over plain http is not queued" offline queue site http://example.org/ --name example.org_en_all
refute "a website needs a well-formed title name" offline queue site https://example.org/ --name '../x'
check "a download can be queued" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' queue add https://example.org/films/holiday.mp4 --to tv | grep -q '^Queued'"
check "a plain http download is refused" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' queue add http://example.org/x.mp4 2>&1 | grep -q 'only https'"
refute "an unknown media folder is refused" offline queue add https://example.org/x.mp4 --to ../../etc
check "the queue shows what is waiting" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' queue | grep -q 'media/tv'"
# shellcheck disable=SC2016
QID="$(php -r '$q = json_decode(file_get_contents($argv[1]), true); foreach ($q["items"] as $i) { if ($i["kind"] === "file") { echo $i["id"]; } }' "$A/var/lib/offline/queue.json")"
check "queue items have well-formed ids" grep -Eq '^[0-9a-f]{8}$' <<<"$QID"
# Stand in for a finished download: files in the staging folder, item marked staged.
mkdir -p "$A/srv/offline/staging/$QID/extras"
head -c 3000 /dev/urandom >"$A/srv/offline/staging/$QID/holiday.mp4"
printf '#!/bin/sh\n' >"$A/srv/offline/staging/$QID/setup.sh"
make_zim "$A/srv/offline/staging/$QID/extras/guide_en_all_2026-11.zim"
ln -s /etc/passwd "$A/srv/offline/staging/$QID/passwd.txt"
check "the downloader records a result" offline queue mark "$QID" staged "3 files"
refute "a queue result with an unknown state is refused" offline queue mark "$QID" bogus x
check "staged files are not yet shared" test ! -e "$A/srv/offline/media/tv/holiday.mp4"
check "staging lists what awaits release" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' stage | grep -q 'holiday.mp4'"
check "staged content can be published" offline stage publish "$QID"
check "a published film reaches its media folder" test -f "$A/srv/offline/media/tv/holiday.mp4"
check "a published library title reaches the library" test -f "$A/srv/offline/knowledge/zim/guide_en_all_2026-11.zim"
check "a script in staging is never published" bash -c "! find '$A/srv/offline/media' '$A/srv/offline/knowledge' -name 'setup.sh' | grep -q ."
check "a link in staging is never published" bash -c "! find '$A/srv/offline/media' '$A/srv/offline/knowledge' -name 'passwd.txt' | grep -q ."
check "the item is recorded as published" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' queue | grep -q 'published'"
check "a queued item can be removed" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' queue remove '$QID' && ! grep -q '$QID' '$A/var/lib/offline/queue.json' && [[ ! -e '$A/srv/offline/staging/$QID' ]]"
check "status reports the role and the queue" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' status --json | php -r '\$s = json_decode(stream_get_contents(STDIN), true); exit(\$s[\"role\"] === \"workstation\" && \$s[\"queue\"][\"total\"] === 3 ? 0 : 1);'"

# --------------------------------------------------------------------------
# Sync and broadcast between two systems, through the shipped rsync and
# stunnel configurations: every connection is TLS with the derived key.
# --------------------------------------------------------------------------

echo
echo "Keyed, encrypted sync"
if have rsync && have stunnel; then
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

  # start_system ROOT NAME [RSYNCD CONFIG]: an rsync daemon on a loopback port and stunnel in front of it.
  # Sets DPORT (the daemon) and PORT (the encrypted listener).
  start_system() {
    local root="$1" name="$2" source="${3:-$ROOT/config/rsyncd.conf}"
    DPORT=$((30000 + RANDOM % 15000))
    PORT=$((DPORT + 15000))
    {
      # The daemon normally runs as "offline"; only the test, when run as root, pins the uid.
      if ((EUID == 0)); then printf 'uid = 0\ngid = 0\n'; fi
      sed -e "s#/srv/offline#$root/srv/offline#" -e "s#/etc/offline/rsyncd.secrets#$root/etc/offline/rsyncd.secrets#" \
        -e "s#/run/offline/#$root/run/offline/#" -e "s#/var/lib/offline/#$root/var/lib/offline/#" "$source"
    } >"$root/$name-rsyncd.conf"
    sed -e "s#^accept = .*#accept = 127.0.0.1:$PORT#" -e "s#^connect = .*#connect = 127.0.0.1:$DPORT#" \
      -e "s#/etc/offline/sync.psk#$root/etc/offline/sync.psk#" "$ROOT/config/stunnel.conf" >"$root/$name-stunnel.conf"
    rsync --daemon --no-detach --config="$root/$name-rsyncd.conf" --port="$DPORT" --address=127.0.0.1 --log-file="$root/$name-rsyncd.log" </dev/null &
    PIDS+=("$!")
    stunnel "$root/$name-stunnel.conf" </dev/null >"$root/$name-stunnel.log" 2>&1 &
    PIDS+=("$!")
    sleep 1.5
  }
  start_system "$B" b
  BPORT="$PORT"
  BDPORT="$DPORT"
  peer() { OFFLINE_PREFIX="$A" OFFLINE_TEST_PEERS="127.0.0.1:$BPORT" "$ROOT/bin/offline" "$@"; }

  check "a dry run fetches nothing" bash -c "OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' sync --dry-run && [[ ! -e '$A/srv/offline/media/movies/holiday.mp4' ]]"
  check "sync completes over the encrypted channel" peer sync
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
  check "the fake ZIM is remembered and not requested again" bash -c "OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' sync | grep -q 'nothing new to fetch'"
  check "the peer is recorded as trusted" grep -q '"auth": "ok"' "$A/var/lib/offline/peers.json"
  refute "no connection settings are left behind" bash -c "find '$A/var/lib/offline' -name 'tls-*' | grep -q ."
  check "a named server is pulled from like any peer" bash -c "sed -i 's/^SERVERS=.*/SERVERS=\"127.0.0.1:$BPORT\"/' '$A/etc/offline/offline.conf' && OFFLINE_PREFIX='$A' '$ROOT/bin/offline' server test | grep -q 'key accepted'"
  check "sync lists a named server first" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' sync | grep -q '1 system found, 1 trusted'"
  sed -i 's/^SERVERS=.*/SERVERS=""/' "$A/etc/offline/offline.conf"

  # The encrypted port: nothing without the key, and nothing in the clear.
  export RSYNC_PASSWORD="$KEY"
  refute "plain rsync to the encrypted port is refused" timeout 20 rsync --list-only --timeout=10 "rsync://offline@127.0.0.1:$BPORT/media/"
  printf 'offline:%064d\n' 0 >"$WORK/wrong.psk"
  chmod 600 "$WORK/wrong.psk"
  printf 'foreground = yes\npid =\nclient = yes\nconnect = 127.0.0.1:%s\nciphers = PSK\nPSKsecrets = %s\n' "$BPORT" "$WORK/wrong.psk" >"$WORK/wrong-stunnel.conf"
  refute "TLS with the wrong key is refused" bash -c "RSYNC_CONNECT_PROG='stunnel $WORK/wrong-stunnel.conf' timeout 20 rsync --list-only --timeout=10 'rsync://offline@127.0.0.1/media/'"
  printf 'foreground = yes\npid =\nclient = yes\nconnect = 127.0.0.1:%s\nciphers = PSK\nPSKsecrets = %s\n' "$BPORT" "$A/etc/offline/sync.psk" >"$WORK/right-stunnel.conf"
  check "TLS with the right key is accepted" bash -c "RSYNC_CONNECT_PROG='stunnel $WORK/right-stunnel.conf' timeout 20 rsync --list-only --timeout=10 'rsync://offline@127.0.0.1/media/'"
  if have openssl; then
    PSK_HEX="$(cut -d: -f2 "$A/etc/offline/sync.psk")"
    check "the key holder gets TLS 1.3 with a fresh key exchange" bash -c "echo | timeout 10 openssl s_client -connect 127.0.0.1:$BPORT -psk '$PSK_HEX' -psk_identity offline 2>/dev/null | grep -q 'Server Temp Key: X25519'"
    refute "TLS 1.2 is refused even with the key" bash -c "echo | timeout 10 openssl s_client -connect 127.0.0.1:$BPORT -psk '$PSK_HEX' -psk_identity offline -tls1_2 2>/dev/null | grep -q 'Cipher is [A-Z]'"
    refute "a client offering certificates and no key gets no session" bash -c "echo | timeout 10 openssl s_client -connect 127.0.0.1:$BPORT 2>/dev/null | grep -q 'Cipher is TLS_'"
  fi

  # The daemon behind it, reached directly on its loopback port.
  refute "the daemon refuses to follow symbolic links" rsync -L --list-only "rsync://offline@127.0.0.1:$BDPORT/media/movies/"
  refute "the daemon refuses uploads to content" rsync "$ROOT/README.md" "rsync://offline@127.0.0.1:$BDPORT/media/"
  refute "the daemon hides dot-folders" rsync --list-only "rsync://offline@127.0.0.1:$BDPORT/media/.hidden/"
  echo '{}' >"$WORK/20260101T000000Z-00000000.json"
  cp "$WORK/20260101T000000Z-00000000.json" "$WORK/note.txt"
  check "the inbox accepts an envelope" rsync "$WORK/20260101T000000Z-00000000.json" "rsync://offline@127.0.0.1:$BDPORT/inbox/"
  refute "the inbox refuses any other file" rsync "$WORK/note.txt" "rsync://offline@127.0.0.1:$BDPORT/inbox/"
  refute "the inbox cannot be listed" rsync --list-only "rsync://offline@127.0.0.1:$BDPORT/inbox/"
  refute "the inbox cannot be read back" rsync "rsync://offline@127.0.0.1:$BDPORT/inbox/20260101T000000Z-00000000.json" "$WORK/back.json"
  unset RSYNC_PASSWORD
  refute "the daemon refuses a client with no key" bash -c "RSYNC_PASSWORD=wrong rsync --list-only 'rsync://offline@127.0.0.1:$BDPORT/media/'"

  # A system on an older version offers no maps module; the rest must still sync.
  sed -i "s/^SYNC_MODULES=.*/SYNC_MODULES=\"knowledge media maps nosuch\"/" "$A/etc/offline/offline.conf"
  sed '/^\[maps\]/,$d' "$ROOT/config/rsyncd.conf" >"$WORK/rsyncd-old.conf"
  start_system "$B" old "$WORK/rsyncd-old.conf"
  OLDPORT="$PORT"
  head -c 4000 /dev/urandom >"$B/srv/offline/media/music/later.flac"
  check "an older system without the maps module still syncs" bash -c "OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$OLDPORT' '$ROOT/bin/offline' sync >/dev/null; [[ -f '$A/srv/offline/media/music/later.flac' ]]"
  echo "AAAAA-AAAAA-AAAAA-AAAAA-AAAAA" | OFFLINE_PREFIX="$A" "$ROOT/bin/offline" key set - >/dev/null
  check "a system with a different key is told so" bash -c "OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' sync | grep -q 'holds a different house key'"
  check "nothing is fetched with a different key" bash -c "head -c 100 /dev/urandom >'$B/srv/offline/media/music/secret.flac'; OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' sync >/dev/null; [[ ! -e '$A/srv/offline/media/music/secret.flac' ]]"
  check "doctor notices a transport key that does not match" bash -c "echo 'offline:00' >'$A/etc/offline/sync.psk'; OFFLINE_PREFIX='$A' '$ROOT/bin/offline' doctor | grep -q 'does not match the house key'"
  echo "$KEY" | OFFLINE_PREFIX="$A" "$ROOT/bin/offline" key set - >/dev/null

  echo
  echo "Family broadcast"
  bcast() { OFFLINE_PREFIX="$A" OFFLINE_TEST_PEERS="127.0.0.1:$BPORT" "$ROOT/bin/offline" broadcast "$@"; }
  on_b() { OFFLINE_PREFIX="$B" OFFLINE_TEST_PEERS="" "$ROOT/bin/offline" "$@"; }
  on_b broadcast process >/dev/null 2>&1 || true   # clears the empty envelope left by the inbox checks
  check "a broadcast is sent" bash -c "OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' broadcast 'Dinner is <b>ready</b>' | grep -q 'passed to 1 of 1 other system'"
  check "it is on the sender's own screen at once" grep -q 'Dinner is <b>ready<' "$A/run/offline/broadcasts.json"
  check "it arrived in the other system's inbox" bash -c "find '$B/var/lib/offline/inbox' -type f | grep -Eq '/[0-9]{8}T[0-9]{6}Z-[0-9a-f]{8}\.json$'"
  check "the other system accepts it" bash -c "OFFLINE_PREFIX='$B' OFFLINE_TEST_PEERS='' '$ROOT/bin/offline' broadcast process | grep -q 'received'"
  check "the other system shows it" grep -q 'Dinner is <b>ready<' "$B/run/offline/broadcasts.json"
  check "the inbox is emptied" bash -c "[[ -z \$(ls -A '$B/var/lib/offline/inbox') ]]"
  refute "the file read by the portal carries no signature" grep -q '"mac"' "$B/run/offline/broadcasts.json"
  check "an urgent broadcast is marked urgent" bash -c "OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' broadcast --urgent 'Power cut' >/dev/null && grep -q '\"level\": \"urgent\"' '$A/run/offline/broadcasts.json'"
  check "recent broadcasts are listed with their delivery" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' broadcast | grep -q '127.0.0.1:$BPORT: delivered'"
  refute "an empty broadcast is refused" bcast '   '
  refute "an over-long broadcast is refused" bcast "$(head -c 600 /dev/zero | tr '\0' x)"
  refute "control characters are refused on the command line" bcast $'one\ttwo'
  # shellcheck disable=SC2016
  check "line breaks and invisible characters are flattened" php -r 'require $argv[1] . "/lib/cli.php"; exit(Offline\broadcast_clean("one\ttwo\u{200b}\n  three ") === "one two three" ? 0 : 1);' "$ROOT"

  # Forgeries, tampering and replays against B.
  on_b broadcast process >/dev/null 2>&1 || true
  GOOD="$(find "$A/var/lib/offline/broadcasts" -name '*.json' | sort | head -n 1)"
  GOODID="$(basename "$GOOD" .json)"
  sed 's/Dinner is/Send money: dinner is/' "$GOOD" >"$B/var/lib/offline/inbox/$GOODID.json"
  rm -f "$B/var/lib/offline/broadcasts/$GOODID.json"
  on_b broadcast process >/dev/null 2>&1 || true
  refute "an altered broadcast is refused" grep -rq 'Send money' "$B/var/lib/offline/broadcasts" "$B/run/offline/broadcasts.json"
  # shellcheck disable=SC2016
  php -r '$e = ["v" => 1, "id" => "20261121T000000Z-aaaaaaaa", "from" => "intruder", "by" => "", "sent" => "2026-11-21T00:00:00Z", "level" => "urgent", "mesh" => "none", "text" => "Unsigned alarm", "mac" => str_repeat("0", 64)]; file_put_contents($argv[1] . "/" . $e["id"] . ".json", json_encode($e));' "$B/var/lib/offline/inbox"
  ln -s /etc/passwd "$B/var/lib/offline/inbox/20261121T000001Z-bbbbbbbb.json"
  mkdir "$B/var/lib/offline/inbox/20261121T000002Z-cccccccc.json"
  head -c 9000 /dev/zero >"$B/var/lib/offline/inbox/20261121T000003Z-dddddddd.json"
  on_b broadcast process >/dev/null 2>&1 || true
  refute "an unsigned broadcast is refused" grep -rq 'Unsigned alarm' "$B/var/lib/offline/broadcasts" "$B/run/offline/broadcasts.json"
  check "links, folders and oversized files in the inbox are cleared away" bash -c "[[ -z \$(ls -A '$B/var/lib/offline/inbox') ]]"
  check "the link's target is untouched" test -s /etc/passwd
  # A broadcast signed with the right key but dated two days ago, as a replayed capture would be.
  # shellcheck disable=SC2016
  OLD_ENVELOPE="$(OFFLINE_PREFIX="$B" php -r '
    define("OFFLINE_PREFIX", getenv("OFFLINE_PREFIX")); require $argv[1] . "/lib/cli.php";
    $t = time() - 2 * 86400;
    $e = ["v" => 1, "id" => gmdate("Ymd\THis\Z", $t) . "-eeeeeeee", "from" => "b", "by" => "", "sent" => gmdate("Y-m-d\TH:i:s\Z", $t), "level" => "info", "mesh" => "none", "text" => "Stale news"];
    $e["mac"] = Offline\broadcast_mac($e, Offline\derived_key("offline-broadcast-v1"));
    file_put_contents(Offline\path("inbox") . "/" . $e["id"] . ".json", json_encode($e)); echo $e["id"];' "$ROOT")"
  on_b broadcast process >/dev/null 2>&1 || true
  refute "a correctly signed but stale broadcast is refused" test -e "$B/var/lib/offline/broadcasts/$OLD_ENVELOPE.json"
  cp "$A/var/lib/offline/broadcasts/"*.json "$B/var/lib/offline/inbox/"
  check "a broadcast delivered twice is shown once" bash -c "OFFLINE_PREFIX='$B' OFFLINE_TEST_PEERS='' '$ROOT/bin/offline' broadcast process >/dev/null; OFFLINE_PREFIX='$B' OFFLINE_TEST_PEERS='' '$ROOT/bin/offline' broadcast process | grep -vq 'received'; [[ \$(grep -c 'Power cut' '$B/run/offline/broadcasts.json') -eq 1 ]]"

  # A system that was asleep collects what it missed by asking, with no push at all.
  C="$WORK/c"
  make_root "$C"
  echo "$KEY" | OFFLINE_PREFIX="$C" "$ROOT/bin/offline" key set - >/dev/null
  check "a system that missed a broadcast collects it when it next asks" bash -c "OFFLINE_PREFIX='$C' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' broadcast process --poll | grep -q 'received' && grep -q 'Power cut' '$C/run/offline/broadcasts.json'"
  check "asking again brings nothing twice" bash -c "! OFFLINE_PREFIX='$C' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' broadcast process --poll | grep -q 'received'"
  D="$WORK/d"
  make_root "$D"
  OFFLINE_PREFIX="$D" "$ROOT/bin/offline" key new >/dev/null
  refute "a system with another key collects nothing" bash -c "OFFLINE_PREFIX='$D' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' broadcast process --poll | grep -q 'received'"
  refute "a system with another key cannot push a broadcast" bash -c "OFFLINE_PREFIX='$D' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' broadcast 'Intruder' | grep -q 'passed to 1 of 1'"

  # The mesh: this scratch machine has a node "plugged in" (a CP210x in its fake sysfs).
  check "the mesh text names the sender and marks urgency" bash -c "php -r 'require \$argv[1] . \"/lib/cli.php\"; \$t = Offline\\broadcast_mesh_text([\"level\" => \"urgent\", \"from\" => \"study\", \"by\" => \"peter\", \"text\" => \"Come home\"]); exit(\$t === \"URGENT Family broadcast from peter at study: Come home\" ? 0 : 1);' '$ROOT'"
  check "the mesh text fits one radio packet" bash -c "php -r 'require \$argv[1] . \"/lib/cli.php\"; \$t = Offline\\broadcast_mesh_text([\"level\" => \"info\", \"from\" => \"study\", \"by\" => \"\", \"text\" => str_repeat(\"é\", 400)]); exit(strlen(\$t) <= 200 && mb_check_encoding(\$t, \"UTF-8\") ? 0 : 1);' '$ROOT'"
  sed -i 's/^MESH_GATEWAY=.*/MESH_GATEWAY=yes/' "$A/etc/offline/offline.conf"
  check "a system with a node attempts the mesh" bash -c "OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='' '$ROOT/bin/offline' broadcast 'Radio check' | grep -Eq 'mesh: (sent|failed)'"
  check "its envelope says the sender handles the mesh" bash -c "grep -l 'Radio check' '$A/var/lib/offline/broadcasts/'*.json | xargs grep -q '\"mesh\":\"origin\"'"
  sed -i 's/^MESH_GATEWAY=.*/MESH_GATEWAY=no/' "$A/etc/offline/offline.conf"

  # What the desktop alert would show, for a person who has seen nothing yet.
  mkdir -p "$WORK/home"
  check "the desktop alert runs for an ordinary user" bash -c "HOME='$WORK/home' XDG_STATE_HOME='$WORK/home/state' DISPLAY= WAYLAND_DISPLAY= OFFLINE_PREFIX='$A' '$ROOT/bin/offline' broadcast alert"
  check "it remembers what the user has been shown" bash -c "[[ \$(wc -l <'$WORK/home/state/offline/broadcasts-seen') -ge 3 ]]"
else
  note "rsync or stunnel not installed: sync and broadcast checks not run"
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
  for page in "" library.php media.php "media.php?p=movies" "media.php?p=movies/Trips" "play.php?f=movies/holiday.mp4" peers.php guide.php alerts.php \
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
  check "the map page widens its policy only to this system" bash -c "curl -sI 'http://127.0.0.1:$WEB/maps.php' | grep -qi \"connect-src 'self'; worker-src 'self'\""
  refute "other pages keep the stricter policy" bash -c "curl -sI 'http://127.0.0.1:$WEB/apps.php' | grep -qi 'worker-src'"
  check "scripts may fetch from the portal alone" bash -c "curl -sI 'http://127.0.0.1:$WEB/apps.php' | grep -qi \"connect-src 'self'; base-uri 'none'\""
  if [[ -s "$A/run/offline/broadcasts.json" ]]; then
    check "a broadcast heads every page" bash -c "curl -s 'http://127.0.0.1:$WEB/library.php' | grep -q 'class=\"broadcast broadcast-urgent\" role=\"alert\"'"
    check "broadcast text is escaped in pages" bash -c "curl -s 'http://127.0.0.1:$WEB/' | grep -q 'Dinner is &lt;b&gt;ready&lt;/b&gt;'"
    refute "raw broadcast markup never reaches a page" bash -c "curl -s 'http://127.0.0.1:$WEB/' | grep -q 'Dinner is <b>'"
    check "the banner feed is JSON" bash -c "curl -sI 'http://127.0.0.1:$WEB/alerts.php' | grep -qi '^content-type: application/json'"
    check "the banner feed lists current broadcasts" bash -c "curl -s 'http://127.0.0.1:$WEB/alerts.php' | php -r '\$d = json_decode(stream_get_contents(STDIN), true); exit(count(\$d[\"broadcasts\"] ?? []) >= 2 ? 0 : 1);'"
    refute "markup in the banner feed is neutralised" bash -c "curl -s 'http://127.0.0.1:$WEB/alerts.php' | grep -q '<b>'"
    refute "the banner feed carries no signature" bash -c "curl -s 'http://127.0.0.1:$WEB/alerts.php' | grep -q 'mac'"
  fi
  check "the systems page explains the broadcast" bash -c "curl -s 'http://127.0.0.1:$WEB/peers.php' | grep -q 'offline broadcast --urgent'"
  check "the systems page shows the download queue" bash -c "curl -s 'http://127.0.0.1:$WEB/peers.php' | grep -q 'NHS Health A to Z'"
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
