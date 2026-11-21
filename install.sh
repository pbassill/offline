#!/usr/bin/env bash
#
# Offline installer for Ubuntu.
#
# Turns a laptop into a self-contained offline library: reference works, media,
# maps, a local AI model, a web portal, family broadcasts, and automatic exchange
# of content with the household's other systems. With --role server it sets up a
# central server instead: the same sharing, a download queue and a staging area,
# and no desktop. Safe to run again at any time; a second run upgrades the tools
# and leaves content, settings and the house key alone.
#
#   sudo ./install.sh --help

set -Eeuo pipefail
IFS=$'\n\t'
umask 022

readonly OFFLINE_VERSION="2.5.0"
readonly SHARE_ROOT="/srv/offline"
readonly STATE_DIR="/var/lib/offline"
readonly CONF_DIR="/etc/offline"
readonly LIB_DIR="/usr/local/lib/offline"
readonly WEB_DIR="/var/www/offline"
readonly LOG_DIR="/var/log/offline"
readonly LOG_FILE="$LOG_DIR/install.log"
readonly LAN_NETS=(10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 169.254.0.0/16)
readonly OLLAMA_INSTALLER_URL="https://ollama.com/install.sh"
readonly MSTY_DEB_URL="https://next-assets.msty.studio/app/latest/linux/MstyStudio_amd64.deb"
# go-pmtiles cuts map regions out of the Protomaps world build. Upstream publishes no
# checksum file, so the release is pinned here; both values were checked against a
# fresh download of the release and match those Project NOMAD pins.
readonly PMTILES_VERSION="1.30.2"
readonly PMTILES_SHA256_AMD64="2cd3aa18868297fc88425038f794efdc0995e0275f4ca16fa496dd79e245a40c"
readonly PMTILES_SHA256_ARM64="804cdf071834e1156af554c1a26cc42b56b9cde5a2db9c6e3653d16fb846d5fa"
readonly MESHTASTIC_CLI_VERSION="2.7.11"
# Radio packages are installed one by one, because not every Ubuntu release carries all of them.
readonly RADIO_PACKAGES=(rtl-sdr soapysdr-tools soapysdr-module-rtlsdr gqrx-sdr cubicsdr welle.io rtl-433
  dump1090-mutability multimon-ng direwolf fldigi gpredict chirp esptool hackrf airspy sox alsa-utils)
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR

OPT_UPGRADE=yes
# Empty means "decide by role": a workstation gets these, a server does not.
OPT_AI=""
OPT_MSTY=""
OPT_JDOWNLOADER=""
OPT_APPS=""
OPT_RADIO=""
OPT_DRY_RUN=no
OPT_SCOPE=""
OPT_DESKTOP=""
OPT_ENDPOINT=""
OPT_MESH=""
OPT_KEY=""
OPT_PACKS=""
OPT_MODELS=""
OPT_MAPS=""
OPT_ROLE=""
OPT_EXPOSE=""
OPT_SERVERS=()
OPT_GAMES=""
OPT_HOST_GAMES=""
OPT_HOTSPOT=no
OPT_APT_CACHE=""

STEPS_OK=()
STEPS_FAILED=()
STEPS_SKIPPED=()

usage() {
  cat <<EOF
Offline installer $OFFLINE_VERSION

Usage: sudo ./install.sh [options]

  --role ROLE          workstation (default): a laptop or desktop that adults
                       and children sit at
                       server: a central system that downloads, stages and
                       serves content to the others, with everything installed
                       repeater: a small system with no screen that holds the
                       household's private network together over whatever
                       links it has, and holds no content
  --endpoint HOST:PORT where other systems can call this one from outside (a
                       server in a datacentre, or a home system behind a
                       forwarded UDP port); they connect to it over WireGuard
  --no-mesh            leave out the radio layer of the private network (Reticulum)
  --headless           a server with no screen: leave out the desktop programs
  --desktop            a server that people also sit at (the default where a
                       desktop is already installed)
  --server HOST[:PORT] pull from this central server; repeat for several, for
                       example one at home and one in a datacentre
  --expose internet    on a server, accept other systems from any address rather
                       than private ones only; they must still hold the house key
  --packs "A B"        download these content packs at the end (see: offline catalogue)
  --maps "A B"         download these map regions at the end (see: offline maps)
  --apps "A B"         applications to install: names, "all" (the whole Project
                       NOMAD set; the default) or "none" (see: offline apps)
  --no-games           skip the children's games
  --host-games         host the family Luanti worlds and the browser activities here
                       (default on a server on the home network)
  --hotspot            make this system a Wi-Fi access point of its own, for when
                       the router is gone (needs a Wi-Fi adapter)
  --apt-cache HOST     fetch Ubuntu packages through the household's package cache
                       on HOST; this is how a system is rebuilt with no internet
  --no-radio           skip the radio tools (SDR, Meshtastic, handset programming)
  --key KEY            join an existing household by entering its house key
                       (or leave it out and run "sudo offline key set" afterwards,
                       which keeps the key off the command line)
  --models "A B"       Ollama models to pull (default: from offline.conf, qwen2.5:0.5b)
  --local-only         portal, reader and media reachable from this system only
  --lan                portal, reader and media reachable from the home network (default)
  --no-upgrade         do not upgrade already-installed packages
  --no-ai              skip Ollama and Msty Studio
  --no-msty            skip Msty Studio
  --no-jdownloader     skip JDownloader
  --dry-run            list every step without changing anything
  -h, --help           show this help

Everything is logged to $LOG_FILE; follow a long step with
  sudo tail -f $LOG_FILE
EOF
}

# --------------------------------------------------------------------------
# Reporting
# --------------------------------------------------------------------------

