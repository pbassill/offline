# Offline

Offline internet and low-power tools for the house.

One command turns an Ubuntu laptop into a self-contained library: reference works that can be read and searched with no connection, street-level maps, films and music, a local AI model, a set of browser applications, tools for the household's radios, and a web portal that ties them together and shows how the machine is faring. Laptops in the same house find one another and exchange whatever one holds that another lacks, so content downloaded once reaches all of them.

It carries every tool and content collection of [Project NOMAD](https://github.com/Crosstalk-Solutions/project-nomad), rebuilt to this project's own security rules; the table under "Project NOMAD" shows where each one went.

```sh
sudo ./install.sh
```

The installer may be run again at any time. A second run upgrades the tools and leaves content, settings and the house key as they were. `sudo ./install.sh --help` lists its options and `--dry-run` shows every step without changing anything.

## What is installed

The installer adds VLC and Kiwix for viewing, `yt-dlp`, `aria2` and JDownloader for fetching, Ollama with a small model (`qwen2.5:0.5b`) and Msty Studio for local AI, `powertop` for power tuning, Podman for the browser applications, and the radio tools described below. Around those it builds four services of its own.

The **library reader** (`kiwix-serve`, port 8080) serves every installed ZIM file to a browser and searches inside them. The **portal** (Apache and PHP, port 80) is the front page: what is installed, battery and storage, the state of each service, the other laptops, a media browser with playback, and a guide. **Content sharing** (an rsync daemon, port 47001) offers this laptop's library and media to the others, and a **peer sync** timer fetches from them every five minutes. A further timer records the laptop's status once a minute for the portal, and a path watcher rebuilds the reader's index whenever a ZIM file is added or removed.

Other devices on the home network, tablets and telephones included, can use the portal at `http://HOSTNAME.local/`. Pass `--local-only` to the installer to restrict everything to the laptop itself.

## The `offline` command

```
offline status [--json]          what is installed, running and shared
offline doctor [--online]        health checks, each with its fix
offline peers                    other Offline laptops on this network
offline power [saver|balanced|performance]

sudo offline catalogue [PACK] [--check]   curated packs; --check refreshes sizes from Kiwix
     offline search TEXT [--lang eng]     search the whole Kiwix library
sudo offline get PACK|ZIM|URL ... [--dry-run] [--yes]
sudo offline update [--dry-run] [--yes]   fetch newer editions of what is installed
sudo offline prune [--dry-run]            remove superseded editions
sudo offline verify [ZIM ...]             compare files with checksums published by Kiwix
sudo offline library                      rebuild the reader index

     offline maps                         regions installed and on offer
sudo offline maps setup                   fetch the viewer's fonts, symbols and world outline
sudo offline maps get REGION ... [--maxzoom N] [--dry-run] [--yes]
sudo offline maps get --bbox W,S,E,N --name NAME
sudo offline maps remove REGION

     offline apps                         applications on offer and their state
sudo offline apps add APP ...|all         install (all = the Project NOMAD set)
sudo offline apps start|stop|restart|enable|disable|remove|info APP
sudo offline apps pull APP ...|all        fetch images now, to install later offline

     offline radio                        receivers and mesh nodes plugged in, tools installed
     offline radio channels               the PMR446 channel table
     offline radio listen pmr [CHANNEL]   scan PMR446 with the USB receiver; also wfm|nfm|am MHZ
     offline radio mesh info|nodes|send TEXT|region EU_868
sudo offline radio firmware               keep Meshtastic firmware on disc for offline flashing

sudo offline sync [--dry-run] [--force]   fetch new content from peers now
sudo offline key [show|new|set [KEY]]     the house key that peers must share
```

Commands that only report run as any user. Commands that change content are run with `sudo` and immediately give up root, continuing as the unprivileged `offline` account. Two keep root: `offline key`, because the key file belongs to root, and the `offline apps` actions that install system services.

## Content

Content comes in packs, listed in `config/catalogue.tsv` and shown by `offline catalogue`.

Most packs are Project NOMAD's curated collections, each subject in three tiers.

| Subject | Essential | Standard adds | Comprehensive adds |
| --- | --- | --- | --- |
| `medicine` | Medical library, NHS Medicines A to Z, military medicine, CDC | MedlinePlus | Wikipedia medicine, LibreTexts medicine, LibrePathology |
| `survival` | Water, food and knots, winter and bug-out guides | Ready.gov, outdoors and amateur radio Q&A, bug-out concepts | Post-disaster library, CD3WD, military science, food storage |
| `education` | Wikibooks | TED-Ed, Wikiversity, LibreTexts sciences | Wikibooks with images, TED talks, LibreTexts humanities and engineering |
| `diy` | Woodworking and vehicle Q&A | Home improvement Q&A | iFixit |
| `agriculture` | Two recipe collections | Gardening and cooking Q&A | Homesteading videos, Gutenberg agriculture |
| `computing` | freeCodeCamp, Python, JavaScript, HTML, CSS | Arduino, Raspberry Pi, Node, React, Git | Electronics and robotics Q&A, Docker, Bash |

A pack is named subject then tier, as in `medicine-standard`. A standard pack brings its essential pack with it and a comprehensive pack brings both, and a tier on its own covers every subject. Wikipedia comes in the five sizes NOMAD offers, from `wikipedia-top-mini` (about 330 MiB) to `wikipedia-all-maxi` (about 120 GiB). This project adds `medical-extra`, `reference`, `practical-extra`, `education-extra`, `books` and `tech-extra`.

```sh
sudo offline get essential
sudo offline get medicine-standard wikipedia-top-nopic
```

The catalogue stores names and a size estimate for planning. True sizes and current editions are read from the live Kiwix catalogue each time, so the file does not go stale, and `sudo offline catalogue --check` reports any entry whose name Kiwix has since changed. Before a download the command shows the total size against free space and asks for confirmation. Each file is fetched with `aria2c` from the Kiwix mirror network, verified against the SHA-256 checksum Kiwix publishes, checked to be a real ZIM file, and only then moved into the library. A file for which no checksum is published is refused. An interrupted download resumes when the command is repeated.

Anything else in the Kiwix library can be found with `offline search` and fetched by name. Additions of your own go in `/etc/offline/catalogue.local.tsv`, which upgrades never overwrite.

ZIM files live in `/srv/offline/knowledge/zim`, which is also reachable as `~/Documents/Kiwix-zims`. A file copied there by hand appears in the reader within seconds. Media lives under `/srv/offline/media` in `movies`, `tv`, `music`, `radio` and `books`.

The portal also carries a short remedies reference for minor ailments, which needs no download.

## Maps

Maps are vector tiles in single PMTiles files, drawn in the browser by the portal's map page; no tile server runs. A region is cut from the current Protomaps world build of OpenStreetMap data for exactly the area asked for.

```sh
sudo offline maps setup
sudo offline maps get uk
sudo offline maps get --bbox -6.5,49.8,-1.8,51.7 --name south-west
```

Thirteen European regions are predefined, any other area can be given by its corners, and the fifty state maps of Project NOMAD are listed as ready-made files. The command reports the size before downloading. The map page shows the centre of the view in decimal degrees and as a Maidenhead locator. The tool that cuts regions is pinned by SHA-256, and the fonts and symbols come from a pinned git commit, so git verifies every byte.

## Applications

Sixteen browser applications are available, fifteen from Project NOMAD's catalogue plus Open WebUI. The installer fetches and installs the NOMAD set by default (`--apps none` skips them, `--apps "cyberchef jellyfin"` chooses). Light ones start at boot; heavier ones start when asked, to save memory and battery.

| Application | Port | Starts | Purpose |
| --- | --- | --- | --- |
| CyberChef | 8100 | at boot | Encode, decode, hash and analyse data |
| Flatnotes | 8200 | at boot | Markdown notes |
| Kolibri | 8310 | on request | Courses and exercises, Khan Academy among them |
| Stirling PDF | 8400 | on request | Merge, split and convert PDFs |
| File Browser | 8410 | on request | Browse and upload files (see below) |
| Calibre-Web | 8420 | on request | E-book library |
| IT Tools | 8430 | at boot | Converters and generators |
| Excalidraw | 8440 | at boot | Whiteboard |
| Meshtastic Web | 8450, TLS | at boot | Configure and message through a mesh node |
| Translated Library | 8460 | on request | The reader with machine translation |
| Homebox | 8470 | on request | Household inventory |
| Vaultwarden | 8480, TLS | on request | Password manager |
| Jellyfin | 8490 | on request | Media server with format conversion |
| MeshCore Web | 8500, TLS | on request | Client for MeshCore radios |
| Qdrant | local only | on request | Vector database |
| Open WebUI | 8300 | on request | Chat with the local models from any device |

Three rules apply to every one. The container's root user is mapped to an unprivileged range of host accounts and cannot gain privileges, so a compromised application is not root on the laptop. The container publishes its port on 127.0.0.1 only, because ports published by a container engine otherwise bypass the host firewall. Other devices reach it through an Apache proxy that applies the portal's home-network rule, with TLS for the three that browsers accept only over a secure connection. Images are pinned by digest and are never pulled except by `offline apps`.

Flatnotes and File Browser are given a random password at installation rather than the open access and fixed password NOMAD uses; `sudo offline apps info NAME` shows it. Shared content is mounted into applications read-only. File Browser was archived by its authors in September 2026 and receives no more security fixes, so it is left out of `all` and has to be asked for by name.

## Radio

The household's radios are PMR446 handsets for voice, Meshtastic nodes for text, and a USB receiver for listening. The installer adds the receiver tools (`rtl-sdr`, Gqrx, CubicSDR, welle.io, `rtl_433`, dump1090, multimon-ng, Dire Wolf, Fldigi, Gpredict, SoapySDR, HackRF and Airspy support), CHIRP for programming handsets, the Meshtastic command and `esptool`. It gives the desktop user access to serial ports and USB receivers, and stops the kernel's television driver from claiming an RTL-SDR dongle.

`offline radio` reports what is plugged in. `offline radio listen pmr` scans the sixteen PMR446 channels through the receiver and `offline radio mesh` wraps the common Meshtastic actions. `sudo offline radio firmware` keeps the current Meshtastic firmware on disc so that a node can be reflashed with no internet. The portal's radio page holds the PMR446 channel and CTCSS tables, the EU_868 mesh settings, a list of frequencies worth knowing and a note on what may lawfully be transmitted and heard. Channel files for Gqrx and CHIRP are in `/usr/local/share/offline/radio`.

## Project NOMAD

| In Project NOMAD | Here |
| --- | --- |
| Command Center | The portal and the `offline` command |
| Information Library (Kiwix) and curated collections | The reader on port 8080; every collection is a pack |
| Wikipedia selector | The five `wikipedia-*` packs |
| Offline Maps (ProtoMaps) | The map page and `offline maps`; NOMAD's state maps are listed |
| AI Assistant (Ollama) | Ollama on the laptop, Msty Studio on the desktop, Open WebUI as an application |
| Qdrant | An application, for use with Open WebUI or your own tools |
| Education Platform (Kolibri) | An application |
| Data Tools (CyberChef), Notes (FlatNotes) | Applications |
| Supply Depot | The other applications, all of them |
| Home and natural remedies data | The portal's remedies page |
| System Benchmark and leaderboard | Not carried; its purpose is a public leaderboard on an outside service |
| FDA drug label database | Not carried; NHS Medicines A to Z and MedlinePlus cover the ground |
| Creator Packs | Not carried; their draft licence grants distribution rights to Project NOMAD only |

NOMAD's own chat screen and document search are part of its Command Center and cannot be separated from it, which is why Open WebUI stands in.

## Sharing between laptops

Laptops exchange content only with others that hold the same **house key**. The installer creates one on the first laptop.

```sh
sudo offline key show                 # on the first laptop
sudo offline key set                  # on each of the others; prompts for the key
```

Library titles, media and maps are exchanged; application data is not. Laptops announce themselves by mDNS and, where that finds nothing, each address of a small local network is checked directly. Addresses are checked in parallel, so a scan takes a few seconds at most. What a peer offers is then filtered before anything is fetched. Only library files, documents, films, music, subtitles, pictures and map files are accepted, and never programs, scripts or web pages. Paths with traversal or hidden components are rejected. An older edition of a title already held is not fetched, which is what allows `prune` to work without the old edition returning from a neighbour. A file claiming to be a ZIM that is not one is discarded and not requested again. Fetching stops before free space falls below `SYNC_MIN_FREE_GB`, and is postponed on battery below `SYNC_MIN_BATTERY` per cent. Nothing is ever deleted because it is absent elsewhere.

Settings are in `/etc/offline/offline.conf`, a plain list of `KEY=value` lines that is read and never executed.

## Security

Every service runs as an unprivileged account, `offline` for content and `offline-web` for the portal, inside a systemd sandbox with a read-only view of the system, no capabilities, a system-call filter, and network access limited by `IPAddressAllow` to private address ranges. The peer sync job cannot reach the internet at all. The firewall denies inbound traffic by default and admits ports 80, 8080 and 47001 from private ranges only; if SSH is running it is kept open to the home network with rate limiting.

The rsync daemon requires the house key, serves read-only, refuses options that follow symbolic links, hides dot-files and partial downloads, and rejects every address outside private ranges. The portal is read-only by construction: it answers `GET` and `HEAD` only, keeps no session and sets no cookie, escapes every value it prints, and runs in a PHP-FPM pool confined by `open_basedir` with every function that could run a program, open a connection or write a file disabled. It sends a strict Content-Security-Policy, which holds because the pages contain no inline script or style. Bootstrap 5 and Font Awesome are vendored in the repository with checksums, so the portal loads nothing from any other site and works with no connection.

The installer downloads over verified TLS, runs nothing piped from the network into a shell, never sources a configuration file, and passes the house key on standard input so that it reaches neither a process list nor the log.

Some limits are worth knowing. The applications are other people's software: this project confines them but does not audit them, their first-run accounts are for you to create, and Calibre-Web ships with a well-known default password that must be changed at first sign-in. Applications are not blocked from making outbound connections of their own. Open WebUI shares the host's network so that it can reach Ollama on 127.0.0.1, which is less isolation than the others have. The Meshtastic command is installed from PyPI at a pinned version, but its dependencies are not pinned by hash. The house key authenticates laptops to one another but does not encrypt the transfer, and the portal and reader use plain HTTP, so content crosses the home network in the clear, as it would from any media server; this is acceptable for public reference material on a network you control and is not suited to private files. Ollama's installer and the Msty Studio package are fetched from their vendors over TLS, but neither vendor publishes a checksum for a rolling "latest" download, so they are trusted on the strength of TLS alone; `--no-ai` and `--no-msty` omit them. The portal status is refreshed once a minute, so figures on a page can be up to a minute old.

## Files

```
install.sh                    the installer
bin/offline                   the offline command
lib/core.php                  read-only helpers shared by the command and the portal
lib/cli.php                   the command's actions
lib/maps.php, apps.php, radio.php   the maps, applications and radio commands
config/                       settings, catalogues, rsync, Apache, PHP-FPM, mDNS, radio channel files
apps/                         one hardened Podman unit per application, and their index
data/                         remedies reference data
systemd/                      service, timer and path units
portal/                       the web portal (PHP, Bootstrap 5, Font Awesome, MapLibre; light, dark and VT100 themes)
tests/test-offline.sh         the test suite
```

## Tests

```sh
tests/test-offline.sh
```

The suite changes nothing outside a temporary directory. It lints the installer, validates the units, checks the hardening settings against regression, and then exercises the real thing: the command against a scratch root and a recorded Kiwix feed, peer sync against a live rsync daemon running the shipped configuration and seeded with hostile files, and the portal under the same PHP restrictions as the production pool. It also holds every application unit to the three rules above and passes each through Podman's own generator.

## Day-to-day checks

```sh
offline doctor
systemctl status offline-rsync offline-reader offline-peer-sync.timer
journalctl -u offline-peer-sync --since today
sudo tail -f /var/log/offline/install.log
```
