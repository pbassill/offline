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
$mapStatus = portal_status()['maps'] ?? [];
$places = is_file("$dir/places/names.tsv");
$placesMeta = Offline\read_json("$dir/places/places.json");
$routing = (bool) ($mapStatus['routing']['on'] ?? false) && is_file("$dir/routing/graph.tar");
$routingRegions = array_values(array_filter((array) ($mapStatus['routing']['regions'] ?? []), 'is_string'));

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
    <div id="map" class="map" data-regions="<?= e(json_encode($regions, JSON_UNESCAPED_SLASHES)) ?>" data-assets="/files/maps/assets" data-places="<?= $places ? '1' : '0' ?>" data-routing="<?= $routing ? '1' : '0' ?>"></div>
    <p class="map-readout" id="map-readout" aria-live="off">Centre of map: move the map to read its position.</p>
  </div>
  <aside class="col-lg-3" aria-labelledby="h-find">
    <h2 id="h-find">Find a place</h2>
<?php if ($places) : ?>
    <div class="map-search" role="search">
      <label class="visually-hidden" for="place-query">Place, UK postcode or coordinates</label>
      <input type="search" id="place-query" class="form-control" placeholder="Town, postcode or 50.17, -5.11" autocomplete="off" maxlength="80">
      <button type="button" class="btn btn-primary" id="place-go"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><span class="visually-hidden">Search</span></button>
    </div>
    <ul class="rows map-results" id="place-results" aria-live="polite"></ul>
<?php else : ?>
    <p class="row-note">Search by name or postcode needs the place index, which an adult can fetch once with <code>sudo offline maps places</code>. Coordinates can be read from the line under the map.</p>
<?php endif; ?>

    <h2 id="h-route">Directions</h2>
<?php if ($routing) : ?>
    <div class="map-route" id="route-panel">
      <p class="row-note" id="route-hint">Choose a start and an end: press a button below, then click the map. A place you have found can also be used.</p>
      <div class="map-route-ends">
        <button type="button" class="btn btn-sm btn-outline-primary" data-route-pick="from"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> Set start</button>
        <button type="button" class="btn btn-sm btn-outline-primary" data-route-pick="to"><i class="fa-solid fa-flag-checkered" aria-hidden="true"></i> Set end</button>
      </div>
      <dl class="facts facts-stack">
        <dt>From</dt><dd id="route-from">not set</dd>
        <dt>To</dt><dd id="route-to">not set</dd>
      </dl>
      <label class="form-label" for="route-mode">Travelling by</label>
      <select id="route-mode" class="form-select form-select-sm">
        <option value="auto">Car</option>
        <option value="bicycle">Bicycle</option>
        <option value="pedestrian">On foot</option>
      </select>
      <div class="map-route-ends">
        <button type="button" class="btn btn-primary" id="route-go">Get directions</button>
        <button type="button" class="btn btn-outline-primary" id="route-clear">Clear</button>
      </div>
      <p class="map-route-summary" id="route-summary" aria-live="polite"></p>
      <ol class="map-route-steps" id="route-steps"></ol>
    </div>
<?php if ($routingRegions !== []) : ?>
    <p class="row-note">Roads known: <?= e(implode(', ', $routingRegions)) ?>. A route is worked out on this system from OpenStreetMap data, with no knowledge of closures or traffic.</p>
<?php endif; ?>
<?php else : ?>
    <p class="row-note">Directions need a routing graph and its engine. An adult can build one with <code>sudo offline maps routing build uk</code> and switch it on with <code>sudo offline maps routing on</code>.</p>
<?php endif; ?>

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
    <p class="row-note">Map data © OpenStreetMap contributors, styled with Protomaps.<?php if ($places && ($placesMeta['credit'] ?? '') !== '') : ?> <?= e((string) $placesMeta['credit']) ?><?php endif; ?></p>
  </aside>
</div>
<script src="/assets/protomaps/pmtiles.js"></script>
<script src="/assets/protomaps/basemaps.js"></script>
<script type="module" src="/assets/js/maps.js"></script>
<?php endif; ?>
<?php page_end();
