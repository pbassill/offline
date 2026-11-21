#!/usr/bin/env bash
#
# Offline installer for Ubuntu.
#
# Turns a laptop into a self-contained offline library: reference works, media,
# a local AI model, a web portal, and automatic exchange of content with other
# Offline laptops in the house. Safe to run again at any time; a second run
# upgrades the tools and leaves content, settings and the house key alone.
#
#   sudo ./install.sh --help

set -Eeuo pipefail
IFS=$'\n\t'
umask 022

readonly OFFLINE_VERSION="2.1.0"
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
OPT_AI=yes
OPT_MSTY=yes
OPT_JDOWNLOADER=yes
OPT_DRY_RUN=no
OPT_SCOPE=""
OPT_KEY=""
OPT_PACKS=""
OPT_MODELS=""
OPT_APPS="all"
OPT_MAPS=""
OPT_RADIO=yes

STEPS_OK=()
STEPS_FAILED=()
STEPS_SKIPPED=()

usage() {
  cat <<EOF
Offline installer $OFFLINE_VERSION

Usage: sudo ./install.sh [options]

  --packs "A B"        download these content packs at the end (see: offline catalogue)
  --maps "A B"         download these map regions at the end (see: offline maps)
  --apps "A B"         applications to install: names, "all" (default: the whole
                       Project NOMAD set) or "none" (see: offline apps)
  --no-radio           skip the radio tools (SDR, Meshtastic, handset programming)
  --key KEY            join an existing household by entering its house key
                       (or leave it out and run "sudo offline key set" afterwards,
                       which keeps the key off the command line)
  --models "A B"       Ollama models to pull (default: from offline.conf, qwen2.5:0.5b)
  --local-only         portal, reader and media reachable from this laptop only
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

# --------------------------------------------------------------------------
# Steps
# --------------------------------------------------------------------------

apt_install() {
  apt-get install -y --no-install-recommends "$@"
}

create_accounts() {
  if ! getent passwd offline >/dev/null; then
    adduser --system --group --home "$STATE_DIR" --no-create-home --shell /usr/sbin/nologin offline
  fi
  if ! getent passwd offline-web >/dev/null; then
    adduser --system --group --home /nonexistent --no-create-home --shell /usr/sbin/nologin offline-web
  fi
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
  # Application data belongs to the applications' own user ranges, not to the sharing account.
  install -d -m 0755 -o root -g root "$SHARE_ROOT/apps"
  install -d -m 0700 -o root -g root "$CONF_DIR/apps"
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
  install -m 0644 -o root -g root "$SCRIPT_DIR"/lib/*.php "$SCRIPT_DIR/config/catalogue.tsv" "$SCRIPT_DIR/config/maps.tsv" "$LIB_DIR/"
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
  if grep -q '^PORTAL_SCOPE=' "$CONF_DIR/offline.conf"; then
    sed -i "s/^PORTAL_SCOPE=.*/PORTAL_SCOPE=$SCOPE/" "$CONF_DIR/offline.conf"
  else
    printf '\nPORTAL_SCOPE=%s\n' "$SCOPE" >>"$CONF_DIR/offline.conf"
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
ExecStart=/usr/bin/kiwix-serve --address=127.0.0.1 --port=8080 --monitorLibrary --blockexternal --library /var/lib/offline/library.xml
IPAddressAllow=
IPAddressAllow=localhost
EOF
  else
    rm -f /etc/systemd/system/offline-reader.service.d/scope.conf
  fi
  systemctl daemon-reload
}

set_house_key() {
  # The key travels on standard input and its output is discarded, so it never
  # appears in a process list or in the log.
  if [[ -n "${OFFLINE_INSTALL_KEY:-}" ]]; then
    printf '%s\n' "$OFFLINE_INSTALL_KEY" | /usr/local/bin/offline key set - >/dev/null
  elif [[ ! -s "$CONF_DIR/house.key" ]]; then
    /usr/local/bin/offline key new >/dev/null
  fi
  [[ -s "$CONF_DIR/house.key" && -s "$CONF_DIR/rsyncd.secrets" ]]
}

configure_firewall() {
  local net ssh_port
  ufw default deny incoming
  ufw default allow outgoing
  # Rule left by earlier versions: the reader port open to every address.
  ufw delete allow 8080/tcp >/dev/null 2>&1 || true
  for net in "${LAN_NETS[@]}"; do
    ufw allow from "$net" to any port 47001 proto tcp comment 'Offline content sharing'
    if [[ "$SCOPE" == lan ]]; then
      ufw allow from "$net" to any port 80 proto tcp comment 'Offline portal'
      ufw allow from "$net" to any port 8080 proto tcp comment 'Offline library reader'
    else
      ufw delete allow from "$net" to any port 80 proto tcp >/dev/null 2>&1 || true
      ufw delete allow from "$net" to any port 8080 proto tcp >/dev/null 2>&1 || true
    fi
  done
  # Do not lock out an administrator who manages this laptop over SSH.
  if systemctl is-active --quiet ssh 2>/dev/null || systemctl is-active --quiet ssh.socket 2>/dev/null; then
    ssh_port="$(sshd -T 2>/dev/null | awk '$1 == "port" { print $2; exit }')" || ssh_port=""
    [[ "$ssh_port" =~ ^[0-9]{1,5}$ ]] || ssh_port=22
    for net in "${LAN_NETS[@]}"; do
      ufw limit from "$net" to any port "$ssh_port" proto tcp comment 'SSH from the home network'
    done
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

configure_webserver() {
  local php_version pool_dir
  php_version="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
  [[ "$php_version" =~ ^[0-9]+\.[0-9]+$ ]] || { echo "Could not determine the PHP version"; return 1; }
  pool_dir="/etc/php/$php_version/fpm/pool.d"
  [[ -d "$pool_dir" ]] || { echo "PHP-FPM $php_version is not installed ($pool_dir is missing)"; return 1; }

  install -m 0644 -o root -g root "$SCRIPT_DIR/config/php-fpm-offline-portal.conf" "$pool_dir/offline-portal.conf"
  install -m 0644 -o root -g root "$SCRIPT_DIR/config/apache-offline-portal.conf" /etc/apache2/sites-available/offline-portal.conf
  install -m 0644 -o root -g root "$SCRIPT_DIR/config/apache-offline-hardening.conf" /etc/apache2/conf-available/zz-offline-hardening.conf
  if [[ "$SCOPE" == lan ]]; then
    printf '# Written by the Offline installer: PORTAL_SCOPE=lan.\nDefine OFFLINE_LAN\n' >/etc/apache2/conf-available/offline-scope.conf
  else
    printf '# Written by the Offline installer: PORTAL_SCOPE=local.\n' >/etc/apache2/conf-available/offline-scope.conf
  fi

  a2enmod -q headers proxy proxy_fcgi setenvif
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
  systemctl enable offline-rsync.service offline-reader.service offline-peer-sync.timer offline-status.timer offline-library.path
  systemctl restart offline-rsync.service offline-reader.service
  systemctl restart offline-peer-sync.timer offline-status.timer offline-library.path
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
Comment=Library, media and status of this laptop, with no internet
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
  rm -f -- "$desktop/Msty.desktop"
  cat >"$desktop/Quick Links.txt" <<'EOF'
OFFLINE: QUICK LINKS

Everything on this laptop:        http://localhost/
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
  sudo offline key show           the house key to enter on the other laptops

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
  : "${TARGET_USER:?}" "${TARGET_HOME:?}" "${SCOPE:?}" "${ARCH:?}"
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

for required in bin/offline lib/core.php lib/cli.php lib/maps.php lib/apps.php lib/radio.php config/offline.conf config/catalogue.tsv config/maps.tsv config/rsyncd.conf apps/apps.tsv data/conditions.json portal/public/index.php systemd/offline-rsync.service; do
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
say "  Network  $([[ "$ONLINE" == yes ]] && echo 'online' || echo 'offline: packages and downloads will be skipped')"

# The scope chosen on the command line wins, then the existing setting, then "lan".
SCOPE="$OPT_SCOPE"
[[ -n "$SCOPE" ]] || SCOPE="$(conf_get PORTAL_SCOPE)"
[[ "$SCOPE" == local || "$SCOPE" == lan ]] || SCOPE=lan
readonly SCOPE
say "  Scope    $([[ "$SCOPE" == lan ]] && echo 'this laptop and the home network' || echo 'this laptop only')"

export TARGET_USER TARGET_HOME SCOPE ARCH
export OFFLINE_INSTALL_KEY="$OPT_KEY"
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a

trap 'die "Unexpected failure at line $LINENO. See $LOG_FILE."' ERR

# --------------------------------------------------------------------------
# Run
# --------------------------------------------------------------------------

section "Packages"
if [[ "$ONLINE" == yes ]]; then
  must "Refresh package lists" apt-get update
  if [[ "$OPT_UPGRADE" == yes ]]; then
    step "Upgrade installed packages" apt-get upgrade -y
  else
    skip "Upgrade installed packages (--no-upgrade)"
  fi
  must "Install core tools (rsync, aria2, firewall, discovery)" \
    apt_install ca-certificates curl rsync aria2 ufw iproute2 avahi-daemon avahi-utils libnss-mdns
  must "Install the library reader (kiwix-tools)" apt_install kiwix-tools
  must "Install the web portal stack (Apache, PHP-FPM)" apt_install apache2 php-fpm php-cli php-xml openssl
  step "Install map and container tools (git, Podman)" apt_install git podman netavark aardvark-dns crun uidmap pipx
  step "Install desktop applications (VLC, Kiwix)" apt_install vlc kiwix
  step "Install download helpers (yt-dlp, ffmpeg)" apt_install yt-dlp ffmpeg
  step "Install power tools (powertop, power-profiles-daemon)" apt_install powertop power-profiles-daemon
else
  skip "Package installation (no internet connection)"
  for required in rsync php aria2c kiwix-serve apache2ctl ufw; do
    command -v "$required" >/dev/null 2>&1 || die "$required is not installed and this laptop is offline. Connect it to the internet for the first installation."
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
step "Announce this laptop to the others" configure_discovery
step "Configure the web server" configure_webserver
must "Start sharing, reader and timers" start_services

section "Maps"
if [[ "$ONLINE" == yes ]]; then
  step "Install the pinned map tool (pmtiles $PMTILES_VERSION)" install_pmtiles
  step "Fetch the map viewer's fonts, symbols and world outline" /usr/local/bin/offline maps setup
else
  skip "Map tool and viewer assets (no internet connection)"
fi

section "Radio"
if [[ "$OPT_RADIO" != yes ]]; then
  skip "Radio tools (--no-radio)"
elif [[ "$ONLINE" != yes ]]; then
  skip "Radio tools (no internet connection)"
else
  step "Install receiver, decoder and handset tools" install_radio_packages
  step "Install the Meshtastic command" install_meshtastic_cli
  step "Give $TARGET_USER access to receivers and serial ports" configure_radio_access
fi

section "Desktop and AI applications"
if [[ "$OPT_AI" != yes ]]; then
  skip "Ollama and Msty Studio (--no-ai)"
elif [[ "$ONLINE" != yes ]]; then
  skip "Ollama and Msty Studio (no internet connection)"
else
  step "Install Ollama" install_ollama
  MODELS="$OPT_MODELS"
  [[ -n "$MODELS" ]] || MODELS="$(conf_get OLLAMA_MODELS)"
  [[ -n "$MODELS" ]] || MODELS="qwen2.5:0.5b"
  step "Pull Ollama models ($MODELS)" pull_models "$MODELS"
  if [[ "$OPT_MSTY" != yes ]]; then
    skip "Msty Studio (--no-msty)"
  elif [[ "$ARCH" != amd64 ]]; then
    skip "Msty Studio (published for amd64 only; this is $ARCH)"
  else
    step "Install Msty Studio" install_msty
  fi
fi
if [[ "$OPT_JDOWNLOADER" != yes ]]; then
  skip "JDownloader (--no-jdownloader)"
elif [[ "$ONLINE" != yes ]]; then
  skip "JDownloader (no internet connection)"
else
  step "Install JDownloader" install_jdownloader
fi
step "Create desktop shortcuts" desktop_shortcuts

section "Browser applications"
APP_ARGS=()
while IFS= read -r app; do
  [[ -n "$app" ]] && APP_ARGS+=("$app")
done < <(tr ' ,' '\n' <<<"$OPT_APPS")
if [[ "$OPT_APPS" == none ]]; then
  skip "Browser applications (--apps none)"
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

cat <<EOF

${C_BOLD}Next${C_OFF}
  Open the portal             http://localhost/
  Choose content              offline catalogue        then   sudo offline get essential
  Add maps                    offline maps             then   sudo offline maps get uk
  Start an app                offline apps             then   sudo offline apps start jellyfin
  Check the radios            offline radio
  Join the other laptops      sudo offline key show    then, on each of them:   sudo offline key set KEY
  Check health at any time    offline doctor

  Log out and back in once so that $TARGET_USER can copy files into $SHARE_ROOT
  and use the radio hardware.
  Full log: $LOG_FILE
EOF

((${#STEPS_FAILED[@]} == 0)) || exit 3
