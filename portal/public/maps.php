<?php
declare(strict_types=1);

// The map viewer fetches tiles, fonts and symbols from this system and runs
// its renderer in a worker, so it needs connect-src and worker-src; both stay 'self'.
define('PORTAL_CSP', "default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; worker-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
require __DIR__ . '/../app/bootstrap.php';

use function Offline\human_bytes;

$dir = Offline\path('maps');
$regions = [];
foreach (@scandir($dir) ?: [] as $file) {
    if (preg_match('/^[a-z0-9][a-z0-9-]{0,60}\.pmtiles$/', $file) === 1 && is_file("$dir/$file") && !is_link("$dir/$file")) {
        $regions[] = ['id' => substr($file, 0, -8), 'url' => '/files/maps/' . rawurlencode($file), 'size' => (int) filesize("$dir/$file")];
    }
}
// The world outline goes underneath; larger regions under smaller ones.
usort($regions, static fn (array $a, array $b): int => [$b['id'] === 'world', $b['size']] <=> [$a['id'] === 'world', $a['size']]);
$assets = is_file(Offline\path('maps_assets') . '/sprites/v4/light.json');
$detailed = array_values(array_filter($regions, static fn (array $r): bool => $r['id'] !== 'world'));

page_start('Maps', 'maps', ['/assets/maplibre/maplibre-gl.css']);
?>
<section class="page-head">
  <h1>Maps</h1>
  <p class="hero-sub"><?php if ($detailed !== []) : ?>Street-level maps of <?= count($detailed) ?> <?= count($detailed) === 1 ? 'region' : 'regions' ?>, drawn on this system from OpenStreetMap data. Nothing is fetched from the internet.<?php else : ?>Offline maps drawn on this system from OpenStreetMap data.<?php endif; ?></p>
</section>

<?php if ($regions === [] || !$assets) : ?>
<p class="empty">No maps are installed yet. With an internet connection, run <code>sudo offline maps setup</code> and then, for example, <code>sudo offline maps get uk</code>. <code>offline maps</code> lists the regions on offer, and any other area can be fetched by its corner coordinates.</p>
<?php else : ?>
<div class="row g-4">
  <div class="col-lg-9">
    <div id="map" class="map" data-regions="<?= e(json_encode($regions, JSON_UNESCAPED_SLASHES)) ?>" data-assets="/files/maps/assets"></div>
    <p class="map-readout" id="map-readout" aria-live="off">Centre of map: move the map to read its position.</p>
  </div>
  <aside class="col-lg-3" aria-labelledby="h-regions">
    <h2 id="h-regions">Regions</h2>
    <ul class="rows">
<?php foreach ($regions as $region) : ?>
      <li>
        <span class="row-main">
          <button type="button" class="btn btn-link map-goto" data-goto="<?= e($region['id']) ?>"><?= e(ucwords(str_replace('-', ' ', $region['id']))) ?></button>
        </span>
        <span class="row-meta"><?= e(human_bytes($region['size'])) ?></span>
      </li>
<?php endforeach; ?>
    </ul>
<?php if ($detailed === []) : ?>
    <p class="row-note">Only the world outline is installed. Add detail with <code>sudo offline maps get REGION</code>.</p>
<?php endif; ?>
    <h3>Reading a position</h3>
    <p class="row-note">The line under the map gives the centre in decimal degrees and as a Maidenhead locator, the grid reference used on the radio.</p>
    <p class="row-note">Map data © OpenStreetMap contributors, styled with Protomaps.</p>
  </aside>
</div>
<script src="/assets/protomaps/pmtiles.js"></script>
<script src="/assets/protomaps/basemaps.js"></script>
<script type="module" src="/assets/js/maps.js"></script>
<?php endif; ?>
<?php page_end();
