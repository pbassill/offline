<?php
/**
 * Offline — central servers, the download queue and the staging area.
 *
 * A server is the same software as a workstation without the desktop: it
 * downloads, stages and publishes content, and every other system pulls from
 * it. There can be several, at home and in a datacentre; a workstation lists
 * them in SERVERS and pulls from each in turn over the keyed TLS channel, so a
 * system away from home can still reach the one on the internet.
 *
 * Downloads never land in the shared folders directly. They arrive in the
 * staging area, which is not shared, and are published from there, either by
 * hand or automatically (STAGING_AUTOPUBLISH).
 */

declare(strict_types=1);

namespace Offline;

/** openzim/zimit, the Kiwix project's own tool for turning a website into a ZIM file. Pinned by digest. */
const ZIMIT_IMAGE = 'ghcr.io/openzim/zimit:3.1.3@sha256:264d386152a0527d75b562298193d27c7f318ee58eb5191940dc12ad0a1b121b';
const ZIMIT_UID_BASE = 2700000;
const MEDIA_FOLDERS = ['movies', 'tv', 'music', 'radio', 'books'];

// ---------------------------------------------------------------------------
// Servers
// ---------------------------------------------------------------------------

/** Rewrite one KEY=value line of offline.conf, keeping everything else as it is. */
function config_set(string $key, string $value): bool
{
    $file = path('conf');
    $lines = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
    $line = $key . '="' . $value . '"';
    $found = false;
    foreach ($lines as $i => $existing) {
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', $existing) === 1) {
            $lines[$i] = $line;
            $found = true;
        }
    }
    if (!$found) {
        $lines[] = $line;
    }
    return write_atomic($file, implode("\n", $lines) . "\n", 0644);
}

/**
 * The sync job is confined to private address ranges by systemd. A server on
 * the internet is outside them, so naming one widens that confinement; the
 * keyed TLS handshake is then what stands between the job and anyone else.
 */
function apply_server_network_policy(array $servers): void
{
    if (prefix() !== '') {
        return;
    }
    $public = array_filter($servers, static function (array $server): bool {
        $addresses = filter_var($server['host'], FILTER_VALIDATE_IP) !== false ? [$server['host']] : (@gethostbynamel($server['host']) ?: [$server['host']]);
        return array_filter($addresses, static fn (string $a): bool => !is_private_ipv4($a) && $a !== '127.0.0.1') !== [];
    });
    // The jobs that speak to other systems: content sync, and the two halves of family broadcast.
    foreach (['offline-peer-sync', 'offline-broadcast', 'offline-broadcast-poll'] as $unit) {
        $dir = "/etc/systemd/system/$unit.service.d";
        if ($public !== []) {
            @mkdir($dir, 0755, true);
            write_atomic("$dir/servers.conf", "# Written by \"offline server\": a configured server is outside the private ranges.\n[Service]\nIPAddressAllow=any\n", 0644);
        } else {
            @unlink("$dir/servers.conf");
            @rmdir($dir);
        }
    }
    run(['systemctl', 'daemon-reload'], 60);
}