if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then
  C_BOLD=$'\033[1m'; C_DIM=$'\033[2m'; C_RED=$'\033[31m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_OFF=$'\033[0m'
else
  C_BOLD=""; C_DIM=""; C_RED=""; C_GREEN=""; C_YELLOW=""; C_OFF=""
fi

stamp() { date -u +'%Y-%m-%dT%H:%M:%SZ'; }

log() {
  if [[ -w "$LOG_FILE" ]]; then
    printf '%s %s\n' "$(stamp)" "$*" >>"$LOG_FILE"
  fi
}

say() { printf '%s\n' "$*"; log "$*"; }
section() { printf '\n%s%s%s\n' "$C_BOLD" "$*" "$C_OFF"; log "== $*"; }
die() { printf '%serror:%s %s\n' "$C_RED" "$C_OFF" "$*" >&2; log "FATAL $*"; exit 1; }

# Run one step. Output goes to the terminal and to the log.
#   step  DESCRIPTION COMMAND...   a failure is recorded and the installer carries on
#   must  DESCRIPTION COMMAND...   a failure stops the installer
run_logged() {
  local description="$1"
  shift
  if [[ "$OPT_DRY_RUN" == yes ]]; then
    printf '  %swould:%s %s\n' "$C_DIM" "$C_OFF" "$description"
    return 0
  fi
  printf '  %s ... ' "$description"
  log "BEGIN $description"
  local status=0 mark
  mark="$(wc -l <"$LOG_FILE")"
  bash "$SCRIPT_DIR/install.sh" --internal-step "$@" >>"$LOG_FILE" 2>&1 </dev/null || status=$?
  if ((status == 0)); then
    printf '%sdone%s\n' "$C_GREEN" "$C_OFF"
    log "OK $description"
  else
    printf '%sfailed%s (exit %d)\n' "$C_RED" "$C_OFF" "$status"
    tail -n "+$((mark + 1))" "$LOG_FILE" | tail -n 8 | sed 's/^/      /'
    log "FAILED($status) $description"
  fi
  return "$status"
}

step() {
  if run_logged "$@"; then
    STEPS_OK+=("$1")
  else
    STEPS_FAILED+=("$1")
  fi
}

must() {
  if run_logged "$@"; then
    STEPS_OK+=("$1")
  else
    die "\"$1\" is essential and did not succeed. See $LOG_FILE for the full output."
  fi
}

skip() {
  printf '  %sskipped:%s %s\n' "$C_DIM" "$C_OFF" "$*"
  log "SKIPPED $*"
  STEPS_SKIPPED+=("$*")
}

# Read one setting from offline.conf without executing the file.
conf_get() {
  local key="$1" file="${2:-$CONF_DIR/offline.conf}" line value=""
  [[ -r "$file" ]] || return 0
  while IFS= read -r line; do
    if [[ "$line" =~ ^[[:space:]]*${key}[[:space:]]*=[[:space:]]*(.*)$ ]]; then
      value="${BASH_REMATCH[1]}"
      value="${value%"${value##*[![:space:]]}"}"
      value="${value#\"}"; value="${value%\"}"
      value="${value#\'}"; value="${value%\'}"
    fi
  done <"$file"
  printf '%s' "$value"
}

# Set one KEY=value line in offline.conf, replacing it if present. Values are
# checked by the caller; nothing here is ever executed.
conf_set() {
  local key="$1" value="$2" file="$CONF_DIR/offline.conf"
  if grep -q "^${key}=" "$file"; then
    sed -i "s|^${key}=.*|${key}=\"${value}\"|" "$file"
  else
    printf '\n%s="%s"\n' "$key" "$value" >>"$file"
  fi
}

# --------------------------------------------------------------------------
# Steps
# --------------------------------------------------------------------------

apt_install() {
  apt-get install -y --no-install-recommends "$@"
}

install_alert_helpers() {
  apt_install zenity libnotify-bin
  # The alert sound is a nicety, and its package is not in every release.
  apt-get install -y --no-install-recommends gnome-session-canberra || echo "No alert sound: gnome-session-canberra is not available."
}

create_accounts() {
  if ! getent passwd offline >/dev/null; then
    adduser --system --group --home "$STATE_DIR" --no-create-home --shell /usr/sbin/nologin offline
  fi
  if ! getent passwd offline-web >/dev/null; then
    adduser --system --group --home /nonexistent --no-create-home --shell /usr/sbin/nologin offline-web
  fi
  # The sign-in pages run as an account of their own, which alone may open a
  # portal session. The web server and the portal pages read sessions through
  # the offline-session group.
  getent group offline-session >/dev/null || addgroup --system offline-session
  if ! getent passwd offline-auth >/dev/null; then
    adduser --system --group --home /nonexistent --no-create-home --shell /usr/sbin/nologin offline-auth
  fi
  usermod -aG offline-session offline-auth
  usermod -aG offline-session www-data
  usermod -aG offline-session offline-web
  # Every child's login account has this group and no other; the children's
  # guard, the content folders and the SSH daemon all recognise it.
  getent group offline-child >/dev/null || addgroup --system offline-child
  # The desktop user may add and remove content; nothing more.
  usermod -aG offline "$TARGET_USER"
}

create_directories() {
  install -d -m 0755 -o root -g root "$CONF_DIR" "$LIB_DIR"
  install -d -m 0755 -o offline -g offline "$STATE_DIR" "$SHARE_ROOT"
  local dir
  for dir in knowledge knowledge/zim knowledge/zim/.incoming media media/movies media/tv media/music media/radio media/books \
    maps radio radio/firmware; do
    install -d -m 2775 -o offline -g offline "$SHARE_ROOT/$dir"
  done
  # What is put in this folder can be watched by everyone in the house without signing in.
  install -d -m 2775 -o offline -g offline "$SHARE_ROOT/media/children"
  # Downloads wait here until published. Not shared, and closed to other accounts.
  install -d -m 2770 -o offline -g offline "$SHARE_ROOT/staging"
  # Family broadcasts: signed ones kept and offered to other systems, those pushed
  # here by other systems, and requests from people on this machine. Members of
  # the offline group may drop a request in the outbox but not list or remove
  # one another's.
  # The family vault: ciphertext only. Its group may work in it whatever a program's
  # umask, which is what lets one adult open what another saved.
  install -d -m 2770 -o offline -g offline "$SHARE_ROOT/vault"
  if command -v setfacl >/dev/null 2>&1; then
    setfacl -m d:g:offline:rwx,d:m::rwx,g:offline:rwx "$SHARE_ROOT/vault" || true
  fi
  # Installer images for rebuilding a system.
  install -d -m 2775 -o offline -g offline "$SHARE_ROOT/rebuild"
  install -d -m 0750 -o offline -g offline "$STATE_DIR/broadcasts" "$STATE_DIR/inbox"
  install -d -m 3730 -o offline -g offline "$STATE_DIR/outbox"
  # Application data belongs to the applications' own user ranges, not to the sharing account.
  install -d -m 0755 -o root -g root "$SHARE_ROOT/apps"
  install -d -m 0700 -o root -g root "$CONF_DIR/apps"
  # Account records: readable by the sharing account, which offers them to the
  # household's other systems, and by the sign-in pages. Nobody else: not the
  # web server, and not the ordinary portal pages.
  install -d -m 2750 -o offline -g offline-auth "$STATE_DIR/accounts"
  # The list of the household's systems, and Reticulum's own state.
  install -d -m 0755 -o offline -g offline "$STATE_DIR/network"
  install -d -m 0750 -o offline -g offline "$STATE_DIR/reticulum"
  # A child's login account uses the portal like any tablet does, and has no
  # business in the folders themselves: the library and the films are closed to
  # the children's group by name, whatever the folders allow everyone else.
  if command -v setfacl >/dev/null 2>&1; then
    setfacl -m g:offline-child:---,m::rwx "$SHARE_ROOT/knowledge" "$SHARE_ROOT/media" "$SHARE_ROOT/radio" "$SHARE_ROOT/rebuild" || true
  fi
  install -m 0644 "$SCRIPT_DIR/config/tmpfiles-offline.conf" /etc/tmpfiles.d/offline.conf
  systemd-tmpfiles --create /etc/tmpfiles.d/offline.conf
}

# Earlier versions kept ZIM files in the user's home and served them as root
# through a symbolic link. Move them into the shared folder and point the
# familiar Documents/Kiwix-zims path at it instead.
migrate_layout() {
  local zim_dir="$SHARE_ROOT/knowledge/zim" home_dir="$TARGET_HOME/Documents/Kiwix-zims" file
  if [[ -L "$SHARE_ROOT/knowledge/kiwix-zims" ]]; then
    rm -f -- "$SHARE_ROOT/knowledge/kiwix-zims"
  fi
  install -d -m 0755 -o "$TARGET_USER" -g "$TARGET_USER" "$TARGET_HOME/Documents"
  if [[ -d "$home_dir" && ! -L "$home_dir" ]]; then
    while IFS= read -r -d '' file; do
      mv -n -- "$file" "$zim_dir/"
    done < <(find "$home_dir" -maxdepth 1 -type f -name '*.zim' -print0)
    if ! rmdir -- "$home_dir" 2>/dev/null; then
      mv -- "$home_dir" "$home_dir.before-offline-$(date -u +%Y%m%d%H%M%S)"
    fi
  fi
  if [[ ! -e "$home_dir" ]]; then
    ln -s -- "$zim_dir" "$home_dir"
    chown -h "$TARGET_USER:$TARGET_USER" "$home_dir"
  fi
  # Content received by earlier versions is owned by root; hand it over.
  local area
  for area in knowledge media maps radio; do
    chown -R -h offline:offline "$SHARE_ROOT/$area"
    find "$SHARE_ROOT/$area" -type d -exec chmod 2775 {} +
    find "$SHARE_ROOT/$area" -type f -exec chmod u+rw,g+rw,o+r,a-x {} +
  done
  chown offline:offline "$SHARE_ROOT"
  rm -f -- /usr/local/bin/offline-peer-sync /etc/offline-rsyncd.conf /run/offline-rsyncd.pid /run/offline-rsyncd.lock
}

install_tools() {
  install -m 0755 -o root -g root "$SCRIPT_DIR/bin/offline" /usr/local/bin/offline
  install -m 0644 -o root -g root "$SCRIPT_DIR"/lib/*.php "$SCRIPT_DIR/config/catalogue.tsv" "$SCRIPT_DIR/config/maps.tsv" \
    "$SCRIPT_DIR/config/sites.tsv" "$SCRIPT_DIR/config/packages.list" "$SCRIPT_DIR/config/offline-routing.container" \
    "$SCRIPT_DIR/config/children.tsv" "$SCRIPT_DIR/config/children-guard.nft" "$LIB_DIR/"
  install -m 0755 -o root -g root "$SCRIPT_DIR/config/apt-proxy-detect" "$LIB_DIR/apt-proxy-detect"
  install -m 0755 -o root -g root "$SCRIPT_DIR/mesh/offline-mesh-bridge" "$LIB_DIR/offline-mesh-bridge"
  # Games: the list, the family-world units and their settings.
  rsync --recursive --delete --chmod=D755,F644 --chown=root:root "$SCRIPT_DIR/games/" "$LIB_DIR/games/"
  # The desktop alert and the dialog that sends a family broadcast.
  install -d -m 0755 -o root -g root "$LIB_DIR/desktop"
  install -m 0755 -o root -g root "$SCRIPT_DIR/desktop/offline-alert-agent" "$SCRIPT_DIR/desktop/offline-broadcast-send" "$LIB_DIR/desktop/"
  install -m 0644 -o root -g root "$SCRIPT_DIR/config/stunnel.conf" "$CONF_DIR/stunnel.conf"
  # Application units, reference data and radio channel files.
  rsync --recursive --delete --chmod=D755,F644 --chown=root:root "$SCRIPT_DIR/apps/" "$LIB_DIR/apps/"
  rsync --recursive --delete --chmod=D755,F644 --chown=root:root "$SCRIPT_DIR/data/" "$LIB_DIR/data/"
  install -d -m 0755 /usr/local/share/offline
  rsync --recursive --delete --chmod=D755,F644 --chown=root:root "$SCRIPT_DIR/config/radio/" /usr/local/share/offline/radio/
  install -m 0644 -o root -g root "$SCRIPT_DIR/config/rsyncd.conf" "$CONF_DIR/rsyncd.conf"
  if [[ ! -e "$CONF_DIR/offline.conf" ]]; then
    install -m 0644 -o root -g root "$SCRIPT_DIR/config/offline.conf" "$CONF_DIR/offline.conf"
  else
    install -m 0644 -o root -g root "$SCRIPT_DIR/config/offline.conf" "$CONF_DIR/offline.conf.dist"
  fi
  conf_set PORTAL_SCOPE "$SCOPE"
  conf_set ROLE "$ROLE"
  if [[ "$ROLE" == repeater ]]; then
    # A repeater exchanges accounts, the list of systems and broadcasts, and no content.
    conf_set SYNC_MODULES ""
    conf_set VAULT_SYNC no
  fi
  conf_set SYNC_EXPOSE "$EXPOSE"
  if [[ "$EXPOSE" == internet ]]; then
    # A system in a datacentre must not go looking through its neighbours' addresses.
    conf_set SYNC_SCAN_FALLBACK no
  fi
  if [[ ! -e "$CONF_DIR/children.local.tsv" ]]; then
    printf '# Your own additions to the children'"'"'s shelf: the start of a ZIM file name, a tab, a note.\n# Run "sudo offline library" after changing this file.\n' >"$CONF_DIR/children.local.tsv"
    chmod 0644 "$CONF_DIR/children.local.tsv"
  fi
  if [[ ! -e "$CONF_DIR/sites.local.tsv" ]]; then
    printf '# Your own websites to archive. Columns separated by single tabs:\n# id\tgroup\taddress\tZIM name\ttitle\tpage limit\tnote\n' >"$CONF_DIR/sites.local.tsv"
    chmod 0644 "$CONF_DIR/sites.local.tsv"
  fi
  if [[ ! -e "$CONF_DIR/maps.local.tsv" ]]; then
    printf '# Your own map regions. Columns separated by single tabs:\n# id\tgroup\tname\tbbox\twest,south,east,north\n' >"$CONF_DIR/maps.local.tsv"
    chmod 0644 "$CONF_DIR/maps.local.tsv"
  fi
  if [[ ! -e "$CONF_DIR/catalogue.local.tsv" ]]; then
    printf '# Your own catalogue entries. Columns separated by single tabs:\n# pack\tZIM file base\ttitle\tdescription\n' >"$CONF_DIR/catalogue.local.tsv"
    chmod 0644 "$CONF_DIR/catalogue.local.tsv"
  fi
}

install_portal() {
  rsync --recursive --delete --chmod=D755,F644 --chown=root:root "$SCRIPT_DIR/portal/" "$WEB_DIR/"
}

install_units() {
  local unit
  for unit in "$SCRIPT_DIR"/systemd/offline-*; do
    install -m 0644 -o root -g root "$unit" "/etc/systemd/system/$(basename "$unit")"
  done
  install -d -m 0755 /etc/systemd/system/offline-reader.service.d
  if [[ "$SCOPE" == local ]]; then
    cat >/etc/systemd/system/offline-reader.service.d/scope.conf <<'EOF'
# Written by the Offline installer: PORTAL_SCOPE=local.
[Service]
ExecStart=
ExecStart=/usr/bin/kiwix-serve --address=127.0.0.1 --port=8080 --monitorLibrary --blockexternal --library /var/lib/offline/library-children.xml
IPAddressAllow=
IPAddressAllow=localhost
EOF
  else
    rm -f /etc/systemd/system/offline-reader.service.d/scope.conf
  fi
  # The encrypted listener answers private addresses only, unless this is a
  # server meant to be reached from elsewhere. The house key is required either way.
  install -d -m 0755 /etc/systemd/system/offline-tls.service.d
  if [[ "$EXPOSE" == internet ]]; then
    cat >/etc/systemd/system/offline-tls.service.d/expose.conf <<'EOF'
# Written by the Offline installer: SYNC_EXPOSE=internet.
[Service]
IPAddressAllow=
IPAddressAllow=any
EOF
  else
    rm -f /etc/systemd/system/offline-tls.service.d/expose.conf
  fi
  systemctl daemon-reload
}

name_servers() {
  local server
  for server in "$@"; do
    /usr/local/bin/offline server add "$server"
  done
}

# Where this system asks the time and looks for packages: the household's own
# servers first, wherever they have been named, now or on an earlier run.
household_services() {
  /usr/local/bin/offline time sources
  /usr/local/bin/offline rebuild clients
}

# What a server on the home network offers the rest of the household.
serve_household() {
  /usr/local/bin/offline time serve on
  /usr/local/bin/offline rebuild cache on
}

set_house_key() {
  # The key travels on standard input and its output is discarded, so it never
  # appears in a process list or in the log.
  if [[ -n "${OFFLINE_INSTALL_KEY:-}" ]]; then
    printf '%s\n' "$OFFLINE_INSTALL_KEY" | /usr/local/bin/offline key set - >/dev/null
  elif [[ ! -s "$CONF_DIR/house.key" ]]; then
    /usr/local/bin/offline key new >/dev/null
  else
    # An existing key from an earlier version has no transport key beside it yet.
    /usr/local/bin/offline key refresh >/dev/null
  fi
  [[ -s "$CONF_DIR/house.key" && -s "$CONF_DIR/rsyncd.secrets" && -s "$CONF_DIR/sync.psk" ]]
}

configure_firewall() {
  local net ssh_port
  ufw default deny incoming
  ufw default allow outgoing
  # Rule left by earlier versions: the reader port open to every address.
  ufw delete allow 8080/tcp >/dev/null 2>&1 || true
  # Earlier versions shared over port 47001 unencrypted. That port now answers
  # this machine only; the encrypted port in front of it is 47002.
  for net in "${LAN_NETS[@]}"; do
    ufw delete allow from "$net" to any port 47001 proto tcp >/dev/null 2>&1 || true
  done
  if [[ "$EXPOSE" == internet ]]; then
    for net in "${LAN_NETS[@]}"; do
      ufw delete allow from "$net" to any port 47002 proto tcp >/dev/null 2>&1 || true
    done
    ufw allow 47002/tcp comment 'Offline sharing, encrypted and keyed'
  else
    ufw delete allow 47002/tcp >/dev/null 2>&1 || true
  fi
  for net in "${LAN_NETS[@]}"; do
    if [[ "$EXPOSE" != internet ]]; then
      ufw allow from "$net" to any port 47002 proto tcp comment 'Offline sharing, encrypted and keyed'
    fi
    if [[ "$SCOPE" == lan ]]; then
      ufw allow from "$net" to any port 80 proto tcp comment 'Offline portal'
      ufw allow from "$net" to any port 443 proto tcp comment 'Offline portal, encrypted, with sign-in'
      ufw allow from "$net" to any port 8080 proto tcp comment 'Offline children'"'"'s shelf'
      ufw allow from "$net" to any port 8443 proto tcp comment 'Offline library for adults who have signed in'
    else
      ufw delete allow from "$net" to any port 80 proto tcp >/dev/null 2>&1 || true
      ufw delete allow from "$net" to any port 443 proto tcp >/dev/null 2>&1 || true
      ufw delete allow from "$net" to any port 8080 proto tcp >/dev/null 2>&1 || true
      ufw delete allow from "$net" to any port 8443 proto tcp >/dev/null 2>&1 || true
    fi
  done
  # Do not lock out an administrator who manages this system over SSH. A server
  # exposed to the internet is administered from the internet, so SSH stays
  # reachable there, rate-limited; everywhere else it is the home network only.
  if systemctl is-active --quiet ssh 2>/dev/null || systemctl is-active --quiet ssh.socket 2>/dev/null; then
    ssh_port="$(sshd -T 2>/dev/null | awk '$1 == "port" { print $2; exit }')" || ssh_port=""
    [[ "$ssh_port" =~ ^[0-9]{1,5}$ ]] || ssh_port=22
    if [[ "$EXPOSE" == internet ]]; then
      ufw limit "$ssh_port/tcp" comment 'SSH, rate-limited'
    else
      # A rule left from a time when this system was exposed to the internet.
      ufw delete limit "$ssh_port/tcp" >/dev/null 2>&1 || true
      for net in "${LAN_NETS[@]}"; do
        ufw limit from "$net" to any port "$ssh_port" proto tcp comment 'SSH from the home network'
      done
    fi
  fi
  ufw logging low
  ufw --force enable
}

configure_discovery() {
  install -d -m 0755 /etc/avahi/services
  install -m 0644 -o root -g root "$SCRIPT_DIR/config/avahi-offline.service" /etc/avahi/services/offline.service
  systemctl enable --now avahi-daemon.service
  systemctl reload avahi-daemon.service || systemctl restart avahi-daemon.service
}

withdraw_discovery() {
  rm -f /etc/avahi/services/offline.service
  systemctl reload avahi-daemon.service 2>/dev/null || true
}

configure_webserver() {
  local php_version pool_dir
  php_version="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
  [[ "$php_version" =~ ^[0-9]+\.[0-9]+$ ]] || { echo "Could not determine the PHP version"; return 1; }
  pool_dir="/etc/php/$php_version/fpm/pool.d"
  [[ -d "$pool_dir" ]] || { echo "PHP-FPM $php_version is not installed ($pool_dir is missing)"; return 1; }

  install -m 0644 -o root -g root "$SCRIPT_DIR/config/php-fpm-offline-portal.conf" "$pool_dir/offline-portal.conf"
  install -m 0644 -o root -g root "$SCRIPT_DIR/config/php-fpm-offline-auth.conf" "$pool_dir/offline-auth.conf"
  install -m 0644 -o root -g root "$SCRIPT_DIR/config/apache-offline-portal.conf" /etc/apache2/sites-available/offline-portal.conf
  install -d -m 0755 -o root -g root /etc/apache2/offline
  install -m 0644 -o root -g root "$SCRIPT_DIR/config/apache-offline-portal-common.conf" /etc/apache2/offline/portal-common.conf
  install -m 0644 -o root -g root "$SCRIPT_DIR/config/apache-offline-session-gate.conf" /etc/apache2/offline/session-gate.conf
  # The encrypted site needs this system's certificate, signed by the household's authority.
  /usr/local/bin/offline tls renew >/dev/null || { echo "This system's certificate could not be made"; return 1; }
  install -m 0644 -o root -g root "$SCRIPT_DIR/config/apache-offline-hardening.conf" /etc/apache2/conf-available/zz-offline-hardening.conf
  if [[ "$SCOPE" == lan ]]; then
    printf '# Written by the Offline installer: PORTAL_SCOPE=lan.\nDefine OFFLINE_LAN\n' >/etc/apache2/conf-available/offline-scope.conf
  else
    printf '# Written by the Offline installer: PORTAL_SCOPE=local.\n' >/etc/apache2/conf-available/offline-scope.conf
  fi

  a2enmod -q headers proxy proxy_fcgi proxy_http setenvif ssl rewrite
  a2dismod -q -f status autoindex cgi cgid 2>/dev/null || true
  a2dissite -q 000-default 2>/dev/null || true
  a2enconf -q zz-offline-hardening offline-scope
  a2ensite -q offline-portal

  "php-fpm$php_version" -t
  if ! apache2ctl configtest; then
    a2dissite -q offline-portal
    echo "Apache rejected the configuration; the portal site has been disabled again."
    return 1
  fi
  systemctl enable "php$php_version-fpm.service" apache2.service
  systemctl restart "php$php_version-fpm.service" apache2.service
}

start_services() {
  # The reader needs an index to start; build an empty one on first install.
  /usr/local/bin/offline library
  systemctl enable offline-rsync.service offline-tls.service offline-reader.service offline-reader-adults.service offline-peer-sync.timer \
    offline-status.timer offline-library.path offline-broadcast.path offline-broadcast-poll.timer offline-sessions.timer \
    offline-tls-renew.timer offline-accounts.path offline-accounts.service offline-children-guard.service \
    offline-net.path offline-net.service
  systemctl restart offline-rsync.service offline-tls.service offline-reader.service offline-reader-adults.service
  systemctl restart offline-peer-sync.timer offline-status.timer offline-library.path offline-broadcast.path offline-broadcast-poll.timer \
    offline-sessions.timer offline-tls-renew.timer offline-accounts.path offline-net.path
  # The children's guard confines child accounts whether or not any exist yet.
  systemctl restart offline-children-guard.service
  # Children do not log in over the network, on any system.
  if [[ -d /etc/ssh/sshd_config.d ]]; then
    printf '%s\n' '# Written by the Offline installer: child accounts log in at a screen, never over SSH.' 'DenyGroups offline-child' \
      >/etc/ssh/sshd_config.d/60-offline-children.conf
    chmod 0644 /etc/ssh/sshd_config.d/60-offline-children.conf
    if sshd -t 2>/dev/null; then
      systemctl reload ssh.service 2>/dev/null || true
    else
      rm -f /etc/ssh/sshd_config.d/60-offline-children.conf
      echo "The SSH daemon rejected the rule that keeps child accounts out; it was withdrawn."
    fi
  fi
  # Accounts made on other systems, or before this upgrade.
  /usr/local/bin/offline user apply --replicated || true
  # Only a server works through a download queue unattended.
  if [[ "$ROLE" == server ]]; then
    systemctl enable --now offline-queue.timer offline-archive.timer
  else
    systemctl disable --now offline-queue.timer offline-archive.timer >/dev/null 2>&1 || true
  fi
  systemctl start offline-status.service
}

install_pmtiles() {
  local arch_name expected tmp tarball
  case "$ARCH" in
    amd64) arch_name=x86_64; expected="$PMTILES_SHA256_AMD64" ;;
    arm64) arch_name=arm64; expected="$PMTILES_SHA256_ARM64" ;;
    *) echo "No pmtiles build is pinned for $ARCH"; return 1 ;;
  esac
  if /usr/local/bin/pmtiles version 2>/dev/null | grep -q "pmtiles $PMTILES_VERSION,"; then
    echo "pmtiles $PMTILES_VERSION is already installed"
    return 0
  fi
  tmp="$(mktemp -d)"
  tarball="go-pmtiles_${PMTILES_VERSION}_Linux_${arch_name}.tar.gz"
  curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --tlsv1.2 --max-time 300 \
    --output "$tmp/$tarball" "https://github.com/protomaps/go-pmtiles/releases/download/v${PMTILES_VERSION}/${tarball}"
  echo "$expected  $tmp/$tarball" | sha256sum --check --strict -
  tar -xzf "$tmp/$tarball" -C "$tmp" pmtiles
  install -m 0755 -o root -g root "$tmp/pmtiles" /usr/local/bin/pmtiles
  rm -rf -- "$tmp"
  /usr/local/bin/pmtiles version
}

install_radio_packages() {
  local package missing=()
  for package in "${RADIO_PACKAGES[@]}"; do
    if apt-cache show "$package" >/dev/null 2>&1; then
      apt-get install -y --no-install-recommends "$package" || missing+=("$package")
    else
      missing+=("$package")
    fi
  done
  if ((${#missing[@]} > 0)); then
    echo "Not available on this Ubuntu release, or failed to install: ${missing[*]}"
  fi
  # At least the receiver tools must be present for the step to count.
  command -v rtl_fm >/dev/null
}

configure_radio_access() {
  local group gqrx="$TARGET_HOME/.config/gqrx"
  # The kernel's television driver claims RTL-SDR dongles before the receiver tools can.
  install -d -m 0755 /etc/modprobe.d
  printf '%s\n' '# Written by the Offline installer: leave RTL2832U dongles to the SDR tools.' \
    'blacklist dvb_usb_rtl28xxu' 'blacklist rtl2832' 'blacklist rtl2830' >/etc/modprobe.d/offline-rtlsdr.conf
  modprobe -r dvb_usb_rtl28xxu 2>/dev/null || true
  # Serial ports (mesh nodes, programming cables) and USB receivers, for the desktop user.
  for group in dialout plugdev; do
    if getent group "$group" >/dev/null; then
      usermod -aG "$group" "$TARGET_USER"
    fi
  done
  # Gqrx bookmarks, only where the user has none yet.
  if [[ ! -e "$gqrx/bookmarks.csv" ]]; then
    install -d -m 0755 -o "$TARGET_USER" -g "$TARGET_USER" "$TARGET_HOME/.config" "$gqrx"
    install -m 0644 -o "$TARGET_USER" -g "$TARGET_USER" /usr/local/share/offline/radio/gqrx-bookmarks.csv "$gqrx/bookmarks.csv"
  fi
}

install_meshtastic_cli() {
  if /usr/local/bin/meshtastic --version 2>/dev/null | grep -q "$MESHTASTIC_CLI_VERSION"; then
    echo "The Meshtastic command $MESHTASTIC_CLI_VERSION is already installed"
    return 0
  fi
  # Its own virtual environment under /opt, with the command linked into /usr/local/bin.
  PIPX_HOME=/opt/pipx PIPX_BIN_DIR=/usr/local/bin PIPX_MAN_DIR=/usr/local/share/man \
    pipx install --force "meshtastic==$MESHTASTIC_CLI_VERSION"
  /usr/local/bin/meshtastic --version
}

install_ollama() {
  if command -v ollama >/dev/null 2>&1; then
    echo "Ollama is already installed: $(ollama --version 2>/dev/null | head -n 1)"
    return 0
  fi
  local tmp
  tmp="$(mktemp -d)"
  # Downloaded over verified TLS and run from a file, never piped into a shell.
  curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --tlsv1.2 \
    --max-time 120 --output "$tmp/ollama-install.sh" "$OLLAMA_INSTALLER_URL"
  sh "$tmp/ollama-install.sh"
  rm -rf -- "$tmp"
}

pull_models() {
  local models="$1" model tries=0 failed=0
  until curl --silent --fail --max-time 2 http://127.0.0.1:11434/api/version >/dev/null 2>&1; do
    ((++tries > 30)) && { echo "Ollama did not start answering on 127.0.0.1:11434"; return 1; }
    sleep 1
  done
  while IFS= read -r model; do
    [[ -n "$model" ]] || continue
    if [[ ! "$model" =~ ^[A-Za-z0-9._/-]+(:[A-Za-z0-9._-]+)?$ ]]; then
      echo "Ignoring model name with unexpected characters: $model"
      failed=1
      continue
    fi
    sudo -u "$TARGET_USER" -H ollama pull "$model" || failed=1
  done < <(tr ' ,' '\n' <<<"$models")
  return "$failed"
}

install_msty() {
  local tmp package
  tmp="$(mktemp -d)"
  curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --tlsv1.2 \
    --max-time 900 --output "$tmp/MstyStudio_amd64.deb" "$MSTY_DEB_URL"
  package="$(dpkg-deb --field "$tmp/MstyStudio_amd64.deb" Package)"
  if [[ ! "$package" =~ [Mm]sty ]]; then
    echo "The downloaded file is not the Msty package (it declares \"$package\"); not installing it."
    rm -rf -- "$tmp"
    return 1
  fi
  chmod 0755 "$tmp"
  chmod 0644 "$tmp/MstyStudio_amd64.deb"
  apt-get install -y "$tmp/MstyStudio_amd64.deb"
  rm -rf -- "$tmp"
}

install_jdownloader() {
  snap install core
  snap install jdownloader2
}

desktop_shortcuts() {
  local desktop="$TARGET_HOME/Desktop" launcher
  cat >/usr/share/applications/offline-portal.desktop <<'EOF'
[Desktop Entry]
Type=Application
Name=Offline Portal
Comment=Library, media and status of this system, with no internet
Exec=xdg-open http://localhost/
Icon=accessories-dictionary
Terminal=false
Categories=Education;Utility;
EOF
  chmod 0644 /usr/share/applications/offline-portal.desktop
  install -d -m 0755 -o "$TARGET_USER" -g "$TARGET_USER" "$desktop"
  launcher="$desktop/offline-portal.desktop"
  install -m 0755 -o "$TARGET_USER" -g "$TARGET_USER" /usr/share/applications/offline-portal.desktop "$launcher"
  sudo -u "$TARGET_USER" -H gio set "$launcher" metadata::trusted true 2>/dev/null || true
  # Family broadcast: a launcher to send one, and the agent that shows them at login.
  install -m 0644 -o root -g root "$SCRIPT_DIR/desktop/offline-broadcast.desktop" /usr/share/applications/offline-broadcast.desktop
  install -d -m 0755 /etc/xdg/autostart
  install -m 0644 -o root -g root "$SCRIPT_DIR/desktop/offline-alert.desktop" /etc/xdg/autostart/offline-alert.desktop
  launcher="$desktop/offline-broadcast.desktop"
  install -m 0755 -o "$TARGET_USER" -g "$TARGET_USER" /usr/share/applications/offline-broadcast.desktop "$launcher"
  sudo -u "$TARGET_USER" -H gio set "$launcher" metadata::trusted true 2>/dev/null || true
  rm -f -- "$desktop/Msty.desktop"
  cat >"$desktop/Quick Links.txt" <<'EOF'
OFFLINE: QUICK LINKS

Everything on this system:        http://localhost/
Library reader:                   http://localhost:8080/
Full guide:                       http://localhost/guide.php

In a terminal:
  offline status                  what is installed, running and shared
  offline doctor                  health checks, each with its fix
  offline catalogue               content packs that can be downloaded
  sudo offline get essential      download a pack (needs the internet)
  offline maps                    map regions; view them at http://localhost/maps.php
  offline apps                    browser applications; see http://localhost/apps.php
  offline radio                   receivers and mesh nodes; see http://localhost/radio.php
  offline broadcast "TEXT"        alert every screen and the mesh radios
  sudo offline key show           the house key to enter on the other systems

Library titles live in Documents/Kiwix-zims. Drop .zim files there and the
reader picks them up. Films, music and books live in /srv/offline/media.

While online, more titles are listed at https://library.kiwix.org and any
website can be turned into a title at https://zimit.kiwix.org.
EOF
  chown "$TARGET_USER:$TARGET_USER" "$desktop/Quick Links.txt"
}

# --------------------------------------------------------------------------
# Step re-entry
# --------------------------------------------------------------------------
# bash ignores "set -e" inside any function that is called from an "if" or
# "||", which is exactly how steps are run. Each step is therefore executed by
# a fresh copy of this script, where a failing command stops the step at once.

if [[ "${1:-}" == "--internal-step" ]]; then
  shift
  : "${TARGET_USER:?}" "${TARGET_HOME:?}" "${SCOPE:?}" "${ARCH:?}" "${ROLE:?}" "${EXPOSE:?}"
  trap 'echo "step stopped at line $LINENO: $BASH_COMMAND" >&2' ERR
  "$@"
  exit 0
fi

# --------------------------------------------------------------------------
# Options
# --------------------------------------------------------------------------

while (($# > 0)); do
  case "$1" in
    --packs) OPT_PACKS="${2:?--packs needs a value}"; shift 2 ;;
    --key) OPT_KEY="${2:?--key needs a value}"; shift 2 ;;
    --models) OPT_MODELS="${2:?--models needs a value}"; shift 2 ;;
    --maps) OPT_MAPS="${2:?--maps needs a value}"; shift 2 ;;
    --apps) OPT_APPS="${2:?--apps needs a value}"; shift 2 ;;
    --no-apps) OPT_APPS=none; shift ;;
    --no-radio) OPT_RADIO=no; shift ;;
    --with-radio) OPT_RADIO=yes; shift ;;
    --with-ai) OPT_AI=yes; shift ;;
    --no-games) OPT_GAMES=no; shift ;;
    --host-games) OPT_HOST_GAMES=yes; shift ;;
    --hotspot) OPT_HOTSPOT=yes; shift ;;
    --endpoint) OPT_ENDPOINT="${2:?--endpoint needs HOST:PORT}"; shift 2 ;;
    --no-mesh) OPT_MESH=no; shift ;;
    --headless) OPT_DESKTOP=no; shift ;;
    --desktop) OPT_DESKTOP=yes; shift ;;
    --apt-cache) OPT_APT_CACHE="${2:?--apt-cache needs a host}"; shift 2 ;;
    --role) OPT_ROLE="${2:?--role needs a value}"; shift 2 ;;
    --server) OPT_SERVERS+=("${2:?--server needs a value}"); shift 2 ;;
    --expose) OPT_EXPOSE="${2:?--expose needs a value}"; shift 2 ;;
    --local-only) OPT_SCOPE=local; shift ;;
    --lan) OPT_SCOPE=lan; shift ;;
    --no-upgrade) OPT_UPGRADE=no; shift ;;
    --no-ai) OPT_AI=no; shift ;;
    --no-msty) OPT_MSTY=no; shift ;;
    --no-jdownloader) OPT_JDOWNLOADER=no; shift ;;
    --dry-run) OPT_DRY_RUN=yes; shift ;;
    -h | --help) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage >&2; exit 2 ;;
  esac
done

# --------------------------------------------------------------------------
# Preflight
# --------------------------------------------------------------------------

if ((EUID != 0)) || [[ -z "${SUDO_USER:-}" || "$SUDO_USER" == "root" ]]; then
  echo "Please run this installer with sudo from your normal user account:  sudo ./install.sh" >&2
  exit 1
fi

readonly TARGET_USER="$SUDO_USER"
[[ "$TARGET_USER" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || { echo "Unexpected user name: $TARGET_USER" >&2; exit 1; }
TARGET_HOME="$(getent passwd "$TARGET_USER" | cut -d: -f6)"
readonly TARGET_HOME
[[ -n "$TARGET_HOME" && -d "$TARGET_HOME" ]] || { echo "Could not determine the home directory for $TARGET_USER" >&2; exit 1; }

for required in bin/offline lib/core.php lib/cli.php lib/maps.php lib/apps.php lib/radio.php lib/server.php lib/broadcast.php \
  lib/games.php lib/network.php lib/vault.php lib/rebuild.php games/games.tsv config/packages.list config/apt-proxy-detect \
  config/offline.conf config/catalogue.tsv config/maps.tsv config/sites.tsv config/rsyncd.conf config/stunnel.conf apps/apps.tsv \
  data/conditions.json portal/public/index.php systemd/offline-rsync.service systemd/offline-tls.service \
  systemd/offline-broadcast.service desktop/offline-alert-agent; do
  [[ -f "$SCRIPT_DIR/$required" ]] || { echo "Missing $required. Run the installer from a complete copy of the repository." >&2; exit 1; }
done

if [[ "$OPT_DRY_RUN" != yes ]]; then
  install -d -m 0750 -o root -g adm "$LOG_DIR"
  touch "$LOG_FILE"
  chmod 0640 "$LOG_FILE"
fi

# Read, never sourced: /etc/os-release is data, not a script.
OS_ID="$(conf_get ID /etc/os-release)"
OS_NAME="$(conf_get PRETTY_NAME /etc/os-release)"
[[ -n "$OS_ID" ]] || OS_ID="unknown"
[[ -n "$OS_NAME" ]] || OS_NAME="unknown"
ARCH="$(dpkg --print-architecture 2>/dev/null || echo unknown)"
readonly OS_ID OS_NAME ARCH

printf '%sOffline installer %s%s  %s\n' "$C_BOLD" "$OFFLINE_VERSION" "$C_OFF" "$(stamp)"
log "---- installer $OFFLINE_VERSION started by $TARGET_USER ----"
say "  System   $OS_NAME ($ARCH)"
say "  User     $TARGET_USER ($TARGET_HOME)"
say "  Free     $(df -h --output=avail "$(dirname "$SHARE_ROOT")" | tail -n 1 | tr -d ' ') available under $(dirname "$SHARE_ROOT")"
if [[ "$OS_ID" != ubuntu ]]; then
  say "  ${C_YELLOW}note:${C_OFF} written for Ubuntu; $OS_NAME is untested and some packages may differ."
fi

ONLINE=no
if curl --silent --head --max-time 8 --proto '=https' --tlsv1.2 https://archive.ubuntu.com/ >/dev/null 2>&1 ||
  getent hosts archive.ubuntu.com >/dev/null 2>&1; then
  ONLINE=yes
fi
readonly ONLINE
[[ -z "$OPT_APT_CACHE" || "$OPT_APT_CACHE" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$ ]] ||
  { echo "--apt-cache takes a host name or IPv4 address." >&2; exit 2; }
# Ubuntu packages can be had from the internet or, failing that, from the household's cache.
PACKAGES="$ONLINE"
if [[ -n "$OPT_APT_CACHE" ]]; then
  # shellcheck disable=SC2016  # the inner shell expands its own argument
  if timeout 3 bash -c 'exec 3<>"/dev/tcp/$0/3142"' "$OPT_APT_CACHE" 2>/dev/null; then
    PACKAGES=yes
  else
    echo "The package cache on $OPT_APT_CACHE does not answer on port 3142." >&2
    exit 1
  fi
fi
readonly PACKAGES
if [[ "$ONLINE" == yes ]]; then
  say "  Network  online$([[ -n "$OPT_APT_CACHE" ]] && echo ", packages through the cache on $OPT_APT_CACHE")"
elif [[ "$PACKAGES" == yes ]]; then
  say "  Network  offline: Ubuntu packages come from the cache on $OPT_APT_CACHE; other downloads are skipped"
else
  say "  Network  offline: packages and downloads will be skipped"
fi

# The scope chosen on the command line wins, then the existing setting, then "lan".
SCOPE="$OPT_SCOPE"
[[ -n "$SCOPE" ]] || SCOPE="$(conf_get PORTAL_SCOPE)"
[[ "$SCOPE" == local || "$SCOPE" == lan ]] || SCOPE=lan
# Role and exposure: the command line wins, then the existing setting, then the default.
ROLE="$OPT_ROLE"
[[ -n "$ROLE" ]] || ROLE="$(conf_get ROLE)"
[[ -n "$ROLE" ]] || ROLE=workstation
[[ "$ROLE" == workstation || "$ROLE" == server || "$ROLE" == repeater ]] || die "--role must be workstation, server or repeater, not \"$ROLE\"."
EXPOSE="$OPT_EXPOSE"
[[ -n "$EXPOSE" ]] || EXPOSE="$(conf_get SYNC_EXPOSE)"
[[ -n "$EXPOSE" ]] || EXPOSE=lan
[[ "$EXPOSE" == lan || "$EXPOSE" == internet ]] || die "--expose must be lan or internet, not \"$EXPOSE\"."
if [[ "$EXPOSE" == internet && "$ROLE" == workstation ]]; then
  die "--expose internet is for a server or a repeater. Add --role server or --role repeater, or leave --expose out."
fi
if [[ "$EXPOSE" == internet ]]; then
  # Only the keyed, encrypted sharing port faces the internet. The portal, the
  # reader and the media shares carry no sign-in and stay on the server itself.
  if [[ "$OPT_SCOPE" == lan ]]; then
    say "  ${C_YELLOW}note:${C_OFF} --lan is ignored with --expose internet; the portal stays on this system only."
  fi
  SCOPE=local
fi
if [[ "$ROLE" == repeater ]]; then
  # A repeater has a status page for whoever sits at it and offers the network nothing but the network.
  SCOPE=local
fi
if [[ -n "$OPT_ENDPOINT" && ! "$OPT_ENDPOINT" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?:[1-9][0-9]{1,4}$ ]]; then
  die "--endpoint takes HOST:PORT, for example files.example.org:51820; \"$OPT_ENDPOINT\" is not that."
fi
readonly SCOPE ROLE EXPOSE
for server in "${OPT_SERVERS[@]:-}"; do
  [[ -z "$server" || "$server" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?(:[0-9]{2,5})?$ ]] ||
    die "--server takes a host name or IPv4 address, optionally with a port; \"$server\" is neither."
done

# A workstation is sat at by definition. A server may be: it has the desktop
# programs if it has a desktop, which can be said outright or is found out.
if [[ "$ROLE" == workstation ]]; then
  DESKTOP=yes
elif [[ "$ROLE" == repeater ]]; then
  DESKTOP=no
elif [[ -n "$OPT_DESKTOP" ]]; then
  DESKTOP="$OPT_DESKTOP"
elif [[ -x /usr/sbin/gdm3 || -x /usr/bin/sddm || -x /usr/sbin/lightdm || -x /usr/bin/gnome-shell ]]; then
  DESKTOP=yes
else
  DESKTOP=no
fi
readonly DESKTOP
# A repeater is the exception to "everything": it carries the network and nothing else.
if [[ "$ROLE" == repeater ]]; then
  : "${OPT_AI:=no}" "${OPT_APPS:=none}" "${OPT_RADIO:=mesh}" "${OPT_GAMES:=no}" "${OPT_HOST_GAMES:=no}"
fi
: "${OPT_MESH:=yes}"
# Every other role has everything; what a desktop is needed for goes where there is one.
WHY_NO_RADIO="--no-radio"
WHY_NO_AI="--no-ai"
WHY_NO_APPS="--apps none"
: "${OPT_AI:=yes}" "${OPT_JDOWNLOADER:=yes}" "${OPT_APPS:=all}" "${OPT_RADIO:=yes}"
if [[ "$DESKTOP" == yes ]]; then
  : "${OPT_MSTY:=yes}" "${OPT_GAMES:=yes}"
else
  OPT_MSTY=no
  OPT_JDOWNLOADER=no
  : "${OPT_GAMES:=no}"
fi
# The family worlds are hosted where the household's content lives, unless asked
# for elsewhere. Never on a server that faces the internet.
if [[ "$ROLE" == server && "$EXPOSE" == lan ]]; then
  : "${OPT_HOST_GAMES:=yes}"
fi
: "${OPT_HOST_GAMES:=no}"
if [[ "$EXPOSE" == internet && ( "$OPT_HOST_GAMES" == yes || "$OPT_HOTSPOT" == yes ) ]]; then
  die "A server exposed to the internet hosts no games and no hotspot. Leave out --host-games and --hotspot."
fi

say "  Role     $ROLE$([[ "$EXPOSE" == internet ]] && echo ', reachable from the internet by systems holding the house key')"
say "  Scope    $([[ "$SCOPE" == lan ]] && echo 'portal open to this system and the home network' || echo 'portal on this system only')"

export TARGET_USER TARGET_HOME SCOPE ARCH ROLE EXPOSE

# With --apt-cache, every package request made during this run, by any step, goes
# through the household's cache. apt still checks each index against Ubuntu's
# signature and each package against the index, so the cache itself is not trusted.
readonly INSTALL_PROXY_CONF=/etc/apt/apt.conf.d/01offline-install-cache
if [[ -n "$OPT_APT_CACHE" && "$OPT_DRY_RUN" != yes ]]; then
  printf '// Written by the Offline installer for the length of one run.\nAcquire::http::Proxy "http://%s:3142";\n' "$OPT_APT_CACHE" >"$INSTALL_PROXY_CONF"
  trap 'rm -f -- "$INSTALL_PROXY_CONF"' EXIT
fi
export OFFLINE_INSTALL_KEY="$OPT_KEY"
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a

trap 'die "Unexpected failure at line $LINENO. See $LOG_FILE."' ERR

# --------------------------------------------------------------------------
# Run
# --------------------------------------------------------------------------

section "Packages"
if [[ "$PACKAGES" == yes ]]; then
  must "Refresh package lists" apt-get update
  if [[ "$OPT_UPGRADE" == yes ]]; then
    step "Upgrade installed packages" apt-get upgrade -y
  else
    skip "Upgrade installed packages (--no-upgrade)"
  fi
  must "Install core tools (rsync, stunnel, aria2, firewall, discovery)" \
    apt_install ca-certificates curl rsync stunnel4 aria2 ufw iproute2 avahi-daemon avahi-utils libnss-mdns inotify-tools
  must "Install the library reader (kiwix-tools)" apt_install kiwix-tools
  must "Install the web portal stack (Apache, PHP-FPM)" apt_install apache2 php-fpm php-cli php-xml php-mbstring openssl
  step "Install the vault and rebuild tools (gocryptfs, signature checks)" apt_install gocryptfs fuse3 acl gpgv unzip qrencode
  if [[ "$ROLE" == server && "$EXPOSE" == lan ]]; then
    step "Install the household time service and package cache (chrony, apt-cacher-ng)" apt_install chrony apt-cacher-ng
  fi
  if [[ "$OPT_HOTSPOT" == yes ]]; then
    step "Install the hotspot tools (NetworkManager, iw)" apt_install network-manager iw
  fi
  step "Install map and container tools (git, Podman)" apt_install git podman netavark aardvark-dns crun uidmap pipx
  step "Install the children's guard (nftables)" apt_install nftables
  step "Install the private network tools (WireGuard, Python for Reticulum)" apt_install wireguard-tools python3-venv python3-pip python3-cryptography python3-serial
  if [[ "$DESKTOP" != yes ]]; then
    skip "Desktop applications (this system has no desktop)"
  else
    step "Install desktop applications (VLC, Kiwix)" apt_install vlc kiwix
    step "Install the desktop alert helpers (dialogs, notifications)" install_alert_helpers
    # GNOME's parental controls, for children's accounts. Not in every release.
    apt-get install -y --no-install-recommends malcontent >>"$LOG_FILE" 2>&1 || true
  fi
  if [[ "$ROLE" == repeater ]]; then
    skip "Download helpers (a repeater holds no content)"
  else
    step "Install download helpers (yt-dlp, ffmpeg)" apt_install yt-dlp ffmpeg
  fi
  step "Install power tools (powertop, power-profiles-daemon)" apt_install powertop power-profiles-daemon
else
  skip "Package installation (no internet connection)"
  for required in rsync stunnel php aria2c kiwix-serve apache2ctl ufw; do
    command -v "$required" >/dev/null 2>&1 || die "$required is not installed and this system is offline. Connect it to the internet for the first installation, or use the household's package cache: --apt-cache HOST"
  done
fi

section "Accounts and folders"
must "Create the unprivileged service accounts" create_accounts
must "Create content and state folders" create_directories
must "Move existing content into the shared layout" migrate_layout

section "Offline tools"
must "Install the offline command and settings" install_tools
must "Install the web portal" install_portal
must "Install system services" install_units
must "Set the house key" set_house_key

section "Network"
step "Configure the firewall" configure_firewall
if [[ "$EXPOSE" == internet ]]; then
  step "Withdraw any local announcement (a server on the internet is named on each system instead)" withdraw_discovery
else
  step "Announce this system to the others" configure_discovery
fi
step "Configure the web server" configure_webserver
must "Start sharing, reader, broadcasts and timers" start_services
if ((${#OPT_SERVERS[@]} > 0)); then
  step "Name the central servers: ${OPT_SERVERS[*]}" name_servers "${OPT_SERVERS[@]}"
fi
step "Use the household's own time servers and package cache where there are any" household_services
if [[ "$ROLE" == server && "$EXPOSE" == lan ]]; then
  step "Serve time and Ubuntu packages to the home network" serve_household
fi
if [[ "$OPT_HOTSPOT" == yes ]]; then
  step "Turn on the Wi-Fi hotspot" /usr/local/bin/offline hotspot on --yes
fi
step "Join the household's private network" /usr/local/bin/offline net join
if [[ -n "$OPT_ENDPOINT" ]]; then
  step "Tell the other systems where to call this one: $OPT_ENDPOINT" /usr/local/bin/offline net endpoint "$OPT_ENDPOINT"
fi
if [[ "$OPT_MESH" != yes ]]; then
  skip "Radio layer of the private network (--no-mesh)"
elif [[ "$ONLINE" == yes || -x /opt/offline/rns/bin/python3 ]]; then
  step "Switch on the radio layer of the private network (Reticulum $(sed -n "s/^const RNS_VERSION = '\(.*\)';$/\1/p" "$SCRIPT_DIR/lib/net.php"), pinned)" /usr/local/bin/offline net radio on
else
  skip "Radio layer of the private network (no internet connection to fetch Reticulum)"
fi

section "Maps"
if [[ "$ROLE" == repeater ]]; then
  skip "Map tool and viewer assets (a repeater holds no content)"
elif [[ "$ONLINE" == yes ]]; then
  step "Install the pinned map tool (pmtiles $PMTILES_VERSION)" install_pmtiles
  step "Fetch the map viewer's fonts, symbols and world outline" /usr/local/bin/offline maps setup
else
  skip "Map tool and viewer assets (no internet connection)"
fi

section "Games"
if [[ "$OPT_GAMES" != yes && "$OPT_HOST_GAMES" != yes ]]; then
  skip "Games ($([[ "$DESKTOP" != yes ]] && echo 'no desktop to play on, and a server that faces the internet hosts none' || echo '--no-games'))"
else
  if [[ "$OPT_GAMES" == yes && "$PACKAGES" == yes ]]; then
    step "Install the children's games (Luanti, GCompris, Tux Paint and others)" /usr/local/bin/offline games install
  elif [[ "$OPT_GAMES" == yes ]]; then
    skip "Children's games (no source of packages)"
  fi
  if [[ "$ONLINE" == yes ]]; then
    step "Fetch the Mineclonia game for Luanti (pinned)" /usr/local/bin/offline games setup
  else
    skip "Mineclonia (no internet connection)"
  fi
  if [[ "$OPT_HOST_GAMES" == yes ]]; then
    if ! command -v podman >/dev/null 2>&1 && [[ "$OPT_DRY_RUN" != yes ]]; then
      skip "Family worlds (Podman is not installed)"
    elif [[ "$ONLINE" == yes || -f /etc/containers/systemd/offline-game-creative.container ]]; then
      step "Host the family worlds (creative and gentle survival)" /usr/local/bin/offline games host all
      step "Install the browser activities for tablets (Sugarizer, about 630 MiB)" /usr/local/bin/offline games activities
    else
      skip "Family worlds and browser activities (no internet connection)"
    fi
  fi
fi

section "Radio"
if [[ "$OPT_RADIO" != yes && "$OPT_RADIO" != mesh ]]; then
  skip "Radio tools ($WHY_NO_RADIO)"
elif [[ "$ONLINE" != yes ]]; then
  skip "Radio tools (no internet connection)"
else
  if [[ "$OPT_RADIO" == mesh ]]; then
    skip "Receiver, decoder and handset tools (a repeater needs only the mesh)"
  else
    step "Install receiver, decoder and handset tools" install_radio_packages
  fi
  step "Install the Meshtastic command" install_meshtastic_cli
  step "Give $TARGET_USER access to receivers and serial ports" configure_radio_access
fi

section "Desktop and AI applications"
if [[ "$OPT_AI" != yes ]]; then
  skip "Ollama and Msty Studio ($([[ "$ROLE" == repeater ]] && echo 'a repeater carries the network and nothing else' || echo "$WHY_NO_AI"))"
elif [[ "$ONLINE" != yes ]]; then
  skip "Ollama and Msty Studio (no internet connection)"
else
  step "Install Ollama" install_ollama
  MODELS="$OPT_MODELS"
  [[ -n "$MODELS" ]] || MODELS="$(conf_get OLLAMA_MODELS)"
  [[ -n "$MODELS" ]] || MODELS="qwen2.5:0.5b"
  step "Pull Ollama models ($MODELS)" pull_models "$MODELS"
  if [[ "$OPT_MSTY" != yes ]]; then
    skip "Msty Studio ($([[ "$DESKTOP" != yes ]] && echo 'a desktop program' || echo '--no-msty'))"
  elif [[ "$ARCH" != amd64 ]]; then
    skip "Msty Studio (published for amd64 only; this is $ARCH)"
  else
    step "Install Msty Studio" install_msty
  fi
fi
if [[ "$DESKTOP" != yes ]]; then
  skip "JDownloader (a desktop program; a server without a desktop uses the download queue)"
elif [[ "$OPT_JDOWNLOADER" != yes ]]; then
  skip "JDownloader (--no-jdownloader)"
elif [[ "$ONLINE" != yes ]]; then
  skip "JDownloader (no internet connection)"
else
  step "Install JDownloader" install_jdownloader
fi
if [[ "$DESKTOP" != yes ]]; then
  skip "Desktop shortcuts and the broadcast alert (this system has no desktop)"
else
  step "Create desktop shortcuts and the broadcast alert" desktop_shortcuts
fi

section "Browser applications"
APP_ARGS=()
while IFS= read -r app; do
  [[ -n "$app" ]] && APP_ARGS+=("$app")
done < <(tr ' ,' '\n' <<<"$OPT_APPS")
if [[ "$OPT_APPS" == none ]]; then
  skip "Browser applications ($([[ "$ROLE" == repeater ]] && echo 'a repeater carries the network and nothing else' || echo "$WHY_NO_APPS"))"
elif ! command -v podman >/dev/null 2>&1 && [[ "$OPT_DRY_RUN" != yes ]]; then
  skip "Browser applications (Podman is not installed)"
else
  # Applications already installed are brought up to date first; this needs no internet.
  step "Refresh installed applications" /usr/local/bin/offline apps refresh
  if [[ "$ONLINE" == yes ]]; then
    step "Install applications: ${OPT_APPS//[,[:space:]]/ } (several gigabytes on first run)" /usr/local/bin/offline apps add "${APP_ARGS[@]}"
  else
    skip "Install applications (no internet connection)"
  fi
fi

if [[ -n "$OPT_PACKS" ]]; then
  section "Content"
  if [[ "$ONLINE" != yes ]]; then
    skip "Download content packs (no internet connection)"
  elif [[ "$OPT_DRY_RUN" == yes ]]; then
    skip "Download content packs: $OPT_PACKS"
  else
    say "  Downloading: $OPT_PACKS. This can take hours; it resumes if interrupted."
    PACK_ARGS=()
    while IFS= read -r pack; do
      [[ -n "$pack" ]] && PACK_ARGS+=("$pack")
    done < <(tr ' ,' '\n' <<<"$OPT_PACKS")
    if /usr/local/bin/offline get --yes "${PACK_ARGS[@]}"; then
      STEPS_OK+=("Download content packs: $OPT_PACKS")
    else
      STEPS_FAILED+=("Download content packs: $OPT_PACKS")
    fi
  fi
fi

if [[ -n "$OPT_MAPS" ]]; then
  section "Map regions"
  if [[ "$ONLINE" != yes ]]; then
    skip "Download map regions (no internet connection)"
  elif [[ "$OPT_DRY_RUN" == yes ]]; then
    skip "Download map regions: $OPT_MAPS"
  else
    MAP_ARGS=()
    while IFS= read -r region; do
      [[ -n "$region" ]] && MAP_ARGS+=("$region")
    done < <(tr ' ,' '\n' <<<"$OPT_MAPS")
    if /usr/local/bin/offline maps get --yes "${MAP_ARGS[@]}"; then
      STEPS_OK+=("Download map regions: $OPT_MAPS")
    else
      STEPS_FAILED+=("Download map regions: $OPT_MAPS")
    fi
  fi
fi

# --------------------------------------------------------------------------
# Summary
# --------------------------------------------------------------------------

trap - ERR
section "Summary"
if [[ "$OPT_DRY_RUN" == yes ]]; then
  say "  Dry run: ${#STEPS_OK[@]} step(s) would run and ${#STEPS_SKIPPED[@]} would be skipped. Nothing was changed."
  exit 0
fi
say "  ${#STEPS_OK[@]} step(s) completed, ${#STEPS_FAILED[@]} failed, ${#STEPS_SKIPPED[@]} skipped."
for failed in "${STEPS_FAILED[@]:-}"; do
  [[ -n "$failed" ]] && say "  ${C_RED}failed:${C_OFF} $failed"
done

echo
/usr/local/bin/offline doctor || true

if [[ "$ROLE" == server ]]; then
  cat <<EOF

${C_BOLD}Next${C_OFF}
  Share the house key         sudo offline key show    then, on each other system:   sudo offline key set KEY
  Point the others here       on each of them:         sudo offline server add $(hostname -f 2>/dev/null || hostname)
  Fetch the reference shelf   sudo offline get household       (Wikipedia with images, WikiMed, Wikivoyage; about 130 GiB)
  Fetch maps                  sudo offline maps get uk         or   sudo offline maps get continents
  Archive UK websites         sudo offline queue site uk
  Queue a download            sudo offline queue add ADDRESS --to movies
  Release what has arrived    offline stage            then   sudo offline stage publish all
  Hold packages for rebuilds  sudo offline rebuild warm        and   sudo offline rebuild image
  Find places and routes      sudo offline maps places         and   sudo offline maps routing build uk
  Be the house's Wi-Fi        sudo offline hotspot on          (needs a Wi-Fi adapter)
  Make the first accounts     sudo offline user add NAME --adult --admin     and   sudo offline user add NAME --child
  See the private network     offline net              and, where others can call this system:   sudo offline net endpoint HOST:PORT
  Check health at any time    offline doctor

  Full log: $LOG_FILE
EOF
else
  cat <<EOF

${C_BOLD}Next${C_OFF}
  Open the portal             http://localhost/
  Choose content              offline catalogue        then   sudo offline get essential
  Add maps                    offline maps             then   sudo offline maps get uk
  Start an app                offline apps             then   sudo offline apps start jellyfin
  Check the radios            offline radio
  Join the other systems      sudo offline key show    then, on each of them:   sudo offline key set KEY
  Use a central server        sudo offline server add HOST
  Send a family broadcast     offline broadcast "Dinner is ready"
  See the games               offline games            or   http://localhost/games.php
  Keep the family's papers    offline vault init       then   offline vault open
  Make the family's accounts  sudo offline user add NAME --adult --admin     and   sudo offline user add NAME --child
  Check health at any time    offline doctor

  Log out and back in once so that $TARGET_USER can copy files into $SHARE_ROOT,
  use the radio hardware, open the vault, and see family broadcasts on the desktop.
  Full log: $LOG_FILE
EOF
fi

((${#STEPS_FAILED[@]} == 0)) || exit 3
