<?php
/**
 * Offline — maps.
 *
 * Map regions are PMTiles archives: single files of vector tiles that the
 * portal reads straight from disc with HTTP range requests, so no tile server
 * runs. Regions are cut from the Protomaps world build (OpenStreetMap data)
 * with the pinned "pmtiles" tool, or fetched ready-made.
 */

declare(strict_types=1);

namespace Offline;

const PROTOMAPS_BUILDS = 'https://build-metadata.protomaps.dev/builds.json';
const PROTOMAPS_BASE = 'https://build.protomaps.com/';
/** protomaps/basemaps-assets, pinned by commit so git itself verifies every byte. */
const BASEMAP_ASSETS_REPO = 'https://github.com/protomaps/basemaps-assets';
const BASEMAP_ASSETS_COMMIT = '028c18f713baecad011301ff7a69acc39bcc2ae7';
const WORLD_MAP = 'world.pmtiles';
const WORLD_MAX_ZOOM = 5;

function map_regions(): array
{
    $regions = [];
    foreach ([path('maps_catalogue'), path('maps_catalogue_local')] as $file) {
        foreach (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if ($line[0] === '#') {
                continue;
            }
            $cols = array_map('trim', explode("\t", $line));
            if (count($cols) < 5 || preg_match('/^[a-z][a-z0-9-]{1,40}$/', $cols[0]) !== 1 || !in_array($cols[3], ['bbox', 'url'], true)) {
                continue;
            }
            if ($cols[3] === 'bbox' && parse_bbox($cols[4]) === null) {
                continue;
            }
            if ($cols[3] === 'url' && preg_match('#^https://[A-Za-z0-9.-]+/[A-Za-z0-9._~/%+-]+\.pmtiles$#', $cols[4]) !== 1) {
                continue;
            }
            $regions[$cols[0]] = [
                'id' => $cols[0], 'group' => $cols[1], 'name' => $cols[2], 'kind' => $cols[3], 'value' => $cols[4],
                'approx' => ctype_digit($cols[5] ?? '') ? (int) $cols[5] * 1048576 : 0,
            ];
        }
    }
    return $regions;
}

/** "W,S,E,N" in degrees, validated. Returns four floats or null. */
function parse_bbox(string $text): ?array
{
    if (preg_match('/^(-?\d{1,3}(?:\.\d{1,6})?),(-?\d{1,2}(?:\.\d{1,6})?),(-?\d{1,3}(?:\.\d{1,6})?),(-?\d{1,2}(?:\.\d{1,6})?)$/', $text, $m) !== 1) {
        return null;
    }
    [$w, $s, $e, $n] = array_map('floatval', array_slice($m, 1));
    if ($w < -180 || $e > 180 || $s < -85.06 || $n > 85.06 || $w >= $e || $s >= $n) {
        return null;
    }
    return [$w, $s, $e, $n];
}

function maps_ready(): array
{
    return [
        'tool' => have('pmtiles'),
        'assets' => is_file(path('maps_assets') . '/sprites/v4/light.json') && is_dir(path('maps_assets') . '/fonts/Noto Sans Regular'),
        'world' => is_file(path('maps') . '/' . WORLD_MAP),
    ];
}

/** Address of the newest Protomaps world build. */
function latest_world_build(): ?array
{
    $json = https_get(PROTOMAPS_BUILDS, 30);
    $builds = $json === null ? null : json_decode($json, true);
    if (!is_array($builds)) {
        return null;
    }
    $latest = null;
    foreach ($builds as $build) {
        if (is_string($build['key'] ?? null) && preg_match('/^\d{8}\.pmtiles$/', $build['key']) === 1
            && ($latest === null || $build['key'] > $latest['key'])) {
            $latest = $build;
        }
    }
    return $latest === null ? null : ['url' => PROTOMAPS_BASE . $latest['key'], 'date' => substr($latest['key'], 0, 8)];
}

function pmtiles_extract(string $source, string $target, ?array $bbox, int $maxZoom, bool $dryRun): int
{
    $command = ['pmtiles', 'extract', $source, $target, '--maxzoom=' . $maxZoom, '--download-threads=4'];
    if ($bbox !== null) {
        $command[] = '--bbox=' . implode(',', $bbox);
    }
    if ($dryRun) {
        $command[] = '--dry-run';
    }
    return run_live($command);
}