function command_server(array $args): int
{
    $action = $args[0] ?? 'list';
    $servers = servers();

    if ($action === 'list') {
        out('This system is a ' . (is_server() ? 'server' : 'workstation') . '.');
        if ($servers === []) {
            out('No central server is configured. Add one with: sudo offline server add HOST');
            return 0;
        }
        $known = read_json(path('peers'))['peers'] ?? [];
        table(['Server', 'Port', 'Access', 'Last sync'], array_map(static function (array $s) use ($known): array {
            $entry = $known[peer_key($s['host'], $s['port'])] ?? [];
            return [$s['host'], (string) $s['port'], $entry['auth'] ?? 'not yet contacted', utc_display($entry['last_sync'] ?? null)];
        }, $servers));
        return 0;
    }

    if ($action === 'test') {
        become_service_user('server test');
        $failed = 0;
        foreach ($servers as $server) {
            $listing = remote_listing(['address' => $server['host'], 'port' => $server['port']], 'knowledge');
            out('  ' . state_mark($listing['ok'] ? 'ok' : 'fail') . ' ' . $server['host'] . ': ' . ($listing['ok'] ? 'reachable, key accepted, ' . plural(count($listing['files']), 'library file') . ' on offer' : $listing['error']));
            $failed += $listing['ok'] ? 0 : 1;
        }
        if ($servers === []) {
            out('No central server is configured.');
        }
        return $failed === 0 ? 0 : 1;
    }

    if (!in_array($action, ['add', 'remove'], true)) {
        fail('Usage: offline server [list]   |   sudo offline server add HOST[:PORT] | remove HOST | test');
    }
    require_root("server $action");
    $entry = strtolower((string) ($args[1] ?? ''));
    if (preg_match('/^([a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?)(?::(\d{2,5}))?$/', $entry, $m) !== 1) {
        fail('Give the server as a host name or IPv4 address, optionally with a port: sudo offline server add files.example.org');
    }
    $hosts = array_map(static fn (array $s): string => $s['host'] . ($s['port'] === TLS_PORT ? '' : ':' . $s['port']), $servers);
    $hosts = array_values(array_filter($hosts, static fn (string $h): bool => explode(':', $h)[0] !== $m[1]));
    if ($action === 'add') {
        $hosts[] = $entry;
    }
    if (!config_set('SERVERS', implode(' ', $hosts))) {
        fail('Could not update ' . path('conf'));
    }
    $updated = [];
    foreach ($hosts as $host) {
        [$name, $port] = explode(':', $host) + [1 => (string) TLS_PORT];
        $updated[] = ['host' => $name, 'port' => (int) $port];
    }
    apply_server_network_policy($updated);
    // A server on the home network is also where this system may ask the time.
    time_apply_sources();
    // ... and for packages, if it keeps a cache of them.
    apt_client_apply();
    out($action === 'add' ? "Server {$m[1]} added. Check the link with: sudo offline server test" : "Server {$m[1]} removed.");
    return 0;
}

// ---------------------------------------------------------------------------
// Download queue
// ---------------------------------------------------------------------------

function queue_load(): array
{
    return read_json(path('queue'))['items'] ?? [];
}

function queue_save(array $items): void
{
    write_json(path('queue'), ['updated' => utc_now(), 'items' => array_values($items)]);
}

/**
 * Change the queue under a lock. The downloader and the site archiver run at
 * the same time; each re-reads the queue before altering it so neither undoes
 * the other's work.
 */
function queue_update(callable $change): array
{
    $lock = lock('queue-state', true);
    $items = array_values($change(queue_load()));
    queue_save($items);
    if (\is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $items;
}

const QUEUE_STATES = ['queued', 'running', 'staged', 'published', 'failed'];

function queue_mark(string $id, string $state, string $message): void
{
    queue_update(static function (array $items) use ($id, $state, $message): array {
        foreach ($items as $index => $item) {
            if ($item['id'] === $id) {
                $items[$index]['state'] = $state;
                $items[$index]['message'] = $message;
                $items[$index]['updated'] = utc_now();
            }
        }
        return $items;
    });
}

/**
 * Run one of this tool's own commands as the service account. Root uses this
 * to record results, so that queue files are only ever written by one account.
 */
function as_service(array $args): int
{
    if (prefix() !== '' || !is_root()) {
        return main(array_merge(['offline'], $args));
    }
    return run_live(array_merge([PHP_BINARY, (string) realpath($_SERVER['SCRIPT_FILENAME'])], $args));
}

/** Suggested websites to archive, from the shipped list and any local additions. */
function site_list(): array
{
    $sites = [];
    foreach ([prefix() . '/usr/local/lib/offline/sites.tsv', prefix() . '/etc/offline/sites.local.tsv'] as $file) {
        foreach (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $cols = array_map('trim', explode("\t", $line));
            if ($line[0] === '#' || count($cols) < 6 || preg_match('/^[a-z][a-z0-9-]{1,40}$/', $cols[0]) !== 1 || !valid_site_url($cols[2])
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,80}$/', $cols[3]) !== 1) {
                continue;
            }
            $sites[$cols[0]] = ['id' => $cols[0], 'group' => $cols[1], 'url' => $cols[2], 'name' => $cols[3], 'title' => $cols[4], 'pages' => max(1, min(50000, (int) $cols[5])), 'note' => $cols[6] ?? ''];
        }
    }
    return $sites;
}

