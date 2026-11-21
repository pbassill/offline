# offline
Offline Internet and Low Power Tools

The installer prepares Ubuntu for offline downloading, knowledge, and entertainment:

- Installs VLC, Kiwix, Ollama, Msty, JDownloader, `yt-dlp`, `aria2`, and rsync.
- Stores Kiwix zims under `~/Documents/Kiwix-zims`.
- Shares knowledge and media through rsync on TCP port `47001`.
- Runs a systemd timer every five minutes to find devices on the local network.
- Pulls any newer knowledge bases or entertainment from peers into `/srv/offline`.

Run the installer with:

```sh
sudo ./install.sh
```

The peer scan service can be run or inspected with:

```sh
systemctl status offline-peer-sync.timer
systemctl status offline-rsync.service
```
