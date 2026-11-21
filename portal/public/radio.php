<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use function Offline\{format_mhz, pmr446_channels};

$s = portal_status();
$radio = $s['radio'] ?? ['devices' => [], 'tools' => [], 'firmware' => []];
$apps = array_column($s['apps'] ?? [], null, 'id');
$channels = pmr446_channels();
$meshWeb = ($apps['meshtastic-web']['state'] ?? '') === 'active' ? 'https://' . portal_host() . ':' . (int) $apps['meshtastic-web']['port'] . '/' : null;

page_start('Radio', 'radio');
?>
<section class="page-head">
  <h1>Radio</h1>
  <p class="hero-sub">Voice on PMR446 handsets, text over the Meshtastic mesh, and a USB receiver for listening. This page holds the channel tables and settings to keep everyone on the same frequency when there is nothing to look them up on.</p>
<?php if ($meshWeb !== null) : ?>
  <a class="btn btn-primary" href="<?= e($meshWeb) ?>"><i class="fa-solid fa-tower-broadcast" aria-hidden="true"></i> Open Meshtastic Web</a>
<?php endif; ?>
</section>

<div class="row g-5">
<div class="col-lg-8 guide">

<section aria-labelledby="r-pmr">
  <h2 id="r-pmr">PMR446 voice channels</h2>
  <p>Sixteen analogue channels, narrow FM, 12.5 kHz apart. Channels 1 to 8 are on every handset; 9 to 16 only on newer ones, so agree a channel from the first eight. Range is a few hundred metres among buildings and several kilometres with a clear line of sight.</p>
  <div class="table-responsive">
  <table class="table table-sm">
    <thead><tr><th scope="col">Channel</th><th scope="col">MHz</th><th scope="col">Channel</th><th scope="col">MHz</th></tr></thead>
    <tbody>
<?php for ($c = 1; $c <= 8; $c++) : ?>
      <tr><th scope="row"><?= $c ?></th><td><?= e(format_mhz($channels[$c])) ?></td><th scope="row"><?= $c + 8 ?></th><td><?= e(format_mhz($channels[$c + 8])) ?></td></tr>
<?php endfor; ?>
    </tbody>
  </table>
  </div>
  <p>A handset's "privacy code" is a sub-audible CTCSS tone. It hides other people's traffic from you, not yours from them: anyone on the channel can still hear you. Every handset in the house must use the same channel and the same code. Codes are numbered differently by some makers, so match the frequency in hertz if two makes will not talk.</p>
  <details class="pack">
    <summary><span class="pack-name">CTCSS tones</span><span class="pack-count">code number and frequency in hertz</span></summary>
    <p class="tone-list">
<?php foreach (Offline\CTCSS_TONES as $i => $tone) : ?>
      <span><?= $i + 1 ?>: <?= e(number_format($tone, 1)) ?></span>
<?php endforeach; ?>
    </p>
  </details>
  <p>Listen across all sixteen channels with the USB receiver: <code>offline radio listen pmr</code>. Stay on one with <code>offline radio listen pmr 3</code>.</p>
</section>

<section aria-labelledby="r-mesh">
  <h2 id="r-mesh">Meshtastic mesh</h2>
  <p>Every node must share three settings to hear the others: region, modem preset and channel key. For the United Kingdom and the European Union the region is <code>EU_868</code>.</p>
  <dl class="facts facts-wide">
    <dt>Region</dt><dd>EU_868, 869.4 to 869.65 MHz</dd>
    <dt>Default preset</dt><dd>LongFast, on 869.525 MHz</dd>
    <dt>Airtime</dt><dd>10 % in any hour; a node that reaches it falls silent until it may send again</dd>
    <dt>Power</dt><dd>up to 27 dBm (0.5 W)</dd>
  </dl>
  <p>With a node plugged in by USB:</p>
  <pre><code>offline radio mesh info
offline radio mesh nodes
offline radio mesh send "Home. All well."
offline radio mesh region EU_868</code></pre>
  <p>The default channel uses a published key, so anyone nearby can read it. Give the household its own channel with its own key in the Meshtastic app or Meshtastic Web, and copy that channel to every node by its QR code.</p>
  <p>Firmware is kept on this system so that a node can be reflashed with no internet: <code>sudo offline radio firmware</code> fetches the current release into <code>/srv/offline/radio/firmware</code>. ESP32 boards are flashed with <code>esptool</code> using the script inside the bundle; nRF52 boards appear as a USB drive when their reset button is pressed twice, and RP2040 boards when the BOOTSEL button is held while plugging in; both take the <code>.uf2</code> file by copying.</p>
</section>

<section aria-labelledby="r-sdr">
  <h2 id="r-sdr">The USB receiver</h2>
  <p>Gqrx shows the spectrum and tunes by clicking; its bookmarks already hold the PMR446 channels and the mesh frequency. From a terminal:</p>
  <pre><code>offline radio listen wfm 93.5      FM broadcast
