# Offline

Offline internet and low-power tools for the house.

One command turns an Ubuntu laptop into a self-contained library: reference works that can be read and searched with no connection, street-level maps, films and music, a local AI model, a set of browser applications, tools for the household's radios, and a web portal that ties them together and shows how the machine is faring. The same command with `--role server` builds a central server instead, which downloads and stages content for the others; there may be several, at home and in a datacentre. Every system holding the household's key exchanges whatever one has that another lacks over an encrypted channel, so content downloaded once reaches all of them, and a family broadcast written on any one appears on every screen and goes out over the mesh radios.

It carries every tool and content collection of [Project NOMAD](https://github.com/Crosstalk-Solutions/project-nomad), rebuilt to this project's own security rules; the table under "Project NOMAD" shows where each one went.

```sh
sudo ./install.sh                                    # a workstation: a laptop or desktop people sit at
sudo ./install.sh --server files.example.org         # a workstation that also pulls from a central server
sudo ./install.sh --role server                      # a central server on the home network
sudo ./install.sh --role server --expose internet    # a central server in a datacentre
```

The installer may be run again at any time. A second run upgrades the tools and leaves content, settings and the house key as they were. `sudo ./install.sh --help` lists its options and `--dry-run` shows every step without changing anything.

## What is installed

On a workstation the installer adds VLC and Kiwix for viewing, `yt-dlp`, `aria2` and JDownloader for fetching, Ollama with a small model (`qwen2.5:0.5b`) and Msty Studio for local AI, `powertop` for power tuning, Podman for the browser applications, and the radio tools described below. A server gets the fetching tools and Podman and none of the desktop software; `--apps`, `--with-ai` and `--with-radio` add the rest where it is wanted. Around those the installer builds services of its own.

The **library reader** (`kiwix-serve`, port 8080) serves every installed ZIM file to a browser and searches inside them. The **portal** (Apache and PHP, port 80) is the front page: what is installed, battery and storage, the state of each service, the other systems, current family broadcasts, a media browser with playback, and a guide. **Content sharing** is an rsync daemon that listens on the machine itself only, with a TLS listener in front of it (stunnel, port 47002) that every other system connects to; a **sync** timer fetches from the central servers and from the other systems every five minutes. The **broadcast** service signs, shows and passes on family broadcasts the moment one is written or arrives, and a second timer asks the other systems once a minute for any that were missed. A further timer records the system's status once a minute for the portal, and a path watcher rebuilds the reader's index whenever a ZIM file is added or removed. A server also runs a **download queue** every ten minutes and a **site archiver** every hour.

Other devices on the home network, tablets and telephones included, can use the portal at `http://HOSTNAME.local/`. Pass `--local-only` to the installer to restrict everything but sharing to the system itself.

## The `offline` command

```
offline status [--json]          what is installed, running and shared
offline doctor [--online]        health checks, each with its fix
offline peers                    other Offline systems on this network
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
sudo offline maps get REGION|GROUP ... [--maxzoom N] [--dry-run] [--yes]
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

     offline broadcast "TEXT" [--urgent]  alert every screen and the Meshtastic mesh
     offline broadcast                    recent broadcasts, and how the last one was delivered

     offline server                       the central servers this system pulls from
sudo offline server add HOST[:PORT] | remove HOST | test
     offline queue                        downloads waiting, running and done
sudo offline queue add URL ... [--to movies|tv|music|radio|books] [--audio]
     offline queue sites                  websites worth archiving, UK ones included
sudo offline queue site NAME|GROUP        archive a website as a library title
sudo offline queue site URL --name NAME [--pages N]
sudo offline queue run | archive | remove ID | retry ID
     offline stage                        what has been downloaded and awaits release
sudo offline stage publish ID|all [--to FOLDER] | discard ID

sudo offline sync [--dry-run] [--force]   fetch new content from servers and peers now
sudo offline key [show|new|set [KEY]|refresh]   the house key every system must share
```

Commands that only report run as any user, and so does `offline broadcast`, for anyone in the `offline` group. Commands that change content are run with `sudo` and immediately give up root, continuing as the unprivileged `offline` account. Four keep root: `offline key`, because the key file belongs to root; the `offline apps` actions that install system services; `offline server add` and `remove`, which edit the settings; and `offline queue archive`, which starts a container.

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

Two packs are this project's own. `household` is the core reference shelf in full: the whole of English Wikipedia with its images (about 121 GiB), WikiMed, which is the medical encyclopaedia maintained by WikiProject Medicine (1.7 GiB), MDWiki (2.1 GiB), Wikivoyage with its maps and pictures (about 1 GiB) and NHS Medicines A to Z. Allow 130 GiB and several hours. `uk` holds what Kiwix publishes ready-made for the United Kingdom, which at present is NHS Medicines A to Z alone; the other UK sources are archived from their own sites by a server, as described under "Central servers".

```sh
sudo offline get household
```

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
sudo offline maps get continents
sudo offline maps get --bbox -6.5,49.8,-1.8,51.7 --name south-west
```

A region is fetched by name, and a group name stands for every region in it. The `continents` group holds all six inhabited continents at full street detail. Each is a generous rectangle, so neighbours overlap at their edges and nothing falls in a gap between them.

| Region | Covers | Size at full detail |
| --- | --- | --- |
| `europe` | Europe, with Iceland, Turkey and western Russia | 49 GiB |
| `africa` | Africa, with Madagascar and the Arabian peninsula | 14 GiB |
| `asia` | Asia, from the Levant to Japan and Indonesia | not measured; shown before download |
| `north-america` | North and Central America, the Caribbean and Greenland | 34 GiB |
| `south-america` | South America | 8.3 GiB |
| `oceania` | Australia, New Zealand, New Guinea and the western Pacific | 3.8 GiB |

The five measured continents come to about 110 GiB. Asia's size could not be measured in the time available and may well be the largest of the six, so the full set needs a server's disc and not a laptop's. The sizes were measured against the build of 21 November 2026 and grow a little with each one. The whole planet as a single file is about 130 GiB, and `--maxzoom 12` gives any region as a much smaller file, with towns and roads but without the finest street detail. Pacific islands east of the date line are outside the `oceania` rectangle and can be fetched with `--bbox`.

For the United Kingdom there is `uk` (2.8 GiB), and the `uk-nations` group holds `england`, `scotland`, `wales` and `northern-ireland` separately. Twelve other European regions are predefined in `european-countries`, any other area can be given by its corners, and the fifty state maps of Project NOMAD are listed as ready-made files. The command reports the size before downloading and refuses a map that would not leave the free-space reserve. The map page shows the centre of the view in decimal degrees and as a Maidenhead locator. The tool that cuts regions is pinned by SHA-256, and the fonts and symbols come from a pinned git commit, so git verifies every byte.

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

## Central servers

A server is the same software as a workstation without the desktop. It holds the household's content, fetches more, and is pulled from by everything else. There can be as many as are useful, and the usual pair is one at home, where the whole house can reach it quickly, and one in a datacentre, which has the bandwidth for large downloads and can be reached by a laptop that is away from home.

```sh
sudo ./install.sh --role server                      # at home
sudo ./install.sh --role server --expose internet    # in a datacentre
sudo offline server add files.example.org            # on every system that should pull from it
sudo offline server test
```

Each system keeps its own list of servers in `SERVERS` and tries all of them at every sync, before looking at the local network, so nothing has to be reconfigured when a laptop leaves the house. A server may itself name other servers, which is how a home server collects what a datacentre server has downloaded. Sync only ever pulls: a server at home behind a router is reached by the systems at home, and is reached from a datacentre only if the router forwards port 47002 to it.

`--expose internet` opens the sharing port, and only that port, to any address. The portal, the reader and the media shares carry no sign-in and are confined to the server itself in that mode, whatever else is asked for. SSH stays reachable, rate-limited, so the administrator is not locked out. The server stops announcing itself and stops scanning its local network, because in a datacentre that network belongs to strangers.

### The download queue and the staging area

Downloads on a server never land in the shared folders directly. They arrive in `/srv/offline/staging`, which is not shared and is closed to other accounts, and are released from there.

```sh
sudo offline queue add https://example.org/films/holiday.mp4 --to movies
sudo offline queue add https://video.example/watch?v=abc --audio --to music
offline queue
offline stage
sudo offline stage publish all
```

A direct link to a media file is fetched with `aria2c`, and any other address is handed to `yt-dlp`. A server works through the queue every ten minutes; on a workstation `sudo offline queue run` does it by hand. Publishing moves films, music, books, map files and library titles to where they belong and leaves everything else behind: a script or a web page in a download is never published, and neither is a symbolic link. Other systems fetch what was published at their next sync. Setting `STAGING_AUTOPUBLISH=yes` removes the manual step. Fetch only what you have the right to keep a copy of.

### Archiving websites, and the United Kingdom sources

Kiwix publishes little that is specific to the United Kingdom, so a server makes its own titles from the sites themselves, using [zimit](https://github.com/openzim/zimit), the Kiwix project's tool for turning a website into a ZIM file.

```sh
offline queue sites
sudo offline queue site uk
sudo offline queue site https://example.org/handbook/ --name example.org_en_handbook --pages 800
```

The `uk` group in `config/sites.tsv` holds three sites: the Government's *Be Ready* emergency preparedness advice, NHS Health A to Z, and the Highway Code. Each is published under the Open Government Licence v3.0, which permits copying with acknowledgement of the source. The NHS asks that copies be kept current, so that one should be archived again every few months. Additions of your own go in `/etc/offline/sites.local.tsv`.

A crawl stays beneath the address given, stops at its page limit, fetches two pages at a time, and identifies itself. The result arrives in staging like any other download and joins the library when published. zimit drives a real browser, so it runs in a container pinned by digest, in its own user namespace, with no new privileges, memory and process limits, and one writable folder; a large site takes hours.

## Family broadcast

A broadcast is one short message for the whole household. It can be written on any system.

```sh
offline broadcast "Dinner is ready"
offline broadcast --urgent "Power cut: torches are in the hall cupboard"
offline broadcast
```

On a workstation the same thing is in the applications menu as *Family broadcast*. Within a second or two the message is at the top of every portal page on the system it was written on, and it is pushed at once to every server and every system seen at a recent sync. Each system that receives it shows it in three places: as a desktop dialog and notification for whoever is logged in, as a band at the top of every portal page, which a page already open picks up within twenty seconds, and, where the terminal permits messages, on open terminals, which is all a server has for a screen. It then passes the message on once to the systems it knows, so a broadcast crosses from a laptop away from home, through the datacentre server, to the house. A system that was asleep or out of reach asks the others once a minute and collects what it missed; a person who logs in later is shown anything sent in the last twelve hours.

Where a Meshtastic node is plugged into a system, the broadcast is also sent as a text message on the mesh's primary channel, so it reaches every handheld node. The system the broadcast was written on sends it if it has a node; if it has none, every system with a node that receives the broadcast sends it. Setting `MESH_GATEWAY=no` on all but one system avoids repeats on the mesh. One LoRa packet carries about two hundred bytes, of which the message's origin takes some, so a long broadcast is cut short on the radio and shown whole on screens.

A broadcast is a small envelope signed with HMAC-SHA256 under a key derived from the house key. A system shows one only if the signature checks, the envelope is less than a day old, and it has not been seen before. The portal displays broadcasts and cannot send them.

## Sharing between systems

Systems exchange content only with others that hold the same **house key**. The installer creates one on the first system.

```sh
sudo offline key show                 # on the first system
sudo offline key set                  # on each of the others; prompts for the key
```

The key does two jobs. A transport key is derived from it, and every connection between two systems is TLS 1.3 with that as a pre-shared key: a system that does not hold the house key cannot complete the handshake, so it learns nothing about what is on offer, and everything after the handshake is encrypted with keys from a fresh exchange made for that connection. There are no certificates to issue or renew. Inside the channel the rsync daemon asks for the house key again before serving anything.

Library titles, media and maps are exchanged; application data and the staging area are not. The configured servers are tried first. Systems on the home network announce themselves by mDNS and, where that finds nothing, each address of a small local network is checked directly. Addresses are checked in parallel, so a scan takes a few seconds at most. What a system offers is then filtered before anything is fetched. Only library files, documents, films, music, subtitles, pictures and map files are accepted, and never programs, scripts or web pages. Paths with traversal or hidden components are rejected. An older edition of a title already held is not fetched, which is what allows `prune` to work without the old edition returning from a neighbour. A file claiming to be a ZIM that is not one is discarded and not requested again. Fetching stops before free space falls below `SYNC_MIN_FREE_GB`, and is postponed on battery below `SYNC_MIN_BATTERY` per cent. Nothing is ever deleted because it is absent elsewhere.

Version 2.2 changed how systems talk to one another. Earlier versions shared on port 47001 without encryption; that port now answers the machine itself only. A system on 2.2 and a system on 2.1 therefore cannot exchange anything, so upgrade every system in the household in the same sitting. The house key is kept, and the installer derives the new transport key from it.

Settings are in `/etc/offline/offline.conf`, a plain list of `KEY=value` lines that is read and never executed.

## Security

Every service runs as an unprivileged account, `offline` for content and `offline-web` for the portal, inside a systemd sandbox with a read-only view of the system, no capabilities, a system-call filter, and network access limited by `IPAddressAllow` to private address ranges. The sync and broadcast jobs cannot reach the internet at all unless a server outside the private ranges has been named, in which case a drop-in widens those jobs alone. The firewall denies inbound traffic by default and admits ports 80, 8080 and 47002 from private ranges only; if SSH is running it is kept open to the home network with rate limiting. On a server installed with `--expose internet` port 47002 and SSH are open to any address and ports 80 and 8080 to none.

Connections between systems are TLS 1.3 with a pre-shared key derived from the house key by HMAC-SHA256, an ephemeral X25519 exchange, and ChaCha20-Poly1305 or AES-128-GCM. TLS 1.2 and below are refused, as is any client without the key. The transport key is readable by the `offline` account alone and never appears on a command line. Behind the listener, the rsync daemon answers the machine itself only, requires the house key, serves content read-only, refuses options that follow symbolic links, and hides dot-files and partial downloads. Its one writable module is the broadcast inbox, which accepts top-level `.json` files and nothing else and can be neither listed nor read back. What arrives there is treated as hostile until its signature checks: it must be a regular file of at most four kibibytes, and links, folders and oversized files are deleted unread.

The portal is read-only by construction: it answers `GET` and `HEAD` only, keeps no session and sets no cookie, escapes every value it prints, and runs in a PHP-FPM pool confined by `open_basedir` with every function that could run a program, open a connection or write a file disabled. It sends a strict Content-Security-Policy, which holds because the pages contain no inline script or style; scripts may fetch from the portal itself and nowhere else. Bootstrap 5 and Font Awesome are vendored in the repository with checksums, so the portal loads nothing from any other site and works with no connection.

The installer downloads over verified TLS, runs nothing piped from the network into a shell, never sources a configuration file, and passes the house key on standard input so that it reaches neither a process list nor the log.

Some limits are worth knowing. The house key is the whole of the trust model: any system that holds it can read all shared content, write a broadcast, and fill the broadcast inbox, so it should be treated like the key to the house and replaced on every system (`sudo offline key new --force`, then `key set` on the rest) if a machine holding it is lost. Because the transport key is derived from the house key, someone who obtained the house key could impersonate a system, though a recording of earlier traffic would still be unreadable to them. A server exposed to the internet presents the TLS listener to the world; it reveals nothing without the key, but it is one more program facing the internet and must be kept patched. A captured broadcast can be replayed for up to a day to a system that has not yet seen it, which would show a genuine message late and nothing worse. Anyone in the `offline` group on a workstation may send a broadcast. Broadcasts sent to the mesh are as private as the mesh channel, which with the default channel key is not private at all. The site archiver has to be started by root, since it runs a container; the crawl is confined by the container and not by a systemd sandbox. `yt-dlp` comes from Ubuntu's archive and falls behind the sites it reads, so a download that fails there may need a newer copy.

The applications are other people's software: this project confines them but does not audit them, their first-run accounts are for you to create, and Calibre-Web ships with a well-known default password that must be changed at first sign-in. Applications are not blocked from making outbound connections of their own. Open WebUI shares the host's network so that it can reach Ollama on 127.0.0.1, which is less isolation than the others have. The Meshtastic command is installed from PyPI at a pinned version, but its dependencies are not pinned by hash. The portal and the reader use plain HTTP, so what a browser reads from them crosses the home network in the clear; this is acceptable for public reference material on a network you control and is not suited to private files. Ollama's installer and the Msty Studio package are fetched from their vendors over TLS, but neither vendor publishes a checksum for a rolling "latest" download, so they are trusted on the strength of TLS alone; `--no-ai` and `--no-msty` omit them. The portal status is refreshed once a minute, so figures on a page can be up to a minute old.

## Files

```
install.sh                    the installer
bin/offline                   the offline command
lib/core.php                  read-only helpers shared by the command and the portal
lib/cli.php                   the command's actions
lib/maps.php, apps.php, radio.php   the maps, applications and radio commands
lib/server.php                central servers, the download queue, staging and site archiving
lib/broadcast.php             family broadcast: signing, delivery, the mesh and the desktop alert
config/                       settings, catalogues, sites to archive, rsync, stunnel, Apache, PHP-FPM, mDNS, radio channel files
desktop/                      the desktop alert agent, the dialog that sends a broadcast, and their launchers
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

The suite changes nothing outside a temporary directory. It lints the installer, validates the units, checks the hardening settings against regression, and then exercises the real thing: the command against a scratch root and a recorded Kiwix feed; sync between scratch systems through a live rsync daemon and a live stunnel running the shipped configurations, seeded with hostile files, with checks that the wrong key, plain rsync, TLS 1.2 and a client with no key are all turned away; family broadcasts sent, received, forged, altered, replayed and collected late; the queue and staging; and the portal under the same PHP restrictions as the production pool. It also holds every application unit to the three rules above and passes each through Podman's own generator.

## Day-to-day checks

```sh
offline doctor
systemctl status offline-tls offline-rsync offline-reader offline-peer-sync.timer offline-broadcast.path
journalctl -u offline-peer-sync -u offline-broadcast -u offline-broadcast-poll --since today
journalctl -u offline-queue -u offline-archive --since today      # on a server
sudo tail -f /var/log/offline/install.log
```
