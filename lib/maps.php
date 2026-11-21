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

    if ($action !== 'get') {
        fail('Usage: offline maps [list]   |   sudo offline maps setup | get REGION ... | get --bbox W,S,E,N --name NAME | remove REGION');
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