function valid_site_url(string $url): bool
{
    return preg_match('#^https://[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?(/[A-Za-z0-9._~/%+-]*)?$#', $url) === 1;
}

function valid_download_url(string $url): bool
{
    return strlen($url) <= 2000 && preg_match('#^https://[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?(:\d{2,5})?(/[^\s\x00-\x1f"\'<>\\\\]*)?$#', $url) === 1;
}

function queue_add(array $item): array
{
    $item += ['id' => bin2hex(random_bytes(4)), 'added' => utc_now(), 'state' => 'queued', 'message' => ''];
    queue_update(static fn (array $items): array => array_merge($items, [$item]));
    return $item;
}

/** Counts for the status file and the portal. Addresses are reduced to their host name. */
function queue_summary(): array
{
    $items = queue_load();
    $states = array_count_values(array_column($items, 'state'));
    ksort($states);
    $staging = is_dir(path('staging')) && is_readable(path('staging')) ? tree_stats(path('staging')) : ['files' => 0, 'bytes' => 0];
    return [
        'total' => count($items),
        'states' => $states,
        'staging_bytes' => $staging['bytes'],
        'items' => array_map(static fn (array $i): array => [
            'id' => $i['id'], 'kind' => $i['kind'], 'state' => $i['state'], 'to' => $i['kind'] === 'site' ? 'library' : 'media/' . $i['to'],
            'what' => $i['kind'] === 'site' ? (string) $i['title'] : (string) (parse_url((string) $i['url'], PHP_URL_HOST) ?: ''),
            'message' => (string) $i['message'], 'added' => (string) ($i['added'] ?? ''),
        ], \array_slice(array_reverse($items), 0, 25)),
    ];
}

function staging_dir(string $id): string
{
    return path('staging') . '/' . $id;
}

/** Fetch one queued video, audio or file into its staging folder. */
function queue_fetch(array $item): array
{
    $dir = staging_dir($item['id']);
    @mkdir($dir, 02770, true);
    if ($item['kind'] === 'file') {
        $code = run_live([
            'aria2c', '--no-conf', '--dir=' . $dir, '--check-certificate=true', '--continue=true', '--auto-file-renaming=false',
            '--file-allocation=none', '--max-connection-per-server=4', '--max-tries=5', '--retry-wait=10', '--timeout=60',
            '--console-log-level=warn', '--summary-interval=0', '--user-agent=offline/' . VERSION, '--', $item['url'],
        ]);
    } else {
        $command = [
            'yt-dlp', '--ignore-config', '--no-progress', '--restrict-filenames', '--no-overwrites', '--no-exec',
            '--paths', $dir, '--output', '%(title).100B [%(id)s].%(ext)s', '--socket-timeout', '30', '--retries', '5',
        ];
        $command = array_merge($command, $item['kind'] === 'audio'
            ? ['--format', 'ba[ext=m4a]/ba', '--extract-audio', '--audio-format', 'm4a']
            : ['--format', 'bv*[ext=mp4][height<=1080]+ba[ext=m4a]/b[ext=mp4]/b', '--merge-output-format', 'mp4', '--write-subs', '--sub-langs', 'en.*', '--convert-subs', 'vtt']);
        $command[] = '--';
        $command[] = $item['url'];
        $code = run_live($command);
    }
    $files = tree_stats($dir);
    if ($code !== 0 && $files['files'] === 0) {
        return ['failed', "the download tool exited with code $code"];
    }
    return ['staged', plural($files['files'], 'file') . ', ' . human_bytes($files['bytes'])];
}

