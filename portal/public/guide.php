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
  <p class="hero-sub">How to read, add and share what is on this laptop. Commands are typed into a terminal; those that change anything begin with <code>sudo</code>.</p>
</section>

<div class="row g-5">
<div class="col-lg-8 guide">

<section id="read" aria-labelledby="g-read">
  <h2 id="g-read">Read and watch</h2>
  <p>The <a href="<?= e(reader_url()) ?>">library reader</a> opens every installed title in a browser and searches inside them. The <a href="/media.php">media pages</a> play films and music that browsers understand; anything else downloads, or opens in VLC on the laptop itself.</p>
<?php if ($lan) : ?>
  <p>Phones, tablets and other computers on the home network can use the same pages at
    <code>http://<?= e($hostName) ?>.local/</code><?php foreach ($addresses as $ip) : ?> or <code>http://<?= e($ip) ?>/</code><?php endforeach; ?>. Addresses outside the home network are refused.</p>
<?php else : ?>
  <p>These pages are restricted to this laptop. To let other devices on the home network use them, set <code>PORTAL_SCOPE=lan</code> in <code>/etc/offline/offline.conf</code> and run the installer again.</p>
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
  <p>The <a href="/radio.php">radio page</a> holds the PMR446 channel table, the mesh settings and the receiver commands. To see what is plugged in and whether this laptop can talk to it:</p>
  <pre><code>offline radio
offline radio listen pmr
offline radio mesh nodes</code></pre>
</section>

<section id="update" aria-labelledby="g-update">
  <h2 id="g-update">Keep titles current</h2>
  <pre><code>sudo offline update
sudo offline prune
sudo offline verify</code></pre>
  <p><code>update</code> fetches newer editions of what is installed and removes the edition each replaces. <code>prune</code> removes superseded editions left behind. <code>verify</code> re-reads each file and compares it with the checksum Kiwix publishes, which is worth doing for titles that arrived from another laptop.</p>
</section>

<section id="add-media" aria-labelledby="g-media">
  <h2 id="g-media">Add films, music and books</h2>
  <p>Copy files into the folders under <code>/srv/offline/media</code> (<code>movies</code>, <code>tv</code>, <code>music</code>, <code>radio</code>, <code>books</code>). Sub-folders are fine. MP4 and WebM video and MP3, FLAC, Ogg and M4A audio play in the browser.</p>
  <p>To save a video or a playlist while online:</p>
  <pre><code>cd /srv/offline/media/movies
yt-dlp -f "bv*[ext=mp4]+ba[ext=m4a]/b[ext=mp4]" ADDRESS</code></pre>
</section>

<section id="share" aria-labelledby="g-share">
  <h2 id="g-share">Share between laptops</h2>
  <p>Laptops exchange content only with others that hold the same house key. Show the key on one laptop and enter it on each of the others:</p>
  <pre><code>sudo offline key show
sudo offline key set XXXXX-XXXXX-XXXXX-XXXXX-XXXXX</code></pre>
  <p>From then on each laptop checks the network every five minutes and copies anything it lacks. To run an exchange at once, or to see what one would fetch:</p>
  <pre><code>sudo offline sync
sudo offline sync --dry-run
offline peers</code></pre>
  <p>The key is a shared secret for the household. It proves that the other machine is one of yours; it does not encrypt the transfer, so the content travels over the home network in the clear, as it would from any media server.</p>
</section>

<section id="power" aria-labelledby="g-power">
  <h2 id="g-power">Save power</h2>
  <pre><code>offline power
offline power saver
offline power balanced</code></pre>
  <p>The first command shows the battery, the estimated running time and the power profile. On battery below <?= e((string) Offline\config_int('SYNC_MIN_BATTERY', 0, 100)) ?> % the laptop stops fetching from the others until it is charged again.</p>
</section>

<section id="health" aria-labelledby="g-health">
  <h2 id="g-health">Check that everything works</h2>
  <pre><code>offline status
offline doctor</code></pre>
  <p><code>status</code> prints what the overview page shows. <code>doctor</code> runs each check in turn and prints the command that fixes anything it finds.</p>
</section>

<section id="ai" aria-labelledby="g-ai">
  <h2 id="g-ai">Ask the local AI model</h2>
  <p>Ollama runs language models on the laptop itself, with no internet. It answers only on this laptop.</p>
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
    <dt>App data</dt><dd><code>/srv/offline/apps</code>, not shared with other laptops</dd>
    <dt>Mesh firmware</dt><dd><code>/srv/offline/radio/firmware</code></dd>
    <dt>Settings</dt><dd><code>/etc/offline/offline.conf</code></dd>
    <dt>Your own catalogue entries</dt><dd><code>/etc/offline/catalogue.local.tsv</code></dd>
    <dt>Installer log</dt><dd><code>/var/log/offline/install.log</code></dd>
  </dl>
  <h3>Network ports</h3>
  <dl class="facts facts-stack">
    <dt>80</dt><dd>These pages</dd>
    <dt><?= Offline\READER_PORT ?></dt><dd>Library reader</dd>
    <dt><?= Offline\SYNC_PORT ?></dt><dd>Exchange between laptops, house key required</dd>
    <dt>11434</dt><dd>Ollama, this laptop only</dd>
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