offline radio listen nfm 145.5     narrow FM voice
rtl_433                            weather stations and other sensors
dump1090-mutability --interactive  aircraft overhead
welle-io                           DAB radio</code></pre>
  <p>An RTL-SDR dongle tunes from about 24 MHz to 1.7 GHz. It receives only. If nothing is heard, raise the gain (<code>--gain 40</code>) and lower the squelch (<code>--squelch 20</code>).</p>
</section>

<section aria-labelledby="r-freq">
  <h2 id="r-freq">Frequencies worth knowing</h2>
  <div class="table-responsive">
  <table class="table table-sm">
    <thead><tr><th scope="col">MHz</th><th scope="col">Use</th><th scope="col">Mode</th></tr></thead>
    <tbody>
      <tr><td>87.5 to 108</td><th scope="row">FM broadcast radio</th><td>wide FM</td></tr>
      <tr><td>174 to 240</td><th scope="row">DAB broadcast radio</th><td>digital</td></tr>
      <tr><td>26.965 to 27.405</td><th scope="row">Citizens' Band, the 40 European channels</th><td>FM, AM</td></tr>
      <tr><td>27.60125 to 27.99125</td><th scope="row">Citizens' Band, the 40 UK channels</th><td>FM</td></tr>
      <tr><td>144.800</td><th scope="row">APRS position reports, Europe</th><td>packet</td></tr>
      <tr><td>145.500</td><th scope="row">Amateur 2 m FM calling</th><td>narrow FM</td></tr>
      <tr><td>145.800</td><th scope="row">International Space Station voice</th><td>narrow FM</td></tr>
      <tr><td>433.500</td><th scope="row">Amateur 70 cm FM calling</th><td>narrow FM</td></tr>
      <tr><td>433.920</td><th scope="row">Weather stations, car keys, sensors</th><td>data</td></tr>
      <tr><td>446.00625 to 446.19375</td><th scope="row">PMR446</th><td>narrow FM</td></tr>
      <tr><td>869.525</td><th scope="row">Meshtastic in Europe</th><td>LoRa</td></tr>
      <tr><td>1090</td><th scope="row">Aircraft position reports (ADS-B)</th><td>data</td></tr>
      <tr><td>121.500</td><th scope="row">Aeronautical emergency</th><td>AM</td></tr>
      <tr><td>156.800</td><th scope="row">Marine distress and calling, channel 16</th><td>narrow FM</td></tr>
    </tbody>
  </table>
  </div>
</section>

<section aria-labelledby="r-law">
  <h2 id="r-law">What may be transmitted and heard</h2>
  <p>PMR446 handsets and Meshtastic nodes within the limits above need no licence. Transmitting on amateur frequencies needs an amateur licence, and a handset that can exceed 0.5 W or has a removable aerial is not licence-free on PMR446 even when tuned to it.</p>
  <p>Receiving is regulated too. In the United Kingdom, Ofcom's guidance is that a receiver may be used for broadcast radio, amateur and Citizens' Band traffic, and weather and navigation broadcasts; other services may be listened to only with the sender's permission, which covers your own household's handsets and nodes. Check the rules where you live.</p>
</section>

</div>

<aside class="col-lg-4" aria-labelledby="r-now">
  <h2 id="r-now">Plugged in now</h2>
<?php if (($radio['devices'] ?? []) === []) : ?>
  <p class="row-note">No receiver or mesh node is recognised on this system's USB ports.</p>
<?php else : ?>
  <ul class="services">
<?php foreach ($radio['devices'] as $device) : ?>
    <li class="is-ok"><i class="fa-solid <?= $device['kind'] === 'sdr' ? 'fa-satellite-dish' : 'fa-microchip' ?> fa-fw" aria-hidden="true"></i><span><?= e($device['name']) ?></span></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>

  <h3>Tools on this system</h3>
  <ul class="services">
<?php foreach (($radio['tools'] ?? []) as $tool => $present) : ?>
    <li class="<?= $present ? 'is-ok' : 'is-off' ?>"><i class="fa-solid <?= $present ? 'fa-circle-check' : 'fa-circle-minus' ?> fa-fw" aria-hidden="true"></i><span><?= e(explode('|', (string) $tool)[0]) ?></span><span class="service-state"><?= $present ? 'installed' : 'missing' ?></span></li>
<?php endforeach; ?>
  </ul>

  <h3>Channel files</h3>
  <p class="row-note">In <code>/usr/local/share/offline/radio</code>: Gqrx bookmarks, and the PMR446 channels as a CHIRP import for programming handsets.</p>

  <h3>Mesh firmware on disc</h3>
  <p class="row-note"><?= ($radio['firmware'] ?? []) === [] ? 'None yet.' : e(implode(', ', $radio['firmware'])) ?></p>

  <h3>Position</h3>
  <p class="row-note">The <a href="/maps.php">map page</a> gives any position as a Maidenhead locator for passing over the radio.</p>
</aside>
</div>
<?php page_end();
