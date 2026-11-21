#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'

run_step() {
  local description="$1"
  shift
  echo
  echo "=== $description ==="
  if "$@"; then
    echo "✔️  Success: $description"
  else
    echo "⚠️  Warning: '$description' failed, continuing..." >&2
  fi
}

if (( EUID != 0 )) || [[ -z "${SUDO_USER:-}" || "$SUDO_USER" == "root" ]]; then
  echo "⚠️  Please run this installer with sudo from a normal user account." >&2
  exit 1
fi

TARGET_USER="$SUDO_USER"
TARGET_HOME="$(getent passwd "$TARGET_USER" | cut -d: -f6)"
if [[ -z "$TARGET_HOME" ]]; then
  echo "Could not determine the home directory for $TARGET_USER" >&2
  exit 1
fi

SHARE_ROOT="/srv/offline"
KIWIX_DIR="$TARGET_HOME/Documents/Kiwix-zims"
MEDIA_DIR="$TARGET_HOME/Videos"
MUSIC_DIR="$TARGET_HOME/Music"

run_step "Updating APT repositories" apt update
run_step "Upgrading installed packages" apt upgrade -y

run_step "Creating offline content directories" \
  bash -c 'install -d -m 2775 -o root -g users "$1/knowledge" "$1/media" "$1/media/movies" "$1/media/radio" "$1/media/music" "$2" "$3" "$4"' \
  _ "$SHARE_ROOT" "$KIWIX_DIR" "$MEDIA_DIR" "$MUSIC_DIR"
mkdir -p "$TARGET_HOME/Desktop"
chown "$TARGET_USER:$TARGET_USER" "$TARGET_HOME/Desktop"

# Keep Kiwix zims in the directory it watches and make it available to peers.
run_step "Linking Kiwix zims to shared knowledge" \
  bash -c 'ln -sfn "$1" "$2/kiwix-zims"; chown -h "$3:$3" "$2/kiwix-zims" || true' _ \
  "$KIWIX_DIR" "$SHARE_ROOT/knowledge" "$TARGET_USER"

QUICK_LINKS="$TARGET_HOME/Desktop/Quick Links.txt"
run_step "Writing Quick Links file" bash -c 'cat > "$1" <<EOF
Download zims from: https://library.kiwix.org
Create zims from websites at: https://zimit.kiwix.org
Place downloaded zims in Documents/Kiwix-zims. Kiwix will scan that folder for new zims.
Nearby offline computers automatically share knowledge and entertainment at port 47001.
EOF
chown "$2:$2" "$1"' _ "$QUICK_LINKS" "$TARGET_USER"

run_step "Installing download and media tools" apt install -y ufw curl wget rsync aria2 yt-dlp vlc kiwix snapd

run_step "Enabling firewall" ufw --force enable
run_step "Allowing HTTP and peer sharing ports" ufw allow 8080/tcp
run_step "Allowing offline peer sharing port" ufw allow from 192.168.0.0/16 to any port 47001 proto tcp
run_step "Allowing private offline peers on 10.x" ufw allow from 10.0.0.0/8 to any port 47001 proto tcp
run_step "Allowing private offline peers on 172.16.x" ufw allow from 172.16.0.0/12 to any port 47001 proto tcp

run_step "Installing Ollama" bash -c "curl -fsSL https://ollama.com/install.sh | sh"
run_step "Pulling Ollama model qwen2.5:0.5b" sudo -u "$TARGET_USER" ollama pull qwen2.5:0.5b

MSTY_URL="https://assets.msty.app/prod/latest/linux/amd64/Msty_amd64_amd64.deb"
TMP_DEB="/tmp/$(basename "$MSTY_URL")"
run_step "Downloading Msty" wget -qO "$TMP_DEB" "$MSTY_URL"
run_step "Installing Msty" apt install -y "$TMP_DEB"

DESKTOP_FILE="$TARGET_HOME/Desktop/Msty.desktop"
run_step "Creating Msty desktop shortcut" bash -c 'cat > "$1" <<EOF
[Desktop Entry]
Type=Application
Name=Msty
Exec=/usr/bin/msty
Icon=utilities-terminal
Terminal=true
Categories=Utility;
EOF
chmod +x "$1"
chown "$2:$2" "$1"' _ "$DESKTOP_FILE" "$TARGET_USER"

run_step "Installing core snap" snap install core
run_step "Installing JDownloader" snap install jdownloader2

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
install -m 0755 "$SCRIPT_DIR/offline-peer-sync" /usr/local/bin/offline-peer-sync
install -m 0644 "$SCRIPT_DIR/offline-rsyncd.conf" /etc/offline-rsyncd.conf
install -m 0644 "$SCRIPT_DIR/offline-rsync.service" /etc/systemd/system/offline-rsync.service
install -m 0644 "$SCRIPT_DIR/offline-peer-sync.service" /etc/systemd/system/offline-peer-sync.service
install -m 0644 "$SCRIPT_DIR/offline-peer-sync.timer" /etc/systemd/system/offline-peer-sync.timer

systemctl daemon-reload
run_step "Starting offline sharing service" systemctl enable --now offline-rsync.service
run_step "Starting five-minute peer scan" systemctl enable --now offline-peer-sync.timer

echo
echo "Completed. Knowledge and media are shared on TCP port 47001."