/**
 * Archive a website as a ZIM file with zimit. This runs a browser, so it is
 * confined like the applications: its own user namespace, no new privileges,
 * memory and process limits, and one writable folder.
 */
function queue_archive_site(array $item): array
{
    $dir = staging_dir($item['id']);
    @mkdir($dir, 02770, true);
    [$exists] = run(['podman', 'image', 'exists', ZIMIT_IMAGE], 30);
    if ($exists !== 0 && run_live(['podman', 'pull', '--quiet', ZIMIT_IMAGE]) !== 0) {
        return ['failed', 'the archiver image could not be fetched'];
    }
    $code = run_live([
        'podman', 'run', '--rm', '--name', 'offline-zimit-' . $item['id'], '--pull=never',
        '--uidmap=0:' . ZIMIT_UID_BASE . ':65536', '--gidmap=0:' . ZIMIT_UID_BASE . ':65536',
        '--security-opt=no-new-privileges', '--memory=3g', '--pids-limit=2048', '--shm-size=1g',
        '-v', $dir . ':/output:U', ZIMIT_IMAGE,
        'zimit', '--seeds', $item['url'], '--name', $item['name'], '--zim-file', $item['name'] . '_' . gmdate('Y-m') . '.zim',
        // Joined to their options, so a title can never be read as an option of its own.
        '--title=' . substr($item['title'], 0, 30), '--description=Archived for offline use on ' . gmdate('j F Y'),
        '--scopeType', 'prefix', '--pageLimit', (string) $item['pages'], '--workers', '2',
        '--timeHardLimit', '43200', '--zim-lang', 'eng', '--userAgentSuffix', 'offline-household-archive',
        '--output', '/output',
    ]);
    // Hand the result to the sharing account, whatever the container's user range left behind.
    $account = posix_getpwnam(SERVICE_USER);
    if ($account !== false) {
        run(['chown', '-R', $account['uid'] . ':' . $account['gid'], $dir], 120);
        run(['chmod', '-R', 'u+rwX,g+rwX,o-rwx', $dir], 120);
    }
    $zims = glob($dir . '/*.zim') ?: [];
    if ($zims === []) {
        return ['failed', "the archiver exited with code $code and produced no ZIM file"];
    }
    return ['staged', basename($zims[0]) . ', ' . human_bytes((int) filesize($zims[0]))];
}

/** Where a staged file belongs once published, or null if its type is not accepted. */
function publish_target(string $file, string $folder): ?string
{
    $ext = extension($file);
    if ($ext === 'zim') {
        return valid_zim_file($file) ? path('zim') . '/' . basename($file) : null;
    }
    if ($ext === 'pmtiles') {
        return path('maps') . '/' . basename($file);
    }
    if (in_array($ext, SYNC_EXTENSIONS['media'], true)) {
        return path('media') . '/' . $folder . '/' . basename($file);
    }
    return null;
}

