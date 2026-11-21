# Offline

Offline internet and low-power tools for the house.

One command turns an Ubuntu laptop into a self-contained library: reference works that can be read and searched with no connection, street-level maps, films and music, a local AI model, a set of browser applications, tools for the household's radios, and a web portal that ties them together and shows how the machine is faring. The same command with `--role server` builds a central server instead, which downloads and stages content for the others; there may be several, at home and in a datacentre. Every system holding the household's key exchanges whatever one has that another lacks over an encrypted channel, so content downloaded once reaches all of them, and a family broadcast written on any one appears on every screen and goes out over the mesh radios.

For the children there are games, among them worlds in the style of Minecraft that the family builds in together. For the day the internet and the router are both gone there is an encrypted folder for the family's papers, a Wi-Fi network the system can raise by itself, a clock the household can agree on, place search and directions on the maps, and the means to rebuild a broken system from what the house already holds.

The household's people have accounts, of two kinds. Without signing in, every screen in the house shows the children's pages: games, the films and books chosen for them, and maps. Adults sign in for the whole library, every film and the pages about the systems. An account made on any system reaches all of them, and a child's login on a workstation has no administrator rights and no route to the internet.

The systems hold themselves together as a private network. Over any IP path they reach one another through WireGuard; where there is none, family broadcasts still cross by LoRa radio. A third kind of system, the repeater, exists for nothing else: a small box with no screen that joins whatever links it has.

