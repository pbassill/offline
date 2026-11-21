#!/usr/bin/env bash
# shellcheck disable=SC2016  # the PHP snippets passed to php -r are meant to reach it unexpanded
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
  case "$name" in
    # These act on the system itself: the firewall table, login accounts, this system's certificate.
    offline-children-guard.service | offline-accounts.service | offline-tls-renew.service | offline-net.service)
      check "$name, which must be root, is held to named capabilities" grep -Eq '^CapabilityBoundingSet=CAP_[A-Z_ ]+$' "$unit" ;;
    offline-sessions.service)
      check "$name runs as the sign-in account" grep -q '^User=offline-auth$' "$unit" ;;
    *)
      check "$name runs as the offline account" grep -q '^User=offline$' "$unit" ;;
  esac
  check "$name cannot gain privileges" grep -q '^NoNewPrivileges=yes$' "$unit"
  check "$name has a read-only system" grep -q '^ProtectSystem=strict$' "$unit"
  # The download queue exists to fetch from the internet; every other job is held to chosen networks.
  if [[ "$name" != offline-queue.service ]]; then
    check "$name is confined to chosen networks" grep -q '^IPAddressDeny=any$' "$unit"
  fi
done
check "portal PHP pool disables program execution" grep -q 'disable_functions.*proc_open' "$ROOT/config/php-fpm-offline-portal.conf"
check "portal PHP pool is confined by open_basedir" grep -q 'open_basedir' "$ROOT/config/php-fpm-offline-portal.conf"
check "portal sends a Content-Security-Policy" grep -q "default-src 'none'" "$ROOT/config/apache-offline-portal-common.conf"
check "the web server and the pages agree on the policy" bash -c "grep -q \"media-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'\" '$ROOT/config/apache-offline-portal-common.conf' && grep -q \"media-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'\" '$ROOT/portal/app/bootstrap.php'"
refute "portal loads nothing from another site" grep -rEq '(src|href)="https?://' "$ROOT/portal/app" "$ROOT/portal/public/index.php" "$ROOT/portal/public/library.php" "$ROOT/portal/public/media.php" "$ROOT/portal/public/play.php" "$ROOT/portal/public/peers.php"
refute "portal has no inline styles or scripts" grep -rEq 'style="|<script>|onclick=' "$ROOT/portal/app" "$ROOT/portal/public"/*.php
check "vendored assets match their checksums" bash -c "cd '$ROOT/portal/public/assets' && sha256sum --quiet -c CHECKSUMS"
check "the map viewer may only talk to this laptop" grep -q "connect-src 'self'; worker-src 'self'" "$ROOT/portal/public/maps.php"
check "the map tool is pinned by checksum for both architectures" bash -c "grep -Eq '^readonly PMTILES_SHA256_AMD64=\"[0-9a-f]{64}\"' '$ROOT/install.sh' && grep -Eq '^readonly PMTILES_SHA256_ARM64=\"[0-9a-f]{64}\"' '$ROOT/install.sh'"
check "map fonts and symbols are pinned by commit" grep -Eq "BASEMAP_ASSETS_COMMIT = '[0-9a-f]{40}'" "$ROOT/lib/maps.php"

# Accounts, sign-in and what children may reach.
GATE="$ROOT/config/apache-offline-session-gate.conf"
SITE="$ROOT/config/apache-offline-portal.conf"
COMMON="$ROOT/config/apache-offline-portal-common.conf"
check "the session cookie is one browsers send over HTTPS only" bash -c "grep -q '__Host-offline-session=' '$GATE' && grep -q \"const SESSION_COOKIE = '__Host-offline-session';\" '$ROOT/lib/core.php' && grep -q 'Secure; HttpOnly; SameSite=Strict' '$ROOT/portal/public/account/signin.php'"
check "a session is recognised by 256 random bits and nothing else" grep -q '=(\[A-Za-z0-9_-\]{43})' "$GATE"
check "the plain site never checks a session, so nothing there is released to one" bash -c "[[ \$(sed -n '/<VirtualHost \\*:80>/,/<\\/VirtualHost>/p' '$SITE' | grep -c 'session-gate') -eq 0 ]]"
check "the encrypted site and the adults' library check the session" bash -c "[[ \$(grep -c 'Include /etc/apache2/offline/session-gate.conf' '$SITE') -eq 2 ]]"
check "the adults' library answers only an adult who has signed in" bash -c "sed -n '/<VirtualHost \\*:8443>/,/<\\/VirtualHost>/p' '$SITE' | grep -q 'Require env OFFLINE_ADULT'"
check "the adults' library is proxied from this system only" grep -q 'ProxyPass / http://127.0.0.1:8090/' "$SITE"
check "films are released only to an adult who has signed in" bash -c "sed -n '/<Directory \\/srv\\/offline\\/media>/,/<\\/Directory>/p' '$COMMON' | grep -q 'Require env OFFLINE_ADULT'"
refute "the children's folder asks for no session" bash -c "sed -n '/<Directory \\/srv\\/offline\\/media\\/children>/,/<\\/Directory>/p' '$COMMON' | grep -q 'OFFLINE_ADULT'"
check "of the certificate folder only the authority's certificate is served" bash -c "sed -n '/<Directory \\/etc\\/offline\\/tls>/,/<\\/Directory>/p' '$COMMON' | grep -q 'Require all denied' && grep -q '<Files \"ca.crt\">' '$COMMON'"
# shellcheck disable=SC2016
check "signing in is redirected off the plain site" grep -q 'RewriteRule ^/account(/.\*)?\$ https://%1/account\$1 \[R=302,L\]' "$SITE"
check "only the sign-in pages accept a form" bash -c "[[ \$(grep -c 'Require method GET HEAD POST' '$SITE' '$COMMON' | awk -F: '{n += \$2} END {print n}') -eq 1 ]]"
refute "no site sends Strict-Transport-Security, which would break the plain-HTTP ports" grep -q '^ *Header.*Strict-Transport-Security' "$SITE" "$COMMON"
AUTH_POOL="$ROOT/config/php-fpm-offline-auth.conf"
check "the sign-in pages run as an account of their own" bash -c "grep -q '^user = offline-auth$' '$AUTH_POOL' && grep -q '^user = offline-web$' '$ROOT/config/php-fpm-offline-portal.conf'"
check "the sign-in pool runs no programs and opens no connections" bash -c "grep -q 'disable_functions.*proc_open.*fsockopen.*curl_exec' '$AUTH_POOL'"
refute "the sign-in pool cannot reach the content" grep -q 'open_basedir.*/srv/offline' "$AUTH_POOL"
check "the ordinary pages cannot write a session, or any other file" grep -q 'disable_functions.*,rename,.*,file_put_contents,' "$ROOT/config/php-fpm-offline-portal.conf"
check "account records are offered read-only, one file and nothing else" bash -c "sed -n '/^\\[accounts\\]/,/^\\[broadcasts\\]/p' '$ROOT/config/rsyncd.conf' | grep -q 'include = /accounts.json' && sed -n '/^\\[accounts\\]/,/^\\[broadcasts\\]/p' '$ROOT/config/rsyncd.conf' | grep -q 'exclude = \\*' && ! sed -n '/^\\[accounts\\]/,/^\\[broadcasts\\]/p' '$ROOT/config/rsyncd.conf' | grep -q 'read only = no'"
check "the session folder exists before the web server starts, in memory" grep -q '^f /run/offline-auth/sessions.map 0640 offline-auth offline-session' "$ROOT/config/tmpfiles-offline.conf"
GUARD="$ROOT/config/children-guard.nft"
check "the children's guard refuses whatever it does not name" grep -q 'counter reject with icmpx type admin-prohibited' "$GUARD"
check "the children's guard recognises a child by group" grep -q 'meta skgid "offline-child" jump children' "$GUARD"
refute "the children's guard opens no adult service" grep -Eq 'dport \{[^}]*\b(8090|8443|3142|47001|47002|11434|22)\b' "$GUARD"
if have nft && getent group offline-child >/dev/null && nft -c -f "$GUARD" 2>/dev/null; then
  pass "the firewall accepts the children's guard"
else
  note "nft, the offline-child group or the right to use them is missing: the children's guard was not parsed"
fi
check "the job that applies replicated accounts has no network and a read-only system" bash -c "grep -q '^IPAddressDeny=any$' '$ROOT/systemd/offline-accounts.service' && grep -q '^RestrictAddressFamilies=AF_UNIX AF_NETLINK$' '$ROOT/systemd/offline-accounts.service' && grep -q '^ReadWritePaths=/etc /home' '$ROOT/systemd/offline-accounts.service'"
check "replication runs the apply in the mode that leaves administrators alone" grep -q '^ExecStart=/usr/local/bin/offline user apply --replicated$' "$ROOT/systemd/offline-accounts.service"
check "the installer keeps child accounts out of SSH" grep -q "DenyGroups offline-child" "$ROOT/install.sh"
# shellcheck disable=SC2016
check "the installer closes the library and the films to the children's group" grep -q 'setfacl -m g:offline-child:---,m::rwx "$SHARE_ROOT/knowledge" "$SHARE_ROOT/media"' "$ROOT/install.sh"
check "the children's shelf reader serves the children's index" grep -q -- '--library /var/lib/offline/library-children.xml$' "$ROOT/systemd/offline-reader.service"
check "the adults' reader listens on this system only" bash -c "grep -q -- '--address=127.0.0.1 --port=8090' '$ROOT/systemd/offline-reader-adults.service' && grep -q '^IPAddressAllow=localhost$' '$ROOT/systemd/offline-reader-adults.service'"
check "every page is for adults unless listed as public" grep -q "portal_require_adult();" "$ROOT/portal/app/bootstrap.php"
# shellcheck disable=SC2016
check "an application with no sign-in of its own is put behind the portal's, over TLS" php -r 'require $argv[1] . "/lib/cli.php"; define("X", 1); $apps = Offline\app_index(); foreach (["cyberchef", "it-tools", "stirling-pdf", "translate", "meshtastic-web", "meshcore-web"] as $id) { $c = Offline\app_proxy_config($apps[$id]); if ($apps[$id]["scheme"] !== "https" || !str_contains($c, "Require env OFFLINE_ADULT") || !str_contains($c, "session-gate.conf") || !str_contains($c, "SSLEngine on")) { fwrite(STDERR, $id); exit(1); } } exit(0);' "$ROOT"
# shellcheck disable=SC2016
check "an application with its own sign-in keeps to it" php -r 'require $argv[1] . "/lib/cli.php"; $apps = Offline\app_index(); foreach (["jellyfin", "vaultwarden", "kolibri", "excalidraw"] as $id) { if (str_contains(Offline\app_proxy_config($apps[$id]), "OFFLINE_ADULT")) { exit(1); } } exit($apps["kolibri"]["audience"] === "all" && $apps["jellyfin"]["audience"] === "own" ? 0 : 1);' "$ROOT"

# The private network.
check "Reticulum is pinned by version and by the checksum of its wheel" bash -c "grep -Eq \"^const RNS_VERSION = '[0-9]+\\.[0-9]+\\.[0-9]+';\" '$ROOT/lib/net.php' && grep -Eq \"^const RNS_WHEEL_SHA256 = '[0-9a-f]{64}';\" '$ROOT/lib/net.php' && grep -q -- \"'--require-hashes'\" '$ROOT/lib/net.php' && grep -q -- \"'--no-deps'\" '$ROOT/lib/net.php'"
check "the list of systems is offered read-only, one file and nothing else" bash -c "sed -n '/^\\[network\\]/,/^\\[broadcasts\\]/p' '$ROOT/config/rsyncd.conf' | grep -q 'include = /nodes.json' && sed -n '/^\\[network\\]/,/^\\[broadcasts\\]/p' '$ROOT/config/rsyncd.conf' | grep -q 'exclude = \\*'"
check "the web server admits the private network wherever it admits the home network" bash -c "[[ \$(grep -c '<IfDefine OFFLINE_LAN>' '$ROOT/config/apache-offline-portal-common.conf' '$ROOT/config/apache-offline-portal.conf' | awk -F: '{n += \$2} END {print n}') -eq \$(grep -c 'Require ip 10.47.0.0/16' '$ROOT/config/apache-offline-portal-common.conf' '$ROOT/config/apache-offline-portal.conf' | awk -F: '{n += \$2} END {print n}') ]]"
check "packet radio is off unless switched on" bash -c "grep -q '^MESH_PACKET=no' '$ROOT/config/offline.conf' && grep -q \"'MESH_PACKET' => 'no'\" '$ROOT/lib/core.php'"
check "the radio defaults are the licence-exempt 25 mW and one per cent" bash -c "grep -q '^MESH_LORA_TXPOWER=14' '$ROOT/config/offline.conf' && grep -q '^MESH_LORA_AIRTIME_PERCENT=1' '$ROOT/config/offline.conf'"
check "the radio layer runs as the unprivileged account, with serial devices and nothing else from /dev" bash -c "grep -q '^User=offline$' '$ROOT/systemd/offline-mesh.service' && grep -q '^DevicePolicy=closed$' '$ROOT/systemd/offline-mesh.service' && grep -q '^DeviceAllow=char-ttyACM rw$' '$ROOT/systemd/offline-mesh.service'"
refute "the radio layer cannot reach a public address" grep -Eq '^IPAddressAllow=.*(any|0\.0\.0\.0/0)' "$ROOT/systemd/offline-mesh.service"
if have python3; then
  check "the broadcast bridge is valid Python" python3 -c 'import ast, sys; ast.parse(open(sys.argv[1]).read())' "$ROOT/mesh/offline-mesh-bridge"
  check "the bridge accepts only what could be a broadcast, however it is packed" python3 -c '