/** Move a staged item's files into the shared folders. Returns [files published, files left behind]. */
function stage_publish(array $item): array
{
    $dir = staging_dir($item['id']);
    $published = 0;
    $skipped = 0;
    $stack = [$dir];
    while ($stack !== []) {
        $current = array_pop($stack);
        foreach (@scandir($current) ?: [] as $entry) {
            $full = "$current/$entry";
            if ($entry[0] === '.' || is_link($full)) {
                continue;
            }
            if (is_dir($full)) {
                $stack[] = $full;
                continue;
            }
            $target = safe_relative_path($entry) ? publish_target($full, $item['to']) : null;
            if ($target === null || file_exists($target)) {
                $skipped++;
                continue;
            }
            @mkdir(dirname($target), 02775, true);
            if (@rename($full, $target) || (@copy($full, $target) && @unlink($full))) {
                @chmod($target, 0664);
                $published++;
            }
        }
    }
    if ($published > 0) {
        queue_mark($item['id'], 'published', plural($published, 'file') . ' published' . ($skipped > 0 ? ", $skipped left in staging" : ''));
        if ($skipped === 0 && preg_match('/^[0-9a-f]{8}$/', $item['id']) === 1) {
            run(['rm', '-rf', '--', $dir], 60);
        }
    }
    return [$published, $skipped];
}

