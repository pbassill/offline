/*
 * Offline map viewer. MapLibre GL draws vector tiles read straight from the
 * PMTiles files on this system; fonts and symbols are local too. Place search and
 * directions, where installed, are answered by this system as well.
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

/*
 * Place search and directions. Both ask this system and nothing else, and
 * every piece of text that comes back is placed with textContent.
 */
const queryBox = document.getElementById('place-query');
const resultList = document.getElementById('place-results');
const routePanel = document.getElementById('route-panel');
const markers = { found: null, from: null, to: null };
const ends = { from: null, to: null };
let picking = null;
let routeLine = null;

function element(tag, className, text) {
  const node = document.createElement(tag);
  if (className) {
    node.className = className;
  }
  if (text !== undefined) {
    node.textContent = text;
  }
  return node;
}

function setMarker(which, lngLat) {
  if (markers[which]) {
    markers[which].remove();
  }
  const dot = element('div', 'map-pin map-pin-' + which);
  markers[which] = new maplibregl.Marker({ element: dot }).setLngLat(lngLat).addTo(map);
}

function describe(lngLat, label) {
  return label || (lngLat[1].toFixed(5) + ', ' + lngLat[0].toFixed(5));
}

function setEnd(which, lngLat, label) {
  ends[which] = { lngLat: lngLat, label: describe(lngLat, label) };
  setMarker(which, lngLat);
  const slot = document.getElementById('route-' + which);
  if (slot) {
    slot.textContent = ends[which].label;
  }
}

function showPlaces(places) {
  while (resultList.firstChild) {
    resultList.removeChild(resultList.firstChild);
  }
  if (places.length === 0) {
    resultList.appendChild(element('li', 'row-note', 'Nothing found. Try fewer letters, a postcode, or coordinates.'));
    return;
  }
  places.forEach(function (place) {
    const item = element('li');
    const main = element('span', 'row-main');
    const go = element('button', 'btn btn-link map-goto', place.name);
    go.type = 'button';
    go.addEventListener('click', function () {
      setMarker('found', [place.lon, place.lat]);
      map.flyTo({ center: [place.lon, place.lat], zoom: place.kind === 'postcode' ? 16 : (place.kind === 'place' ? 12 : 14), duration: 700 });
    });
    main.appendChild(go);
    main.appendChild(element('span', 'row-note', place.detail));
    item.appendChild(main);
    if (routePanel) {
      const meta = element('span', 'row-meta');
      [['from', 'Start'], ['to', 'End']].forEach(function (pair) {
        const use = element('button', 'btn btn-sm btn-outline-primary', pair[1]);
        use.type = 'button';
        use.addEventListener('click', function () { setEnd(pair[0], [place.lon, place.lat], place.name); });
        meta.appendChild(use);
      });
      item.appendChild(meta);
    }
    resultList.appendChild(item);
  });
}

function findPlaces() {
  const text = queryBox.value.trim();
  if (text.length < 2) {
    return;
  }
  const centre = map.getCenter();
  const address = '/places.php?q=' + encodeURIComponent(text) + '&near=' + centre.lat.toFixed(4) + ',' + centre.lng.toFixed(4);
  window.fetch(address, { credentials: 'omit', cache: 'no-store' })
    .then(function (response) { return response.ok ? response.json() : { places: [] }; })
    .then(function (data) {
      showPlaces((data.places || []).filter(function (p) {
        return p && typeof p.name === 'string' && typeof p.detail === 'string' && Number.isFinite(p.lat) && Number.isFinite(p.lon);
      }));
    })
    .catch(function () { showPlaces([]); });
}

if (queryBox && resultList) {
  document.getElementById('place-go').addEventListener('click', findPlaces);
  queryBox.addEventListener('keydown', function (event) {
    if (event.key === 'Enter') {
      event.preventDefault();
      findPlaces();
    }
  });
}

/* Valhalla returns the line of a route packed as a string, six decimal places per coordinate. */
function unpackLine(text) {
  const points = [];
  let index = 0;
  let lat = 0;
  let lon = 0;
  while (index < text.length) {
    const pair = [0, 0];
    for (let part = 0; part < 2; part++) {
      let shift = 0;
      let result = 0;
      let byte;
      do {
        byte = text.charCodeAt(index++) - 63;
        result |= (byte & 0x1f) << shift;
        shift += 5;
      } while (byte >= 0x20 && index < text.length);
      pair[part] = (result & 1) ? ~(result >> 1) : (result >> 1);
    }
    lat += pair[0];
    lon += pair[1];
    points.push([lon / 1e6, lat / 1e6]);
  }
  return points;
}