function maps_setup(): bool
{
    $assets = path('maps_assets');
    $ready = maps_ready();
    if (!$ready['assets']) {
        if (!have('git')) {
            warn('git is not installed, so the map fonts and symbols cannot be fetched. Install git and run: sudo offline maps setup');
            return false;
        }
        out('Fetching map fonts and symbols (Protomaps basemap assets, pinned commit) ...');
        // Worked on beside its destination, so the final move is a rename on one filesystem.
        $work = path('maps') . '/.assets-work.' . getmypid();
        $git = static fn (array $args): array => run(array_merge(['git', '-c', 'advice.detachedHead=false', '-C', $work], $args), 600);
        @mkdir($work, 0755, true);
        $ok = $git(['init', '--quiet'])[0] === 0
            && $git(['fetch', '--quiet', '--depth', '1', BASEMAP_ASSETS_REPO, BASEMAP_ASSETS_COMMIT])[0] === 0
            && $git(['checkout', '--quiet', 'FETCH_HEAD', '--', 'fonts', 'sprites/v4'])[0] === 0
            // The fetch only succeeds for exactly this commit, and git checks every object against it.
            && trim($git(['rev-parse', 'FETCH_HEAD'])[1]) === BASEMAP_ASSETS_COMMIT;
        if ($ok) {
            @mkdir($assets, 02775, true);
            run(['rm', '-rf', '--', $assets . '/fonts', $assets . '/sprites'], 60);
            @mkdir($assets . '/sprites', 02775, true);
            $ok = @rename($work . '/fonts', $assets . '/fonts') && @rename($work . '/sprites/v4', $assets . '/sprites/v4');
        }
        run(['rm', '-rf', '--', $work], 60);
        if (!$ok) {
            warn('The map fonts and symbols could not be fetched. Is this system online?');
            return false;
        }
        run(['chmod', '-R', 'a+rX,g+w', $assets], 60);
    }
    if (!$ready['world']) {
        if (!$ready['tool']) {
            warn('The pmtiles tool is not installed; run the installer while online.');
            return false;
        }
        $build = latest_world_build();
        if ($build === null) {
            warn('The Protomaps build list could not be reached. Is this system online?');
            return false;
        }
        out('Fetching the low-detail world map (zoom 0 to ' . WORLD_MAX_ZOOM . ') ...');
        $tmp = path('maps') . '/.incoming-world.pmtiles';
        if (pmtiles_extract($build['url'], $tmp, null, WORLD_MAX_ZOOM, false) !== 0 || !@rename($tmp, path('maps') . '/' . WORLD_MAP)) {
            @unlink($tmp);
            warn('The world map could not be fetched.');
            return false;
        }
    }
    return true;
}

function installed_maps(): array
{
    $maps = [];
    foreach (@scandir(path('maps')) ?: [] as $file) {
        if ($file[0] !== '.' && preg_match('/^[a-z0-9][a-z0-9-]{0,60}\.pmtiles$/', $file) === 1 && is_file(path('maps') . '/' . $file)) {
            $maps[] = ['id' => substr($file, 0, -8), 'file' => $file, 'size' => (int) filesize(path('maps') . '/' . $file), 'mtime' => (int) filemtime(path('maps') . '/' . $file)];
        }
    }
    return $maps;
}