function command_queue(array $args): int
{
    $action = $args[0] ?? 'list';
    $rest = array_slice($args, 1);
    $items = queue_load();

    if ($action === 'list') {
        if ($items === []) {
            out('The download queue is empty.');
        } else {
            table(['Id', 'Kind', 'State', 'To', 'What', 'Result'], array_map(static fn (array $i): array => [
                $i['id'], $i['kind'], $i['state'], $i['kind'] === 'site' ? 'library' : 'media/' . $i['to'], printable($i['kind'] === 'site' ? $i['title'] : $i['url'], 60), printable($i['message'], 60),
            ], $items));
        }
        out();
        out('Add with: sudo offline queue add URL [--to movies|tv|music|radio|books] [--audio]');
        out('          sudo offline queue site NAME|GROUP   (see: offline queue sites)');
        return 0;
    }

    if ($action === 'sites') {
        $sites = site_list();
        table(['Name', 'Group', 'Title', 'Pages', 'Address'], array_map(static fn (array $s): array => [$s['id'], $s['group'], $s['title'], (string) $s['pages'], $s['url']], array_values($sites)));
        out();
        out('Archive one, or a whole group, with: sudo offline queue site NAME|GROUP');
        out('Any other site: sudo offline queue site https://example.org/ --name example.org_en_all --pages 500');
        return 0;
    }

    // Site archiving runs a container, which needs root; everything else is the service account's work.
    if ($action === 'archive') {
        require_root('queue archive');
        if (prefix() === '' && !have('podman')) {
            fail('Podman is not installed. Run the installer, or: sudo apt install podman');
        }
        $lock = lock('archive');
        if ($lock === null) {
            out('A site is already being archived; skipped.');
            return 0;
        }
        $done = 0;
        foreach ($items as $item) {
            if ($item['kind'] !== 'site' || $item['state'] !== 'queued') {
                continue;
            }
            out(paint("Archiving {$item['title']} ({$item['url']}), up to {$item['pages']} pages", 'bold'));
            as_service(['queue', 'mark', $item['id'], 'running', 'archiving since ' . utc_now()]);
            [$state, $message] = queue_archive_site($item);
            as_service(['queue', 'mark', $item['id'], $state, $message]);
            out("  $state: $message");
            $done++;
        }
        // Publishing, where it is automatic, is the service account's work.
        return $done > 0 && config_bool('STAGING_AUTOPUBLISH') ? as_service(['queue', 'run']) : 0;
    }

    become_service_user("queue $action");
    @mkdir(path('staging'), 02770, true);

    if ($action === 'mark') {
        // Internal: the archiver, which runs as root, records its results through here.
        if (preg_match('/^[0-9a-f]{8}$/', $rest[0] ?? '') !== 1 || !in_array($rest[1] ?? '', QUEUE_STATES, true)) {
            fail('Usage: offline queue mark ID STATE MESSAGE');
        }
        queue_mark($rest[0], $rest[1], substr((string) ($rest[2] ?? ''), 0, 300));
        return 0;
    }

    if ($action === 'add') {
        $to = 'movies';
        $audio = in_array('--audio', $rest, true);
        $urls = [];
        for ($i = 0; $i < count($rest); $i++) {
            if ($rest[$i] === '--to' && isset($rest[$i + 1])) {
                $to = $rest[++$i];
            } elseif ($rest[$i][0] !== '-') {
                $urls[] = $rest[$i];
            }
        }
        if ($audio && $to === 'movies') {
            $to = 'music';
        }
        if (!in_array($to, MEDIA_FOLDERS, true) || $urls === []) {
            fail('Usage: sudo offline queue add URL ... [--to ' . implode('|', MEDIA_FOLDERS) . '] [--audio]');
        }
        foreach ($urls as $url) {
            if (!valid_download_url($url)) {
                warn('Skipped ' . printable($url, 80) . ': only https:// addresses are accepted.');
                continue;
            }
            $direct = in_array(extension((string) parse_url($url, PHP_URL_PATH)), SYNC_EXTENSIONS['media'], true);
            $item = queue_add(['kind' => $direct ? 'file' : ($audio ? 'audio' : 'video'), 'url' => $url, 'to' => $to]);
            out("Queued {$item['id']}: " . printable($url, 100));
        }
        out('The queue runs every ten minutes on a server. Run it now with: sudo offline queue run');
        return 0;
    }

    if ($action === 'site') {
        $sites = site_list();
        $chosen = [];
        $name = null;
        $pages = 500;
        $url = null;
        for ($i = 0; $i < count($rest); $i++) {
            if ($rest[$i] === '--name' && isset($rest[$i + 1])) {
                $name = $rest[++$i];
            } elseif ($rest[$i] === '--pages' && isset($rest[$i + 1])) {
                $pages = max(1, min(50000, (int) $rest[++$i]));
            } elseif (str_starts_with($rest[$i], 'https://')) {
                $url = $rest[$i];
            } elseif ($rest[$i][0] !== '-') {
                $matches = array_filter($sites, static fn (array $s): bool => $s['id'] === $rest[$i] || $s['group'] === $rest[$i]);
                if ($matches === []) {
                    fail('No site or group called "' . printable($rest[$i]) . '". See: offline queue sites');
                }
                $chosen = array_merge($chosen, array_values($matches));
            }
        }
        if ($url !== null) {
            if (!valid_site_url($url) || $name === null || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,80}$/', $name) !== 1) {
                fail('Usage: sudo offline queue site https://example.org/ --name example.org_en_all [--pages 500]');
            }
            $chosen[] = ['url' => $url, 'name' => $name, 'title' => $name, 'pages' => $pages];
        }
        if ($chosen === []) {
            fail('Usage: sudo offline queue site NAME|GROUP   or   sudo offline queue site URL --name NAME');
        }
        foreach ($chosen as $site) {
            $item = queue_add(['kind' => 'site', 'url' => $site['url'], 'name' => $site['name'], 'title' => $site['title'], 'pages' => $site['pages'], 'to' => 'library']);
            out("Queued {$item['id']}: {$site['title']} ({$site['url']})");
        }
        out('Sites are archived by: sudo offline queue archive   (hourly on a server). A large site takes hours.');
        return 0;
    }

    if ($action === 'remove' || $action === 'retry') {
        $id = $rest[0] ?? '';
        if (preg_match('/^[0-9a-f]{8}$/', $id) !== 1 || !in_array($id, array_column($items, 'id'), true)) {
            fail('No queue item with that id. See: offline queue');
        }
        if ($action === 'remove') {
            queue_update(static fn (array $all): array => array_filter($all, static fn (array $i): bool => $i['id'] !== $id));
            run(['rm', '-rf', '--', staging_dir($id)], 60);
        } else {
            queue_mark($id, 'queued', '');
        }
        out($action === 'remove' ? 'Removed, together with anything it had staged.' : 'Queued again.');
        return 0;
    }

    if ($action !== 'run') {
        fail('Usage: offline queue [list] | sites   |   sudo offline queue add|site|run|archive|remove|retry ...');
    }
    $lock = lock('queue');
    if ($lock === null) {
        out('The queue is already running; skipped.');
        return 0;
    }
    $reserve = config_int('DOWNLOAD_MIN_FREE_GB') * 1024 ** 3;
    $published = 0;
    foreach ($items as $item) {
        if ($item['state'] === 'queued' && $item['kind'] !== 'site') {
            if (free_bytes() < $reserve) {
                out('Free space is below the reserve; the queue is paused.');
                break;
            }
            out(paint("Fetching {$item['id']}: " . printable($item['url'], 100), 'bold'));
            queue_mark($item['id'], 'running', 'fetching since ' . utc_now());
            [$item['state'], $message] = queue_fetch($item);
            queue_mark($item['id'], $item['state'], $message);
            out("  {$item['state']}: $message");
        }
        if ($item['state'] === 'staged' && config_bool('STAGING_AUTOPUBLISH')) {
            $published += stage_publish($item)[0];
        }
    }
    if ($published > 0) {
        rebuild_library(false);
        out(plural($published, 'file') . ' published to the shared folders.');
    }
    command_status(['--write']);
    return 0;
}