function drawRoute() {
  if (!routeLine) {
    return;
  }
  const data = { type: 'Feature', geometry: { type: 'LineString', coordinates: routeLine }, properties: {} };
  if (map.getSource('route')) {
    map.getSource('route').setData(data);
    return;
  }
  map.addSource('route', { type: 'geojson', data: data });
  const colour = getComputedStyle(document.documentElement).getPropertyValue('--o-route').trim() || '#b3261e';
  map.addLayer({ id: 'route-casing', type: 'line', source: 'route', layout: { 'line-join': 'round', 'line-cap': 'round' }, paint: { 'line-color': '#ffffff', 'line-width': 8, 'line-opacity': 0.85 } });
  map.addLayer({ id: 'route-line', type: 'line', source: 'route', layout: { 'line-join': 'round', 'line-cap': 'round' }, paint: { 'line-color': colour, 'line-width': 4.5 } });
}

function journeyTime(seconds) {
  const minutes = Math.max(1, Math.round(seconds / 60));
  return minutes < 60 ? minutes + ' min' : Math.floor(minutes / 60) + ' h ' + (minutes % 60) + ' min';
}

function clearRoute() {
  routeLine = null;
  ['route-line', 'route-casing'].forEach(function (id) { if (map.getLayer(id)) { map.removeLayer(id); } });
  if (map.getSource('route')) {
    map.removeSource('route');
  }
  document.getElementById('route-summary').textContent = '';
  const steps = document.getElementById('route-steps');
  while (steps.firstChild) {
    steps.removeChild(steps.firstChild);
  }
}

function getDirections() {
  const summary = document.getElementById('route-summary');
  if (!ends.from || !ends.to) {
    summary.textContent = 'Set a start and an end first.';
    return;
  }
  const request = {
    locations: [{ lat: ends.from.lngLat[1], lon: ends.from.lngLat[0] }, { lat: ends.to.lngLat[1], lon: ends.to.lngLat[0] }],
    costing: document.getElementById('route-mode').value,
    units: 'miles',
    language: 'en-GB'
  };
  summary.textContent = 'Working out the route ...';
  window.fetch('/valhalla/route?json=' + encodeURIComponent(JSON.stringify(request)), { credentials: 'omit', cache: 'no-store' })
    .then(function (response) { return response.json().then(function (body) { return { ok: response.ok, body: body }; }); })
    .then(function (answer) {
      const trip = answer.body && answer.body.trip;
      if (!answer.ok || !trip || !Array.isArray(trip.legs) || trip.legs.length === 0) {
        clearRoute();
        summary.textContent = 'No route was found between those points. Both must be on roads this system has data for.';
        return;
      }
      clearRoute();
      routeLine = trip.legs.reduce(function (all, leg) { return all.concat(unpackLine(String(leg.shape || ''))); }, []);
      drawRoute();
      summary.textContent = Number(trip.summary.length).toFixed(1) + ' miles, about ' + journeyTime(Number(trip.summary.time)) + '.';
      const steps = document.getElementById('route-steps');
      trip.legs.forEach(function (leg) {
        (leg.maneuvers || []).forEach(function (step) {
          const line = element('li', '', String(step.instruction || ''));
          if (Number(step.length) >= 0.1) {
            line.appendChild(element('span', 'row-note', ' ' + Number(step.length).toFixed(1) + ' mi'));
          }
          steps.appendChild(line);
        });
      });
      const bounds = routeLine.reduce(function (box, point) { return box.extend(point); }, new maplibregl.LngLatBounds(routeLine[0], routeLine[0]));
      map.fitBounds(bounds, { padding: 48, duration: 600 });
    })
    .catch(function () {
      summary.textContent = 'The routing service did not answer. An adult can check it with: systemctl status offline-routing';
    });
}

if (routePanel) {
  document.querySelectorAll('[data-route-pick]').forEach(function (button) {
    button.addEventListener('click', function () {
      picking = button.getAttribute('data-route-pick');
      document.getElementById('route-hint').textContent = 'Now click the map where the journey ' + (picking === 'from' ? 'starts' : 'ends') + '.';
      map.getCanvas().classList.add('map-picking');
    });
  });
  map.on('click', function (event) {
    if (!picking) {
      return;
    }
    setEnd(picking, [event.lngLat.lng, event.lngLat.lat]);
    picking = null;
    map.getCanvas().classList.remove('map-picking');
    document.getElementById('route-hint').textContent = 'Choose a start and an end: press a button below, then click the map. A place you have found can also be used.';
  });
  document.getElementById('route-go').addEventListener('click', getDirections);
  document.getElementById('route-clear').addEventListener('click', function () {
    clearRoute();
    ['from', 'to'].forEach(function (which) {
      ends[which] = null;
      if (markers[which]) {
        markers[which].remove();
        markers[which] = null;
      }
      document.getElementById('route-' + which).textContent = 'not set';
    });
  });
  // Changing the theme replaces the map's style, and the route's layers with it.
  map.on('style.load', drawRoute);
}