function command_maps(array $args): int
{
    $action = $args[0] ?? 'list';
    $rest = array_slice($args, 1);
    $regions = map_regions();

    if ($action === 'find') {
        $found = places_search(implode(' ', $rest));
        if ($found === []) {
            out(is_file(places_dir() . '/names.tsv') ? 'Nothing found.' : 'Place search has not been set up. sudo offline maps places');
            return $found === [] && is_file(places_dir() . '/names.tsv') ? 1 : 0;
        }
        table(['Place', 'Where', 'Latitude', 'Longitude'], array_map(static fn (array $p): array => [
            printable($p['name'], 60), printable($p['detail'], 60), sprintf('%.5f', $p['lat']), sprintf('%.5f', $p['lon']),
        ], $found));
        return 0;
    }

    if ($action === 'list' || $action === 'catalogue') {
        $installed = array_column(installed_maps(), null, 'id');
        $ready = maps_ready();
        heading('On this system');
        if ($installed === []) {
            out('  No maps yet. Start with: sudo offline maps setup   then   sudo offline maps get REGION');
        } else {
            table(['Region', 'Size', 'Fetched'], array_map(static fn (array $m): array => [$m['id'], human_bytes($m['size']), gmdate('Y-m-d', $m['mtime'])], array_values($installed)));
        }
        if (!$ready['assets'] || !$ready['world']) {
            out('  The map viewer still needs its fonts, symbols and world outline: sudo offline maps setup');
        }
        $groups = [];
        foreach ($regions as $region) {
            $groups[$region['group']][] = $region;
        }
        foreach ($groups as $group => $members) {
            heading("Available: $group");
            table(['Region', 'Name', 'Size', 'This machine'], array_map(static fn (array $r): array => [
                $r['id'], $r['name'], $r['approx'] > 0 ? '~' . human_bytes($r['approx']) : 'shown before download', isset($installed[$r['id']]) ? 'installed' : '-',
            ], $members));
        }
        out();
        out('Fetch with: sudo offline maps get REGION|GROUP ...   or   sudo offline maps get --bbox W,S,E,N --name NAME');
        out('A group name fetches every region in it: sudo offline maps get continents');
        out('Add --maxzoom 12 for a smaller file with less street detail (the default, 15, is full detail).');
        return 0;
    }

    if ($action === 'routing') {
        return command_routing($rest);
    }

    become_service_user("maps $action");
    @mkdir(path('maps'), 02775, true);

    if ($action === 'setup') {
        $ok = maps_setup();
        command_status(['--write']);
        out($ok ? 'The map viewer is ready.' : 'Map set-up is incomplete; see the warnings above.');
        return $ok ? 0 : 1;
    }

    if ($action === 'remove') {
        $id = $rest[0] ?? '';
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,60}$/', $id) !== 1 || !is_file(path('maps') . "/$id.pmtiles")) {
            fail('Usage: sudo offline maps remove REGION   (see: offline maps)');
        }
        @unlink(path('maps') . "/$id.pmtiles");
        out("Removed the $id map.");
        command_status(['--write']);
        return 0;
    }

    if ($action === 'places') {
        // --from FOLDER builds from GeoNames files already on disc, for a system with no internet.
        $from = array_search('--from', $rest, true);
        $withPostcodes = !in_array('--no-postcodes', $rest, true);
        if ($from !== false && !is_readable((string) ($rest[$from + 1] ?? '') . '/countryInfo.txt')) {
            fail('--from needs a folder holding the GeoNames files (countryInfo.txt, cities500.zip and the rest) that the "offline" account can read.');
        }
        $ok = $from !== false && is_dir((string) ($rest[$from + 1] ?? '')) ? places_build(rtrim($rest[$from + 1], '/'), $withPostcodes) : places_fetch($withPostcodes);
        command_status(['--write']);
        return $ok ? 0 : 1;
    }

    if ($action !== 'get') {
        fail('Usage: offline maps [list]   |   sudo offline maps setup | places | get REGION ... | get --bbox W,S,E,N --name NAME | remove REGION | routing ...');
    }

    $dryRun = in_array('--dry-run', $rest, true);
    $yes = in_array('--yes', $rest, true) || in_array('-y', $rest, true);
    $maxZoom = 15;
    $bbox = null;
    $name = null;
    $wanted = [];
    for ($i = 0; $i < count($rest); $i++) {
        $arg = $rest[$i];
        if ($arg === '--maxzoom' && isset($rest[$i + 1])) {
            $maxZoom = max(0, min(15, (int) $rest[++$i]));
        } elseif ($arg === '--bbox' && isset($rest[$i + 1])) {
            $bbox = parse_bbox($rest[++$i]);
            if ($bbox === null) {
                fail('--bbox takes west,south,east,north in degrees, for example --bbox -6.5,49.8,-1.8,51.7');
            }
        } elseif ($arg === '--name' && isset($rest[$i + 1])) {
            $name = $rest[++$i];
        } elseif ($arg[0] !== '-') {
            $wanted[] = $arg;
        }
    }
    $jobs = [];
    if ($bbox !== null) {
        if ($name === null || preg_match('/^[a-z0-9][a-z0-9-]{0,60}$/', $name) !== 1 || $name === 'world') {
            fail('Give the area a name of lower-case letters, digits and hyphens: --name cornwall');
        }
        $jobs[] = ['id' => $name, 'name' => $name, 'kind' => 'bbox', 'bbox' => $bbox];
    }
    foreach ($wanted as $id) {
        // A region is fetched by its own name; a group name stands for every region in it.
        $members = isset($regions[$id]) ? [$regions[$id]] : array_values(array_filter($regions, static fn (array $r): bool => $r['group'] === $id));
        if ($members === []) {
            fail("No map region or group called \"" . printable($id) . '". Run: offline maps');
        }
        foreach ($members as $region) {
            $jobs[$region['id']] = $region + ['bbox' => $region['kind'] === 'bbox' ? parse_bbox($region['value']) : null];
        }
    }
    $jobs = array_values($jobs);
    if ($jobs === []) {
        fail('Usage: sudo offline maps get REGION ...   or   sudo offline maps get --bbox W,S,E,N --name NAME');
    }
    if (!$dryRun && !maps_setup()) {
        warn('Continuing without the viewer assets; regions can still be fetched.');
    }
    $needsBuild = array_filter($jobs, static fn (array $j): bool => $j['kind'] === 'bbox') !== [];
    $build = $needsBuild ? latest_world_build() : null;
    if ($needsBuild && ($build === null || !have('pmtiles'))) {
        fail($build === null ? 'The Protomaps build list could not be reached. This command needs an internet connection.' : 'The pmtiles tool is not installed; run the installer while online.');
    }
    $lock = $dryRun ? true : lock('content');
    if ($lock === null) {
        fail('Another download or sync is already running. Try again when it has finished.');
    }
    $reserve = config_int('DOWNLOAD_MIN_FREE_GB') * 1024 ** 3;
    $done = 0;
    foreach ($jobs as $job) {
        $target = path('maps') . "/{$job['id']}.pmtiles";
        $tmp = path('maps') . "/.incoming-{$job['id']}.pmtiles";
        out();
        out(paint("Map: {$job['name']}", 'bold'));
        if ($job['kind'] === 'bbox') {
            out("  Working out the size of this area at zoom 0 to $maxZoom (Protomaps build {$build['date']}) ...");
            pmtiles_extract($build['url'], $tmp, $job['bbox'], $maxZoom, true);
            if ($dryRun) {
                continue;
            }
            if (free_bytes() < $reserve || ($job['approx'] ?? 0) + $reserve > free_bytes()) {
                warn('Not enough free space for this map' . (($job['approx'] ?? 0) > 0 ? ' (about ' . human_bytes($job['approx']) . ')' : '') . '; skipped.');
                continue;
            }
            if (!confirm('  Fetch it (the archive size is on the last line above)?', $yes)) {
                out('  Skipped. Add --yes to skip this question.');
                continue;
            }
            $ok = pmtiles_extract($build['url'], $tmp, $job['bbox'], $maxZoom, false) === 0;
        } else {
            out('  Ready-made file, about ' . human_bytes($job['approx']) . '.');
            if ($dryRun) {
                continue;
            }
            if ($job['approx'] + $reserve > free_bytes()) {
                warn('Not enough free space; skipped.');
                continue;
            }
            if (!confirm('  Fetch it?', $yes)) {
                out('  Skipped. Add --yes to skip this question.');
                continue;
            }
            $ok = run_live(['curl', '--fail', '--location', '--proto', '=https', '--proto-redir', '=https', '--tlsv1.2',
                '--continue-at', '-', '--retry', '5', '--retry-delay', '10', '--progress-bar', '--output', $tmp, '--', $job['value']]) === 0;
        }
        // A PMTiles archive begins with the seven bytes "PMTiles".
        $head = (string) @file_get_contents($tmp, false, null, 0, 7);
        if (!$ok || $head !== 'PMTiles') {
            @unlink($tmp);
            warn("The {$job['id']} map did not download correctly; nothing was kept.");
            continue;
        }
        @chmod($tmp, 0664);
        if (@rename($tmp, $target)) {
            $done++;
            out(paint('✔', 'green') . " {$job['id']} installed (" . human_bytes((int) filesize($target)) . ').');
        }
    }
    if (!$dryRun) {
        command_status(['--write']);
        out();
        out("$done of " . plural(count($jobs), 'map') . ' installed. View them at http://localhost/maps.php');
    }
    return $dryRun || $done === count($jobs) ? 0 : 1;
}