function command_stage(array $args): int
{
    $action = $args[0] ?? 'list';
    $items = queue_load();
    $staged = array_filter($items, static fn (array $i): bool => $i['state'] === 'staged');

    if ($action === 'list') {
        if ($staged === []) {
            out('Nothing is waiting in the staging area.');
            return 0;
        }
        foreach ($staged as $item) {
            heading("{$item['id']}  " . printable($item['kind'] === 'site' ? $item['title'] : $item['url'], 90));
            $dir = staging_dir($item['id']);
            $iterator = is_dir($dir) && is_readable($dir) ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) : [];
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    out('  ' . printable(substr($file->getPathname(), strlen($dir) + 1), 90) . '  ' . human_bytes($file->getSize()));
                }
            }
            out('  goes to: ' . ($item['kind'] === 'site' ? 'the library' : 'media/' . $item['to']));
        }
        out();
        out('Release to every system with: sudo offline stage publish ID|all   Discard with: sudo offline stage discard ID');
        return 0;
    }

    if (!in_array($action, ['publish', 'discard'], true) || !isset($args[1])) {
        fail('Usage: offline stage [list]   |   sudo offline stage publish ID|all [--to FOLDER]   |   sudo offline stage discard ID');
    }
    become_service_user("stage $action");
    $to = null;
    if (($position = array_search('--to', $args, true)) !== false && in_array($args[$position + 1] ?? '', MEDIA_FOLDERS, true)) {
        $to = $args[$position + 1];
    }
    $count = 0;
    foreach ($items as $item) {
        if ($item['state'] !== 'staged' || ($args[1] !== 'all' && $item['id'] !== $args[1])) {
            continue;
        }
        $id = $item['id'];
        if ($action === 'discard') {
            if (preg_match('/^[0-9a-f]{8}$/', $id) === 1) {
                run(['rm', '-rf', '--', staging_dir($id)], 60);
            }
            queue_update(static fn (array $all): array => array_filter($all, static fn (array $i): bool => $i['id'] !== $id));
            out("Discarded $id.");
            $count++;
            continue;
        }
        if ($to !== null && $item['kind'] !== 'site') {
            $item['to'] = $to;
        }
        [$published, $skipped] = stage_publish($item);
        out("$id: " . ($published > 0 ? plural($published, 'file') . ' published' . ($skipped > 0 ? ", $skipped left in staging" : '') : 'nothing of an accepted type to publish'));
        $count += $published > 0 ? 1 : 0;
    }
    if ($count === 0) {
        fail('No staged item matched. See: offline stage');
    }
    if ($action === 'publish') {
        rebuild_library(false);
        command_status(['--write']);
        out('Published. Other systems fetch it at their next sync, within five minutes.');
    }
    return 0;
}
