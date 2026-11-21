<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$s = portal_status();
$hostName = (string) ($s['host']['name'] ?? gethostname());
$addresses = array_column($s['host']['addresses'] ?? [], 'ip');
$lan = Offline\config('PORTAL_SCOPE') !== 'local';

page_start('Guide', 'guide');
?>
<section class="page-head">
  <h1>Guide</h1>
  <p class="hero-sub">How to read, add and share what is on this system. Commands are typed into a terminal; those that change anything begin with <code>sudo</code>.</p>
</section>

<div class="row g-5">
<div class="col-lg-8 guide">

<section id="read" aria-labelledby="g-read">
  <h2 id="g-read">Read and watch</h2>
  <p>The <a href="<?= e(reader_url()) ?>">library reader</a> opens every installed title in a browser and searches inside them. The <a href="/media.php">media pages</a> play films and music that browsers understand; anything else downloads, or opens in VLC on the system itself.</p>
<?php if ($lan) : ?>
  <p>Phones, tablets and other computers on the home network can use the same pages at
    <code>http://<?= e($hostName) ?>.local/</code><?php foreach ($addresses as $ip) : ?> or <code>http://<?= e($ip) ?>/</code><?php endforeach; ?>. Addresses outside the home network are refused.</p>
<?php else : ?>
  <p>These pages are restricted to this system. To let other devices on the home network use them, set <code>PORTAL_SCOPE=lan</code> in <code>/etc/offline/offline.conf</code> and run the installer again.</p>
<?php endif; ?>
</section>

<section id="add-knowledge" aria-labelledby="g-know">
  <h2 id="g-know">Add library titles</h2>
  <p>This needs an internet connection. See what is on offer, then fetch a whole pack or a single title:</p>
  <pre><code>offline catalogue
sudo offline get essential
sudo offline get medicine-standard wikipedia-top-nopic
sudo offline get wikipedia_en_medicine_maxi</code></pre>
  <p>Before downloading, the command shows the total size and the free space, and asks for confirmation. Every file is checked against the checksum Kiwix publishes. An interrupted download resumes when the same command is run again.</p>
  <p>To find something that is not in a pack, search the whole Kiwix library:</p>
  <pre><code>offline search gardening
sudo offline get gardening.stackexchange.com_en_all</code></pre>
  <p>Titles copied by hand also work: put <code>.zim</code> files in <code>Documents/Kiwix-zims</code> and the reader picks them up within a few seconds.</p>
</section>

<section id="maps" aria-labelledby="g-maps">
  <h2 id="g-maps">Add maps</h2>
  <p>Maps are cut from OpenStreetMap data for exactly the area asked for, then drawn by the <a href="/maps.php">map page</a> with no connection. Fetch the viewer's fonts and world outline once, then any regions:</p>
  <pre><code>offline maps
sudo offline maps setup
sudo offline maps get uk
sudo offline maps get --bbox -6.5,49.8,-1.8,51.7 --name south-west</code></pre>
  <p>The corners are west, south, east and north in degrees. The command works out the size before it downloads and asks first. <code>--maxzoom 12</code> gives a much smaller file without individual buildings.</p>
</section>

<section id="apps" aria-labelledby="g-apps">
  <h2 id="g-apps">Use the apps</h2>
  <p>The <a href="/apps.php">apps page</a> lists what is installed and links to each. Heavier ones are started when wanted:</p>
  <pre><code>offline apps
sudo offline apps start jellyfin
sudo offline apps info jellyfin
sudo offline apps enable jellyfin
sudo offline apps stop jellyfin</code></pre>
  <p><code>info</code> shows the address, how to sign in for the first time and where the data is kept. Jellyfin plays the films and music in <code>/srv/offline/media</code> on any device, including formats a browser cannot play by itself. Kolibri downloads its courses from inside its own pages while online.</p>
</section>

<section id="radio" aria-labelledby="g-radio">
  <h2 id="g-radio">Use the radios</h2>
  <p>The <a href="/radio.php">radio page</a> holds the PMR446 channel table, the mesh settings and the receiver commands. To see what is plugged in and whether this system can talk to it:</p>
  <pre><code>offline radio
offline radio listen pmr
offline radio mesh nodes</code></pre>
</section>

<section id="update" aria-labelledby="g-update">
  <h2 id="g-update">Keep titles current</h2>
  <pre><code>sudo offline update
sudo offline prune
sudo offline verify</code></pre>
  <p><code>update</code> fetches newer editions of what is installed and removes the edition each replaces. <code>prune</code> removes superseded editions left behind. <code>verify</code> re-reads each file and compares it with the checksum Kiwix publishes, which is worth doing for titles that arrived from another system.</p>
</section>