// ---------------------------------------------------------------------------
// Place search
// ---------------------------------------------------------------------------

/** GeoNames publishes its gazetteer as plain tab-separated files (CC BY 4.0). */
const GEONAMES_BASE = 'https://download.geonames.org/export/';
const PLACES_SOURCES = [
    'cities' => ['dump/cities500.zip', 'cities500.txt'],
    'gb' => ['dump/GB.zip', 'GB.txt'],
    'admin1' => ['dump/admin1CodesASCII.txt', null],
    'countries' => ['dump/countryInfo.txt', null],
    'postcodes' => ['zip/GB_full.csv.zip', 'GB_full.txt'],
];
/** Beyond towns and villages, the kinds of place in Great Britain worth finding: hills, water, woods, stations, hospitals. */
const PLACES_GB_KINDS = ['T' => 'hill or coast', 'H' => 'water', 'L' => 'area', 'V' => 'woodland'];
const PLACES_GB_SPOTS = ['AIRP' => 'airport', 'RSTN' => 'railway station', 'HSP' => 'hospital', 'CSTL' => 'castle', 'LTHSE' => 'lighthouse'];

/** Open one downloaded file for reading, unpacking it into a scratch folder first if it came packed. */
function places_open(string $file, ?string $member, string $scratch)
{
    if ($member === null) {
        return @fopen($file, 'r');
    }
    if (!is_file("$scratch/$member") && (!is_file($file) || run(['unzip', '-o', '-q', $file, $member, '-d', $scratch], 600)[0] !== 0)) {
        return false;
    }
    return @fopen("$scratch/$member", 'r');
}

/**
 * Build the search index from GeoNames files in $source (downloaded first unless
 * they are already there). Every field is checked before it is written, and the
 * result is plain text that the portal reads and escapes like any other.
 */