import importlib.machinery, importlib.util, json, sys, zlib
loader = importlib.machinery.SourceFileLoader("bridge", sys.argv[1])
bridge = importlib.util.module_from_spec(importlib.util.spec_from_loader("bridge", loader)); loader.exec_module(bridge)
good = json.dumps({"v": 1, "id": "20261121T103215Z-ea924c63", "text": "Kettle is on " * 20}).encode()
assert bridge.unpack(bridge.pack(good)) == ("20261121T103215Z-ea924c63", good)
assert bridge.pack(good)[:1] == bridge.PACKED and bridge.unpack(bridge.RAW + good)[1] == good
for bad in [b"", b"\x01", b"\x03" + good, bridge.RAW + b"not json", bridge.RAW + json.dumps({"id": "../../etc/passwd"}).encode(), bridge.RAW + json.dumps(["20261121T103215Z-ea924c63"]).encode(),
            bridge.RAW + json.dumps({"id": 7}).encode(), bridge.PACKED + b"not zlib", bridge.PACKED + zlib.compress(b"x" * 1000000), bridge.RAW + b"{" + b" " * 5000 + b"}"]:
    assert bridge.unpack(bad) is None, bad[:20]
assert bridge.derived("ABCDE", "purpose").hex() == sys.argv[2]
' "$ROOT/mesh/offline-mesh-bridge" "$(php -r 'echo hash_hmac("sha256", "purpose", "ABCDE");')"
else
  note "python3 not installed: the broadcast bridge not checked"
fi

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

# Family worlds and the routing engine: confined like the applications, and more.
for unit in "$ROOT"/games/*.container "$ROOT/config/offline-routing.container"; do
  name="$(basename "$unit" .container)"
  check "$name is pinned by image digest" grep -Eq '^Image=[a-z0-9./-]+:[A-Za-z0-9._-]+@sha256:[0-9a-f]{64}$' "$unit"
  check "$name cannot gain privileges" grep -q '^NoNewPrivileges=true$' "$unit"
  check "$name holds no capabilities" grep -q '^DropCapability=all$' "$unit"
  check "$name sees a read-only system" grep -q '^ReadOnly=true$' "$unit"
  check "$name runs in its own user namespace" grep -Eq '^PodmanArgs=.*--uidmap=0:[1-9][0-9]{6}:65536 --gidmap=0:[1-9][0-9]{6}:65536' "$unit"
  check "$name never pulls an image by itself" grep -q -- '--pull=never' "$unit"
  check "$name has a memory limit" grep -Eq -- '--memory=[0-9]+[mg] ' "$unit"
done
check "the routing engine listens on this system only" grep -q '^PublishPort=127\.0\.0\.1:18700:8002$' "$ROOT/config/offline-routing.container"
check "the routing engine reads its graph read-only" grep -q '^Volume=/srv/offline/maps/routing:/data:ro$' "$ROOT/config/offline-routing.container"
for unit in "$ROOT"/games/*.container; do
  check "$(basename "$unit" .container) answers private addresses only, whatever the firewall says" bash -c "grep -q '^IPAddressDeny=any$' '$unit' && grep -q '^IPAddressAllow=localhost 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 169.254.0.0/16$' '$unit'"
done
check "a family world mounts its game read-only" grep -q 'games/mineclonia:ro$' "$ROOT/games/offline-game-creative.container"
check "no two containers share a user range" bash -c "[[ \$( { grep -oh 'uidmap=0:[0-9]*' '$ROOT'/apps/*.container '$ROOT'/games/*.container '$ROOT/config/offline-routing.container' | cut -d: -f2; grep -oh 'ZIMIT_UID_BASE = [0-9]*' '$ROOT/lib/server.php' | grep -o '[0-9]\+\$'; } | sort | uniq -d | wc -l) -eq 0 ]]"
check "the routing graph is built in the range that serves it" bash -c "grep -q \"uidmap=0:\$(grep -oh 'ROUTING_UID_BASE = [0-9]*' '$ROOT/lib/maps.php' | grep -o '[0-9]\+\$'):65536\" '$ROOT/config/offline-routing.container'"
check "the creative world cannot hurt anyone" bash -c "grep -q '^enable_damage = false' '$ROOT/games/creative.conf' && grep -q '^enable_pvp = false' '$ROOT/games/creative.conf'"
check "the survival world has no monsters and no fighting" bash -c "grep -q '^only_peaceful_mobs = true' '$ROOT/games/survival.conf' && grep -q '^enable_pvp = false' '$ROOT/games/survival.conf'"
for world in creative survival; do
  check "the $world world is never announced publicly" grep -q '^server_announce = false' "$ROOT/games/$world.conf"
  check "the $world world requires a password" grep -q '^disallow_empty_password = true' "$ROOT/games/$world.conf"
  check "the $world world keeps mods sandboxed" grep -q '^secure.enable_security = true' "$ROOT/games/$world.conf"
done
if [[ -x /usr/libexec/podman/quadlet ]]; then
  QUADLET_GAMES="$(mktemp -d "$WORK/quadlet-games.XXXXXX")"
  cp "$ROOT"/games/*.container "$ROOT/config/offline-routing.container" "$QUADLET_GAMES/"
  refute "Podman accepts the world and routing units" bash -c "QUADLET_UNIT_DIRS='$QUADLET_GAMES' /usr/libexec/podman/quadlet -dryrun 2>&1 | grep -Eiq 'unsupported|converting|error'"
fi
check "directions pass through the portal only when switched on" grep -q '<IfDefine OFFLINE_ROUTING>' "$ROOT/config/apache-offline-portal-common.conf"
check "the portal passes on route requests and nothing else" bash -c "[[ \$(grep -c 'ProxyPass ' '$ROOT/config/apache-offline-portal-common.conf') -eq 1 ]] && grep -q 'ProxyPass /valhalla/route http://127.0.0.1:18700/route' '$ROOT/config/apache-offline-portal-common.conf'"
check "the vault module is read-only like the rest" bash -c "sed -n '/^\\[vault\\]/,/^\\[broadcasts\\]/p' '$ROOT/config/rsyncd.conf' | grep -vq 'read only = no'"
check "the installer installs the PHP text functions the commands use" grep -q 'php-mbstring' "$ROOT/install.sh"
# Every package the installer asks apt for must be in the list that fills the package cache.
MISSING_PACKAGES="$(grep -E '^[[:space:]]*(must|step) .*apt_install |^[[:space:]]+apt_install ' "$ROOT/install.sh" | sed 's/.*apt_install //' | tr ' ' '\n' |
  grep -E '^[a-z0-9][a-z0-9.+-]+$' | sort -u | while read -r package; do grep -qx "$package" "$ROOT/config/packages.list" || echo "$package"; done)"
check "the package cache list covers what the installer installs" test -z "$MISSING_PACKAGES"
for package in $(cut -f2 "$ROOT/games/games.tsv" | grep -v '^#' | tr ',' ' '); do
  check "the package cache list covers the game package $package" grep -qx "$package" "$ROOT/config/packages.list"
done
if have shellcheck; then
  check "the package cache detector passes shellcheck" shellcheck "$ROOT/config/apt-proxy-detect"
fi
check "with no cache listed, apt is told to go direct" bash -c "[[ \$(bash '$ROOT/config/apt-proxy-detect') == DIRECT || -r /etc/offline/apt-cache.hosts ]]"

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
    "$1"/srv/offline/vault "$1"/usr/games "$1"/run/offline-auth "$1"/etc/offline/tls \
    "$1"/srv/offline/maps "$1"/srv/offline/radio "$1"/srv/offline/apps "$1"/sys/class/power_supply/BAT0 \
    "$1"/sys/bus/usb/devices/1-1 "$1"/sys/bus/usb/devices/1-2
  cp "$ROOT/config/catalogue.tsv" "$ROOT/config/maps.tsv" "$ROOT/config/sites.tsv" "$1/usr/local/lib/offline/"
  cp -r "$ROOT/apps" "$ROOT/data" "$ROOT/games" "$1/usr/local/lib/offline/"
  cp "$ROOT/config/packages.list" "$ROOT/config/offline-routing.container" "$ROOT/config/children.tsv" "$1/usr/local/lib/offline/"
  echo 0bda >"$1/sys/bus/usb/devices/1-1/idVendor"; echo 2838 >"$1/sys/bus/usb/devices/1-1/idProduct"
  echo 10c4 >"$1/sys/bus/usb/devices/1-2/idVendor"; echo ea60 >"$1/sys/bus/usb/devices/1-2/idProduct"
  cp "$ROOT/config/offline.conf" "$1/etc/offline/"
  # The scratch machines have a mesh node "plugged in"; keep the real radio command out of most checks.
  # The free-space floors are switched off, so that results do not depend on how full this machine is;
  # the rule itself has its own check further down.
  sed -i 's/^MESH_GATEWAY=.*/MESH_GATEWAY=no/; s/^SYNC_MIN_FREE_GB=.*/SYNC_MIN_FREE_GB=0/; s/^DOWNLOAD_MIN_FREE_GB=.*/DOWNLOAD_MIN_FREE_GB=0/' "$1/etc/offline/offline.conf"
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

echo
echo "People"
check "with no accounts the command says what that means" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' user | grep -q 'nobody can sign in for the rest'"
check "an adult's account can be made" bash -c "printf 'correct horse battery\n' | OFFLINE_PREFIX='$A' '$ROOT/bin/offline' user add peter --adult --full 'Peter Example' | grep -q 'Account peter created (adult)'"
check "a child's account can be made" bash -c "printf 'tigers7\n' | OFFLINE_PREFIX='$A' '$ROOT/bin/offline' user add twin-one --child | grep -q 'the children.s pages need no sign-in'"
ACCOUNTS="$A/var/lib/offline/accounts/accounts.json"
check "the records are closed to other accounts" bash -c "[[ \$(stat -c %a '$ACCOUNTS') == 640 ]]"
refute "no password is stored, only hashes" grep -q -e 'correct horse' -e tigers7 "$ACCOUNTS"
# shellcheck disable=SC2016
check "an adult has an Argon2id hash for the portal and a login hash; a child has no portal hash" php -r '$a = json_decode(file_get_contents($argv[1]), true)["accounts"]; exit(str_starts_with($a["peter"]["portal"], "\$argon2id\$v=19\$m=65536,t=3,p=1\$") && str_starts_with($a["peter"]["login"], "\$6\$rounds=200000\$") && $a["twin-one"]["portal"] === null && str_starts_with($a["twin-one"]["login"], "\$6\$") ? 0 : 1);' "$ACCOUNTS"
refute "the same name cannot be made twice" bash -c "printf 'another long password\n' | OFFLINE_PREFIX='$A' '$ROOT/bin/offline' user add peter --adult"
refute "an account must be one kind or the other" bash -c "printf 'another long password\n' | OFFLINE_PREFIX='$A' '$ROOT/bin/offline' user add both --adult --child"
refute "a child cannot be an administrator" bash -c "printf 'tigers7\n' | OFFLINE_PREFIX='$A' '$ROOT/bin/offline' user add twin-two --child --admin"
refute "a short password is refused for an adult" bash -c "printf 'short\n' | OFFLINE_PREFIX='$A' '$ROOT/bin/offline' user add weak --adult"
refute "a password containing the account name is refused" bash -c "printf 'isabella-isabella\n' | OFFLINE_PREFIX='$A' '$ROOT/bin/offline' user add isabella --adult"
refute "a password is never taken from the command line" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' user add argv --adult 'a long enough password' </dev/null"
for bad in root offline www-data Peter 'a b' 'x;id' ../x a; do
  refute "the account name \"$bad\" is refused" bash -c "printf 'a long enough password\n' | OFFLINE_PREFIX='$A' '$ROOT/bin/offline' user add '$bad' --adult"
done
refute "a full name with markup is refused" bash -c "printf 'a long enough password\n' | OFFLINE_PREFIX='$A' '$ROOT/bin/offline' user add markup --adult --full '<b>x</b>'"
refute "a password file others can read is refused" bash -c "echo 'a long enough password' >'$WORK/loose.pw'; chmod 644 '$WORK/loose.pw'; OFFLINE_PREFIX='$A' '$ROOT/bin/offline' user add filed --adult --password-file '$WORK/loose.pw'"