<section id="add-media" aria-labelledby="g-media">
  <h2 id="g-media">Add films, music and books</h2>
  <p>Copy files into the folders under <code>/srv/offline/media</code> (<code>movies</code>, <code>tv</code>, <code>music</code>, <code>radio</code>, <code>books</code>). Sub-folders are fine. MP4 and WebM video and MP3, FLAC, Ogg and M4A audio play in the browser.</p>
  <p>To save a video or a playlist while online:</p>
  <pre><code>cd /srv/offline/media/movies
yt-dlp -f "bv*[ext=mp4]+ba[ext=m4a]/b[ext=mp4]" ADDRESS</code></pre>
</section>

<section id="share" aria-labelledby="g-share">
  <h2 id="g-share">Share between systems</h2>
  <p>Systems exchange content only with others that hold the same house key. Show the key on one system and enter it on each of the others:</p>
  <pre><code>sudo offline key show
sudo offline key set XXXXX-XXXXX-XXXXX-XXXXX-XXXXX</code></pre>
  <p>From then on each system checks the network every five minutes and copies anything it lacks. To run an exchange at once, or to see what one would fetch:</p>
  <pre><code>sudo offline sync
sudo offline sync --dry-run
offline peers</code></pre>
  <p>The key is a shared secret for the household. Every connection between two systems is encrypted with a key derived from it, and a system that does not hold it cannot connect at all, let alone see what is on offer.</p>
</section>

<section id="servers" aria-labelledby="g-servers">
  <h2 id="g-servers">Use a central server</h2>
  <p>A server is the same software without the desktop. It downloads and holds content for the whole household, and there may be several: one at home, one in a datacentre that a system away from home can still reach. Name a server on each system that should pull from it:</p>
  <pre><code>sudo offline server add files.example.org
sudo offline server test
offline server</code></pre>
  <p>On a server, downloads go into a staging area first and are shared only once published:</p>
  <pre><code>sudo offline queue add ADDRESS --to movies
sudo offline queue site uk
offline stage
sudo offline stage publish all</code></pre>
</section>

<section id="broadcast" aria-labelledby="g-broadcast">
  <h2 id="g-broadcast">Send a family broadcast</h2>
  <p>A broadcast goes to every screen in the household and to the mesh radios. Open <em>Family broadcast</em> from the applications menu, or type:</p>
  <pre><code>offline broadcast "Dinner is ready"
offline broadcast --urgent "Come home now"
offline broadcast</code></pre>
  <p>The last command lists recent broadcasts and shows which systems the most recent one from here reached. A system that was switched off collects what it missed within a minute of waking. The mesh carries about two hundred characters, so keep an urgent message short.</p>
</section>

<section id="games" aria-labelledby="g-games">
  <h2 id="g-games">Play</h2>
  <p>The <a href="/games.php">games page</a> lists what is on this system, the family's Luanti worlds and how to join them, and the activities that run in a tablet's browser.</p>
  <pre><code>offline games
sudo offline games install
sudo offline games host all
sudo offline games activities</code></pre>
</section>

<section aria-labelledby="h-people">
  <h2 id="h-people">Accounts for adults and children</h2>
  <p>Anyone who has not signed in sees the children's pages. Adults sign in for everything else. An account made on one system reaches every other system within a few minutes, with the same password for the portal and for logging in.</p>
  <pre><code>sudo offline user add NAME --adult
sudo offline user add NAME --child
offline user
sudo offline user passwd NAME</code></pre>
  <p>Films and music put in <code>/srv/offline/media/children</code> can be watched by everyone. The children's shelf holds the library titles listed for children; add your own choices to <code>/etc/offline/children.local.tsv</code> and run <code>sudo offline library</code>.</p>
  <p class="row-note">A child's login account on a workstation has no administrator rights and cannot reach the internet. Administrator rights are given only on the system where <code>--admin</code> is typed.</p>
  <p class="row-note">To stop a telephone or tablet warning about this system's certificate, open <a href="/household-ca.crt">/household-ca.crt</a> on it once and compare the fingerprint it shows with <code>offline tls</code>.</p>
</section>

<section id="vault" aria-labelledby="g-vault">
  <h2 id="g-vault">Keep the family's papers</h2>
  <p>The vault is an encrypted folder for passports, insurance, medical notes and the like. It is created once, on one system, and every other system receives a copy it cannot read. Opening it asks for the passphrase and shows an ordinary folder called <code>Vault</code> in your home folder, for you alone; it closes itself after half an hour without use.</p>
  <pre><code>offline vault init
offline vault open
offline vault close</code></pre>
  <p>The passphrase is stored nowhere. Write it down and keep it with the papers it protects.</p>
</section>

<section id="hotspot" aria-labelledby="g-hotspot">
  <h2 id="g-hotspot">When the router is gone</h2>
  <p>A system with a Wi-Fi adapter can become the house's network. Telephones and tablets join it and open <code>http://offline.lan/</code>. Devices on the hotspot reach this system and nothing beyond it.</p>
  <pre><code>sudo offline hotspot on