function places_build(string $source, bool $withPostcodes): bool
{
    $target = places_dir();
    $work = path('maps') . '/.places-work.' . getmypid();
    run(['rm', '-rf', '--', $work], 60);
    if (!@mkdir("$work/postcodes", 02775, true) || !@mkdir("$work/unpacked", 0700, true)) {
        warn("Could not create $work");
        return false;
    }
    $countries = [];
    $handle = places_open("$source/countryInfo.txt", null, $work);
    while ($handle !== false && ($line = fgets($handle)) !== false) {
        $cols = explode("\t", rtrim($line, "\r\n"));
        if ($line[0] !== '#' && preg_match('/^[A-Z]{2}$/', $cols[0] ?? '') === 1 && isset($cols[4])) {
            $countries[$cols[0]] = printable($cols[4], 60);
        }
    }
    $admins = [];
    $handle = places_open("$source/admin1CodesASCII.txt", null, $work);
    while ($handle !== false && ($line = fgets($handle)) !== false) {
        $cols = explode("\t", rtrim($line, "\r\n"));
        if (preg_match('/^[A-Z]{2}\.[A-Za-z0-9]{1,20}$/', $cols[0] ?? '') === 1 && isset($cols[1])) {
            $admins[$cols[0]] = printable($cols[1], 60);
        }
    }

    $rows = [];
    $seen = [];
    foreach (['cities' => false, 'gb' => true] as $set => $britain) {
        [$archive, $member] = PLACES_SOURCES[$set];
        $handle = places_open("$source/" . basename($archive), $member, "$work/unpacked");
        if ($handle === false) {
            continue;
        }
        while (($line = fgets($handle)) !== false) {
            $c = explode("\t", rtrim($line, "\r\n"));
            if (count($c) < 15 || isset($seen[$c[0]]) || !is_numeric($c[4]) || !is_numeric($c[5]) || abs((float) $c[4]) > 90 || abs((float) $c[5]) > 180) {
                continue;
            }
            $kind = 'place';
            if ($c[6] !== 'P') {
                $kind = $britain ? (PLACES_GB_SPOTS[$c[7]] ?? PLACES_GB_KINDS[$c[6]] ?? null) : null;
                if ($kind === null) {
                    continue;
                }
            }
            $name = printable($c[1], 80);
            $key = places_key($c[2] !== '' ? $c[2] : $c[1]);
            if ($name === '' || $key === '' || str_contains($name, "\t")) {
                continue;
            }
            $seen[$c[0]] = true;
            $detail = trim(($admins[$c[8] . '.' . $c[10]] ?? '') . ', ' . ($countries[$c[8]] ?? $c[8]), ', ');
            $rows[] = [(int) $c[14], implode("\t", [$key, $name, $detail, sprintf('%.5f', (float) $c[4]), sprintf('%.5f', (float) $c[5]), $kind])];
        }
        fclose($handle);
    }
    if ($rows === []) {
        run(['rm', '-rf', '--', $work], 60);
        warn('No place names could be read. Is unzip installed, and did the download complete?');
        return false;
    }
    // Largest first, so that the best-known place of a name is found before its namesakes.
    usort($rows, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
    $out = fopen("$work/names.tsv", 'w');
    foreach ($rows as $row) {
        fwrite($out, $row[1] . "\n");
    }
    fclose($out);
    $places = count($rows);
    unset($rows, $seen);

    $postcodes = 0;
    if ($withPostcodes && is_file("$source/GB_full.csv.zip")) {
        $files = [];
        $handle = places_open("$source/GB_full.csv.zip", 'GB_full.txt', "$work/unpacked");
        while ($handle !== false && ($line = fgets($handle)) !== false) {
            $c = explode("\t", rtrim($line, "\r\n"));
            $code = strtoupper(str_replace(' ', '', $c[1] ?? ''));
            if (count($c) < 11 || preg_match('/^([A-Z]{1,2})\d[A-Z\d]?\d[A-Z]{2}$/', $code, $m) !== 1 || !is_numeric($c[9]) || !is_numeric($c[10])) {
                continue;
            }
            $files[$m[1]] ??= fopen("$work/postcodes/{$m[1]}.tsv", 'w');
            fwrite($files[$m[1]], implode("\t", [$code, sprintf('%.5f', (float) $c[9]), sprintf('%.5f', (float) $c[10]), printable($c[2], 60)]) . "\n");
            $postcodes++;
        }
        if ($handle !== false) {
            fclose($handle);
        }
        array_map('fclose', $files);
    }
    run(['rm', '-rf', '--', "$work/unpacked"], 300);
    write_json("$work/places.json", ['built' => utc_now(), 'places' => $places, 'postcodes' => $postcodes,
        'credit' => 'Place names from GeoNames (geonames.org), CC BY 4.0. UK postcodes from GeoNames, drawn from Ordnance Survey and Royal Mail open data.']);
    run(['chmod', '-R', 'a+rX,g+w', $work], 60);
    run(['rm', '-rf', '--', $target . '.old'], 60);
    if (is_dir($target)) {
        @rename($target, $target . '.old');
    }
    $ok = @rename($work, $target);
    run(['rm', '-rf', '--', $target . '.old'], 60);
    if ($ok) {
        out(paint('✔', 'green') . ' Place search is ready: ' . number_format($places) . ' places' . ($postcodes > 0 ? ' and ' . number_format($postcodes) . ' UK postcodes' : '') . '.');
    }
    return $ok;
}

function places_fetch(bool $withPostcodes): bool
{
    if (!have('unzip')) {
        warn('unzip is not installed: sudo apt install unzip');
        return false;
    }
    $cache = path('maps') . '/.places-download';
    @mkdir($cache, 02775, true);
    foreach (PLACES_SOURCES as $set => [$remote]) {
        if ($set === 'postcodes' && !$withPostcodes) {
            continue;
        }
        out('Fetching ' . basename($remote) . ' from GeoNames ...');
        $code = run_live(['curl', '--fail', '--location', '--proto', '=https', '--proto-redir', '=https', '--tlsv1.2', '--silent', '--show-error',
            '--retry', '3', '--max-time', '900', '--output', "$cache/" . basename($remote), '--', GEONAMES_BASE . $remote]);
        if ($code !== 0) {
            warn(basename($remote) . ' could not be fetched. Is this system online?');
            return false;
        }
    }
    $ok = places_build($cache, $withPostcodes);
    run(['rm', '-rf', '--', $cache], 60);
    return $ok;
}

// ---------------------------------------------------------------------------
// Directions
// ---------------------------------------------------------------------------

/** Valhalla, the open-source routing engine, pinned by digest. */
const ROUTING_IMAGE = 'ghcr.io/valhalla/valhalla:3.9.3@sha256:cf618ccafdead96829282acc81a70aa00947c2f28ddcf118f0194acf2fbc7e9d';
const ROUTING_UID_BASE = 3000000;
const ROUTING_PORT = 18700;
const GEOFABRIK_BASE = 'https://download.geofabrik.de/';
/** Regions a routing graph can be built for: id => path of the OpenStreetMap extract at Geofabrik. */
const ROUTING_REGIONS = [
    'uk' => 'europe/united-kingdom', 'england' => 'europe/united-kingdom/england', 'scotland' => 'europe/united-kingdom/scotland',
    'wales' => 'europe/united-kingdom/wales', 'ireland' => 'europe/ireland-and-northern-ireland', 'france' => 'europe/france',
    'spain' => 'europe/spain', 'portugal' => 'europe/portugal', 'belgium' => 'europe/belgium', 'netherlands' => 'europe/netherlands',
    'germany' => 'europe/germany', 'switzerland' => 'europe/switzerland', 'austria' => 'europe/austria', 'italy' => 'europe/italy',
    'denmark' => 'europe/denmark',
];

function routing_dir(): string
{
    return path('maps') . '/routing';
}

function routing_unit(): string
{
    return prefix() . '/etc/containers/systemd/offline-routing.container';
}

function routing_status(): array
{
    $meta = read_json(routing_dir() . '/graph.json');
    $graph = routing_dir() . '/graph.tar';
    return [
        'graph' => is_file($graph),
        'size' => is_file($graph) ? (int) filesize($graph) : 0,
        'regions' => array_values(array_filter((array) ($meta['regions'] ?? []), 'is_string')),
        'built' => (string) ($meta['built'] ?? ''),
        'on' => is_file(routing_unit()),
    ];
}

/** Fetch one region's OpenStreetMap extract. Runs as the sharing account. */
function routing_fetch(string $region): bool
{
    $dir = path('staging') . '/routing-pbf';
    @mkdir($dir, 02770, true);
    $url = GEOFABRIK_BASE . ROUTING_REGIONS[$region] . '-latest.osm.pbf';
    $file = "$dir/$region.osm.pbf";
    out(paint("Fetching the OpenStreetMap extract for $region from Geofabrik", 'bold'));
    $code = run_live(['aria2c', '--no-conf', '--dir=' . $dir, '--out=' . "$region.osm.pbf", '--check-certificate=true', '--continue=true', '--allow-overwrite=true',
        '--auto-file-renaming=false', '--file-allocation=none', '--max-connection-per-server=2', '--max-tries=5', '--retry-wait=10',
        '--console-log-level=warn', '--summary-interval=0', '--user-agent=offline/' . VERSION, '--', $url]);
    if ($code !== 0 || !is_file($file)) {
        warn("The extract for $region could not be fetched. Is this system online?");
        return false;
    }
    // Geofabrik publishes an MD5 beside each file. It catches a damaged download; it is not a signature.
    $md5 = https_get($url . '.md5', 60);
    if ($md5 !== null && preg_match('/^([0-9a-f]{32})\b/', $md5, $m) === 1) {
        if (hash_file('md5', $file) !== $m[1]) {
            @unlink($file);
            warn("The extract for $region does not match Geofabrik's checksum and was discarded.");
            return false;
        }
    } else {
        warn("Geofabrik's checksum for $region could not be fetched; the download is unchecked beyond its TLS connection.");
    }
    return true;
}

/**
 * Build a routing graph from OpenStreetMap extracts. The build reads untrusted
 * data with complex software, so it runs in a container with no network at
 * all, its own user range, no capabilities and one writable folder.
 */
function routing_build(array $pbfs, array $regions): bool
{
    $work = path('staging') . '/routing-work';
    run(['rm', '-rf', '--', $work], 600);
    if (!@mkdir($work, 0750, true)) {
        warn("Could not create $work");
        return false;
    }
    $inputs = [];
    foreach ($pbfs as $index => $pbf) {
        // A PBF file opens with a four-byte length and a small header naming its first block "OSMHeader".
        $head = (string) @file_get_contents($pbf, false, null, 6, 9);
        if (!is_file($pbf) || is_link($pbf) || $head !== 'OSMHeader') {
            warn(printable($pbf) . ' is not an OpenStreetMap PBF extract.');
            return false;
        }
        // A copy, because the build folder is handed to the container's own accounts.
        if (!@copy($pbf, "$work/in$index.osm.pbf")) {
            warn('Could not stage ' . printable($pbf));
            return false;
        }
        $inputs[] = "/data/in$index.osm.pbf";
    }
    $files = implode(' ', $inputs);
    file_put_contents("$work/build.sh", implode("\n", [
        'set -e',
        'cd /data',
        'valhalla_build_config --mjolnir-tile-dir /data/tiles --mjolnir-tile-extract /data/graph.tar --mjolnir-admin /data/admins.sqlite --mjolnir-timezone /data/timezones.sqlite > /data/valhalla.json',
        '# Which side of the road, and where borders lie, come from the same extract.',
        "valhalla_build_admins -c /data/valhalla.json $files",
        "valhalla_build_tiles -c /data/valhalla.json $files",
        'valhalla_build_extract -c /data/valhalla.json --overwrite',
        '',
    ]));
    [$exists] = run(['podman', 'image', 'exists', ROUTING_IMAGE], 30);
    if ($exists !== 0) {
        out('Fetching the routing engine (verified against the pinned digest) ...');
        if (run_live(['podman', 'pull', '--quiet', ROUTING_IMAGE]) !== 0) {
            warn('The routing engine image could not be fetched. Is this system online?');
            return false;
        }
    }
    out(paint('Building the routing graph. A country takes from minutes to hours and several gigabytes of memory.', 'bold'));
    $memory = max(2, (int) floor(((int) (preg_match('/^MemTotal:\s+(\d+) kB/m', (string) @file_get_contents('/proc/meminfo'), $m) === 1 ? $m[1] : 4194304)) / 1048576 * 0.85));
    $code = run_live(['podman', 'run', '--rm', '--name', 'offline-routing-build', '--pull=never', '--network=none',
        '--uidmap=0:' . ROUTING_UID_BASE . ':65536', '--gidmap=0:' . ROUTING_UID_BASE . ':65536',
        '--security-opt=no-new-privileges', '--cap-drop=all', '--read-only', '--tmpfs', '/tmp', '--memory=' . $memory . 'g', '--pids-limit=1024',
        '-v', $work . ':/data:U', ROUTING_IMAGE, '/bin/bash', '/data/build.sh']);
    $graph = "$work/graph.tar";
    if ($code !== 0 || !is_file($graph) || (int) filesize($graph) < 10240) {
        run(['rm', '-rf', '--', $work], 600);
        warn("The graph was not built (exit $code). The commonest cause is too little memory for the region.");
        return false;
    }
    $target = routing_dir();
    $account = posix_getpwnam(SERVICE_USER);
    @mkdir($target, 02775, true);
    $ok = @rename($graph, "$target/graph.tar.new") || @copy($graph, "$target/graph.tar.new");
    $ok = $ok && @rename("$target/graph.tar.new", "$target/graph.tar");
    write_json("$target/graph.json", ['built' => utc_now(), 'regions' => array_values($regions), 'engine' => 'Valhalla 3.9.3',
        'credit' => 'Routing data © OpenStreetMap contributors, extracts by Geofabrik.']);
    if ($account !== false) {
        run(['chown', '-R', $account['uid'] . ':' . $account['gid'], $target], 60);
    }
    run(['chmod', '-R', 'u=rwX,g=rwX,o=rX', $target], 60);
    run(['rm', '-rf', '--', $work], 600);
    return $ok;
}

function command_routing(array $args): int
{
    $action = $args[0] ?? 'status';
    $rest = \array_slice($args, 1);

    if ($action === 'status') {
        $status = routing_status();
        if (!$status['graph']) {
            out('No routing graph is held. One arrives by sync if another system has built it, or build one here:');
            out('  sudo offline maps routing build uk        (regions: ' . implode(', ', array_keys(ROUTING_REGIONS)) . ')');
        } else {
            out('Routing graph: ' . ($status['regions'] !== [] ? implode(', ', array_map(static fn (string $r): string => printable($r, 30), $status['regions'])) : 'regions unknown')
                . ', ' . human_bytes($status['size']) . ($status['built'] !== '' ? ', built ' . utc_display($status['built']) : '') . '.');
        }
        out('Directions on the map page: ' . ($status['on'] ? 'switched on' : 'switched off. sudo offline maps routing on'));
        return 0;
    }

    if ($action === 'fetch') {
        // Internal: the download half of a build, done by the sharing account.
        become_service_user('maps routing fetch');
        return isset(ROUTING_REGIONS[$rest[0] ?? '']) && routing_fetch($rest[0]) ? 0 : 1;
    }

    require_root("maps routing $action");
    if ($action === 'build') {
        if (prefix() === '' && !have('podman')) {
            fail('Podman is not installed. Run the installer, or: sudo apt install podman');
        }
        $pbfs = [];
        $regions = [];
        for ($i = 0; $i < count($rest); $i++) {
            if ($rest[$i] === '--pbf' && isset($rest[$i + 1])) {
                $pbfs[] = (string) realpath($rest[++$i]);
                $regions[] = preg_replace('/[^A-Za-z0-9._-]/', '', basename($rest[$i], '.osm.pbf')) ?: 'custom';
            } elseif (isset(ROUTING_REGIONS[$rest[$i]])) {
                if (as_service(['maps', 'routing', 'fetch', $rest[$i]]) !== 0) {
                    return 1;
                }
                $pbfs[] = path('staging') . "/routing-pbf/{$rest[$i]}.osm.pbf";
                $regions[] = $rest[$i];
            } else {
                fail('Unknown region "' . printable($rest[$i]) . '". Regions: ' . implode(', ', array_keys(ROUTING_REGIONS)) . '. Or: --pbf FILE.osm.pbf');
            }
        }
        if ($pbfs === []) {
            fail('Usage: sudo offline maps routing build REGION ...   or   sudo offline maps routing build --pbf FILE.osm.pbf');
        }
        if (!routing_build($pbfs, $regions)) {
            return 1;
        }
        // The extracts fetched for this build have served their purpose.
        run(['rm', '-rf', '--', path('staging') . '/routing-pbf'], 300);
        out(paint('✔', 'green') . ' The routing graph is built: ' . human_bytes((int) filesize(routing_dir() . '/graph.tar')) . '. Other systems receive it at their next sync.');
        if (is_file(routing_unit()) && prefix() === '') {
            sys(['systemctl', 'restart', 'offline-routing.service'], 120);
        } else {
            out('Switch directions on with: sudo offline maps routing on');
        }
        as_service(['status', '--write']);
        return 0;
    }

    if (!in_array($action, ['on', 'off'], true)) {
        fail('Usage: offline maps routing [status]   |   sudo offline maps routing build REGION ... | on | off');
    }
    $define = prefix() . '/etc/apache2/conf-available/offline-routing.conf';
    if ($action === 'on') {
        $source = prefix() . '/usr/local/lib/offline/offline-routing.container';
        if (!is_file($source)) {
            fail('The routing unit is missing. Run the installer again.');
        }
        if (prefix() === '') {
            if (!have('podman')) {
                fail('Podman is not installed. Run the installer, or: sudo apt install podman');
            }
            if (run(['podman', 'image', 'exists', ROUTING_IMAGE], 30)[0] !== 0 && run_live(['podman', 'pull', '--quiet', ROUTING_IMAGE]) !== 0) {
                fail('The routing engine image could not be fetched. Is this system online?');
            }
        }
        @mkdir(dirname(routing_unit()), 0755, true);
        @mkdir(routing_dir(), 02775, true);
        copy($source, routing_unit());
        @mkdir(dirname($define), 0755, true);
        write_atomic($define, "# Written by \"offline maps routing on\".\nDefine OFFLINE_ROUTING\n", 0644);
        if (prefix() === '') {
            sys(['a2enmod', '-q', 'proxy', 'proxy_http', 'headers']);
            sys(['a2enconf', '-q', 'offline-routing']);
            if (!sys(['apache2ctl', 'configtest'])) {
                sys(['a2disconf', '-q', 'offline-routing']);
                fail('Apache rejected the configuration; directions were not switched on.');
            }
            sys(['systemctl', 'reload-or-restart', 'apache2.service']);
            sys(['systemctl', 'daemon-reload']);
            sys(['systemctl', 'restart', 'offline-routing.service'], 180);
        }
        out('Directions are switched on.' . (is_file(routing_dir() . '/graph.tar') ? '' : ' They start working once a routing graph is here: sudo offline maps routing build uk'));
    } else {
        if (prefix() === '') {
            sys(['systemctl', 'stop', 'offline-routing.service'], 60);
            sys(['a2disconf', '-q', 'offline-routing']);
            sys(['systemctl', 'reload-or-restart', 'apache2.service']);
        }
        @unlink(routing_unit());
        @unlink($define);
        if (prefix() === '') {
            sys(['systemctl', 'daemon-reload']);
        }
        out('Directions are switched off. The routing graph is kept.');
    }
    as_service(['status', '--write']);
    return 0;
}
