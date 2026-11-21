/*
 * Offline map viewer. MapLibre GL draws vector tiles read straight from the
 * PMTiles files on this laptop; fonts and symbols are local too.
 */
import * as maplibreModule from '/assets/maplibre/maplibre-gl.mjs';

const maplibregl = maplibreModule.default && maplibreModule.default.Map ? maplibreModule.default : maplibreModule;
const container = document.getElementById('map');
const readout = document.getElementById('map-readout');
const regions = JSON.parse(container.dataset.regions);
const assets = new URL(container.dataset.assets, window.location.href).href;

// Load the renderer's worker from its own file rather than a blob, so the
// page's Content-Security-Policy can stay at worker-src 'self'.
maplibregl.setWorkerUrl(new URL('/assets/maplibre/maplibre-gl-worker.mjs', window.location.href).href);

const protocol = new pmtiles.Protocol();
maplibregl.addProtocol('pmtiles', protocol.tile);

function flavourName() {
  const theme = document.documentElement.getAttribute('data-theme');
  return theme === 'dark' ? 'dark' : (theme === 'vt100' ? 'black' : 'light');
}

function buildStyle() {
  const flavour = flavourName();
  const sources = {};
  let layers = [];
  regions.forEach(function (region, index) {
    const name = 'region-' + region.id;
    sources[name] = {
      type: 'vector',
      url: 'pmtiles://' + new URL(region.url, window.location.href).href,
      attribution: index === 0 ? '© OpenStreetMap contributors · Protomaps' : undefined
    };
    basemaps.layers(name, basemaps.namedFlavor(flavour), { lang: 'en' }).forEach(function (layer) {
      // One background is enough; every other layer is repeated per region.
      if (layer.type === 'background' && index > 0) {
        return;
      }
      layers.push(Object.assign({}, layer, { id: name + ':' + layer.id }));
    });
  });
  return {
    version: 8,
    glyphs: assets + '/fonts/{fontstack}/{range}.pbf',
    sprite: assets + '/sprites/v4/' + flavour,
    sources: sources,
    layers: layers
  };
}

function maidenhead(lat, lon) {
  const A = 'ABCDEFGHIJKLMNOPQRSTUVWX';
  let x = lon + 180;
  let y = lat + 90;
  let locator = A[Math.floor(x / 20)] + A[Math.floor(y / 10)];
  x %= 20;
  y %= 10;
  locator += Math.floor(x / 2) + '' + Math.floor(y);
  x = (x % 2) * 12;
  y = (y % 1) * 24;
  return locator + A[Math.floor(x)].toLowerCase() + A[Math.floor(y)].toLowerCase();
}

const map = new maplibregl.Map({
  container: container,
  style: buildStyle(),
  center: [0, 30],
  zoom: 1.2,
  maxZoom: 18,
  attributionControl: { compact: true }
});
map.addControl(new maplibregl.NavigationControl({ visualizePitch: false }), 'top-right');
map.addControl(new maplibregl.ScaleControl({ unit: 'metric' }), 'bottom-left');

function showPosition() {
  const centre = map.getCenter();
  const lat = Math.max(-89.999, Math.min(89.999, centre.lat));
  const lon = ((centre.lng + 540) % 360) - 180;
  readout.textContent = 'Centre of map: ' + lat.toFixed(5) + ', ' + lon.toFixed(5) +
    '   locator ' + maidenhead(lat, lon) + '   zoom ' + map.getZoom().toFixed(1);
}
map.on('move', showPosition);

function goTo(id, animate) {
  const region = regions.find(function (r) { return r.id === id; });
  if (!region) {
    return;
  }
  new pmtiles.PMTiles(new URL(region.url, window.location.href).href).getHeader().then(function (header) {
    if (id === 'world') {
      map.easeTo({ center: [0, 30], zoom: 1.2, duration: animate ? 600 : 0 });
    } else {
      map.fitBounds([[header.minLon, header.minLat], [header.maxLon, header.maxLat]], { padding: 24, duration: animate ? 600 : 0 });
    }
  }).catch(function () {
    readout.textContent = 'The ' + id + ' map could not be read.';
  });
}

document.querySelectorAll('[data-goto]').forEach(function (button) {
  button.addEventListener('click', function () { goTo(button.getAttribute('data-goto'), true); });
});

// Open on the smallest detailed region, which is the last in the list.
const detailed = regions.filter(function (r) { return r.id !== 'world'; });
if (detailed.length > 0) {
  goTo(detailed[detailed.length - 1].id, false);
}

// Redraw in matching colours when the theme changes.
new MutationObserver(function () { map.setStyle(buildStyle()); })
  .observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