sudo offline hotspot show
sudo offline hotspot off</code></pre>
</section>

<section id="rebuild" aria-labelledby="g-rebuild">
  <h2 id="g-rebuild">Rebuild a system, or move content by disc</h2>
  <p>A home server keeps Ubuntu's packages and an installer image, so that a replacement system can be set up with no internet: install Ubuntu from the image, then run the installer with <code>--apt-cache SERVER</code>.</p>
  <pre><code>sudo offline rebuild warm
sudo offline rebuild image
offline rebuild status --verify</code></pre>
  <p>Shared content can be copied to a disc and brought in elsewhere. What comes in from a disc passes the same checks as content from another system.</p>
  <pre><code>sudo offline export /media/you/DISC/offline
sudo offline import /media/you/DISC/offline</code></pre>
</section>

<section id="directions" aria-labelledby="g-directions">
  <h2 id="g-directions">Find places and routes</h2>
  <p>The <a href="/maps.php">map page</a> finds towns, UK postcodes and coordinates once the place index is fetched, and gives directions by car, bicycle or on foot once a routing graph is built. Both are worked out on this system.</p>
  <pre><code>sudo offline maps places
sudo offline maps routing build uk
sudo offline maps routing on
offline maps find penryn</code></pre>
</section>

<section id="power" aria-labelledby="g-power">
  <h2 id="g-power">Save power</h2>
  <pre><code>offline power
offline power saver
offline power balanced</code></pre>
  <p>The first command shows the battery, the estimated running time and the power profile. On battery below <?= e((string) Offline\config_int('SYNC_MIN_BATTERY', 0, 100)) ?> % the system stops fetching from the others until it is charged again.</p>
</section>

<section id="health" aria-labelledby="g-health">
  <h2 id="g-health">Check that everything works</h2>
  <pre><code>offline status
offline doctor</code></pre>
  <p><code>status</code> prints what the overview page shows. <code>doctor</code> runs each check in turn and prints the command that fixes anything it finds.</p>
</section>

<section id="ai" aria-labelledby="g-ai">
  <h2 id="g-ai">Ask the local AI model</h2>
  <p>Ollama runs language models on the system itself, with no internet. It answers only on this system.</p>
  <pre><code>ollama run qwen2.5:0.5b
ollama list</code></pre>
  <p>Small models are quick and frugal with the battery but are often wrong. Treat an answer as a prompt for where to look in the library, not as the reference itself.</p>
</section>

</div>

<aside class="col-lg-4" aria-labelledby="g-ref">
  <h2 id="g-ref">Reference</h2>
  <h3>Where things are kept</h3>
  <dl class="facts facts-stack">
    <dt>Library titles</dt><dd><code>/srv/offline/knowledge/zim</code><br>also reachable as <code>~/Documents/Kiwix-zims</code></dd>
    <dt>Media</dt><dd><code>/srv/offline/media</code></dd>
    <dt>Maps</dt><dd><code>/srv/offline/maps</code></dd>
    <dt>Staging area</dt><dd><code>/srv/offline/staging</code>, not shared until published</dd>
    <dt>App data</dt><dd><code>/srv/offline/apps</code>, not shared with other systems</dd>
    <dt>Mesh firmware</dt><dd><code>/srv/offline/radio/firmware</code></dd>
    <dt>Settings</dt><dd><code>/etc/offline/offline.conf</code></dd>
    <dt>Your own catalogue entries</dt><dd><code>/etc/offline/catalogue.local.tsv</code></dd>
    <dt>Installer log</dt><dd><code>/var/log/offline/install.log</code></dd>
  </dl>
  <h3>Network ports</h3>
  <dl class="facts facts-stack">
    <dt>80</dt><dd>These pages</dd>
    <dt>443</dt><dd>This portal, encrypted, with sign-in</dd>
    <dt><?= Offline\READER_PORT ?></dt><dd>The children's shelf</dd>
    <dt><?= Offline\READER_ADULT_PORT ?></dt><dd>The whole library, for adults who have signed in</dd>
    <dt><?= Offline\TLS_PORT ?></dt><dd>Exchange between systems, encrypted, house key required</dd>
    <dt>11434</dt><dd>Ollama, this system only</dd>
    <dt>8100 to 8500</dt><dd>Apps, each on its own port; see the apps page</dd>
  </dl>
  <p class="row-note">The firewall admits the first three, and the ports of installed apps, from private home-network addresses only, and nothing else.</p>
  <h3>Activity logs</h3>
  <pre><code>journalctl -u offline-peer-sync
journalctl -u offline-rsync
journalctl -u offline-reader</code></pre>
</aside>
</div>
<?php page_end();