# Signing in, as the sign-in pages do it.
auth() { php -r 'define("OFFLINE_PREFIX", $argv[1]); require $argv[2] . "/lib/core.php"; require $argv[2] . "/lib/auth.php"; eval($argv[3]);' "$A" "$ROOT" "$1"; }
check "the right password opens a session" auth '$r = Offline\sign_in("peter", "correct horse battery", "192.168.7.20"); exit($r["ok"] && preg_match("/^[A-Za-z0-9_-]{43}$/", $r["token"]) === 1 ? 0 : 1);'
check "the session file holds what the web server looks for" grep -Eq '^[A-Za-z0-9_-]{43} adult:peter:[0-9]{10}$' "$A/run/offline-auth/sessions.map"
check "the session file is closed to other accounts" bash -c "[[ \$(stat -c %a '$A/run/offline-auth/sessions.map') == 640 ]]"
TOKEN="$(cut -d' ' -f1 "$A/run/offline-auth/sessions.map" | head -n 1)"
check "the pages recognise the session" auth "\$s = Offline\\session_lookup('$TOKEN'); exit(\$s !== null && \$s['user'] === 'peter' ? 0 : 1);"
check "a made-up token is nobody" auth 'exit(Offline\session_lookup(str_repeat("A", 43)) === null && Offline\session_lookup("../../etc/passwd") === null && Offline\session_lookup(null) === null ? 0 : 1);'
check "the name is not case-sensitive, the password is" auth 'exit(Offline\sign_in("Peter", "correct horse battery", "192.168.7.21")["ok"] && !Offline\sign_in("peter", "Correct horse battery", "192.168.7.21")["ok"] ? 0 : 1);'
check "a child's account cannot sign in for more" auth 'exit(Offline\sign_in("twin-one", "tigers7", "192.168.7.22")["ok"] ? 1 : 0);'
check "an unknown name is refused in the same way as a wrong password" auth '$a = Offline\sign_in("nobody-here", "x", "192.168.7.23"); $b = Offline\sign_in("peter", "x", "192.168.7.24"); exit($a === $b && $a === ["ok" => false, "wait" => 0] ? 0 : 1);'
check "after five wrong guesses the right password is not heard either" auth 'for ($i = 0; $i < 5; $i++) { Offline\sign_in("peter", "wrong guess $i", "192.168.7.30"); } $r = Offline\sign_in("peter", "correct horse battery", "192.168.7.30"); exit(!$r["ok"] && $r["wait"] > 800 ? 0 : 1);'
check "another address is not held up by that" auth 'exit(Offline\sign_in("peter", "correct horse battery", "192.168.7.31")["ok"] ? 0 : 1);'
check "one address guessing at many names is held too" auth 'for ($i = 0; $i < 20; $i++) { Offline\sign_in("name$i", "wrong", "192.168.7.40"); } exit(Offline\sign_in("peter", "correct horse battery", "192.168.7.40")["wait"] > 0 ? 0 : 1);'
refute "the guessing record holds no names or addresses" grep -q -e peter -e 192.168 "$A/run/offline-auth/throttle.json"
check "an over-long password is refused without being hashed" auth '$t = microtime(true); $r = Offline\sign_in("peter", str_repeat("x", 100000), "192.168.7.50"); exit(!$r["ok"] && microtime(true) - $t < 0.1 ? 0 : 1);'
check "a new password ends the account's sessions" bash -c "printf 'a different long password\n' | OFFLINE_PREFIX='$A' '$ROOT/bin/offline' user passwd peter | grep -Eq 'and [0-9]+ portal sessions? ended' && [[ ! -s '$A/run/offline-auth/sessions.map' ]]"
check "the old password no longer works and the new one does" auth 'exit(!Offline\sign_in("peter", "correct horse battery", "192.168.7.60")["ok"] && Offline\sign_in("peter", "a different long password", "192.168.7.61")["ok"] ? 0 : 1);'
# A session from before a change made on another system: the minute-by-minute check ends it.
sed -i -E 's/"updated": "[^"]+"/"updated": "2099-01-01T00:00:00Z"/' "$ACCOUNTS"
check "a record dated in the future is not believed" auth 'exit(Offline\accounts_load() === [] ? 0 : 1);'
sed -i -E "s/\"updated\": \"[^\"]+\"/\"updated\": \"$(date -u -d '+30 seconds' +%Y-%m-%dT%H:%M:%SZ)\"/" "$ACCOUNTS"
check "a session older than its account's last change is ended" bash -c "[[ -s '$A/run/offline-auth/sessions.map' ]] && OFFLINE_PREFIX='$A' '$ROOT/bin/offline' user prune-sessions && [[ ! -s '$A/run/offline-auth/sessions.map' ]]"
sed -i -E "s/\"updated\": \"[^\"]+\"/\"updated\": \"$(date -u -d '-1 hour' +%Y-%m-%dT%H:%M:%SZ)\"/" "$ACCOUNTS"
echo "$(printf 'B%.0s' {1..43}) adult:peter:1000000000" >"$A/run/offline-auth/sessions.map"
check "a session that has run out is nobody, and is cleared away" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' user prune-sessions && [[ ! -s '$A/run/offline-auth/sessions.map' ]]"
check "a locked account cannot sign in" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' user lock peter >/dev/null && ! php -r 'define(\"OFFLINE_PREFIX\", \$argv[1]); require \$argv[2] . \"/lib/core.php\"; require \$argv[2] . \"/lib/auth.php\"; exit(Offline\\sign_in(\"peter\", \"a different long password\", \"192.168.7.70\")[\"ok\"] ? 0 : 1);' '$A' '$ROOT'"
check "and can again once unlocked" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' user unlock peter >/dev/null && php -r 'define(\"OFFLINE_PREFIX\", \$argv[1]); require \$argv[2] . \"/lib/core.php\"; require \$argv[2] . \"/lib/auth.php\"; exit(Offline\\sign_in(\"peter\", \"a different long password\", \"192.168.7.71\")[\"ok\"] ? 0 : 1);' '$A' '$ROOT'"
# shellcheck disable=SC2016
check "records from another system are taken only if newer and exactly the right shape" php -r 'require $argv[1] . "/lib/core.php"; require $argv[1] . "/lib/auth.php";
  $ok = ["class" => "adult", "full" => "A", "portal" => null, "login" => null, "locked" => false, "removed" => false, "updated" => "2026-01-02T00:00:00Z"];
  $mine = ["anna" => $ok, "ben" => $ok];
  $theirs = [
    "anna" => ["updated" => "2026-01-01T00:00:00Z", "full" => "Older"] + $ok,
    "ben" => ["updated" => "2026-01-03T00:00:00Z", "full" => "Newer"] + $ok,
    "carl" => $ok,
    "root" => $ok, "Dora" => $ok, "x;y" => $ok,
    "extra" => $ok + ["sudo" => true],
    "future" => ["updated" => "2099-01-01T00:00:00Z"] + $ok,
    "kid" => ["class" => "child", "portal" => "\$argon2id\$v=19\$m=65536,t=3,p=1\$a1BXWEI3QkFBbUlYc3VwUQ\$o9N9s0iypp1szY4he7O5gOiVoSgkymqjBOln20kCMys"] + $ok,
    "weak" => ["portal" => "\$1\$abc\$def"] + $ok,
    "shell" => ["login" => "\$6\$rounds=200000\$abcdefghijklmnop\$x\nroot:x"] + $ok,
    "gecos" => ["full" => "a:b,c"] + $ok,
  ];
  [$merged, $changed] = Offline\accounts_merge($mine, $theirs);
  exit($changed === ["ben", "carl"] && $merged["anna"]["full"] === "A" && $merged["ben"]["full"] === "Newer" && array_keys($merged) === ["anna", "ben", "carl"] ? 0 : 1);' "$ROOT"
check "removing an account keeps only a marker, without its hashes" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' user remove twin-one >/dev/null && php -r '\$r = json_decode(file_get_contents(\$argv[1]), true)[\"accounts\"][\"twin-one\"]; exit(\$r[\"removed\"] === true && \$r[\"portal\"] === null && \$r[\"login\"] === null ? 0 : 1);' '$ACCOUNTS' && ! OFFLINE_PREFIX='$A' '$ROOT/bin/offline' user | grep -q twin-one"
check "a removed name can be used again" bash -c "printf 'tigers7\n' | OFFLINE_PREFIX='$A' '$ROOT/bin/offline' user add twin-one --child >/dev/null"
check "an account for the portal alone has no login hash to replicate" bash -c "printf 'grandmother visits often\n' | OFFLINE_PREFIX='$A' '$ROOT/bin/offline' user add granny --adult --portal-only | grep -q 'none, on any system' && php -r 'exit(json_decode(file_get_contents(\$argv[1]), true)[\"accounts\"][\"granny\"][\"login\"] === null ? 0 : 1);' '$ACCOUNTS'"
# shellcheck disable=SC2016
check "login accounts are wanted everywhere at home, and nowhere on a server facing the internet" php -r 'define("OFFLINE_PREFIX", $argv[2]); require $argv[1] . "/lib/cli.php";
  $adult = ["class" => "adult", "login" => "x"]; $child = ["class" => "child", "login" => "x"]; $portal = ["class" => "adult", "login" => null];
  $home = Offline\login_wanted($adult) && !Offline\login_wanted($child) && !Offline\login_wanted($portal);
  mkdir(OFFLINE_PREFIX . "/usr/sbin", 0755, true); touch(OFFLINE_PREFIX . "/usr/sbin/gdm3"); chmod(OFFLINE_PREFIX . "/usr/sbin/gdm3", 0755);
  exit($home && Offline\login_wanted($child) ? 0 : 1);' "$ROOT" "$A"
sed -i 's/^SYNC_EXPOSE=.*/SYNC_EXPOSE=internet/' "$A/etc/offline/offline.conf"
# shellcheck disable=SC2016
check "a server facing the internet makes no login accounts from the records unless told to" php -r 'define("OFFLINE_PREFIX", $argv[2]); require $argv[1] . "/lib/cli.php"; exit(Offline\login_wanted(["class" => "adult", "login" => "x"]) ? 1 : 0);' "$ROOT" "$A"
sed -i 's/^SYNC_EXPOSE=.*/SYNC_EXPOSE=lan/' "$A/etc/offline/offline.conf"

echo
echo "Certificates"
if have openssl; then
  check "this system has a certificate signed by the household's authority" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' tls renew >/dev/null && openssl verify -CAfile '$A/etc/offline/tls/ca.crt' '$A/etc/offline/tls/offline.crt' >/dev/null 2>&1"
  check "its key is private" bash -c "[[ \$(stat -c %a '$A/etc/offline/tls/offline.key') == 600 ]]"
  refute "the authority's own key is left nowhere on disc" bash -c "find '$A/etc/offline/tls' -name 'ca.key' -o -name 'ca.der' -o -name '.work*' | grep -q ."
  check "the authority may sign only for names and addresses inside a home" bash -c "openssl x509 -in '$A/etc/offline/tls/ca.crt' -noout -text | grep -A3 'Name Constraints: critical' | grep -q 'DNS:.local'"
  # A second system holding the same house key arrives at the same authority by itself.
  mkdir -p "$B/etc/offline/tls"
  echo "$KEY" | OFFLINE_PREFIX="$B" "$ROOT/bin/offline" key set - >/dev/null
  OFFLINE_PREFIX="$B" "$ROOT/bin/offline" tls renew >/dev/null
  check "a device that trusts one system's authority accepts another system's certificate" bash -c "openssl verify -CAfile '$A/etc/offline/tls/ca.crt' '$B/etc/offline/tls/offline.crt' >/dev/null 2>&1"
  T="$WORK/t"
  make_root "$T"
  echo 'ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ' | OFFLINE_PREFIX="$T" "$ROOT/bin/offline" key set - >/dev/null
  OFFLINE_PREFIX="$T" "$ROOT/bin/offline" tls renew >/dev/null
  refute "a household with another key is another authority" bash -c "openssl verify -CAfile '$A/etc/offline/tls/ca.crt' '$T/etc/offline/tls/offline.crt' >/dev/null 2>&1"
  # Someone who had the house key could sign what they liked; devices must still refuse it for a public name.
  # shellcheck disable=SC2016
  php -r 'define("OFFLINE_PREFIX", $argv[2]); require $argv[1] . "/lib/cli.php"; file_put_contents($argv[3] . "/rogue-ca.der", Offline\household_ca_der());' "$ROOT" "$A" "$WORK"
  openssl ec -inform DER -in "$WORK/rogue-ca.der" -out "$WORK/rogue-ca.key" 2>/dev/null
  openssl req -new -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -keyout "$WORK/rogue.key" -subj '/CN=www.example.com' -out "$WORK/rogue.csr" 2>/dev/null
  printf 'subjectAltName=DNS:www.example.com\nextendedKeyUsage=serverAuth\n' >"$WORK/rogue.ext"
  openssl x509 -req -in "$WORK/rogue.csr" -CA "$A/etc/offline/tls/ca.crt" -CAkey "$WORK/rogue-ca.key" -set_serial 7 -days 30 -extfile "$WORK/rogue.ext" -out "$WORK/rogue.crt" 2>/dev/null
  check "the test could sign a certificate for a public name with the authority's key" test -s "$WORK/rogue.crt"
  refute "and such a certificate is refused, because of the limit written into the authority" bash -c "openssl verify -CAfile '$A/etc/offline/tls/ca.crt' '$WORK/rogue.crt' >/dev/null 2>&1"
  check "a certificate already in order is left as it is" bash -c "before=\$(sha256sum '$A/etc/offline/tls/offline.crt'); OFFLINE_PREFIX='$A' '$ROOT/bin/offline' tls renew >/dev/null; [[ \"\$before\" == \"\$(sha256sum '$A/etc/offline/tls/offline.crt')\" ]]"
  check "the command reports the authority's fingerprint to check on a device" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' tls | grep -Eq 'Fingerprint +([0-9A-F]{2}:){31}[0-9A-F]{2}'"
else
  note "openssl not installed: certificates not exercised"
fi

echo
echo "The children's shelf"
# shellcheck disable=SC2016
check "a title is on the shelf only if it is listed" php -r 'define("OFFLINE_PREFIX", $argv[2]); require $argv[1] . "/lib/core.php"; file_put_contents(OFFLINE_PREFIX . "/etc/offline/children.local.tsv", "# ours\nstorybook_en_all\tOur own\n../../etc\tbad\n");
  exit(Offline\is_children_title("vikidia_en_all_maxi") && Offline\is_children_title("vikidia_en_all") && Offline\is_children_title("storybook_en_all_maxi")
    && !Offline\is_children_title("vikidia_en_allsorts") && !Offline\is_children_title("wikipedia_en_all_maxi") && !Offline\is_children_title("wikipedia_en_medicine_maxi")
    && !Offline\is_children_title("wikipedia_en-simple_all_maxi") && !isset(Offline\children_titles()["../../etc"]) ? 0 : 1);' "$ROOT" "$A"
# shellcheck disable=SC2016
check "only the children's folder of the media counts as theirs" php -r 'require $argv[1] . "/lib/core.php"; exit(Offline\is_children_media("children") && Offline\is_children_media("children/films") && !Offline\is_children_media("childrens") && !Offline\is_children_media("movies") && !Offline\is_children_media("movies/children") && !Offline\is_children_media("") ? 0 : 1);' "$ROOT"
check "the catalogue has a children's pack, all of it on the shelf" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' catalogue children | grep -q Vikidia && for base in \$(awk -F'\t' '\$1 ~ /^children/ {print \$2}' '$ROOT/config/catalogue.tsv'); do php -r 'require \$argv[1] . \"/lib/core.php\"; exit(Offline\\is_children_title(\$argv[2]) ? 0 : 1);' '$ROOT' \"\$base\" || exit 1; done"

echo
echo "Private network"
if php -r 'exit(function_exists("sodium_crypto_box_keypair") ? 0 : 1);'; then
  check "before joining, the command says how" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' net | grep -q 'sudo offline net join'"
  check "a system can join" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' net join | grep -Eq 'on the private network as 10\.47\.[0-9]+\.[0-9]+\.'"
  check "its private key is private" bash -c "[[ \$(stat -c %a '$A/etc/offline/net/private.key') == 600 ]]"
  refute "the list of systems holds no private key" grep -q "$(cat "$A/etc/offline/net/private.key")" "$A/var/lib/offline/network/nodes.json"
  check "joining again keeps the same key and address" bash -c "before=\$(cat '$A/etc/offline/net/public.key'); OFFLINE_PREFIX='$A' '$ROOT/bin/offline' net join >/dev/null; [[ \"\$before\" == \"\$(cat '$A/etc/offline/net/public.key')\" ]]"
  if have wg; then
    check "WireGuard derives the same public key from the private one" bash -c "[[ \"\$(wg pubkey <'$A/etc/offline/net/private.key')\" == \"\$(cat '$A/etc/offline/net/public.key')\" ]]"
  fi
  check "a system that can be called says where" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' net endpoint files.example.org:51820 >/dev/null && grep -q '^ListenPort = 51820' '$A/etc/wireguard/offline0.conf'"
  for bad in 'files.example.org' '-oProxyCommand=x:22' 'host:0' 'host:99999' 'a b:51820' 'host:51820;id' '/etc/passwd:1'; do
    refute "the endpoint \"$bad\" is refused" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' net endpoint '$bad'"
  done
  # shellcheck disable=SC2016
  check "a record is believed only if its address follows from its key and nothing in it could be an option or a command" php -r 'define("OFFLINE_PREFIX", $argv[2]); require $argv[1] . "/lib/cli.php";
    $key = base64_encode(random_bytes(32)); $other = base64_encode(random_bytes(32));
    $ok = ["name" => "nuc-attic", "role" => "repeater", "address" => Offline\net_address($key), "endpoint" => "home.example.org:51821", "removed" => false, "updated" => Offline\utc_now()];
    $bad = [
      ["address" => Offline\net_address($other)] + $ok, ["address" => "10.47.0.1"] + $ok, ["address" => "192.168.1.1"] + $ok,
      ["endpoint" => "host:22\nPostUp = id"] + $ok, ["endpoint" => "-x:1"] + $ok, ["name" => "a b"] + $ok, ["name" => "x\n[Peer]"] + $ok,
      ["role" => "root"] + $ok, ["updated" => "2099-01-01T00:00:00Z"] + $ok, $ok + ["postup" => "id"], ["removed" => "no"] + $ok,
    ];
    foreach ($bad as $record) { if (Offline\net_record_ok($record, $key)) { fwrite(STDERR, json_encode($record)); exit(1); } }
    exit(Offline\net_record_ok($ok, $key) && !Offline\net_record_ok($ok, "not-a-key") && Offline\net_address("short") === null ? 0 : 1);' "$ROOT" "$A"
  # Two more systems, as a sync would bring them: a repeater that can be called and a laptop that cannot.
  # shellcheck disable=SC2016
  php -r 'define("OFFLINE_PREFIX", $argv[2]); require $argv[1] . "/lib/cli.php"; $n = Offline\net_nodes();
    foreach ([["nuc-attic", "repeater", "home.example.org:51821"], ["laptop-two", "workstation", null]] as [$name, $role, $endpoint]) { $k = base64_encode(random_bytes(32));
      $n[$k] = ["name" => $name, "role" => $role, "address" => Offline\net_address($k), "endpoint" => $endpoint, "removed" => false, "updated" => Offline\utc_now()]; }
    Offline\net_nodes_save($n);' "$ROOT" "$A"
  check "the tunnel's configuration follows the list" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' net apply && [[ \$(grep -c '^\[Peer\]' '$A/etc/wireguard/offline0.conf') -eq 2 ]]"
  WGCONF="$A/etc/wireguard/offline0.conf"
  check "the configuration is closed to other accounts" bash -c "[[ \$(stat -c %a '$WGCONF') == 600 ]]"
  check "every peer needs the key derived from the house key, and is allowed its one address" bash -c "[[ \$(grep -c '^PresharedKey = ' '$WGCONF') -eq 2 && \$(grep -Ec '^AllowedIPs = 10\.47\.[0-9]+\.[0-9]+/32$' '$WGCONF') -eq 2 ]]"
  refute "no peer is allowed a wider range" grep -Eq '^AllowedIPs = .*(/0|/8|/16|,)' "$WGCONF"
  check "only a system that can be called is called" bash -c "[[ \$(grep -c '^Endpoint = ' '$WGCONF') -eq 1 ]] && grep -q '^Endpoint = home.example.org:51821' '$WGCONF'"
  refute "the configuration runs no commands" grep -Eiq '^(PostUp|PostDown|PreUp|PreDown|SaveConfig|DNS|Table)' "$WGCONF"
  if have wg-quick && ((EUID == 0)); then
    check "WireGuard accepts the configuration" bash -c "cp '$WGCONF' '$WORK/offline0.conf' && chmod 600 '$WORK/offline0.conf' && wg-quick strip '$WORK/offline0.conf' >/dev/null 2>&1"
  else
    note "wireguard-tools not installed, or not root: the tunnel's configuration not parsed by WireGuard"
  fi
  check "the report lists the other systems" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' net | grep -Eq 'nuc-attic +repeater +10\.47\.' && OFFLINE_PREFIX='$A' '$ROOT/bin/offline' status --json | php -r 'exit(json_decode(stream_get_contents(STDIN), true)[\"net\"][\"joined\"] === true ? 0 : 1);'"
  check "a lost system can be dropped" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' net forget laptop-two | grep -q 'change the house key as well' && [[ \$(grep -c '^\[Peer\]' '$WGCONF') -eq 1 ]]"
  refute "a system cannot forget itself that way" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' net forget \"\$(hostname | tr 'A-Z' 'a-z' | tr -cd 'a-z0-9-')\""

  # The radio layer's configuration.
  check "the radio layer can be switched on" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' net radio on | grep -q 'The radio layer is on'"
  RNS="$A/var/lib/offline/reticulum/config"
  check "every interface carries the household's access code" bash -c "[[ \$(grep -c '^  \[\[' '$RNS') -ge 2 && \$(grep -c '^  \[\[' '$RNS') -eq \$(grep -c '^    passphrase = [0-9a-f]\{64\}$' '$RNS') ]]"
  refute "the access code is not the house key" grep -q "$KEY" "$RNS"
  check "a workstation speaks for itself and does not carry others' traffic" grep -q '^  enable_transport = No' "$RNS"
  check "it calls the systems that can be called, through the tunnel" bash -c "grep -A3 'Private network, to nuc-attic' '$RNS' | grep -Eq 'target_host = 10\.47\.'"
  refute "packet radio is not configured unless switched on" grep -q 'KISSInterface' "$RNS"
  mkdir -p "$A/dev/serial/by-id" && touch "$A/dev/serial/by-id/usb-unsigned.io_RNode_1234-if00" "$A/dev/serial/by-id/usb-Some_GPS-if00"
  sed -i 's/^ROLE=.*/ROLE=repeater/' "$A/etc/offline/offline.conf"
  echo 'MESH_PACKET=yes' >>"$A/etc/offline/offline.conf"; echo 'MESH_PACKET_PORT="/dev/ttyUSB0; reboot"' >>"$A/etc/offline/offline.conf"
  OFFLINE_PREFIX="$A" "$ROOT/bin/offline" net radio on >/dev/null
  check "a repeater carries traffic for others" grep -q '^  enable_transport = Yes' "$RNS"
  check "a LoRa radio is found by name and held to its airtime limit" bash -c "grep -q 'port = /dev/serial/by-id/usb-unsigned.io_RNode_1234-if00' '$RNS' && grep -q 'airtime_limit_long = 1$' '$RNS' && grep -q 'txpower = 14$' '$RNS'"
  refute "another serial device is not taken for a radio" grep -q 'Some_GPS' "$RNS"
  refute "a packet radio port that is not a device name is ignored" grep -q -e reboot -e KISSInterface "$RNS"
  sed -i 's#^MESH_PACKET_PORT=.*#MESH_PACKET_PORT="/dev/ttyUSB0"#' "$A/etc/offline/offline.conf"
  check "packet radio is configured once switched on with a real device name" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' net radio on >/dev/null && grep -q 'type = KISSInterface' '$RNS'"
  sed -i 's/^SYNC_EXPOSE=.*/SYNC_EXPOSE=internet/' "$A/etc/offline/offline.conf"
  check "on a datacentre's network the radio layer does not look for neighbours" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' net radio on >/dev/null && ! grep -q AutoInterface '$RNS'"
  sed -i 's/^SYNC_EXPOSE=.*/SYNC_EXPOSE=lan/; s/^ROLE=.*/ROLE=workstation/; /^MESH_PACKET/d' "$A/etc/offline/offline.conf"
  check "the radio layer can be switched off" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' net radio off >/dev/null && [[ ! -e '$RNS' ]]"
else
  note "PHP's sodium extension is missing: the private network not exercised"
fi

echo
echo "Games"
check "the games are listed" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' games | grep -q 'SuperTuxKart'"
touch "$A/usr/games/gcompris-qt" && chmod 755 "$A/usr/games/gcompris-qt"
check "an installed game is recognised" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' games | grep -Eq 'GCompris +2 to 10 +installed'"
printf '#!/bin/sh\necho "Minetest 5.6.1 (Linux)"\n' >"$A/usr/games/minetest" && chmod 755 "$A/usr/games/minetest"
check "a Luanti too old for the game is pointed out" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' games | grep -q 'Luanti 5.6.1 is installed here, and Mineclonia .* is written for 5.10 or newer'"
check "the health check says so too" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' doctor | grep -q 'Luanti 5.6.1 is installed here'"
printf '#!/bin/sh\necho "Luanti 5.10.0 (Linux)"\n' >"$A/usr/games/luanti" && chmod 755 "$A/usr/games/luanti"
refute "a new enough Luanti draws no remark" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' games | grep -q 'is installed here'"
# Stand in for the pinned fetches, so that the suite needs no network.
mkdir -p "$A/usr/local/share/offline/luanti/games/mineclonia" "$A/usr/local/share/offline/sugarizer/js"
printf 'title = Mineclonia\nmin_minetest_version = 5.12\n' >"$A/usr/local/share/offline/luanti/games/mineclonia/game.conf"
check "what the game itself asks for is what counts" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' games | grep -q 'Luanti 5.10.0 is installed here, and Mineclonia .* is written for 5.12 or newer'"
echo 'title = Mineclonia' >"$A/usr/local/share/offline/luanti/games/mineclonia/game.conf"
check "a family world can be hosted" offline games host creative
check "the world's unit starts at boot" grep -q '^WantedBy=multi-user.target' "$A/etc/containers/systemd/offline-game-creative.container"
check "the world's settings are installed" grep -q '^creative_mode = true' "$A/etc/offline/games/creative.conf"
echo '# my own change' >>"$A/etc/offline/games/creative.conf"
check "hosting again keeps edited settings" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' games host creative >/dev/null && grep -q 'my own change' '$A/etc/offline/games/creative.conf' && test -f '$A/etc/offline/games/creative.conf.dist'"
check "the hosted world is reported" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' status --json | php -r '\$s = json_decode(stream_get_contents(STDIN), true); exit(\$s[\"games\"][\"worlds\"][0][\"hosted\"] === true && \$s[\"games\"][\"mineclonia\"] !== null ? 0 : 1);'"
refute "an unknown world is refused" offline games host nether
refute "an unknown game is refused" offline games install doom
check "a world can be withdrawn, keeping what was built" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' games unhost creative | grep -q 'kept in' && [[ ! -e '$A/etc/containers/systemd/offline-game-creative.container' && -d '$A/srv/offline/apps/luanti/creative' ]]"
SUGARIZER_COMMIT="$(sed -n "s/^const SUGARIZER_COMMIT = '\([0-9a-f]\{40\}\)';$/\1/p" "$ROOT/lib/games.php")"
echo "$SUGARIZER_COMMIT" >"$A/usr/local/share/offline/sugarizer/.offline-commit"
echo '<html></html>' >"$A/usr/local/share/offline/sugarizer/index.html"
check "the browser activities get a site of their own" offline games activities
check "the activities site answers GET and HEAD only" grep -q 'AllowMethods GET HEAD' "$A/etc/apache2/sites-available/offline-activities.conf"
check "the activities site may load nothing from elsewhere" grep -q "default-src 'self' 'unsafe-inline' 'unsafe-eval' data: blob:; connect-src 'self'" "$A/etc/apache2/sites-available/offline-activities.conf"
check "the activities site follows the home-network rule" grep -q 'Require ip 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16' "$A/etc/apache2/sites-available/offline-activities.conf"
check "pinned sources are full commit hashes" bash -c "[[ \$(grep -cE \"^const (MINECLONIA|SUGARIZER)_COMMIT = '[0-9a-f]{40}';\" '$ROOT/lib/games.php') -eq 2 ]]"

echo
echo "Hotspot and time"
check "the hotspot reports that it is not set up" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' hotspot | grep -q 'not set up'"
check "the hotspot can be set up" offline hotspot on --interface wlp2s0 --ssid 'Family Offline'
HOTSPOT="$A/etc/NetworkManager/system-connections/offline-hotspot.nmconnection"
check "its profile is private" bash -c "[[ \$(stat -c %a '$HOTSPOT') == 600 ]]"
check "it uses WPA2 with AES" bash -c "grep -q '^key-mgmt=wpa-psk' '$HOTSPOT' && grep -q '^pairwise=ccmp;' '$HOTSPOT' && grep -q '^proto=rsn;' '$HOTSPOT'"
check "push-button pairing is off" grep -q '^wps-method=1' "$HOTSPOT"
check "its passphrase is generated, four groups of four" grep -Eq '^psk=([a-z2-9]{4}-){3}[a-z2-9]{4}$' "$HOTSPOT"
PASSPHRASE="$(sed -n 's/^psk=//p' "$HOTSPOT")"
check "its name service answers nothing about the outside" grep -q '^no-resolv' "$A/etc/NetworkManager/dnsmasq-shared.d/offline.conf"
check "its name service knows this system" grep -q '^address=/offline.lan/10.42.0.1' "$A/etc/NetworkManager/dnsmasq-shared.d/offline.conf"
check "the passphrase can be shown again" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' hotspot show | grep -q '$PASSPHRASE'"
check "turning it off keeps it for next time" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' hotspot off >/dev/null && grep -q '^autoconnect=false' '$HOTSPOT'"
check "turning it on again keeps the same passphrase" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' hotspot on >/dev/null && grep -q '^psk=$PASSPHRASE' '$HOTSPOT' && grep -q '^ssid=Family Offline' '$HOTSPOT'"
refute "an interface name with shell characters is refused" offline hotspot on --interface 'wl;id'
refute "a network name with quotes is refused" offline hotspot on --interface wlan0 --ssid 'a"b'
refute "a channel outside the band is refused" offline hotspot on --interface wlan0 --band a --channel 6
check "the hotspot can be removed" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' hotspot remove >/dev/null && [[ ! -e '$HOTSPOT' ]]"
if have nmcli && nmcli --offline connection add type wifi ifname wlp2s0 con-name x ssid y >/dev/null 2>&1; then
  offline hotspot on --interface wlp2s0 --ssid Check >/dev/null
  nmcli --offline connection add type wifi ifname wlp2s0 con-name offline-hotspot autoconnect yes ssid Check mode ap 802-11-wireless.band bg \
    802-11-wireless.channel 6 wifi-sec.key-mgmt wpa-psk wifi-sec.proto rsn wifi-sec.pairwise ccmp wifi-sec.group ccmp wifi-sec.pmf 2 \
    wifi-sec.wps-method 1 wifi-sec.psk "$(sed -n 's/^psk=//p' "$HOTSPOT")" ipv4.method shared ipv4.addresses 10.42.0.1/24 ipv6.method disabled 2>/dev/null |
    grep -Ev '^(uuid=|addr-gen-mode=|\[proxy\]|$)' | sort >"$WORK/nm-reference.txt"
  check "the profile matches what NetworkManager itself would write" bash -c "grep -Ev '^(#|autoconnect=|\$)' '$HOTSPOT' | sort | diff - '$WORK/nm-reference.txt'"
  offline hotspot remove >/dev/null
else
  note "NetworkManager not installed: hotspot profile not compared with its own generator"
fi

sed -i 's/^SERVERS=.*/SERVERS="192.168.7.10 198.51.100.7"/' "$A/etc/offline/offline.conf"
check "only a server on a private network is asked the time" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' time sources | grep -q 'asks 192.168.7.10 for the time' && ! grep -rq '198.51.100.7' '$A/etc/systemd/timesyncd.conf.d'"
check "the usual time server stays as the fallback" grep -q '^NTP=192.168.7.10 ntp.ubuntu.com' "$A/etc/systemd/timesyncd.conf.d/offline.conf"
mkdir -p "$A/etc/chrony"
check "with chrony present its own source list is used" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' time sources >/dev/null && grep -q '^server 192.168.7.10 iburst' '$A/etc/chrony/sources.d/offline.sources' && [[ ! -e '$A/etc/systemd/timesyncd.conf.d/offline.conf' ]]"
check "a home server can serve time" offline time serve on
check "it serves private networks and keeps going without the internet" bash -c "grep -q '^allow 192.168.0.0/16' '$A/etc/chrony/conf.d/offline-server.conf' && grep -q '^local stratum 10 orphan' '$A/etc/chrony/conf.d/offline-server.conf'"
refute "it serves no public range" grep -Eq '^allow (0\.0\.0\.0|all|::)' "$A/etc/chrony/conf.d/offline-server.conf"
if have chronyd; then
  check "chrony accepts the time service settings" bash -c "chronyd -p -f '$A/etc/chrony/conf.d/offline-server.conf' | grep -q 'local stratum 10 orphan'"
fi
check "the same private servers are tried for packages" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' rebuild clients >/dev/null && grep -qx '192.168.7.10' '$A/etc/offline/apt-cache.hosts' && ! grep -q '198.51.100.7' '$A/etc/offline/apt-cache.hosts'"
check "apt is told to ask the detector, not given a fixed proxy" bash -c "grep -q 'Proxy-Auto-Detect \"/usr/local/lib/offline/apt-proxy-detect\"' '$A/etc/apt/apt.conf.d/02offline-cache' && ! grep -q 'Acquire::http::Proxy \"' '$A/etc/apt/apt.conf.d/02offline-cache'"
sed -i 's/^SYNC_EXPOSE=.*/SYNC_EXPOSE=internet/' "$A/etc/offline/offline.conf"
refute "a server facing the internet serves no time" offline time serve on
refute "a server facing the internet hosts no world" offline games host creative
refute "a server facing the internet serves no tablet activities" offline games activities
refute "a server facing the internet raises no hotspot" offline hotspot on --interface wlp2s0
refute "a server facing the internet serves no package cache" offline rebuild cache on
sed -i 's/^SYNC_EXPOSE=.*/SYNC_EXPOSE=lan/; s/^SERVERS=.*/SERVERS=""/' "$A/etc/offline/offline.conf"
offline time sources >/dev/null
offline rebuild clients >/dev/null
check "with no household server, apt is left alone" test ! -e "$A/etc/apt/apt.conf.d/02offline-cache"

echo
echo "Rebuilding"
check "the package cache can be switched on" offline rebuild cache on
ACNG="$A/etc/apt-cacher-ng/zz_offline.conf"
check "the cache serves package archives only" grep -q '^ForceManaged: 1' "$ACNG"
check "the cache knows Ubuntu's security archive" grep -q '^Remap-ubusec: security.ubuntu.com' "$ACNG"
check "its maintenance pages need a sign-in" bash -c "grep -Eq '^AdminAuth: offline:[0-9a-f]{32}$' '$A/etc/apt-cacher-ng/zz_offline_security.conf' && [[ \$(stat -c %a '$A/etc/apt-cacher-ng/zz_offline_security.conf') == 640 ]]"
check "the cache can be told to stand alone" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' rebuild offline on >/dev/null && grep -q '^Offlinemode: 1' '$ACNG' && OFFLINE_PREFIX='$A' '$ROOT/bin/offline' rebuild | grep -q 'offline (answers from what it holds)'"
check "and to fetch again" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' rebuild offline off >/dev/null && grep -q '^Offlinemode: 0' '$ACNG'"
printf '%064d *ubuntu-24.04.1-desktop-amd64.iso\n%064d *ubuntu-24.04.3-desktop-amd64.iso\n%064d *ubuntu-24.04.3-live-server-amd64.iso\n%064d *ubuntu-24.04.10-desktop-amd64.iso\n%064d *ubuntu-24.04.3-desktop-arm64.iso\n' 1 2 3 4 5 >"$WORK/SHA256SUMS"
# shellcheck disable=SC2016
check "the newest image of the kind asked for is chosen" php -r 'require $argv[1] . "/lib/cli.php"; $i = Offline\ubuntu_pick_image(file_get_contents($argv[2]), "24.04", "desktop", "amd64"); exit($i !== null && $i[0] === "ubuntu-24.04.10-desktop-amd64.iso" && $i[1] === str_pad("4", 64, "0", STR_PAD_LEFT) ? 0 : 1);' "$ROOT" "$WORK/SHA256SUMS"
# shellcheck disable=SC2016
check "an image for another release is not chosen" php -r 'require $argv[1] . "/lib/cli.php"; exit(Offline\ubuntu_pick_image(file_get_contents($argv[2]), "26.04", "desktop", "amd64") === null ? 0 : 1);' "$ROOT" "$WORK/SHA256SUMS"
echo 'not a signature' >"$WORK/SHA256SUMS.gpg"
# shellcheck disable=SC2016
check "a checksum list Ubuntu did not sign is refused" php -r 'require $argv[1] . "/lib/cli.php"; exit(Offline\ubuntu_signature_ok($argv[2], $argv[2] . ".gpg") ? 1 : 0);' "$ROOT" "$WORK/SHA256SUMS" 2>/dev/null

echo
echo "Family vault"
# shellcheck disable=SC2016
check "names gocryptfs writes are accepted" php -r 'require $argv[1] . "/lib/cli.php"; foreach (["gocryptfs.conf", "gocryptfs.diriv", "g3GNyP2FoBqq4W1P0F6RRQ", "g3GNyP2FoBqq4W1P0F6RRQ/RjfMfcAyNtvHKK_u04H8Hg", "H4gU-TQ/gocryptfs.diriv", "gocryptfs.longname.dHvBkLuCUGBRfDr9oSd3X5MjWGnbnyD0mfQAkV6xHj8", "gocryptfs.longname.dHvBkLuCUGBRfDr9oSd3X5MjWGnbnyD0mfQAkV6xHj8.name"] as $p) { if (!Offline\vault_path_ok($p)) { fwrite(STDERR, $p); exit(1); } } exit(0);' "$ROOT"
# shellcheck disable=SC2016
check "anything else is not" php -r 'require $argv[1] . "/lib/cli.php"; foreach (["", "/etc/passwd", "../x", "a/../b", ".hidden", "notes.txt", "run.sh", "a/gocryptfs.conf", "gocryptfs.conf/x", "a b", "a\nb", "gocryptfs.longname.short"] as $p) { if (Offline\vault_path_ok($p)) { fwrite(STDERR, $p); exit(1); } } exit(0);' "$ROOT"
check "a system with no vault says how to make one" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' vault | grep -q 'offline vault init'"
refute "a passphrase file others can read is refused" bash -c "echo secret >'$WORK/loose.pass'; chmod 644 '$WORK/loose.pass'; OFFLINE_PREFIX='$A' '$ROOT/bin/offline' vault init --passfile '$WORK/loose.pass'"
if have gocryptfs; then
  printf 'correct horse battery staple\n' >"$WORK/vault.pass"
  chmod 600 "$WORK/vault.pass"
  check "the vault can be created" offline vault init --passfile "$WORK/vault.pass"
  check "its key file is readable by the sharing group and nobody else" bash -c "[[ \$(stat -c %a '$A/srv/offline/vault/gocryptfs.conf') == 440 ]]"
  refute "the key file does not hold the passphrase" grep -q 'correct horse' "$A/srv/offline/vault/gocryptfs.conf"
  refute "a second vault cannot be created over the first" offline vault init --passfile "$WORK/vault.pass"
  if [[ -w /dev/fuse ]] && (have fusermount3 || have fusermount); then
    mkdir -p "$WORK/vaulthome"
    vault() { HOME="$WORK/vaulthome" OFFLINE_PREFIX="$A" "$ROOT/bin/offline" vault "$@"; }
    check "the vault opens with its passphrase" vault open --passfile "$WORK/vault.pass"
    if mountpoint -q "$WORK/vaulthome/Vault"; then
      mkdir -p "$WORK/vaulthome/Vault/Passports"
      echo 'Passport number 123456789' >"$WORK/vaulthome/Vault/Passports/peter.txt"
      check "a document can be saved in it" grep -q 123456789 "$WORK/vaulthome/Vault/Passports/peter.txt"
      refute "nothing readable reaches the disc" grep -rq -e 123456789 -e Passports -e peter "$A/srv/offline/vault"
      check "the vault reports that it is open" bash -c "HOME='$WORK/vaulthome' OFFLINE_PREFIX='$A' '$ROOT/bin/offline' vault | grep -q 'It is open at'"
      check "the vault closes" vault close
      refute "closed, the documents are gone from view" test -e "$WORK/vaulthome/Vault/Passports/peter.txt"
      echo wrong >"$WORK/wrong.pass"; chmod 600 "$WORK/wrong.pass"
      refute "the wrong passphrase opens nothing" vault open --passfile "$WORK/wrong.pass"
      refute "and leaves nothing mounted" mountpoint -q "$WORK/vaulthome/Vault"
    else
      fail "the vault is mounted after opening"
    fi
  else
    note "FUSE is not available here: opening the vault not exercised"
  fi
else
  note "gocryptfs not installed: vault creation not exercised"
fi

echo
echo "Place search and directions"
mkdir -p "$WORK/geo"
# shellcheck disable=SC2016
php -r '
$d = $argv[1];
$row = fn($id, $name, $ascii, $lat, $lon, $class, $code, $cc, $adm, $pop) => implode("\t", [$id, $name, $ascii, "", $lat, $lon, $class, $code, $cc, "", $adm, "", "", "", $pop, "", "", "Europe/London", "2026-01-01"]) . "\n";
file_put_contents("$d/cities500.txt",
    $row(1, "Penryn", "Penryn", 50.16913, -5.10709, "P", "PPL", "GB", "ENG", 7166) . $row(2, "Falmouth", "Falmouth", 50.15441, -5.07113, "P", "PPL", "GB", "ENG", 21635)
    . $row(3, "Falmouth", "Falmouth", 41.55149, -70.61475, "P", "PPL", "US", "MA", 31531) . $row(4, "Zürich", "Zurich", 47.36667, 8.55, "P", "PPLA", "CH", "ZH", 341730)
    . $row(5, "Evil\t<script>alert(1)</script>", "Evil", 1, 1, "P", "PPL", "GB", "ENG", 5) . $row(6, "Nowhere", "Nowhere", 123.0, 0, "P", "PPL", "GB", "ENG", 5)
    . $row(7, "<b>Bold</b> Town", "Bold Town", 51, -1, "P", "PPL", "GB", "ENG", 9));
file_put_contents("$d/GB.txt", $row(1, "Penryn", "Penryn", 50.16913, -5.10709, "P", "PPL", "GB", "ENG", 7166) . $row(8, "Carn Brea", "Carn Brea", 50.2226, -5.2473, "T", "HLL", "GB", "ENG", 0)
    . $row(9, "Some Farm", "Some Farm", 50.1, -5.1, "S", "FRM", "GB", "ENG", 0) . $row(10, "Treliske Hospital", "Treliske Hospital", 50.266, -5.092, "S", "HSP", "GB", "ENG", 0));
file_put_contents("$d/admin1CodesASCII.txt", "GB.ENG\tEngland\tEngland\t1\nUS.MA\tMassachusetts\tMassachusetts\t2\n");
file_put_contents("$d/countryInfo.txt", "# comment\nGB\tGBR\t826\tUK\tUnited Kingdom\tLondon\nUS\tUSA\t840\tUS\tUnited States\tWashington\nCH\tCHE\t756\tSZ\tSwitzerland\tBern\n");
$pc = fn($code, $place, $lat, $lon) => implode("\t", ["GB", $code, $place, "England", "ENG", "Cornwall", "", "", "", $lat, $lon, "6"]) . "\n";
file_put_contents("$d/GB_full.txt", $pc("TR10 8AB", "Penryn", 50.1690, -5.1070) . $pc("TR10 8AD", "Penryn", 50.1695, -5.1080) . $pc("SW1A 1AA", "London", 51.5010, -0.1416) . $pc("../../etc", "X", 1, 1) . $pc("TR1 1AA", "Truro", "x", -5.05));
' "$WORK/geo"
if have unzip && have python3; then
  (cd "$WORK/geo" && python3 -m zipfile -c cities500.zip cities500.txt && python3 -m zipfile -c GB.zip GB.txt && python3 -m zipfile -c GB_full.csv.zip GB_full.txt && rm cities500.txt GB.txt GB_full.txt)
  check "the place index is built from GeoNames files" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' maps places --from '$WORK/geo' | grep -q '7 places and 3 UK postcodes'"
  find_place() { OFFLINE_PREFIX="$A" "$ROOT/bin/offline" maps find "$@"; }
  check "a town is found by name" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' maps find penryn | grep -q 'Penryn .*England, United Kingdom .*50.16913'"
  check "a name is found without its accents" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' maps find zurich | grep -q 'Zürich'"
  check "the beginning of a name is enough" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' maps find falm | grep -c Falmouth | grep -qx 2"
  check "a UK postcode is found, spaced or not" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' maps find tr108ab | grep -q 'TR10 8AB .*Penryn, postcode'"
  check "part of a postcode lists those that match" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' maps find 'TR10' | grep -c postcode | grep -qx 2"
  check "coordinates are recognised" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' maps find '50.169, -5.107' | grep -q Coordinates"
  check "hills and hospitals in Britain are indexed" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' maps find carn | grep -q 'Carn Brea' && OFFLINE_PREFIX='$A' '$ROOT/bin/offline' maps find treliske | grep -q Hospital"
  refute "farms and the like are not" find_place farm
  refute "a row with an impossible position is dropped" grep -q Nowhere "$A/srv/offline/maps/places/names.tsv"
  refute "a row that breaks the column layout is dropped" grep -q 'alert(1)' "$A/srv/offline/maps/places/names.tsv"
  check "a postcode that is not one creates no file" bash -c "[[ \$(find '$A/srv/offline/maps/places/postcodes' -type f | wc -l) -eq 2 ]]"
  check "every index line has six columns" bash -c "! awk -F'\t' 'NF != 6' '$A/srv/offline/maps/places/names.tsv' | grep -q ."
else
  note "unzip or python3 not installed: place index not built"
fi
check "with no routing graph the command says how to get one" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' maps routing | grep -q 'sudo offline maps routing build uk'"
refute "an unknown routing region is refused" offline maps routing build atlantis
echo 'not a map extract' >"$WORK/fake.osm.pbf"
check "a file that is not an OpenStreetMap extract is refused before anything runs" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' maps routing build --pbf '$WORK/fake.osm.pbf' 2>&1 | grep -q 'is not an OpenStreetMap PBF extract'"
check "directions can be switched on" offline maps routing on
check "switching them on installs the engine's unit and tells the web server" bash -c "grep -q '^PublishPort=127.0.0.1:18700:8002' '$A/etc/containers/systemd/offline-routing.container' && grep -q '^Define OFFLINE_ROUTING' '$A/etc/apache2/conf-available/offline-routing.conf'"
check "and off again" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' maps routing off >/dev/null && [[ ! -e '$A/etc/containers/systemd/offline-routing.container' && ! -e '$A/etc/apache2/conf-available/offline-routing.conf' ]]"
check "map regions are fetched by group name" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' maps get uk-nations --dry-run 2>&1 | grep -c '^Map: ' | grep -qx 4 || ! command -v pmtiles >/dev/null"

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

  echo
  echo "Accounts between systems"
  printf 'made on another system\n' | OFFLINE_PREFIX="$B" "$ROOT/bin/offline" user add visitor --adult >/dev/null
  check "an account made on one system reaches another at its next sync" bash -c "OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' sync | grep -q 'accounts: 1 account updated (visitor)'"
  check "and can sign in there" auth 'exit(Offline\sign_in("visitor", "made on another system", "192.168.7.80")["ok"] ? 0 : 1);'
  check "what was already held is not fetched again" bash -c "! OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' sync | grep -q 'accounts:'"
  sleep 1
  OFFLINE_PREFIX="$B" "$ROOT/bin/offline" user lock visitor >/dev/null
  check "a lock follows, and the account can no longer sign in" bash -c "OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' sync >/dev/null; ! php -r 'define(\"OFFLINE_PREFIX\", \$argv[1]); require \$argv[2] . \"/lib/core.php\"; require \$argv[2] . \"/lib/auth.php\"; exit(Offline\\sign_in(\"visitor\", \"made on another system\", \"192.168.7.81\")[\"ok\"] ? 0 : 1);' '$A' '$ROOT'"
  sleep 1
  OFFLINE_PREFIX="$B" "$ROOT/bin/offline" user remove visitor >/dev/null
  check "a removal follows" bash -c "OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' sync >/dev/null; ! OFFLINE_PREFIX='$A' '$ROOT/bin/offline' user | grep -q visitor"
  # A system holding the key, but not honest: records that are not exactly the right shape change nothing.
  cp "$A/var/lib/offline/accounts/accounts.json" "$WORK/accounts-before.json"
  php -r '$ok = ["class" => "adult", "full" => "", "portal" => null, "login" => null, "locked" => false, "removed" => false, "updated" => gmdate("Y-m-d\\TH:i:s\\Z")];
    file_put_contents($argv[1], json_encode(["accounts" => ["root" => $ok, "x;reboot" => $ok, "sudoer" => $ok + ["groups" => ["sudo"]], "kid" => ["class" => "wizard"] + $ok,
      "hash" => ["login" => "\$6\$rounds=200000\$abcdefghijklmnop\$x\nroot::0:0::/root:/bin/sh"] + $ok, "ahead" => ["updated" => "2099-01-01T00:00:00Z"] + $ok]]));' "$B/var/lib/offline/accounts/accounts.json"
  check "malformed and overreaching records from another system change nothing" bash -c "OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' sync >/dev/null; cmp -s '$A/var/lib/offline/accounts/accounts.json' '$WORK/accounts-before.json'"
  head -c 2000000 /dev/zero >"$B/var/lib/offline/accounts/accounts.json"
  check "an oversized accounts file is not fetched" bash -c "OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' sync >/dev/null; cmp -s '$A/var/lib/offline/accounts/accounts.json' '$WORK/accounts-before.json' && ! ls '$A/var/lib/offline/' | grep -q '^accounts-'"
  refute "the accounts share offers nothing but the one file" bash -c "echo secret >'$B/var/lib/offline/accounts/other.txt'; RSYNC_PASSWORD='$KEY' rsync 'rsync://offline@127.0.0.1:$BDPORT/accounts/other.txt' '$WORK/stolen.txt' 2>/dev/null"

  echo
  echo "The list of systems between systems"
  if [[ -f "$A/etc/offline/net/public.key" ]]; then
    OFFLINE_PREFIX="$B" "$ROOT/bin/offline" net join >/dev/null
    OFFLINE_PREFIX="$B" "$ROOT/bin/offline" net endpoint b.example.org:51820 >/dev/null
    check "a system that joined is learnt of at the next sync" bash -c "OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' sync | grep -q 'network: 1 system updated'"
    check "and becomes a peer of the tunnel" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' net apply && grep -q \"^PublicKey = \$(cat '$B/etc/offline/net/public.key')\" '$A/etc/wireguard/offline0.conf' && grep -q '^Endpoint = b.example.org:51820' '$A/etc/wireguard/offline0.conf'"
    cp "$A/var/lib/offline/network/nodes.json" "$WORK/nodes-before.json"
    php -r '$k = base64_encode(random_bytes(32)); file_put_contents($argv[1], json_encode(["nodes" => [$k => ["name" => "intruder", "role" => "server", "address" => "10.47.0.1", "endpoint" => "evil.example:51820", "removed" => false, "updated" => gmdate("Y-m-d\\TH:i:s\\Z")],
      "not a key" => ["name" => "x", "role" => "server", "address" => "10.47.1.1", "endpoint" => null, "removed" => false, "updated" => gmdate("Y-m-d\\TH:i:s\\Z")]]]));' "$B/var/lib/offline/network/nodes.json"
    check "a record claiming an address that is not its key's changes nothing" bash -c "OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' sync >/dev/null; cmp -s '$A/var/lib/offline/network/nodes.json' '$WORK/nodes-before.json'"
    R="$WORK/r"
    make_root "$R"
    echo "$KEY" | OFFLINE_PREFIX="$R" "$ROOT/bin/offline" key set - >/dev/null
    sed -i 's/^ROLE=.*/ROLE=repeater/; s/^SYNC_MODULES=.*/SYNC_MODULES=""/; s/^VAULT_SYNC=.*/VAULT_SYNC=no/' "$R/etc/offline/offline.conf"
    OFFLINE_PREFIX="$B" "$ROOT/bin/offline" net endpoint b.example.org:51820 >/dev/null
    printf 'known to the repeater too\n' | OFFLINE_PREFIX="$B" "$ROOT/bin/offline" user add relayed --adult >/dev/null
    check "a repeater takes accounts and the list of systems, and no content" bash -c "OFFLINE_PREFIX='$R' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' sync >'$WORK/repeater-sync.txt'; grep -q 'accounts: 1 account updated (relayed)' '$WORK/repeater-sync.txt' && grep -q 'network: 1 system updated' '$WORK/repeater-sync.txt' && grep -q '1 trusted' '$WORK/repeater-sync.txt' && [[ -z \$(find '$R/srv/offline/media' '$R/srv/offline/knowledge' -type f | head -n 1) ]]"
  fi

  echo
  echo "Vault exchange"
  # B holds ciphertext, as a real vault would leave it, and some things that are not ciphertext.
  mkdir -p "$B/srv/offline/vault/H4gUTQjnIQmBDDyYGjEKDw"
  echo 'conf-as-B-has-it' >"$B/srv/offline/vault/gocryptfs.conf"
  head -c 16 /dev/urandom >"$B/srv/offline/vault/gocryptfs.diriv"
  head -c 16 /dev/urandom >"$B/srv/offline/vault/H4gUTQjnIQmBDDyYGjEKDw/gocryptfs.diriv"
  head -c 900 /dev/urandom >"$B/srv/offline/vault/H4gUTQjnIQmBDDyYGjEKDw/yreZt0nVExB5xAyO8nCZgQ"
  echo 'plain text' >"$B/srv/offline/vault/notes.txt"
  printf '#!/bin/sh\n' >"$B/srv/offline/vault/run.sh"
  ln -s /etc/passwd "$B/srv/offline/vault/passwdlink"
  rm -rf -- "$A/srv/offline/vault"
  mkdir -p "$A/srv/offline/vault"
  check "sync carries the vault's ciphertext" bash -c "OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' sync | grep -q 'vault: 4 files to fetch'"
  check "the key file and the encrypted documents arrive" bash -c "cmp -s '$B/srv/offline/vault/gocryptfs.conf' '$A/srv/offline/vault/gocryptfs.conf' && cmp -s '$B/srv/offline/vault/H4gUTQjnIQmBDDyYGjEKDw/yreZt0nVExB5xAyO8nCZgQ' '$A/srv/offline/vault/H4gUTQjnIQmBDDyYGjEKDw/yreZt0nVExB5xAyO8nCZgQ'"
  check "what arrives is closed to other accounts" bash -c "[[ \$(stat -c %a '$A/srv/offline/vault/H4gUTQjnIQmBDDyYGjEKDw/yreZt0nVExB5xAyO8nCZgQ') == 660 ]]"
  refute "a plain file planted in a vault is not fetched" test -e "$A/srv/offline/vault/notes.txt"
  refute "a script planted in a vault is not fetched" test -e "$A/srv/offline/vault/run.sh"
  refute "a link planted in a vault is not fetched" test -e "$A/srv/offline/vault/passwdlink"
  # An edited document keeps its size; only its time tells that it changed.
  head -c 900 /dev/urandom >"$B/srv/offline/vault/H4gUTQjnIQmBDDyYGjEKDw/yreZt0nVExB5xAyO8nCZgQ"
  touch -d '+1 hour' "$B/srv/offline/vault/H4gUTQjnIQmBDDyYGjEKDw/yreZt0nVExB5xAyO8nCZgQ"
  check "a newer copy of a document replaces an older one of the same size" bash -c "OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' sync >/dev/null; cmp -s '$B/srv/offline/vault/H4gUTQjnIQmBDDyYGjEKDw/yreZt0nVExB5xAyO8nCZgQ' '$A/srv/offline/vault/H4gUTQjnIQmBDDyYGjEKDw/yreZt0nVExB5xAyO8nCZgQ'"
  head -c 900 /dev/urandom >"$A/srv/offline/vault/H4gUTQjnIQmBDDyYGjEKDw/yreZt0nVExB5xAyO8nCZgQ"
  touch -d '+2 hours' "$A/srv/offline/vault/H4gUTQjnIQmBDDyYGjEKDw/yreZt0nVExB5xAyO8nCZgQ"
  refute "an older copy never replaces a newer one" bash -c "OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' sync >/dev/null; cmp -s '$B/srv/offline/vault/H4gUTQjnIQmBDDyYGjEKDw/yreZt0nVExB5xAyO8nCZgQ' '$A/srv/offline/vault/H4gUTQjnIQmBDDyYGjEKDw/yreZt0nVExB5xAyO8nCZgQ'"
  sed -i 's/^SYNC_MIN_FREE_GB=.*/SYNC_MIN_FREE_GB=100000000/' "$A/etc/offline/offline.conf"
  head -c 2000 /dev/urandom >"$B/srv/offline/media/movies/second.mp4"
  check "a system short of space fetches nothing and says so" bash -c "OFFLINE_PREFIX='$A' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' sync | grep -q 'left behind to protect free space' && [[ ! -e '$A/srv/offline/media/movies/second.mp4' ]]"
  sed -i 's/^SYNC_MIN_FREE_GB=.*/SYNC_MIN_FREE_GB=0/' "$A/etc/offline/offline.conf"
  E="$WORK/e"
  make_root "$E"
  echo "$KEY" | OFFLINE_PREFIX="$E" "$ROOT/bin/offline" key set - >/dev/null
  sed -i 's/^VAULT_SYNC=.*/VAULT_SYNC=no/; s/^SYNC_MODULES=.*/SYNC_MODULES="maps"/' "$E/etc/offline/offline.conf"
  check "a system told not to hold the vault does not fetch it" bash -c "OFFLINE_PREFIX='$E' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' sync >/dev/null; [[ -z \$(ls -A '$E/srv/offline/vault') ]]"
  if [[ -f "$A/srv/offline/maps/places/names.tsv" ]]; then
    mkdir -p "$B/srv/offline/maps/places" "$B/srv/offline/maps/routing"
    cp "$A/srv/offline/maps/places/names.tsv" "$B/srv/offline/maps/places/names.tsv"
    head -c 20000 /dev/urandom >"$B/srv/offline/maps/routing/graph.tar"
    check "the place index and the routing graph travel with the maps" bash -c "OFFLINE_PREFIX='$E' OFFLINE_TEST_PEERS='127.0.0.1:$BPORT' '$ROOT/bin/offline' sync >/dev/null; [[ -f '$E/srv/offline/maps/places/names.tsv' && -f '$E/srv/offline/maps/routing/graph.tar' ]]"
  fi
else
  note "rsync or stunnel not installed: sync and broadcast checks not run"
fi

# --------------------------------------------------------------------------
# Content by disc: out through the filters, and in through them again
# --------------------------------------------------------------------------

echo
echo "Export and import"
if have rsync; then
  DISC="$WORK/disc"
  mkdir -p "$DISC" "$A/srv/offline/media/music/.cache"
  head -c 3000 /dev/urandom >"$A/srv/offline/media/music/song.flac"
  printf '#!/bin/sh\n' >"$A/srv/offline/media/music/tool.sh"
  echo hidden >"$A/srv/offline/media/music/.cache/x.mp3"
  ln -sf /etc/passwd "$A/srv/offline/media/music/link.txt"
  check "content can be exported to a disc" offline export "$DISC/offline"
  check "the export says what it is" grep -q 'sudo offline import' "$DISC/offline/OFFLINE-EXPORT.txt"
  check "films, music and library titles are on the disc" bash -c "[[ -f '$DISC/offline/media/music/song.flac' && -n \$(find '$DISC/offline/knowledge' -name '*.zim' | head -n 1) ]]"
  refute "a script is not exported" test -e "$DISC/offline/media/music/tool.sh"
  refute "a hidden folder is not exported" test -e "$DISC/offline/media/music/.cache"
  refute "a link is not exported" test -e "$DISC/offline/media/music/link.txt"
  refute "a relative folder is refused" offline export disc
  check "exporting again copies nothing twice" bash -c "OFFLINE_PREFIX='$A' '$ROOT/bin/offline' export '$DISC/offline' | grep -q 'To copy: 0 files'"
  # Somebody else's disc: what is on it cannot be assumed to have come from an export.
  printf '#!/bin/sh\n' >"$DISC/offline/media/music/planted.sh"
  echo '<script>' >"$DISC/offline/media/music/page.html"
  echo 'not a zim' >"$DISC/offline/knowledge/zim/fake_en_2026-03.zim"
  make_zim "$DISC/offline/knowledge/zim/testwiki_en_all_2020-01.zim"
  ln -s /etc/passwd "$DISC/offline/media/music/passwd.txt"
  mkdir -p "$DISC/offline/media/linked" && ln -s /etc "$DISC/offline/media/linked/etc"
  G="$WORK/g"
  make_root "$G"
  make_zim "$G/srv/offline/knowledge/zim/testwiki_en_all_2026-05.zim" 500
  inbound() { OFFLINE_PREFIX="$G" "$ROOT/bin/offline" import "$@"; }
  check "a dry run brings nothing in" bash -c "OFFLINE_PREFIX='$G' '$ROOT/bin/offline' import '$DISC/offline' --dry-run | grep -q 'nothing was copied' && [[ ! -e '$G/srv/offline/media/music/song.flac' ]]"
  check "content can be brought in from a disc" inbound "$DISC/offline"
  check "music arrives intact" cmp -s "$A/srv/offline/media/music/song.flac" "$G/srv/offline/media/music/song.flac"
  refute "a script on the disc is not brought in" test -e "$G/srv/offline/media/music/planted.sh"
  refute "a web page on the disc is not brought in" test -e "$G/srv/offline/media/music/page.html"
  refute "a link on the disc is not brought in" test -e "$G/srv/offline/media/music/passwd.txt"
  refute "a linked folder on the disc is not entered" test -e "$G/srv/offline/media/linked"
  refute "a fake library title is discarded" test -e "$G/srv/offline/knowledge/zim/fake_en_2026-03.zim"
  refute "an edition older than the one held is not brought in" test -e "$G/srv/offline/knowledge/zim/testwiki_en_all_2020-01.zim"
  check "only what was asked for is brought in" bash -c "H='$WORK/h'; mkdir -p \"\$H\"; cp -r '$G/etc' '$G/usr' \"\$H/\"; mkdir -p \"\$H/srv/offline/knowledge/zim\" \"\$H/srv/offline/media\" \"\$H/var/lib/offline\" \"\$H/run/offline\"; OFFLINE_PREFIX=\"\$H\" '$ROOT/bin/offline' import '$DISC/offline' --what media >/dev/null && [[ -f \"\$H/srv/offline/media/music/song.flac\" && -z \$(ls -A \"\$H/srv/offline/knowledge/zim\") ]]"
  refute "a folder that is not an export is refused" inbound "$WORK/geo"
else
  note "rsync not installed: export and import not run"
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
  # The pages look at a session only on the encrypted site, which the web server marks with HTTPS=on.
  printf '<?php define("OFFLINE_PREFIX", "%s"); require "%s/lib/core.php"; $_SERVER["HTTPS"] = "on";\n' "$A" "$ROOT" >"$WORK/prepend.php"
  ADULT="$(printf 'C%.0s' {1..43})"
  echo "$ADULT adult:peter:$(($(date +%s) + 3600))" >"$A/run/offline-auth/sessions.map"
  # Every check written before there were accounts looks at the portal as a signed-in adult: curl sends
  # the session with each request. The checks of what a child sees use "curl -q", which sends nothing.
  mkdir -p "$WORK/curlhome"
  printf 'header = "Cookie: __Host-offline-session=%s"\n' "$ADULT" >"$WORK/curlhome/.curlrc"
  export CURL_HOME="$WORK/curlhome"
  mkdir -p "$A/srv/offline/media/children/Cartoons"
  head -c 1500 /dev/urandom >"$A/srv/offline/media/children/Cartoons/Small film.mp4"
  ln -s ../movies "$A/srv/offline/media/children/way-out"
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
  for page in "" library.php media.php "media.php?p=movies" "media.php?p=movies/Trips" "play.php?f=movies/holiday.mp4" peers.php guide.php alerts.php games.php "places.php?q=penryn" \
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
  check "the games page lists the family worlds" bash -c "curl -s 'http://127.0.0.1:$WEB/games.php' | grep -q 'Family worlds'"
  check "the games page shows an installed game" bash -c "curl -s 'http://127.0.0.1:$WEB/games.php' | grep -q 'GCompris'"
  check "the games page links the browser activities" bash -c "curl -s 'http://127.0.0.1:$WEB/games.php' | grep -q ':8600/'"
  if [[ -f "$A/srv/offline/maps/places/names.tsv" ]]; then
    check "the map page offers place search" bash -c "curl -s 'http://127.0.0.1:$WEB/maps.php' | grep -q 'id=\"place-query\"'"
    check "place search answers in JSON" bash -c "curl -s 'http://127.0.0.1:$WEB/places.php?q=TR10+8AB' | php -r '\$d = json_decode(stream_get_contents(STDIN), true); exit((\$d[\"places\"][0][\"name\"] ?? \"\") === \"TR10 8AB\" ? 0 : 1);'"
    check "of two places with one name the nearer comes first" bash -c "curl -s 'http://127.0.0.1:$WEB/places.php?q=falmouth&near=50.2,-5.1' | php -r '\$d = json_decode(stream_get_contents(STDIN), true); exit(str_contains(\$d[\"places\"][0][\"detail\"] ?? \"\", \"United Kingdom\") ? 0 : 1);' && curl -s 'http://127.0.0.1:$WEB/places.php?q=falmouth&near=41.5,-70.6' | php -r '\$d = json_decode(stream_get_contents(STDIN), true); exit(str_contains(\$d[\"places\"][0][\"detail\"] ?? \"\", \"United States\") ? 0 : 1);'"
    refute "markup in a place name is neutralised" bash -c "curl -s 'http://127.0.0.1:$WEB/places.php?q=bold' | grep -q '<b>'"
    check "a search with control characters is refused" bash -c "[[ \$(curl -s -o /dev/null -w '%{http_code}' 'http://127.0.0.1:$WEB/places.php?q=%00x') == 400 ]]"
  fi
  refute "directions are not offered without a graph and its engine" bash -c "curl -s 'http://127.0.0.1:$WEB/maps.php' | grep -q 'id=\"route-panel\"'"
  check "the guide covers the vault and the hotspot" bash -c "curl -s 'http://127.0.0.1:$WEB/guide.php' | grep -q 'offline vault open' && curl -s 'http://127.0.0.1:$WEB/guide.php' | grep -q 'offline hotspot on'"
  check "the systems page explains the broadcast" bash -c "curl -s 'http://127.0.0.1:$WEB/peers.php' | grep -q 'offline broadcast --urgent'"
  check "the systems page shows the download queue" bash -c "curl -s 'http://127.0.0.1:$WEB/peers.php' | grep -q 'NHS Health A to Z'"
  check "the apps page shows what is installed" bash -c "curl -s 'http://127.0.0.1:$WEB/apps.php' | grep -q 'sudo offline apps start vaultwarden'"
  check "the radio page shows the plugged-in receiver" bash -c "curl -s 'http://127.0.0.1:$WEB/radio.php' | grep -q 'RTL-SDR receiver'"
  refute "generated sign-ins never reach a page" bash -c "curl -s 'http://127.0.0.1:$WEB/apps.php' | grep -q \"\$(cat '$A/etc/offline/apps/flatnotes.login')\""
  check "pages carry a Content-Security-Policy" bash -c "curl -sI 'http://127.0.0.1:$WEB/' | grep -qi \"content-security-policy: default-src 'none'\""
  refute "the house key never reaches a page" grep -rq "$KEY" "$WORK/body.html" "$A/run/offline/status.json"

  echo
  echo "What a child sees"
  kid() { curl -q -s -o "$WORK/kid.html" -w '%{http_code} %{redirect_url}' "$@"; }
  check "without signing in, the front page is the children's" bash -c "curl -q -s 'http://127.0.0.1:$WEB/' | grep -q 'What would you like to do?' && curl -q -s 'http://127.0.0.1:$WEB/' | grep -q '<body class=\"kids\">'"
  check "signed in, it is the overview" bash -c "curl -s 'http://127.0.0.1:$WEB/' | grep -q '<body class=\"grown-up\">'"
  refute "the children's pages do not list the adults' pages" bash -c "curl -q -s 'http://127.0.0.1:$WEB/' | grep -Eq 'href=\"/(library|apps|radio|peers|guide|remedies)\.php'"
  for page in library.php apps.php radio.php peers.php guide.php remedies.php "media.php?p=movies" "media.php?p=movies/Trips" "play.php?f=movies/holiday.mp4" \
    "media.php?p=children/way-out" "play.php?f=children/../movies/holiday.mp4"; do
    if [[ "$(kid "http://127.0.0.1:$WEB/$page")" == "302 https://127.0.0.1/account/signin.php"* || "$(kid "http://127.0.0.1:$WEB/$page")" == 404* ]] && ! grep -q 'Gower\|<video\|files/media/movies' "$WORK/kid.html"; then
      pass "/$page is closed to anyone not signed in"
    else
      fail "/$page is closed to anyone not signed in"
    fi
  done
  check "a made-up session is nobody" bash -c "curl -q -s -H 'Cookie: __Host-offline-session=$(printf 'D%.0s' {1..43})' 'http://127.0.0.1:$WEB/library.php' -o /dev/null -w '%{http_code}' | grep -qx 302"
  check "a session that has run out is nobody" bash -c "echo '$(printf 'E%.0s' {1..43}) adult:peter:1000000000' >>'$A/run/offline-auth/sessions.map'; curl -q -s -H 'Cookie: __Host-offline-session=$(printf 'E%.0s' {1..43})' 'http://127.0.0.1:$WEB/library.php' -o /dev/null -w '%{http_code}' | grep -qx 302"
  check "the way back after signing in is kept, and only within the portal" bash -c "curl -q -s -o /dev/null -w '%{redirect_url}' 'http://127.0.0.1:$WEB/library.php' | grep -q 'signin.php?next=%2Flibrary.php'"
  check "children see their own films" bash -c "curl -q -s 'http://127.0.0.1:$WEB/media.php' | grep -q 'Cartoons' && curl -q -s 'http://127.0.0.1:$WEB/media.php?p=children/Cartoons' | grep -q 'Small film.mp4'"
  refute "children do not see the other folders" bash -c "curl -q -s 'http://127.0.0.1:$WEB/media.php' | grep -q -e movies -e Trips -e 'srv/offline'"
  check "a children's film can be played" bash -c "curl -q -s 'http://127.0.0.1:$WEB/play.php?f=children/Cartoons/Small%20film.mp4' | grep -q '<video'"
  check "the children's shelf page opens without signing in" bash -c "[[ \$(curl -q -s -o /dev/null -w '%{http_code}' 'http://127.0.0.1:$WEB/read.php') == 200 ]]"
  check "games, maps and broadcasts stay open to children" bash -c "for page in games.php maps.php alerts.php 'places.php?q=x'; do [[ \$(curl -q -s -o /dev/null -w '%{http_code}' \"http://127.0.0.1:$WEB/\$page\") == 200 ]] || exit 1; done"
  check "on the plain site a session is not even looked at" bash -c "printf '<?php define(\"OFFLINE_PREFIX\", \"%s\"); require \"%s/lib/core.php\";\n' '$A' '$ROOT' >'$WORK/prepend-plain.php'; php -d auto_prepend_file='$WORK/prepend-plain.php' -r '\$_COOKIE[\"__Host-offline-session\"] = \$argv[1]; \$_SERVER[\"REQUEST_METHOD\"] = \"GET\"; \$_SERVER[\"SCRIPT_NAME\"] = \"/index.php\"; require \$argv[2] . \"/portal/app/bootstrap.php\"; exit(portal_is_adult() ? 1 : 0);' '$ADULT' '$ROOT' >/dev/null 2>&1"

  # The sign-in pages, under the restrictions of their own pool.
  AUTH_DISABLED="$(sed -n 's/^php_admin_value\[disable_functions\] = //p' "$ROOT/config/php-fpm-offline-auth.conf")"
  printf '<?php define("OFFLINE_PREFIX", "%s"); require "%s/lib/core.php"; require "%s/lib/auth.php"; $_SERVER["HTTPS"] = "on";\n' "$A" "$ROOT" "$ROOT" >"$WORK/prepend-auth.php"
  SIGN=$((WEB + 1))
  php -d auto_prepend_file="$WORK/prepend-auth.php" -d disable_functions="$AUTH_DISABLED" -d allow_url_fopen=0 \
    -d open_basedir="$WORK/:$ROOT/lib/:$ROOT/config/" -d display_errors=1 -d error_reporting=-1 \
    -S "127.0.0.1:$SIGN" -t "$WORK/site/public" >"$WORK/php-auth.log" 2>&1 &
  PIDS+=("$!")
  sleep 1
  check "the sign-in page renders" bash -c "curl -q -s 'http://127.0.0.1:$SIGN/account/signin.php' | grep -q 'name=\"password\" type=\"password\"' && ! curl -q -s 'http://127.0.0.1:$SIGN/account/signin.php' | grep -Eq 'Warning|Notice|Fatal error|Deprecated'"
  check "a wrong password is refused without saying which part was wrong" bash -c "curl -q -s -d 'name=peter&password=not-the-password' 'http://127.0.0.1:$SIGN/account/signin.php' | grep -q 'do not match an adult'"
  check "the right password sets a session cookie that scripts and other sites cannot use" bash -c "curl -q -s -D '$WORK/signin.headers' -o /dev/null -d 'name=peter&password=a different long password&next=/library.php' 'http://127.0.0.1:$SIGN/account/signin.php'; grep -Eiq '^Set-Cookie: __Host-offline-session=[A-Za-z0-9_-]{43}; Path=/; Max-Age=43200; Secure; HttpOnly; SameSite=Strict' '$WORK/signin.headers' && grep -iq '^Location: /library.php' '$WORK/signin.headers'"
  check "signing in cannot be used to send someone to another site" bash -c "curl -q -s -D - -o /dev/null -d 'name=peter&password=a different long password&next=//evil.example/' 'http://127.0.0.1:$SIGN/account/signin.php' | grep -iq '^Location: /\s*$'"
  check "a sign-in form sent from another site is refused" bash -c "[[ \$(curl -q -s -o /dev/null -w '%{http_code}' -H 'Origin: https://evil.example' -d 'name=peter&password=a different long password' 'http://127.0.0.1:$SIGN/account/signin.php') == 403 ]]"
  NEW="$(sed -n 's/^Set-Cookie: __Host-offline-session=\([A-Za-z0-9_-]\{43\}\);.*/\1/Ip' "$WORK/signin.headers" | head -n 1)"
  check "signing out needs the form, not just a visit" bash -c "[[ \$(curl -q -s -o /dev/null -w '%{http_code}' -H 'Cookie: __Host-offline-session=$NEW' -d 'proof=guess' 'http://127.0.0.1:$SIGN/account/signout.php') == 403 ]] && grep -q '^$NEW ' '$A/run/offline-auth/sessions.map'"
  check "signing out ends the session" bash -c "proof=\$(curl -q -s -H 'Cookie: __Host-offline-session=$NEW' 'http://127.0.0.1:$SIGN/account/signout.php' | sed -n 's/.*name=\"proof\" value=\"\([0-9a-f]\{64\}\)\".*/\1/p'); curl -q -s -o /dev/null -H 'Cookie: __Host-offline-session=$NEW' -d \"proof=\$proof\" 'http://127.0.0.1:$SIGN/account/signout.php'; ! grep -q '^$NEW ' '$A/run/offline-auth/sessions.map'"
  refute "no PHP error was logged by the sign-in pages" grep -Eq 'Warning|Notice|Fatal|Deprecated' "$WORK/php-auth.log"
else
  note "curl not installed: portal checks not run"
fi

echo
echo "$PASSED passed, $FAILED failed."
((FAILED == 0))