It carries every tool and content collection of [Project NOMAD](https://github.com/Crosstalk-Solutions/project-nomad), rebuilt to this project's own security rules; the table under "Project NOMAD" shows where each one went.

```sh
sudo ./install.sh                                    # a workstation: a laptop or desktop people sit at
sudo ./install.sh --server files.example.org         # a workstation that also pulls from a central server
sudo ./install.sh --role server                      # a central server on the home network, with everything installed
sudo ./install.sh --role server --headless           # the same with no screen: the desktop programs are left out
sudo ./install.sh --role server --expose internet    # a central server in a datacentre
sudo ./install.sh --role repeater                    # a small system that holds the private network together and holds no content
sudo ./install.sh --role server --expose internet --endpoint files.example.org:51820   # a datacentre server the others can call
sudo ./install.sh --hotspot                          # a workstation that is also the house's Wi-Fi when the router is gone
sudo ./install.sh --apt-cache server.local           # rebuild a system with no internet, from the home server's package cache
```

The installer may be run again at any time. A second run upgrades the tools and leaves content, settings and the house key as they were. `sudo ./install.sh --help` lists its options and `--dry-run` shows every step without changing anything.

## What is installed

On a workstation the installer adds VLC and Kiwix for viewing, `yt-dlp`, `aria2` and JDownloader for fetching, Ollama with a small model (`qwen2.5:0.5b`) and Msty Studio for local AI, `powertop` for power tuning, Podman for the browser applications, and the radio tools described below. It adds the children's games described under "Games", and `gocryptfs` for the family vault. A server has everything a workstation has. The programs that need a screen (VLC, Kiwix, Msty Studio, JDownloader, the games) go on a server that has a desktop and are left off one that has none; the installer finds out which it is, and `--headless` or `--desktop` says so outright. A server on the home network also hosts the family's game worlds and the tablet activities, keeps time for the household with `chrony`, and holds Ubuntu's packages in `apt-cacher-ng` for rebuilding a system. Around those the installer builds services of its own.

The **library reader** (`kiwix-serve`) serves ZIM files to a browser and searches inside them. There are two: the children's shelf on port 8080, whose index holds only the titles chosen for children, and the whole library on port 8443, over TLS, for adults who have signed in. The **portal** (Apache and PHP) is the front page, on port 80 and, encrypted, on port 443. To anyone who has not signed in it is the children's pages. To an adult it shows what is installed, battery and storage, the state of each service, the other systems, current family broadcasts, a media browser with playback, and a guide. **Content sharing** is an rsync daemon that listens on the machine itself only, with a TLS listener in front of it (stunnel, port 47002) that every other system connects to; a **sync** timer fetches from the central servers and from the other systems every five minutes. The **broadcast** service signs, shows and passes on family broadcasts the moment one is written or arrives, and a second timer asks the other systems once a minute for any that were missed. A further timer records the system's status once a minute for the portal, and a path watcher rebuilds the reader's index whenever a ZIM file is added or removed. A server also runs a **download queue** every ten minutes and a **site archiver** every hour.

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
sudo offline maps places [--from FOLDER] [--no-postcodes]   build the place index (GeoNames)
     offline maps find TEXT               a town, a UK postcode or coordinates
sudo offline maps routing build REGION ... | --pbf FILE     build the graph that directions need
sudo offline maps routing on|off          serve directions to the map page

     offline apps                         applications on offer and their state
sudo offline apps add APP ...|all         install (all = the Project NOMAD set)
sudo offline apps start|stop|restart|enable|disable|remove|info APP
sudo offline apps pull APP ...|all        fetch images now, to install later offline

     offline games                        games installed, family worlds and tablet activities
sudo offline games install [GAME ...]     install the desktop games
sudo offline games host [creative|survival|all] | unhost WORLD
sudo offline games start|stop|restart WORLD
sudo offline games activities [remove]    browser activities for tablets (Sugarizer)

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
sudo offline queue add URL ... [--to movies|tv|music|radio|books|children] [--audio]
     offline queue sites                  websites worth archiving, UK ones included
sudo offline queue site NAME|GROUP        archive a website as a library title
sudo offline queue site URL --name NAME [--pages N]
sudo offline queue run | archive | remove ID | retry ID
     offline books [list [SHELF]]         books worth a copy, each held to a checksum
     offline books show NAME | verify
sudo offline books get NAME|SHELF|all     fetch them into the shared books folder
     offline stage                        what has been downloaded and awaits release
sudo offline stage publish ID|all [--to FOLDER] | discard ID

     offline vault                        whether the encrypted family folder exists and is open
     offline vault init | open [FOLDER] | close | passwd     (as yourself, never with sudo)

     offline rebuild [status --verify]    the package cache and the installer images held
sudo offline rebuild cache on|off | warm | offline on|off | clients
sudo offline rebuild image [desktop|live-server] [--release 26.04]
sudo offline export FOLDER [--what knowledge,media,maps,vault]   copy shared content to a disc
sudo offline import FOLDER [--what ...] [--dry-run]              bring content in from a disc

     offline hotspot                      whether this system is acting as a Wi-Fi access point
sudo offline hotspot on [--ssid NAME] [--interface IF] [--band bg|a] [--channel N] | show | off | remove
     offline time                         the clock, and where it is set from
sudo offline time serve on|off | sources

     offline net                          this system's place on the household's private network
sudo offline net join | endpoint HOST:PORT|none | forget NAME | leave
sudo offline net radio on|off             carry family broadcasts over LoRa and the other links

     offline user                         the household's accounts
sudo offline user add NAME --adult|--child [--full "Full name"] [--admin] [--portal-only]
sudo offline user passwd NAME | lock NAME | unlock NAME | remove NAME | apply
     offline tls [renew]                  this system's certificate and the household's certificate authority

sudo offline sync [--dry-run] [--force]   fetch new content from servers and peers now
sudo offline key [show|new|set [KEY]|refresh]   the house key every system must share
```

Commands that only report run as any user, and so does `offline broadcast`, for anyone in the `offline` group. Commands that change content are run with `sudo` and immediately give up root, continuing as the unprivileged `offline` account. Some keep root because the thing they change belongs to the system: `offline key`, because the key file belongs to root; the `offline apps` and `offline games` actions that install services; `offline server add` and `remove`, which edit the settings; `offline queue archive` and `offline maps routing build`, `on` and `off`, which start containers; `offline hotspot`, `offline time`, `offline user`, `offline tls`, `offline net` and the `offline rebuild` actions that configure a service; and `offline export` and `import`, because a disc may be readable by root alone, although what they copy still passes the same filters. `offline vault` is the exception in the other direction: it is run as yourself, and `open` and `close` refuse to run as root, so that the open folder belongs to the person who gave the passphrase.

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

### Finding places and directions

The map page can find a place and plan a route to it, both worked out on the system itself.

```sh
sudo offline maps places
offline maps find penryn
offline maps find "TR10 8AB"
sudo offline maps routing build uk
sudo offline maps routing on
```

`maps places` fetches GeoNames' gazetteer and builds an index from it: every town and village in the world with more than five hundred inhabitants, the hills, waters, woods, railway stations, hospitals, castles, lighthouses and airports of Great Britain, and, unless `--no-postcodes` is given, every postcode in Great Britain. Northern Ireland's postcodes are not expected to be in that file, which follows Ordnance Survey's open data for Great Britain; `offline maps find BT1` shows whether they are. The search box on the map page and `offline maps find` accept a name, with or without its accents, the beginning of a name, a full or partial postcode, or a pair of coordinates; where two places share a name, the page lists the one nearer the middle of the view first. A system with no internet builds the same index from files brought on a disc with `--from FOLDER`. GeoNames' data is published under Creative Commons Attribution 4.0 and is credited on the map page.

Directions come from [Valhalla](https://github.com/valhalla/valhalla), by car, bicycle or on foot, with distances in miles. `maps routing build` downloads the OpenStreetMap extract of each region named from Geofabrik and builds a routing graph from it in a container that has no network at all; `--pbf FILE` builds from an extract you already hold. Fifteen regions are predefined, among them the United Kingdom, England, Scotland and Wales separately, and the island of Ireland. `maps routing on` then starts the engine, which listens on the system itself only, and the portal passes route requests to it under the same home-network rule as everything else. A graph for a country takes from minutes to hours to build and several gigabytes of memory, so build it on the server; the index and the graph are shared with the other systems like any map file. Each of them then needs only `maps routing on`, which fetches the engine's container image the first time and so needs the internet once.

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

## Games

`offline games` and the portal's games page list what there is to play. The installer puts these on a workstation; `--no-games` leaves them out.

| Game | Ages | What it is |
| --- | --- | --- |
| Luanti with Mineclonia | 6 and up | Block-building in the style of Minecraft, alone or in the family's worlds |
| GCompris | 2 to 10 | More than a hundred activities: letters, numbers, clocks, money, science, chess |
| Tux Paint | 3 to 12 | Drawing and painting with stamps and effects |
| Tux, of Math Command | 6 to 12 | Arithmetic as an arcade game |
| Tux Typing | 6 and up | Learning to type |
| SuperTuxKart | 6 and up | Kart racing, on a split screen or across the home network |

[Luanti](https://www.luanti.org/), which was called Minetest until 2024, is a free engine for block-building games, and [Mineclonia](https://codeberg.org/mineclonia/mineclonia) is the game for it that follows Minecraft most closely. It has nothing to do with Mojang or Microsoft: no account, no sign-in, no shop, and no connection to anything outside the house.

A server on the home network hosts two **family worlds**, and `sudo offline games host all` does the same on any other system. `creative` (port 30000) gives every block freely and nobody can be hurt. `survival` (port 30001) has gathering, crafting and exploring with no monsters, no fighting between players, and belongings kept after a fall. To join, open Luanti, choose *Join Game*, and give the server's name and the port. The first person to join under the name `parent` becomes the administrator of that world, so an adult should do so before the children arrive; every player sets a password at first joining, and an empty one is refused. The worlds are never announced to Luanti's public server list. Each world's settings are in `/etc/offline/games/`, and an upgrade leaves edited settings alone.

For tablets and telephones there is [Sugarizer](https://sugarizer.org/), about sixty activities that run in any browser, an iPad's included: painting, memory games, mazes, chess, and blocks for learning to program. `sudo offline games activities` installs it and serves it on port 8600; what a child makes is kept in that browser.

Two limits should be known before anything is promised to a child. Luanti has no version for the iPad, so the family worlds are played on the laptops and the tablets get Sugarizer. And Mineclonia is written for Luanti 5.10 or newer: Ubuntu 26.04 carries 5.10, while Ubuntu 24.04 carries 5.6, which is older than the game supports, and `offline games` and `offline doctor` say so where it applies.

## The private network, and repeaters

There are three kinds of system. A **server** holds the household's content and has everything installed. A **workstation** is what adults and children sit at. A **repeater** holds no content at all: it is a small machine with no screen, a spare NUC or a Raspberry Pi, whose whole job is to keep the others in touch over whatever links it has.

```sh
sudo ./install.sh --role repeater
offline net
sudo offline net endpoint attic.example.org:51820
sudo offline net radio on
sudo offline net forget old-laptop
```

The network has two layers, and both are closed to anyone without the house key.

**Wherever there is an IP path**, whether the home network, the internet or a mobile connection, systems reach one another through WireGuard. The installer joins every system: it makes a key pair, which never leaves the system, and takes an address in `10.47.0.0/16` that is worked out from the public key, so no two systems need to agree on numbering and none can claim another's address. Every tunnel also requires a pre-shared key derived from the house key. Systems learn of one another from a short list of names, public keys and addresses that travels with the sync, exactly as the accounts do. A tunnel needs one end that can be called, so a system that others can reach from outside says where: a server in a datacentre gives its own name, and a home system gives the household's public name and a UDP port that the router forwards to it. Every other system then keeps a tunnel open to each system that can be called, and those that can be called keep tunnels to one another. Through the tunnel a system away from home reaches the portal, the library, the films, the applications and the sharing port of every server, as if it were in the house.

**A server exposed to the internet** offers the world two things only, the keyed sharing port and the WireGuard port, and WireGuard does not answer a packet that is not from a holder of the right keys. Everything else on it is installed and running and answers the private network alone.

**Where there is no IP path**, short messages still get through. The radio layer is [Reticulum](https://reticulum.network/), which runs over LoRa radios, over packet radio modems, and over the home network and the tunnels as well. It carries family broadcasts: a broadcast written anywhere is sent on every link each system has, and each system that receives one passes it on once, so a message crosses several hops by whichever paths exist. A system passes on only what it has verified, since a broadcast heard by radio goes through the same signature check as one arriving any other way. One LoRa packet holds about 380 bytes, which after the envelope leaves room for roughly 170 characters; a longer broadcast travels by the IP routes and the Meshtastic mesh only.

A LoRa radio for this layer is a board running the **RNode** firmware, plugged in by USB and found by name. The same inexpensive boards that run Meshtastic run RNode, but a board runs one or the other, so the radios of this layer are separate from the Meshtastic nodes, which carry broadcasts to the handsets as before. The defaults are for the licence-exempt band at 865 to 868 MHz: 867.2 MHz, 25 mW, and no more than one per cent of the time on air, which the radio enforces. They are settings in `offline.conf`, and should be checked against Ofcom's IR 2030 before being changed. **Packet radio** through a KISS modem is supported and is off by default, deliberately: this network is encrypted, and on amateur frequencies encrypted traffic is not permitted, so it is for a modem on frequencies where it is.

## People: adults and children

```sh
sudo offline user add peter --adult --admin --full "Peter"
sudo offline user add isabella --adult
sudo offline user add robin --child
offline user
sudo offline user passwd robin
sudo offline user lock robin
sudo offline user remove robin
```

The command asks for a password twice and never takes one from the command line. An adult's needs twelve characters and a child's six, because a seven-year-old has to type it and nothing a child's account is kept from rests on that password. What is stored is two hashes and never the password: Argon2id for the portal and SHA-512-crypt for logging in.

**An account made on any system reaches all of them.** The record travels with the next sync, which is at most five minutes away, to every system that holds the house key, and each system then brings its own login accounts into line by itself: the person can sign in to every portal and log in at every system with the one password. A change of password, a lock and a removal follow the same way. Removal locks the login account on every system and keeps its files; `sudo deluser --remove-home NAME` deletes them where that is wanted.

Three things do not travel, each for a reason. *Administrator rights* are given only on the system where `--admin` is typed, and replication never changes the login password of an account that has them on a system, so that nobody who obtained the house key could make themselves root everywhere; `sudo offline user apply`, run by an administrator on that system, brings such an account into line. *A child's login account* is made only on a system with a desktop, since there is nowhere else for a child to log in, and child accounts are refused SSH on every system. *A server exposed to the internet* takes the records and makes no login accounts from them, so that replication cannot add password logins to a machine whose SSH faces the world; `ACCOUNT_LOGINS=yes` in its settings overrides that, and `ACCOUNT_LOGINS=no` stops any system making login accounts from the records. `--portal-only` makes an account that signs in to the portal and logs in nowhere, which suits a grandparent.

### What a child sees

Anyone who has not signed in sees the children's pages, on every device: five large doors marked Play, Watch, Read, Make and Maps. **Play** is the games page. **Watch** shows the folder `/srv/offline/media/children` and nothing else; whatever an adult puts there, or publishes there with `sudo offline stage publish ID --to children`, the whole house can watch. **Read** is the children's shelf: the library titles listed in `config/children.tsv`, which are Vikidia, the PhET simulations, picture books and TED's talks and lessons for children. `sudo offline get children` fetches a starter set of about 4 GiB. A title is on the shelf only if it is listed, and your own choices go in `/etc/offline/children.local.tsv`. Wikipedia in simple English is deliberately not listed, because it is written simply and still covers every subject; one line in the local file adds it if that suits your household. **Make** is Sugarizer, and **Maps** is the map page with its place search and directions.

Everything else is closed until an adult signs in, and it is closed in three places and not by the pages alone. The pages send the visitor to sign in. The web server releases films outside the children's folder only to a signed-in adult. And the reader that anyone can open holds the children's index and nothing more, while the whole library sits behind the web server on a port of its own. An application with no sign-in of its own (CyberChef, IT Tools, Stirling PDF, the translated library and the two radio clients) is put behind the portal's; Kolibri and Excalidraw are open to children; the others have their own sign-in, and that is what keeps a child out of them.

### Signing in

An adult signs in at `https://HOSTNAME.local/account/signin.php`, which the *Grown-ups* button on every children's page leads to. A session lasts twelve hours, ends at once when the account is locked, removed or given a new password on any system, and ends when the system restarts. After five wrong passwords for an account from one address, or twenty from one address in all, that address waits fifteen minutes. Sign-ins and refusals are written to the system log.

Signing in is over HTTPS, and every system has a certificate for it. The certificates are signed by a **household certificate authority** whose key is derived from the house key and stored nowhere, so every system holding the house key signs its own certificate under the one authority with nothing to distribute. `offline tls` shows the authority's fingerprint. A telephone or tablet is given the authority once, from `http://HOSTNAME.local/household-ca.crt`; after that it accepts every system in the house without a warning. The authority may sign only for names ending in `.local` or `.lan`, for `localhost`, and for private addresses, and that limit is written into its certificate, so even someone who obtained the house key could not make a certificate that a family device would accept for a public website. Certificates last 397 days and are renewed by a weekly timer.

### A child's account on a workstation

A child logs in to an ordinary desktop, reduced to what is theirs.

The account has no administrator rights and belongs to one group, `offline-child`, and to no other. The **children's guard** is a firewall rule on that group: a child's account may reach the portal, the children's shelf, the tablet activities, Kolibri, Excalidraw, the family worlds and a kart race on the home network, and nothing else. The internet is refused, and so is every service the system or the home network keeps for adults, such as the whole library, the applications, the local AI and the sharing ports. The library and media folders are closed to the group by name, so the films cannot be opened from the file manager either. The children's pages open by themselves at login. Where GNOME's parental controls are installed (`malcontent`), the terminal, the software centre, the disc utility and the adults' programs are neither listed nor startable, and nothing can be installed.

## Family vault

The vault is an encrypted folder for the papers a household needs when things go wrong: passports, insurance, medical notes, account details. It is made once, on one system.

```sh
offline vault init          # choose the passphrase; once, on one system
offline vault open          # asks for the passphrase and shows ~/Vault
offline vault close
offline vault passwd        # change the passphrase
```

While it is open the vault is an ordinary folder in your home folder, visible to you alone, and it closes by itself after `VAULT_IDLE_MINUTES` (thirty) without use. What is kept on disc, in `/srv/offline/vault`, is ciphertext made by [gocryptfs](https://nuetzlich.net/gocryptfs/): file contents and file names are encrypted, and the passphrase is stored nowhere. That ciphertext is exchanged between systems like any other content, so every system in the house holds a copy of the vault that it cannot read, and any of them can open it for a member of the `offline` group who knows the passphrase. Write the passphrase down and keep it with the papers it protects; if it is lost, the vault is lost.

Three things follow from the design. Where two systems hold different copies of one file, the newer replaces the older, so two people editing the same document on two systems at once will lose one of the edits. A file deleted from the vault on one system is not deleted from the others, in keeping with the rule that sync never deletes; it returns at the next sync unless it is removed everywhere. And a datacentre server receives the ciphertext too unless `VAULT_SYNC=no` is set there, which is the right setting for any system that should not hold the vault at all.

## When the router is gone

A system with a Wi-Fi adapter can become the house's network.

```sh
sudo offline hotspot on
sudo offline hotspot show
sudo offline hotspot off
```

`hotspot on` creates a network named after the system, protects it with WPA2 and a generated passphrase of four groups of four characters, and prints both, with a QR code for telephones to scan. Devices that join are given addresses by this system and can open the portal at `http://offline.lan/`. They reach this system and nothing beyond it: nothing arriving from the hotspot is forwarded to any other network, and its name service answers for this system alone. The passphrase is kept when the hotspot is switched off and reused when it is switched on again, so devices that have joined once rejoin without help. The adapter must support access-point mode, which most built-in laptop adapters do; a second adapter lets the system stay on the home network at the same time.

Clocks matter more than they seem to: broadcasts are refused when they appear to be more than a day old, and a laptop that has been in a cupboard for a year can be badly wrong. A server on the home network therefore **serves time** to the household and goes on doing so from its own clock when there is no internet to check it against. Every system that names a server on a private address asks it for the time as well as the usual internet source. `offline time` shows where the clock is being set from.

## Rebuilding a system, and moving content by disc

A laptop whose disc has failed cannot be rebuilt from the internet when there is none. A server on the home network therefore keeps what a rebuild needs.

```sh
sudo offline rebuild warm                 # on the server, while there is still an internet
sudo offline rebuild image                # the Ubuntu installer, verified
offline rebuild status --verify
sudo ./install.sh --apt-cache server.local   # on the replacement system
```

The server runs a **package cache** that holds every Ubuntu package fetched through it. `rebuild warm` fetches everything the installer asks for, with all that those packages depend on, so that the cache can answer for a complete installation; the test suite checks that the list it works from and the installer agree. `rebuild image` downloads an Ubuntu installer image and accepts it only if its SHA-256 appears in a checksum list that carries a valid signature from Ubuntu's own key. `rebuild offline on` tells the cache to answer from what it holds without trying the internet first, which saves a long wait on each package when the connection is gone. Every system that names a server on a private address is set to try its cache before going direct, and goes direct if the cache does not answer within a second, so a laptop away from home is not held up.

A rebuild then runs like this: install Ubuntu from the image, copy this repository from a disc, and run the installer with `--apt-cache`. Packages come from the cache, content comes back at the first sync, and the steps that need the internet are skipped and can be run later. The cache covers Ubuntu's packages. It does not cover what the installer fetches from elsewhere: the application images, Mineclonia, Sugarizer, the map tool, the Meshtastic command, Ollama, Msty Studio and JDownloader. Those wait for a connection, and the installer can be run again when there is one.

Content can also travel on a disc, for a system that the network does not reach or a relative's house.

```sh
sudo offline export /media/peter/DISC/offline
sudo offline import /media/peter/DISC/offline --dry-run
sudo offline import /media/peter/DISC/offline
```

`export` copies library titles, media, maps and the vault's ciphertext, and nothing else. `import` treats a disc exactly as sync treats another system, since a disc may have been anywhere: only accepted kinds of file come in, links and hidden folders are ignored, a library title that is not a real ZIM file is discarded, and an older edition of a title already held is left on the disc.

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

The first copy from a server can run for hours, and it says what it is doing while it runs. The moment a server answers and accepts the house key, that is written down; while files are being fetched, `offline doctor`, `offline status`, `offline server` and the portal all report a sync in progress, since when, and what it is fetching; and the totals are added when the run ends. `sudo offline server test` records what it finds in the same place, so a server that has passed its test is shown as one whose key was accepted, with a note that no sync from it has finished yet. A sync that stands aside because a download holds the same lock is reported as having stood aside, with the reason. Each warning in the health check is therefore one of four distinct things: never tried, tried and refused, running now, or held back.

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

The `uk` group in `config/sites.tsv` holds three sites: the Government's *Be Ready* emergency preparedness advice, NHS Health A to Z, and the Highway Code. Each is published under the Open Government Licence v3.0, which permits copying with acknowledgement of the source. The NHS asks that copies be kept current, so that one should be archived again every few months. The `cooking` group is described with the outdoor cooking shelf below, and is on different terms: the note against every line of the list says what they are. Additions of your own go in `/etc/offline/sites.local.tsv`.

A crawl stays beneath the address given, stops at its page limit, fetches two pages at a time, and identifies itself. The result arrives in staging like any other download and joins the library when published. zimit drives a real browser, so it runs in a container pinned by digest, in its own user namespace, with no new privileges, memory and process limits, and one writable folder; a large site takes hours.

### Books, and the outdoor cooking shelf

A library title is a website made portable. A book is a single document, and `offline books` fetches documents by address into `/srv/offline/media/books`, where the portal's media browser shows them and from where every other system receives them at its next sync.

```sh
offline books
offline books show geezer-cookbook
sudo offline books get outdoor-cooking
sudo offline queue run
offline books verify
```

Each line of `config/books.tsv` records an address, the size of the file and its SHA-256. A fetched file is checked against that line when it lands and again at the moment it leaves staging, and a file that differs by a single bit is discarded with the reason recorded in the queue, whichever machine served it. Its type is read from its first bytes and never from its name; a symbolic link is never taken for a book; and only the named file is published, whatever else has found its way into its staging folder. A book that matches its line is published as it arrives, because the review happened when the line was written. `offline books verify` reads every book that is here against its line again, and a book that has been altered since is reported and left for a person to deal with.

The shipped list is one shelf of 39 books and about 340 MiB, `outdoor-cooking`, in four parts. `outdoor-cooking/dutch-oven` is Dutch-oven and one-pot cooking: *The Geezer Cookbook* and its 350 recipes, two manuals of method, and three short books on one-pot meals, light ovens and baking in a pot. `outdoor-cooking/camp-cookbooks` is recipes cooked by Scout troops in camp, the 359-page *Soaring Eagle District Cookbook* among them. `outdoor-cooking/classics` is the old masters, from Maria Parloa in 1878 to Horace Kephart's *Camp Cookery* and Daniel Beard in 1920. `outdoor-cooking/field-kitchens` is cooking for numbers over open fires: the British *Manual of Military Cooking* of 1910 and 1915, two American army manuals, the Forest Service's *Camp Stoves and Fireplaces*, and eight radio talks on campfire meals and pit barbecue from the United States Department of Agriculture.

The rights column of the list says what stands behind each line. Twenty-six books are `pd`, in the public domain by age or as the work of a government. Eight are `cc`, under a Creative Commons licence given by their author or editor. Five are `marked`: the person who placed them in the Internet Archive marked them as free to copy, and nothing in the documents themselves confirms it, so each is a copy for your own household and not for passing on. Many of the files are the Internet Archive's own PDF of a scanned book, which the Archive may one day regenerate; such a book then fails its check, safely, until its line is brought up to date.

Additions of your own go in `/etc/offline/books.local.tsv`, where a line may also replace a shipped one. There alone the checksum and the size may each be a hyphen, for a document whose checksum cannot be known beforehand. Such a book is checked for its type and nothing else, and waits in staging for an adult to release it.

Two more things complete the shelf. The `outdoor-cooking` pack is what Kiwix publishes ready-made that bears on cooking over fire, about 570 MiB: the cooking and outdoors questions and answers, three recipe collections that are free to copy, a set of one-pot recipes from a small boat, and ScoutWiki. `outdoor-cooking-extra` adds the two large open collections, about 13 GiB between them: Wikibooks, for its Cookbook, and every Project Gutenberg book classed under technology, which is where Gutenberg files cookery and with it the old camp cookery manuals. The `cooking` group of `config/sites.tsv` then names seven recipe sites for a server to archive. One is the United States National Park Service. The other six are ordinary copyrighted sites whose owners have granted nothing, and the list says so against each: a copy of one is for your own household's use, to be read when the site cannot be reached.

```sh
sudo offline get outdoor-cooking
sudo offline queue site cooking
```

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

Library titles, media, maps (with the place index and the routing graph), the vault's ciphertext, the account records and the list of the household's systems are exchanged; application data, the game worlds and the staging area are not. The configured servers are tried first. Systems on the home network announce themselves by mDNS and, where that finds nothing, each address of a small local network is checked directly. Addresses are checked in parallel, so a scan takes a few seconds at most. What a system offers is then filtered before anything is fetched. Only library files, documents, films, music, subtitles, pictures and map files are accepted, and never programs, scripts or web pages. Paths with traversal or hidden components are rejected. An older edition of a title already held is not fetched, which is what allows `prune` to work without the old edition returning from a neighbour. A file claiming to be a ZIM that is not one is discarded and not requested again. Fetching stops before free space falls below `SYNC_MIN_FREE_GB`, and is postponed on battery below `SYNC_MIN_BATTERY` per cent. Nothing is ever deleted because it is absent elsewhere.

Version 2.2 changed how systems talk to one another. Earlier versions shared on port 47001 without encryption; that port now answers the machine itself only. A system on 2.2 and a system on 2.1 therefore cannot exchange anything, so upgrade every system in the household in the same sitting. The house key is kept, and the installer derives the new transport key from it.

Versions 2.2 to 2.5 speak to one another without change, and a system on an older version simply neither offers nor fetches what it does not know about: the vault, the place index and the routing graph before 2.3, the accounts before 2.4, the private network before 2.5. Upgrade every system all the same, and soon: a portal on an older version has no sign-in and shows everything to everyone.

Settings are in `/etc/offline/offline.conf`, a plain list of `KEY=value` lines that is read and never executed.

## Security

Every service runs as an unprivileged account, `offline` for content and `offline-web` for the portal, inside a systemd sandbox with a read-only view of the system, no capabilities, a system-call filter, and network access limited by `IPAddressAllow` to private address ranges. The sync and broadcast jobs cannot reach the internet at all unless a server outside the private ranges has been named, in which case a drop-in widens those jobs alone. The firewall denies inbound traffic by default and admits ports 80, 443, 8080, 8443 and 47002 from private ranges only; if SSH is running it is kept open to the home network with rate limiting. On a server installed with `--expose internet` port 47002 and SSH are open to any address and the portal and reader ports to none. Each further service opens its own port to private ranges when it is switched on and closes it when switched off: 8600 for the tablet activities, 123 (UDP) for time and 3142 for the package cache. The family worlds on ports 30000 and 30001 (UDP) are a special case, because a port published by a container engine passes around the host firewall. Their units therefore carry the rule themselves: systemd filters every packet to and from the game server's sockets, so a world answers private addresses only and cannot itself reach the internet. A server exposed to the internet refuses to host games, serve time, serve packages or raise a hotspot, because none of those belongs on a public address.

Connections between systems are TLS 1.3 with a pre-shared key derived from the house key by HMAC-SHA256, an ephemeral X25519 exchange, and ChaCha20-Poly1305 or AES-128-GCM. TLS 1.2 and below are refused, as is any client without the key. The transport key is readable by the `offline` account alone and never appears on a command line. Behind the listener, the rsync daemon answers the machine itself only, requires the house key, serves content read-only, refuses options that follow symbolic links, and hides dot-files and partial downloads. Its one writable module is the broadcast inbox, which accepts top-level `.json` files and nothing else and can be neither listed nor read back. What arrives there is treated as hostile until its signature checks: it must be a regular file of at most four kibibytes, and links, folders and oversized files are deleted unread.

The portal's pages are read-only by construction: they answer `GET` and `HEAD` only, can read a session and cannot open one, escape every value they print, and runs in a PHP-FPM pool confined by `open_basedir` with every function that could run a program, open a connection or write a file disabled. It sends a strict Content-Security-Policy, which holds because the pages contain no inline script or style; scripts may fetch from the portal itself and nowhere else. Bootstrap 5 and Font Awesome are vendored in the repository with checksums, so the portal loads nothing from any other site and works with no connection.

The family worlds and the routing engine are confined as the applications are, each in its own unprivileged range of host accounts, unable to gain privileges, with images pinned by digest and never pulled unasked. They go further in three ways: no capabilities at all, a read-only system, and for the worlds a game mounted read-only, so that a world can write to its own folder and nowhere else. The routing engine publishes its port on the system itself only; the portal passes it `GET` requests for routes and nothing else. A game cannot pass through the web server, so a world's port is the one port a container publishes to the network, guarded by the address filter described above. Luanti's own mod sandbox is left on. Mineclonia and Sugarizer are fetched by exact git commit, which git verifies byte for byte. The vault's ciphertext is accepted from another system only if every name in it has the shape gocryptfs gives to names, so a vault cannot be used to deliver a plain file or a script, and what arrives is closed to accounts outside the `offline` group. The hotspot uses WPA2 with AES only, with push-button pairing switched off. The package cache serves package archives and refuses to act as a general proxy, and its maintenance pages need a password that is generated when the cache is first switched on.

The private network adds no trust beyond the house key and takes none away. A tunnel needs three things: a system's own private key, which never leaves it; a public key that the other end has learnt from the list; and the pre-shared key derived from the house key. Each peer is allowed its one address and no range, the configuration contains no commands, and a record in the list is believed only if its address is the one its key gives and its name and calling address could not be read as anything else. Reticulum is installed as one wheel pinned by SHA-256, with its two dependencies taken from Ubuntu's signed archive. Every Reticulum interface carries an access code derived from the house key, the group address that broadcasts use is encrypted with another, and the service that runs it is the unprivileged sharing account with serial devices and nothing else from `/dev`, no route to a public address, and the right to write only where broadcasts are received. A test by hand ran a system with the wrong access code and a system with another house key beside two real ones: neither heard anything.

Signing in is kept apart from the rest of the portal. The two pages that accept a form run in a PHP pool of their own, as the account `offline-auth`; it is the only part of the portal that can read the account records or write a session, and it can run no program, open no connection and write nowhere but the session folder. The ordinary pages, and the web server itself, cannot read the account records at all. A session is 256 random bits in a cookie that browsers send over HTTPS only (`__Host-`, `Secure`, `HttpOnly`, `SameSite=Strict`), so it never crosses the home network in the clear and no script can read it. The session list is kept in memory and is what the web server itself consults before releasing a film or passing a request to the whole library, so those are closed even to a request that never touches a page. A page not listed as public is for adults: a page added later and forgotten is closed, not open. A sign-in form sent from another site is refused, signing out needs a proof tied to the session, and the address to return to after signing in can only be one inside the portal. No site sends `Strict-Transport-Security`, deliberately: it binds a host name on every port, and the children's shelf and some applications are plain HTTP on ports of their own.

Replicated accounts are the one place where a system acts as root on what another system sent, so that job is drawn narrowly. Every record must have exactly the shape this software writes, down to the form of its hashes and the characters allowed in a name, or it is ignored; a record dated in the future is ignored too, since it would otherwise win every comparison for ever. The job has no network, a read-only system apart from the account files and home folders, and a bounded set of capabilities. It cannot give administrator rights, will not touch an account that has them, and will not touch one of the system's own accounts.

The installer downloads over verified TLS, runs nothing piped from the network into a shell, never sources a configuration file, and passes the house key on standard input so that it reaches neither a process list nor the log.

Some limits are worth knowing. The house key is the whole of the trust model: any system that holds it can read all shared content, write a broadcast, and fill the broadcast inbox, so it should be treated like the key to the house and replaced on every system (`sudo offline key new --force`, then `key set` on the rest) if a machine holding it is lost. Because the transport key is derived from the house key, someone who obtained the house key could impersonate a system, though a recording of earlier traffic would still be unreadable to them. A server exposed to the internet presents the TLS listener to the world; it reveals nothing without the key, but it is one more program facing the internet and must be kept patched. A captured broadcast can be replayed for up to a day to a system that has not yet seen it, which would show a genuine message late and nothing worse. Anyone in the `offline` group on a workstation may send a broadcast. Broadcasts sent to the mesh are as private as the mesh channel, which with the default channel key is not private at all. The site archiver has to be started by root, since it runs a container; the crawl is confined by the container and not by a systemd sandbox. `yt-dlp` comes from Ubuntu's archive and falls behind the sites it reads, so a download that fails there may need a newer copy.

The private network has limits of its own. Any holder of the house key can add a system to the list, which is the trust model throughout; a lost system is removed with `sudo offline net forget NAME`, and because it still holds the house key the key must be changed too. Tunnels form only with systems that can be called, so two laptops that are both away from home reach each other through a server and not directly. The list shows every system's name and calling address to every holder of the house key. A family broadcast over the radio layer is flooded, each system sending it once; that suits a household and would not suit a town. The radio layer carries broadcasts and nothing else: no files, no accounts, no sync. Radio regulations are yours to keep: the defaults are meant to be lawful in the United Kingdom, and nothing stops them being changed to settings that are not. The tunnel could not be brought up in the test environment, which has no WireGuard in its kernel, so its configuration was checked by WireGuard's own tools and its operation was not; the LoRa interface was configured and read by Reticulum without a radio attached.

The accounts keep a child out of what is not meant for children; they are not a defence against a determined adult. Anyone holding the house key can create an account on every system, an administrator's excepted, which is one more reason to treat that key as the key to the house. The children's pages and the children's shelf are plain HTTP, as before, and only what needs a session is encrypted. Session tokens are held as they are, not hashed, in a file in memory that the web server and the two PHP pools can read, since the web server has to compare them. A session ends within a minute of a lock or a change of password, not within the same second. Applications with their own sign-in (Jellyfin, Calibre-Web, Homebox, Flatnotes, Open WebUI, Vaultwarden, File Browser) are as closed as that sign-in makes them: set a password on each when it is first started, and use Jellyfin's own parental controls if children are given Jellyfin accounts. The children's guard governs a child's account on a workstation; a tablet is not an account, and what a tablet can reach on the internet is for the home router to decide. The guard does not stop a child who can start the computer from other media or who knows an adult's password; a firmware password and full-disc encryption are the remedies for the first. The desktop's parental controls were written to GNOME's documented interface and could not be tried on a real desktop here.

The vault protects papers on a system that is switched off, lost or stolen, and on every system that holds only the ciphertext. It does not protect them from someone who can already act as you, or as root, on a system while the vault is open there, and the number of files in it and their sizes can be seen by anyone who holds the house key. The vault's key file travels with the ciphertext, as it must for the vault to open anywhere, so the strength of the passphrase is the strength of the vault. Anyone on the home network can join a family world under a name not yet taken; the worlds are for a household and have no defence beyond the home network's own. Any device on the home network may ask the package cache for packages and the time server for the time. The package cache speaks plain HTTP, as Ubuntu's mirrors do; packages remain protected by Ubuntu's signatures, which apt checks on every system. Anyone who learns the hotspot's passphrase can use the portal, the reader and the media shares, exactly as a guest on the home Wi-Fi can. Sugarizer needs a looser content policy than the portal, since its activities build pages as they run, so it is served from a port of its own, where it shares nothing with the portal, and may still load nothing from any other site.

The applications are other people's software: this project confines them but does not audit them, their first-run accounts are for you to create, and Calibre-Web ships with a well-known default password that must be changed at first sign-in. Applications are not blocked from making outbound connections of their own. Open WebUI shares the host's network so that it can reach Ollama on 127.0.0.1, which is less isolation than the others have. The Meshtastic command is installed from PyPI at a pinned version, but its dependencies are not pinned by hash. The portal and the reader use plain HTTP, so what a browser reads from them crosses the home network in the clear; this is acceptable for public reference material on a network you control and is not suited to private files. Ollama's installer and the Msty Studio package are fetched from their vendors over TLS, but neither vendor publishes a checksum for a rolling "latest" download, so they are trusted on the strength of TLS alone; `--no-ai` and `--no-msty` omit them. The portal status is refreshed once a minute, so figures on a page can be up to a minute old.

## Files

```
install.sh                    the installer
bin/offline                   the offline command
lib/core.php                  read-only helpers shared by the command and the portal
lib/cli.php                   the command's actions
lib/maps.php, apps.php, radio.php   the maps (with place search and routing), applications and radio commands
lib/games.php                 games, the family worlds and the tablet activities
lib/network.php               the Wi-Fi hotspot and the household's time
lib/vault.php                 the encrypted family vault
lib/net.php                   the private network: WireGuard, the list of systems, and Reticulum's configuration
mesh/offline-mesh-bridge      carries family broadcasts over Reticulum (Python)
lib/auth.php                  account records, password hashes, portal sessions and the limit on guessing
lib/users.php                 the user and tls commands: login accounts, replication, the household certificate authority
lib/rebuild.php               the package cache, installer images, and export and import by disc
lib/server.php                central servers, the download queue, staging and site archiving
lib/books.php                 the books command: documents fetched by address and held to a recorded checksum
lib/broadcast.php             family broadcast: signing, delivery, the mesh and the desktop alert
config/                       settings, catalogues, sites to archive, books to fetch, rsync, stunnel, Apache, PHP-FPM, mDNS, radio channel files,
                              the routing engine's unit, the package list and the package cache detector
desktop/                      the desktop alert agent, the dialog that sends a broadcast, and their launchers
apps/                         one hardened Podman unit per application, and their index
games/                        the list of games, and the units and settings of the family worlds
data/                         remedies reference data
systemd/                      service, timer and path units
portal/                       the web portal (PHP, Bootstrap 5, Font Awesome, MapLibre; light, dark and VT100 themes)
tests/test-offline.sh         the test suite
```

## Tests

```sh
tests/test-offline.sh
```

The suite changes nothing outside a temporary directory. It lints the installer, validates the units, checks the hardening settings against regression, and then exercises the real thing: the command against a scratch root and a recorded Kiwix feed; sync between scratch systems through a live rsync daemon and a live stunnel running the shipped configurations, seeded with hostile files, with checks that the wrong key, plain rsync, TLS 1.2 and a client with no key are all turned away; family broadcasts sent, received, forged, altered, replayed and collected late; the queue and staging; and the portal under the same PHP restrictions as the production pool. It also holds every application unit to the three rules above and passes each through Podman's own generator, and does the same for the family worlds and the routing engine.

For version 2.5 it joins systems to the private network; has WireGuard's own tools check that the public key matches and that the configuration is accepted; feeds the list records that claim another system's address or carry a command where a name should be; replicates the list between systems and to a repeater that holds no content; generates Reticulum's configuration for each role and checks that every interface carries the access code; and feeds the broadcast bridge packets that are malformed, oversized or compressed to explode. Tried by hand as well: Reticulum installed by checksum, and five instances of the bridge joined by local links, in which a broadcast crossed two hops, was not echoed to its sender, and was heard neither by an instance with the wrong access code nor by one with another house key.

For version 2.4 it makes, changes, locks and removes accounts; signs in through the real sign-in pages under their own pool's restrictions, with wrong passwords, forged and expired sessions, a form from another site and an attempt to be sent elsewhere afterwards; replicates accounts between systems, including records that are malformed, oversized or reach for more than they should; checks that every adult page, and every film outside the children's folder, is closed to a visitor who has not signed in, by name and by symbolic link; derives the household's certificate authority on separate systems and confirms that each accepts the other's certificate, that a household with another key does not, and that a certificate signed with the authority's key for a public name is refused; and has the firewall parse the children's guard. Tried by hand on a real web server and real accounts as well: signing in and out, the web server's own refusal of films and of the whole library without a session, the applications' gate, and a child's account that could open the portal and the children's shelf and was refused the whole library, the package cache, the internet and the media folders.

For version 2.3 it creates, opens, writes to and closes a real vault and confirms that nothing readable reaches the disc; exchanges vault ciphertext between systems with plain files, scripts and links planted in it; compares the hotspot profile with the one NetworkManager itself would write and has chrony parse the time settings; builds a place index from GeoNames-format files containing hostile rows and searches it through the command and the portal; exports to a disc, tampers with the disc and imports it; and checks that a checksum list Ubuntu did not sign is refused. Where a tool is missing, such as gocryptfs or a usable FUSE device, the checks that need it are skipped with a note. What the suite cannot do is start systemd services, raise a hotspot on real hardware, or build a routing graph, and those were tried by hand as far as a machine without a Wi-Fi adapter allows.

## Day-to-day checks

```sh
offline doctor
systemctl status offline-tls offline-rsync offline-reader offline-peer-sync.timer offline-broadcast.path
journalctl -u offline-peer-sync -u offline-broadcast -u offline-broadcast-poll --since today
journalctl -u offline-queue -u offline-archive --since today      # on a server
systemctl status offline-game-creative offline-game-survival offline-routing chrony apt-cacher-ng
sudo tail -f /var/log/offline/install.log
```
