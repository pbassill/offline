<?php
/**
 * Offline — command-line actions.
 *
 * Every external program is started with an argument array (never through a
 * shell), a fixed PATH and a clean environment. Anything that changes shared
 * content runs as the unprivileged "offline" account, even when invoked
 * through sudo.
 */

declare(strict_types=1);

namespace Offline;

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/maps.php';
require_once __DIR__ . '/apps.php';
require_once __DIR__ . '/radio.php';
require_once __DIR__ . '/server.php';
require_once __DIR__ . '/broadcast.php';
require_once __DIR__ . '/games.php';
require_once __DIR__ . '/network.php';
require_once __DIR__ . '/vault.php';
require_once __DIR__ . '/rebuild.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/net.php';

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------

function use_colour(): bool
{
    static $colour = null;
    if ($colour === null) {
        $colour = getenv('NO_COLOR') === false && \function_exists('posix_isatty') && @posix_isatty(STDOUT);
    }
    return $colour;
}

function paint(string $text, string $style): string
{
    static $codes = ['bold' => '1', 'dim' => '2', 'red' => '31', 'green' => '32', 'yellow' => '33', 'cyan' => '36'];
    return use_colour() && isset($codes[$style]) ? "\033[{$codes[$style]}m$text\033[0m" : $text;
}

function out(string $line = ''): void
{
    // A closed pipe (for example "offline status | head") simply ends the output.
    if (@fwrite(STDOUT, $line . "\n") === false) {
        exit(0);
    }
}

function warn(string $line): void
{
    fwrite(STDERR, paint('warning: ', 'yellow') . $line . "\n");
}

function fail(string $line, int $code = 1): never
{
    fwrite(STDERR, paint('error: ', 'red') . $line . "\n");
    exit($code);
}

function heading(string $text): void
{
    out();
    out(paint($text, 'bold'));
}

/** Print rows as aligned columns. Text is cleaned so nothing can inject terminal escapes. */
function table(array $headers, array $rows): void
{
    $rows = array_map(static fn (array $r): array => array_map(static fn ($c): string => printable((string) $c), $r), $rows);
    $widths = array_map(__NAMESPACE__ . '\\width', $headers);
    foreach ($rows as $row) {
        foreach (array_values($row) as $i => $cell) {
            $widths[$i] = max($widths[$i] ?? 0, width($cell));
        }
    }
    $format = static function (array $cells) use ($widths): string {
        $line = '';
        foreach (array_values($cells) as $i => $cell) {
            $line .= $cell . str_repeat(' ', max(0, $widths[$i] - width($cell)) + 2);
        }
        return '  ' . rtrim($line);
    };
    out(paint($format($headers), 'dim'));
    foreach ($rows as $row) {
        out($format($row));
    }
}

/**
 * Make untrusted text safe to print: control and formatting characters
 * (including terminal escapes and bidirectional overrides) are removed and the
 * length is bounded.
 */
function printable(string $text, int $max = 120): string
{
    if (preg_match('//u', $text) === 1) {
        $clean = preg_replace('/[\p{C}\p{Zl}\p{Zp}]/u', '', $text) ?? '';
        if (width($clean) > $max) {
            preg_match('/^\X{0,' . ($max - 3) . '}/u', $clean, $m);
            $clean = ($m[0] ?? '') . '...';
        }
        return $clean;
    }
    $clean = preg_replace('/[^\x20-\x7e]/', '?', $text) ?? '';
    return strlen($clean) > $max ? substr($clean, 0, $max - 3) . '...' : $clean;
}

function width(string $text): int
{
    $count = preg_match_all('/\X/u', $text);
    return $count === false ? strlen($text) : $count;
}

function confirm(string $question, bool $assumeYes): bool
{
    if ($assumeYes) {
        return true;
    }
    if (!\function_exists('posix_isatty') || !@posix_isatty(STDIN)) {
        return false;
    }
    fwrite(STDOUT, "$question [y/N] ");
    return in_array(strtolower(trim((string) fgets(STDIN))), ['y', 'yes'], true);
}

// ---------------------------------------------------------------------------
// Processes and privileges
// ---------------------------------------------------------------------------

function child_env(): array
{
    return [
        'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'LANG' => 'C.UTF-8',
        'LC_ALL' => 'C.UTF-8',
        'TZ' => 'UTC',
        'HOME' => path('state'),
    ];
}

function have(string $binary): bool
{
    // An absolute path names one program; anything else is looked for on the fixed PATH.
    if ($binary !== '' && $binary[0] === '/') {
        return is_executable($binary);
    }
    foreach (explode(':', child_env()['PATH']) as $dir) {
        if (is_executable("$dir/$binary")) {
            return true;
        }
    }
    return false;
}

/**
 * Run a program and capture its output. Returns [exit code, stdout, stderr].
 * A timeout of zero means wait for as long as it takes.
 */
function run(array $command, int $timeout = 60, array $env = []): array
{
    if (!have($command[0])) {
        return [127, '', $command[0] . ' is not installed'];
    }
    $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($command, $spec, $pipes, null, $env + child_env());
    if (!is_resource($proc)) {
        return [126, '', 'could not start ' . $command[0]];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $buffers = [1 => '', 2 => ''];
    $deadline = $timeout > 0 ? microtime(true) + $timeout : null;
    $open = [1 => $pipes[1], 2 => $pipes[2]];
    $timedOut = false;
    while ($open !== []) {
        $read = array_values($open);
        $write = $except = null;
        $wait = $deadline === null ? 5 : max(0, (int) ceil($deadline - microtime(true)));
        if (@stream_select($read, $write, $except, $wait) === false) {
            break;
        }
        foreach ($open as $id => $pipe) {
            $chunk = fread($pipe, 65536);
            if ($chunk !== false && $chunk !== '') {
                $buffers[$id] .= $chunk;
            }
            if (feof($pipe)) {
                fclose($pipe);
                unset($open[$id]);
            }
        }
        if ($deadline !== null && microtime(true) >= $deadline && $open !== []) {
            $timedOut = true;
            proc_terminate($proc, 15);
            usleep(300000);
            proc_terminate($proc, 9);
            break;
        }
    }
    foreach ($open as $pipe) {
        fclose($pipe);
    }
    $code = proc_close($proc);
    return [$timedOut ? 124 : $code, $buffers[1], $timedOut ? 'timed out' : $buffers[2]];
}

/** Run a program attached to the terminal (or the journal) so progress is visible. */
function run_live(array $command, array $env = []): int
{
    if (!have($command[0])) {
        warn($command[0] . ' is not installed');
        return 127;
    }
    $spec = [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR];
    $proc = @proc_open($command, $spec, $pipes, null, $env + child_env());
    return is_resource($proc) ? proc_close($proc) : 126;
}

/** True when running as root outside the test suite. */
function is_root(): bool
{
    return \function_exists('posix_geteuid') && posix_geteuid() === 0;
}

/**
 * Make sure we are the unprivileged "offline" account before touching shared
 * content. Root gives up its privileges for good; anyone else is told to use sudo.
 */
function become_service_user(string $command): void
{
    umask(0002);
    if (prefix() !== '') {
        return;
    }
    $account = posix_getpwnam(SERVICE_USER);
    if ($account === false) {
        fail('The "offline" service account does not exist yet. Run the installer first.');
    }
    if (posix_geteuid() === $account['uid']) {
        return;
    }
    if (!is_root()) {
        fail("\"offline $command\" changes shared content. Run it as: sudo offline $command");
    }
    if (!posix_initgroups(SERVICE_USER, $account['gid'])
        || !posix_setgid($account['gid'])
        || !posix_setuid($account['uid'])
        || posix_geteuid() === 0) {
        fail('Could not drop root privileges; refusing to continue.');
    }
    @chdir(path('state'));
}

/** Take an exclusive lock so two content jobs never run at once. */
function lock(string $name, bool $wait = false)
{
    $file = path('state') . "/$name.lock";
    $handle = @fopen($file, 'c');
    if ($handle === false) {
        fail("Cannot open lock file $file");
    }
    if (!flock($handle, $wait ? LOCK_EX : LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return null;
    }
    return $handle;
}

function write_atomic(string $file, string $data, int $mode = 0644): bool
{
    $tmp = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $data) === false) {
        return false;
    }
    @chmod($tmp, $mode);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function write_json(string $file, array $data): bool
{
    return write_atomic($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
}

function free_bytes(): int
{
    $free = @disk_free_space(path('share'));
    return $free === false ? 0 : (int) $free;
}

// ---------------------------------------------------------------------------
// Network facts
// ---------------------------------------------------------------------------

/** Global IPv4 addresses of this machine: [['ip' => ..., 'prefix' => 24, 'if' => 'wlp2s0'], ...]. */
function own_addresses(): array
{
    [$code, $json] = run(['ip', '-j', '-4', 'addr', 'show', 'scope', 'global'], 5);
    $addresses = [];
    foreach ($code === 0 ? (json_decode($json, true) ?: []) : [] as $link) {
        foreach ($link['addr_info'] ?? [] as $info) {
            if (isset($info['local']) && filter_var($info['local'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $addresses[] = ['ip' => $info['local'], 'prefix' => (int) ($info['prefixlen'] ?? 32), 'if' => (string) ($link['ifname'] ?? '')];
            }
        }
    }
    return $addresses;
}

function has_default_route(): bool
{
    [$code, $json] = run(['ip', '-j', 'route', 'show', 'default'], 5);
    return $code === 0 && (json_decode($json, true) ?: []) !== [];
}

function port_open(string $host, int $port, float $timeout = 0.4): bool
{
    $socket = @stream_socket_client("tcp://$host:$port", $errno, $error, $timeout);
    if ($socket === false) {
        return false;
    }
    fclose($socket);
    return true;
}

/** HTTPS only, HTTPS redirects only. Returns the body or null. */
function https_get(string $url, int $timeout = 60): ?string
{
    if (prefix() !== '' && str_starts_with($url, 'file://')) {
        $body = @file_get_contents(substr($url, 7));
        return $body === false ? null : $body;
    }
    if (!str_starts_with($url, 'https://')) {
        return null;
    }
    [$code, $body] = run([
        'curl', '--silent', '--show-error', '--fail', '--location', '--max-redirs', '5',
        '--proto', '=https', '--proto-redir', '=https', '--tlsv1.2',
        '--max-time', (string) $timeout, '--max-filesize', '67108864',
        '--user-agent', 'offline/' . VERSION, '--', $url,
    ], $timeout + 5);
    return $code === 0 ? $body : null;
}

// ---------------------------------------------------------------------------
// Status
// ---------------------------------------------------------------------------

function power_state(): array
{
    $power = ['on_ac' => null, 'battery' => null, 'battery_state' => null, 'seconds_left' => null, 'profile' => null];
    $base = prefix() . '/sys/class/power_supply';
    foreach (@scandir($base) ?: [] as $supply) {
        if ($supply[0] === '.') {
            continue;
        }
        $read = static fn (string $name): ?string => ($v = @file_get_contents("$base/$supply/$name")) === false ? null : trim($v);
        $type = $read('type');
        if ($type === 'Mains' || $type === 'USB') {
            if ($read('online') === '1') {
                $power['on_ac'] = true;
            } elseif ($power['on_ac'] === null && $type === 'Mains') {
                $power['on_ac'] = false;
            }
        } elseif ($type === 'Battery' && $read('scope') !== 'Device' && $read('capacity') !== null) {
            $power['battery'] = (int) $read('capacity');
            $power['battery_state'] = strtolower((string) $read('status'));
            $now = $read('energy_now') ?? $read('charge_now');
            $rate = $read('power_now') ?? $read('current_now');
            if ($power['battery_state'] === 'discharging' && $now !== null && (int) $rate > 0) {
                $power['seconds_left'] = (int) ((int) $now / (int) $rate * 3600);
            }
        }
    }
    if ($power['on_ac'] === null && $power['battery_state'] !== null) {
        $power['on_ac'] = $power['battery_state'] !== 'discharging';
    }
    if (prefix() === '' && have('powerprofilesctl')) {
        [$code, $profile] = run(['powerprofilesctl', 'get'], 3);
        if ($code === 0 && preg_match('/^[a-z-]{3,20}$/', trim($profile)) === 1) {
            $power['profile'] = trim($profile);
        }
    }
    return $power;
}

function service_states(): array
{
    $units = [
        'share' => ['offline-tls.service', 'Sharing (encrypted)', TLS_PORT],
        'daemon' => ['offline-rsync.service', 'Sharing daemon', SYNC_PORT],
        'reader' => ['offline-reader.service', 'Library reader', READER_PORT],
        'sync' => ['offline-peer-sync.timer', 'Sync timer', null],
        'broadcast' => ['offline-broadcast.path', 'Family broadcast', null],
    ] + (is_server() ? ['queue' => ['offline-queue.timer', 'Download queue', null]] : []) + [
        'portal' => ['apache2.service', 'Web portal', 80],
        'discovery' => ['avahi-daemon.service', 'Peer discovery', null],
        'firewall' => ['ufw.service', 'Firewall', null],
        'ai' => ['ollama.service', 'Ollama', 11434],
    ];
    $states = [];
    if (prefix() === '' && have('systemctl')) {
        [, $output] = run(array_merge(['systemctl', 'is-active'], array_column($units, 0)), 8);
        $states = preg_split('/\R/', trim($output)) ?: [];
    }
    $services = [];
    $index = 0;
    $ufwEnabled = preg_match('/^ENABLED=yes\s*$/m', (string) @file_get_contents('/etc/ufw/ufw.conf')) === 1;
    foreach ($units as $key => [$unit, $label, $port]) {
        $state = $states[$index++] ?? 'unknown';
        if ($key === 'firewall' && $state === 'active' && !$ufwEnabled) {
            $state = 'disabled';
        }
        $services[$key] = [
            'unit' => $unit,
            'label' => $label,
            'state' => preg_match('/^[a-z-]{2,20}$/', $state) === 1 ? $state : 'unknown',
            'port' => $port,
            'listening' => $port === null || prefix() !== '' ? null : port_open('127.0.0.1', $port),
        ];
    }
    return $services;
}

function ollama_models(): array
{
    if (prefix() !== '' || !port_open('127.0.0.1', 11434, 0.3)) {
        return [];
    }
    [$code, $body] = run(['curl', '--silent', '--fail', '--max-time', '3', '--proto', '=http', 'http://127.0.0.1:11434/api/tags'], 5);
    $models = [];
    foreach ($code === 0 ? (json_decode($body, true)['models'] ?? []) : [] as $model) {
        if (is_string($model['name'] ?? null)) {
            $models[] = ['name' => substr($model['name'], 0, 80), 'size' => (int) ($model['size'] ?? 0)];
        }
    }
    return $models;
}

function collect_status(): array
{
    $meminfo = [];
    foreach (@file('/proc/meminfo') ?: [] as $line) {
        if (preg_match('/^(\w+):\s+(\d+) kB/', $line, $m) === 1) {
            $meminfo[$m[1]] = (int) $m[2] * 1024;
        }
    }
    $os = 'Linux';
    foreach (@file('/etc/os-release') ?: [] as $line) {
        if (preg_match('/^PRETTY_NAME="?([^"\n]+)"?/', $line, $m) === 1) {
            $os = $m[1];
        }
    }
    $books = library_books();
    $zims = [];
    $zimBytes = 0;
    foreach (list_zims() as $zim) {
        $zim['title'] = $books[$zim['file']]['title'] ?? '';
        $zim['description'] = $books[$zim['file']]['description'] ?? '';
        $zim['articles'] = $books[$zim['file']]['articles'] ?? 0;
        $zim['indexed'] = isset($books[$zim['file']]);
        $zimBytes += $zim['size'];
        $zims[] = $zim;
    }
    $media = [];
    foreach (@scandir(path('media')) ?: [] as $entry) {
        if ($entry[0] !== '.' && is_dir(path('media') . '/' . $entry) && !is_link(path('media') . '/' . $entry)) {
            $media[$entry] = tree_stats(path('media') . '/' . $entry);
        }
    }
    $total = (int) (@disk_total_space(path('share')) ?: 0);
    $free = free_bytes();
    $peers = read_json(path('peers'));

    return [
        'version' => VERSION,
        'generated' => utc_now(),
        'host' => [
            'name' => (string) gethostname(),
            'os' => $os,
            'kernel' => php_uname('r'),
            'uptime' => (int) (float) (@file_get_contents('/proc/uptime') ?: '0'),
            'load' => (float) (explode(' ', (string) @file_get_contents('/proc/loadavg'))[0] ?? 0),
            'memory_total' => $meminfo['MemTotal'] ?? 0,
            'memory_available' => $meminfo['MemAvailable'] ?? 0,
            'addresses' => own_addresses(),
            'online' => has_default_route(),
        ],
        'power' => power_state(),
        'storage' => [
            'total' => $total,
            'free' => $free,
            'used_percent' => $total > 0 ? (int) round(($total - $free) / $total * 100) : 0,
        ],
        'knowledge' => ['count' => count($zims), 'bytes' => $zimBytes, 'zims' => $zims],
        'media' => $media,
        'services' => service_states(),
        'ai' => ['models' => ollama_models()],
        'maps' => ['ready' => maps_ready(), 'regions' => installed_maps(), 'routing' => routing_status(),
            'places' => read_json(places_dir() . '/places.json') + ['ready' => is_file(places_dir() . '/names.tsv')]],
        'apps' => array_values(app_states()),
        'radio' => radio_status(),
        'games' => games_status(),
        'hotspot' => ['set_up' => is_file(hotspot_profile_file()), 'on' => hotspot_active()],
        'time_server' => time_serving(),
        'people' => people_status(),
        'net' => net_status(),
        'tls' => tls_status(),
        'vault' => vault_status(),
        'rebuild' => rebuild_status(),
        'house_key' => is_file(path('key')) && (int) @filesize(path('key')) > 0,
        'role' => is_server() ? 'server' : 'workstation',
        'servers' => array_map(static fn (array $s): string => peer_key($s['host'], $s['port']), servers()),
        'queue' => queue_summary(),
        'broadcast' => ['hours' => config_int('BROADCAST_HOURS', 1, 24), 'mesh_gateway' => config_bool('MESH_GATEWAY')],
        'sync' => [
            'enabled' => config_bool('SYNC_ENABLED'),
            'last_run' => $peers['last_run'] ?? null,
            'peers' => array_values($peers['peers'] ?? []),
        ],
    ];
}

function state_mark(string $state, ?bool $listening = null): string
{
    $good = in_array($state, ['active', 'ok'], true) && $listening !== false;
    $bad = in_array($state, ['failed', 'fail'], true) || ($state === 'active' && $listening === false);
    return paint($good ? '●' : ($bad ? '✖' : '○'), $good ? 'green' : ($bad ? 'red' : 'yellow'));
}

function command_status(array $args): int
{
    $json = in_array('--json', $args, true);
    $write = in_array('--write', $args, true);
    if ($write) {
        become_service_user('status --write');
    }
    $status = collect_status();
    if ($write) {
        if (!is_dir(dirname(path('status')))) {
            @mkdir(dirname(path('status')), 0755, true);
        }
        return write_json(path('status'), $status) ? 0 : 1;
    }
    if ($json) {
        out(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        return 0;
    }

    $host = $status['host'];
    out(paint('OFFLINE ' . VERSION, 'bold') . '  ' . printable($host['name']) . " ({$status['role']})  " . paint(utc_display($status['generated']), 'dim'));

    heading('System');
    out('  ' . printable($host['os']) . ', kernel ' . printable($host['kernel']) . ', up ' . human_duration($host['uptime']) . ', load ' . number_format($host['load'], 2));
    $addresses = array_map(static fn (array $a): string => "{$a['ip']}/{$a['prefix']} ({$a['if']})", $host['addresses']);
    out('  Addresses  ' . ($addresses === [] ? 'no network address' : printable(implode(', ', $addresses), 200)));
    out('  Internet   ' . ($host['online'] ? 'a default route is present' : 'no default route (working offline)'));
    $power = $status['power'];
    if ($power['battery'] !== null) {
        $line = "battery {$power['battery']} % ({$power['battery_state']}";
        $line .= $power['seconds_left'] !== null ? ', about ' . human_duration($power['seconds_left']) . ' left)' : ')';
    } else {
        $line = $power['on_ac'] === false ? 'on battery' : 'mains power, no battery reported';
    }
    out('  Power      ' . $line . ($power['profile'] !== null ? ", profile {$power['profile']}" : ''));
    $storage = $status['storage'];
    out('  Storage    ' . human_bytes($storage['free']) . ' free of ' . human_bytes($storage['total']) . " ({$storage['used_percent']} % used)");
    out('  Memory     ' . human_bytes($host['memory_available']) . ' available of ' . human_bytes($host['memory_total']));

    heading('Services');
    foreach ($status['services'] as $service) {
        $detail = $service['state'];
        if ($service['port'] !== null && $service['listening'] !== null) {
            $detail .= $service['listening'] ? ", listening on port {$service['port']}" : ", nothing listening on port {$service['port']}";
        }
        out('  ' . state_mark($service['state'], $service['listening']) . ' ' . str_pad($service['label'], 21) . ' ' . $detail);
    }
    out('  ' . state_mark($status['house_key'] ? 'ok' : 'fail') . ' ' . str_pad('House key', 21) . ' ' . ($status['house_key'] ? 'set' : 'not set; run: sudo offline key new'));

    heading('Knowledge');
    $knowledge = $status['knowledge'];
    out('  ' . plural($knowledge['count'], 'ZIM file') . ', ' . human_bytes($knowledge['bytes']));
    $rows = [];
    foreach ($knowledge['zims'] as $zim) {
        $rows[] = [$zim['title'] !== '' ? $zim['title'] : $zim['base'], $zim['date'], human_bytes($zim['size']), $zim['indexed'] ? 'in reader' : 'not indexed'];
    }
    if ($rows !== []) {
        table(['Title', 'Edition', 'Size', 'State'], $rows);
    } else {
        out('  Nothing downloaded yet. See: offline catalogue');
    }

    heading('Media');
    $rows = [];
    foreach ($status['media'] as $folder => $stats) {
        $rows[] = [$folder, (string) $stats['files'], human_bytes($stats['bytes'])];
    }
    if ($rows !== []) {
        table(['Folder', 'Files', 'Size'], $rows);
    } else {
        out('  No media folders found.');
    }

    heading('Maps');
    $regions = array_filter($status['maps']['regions'], static fn (array $m): bool => $m['id'] !== 'world');
    out('  ' . ($regions === [] ? 'No map regions yet. See: offline maps' : plural(count($regions), 'region') . ': ' . printable(implode(', ', array_map(static fn (array $m): string => $m['id'] . ' (' . human_bytes($m['size']) . ')', $regions)), 200)));

    heading('Applications');
    $installedApps = array_filter($status['apps'], static fn (array $a): bool => $a['installed']);
    if ($installedApps === []) {
        out('  None installed. See: offline apps');
    } else {
        table(['App', 'Address', 'State'], array_map(static fn (array $a): array => [
            $a['title'], $a['port'] !== null ? "{$a['scheme']}://localhost:{$a['port']}/" : 'this system only',
            ($a['state'] === 'active' ? 'running' : 'stopped') . ($a['boot'] ? ', at boot' : ', on request'),
        ], $installedApps));
    }

    heading('AI');
    $models = $status['ai']['models'];
    out('  ' . ($models === [] ? 'No Ollama models reported.' : implode(', ', array_map(static fn (array $m): string => printable($m['name']) . ' (' . human_bytes($m['size']) . ')', $models))));

    $queue = $status['queue'];
    if ($status['role'] === 'server' || $queue['total'] > 0) {
        heading('Download queue and staging');
        out('  ' . ($queue['total'] === 0 ? 'The queue is empty.' : implode(', ', array_map(static fn (string $state, int $count): string => "$count $state", array_keys($queue['states']), $queue['states'])))
            . '  Staging holds ' . human_bytes($queue['staging_bytes']) . '.');
        if (($queue['states']['staged'] ?? 0) > 0) {
            out('  Release what is staged with: sudo offline stage publish all');
        }
    }

    heading('Servers and peers');
    out('  Central servers: ' . ($status['servers'] === [] ? 'none configured' : printable(implode(', ', $status['servers']), 200)));
    $last = $status['sync']['last_run'];
    if (!$status['sync']['enabled']) {
        out('  Peer sync is switched off in /etc/offline/offline.conf.');
    } elseif ($last === null) {
        out('  No sync has run yet.');
    } else {
        out('  Last sync ' . utc_display($last['finished'] ?? null) . ': ' . printable((string) ($last['message'] ?? '')));
    }
    $rows = [];
    foreach ($status['sync']['peers'] as $peer) {
        $rows[] = [$peer['address'] ?? '', $peer['name'] ?? '', $peer['auth'] ?? '', utc_display($peer['last_seen'] ?? null), human_bytes((int) ($peer['total_bytes'] ?? 0))];
    }
    if ($rows !== []) {
        table(['Address', 'Name', 'Access', 'Last seen', 'Received'], $rows);
    }
    out();
    return 0;
}

// ---------------------------------------------------------------------------
// Doctor
// ---------------------------------------------------------------------------

function command_doctor(array $args): int
{
    $checks = [];
    $add = static function (string $name, string $state, string $detail, string $fix = '') use (&$checks): void {
        $checks[] = compact('name', 'state', 'detail', 'fix');
    };
    $status = collect_status();

    $add('Configuration', is_readable(path('conf')) ? 'ok' : 'warn', is_readable(path('conf')) ? path('conf') : 'not found, using built-in defaults', 'sudo ./install.sh');

    $keyFile = path('key');
    if (!is_file($keyFile) || (int) @filesize($keyFile) === 0) {
        $add('House key', 'fail', 'not set, so no peer can be trusted', 'sudo offline key new   (or: sudo offline key set KEY)');
    } elseif (((int) @fileperms($keyFile) & 0007) !== 0) {
        $add('House key', 'fail', 'readable by every local account', 'sudo chmod 0640 ' . $keyFile);
    } else {
        $add('House key', 'ok', 'set and not world-readable');
        // The transport key is derived from the house key; the two must agree or no connection opens.
        $psk = @file_get_contents(path('psk'));
        if (!is_file(path('psk'))) {
            $add('Transport key', 'fail', 'missing, so the encrypted channel cannot open', 'sudo offline key refresh');
        } elseif ($psk !== false && trim($psk) !== SERVICE_USER . ':' . derived_key('offline-sync-v1')) {
            $add('Transport key', 'fail', 'does not match the house key', 'sudo offline key refresh');
        } else {
            $add('Transport key', 'ok', 'present; connections between systems are encrypted');
        }
    }

    foreach (['zim' => 'Knowledge folder', 'media' => 'Media folder', 'state' => 'State folder'] as $key => $label) {
        $add($label, is_dir(path($key)) ? 'ok' : 'fail', path($key) . (is_dir(path($key)) ? '' : ' is missing'), 'sudo ./install.sh');
    }

    foreach ($status['services'] as $key => $service) {
        $optional = in_array($key, ['ai', 'discovery'], true);
        if ($service['state'] === 'active' && $service['listening'] !== false) {
            $add($service['label'], 'ok', 'running');
        } elseif ($service['state'] === 'active') {
            $add($service['label'], 'fail', "running but nothing answers on port {$service['port']}", "sudo systemctl restart {$service['unit']}");
        } else {
            $fix = match ($key) {
                'firewall' => 'sudo ufw enable',
                'ai' => 'optional; run the installer again without --no-ai',
                default => "sudo systemctl enable --now {$service['unit']}",
            };
            $add($service['label'], $optional ? 'warn' : 'fail', "{$service['unit']} is {$service['state']}", $fix);
        }
    }

    $free = $status['storage']['free'];
    $floor = config_int('SYNC_MIN_FREE_GB') * 1024 ** 3;
    $add('Free space', $free > $floor ? 'ok' : 'warn', human_bytes($free) . ' free; peer sync pauses below ' . human_bytes($floor), 'offline prune   (or remove media you no longer need)');

    $notIndexed = array_filter($status['knowledge']['zims'], static fn (array $z): bool => !$z['indexed']);
    if ($status['knowledge']['count'] === 0) {
        $add('Library', 'warn', 'no ZIM files yet', 'offline catalogue   then   sudo offline get PACK');
    } elseif ($notIndexed !== []) {
        $add('Library', 'warn', plural(count($notIndexed), 'ZIM file') . ' not in the reader index', 'sudo offline library');
    } else {
        $add('Library', 'ok', plural($status['knowledge']['count'], 'ZIM file') . ', all in the reader index');
    }

    $superseded = superseded_zims();
    $add('Old editions', $superseded === [] ? 'ok' : 'warn', $superseded === [] ? 'none' : plural(count($superseded), 'superseded file') . ' using ' . human_bytes(array_sum(array_column($superseded, 'size'))), 'sudo offline prune');

    $seen = array_filter($status['sync']['peers'], static fn (array $p): bool => ($p['auth'] ?? '') === 'ok');
    $rejected = array_filter($status['sync']['peers'], static fn (array $p): bool => ($p['auth'] ?? '') === 'rejected');
    if ($rejected !== []) {
        $add('Peers', 'warn', plural(count($rejected), 'system') . ' refused this system\'s house key', 'Give every system the same key: sudo offline key set KEY');
    } elseif ($seen === []) {
        $add('Peers', 'warn', 'no trusted system has been reached yet', 'sudo offline sync');
    } else {
        $add('Peers', 'ok', plural(count($seen), 'trusted system') . ' known');
    }
    $known = array_column($status['sync']['peers'], null, 'address');
    foreach ($status['servers'] as $server) {
        $auth = $known[$server]['auth'] ?? 'unknown';
        $add("Server $server", $auth === 'ok' ? 'ok' : 'warn', match ($auth) {
            'ok' => 'reached at ' . utc_display($known[$server]['last_sync'] ?? null),
            'rejected' => 'holds a different house key',
            'unknown' => 'not yet contacted',
            default => 'not reached at the last sync (expected when it is out of range)',
        }, 'sudo offline server test');
    }
    if ($status['vault']['ready']) {
        $open = ((int) @fileperms(path('vault')) & 0007) !== 0;
        $add('Family vault', $open ? 'fail' : 'ok', $open ? 'its folder is open to every local account' : plural($status['vault']['files'], 'encrypted file') . ', closed to other accounts',
            'sudo chmod 2770 ' . path('vault'));
    }
    $net = $status['net'];
    if ($net['joined'] && prefix() === '') {
        $add('Private network', $net['up'] ? 'ok' : 'fail', $net['up'] ? "tunnel up as {$net['address']}, " . plural(count($net['peers']), 'other system') . ' known' : 'joined, but the tunnel is down',
            'sudo systemctl restart wg-quick@' . NET_INTERFACE);
    }
    if ($net['mesh']['enabled'] && prefix() === '') {
        $add('Radio layer', $net['mesh']['state'] === 'active' ? 'ok' : 'warn', $net['mesh']['state'] === 'active'
            ? ($net['mesh']['radios'] === [] ? 'running over the home network and the tunnels; no LoRa radio plugged in' : 'running, with ' . plural(count($net['mesh']['radios']), 'LoRa radio'))
            : "switched on but {$net['mesh']['state']}", 'sudo systemctl restart ' . MESH_UNIT);
    }
    $people = $status['people'];
    if ($people['children_here'] > 0) {
        $guarded = children_guard_active();
        $add('Children\'s guard', $guarded ? 'ok' : 'fail', $guarded
            ? plural($people['children_here'], 'child account') . ' here, kept from the internet and from the services meant for adults'
            : plural($people['children_here'], 'child account') . ' here can reach the internet: the guard is not running', 'sudo systemctl enable --now ' . CHILDREN_GUARD);
    }
    if (prefix() === '' && is_dir('/var/www/offline')) {
        $add('Portal sign-in', !$people['sign_in'] ? 'fail' : ($people['adults'] > 0 ? 'ok' : 'warn'), match (true) {
            !$people['sign_in'] => 'the session folder is missing, so nobody can sign in and only the children\'s pages are shown',
            $people['adults'] > 0 => plural($people['adults'], 'adult account') . ', ' . plural($people['children'], 'child account'),
            default => 'no adult account yet, so only the children\'s pages can be opened',
        }, $people['sign_in'] ? 'sudo offline user add NAME --adult' : 'sudo systemd-tmpfiles --create /etc/tmpfiles.d/offline.conf');
        $days = $status['tls']['days'];
        $add('Certificate', $days === null ? 'fail' : ($days < 30 ? 'warn' : 'ok'), $days === null ? 'none, so adults cannot sign in' : "valid for another $days days", 'sudo offline tls renew');
    }
    if ($status['role'] === 'server' && (string) config('SYNC_EXPOSE') !== 'internet') {
        $add('Time service', $status['time_server'] ? 'ok' : 'warn', $status['time_server'] ? 'answering the home network' : 'this server is not serving time to the household', 'sudo offline time serve on');
        $add('Package cache', $status['rebuild']['cache'] ? 'ok' : 'warn', $status['rebuild']['cache']
            ? 'serving' . ($status['rebuild']['cache_offline_mode'] ? ' from what it holds (offline mode)' : '') : 'this server keeps no packages for rebuilding a system', 'sudo offline rebuild cache on');
    }
    $luanti = luanti_client_note();
    if ($luanti !== null) {
        $add('Luanti', 'warn', $luanti, 'upgrade this system to Ubuntu 26.04, or play the family worlds from one that has it');
    }
    foreach ($status['games']['worlds'] as $world) {
        if ($world['hosted'] && prefix() === '') {
            $add("World {$world['id']}", $world['state'] === 'active' ? 'ok' : 'warn', $world['state'] === 'active' ? "open on port {$world['port']}" : "hosted here but {$world['state']}", "sudo offline games start {$world['id']}");
        }
    }
    if ($status['role'] === 'server' && ($status['queue']['states']['failed'] ?? 0) > 0) {
        $add('Download queue', 'warn', plural($status['queue']['states']['failed'], 'download') . ' failed', 'offline queue   then   sudo offline queue retry ID');
    }

    if (in_array('--online', $args, true)) {
        $feed = https_get(config('CATALOGUE_URL') . '?count=1', 20);
        $add('Kiwix catalogue', $feed !== null && str_contains($feed, '<feed') ? 'ok' : 'warn', $feed !== null ? 'reachable' : 'not reachable (expected when offline)');
    }

    $worst = 0;
    out(paint('OFFLINE doctor', 'bold') . '  ' . paint(utc_display(utc_now()), 'dim'));
    out();
    foreach ($checks as $check) {
        $worst = max($worst, ['ok' => 0, 'warn' => 1, 'fail' => 2][$check['state']]);
        out('  ' . state_mark($check['state']) . ' ' . str_pad(printable($check['name'], 40), 21) . ' ' . printable($check['detail'], 160));
        if ($check['state'] !== 'ok' && $check['fix'] !== '') {
            out('      ' . paint('fix: ' . $check['fix'], 'dim'));
        }
    }
    out();
    out($worst === 0 ? 'Everything looks healthy.' : ($worst === 1 ? 'Working, with points to review above.' : 'Problems found; see the fixes above.'));
    return $worst === 2 ? 1 : 0;
}

// ---------------------------------------------------------------------------
// Kiwix catalogue
// ---------------------------------------------------------------------------

/** Query the Kiwix OPDS catalogue and return its entries in a simple form. */
function catalogue_query(array $params): ?array
{
    $base = (string) config('CATALOGUE_URL');
    $url = str_starts_with($base, 'file://') ? $base : $base . '?' . http_build_query($params);
    $body = https_get($url);
    if ($body === null) {
        return null;
    }
    $dom = new \DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $dom->loadXML($body, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        return null;
    }
    $xpath = new \DOMXPath($dom);
    $text = static fn (\DOMNode $entry, string $name): string => trim((string) $xpath->evaluate("string(*[local-name()='$name'])", $entry));
    $entries = [];
    foreach ($xpath->query("//*[local-name()='entry']") ?: [] as $entry) {
        $href = '';
        $length = 0;
        foreach ($xpath->query("*[local-name()='link']", $entry) ?: [] as $link) {
            $candidate = $link->getAttribute('href');
            if ($link->getAttribute('type') === 'application/x-zim' || preg_match('/\.zim(\.meta4)?$/', $candidate) === 1) {
                $href = $candidate;
                $length = (int) $link->getAttribute('length');
            }
        }
        $direct = preg_replace('/\.meta4$/', '', $href) ?? '';
        $file = basename((string) parse_url($direct, PHP_URL_PATH));
        $parsed = parse_zim_name($file);
        if ($parsed === null || !str_starts_with($direct, 'https://')) {
            continue;
        }
        $entries[] = [
            'base' => $parsed['base'],
            'date' => $parsed['date'],
            'file' => $file,
            'url' => $direct,
            'size' => $length,
            'title' => $text($entry, 'title'),
            'summary' => $text($entry, 'summary'),
            'language' => $text($entry, 'language'),
            'articles' => (int) $text($entry, 'articleCount'),
        ];
    }
    return $entries;
}

/**
 * Find the newest edition of a ZIM by its file base, e.g.
 * "wikipedia_en_medicine_maxi". Kiwix catalogues the name and the flavour
 * (maxi, nopic, mini) separately, so both spellings are tried.
 */
function resolve_zim(string $base): ?array
{
    $names = [$base];
    if (preg_match('/^(.+)_(maxi|nopic|mini)$/', $base, $m) === 1) {
        $names[] = $m[1];
    }
    $best = null;
    foreach ($names as $name) {
        foreach (catalogue_query(['name' => $name, 'count' => 100]) ?? [] as $entry) {
            if ($entry['base'] === $base && ($best === null || $entry['date'] > $best['date'])) {
                $best = $entry;
            }
        }
        if ($best !== null) {
            break;
        }
    }
    if ($best !== null) {
        $cache = read_json(path('cache'));
        $cache[$base] = $best + ['checked' => utc_now()];
        @write_json(path('cache'), $cache);
    }
    return $best;
}

function installed_edition(string $base): ?array
{
    foreach (list_zims() as $zim) {
        if ($zim['base'] === $base) {
            return $zim;
        }
    }
    return null;
}

function command_catalogue(array $args): int
{
    $check = in_array('--check', $args, true);
    $only = array_values(array_filter($args, static fn (string $a): bool => $a[0] !== '-'))[0] ?? null;
    if ($check) {
        become_service_user('catalogue --check');
    }
    $cache = read_json(path('cache'));
    $all = catalogue();
    $names = array_values(array_unique(array_column($all, 'pack')));
    $chosen = $only === null ? $names : expand_pack($only, $names);
    $packs = [];
    foreach ($all as $entry) {
        if (in_array($entry['pack'], $chosen, true)) {
            $packs[$entry['pack']][] = $entry;
        }
    }
    if ($packs === []) {
        fail($only === null ? 'The catalogue is empty.' : "No pack called \"$only\". Run: offline catalogue");
    }
    $missing = 0;
    foreach ($packs as $pack => $entries) {
        $approx = array_sum(array_column($entries, 'approx'));
        heading("Pack: $pack" . ($approx > 0 ? '  (about ' . human_bytes($approx) . ')' : ''));
        $rows = [];
        foreach ($entries as $entry) {
            $live = $check ? resolve_zim($entry['zim']) : ($cache[$entry['zim']] ?? null);
            $local = installed_edition($entry['zim']);
            if ($check && $live === null) {
                $missing++;
            }
            $state = $local === null ? '-' : 'installed ' . $local['date'];
            if ($local !== null && $live !== null && $live['date'] > $local['date']) {
                $state .= ', newer available';
            }
            $size = $live !== null ? human_bytes($live['size']) : ($check ? 'NOT FOUND' : ($entry['approx'] > 0 ? '~' . human_bytes($entry['approx']) : '?'));
            $rows[] = [$entry['zim'], $entry['title'], $size, $live['date'] ?? '', $state];
        }
        table(['ZIM', 'Contents', 'Size', 'Latest', 'This machine'], $rows);
    }
    out();
    if ($check) {
        out($missing === 0 ? 'Every entry was found in the live Kiwix catalogue.' : "$missing entr" . ($missing === 1 ? 'y was' : 'ies were') . ' not found. Use "offline search TEXT" to find the current name, then correct catalogue.local.tsv.');
        return $missing === 0 ? 0 : 1;
    }
    out('Sizes marked ~ are estimates. Refresh them with: sudo offline catalogue --check');
    out('Download with: sudo offline get PACK   or   sudo offline get ZIM');
    out('A tier on its own covers every subject: sudo offline get essential');
    return 0;
}

function command_search(array $args): int
{
    $language = 'eng';
    $words = [];
    for ($i = 0; $i < count($args); $i++) {
        if ($args[$i] === '--lang' && isset($args[$i + 1])) {
            $language = preg_replace('/[^a-z,]/', '', strtolower($args[++$i])) ?: 'eng';
        } elseif ($args[$i][0] !== '-') {
            $words[] = $args[$i];
        }
    }
    if ($words === []) {
        fail('Usage: offline search TEXT [--lang eng]');
    }
    $entries = catalogue_query(['q' => implode(' ', $words), 'lang' => $language, 'count' => 60]);
    if ($entries === null) {
        fail('The Kiwix catalogue could not be reached. This command needs an internet connection.');
    }
    if ($entries === []) {
        out('Nothing matched.');
        return 0;
    }
    usort($entries, static fn (array $a, array $b): int => $a['base'] <=> $b['base']);
    table(['ZIM', 'Title', 'Size', 'Edition'], array_map(static fn (array $e): array => [$e['base'], $e['title'], human_bytes($e['size']), $e['date']], $entries));
    out();
    out('Download with: sudo offline get ZIM');
    return 0;
}

// ---------------------------------------------------------------------------
// Downloading, pruning, library index
// ---------------------------------------------------------------------------

function valid_zim_file(string $file): bool
{
    $handle = @fopen($file, 'rb');
    if ($handle === false) {
        return false;
    }
    $magic = fread($handle, 4);
    fclose($handle);
    return $magic === "\x5a\x49\x4d\x04";
}

function download_zim(array $target): bool
{
    $incoming = path('incoming');
    if (!is_dir($incoming) && !@mkdir($incoming, 02775, true)) {
        warn("Cannot create $incoming");
        return false;
    }
    // Refuse anything Kiwix has not published a SHA-256 checksum for; aria2c
    // then verifies the finished file against that checksum.
    $meta = https_get($target['url'] . '.meta4', 60);
    if ($meta === null) {
        warn("Could not fetch the download description for {$target['file']}. Is this system online?");
        return false;
    }
    if (preg_match('/<hash\s+type="sha-256">\s*[0-9a-f]{64}\s*<\/hash>/i', $meta) !== 1) {
        warn("Kiwix publishes no SHA-256 checksum for {$target['file']}; refusing an unverifiable download.");
        return false;
    }
    $connections = (string) config_int('DOWNLOAD_CONNECTIONS', 1, 16);
    $code = run_live([
        'aria2c', '--no-conf', '--dir=' . $incoming,
        '--follow-metalink=mem', '--metalink-preferred-protocol=https', '--check-integrity=true',
        '--check-certificate=true', '--continue=true', '--auto-file-renaming=false', '--allow-overwrite=true',
        '--file-allocation=none', '--max-connection-per-server=' . $connections, '--split=' . $connections,
        '--min-split-size=20M', '--max-tries=8', '--retry-wait=10', '--timeout=60', '--connect-timeout=30',
        '--console-log-level=warn', '--summary-interval=60', '--user-agent=offline/' . VERSION,
        $target['url'] . '.meta4',
    ]);
    $partial = $incoming . '/' . $target['file'];
    if ($code === 32) {
        @unlink($partial);
        @unlink($partial . '.aria2');
        warn("{$target['file']} failed its checksum and has been discarded. Run the same command again.");
        return false;
    }
    if ($code !== 0 || !is_file($partial) || is_file($partial . '.aria2')) {
        warn("Download of {$target['file']} did not complete (aria2c exit $code). Run the same command again to resume.");
        return false;
    }
    if (!valid_zim_file($partial)) {
        @unlink($partial);
        warn("{$target['file']} is not a valid ZIM file and has been discarded.");
        return false;
    }
    @chmod($partial, 0664);
    if (!@rename($partial, path('zim') . '/' . $target['file'])) {
        warn("Could not move {$target['file']} into the library folder.");
        return false;
    }
    return true;
}

function command_get(array $args, bool $updateOnly = false): int
{
    $dryRun = in_array('--dry-run', $args, true);
    $yes = in_array('--yes', $args, true) || in_array('-y', $args, true);
    $items = array_values(array_filter($args, static fn (string $a): bool => $a[0] !== '-'));
    become_service_user($updateOnly ? 'update' : 'get');

    if ($updateOnly) {
        $items = array_values(array_unique(array_column(array_filter(list_zims(), static fn (array $z): bool => $z['date'] !== ''), 'base')));
        if ($items === []) {
            out('Nothing is installed yet.');
            return 0;
        }
    } elseif ($items === []) {
        fail('Usage: sudo offline get PACK|ZIM|URL ... [--dry-run] [--yes]');
    }

    $catalogue = catalogue();
    $packNames = array_values(array_unique(array_column($catalogue, 'pack')));
    $targets = [];
    $unresolved = [];
    foreach ($items as $item) {
        if (str_starts_with($item, 'https://')) {
            $direct = preg_replace('/\.meta4$/', '', $item) ?? '';
            $file = basename((string) parse_url($direct, PHP_URL_PATH));
            $host = (string) parse_url($direct, PHP_URL_HOST);
            $parsed = parse_zim_name($file);
            if ($parsed === null || !(str_ends_with($host, '.kiwix.org') || $host === 'kiwix.org')) {
                warn("Skipping $item: only dated ZIM files from kiwix.org can be fetched by URL.");
                continue;
            }
            $targets[$parsed['base']] = ['base' => $parsed['base'], 'date' => $parsed['date'], 'file' => $file, 'url' => $direct, 'size' => 0, 'title' => $file];
            continue;
        }
        if (str_contains($item, '://')) {
            warn("Skipping $item: only https:// addresses are accepted.");
            continue;
        }
        $members = expand_pack($item, $packNames);
        $bases = $members !== []
            ? array_values(array_unique(array_column(array_filter($catalogue, static fn (array $e): bool => in_array($e['pack'], $members, true)), 'zim')))
            : [$item];
        foreach ($bases as $base) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{1,180}$/', $base) !== 1) {
                warn("Skipping \"$base\": not a valid pack or ZIM name.");
                continue;
            }
            $live = resolve_zim($base);
            if ($live === null) {
                $unresolved[] = $base;
            } else {
                $targets[$base] = $live;
            }
        }
    }

    $plan = [];
    $rows = [];
    $bytes = 0;
    foreach ($targets as $base => $target) {
        $local = installed_edition($base);
        if ($local !== null && $local['date'] >= $target['date']) {
            if (!$updateOnly) {
                $rows[] = [$target['file'], human_bytes($target['size']), "already installed ({$local['date']})"];
            }
            continue;
        }
        $plan[] = $target;
        $bytes += $target['size'];
        $rows[] = [$target['file'], $target['size'] > 0 ? human_bytes($target['size']) : 'unknown', $local === null ? 'new' : "replaces {$local['date']}"];
    }
    if ($rows !== []) {
        table(['File', 'Size', 'Action'], $rows);
    }
    if ($updateOnly && $unresolved !== []) {
        out(plural(count($unresolved), 'installed title is', 'installed titles are') . ' not in the Kiwix catalogue and ' . (count($unresolved) === 1 ? 'was' : 'were') . ' left alone.');
        $unresolved = [];
    }
    foreach ($unresolved as $base) {
        warn("\"$base\" was not found in the Kiwix catalogue (or the catalogue is unreachable). Try: offline search " . explode('_', $base)[0]);
    }
    if ($plan === []) {
        out($updateOnly ? 'Everything installed is the latest edition.' : 'Nothing to download.');
        return $unresolved === [] ? 0 : 1;
    }
    $free = free_bytes();
    $reserve = config_int('DOWNLOAD_MIN_FREE_GB') * 1024 ** 3;
    out();
    out('Total to download: ' . human_bytes($bytes) . '. Free space: ' . human_bytes($free) . ' (keeping ' . human_bytes($reserve) . ' in reserve).');
    if ($bytes + $reserve > $free) {
        fail('Not enough free space for this selection. Choose fewer items or free some space first.');
    }
    if ($dryRun) {
        out('Dry run: nothing was downloaded.');
        return 0;
    }
    if (!have('aria2c')) {
        fail('aria2c is not installed. Run the installer, or: sudo apt install aria2');
    }
    if (!confirm('Download now?', $yes)) {
        out('Cancelled. Add --yes to skip this question.');
        return 1;
    }
    $lock = lock('content');
    if ($lock === null) {
        fail('Another download or sync is already running. Try again when it has finished.');
    }
    $done = 0;
    foreach ($plan as $target) {
        out();
        out(paint("Fetching {$target['file']}", 'bold'));
        if (download_zim($target)) {
            $done++;
            out(paint('✔', 'green') . " {$target['file']} verified and installed.");
            rebuild_library(false);
        }
    }
    if (config_bool('AUTO_PRUNE')) {
        prune(false, true);
    }
    rebuild_library(true);
    command_status(['--write']);
    out();
    out("$done of " . plural(count($plan), 'download') . ' completed.');
    return $done === count($plan) && $unresolved === [] ? 0 : 1;
}

/** Older editions of titles for which a newer edition is present. */
function superseded_zims(): array
{
    $newest = [];
    $old = [];
    foreach (list_zims() as $zim) {
        if ($zim['date'] === '') {
            continue;
        }
        if (!isset($newest[$zim['base']])) {
            $newest[$zim['base']] = $zim['date'];
        } elseif ($zim['date'] < $newest[$zim['base']]) {
            $old[] = $zim;
        }
    }
    return $old;
}

function prune(bool $dryRun, bool $quiet): int
{
    $removed = 0;
    foreach (superseded_zims() as $zim) {
        if ($dryRun) {
            out("  would remove {$zim['file']} (" . human_bytes($zim['size']) . ')');
            continue;
        }
        if (@unlink(path('zim') . '/' . $zim['file'])) {
            $removed++;
            out("  removed {$zim['file']} (" . human_bytes($zim['size']) . ')');
        } else {
            warn("could not remove {$zim['file']}");
        }
    }
    // Abandoned partial downloads older than a fortnight.
    foreach (@scandir(path('incoming')) ?: [] as $entry) {
        $full = path('incoming') . '/' . $entry;
        if ($entry[0] !== '.' && is_file($full) && time() - (int) filemtime($full) > 14 * 86400) {
            $dryRun ? out("  would remove stale partial download $entry") : @unlink($full);
        }
    }
    if (!$quiet && $removed === 0 && !$dryRun) {
        out('No superseded editions to remove.');
    }
    return $removed;
}

function command_prune(array $args): int
{
    become_service_user('prune');
    $dryRun = in_array('--dry-run', $args, true);
    if (prune($dryRun, false) > 0) {
        rebuild_library(true);
        command_status(['--write']);
    }
    return 0;
}

/** Rebuild the reader's library index from the ZIM files actually present. */
function rebuild_library(bool $verbose): bool
{
    $lock = lock('library', true);
    $target = path('library');
    $tmp = $target . '.new.' . getmypid();
    @unlink($tmp);
    $added = 0;
    $failed = 0;
    if (have('kiwix-manage')) {
        foreach (list_zims() as $zim) {
            $file = path('zim') . '/' . $zim['file'];
            if (!valid_zim_file($file)) {
                $failed++;
                warn("{$zim['file']} is not a valid ZIM file; left out of the reader.");
                continue;
            }
            [$code, , $error] = run(['kiwix-manage', $tmp, 'add', $file], 300);
            if ($code === 0) {
                $added++;
            } else {
                $failed++;
                warn("kiwix-manage could not read {$zim['file']}: " . printable(trim($error)));
            }
        }
    } elseif ($verbose) {
        warn('kiwix-manage is not installed, so the reader index cannot be built. Install the kiwix-tools package.');
    }
    if (!is_file($tmp)) {
        file_put_contents($tmp, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<library version=\"20110515\"></library>\n");
    }
    @chmod($tmp, 0644);
    $ok = @rename($tmp, $target);
    // The children's shelf is a second index holding only the titles chosen for them; its reader serves nothing else.
    $shelf = path('library_children') . '.new.' . getmypid();
    @unlink($shelf);
    $children = 0;
    if (have('kiwix-manage')) {
        foreach (list_zims() as $zim) {
            $file = path('zim') . '/' . $zim['file'];
            if (is_children_title($zim['base']) && valid_zim_file($file) && run(['kiwix-manage', $shelf, 'add', $file], 300)[0] === 0) {
                $children++;
            }
        }
    }
    if (!is_file($shelf)) {
        file_put_contents($shelf, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<library version=\"20110515\"></library>\n");
    }
    @chmod($shelf, 0644);
    $ok = @rename($shelf, path('library_children')) && $ok;
    if ($lock !== null) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    if ($verbose) {
        out('Reader index rebuilt: ' . plural($added, 'title') . ($failed > 0 ? ", $failed skipped" : '') . ', of which ' . $children . ' on the children\'s shelf.');
    }
    return $ok;
}

function command_library(array $args): int
{
    become_service_user('library');
    $ok = rebuild_library(true);
    command_status(['--write']);
    return $ok ? 0 : 1;
}

function command_verify(array $args): int
{
    become_service_user('verify');
    $wanted = array_values(array_filter($args, static fn (string $a): bool => $a[0] !== '-'));
    $bad = 0;
    $checked = 0;
    foreach (list_zims() as $zim) {
        if ($wanted !== [] && !in_array($zim['base'], $wanted, true) && !in_array($zim['file'], $wanted, true)) {
            continue;
        }
        $checked++;
        $live = $zim['date'] !== '' ? resolve_zim($zim['base']) : null;
        if ($live === null) {
            out('  ' . state_mark('warn') . " {$zim['file']}: not in the Kiwix catalogue, cannot be checked");
            continue;
        }
        $sumUrl = dirname($live['url']) . '/' . rawurlencode($zim['file']) . '.sha256';
        $published = https_get($sumUrl, 30);
        if ($published === null || preg_match('/\b([0-9a-f]{64})\b/i', $published, $m) !== 1) {
            out('  ' . state_mark('warn') . " {$zim['file']}: Kiwix no longer publishes a checksum for this edition");
            continue;
        }
        out("  hashing {$zim['file']} (" . human_bytes($zim['size']) . ') ...');
        $actual = hash_file('sha256', path('zim') . '/' . $zim['file']);
        if ($actual !== false && hash_equals(strtolower($m[1]), $actual)) {
            out('  ' . state_mark('ok') . " {$zim['file']}: matches the checksum published by Kiwix");
        } else {
            $bad++;
            out('  ' . state_mark('fail') . " {$zim['file']}: DOES NOT MATCH the published checksum. Delete it and download it again.");
        }
    }
    if ($checked === 0) {
        out('No matching ZIM files.');
    }
    return $bad === 0 ? 0 : 1;
}

// ---------------------------------------------------------------------------
// Peers
// ---------------------------------------------------------------------------

/** Check many addresses for an open TCP port at once. */
function scan_hosts(array $ips, int $port, float $timeout = 0.5, int $batch = 128): array
{
    $found = [];
    foreach (array_chunk($ips, $batch) as $chunk) {
        $sockets = [];
        foreach ($chunk as $ip) {
            $socket = @stream_socket_client("tcp://$ip:$port", $errno, $error, $timeout, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
            if ($socket !== false) {
                stream_set_blocking($socket, false);
                $sockets[$ip] = $socket;
            }
        }
        $deadline = microtime(true) + $timeout;
        while ($sockets !== [] && ($left = $deadline - microtime(true)) > 0) {
            $read = null;
            $write = array_values($sockets);
            $except = array_values($sockets);
            if (@stream_select($read, $write, $except, 0, (int) ($left * 1000000)) === false) {
                break;
            }
            foreach (array_merge($write, $except ?? []) as $socket) {
                $ip = array_search($socket, $sockets, true);
                if ($ip === false) {
                    continue;
                }
                if (@stream_socket_get_name($socket, true) !== false) {
                    $found[] = $ip;
                }
                fclose($socket);
                unset($sockets[$ip]);
            }
        }
        foreach ($sockets as $socket) {
            fclose($socket);
        }
    }
    return $found;
}

/** Find other Offline machines on the local network: address => details. */
function peer_key(string $address, int $port): string
{
    return $port === TLS_PORT ? $address : "$address:$port";
}

/**
 * The systems to pull from: the configured central servers first, then other
 * machines found on the local network. Keyed by address.
 */
function discover_peers(bool $verbose): array
{
    $peers = [];
    foreach (servers() as $server) {
        $peers[peer_key($server['host'], $server['port'])] = ['address' => $server['host'], 'port' => $server['port'], 'name' => $server['host'], 'method' => 'server'];
    }
    // Servers on the household's private network are pulled from wherever this system happens to be, once its tunnel is up.
    if (net_active()) {
        $identity = net_identity();
        foreach (net_nodes() as $key => $node) {
            if (!$node['removed'] && $node['role'] === 'server' && ($identity === null || $key !== $identity['public'])) {
                $peers[peer_key($node['address'], TLS_PORT)] = ['address' => $node['address'], 'port' => TLS_PORT, 'name' => $node['name'], 'method' => 'private network'];
            }
        }
    }
    if (prefix() !== '') {
        foreach (array_filter(explode(',', (string) getenv('OFFLINE_TEST_PEERS'))) as $spec) {
            [$ip, $port] = explode(':', $spec) + [1 => (string) TLS_PORT];
            $peers[peer_key($ip, (int) $port)] = ['address' => $ip, 'port' => (int) $port, 'name' => 'test-peer', 'method' => 'test'];
        }
        return $peers;
    }
    // A server on the internet speaks only to the servers it has been given;
    // it does not go looking through a datacentre's network.
    if ((string) config('SYNC_EXPOSE') === 'internet') {
        return $peers;
    }
    $own = array_column(own_addresses(), 'ip');
    $local = [];
    if (have('avahi-browse')) {
        [, $output] = run(['avahi-browse', '--resolve', '--parsable', '--terminate', '--no-db-lookup', MDNS_TYPE], 15);
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $fields = explode(';', $line);
            if (count($fields) < 9 || $fields[0] !== '=' || $fields[2] !== 'IPv4') {
                continue;
            }
            [$ip, $port] = [$fields[7], (int) $fields[8]];
            if ($port !== TLS_PORT || !is_private_ipv4($ip) || in_array($ip, $own, true)) {
                continue;
            }
            $name = preg_replace_callback('/\\\\(\d{3})/', static fn (array $m): string => chr((int) $m[1] & 0xff), $fields[3]) ?? '';
            $local[$ip] = ['address' => $ip, 'port' => $port, 'name' => printable($name, 60), 'method' => 'mdns'];
        }
    }
    if ($local === [] && config_bool('SYNC_SCAN_FALLBACK')) {
        $candidates = [];
        foreach (own_addresses() as $address) {
            if ($address['prefix'] < 22 || $address['prefix'] > 30 || !is_private_ipv4($address['ip'])) {
                continue;
            }
            $mask = -1 << (32 - $address['prefix']);
            $network = ip2long($address['ip']) & $mask;
            $broadcast = $network | (~$mask & 0xffffffff);
            for ($host = $network + 1; $host < $broadcast; $host++) {
                $ip = long2ip($host);
                if (!in_array($ip, $own, true)) {
                    $candidates[$ip] = true;
                }
            }
        }
        if ($verbose && $candidates !== []) {
            out('No machine announced itself; checking ' . count($candidates) . ' local addresses directly ...');
        }
        foreach (scan_hosts(array_keys($candidates), TLS_PORT) as $ip) {
            $local[$ip] = ['address' => $ip, 'port' => TLS_PORT, 'name' => '', 'method' => 'scan'];
        }
    }
    // A server that is also on the local network is listed once, as the server.
    $serverAddresses = [];
    foreach (servers() as $server) {
        foreach (@gethostbynamel($server['host']) ?: [] as $resolved) {
            $serverAddresses[$resolved] = true;
        }
    }
    foreach ($local as $ip => $peer) {
        if (!isset($serverAddresses[$ip])) {
            $peers[$ip] = $peer;
        }
    }
    return $peers;
}

function rsync_url(array $peer, string $module): string
{
    // The address is only a label here: the connection itself is made by the TLS helper below.
    return 'rsync://' . SERVICE_USER . '@' . $peer['address'] . '/' . $module . '/';
}

/**
 * Keys derived from the house key, one per purpose, so that no two uses
 * share key material. Returns 64 hexadecimal characters, or null with no key.
 */
function derived_key(string $purpose): ?string
{
    $key = trim((string) @file_get_contents(path('key')));
    return $key === '' ? null : hash_hmac('sha256', $purpose, $key);
}

/**
 * Every connection to another system is TLS 1.3 with a pre-shared key derived
 * from the house key: both ends prove they hold it, a fresh X25519 exchange
 * gives each connection its own keys, and everything that follows is encrypted. rsync is told to reach the far daemon through
 * stunnel, which reads the key from a file so it never appears in a process
 * list. Returns the environment for run() and the config file to remove.
 */
function tls_transport(array $peer, int $connectTimeout = 8): array
{
    static $counter = 0;
    $conf = path('state') . '/tls-' . getmypid() . '-' . (++$counter) . '.conf';
    $previous = umask(0077);
    file_put_contents($conf, implode("\n", [
        '; Written by "offline" for one connection; removed afterwards.',
        'foreground = yes',
        'pid =',
        'debug = 3',
        'client = yes',
        'connect = ' . $peer['address'] . ':' . $peer['port'],
        'ciphers = PSK',
        'ciphersuites = TLS_CHACHA20_POLY1305_SHA256:TLS_AES_128_GCM_SHA256',
        'sslVersionMin = TLSv1.3',
        'PSKsecrets = ' . path('psk'),
        'TIMEOUTconnect = ' . max(1, min(60, $connectTimeout)),
        'TIMEOUTbusy = 60',
        'TIMEOUTidle = 600',
        '',
    ]));
    umask($previous);
    return [['RSYNC_CONNECT_PROG' => 'stunnel ' . $conf], $conf];
}

/** Turn a failed connection's error text into a state and a sentence. */
function classify_transport_error(string $error): array
{
    if (preg_match('/auth failed|SSL_connect|SSL_accept|alert|binder|decrypt/i', $error) === 1) {
        return ['rejected', 'it holds a different house key'];
    }
    if (preg_match('/refused|timed out|No more addresses|unreachable|resolve|did not see server greeting/i', $error) === 1) {
        return ['error', 'it could not be reached'];
    }
    return ['error', printable(trim($error), 160)];
}

/**
 * List the files a peer offers in one module.
 * Returns ['ok' => bool, 'auth' => ok|rejected|error, 'error' => string, 'files' => [...]].
 */
function remote_listing(array $peer, string $module): array
{
    [$env, $conf] = tls_transport($peer);
    [$code, $output, $error] = run([
        'rsync', '--list-only', '--recursive', '--no-motd', '--no-human-readable', '--8-bit-output',
        '--timeout=60', '--password-file=' . path('key'), rsync_url($peer, $module),
    ], 180, $env);
    @unlink($conf);
    if ($code !== 0) {
        [$state, $sentence] = classify_transport_error($error);
        // A module the far side does not offer is not a failure of the link.
        if (stripos($error, 'Unknown module') !== false) {
            [$state, $sentence] = ['error', "it does not offer \"$module\""];
        }
        return ['ok' => false, 'auth' => $state, 'error' => $sentence, 'files' => []];
    }
    $files = [];
    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        if (preg_match('/^-\S{9,10}\s+([\d,.]+)\s+(\d{4}\/\d\d\/\d\d \d\d:\d\d:\d\d)\s+(.+)$/', $line, $m) !== 1) {
            continue;
        }
        $mtime = \DateTimeImmutable::createFromFormat('Y/m/d H:i:s', $m[2], new \DateTimeZone('UTC'));
        $files[] = ['path' => $m[3], 'size' => (int) preg_replace('/\D/', '', $m[1]), 'mtime' => $mtime === false ? 0 : $mtime->getTimestamp()];
    }
    return ['ok' => true, 'auth' => 'ok', 'error' => '', 'files' => $files];
}

/**
 * Decide which of a peer's files are worth pulling. Nothing is accepted unless
 * its path is safe, its type is on the allow list, it is not an older edition
 * of a ZIM we already hold, and there is room for it above the free-space floor.
 */
function plan_pull(string $module, array $remote, int &$budget, array &$counts): array
{
    $root = path($module);
    $allowed = SYNC_EXTENSIONS[$module] ?? [];
    usort($remote, static fn (array $a, array $b): int => $a['size'] <=> $b['size']);
    $localEditions = [];
    foreach (list_zims() as $zim) {
        $localEditions[$zim['base']] = max($localEditions[$zim['base']] ?? '', $zim['date']);
    }
    $wanted = [];
    $incomingEditions = [];
    $rejected = read_json(path('state') . '/rejected.json');
    foreach ($remote as $file) {
        // The vault holds ciphertext whose names say nothing; they are checked for shape, not type.
        if ($module === 'vault' ? !vault_path_ok($file['path']) : (!safe_relative_path($file['path']) || !in_array(extension($file['path']), $allowed, true))) {
            $counts['filtered']++;
            continue;
        }
        if (($rejected[$module . '/' . $file['path']] ?? -1) === $file['size']) {
            $counts['rejected']++;
            continue;
        }
        $local = $root . '/' . $file['path'];
        // Content is skipped once a file of the same size is held. An encrypted document can
        // change without changing size, so in the vault only an older copy is replaced.
        if (is_file($local) && (($module !== 'vault' && (int) filesize($local) === $file['size']) || (int) filemtime($local) >= $file['mtime'])) {
            continue;
        }
        $parsed = extension($file['path']) === 'zim' ? parse_zim_name(basename($file['path'])) : null;
        if ($parsed !== null) {
            if (($localEditions[$parsed['base']] ?? '') > $parsed['date'] || ($incomingEditions[$parsed['base']] ?? '') > $parsed['date']) {
                $counts['superseded']++;
                continue;
            }
            $incomingEditions[$parsed['base']] = $parsed['date'];
        }
        if ($file['size'] > $budget) {
            $counts['no_space']++;
            continue;
        }
        $budget -= $file['size'];
        $wanted[] = $file;
    }
    // Where a peer offers two editions of one title, keep only the newest.
    return array_values(array_filter($wanted, static function (array $file) use ($incomingEditions): bool {
        $parsed = extension($file['path']) === 'zim' ? parse_zim_name(basename($file['path'])) : null;
        return $parsed === null || $incomingEditions[$parsed['base']] === $parsed['date'];
    }));
}

/** Pull the chosen files from one module of one peer. Returns [files, bytes, error]. */
function pull_files(array $peer, string $module, array $files, bool $showProgress): array
{
    $list = path('state') . '/pull-' . getmypid() . '.list';
    $log = path('state') . '/pull-' . getmypid() . '.log';
    file_put_contents($list, implode("\0", array_column($files, 'path')) . "\0");
    @unlink($log);
    $command = [
        'rsync', '--times', '--update', '--from0', '--files-from=' . $list, '--partial-dir=.rsync-partial',
        '--no-motd', $module === 'vault' ? '--chmod=D2770,F660' : '--chmod=D2775,F664', '--timeout=300',
        '--password-file=' . path('key'), '--log-file=' . $log, '--log-file-format=%n|%l',
    ];
    [$env, $conf] = tls_transport($peer);
    $limit = config_int('SYNC_BWLIMIT_KBPS');
    if ($limit > 0) {
        $command[] = '--bwlimit=' . $limit;
    }
    if ($showProgress) {
        $command[] = '--info=progress2';
    }
    $command[] = rsync_url($peer, $module);
    $command[] = path($module) . '/';
    if ($showProgress) {
        $code = run_live($command, $env);
        $error = '';
    } else {
        [$code, , $error] = run($command, 0, $env);
    }
    @unlink($conf);
    $count = 0;
    $bytes = 0;
    $received = [];
    foreach (@file($log, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\S+ \S+ \[\d+\] (.+)\|(\d+)$/', $line, $m) === 1 && !str_ends_with($m[1], '/')) {
            $count++;
            $bytes += (int) $m[2];
            $received[] = $m[1];
        }
    }
    @unlink($list);
    @unlink($log);
    // A file that claims to be a ZIM but is not one never reaches the reader.
    foreach ($received as $relative) {
        $full = path($module) . '/' . $relative;
        if (extension($relative) === 'zim' && is_file($full) && !valid_zim_file($full)) {
            $rejected = read_json(path('state') . '/rejected.json');
            $rejected[$module . '/' . $relative] = (int) filesize($full);
            write_json(path('state') . '/rejected.json', array_slice($rejected, -500, null, true));
            $bytes -= (int) filesize($full);
            @unlink($full);
            $count--;
            warn(printable($relative) . ' from ' . $peer['address'] . ' is not a valid ZIM file and was discarded.');
        }
    }
    $ok = in_array($code, [0, 23, 24], true);
    return [max(0, $count), max(0, $bytes), $ok ? '' : printable(trim($error) !== '' ? trim($error) : "rsync exit $code", 160)];
}

function merge_peers(array $state, array $found, ?array $lastRun = null, array $results = []): array
{
    $now = utc_now();
    $peers = $state['peers'] ?? [];
    foreach ($found as $ip => $peer) {
        $entry = $peers[$ip] ?? ['address' => $ip, 'first_seen' => $now, 'auth' => 'unknown', 'total_files' => 0, 'total_bytes' => 0];
        $entry['name'] = $peer['name'] !== '' ? $peer['name'] : ($entry['name'] ?? '');
        $entry['method'] = $peer['method'];
        $entry['last_seen'] = $now;
        if (isset($results[$ip])) {
            $result = $results[$ip];
            $entry['auth'] = $result['auth'];
            $entry['last_error'] = $result['error'];
            $entry['offers'] = $result['offers'];
            $entry['last_files'] = $result['files'];
            $entry['last_bytes'] = $result['bytes'];
            $entry['total_files'] += $result['files'];
            $entry['total_bytes'] += $result['bytes'];
            if ($result['auth'] === 'ok') {
                $entry['last_sync'] = $now;
            }
        }
        $peers[$ip] = $entry;
    }
    // Forget machines not seen for thirty days.
    $peers = array_filter($peers, static fn (array $p): bool => strtotime((string) ($p['last_seen'] ?? '')) > time() - 30 * 86400);
    return ['updated' => $now, 'peers' => $peers, 'last_run' => $lastRun ?? ($state['last_run'] ?? null)];
}

function remember_peers(array $found, ?array $lastRun = null, array $results = []): array
{
    $state = merge_peers(read_json(path('peers')), $found, $lastRun, $results);
    write_json(path('peers'), $state);
    return $state;
}

function command_peers(array $args): int
{
    $found = discover_peers(true);
    // Only the service account keeps the record; anyone else just gets the view.
    $state = is_writable(path('state')) ? remember_peers($found) : merge_peers(read_json(path('peers')), $found);
    if (($state['peers'] ?? []) === []) {
        out('No other Offline machine has been seen on this network.');
        out('Each system must be on the same network and hold the same house key (sudo offline key show).');
        return 0;
    }
    $rows = [];
    foreach ($state['peers'] as $ip => $peer) {
        $rows[] = [
            $ip, $peer['name'] ?? '', isset($found[$ip]) ? 'yes' : 'no', $peer['auth'] ?? 'unknown',
            utc_display($peer['last_sync'] ?? null), (string) ($peer['total_files'] ?? 0), human_bytes((int) ($peer['total_bytes'] ?? 0)),
        ];
    }
    table(['Address', 'Name', 'Here now', 'Access', 'Last sync', 'Files received', 'Received'], $rows);
    return 0;
}

function command_sync(array $args): int
{
    $dryRun = in_array('--dry-run', $args, true);
    $force = in_array('--force', $args, true);
    become_service_user('sync');
    $interactive = \function_exists('posix_isatty') && @posix_isatty(STDOUT);
    $started = utc_now();
    $finish = static function (string $result, string $message, array $found = [], array $results = [], int $files = 0, int $bytes = 0) use ($started, $dryRun): int {
        out($message);
        if (!$dryRun) {
            remember_peers($found, ['started' => $started, 'finished' => utc_now(), 'result' => $result, 'message' => $message, 'files' => $files, 'bytes' => $bytes], $results);
            command_status(['--write']);
        }
        return $result === 'error' ? 1 : 0;
    };

    if (!config_bool('SYNC_ENABLED') && !$force) {
        return $finish('skipped', 'Peer sync is switched off (SYNC_ENABLED=no).');
    }
    if (!is_readable(path('key')) || (int) filesize(path('key')) === 0) {
        return $finish('error', 'No house key is set, so no peer can be trusted. Run: sudo offline key new');
    }
    $power = power_state();
    $minimum = config_int('SYNC_MIN_BATTERY', 0, 100);
    if (!$force && $power['on_ac'] === false && $power['battery'] !== null && $power['battery'] < $minimum) {
        return $finish('skipped', "On battery at {$power['battery']} %, below the {$minimum} % threshold; sync postponed to save power.");
    }
    if (!have('rsync')) {
        return $finish('error', 'rsync is not installed.');
    }
    $lock = lock('content');
    if ($lock === null) {
        return $finish('skipped', 'A download or another sync is already running; skipped.');
    }

    $found = discover_peers($interactive);
    if ($found === []) {
        return $finish('ok', 'No other system found on the local network.');
    }
    $modules = array_values(array_intersect(preg_split('/[\s,]+/', (string) config('SYNC_MODULES')) ?: [], array_keys(SYNC_EXTENSIONS)));
    // The family vault travels as ciphertext, to every system that keeps a folder for it.
    if (config_bool('VAULT_SYNC') && is_dir(path('vault'))) {
        $modules[] = 'vault';
    }
    $budget = max(0, free_bytes() - config_int('SYNC_MIN_FREE_GB') * 1024 ** 3);
    $results = [];
    $totalFiles = 0;
    $totalBytes = 0;
    $counts = ['filtered' => 0, 'superseded' => 0, 'no_space' => 0, 'rejected' => 0];
    $gotZim = false;

    foreach ($found as $ip => $peer) {
        $result = ['auth' => 'error', 'error' => '', 'files' => 0, 'bytes' => 0, 'offers' => []];
        if ($modules === []) {
            // A system that holds no content, a repeater for one, still exchanges accounts and the list of systems.
            $probe = remote_listing($peer, 'media');
            $result['auth'] = $probe['ok'] ? 'ok' : $probe['auth'];
            $result['error'] = $probe['ok'] ? '' : $probe['error'];
        }
        foreach ($modules as $module) {
            $listing = remote_listing($peer, $module);
            if (!$listing['ok']) {
                if ($listing['auth'] === 'rejected') {
                    $result['auth'] = 'rejected';
                    $result['error'] = $listing['error'];
                    out("  $ip: {$listing['error']}");
                    break;
                }
                // A system on an older version may not offer every module; carry on with the rest.
                $result['error'] = "$module: {$listing['error']}";
                continue;
            }
            $result['auth'] = 'ok';
            $result['offers'][$module] = ['files' => count($listing['files']), 'bytes' => array_sum(array_column($listing['files'], 'size'))];
            $wanted = plan_pull($module, $listing['files'], $budget, $counts);
            if ($wanted === []) {
                continue;
            }
            $size = array_sum(array_column($wanted, 'size'));
            out("  $ip/$module: " . plural(count($wanted), 'file') . ' to fetch, ' . human_bytes($size));
            if ($dryRun) {
                foreach ($wanted as $file) {
                    out('    ' . printable($file['path']) . ' (' . human_bytes($file['size']) . ')');
                }
                continue;
            }
            [$files, $bytes, $error] = pull_files($peer, $module, $wanted, $interactive);
            $result['files'] += $files;
            $result['bytes'] += $bytes;
            if ($error !== '') {
                $result['error'] = $error;
                out("  $ip/$module: $error");
            }
            $gotZim = $gotZim || ($module === 'knowledge' && $files > 0);
        }
        if ($result['auth'] === 'ok' && !$dryRun) {
            $changed = accounts_pull($peer);
            if ($changed !== []) {
                out("  $ip/accounts: " . plural(count($changed), 'account') . ' updated (' . implode(', ', $changed) . ')');
                $accountsChanged = true;
            }
            $systems = net_pull($peer);
            if ($systems !== []) {
                out("  $ip/network: " . plural(count($systems), 'system') . ' updated (' . implode(', ', array_unique($systems)) . ')');
            }
        }
        if ($result['auth'] === 'ok' && $result['offers'] !== [] && count($result['offers']) === count($modules)) {
            $result['error'] = '';
        } elseif ($result['auth'] === 'error') {
            out("  $ip: " . ($result['error'] !== '' ? $result['error'] : 'could not be reached'));
        }
        $results[$ip] = $result;
        $totalFiles += $result['files'];
        $totalBytes += $result['bytes'];
    }

    if (!$dryRun && $gotZim) {
        if (config_bool('AUTO_PRUNE')) {
            prune(false, true);
        }
        rebuild_library(false);
    }
    $trusted = count(array_filter($results, static fn (array $r): bool => $r['auth'] === 'ok'));
    $message = plural(count($found), 'system') . ' found, ' . $trusted . ' trusted; '
        . ($dryRun ? 'dry run, nothing fetched' : ($totalFiles === 0 ? 'nothing new to fetch' : plural($totalFiles, 'file') . ' received (' . human_bytes($totalBytes) . ')'));
    if ($accountsChanged ?? false) {
        $message .= '; accounts updated';
    }
    if ($counts['no_space'] > 0) {
        $message .= '; ' . plural($counts['no_space'], 'file') . ' left behind to protect free space';
    }
    if ($counts['filtered'] > 0) {
        $message .= '; ' . plural($counts['filtered'], 'file') . ' ignored as not an accepted type';
    }
    return $finish('ok', $message, $found, $results, $totalFiles, $totalBytes);
}

/**
 * Fetch another system's account records and keep whatever is newer. Returns
 * the names that changed. The file is small, is fetched to one side, and every
 * record in it is checked before it is believed.
 */
function accounts_pull(array $peer): array
{
    $tmp = path('state') . '/accounts-' . getmypid() . '.json';
    @unlink($tmp);
    [$env, $conf] = tls_transport($peer);
    [$code] = run([
        'rsync', '--no-motd', '--timeout=60', '--max-size=1m', '--chmod=F600', '--password-file=' . path('key'),
        rsync_url($peer, 'accounts') . 'accounts.json', $tmp,
    ], 90, $env);
    @unlink($conf);
    $theirs = $code === 0 && is_file($tmp) && !is_link($tmp) ? accounts_load($tmp) : [];
    @unlink($tmp);
    if ($theirs === []) {
        return [];
    }
    $lock = lock('accounts', true);
    [$merged, $changed] = accounts_merge(accounts_load(), $theirs);
    if ($changed !== []) {
        accounts_dir_ready();
        if (!accounts_save($merged)) {
            $changed = [];
        }
    }
    if ($lock !== null) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $changed;
}

/** For the status file: how many accounts there are, and whether children on this system are guarded. */
function people_status(): array
{
    $accounts = is_readable(path('accounts')) ? array_filter(accounts_load(), static fn (array $r): bool => !$r['removed']) : [];
    $children = array_keys(array_filter($accounts, static fn (array $r): bool => $r['class'] === 'child'));
    return [
        'adults' => count($accounts) - count($children),
        'children' => count($children),
        'children_here' => count(array_filter($children, static fn (string $name): bool => login_account($name) !== null)),
        'sign_in' => is_dir(dirname(path('sessions'))),
    ];
}

// ---------------------------------------------------------------------------
// House key and power
// ---------------------------------------------------------------------------

function normalise_key(string $key): ?string
{
    $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $key) ?? '');
    if (preg_match('/^[0-9A-HJKMNP-TV-Z]{25}$/', $key) !== 1) {
        return null;
    }
    return implode('-', str_split($key, 5));
}

function new_key(): string
{
    $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $key = '';
    for ($i = 0; $i < 25; $i++) {
        $key .= $alphabet[random_int(0, 31)];
    }
    return implode('-', str_split($key, 5));
}

function store_key(string $key): void
{
    $account = prefix() === '' ? posix_getpwnam(SERVICE_USER) : false;
    // house.key and rsyncd.secrets: root's, readable by the service group.
    // sync.psk: the TLS key derived from the house key, for the service account alone.
    $files = [
        path('key') => [$key . "\n", 0640, 0],
        path('secrets') => [SERVICE_USER . ':' . $key . "\n", 0640, 0],
        path('psk') => [SERVICE_USER . ':' . hash_hmac('sha256', 'offline-sync-v1', $key) . "\n", 0600, $account['uid'] ?? 0],
    ];
    foreach ($files as $file => [$content, $mode, $owner]) {
        $tmp = $file . '.tmp.' . getmypid();
        $previous = umask(0077);
        $written = file_put_contents($tmp, $content);
        umask($previous);
        if ($written === false) {
            fail("Could not write $file");
        }
        if ($account !== false) {
            chown($tmp, $owner);
            chgrp($tmp, $account['gid']);
        }
        chmod($tmp, $mode);
        if (!rename($tmp, $file)) {
            fail("Could not replace $file");
        }
    }
    // The household's certificate authority follows the house key, so this system's certificate is signed afresh.
    if (have('openssl') && is_dir(dirname(path('tls')))) {
        ensure_tls_certificate();
    }
}

function command_key(array $args): int
{
    $action = $args[0] ?? 'show';
    if (prefix() === '' && !is_root()) {
        fail('The house key is only visible to root. Run: sudo offline key ' . $action);
    }
    switch ($action) {
        case 'show':
            $key = trim((string) @file_get_contents(path('key')));
            if ($key === '') {
                fail('No house key is set. Create one with: sudo offline key new');
            }
            out($key);
            out();
            out('Enter the same key on every other system with: sudo offline key set ' . $key);
            return 0;
        case 'new':
            if (is_file(path('key')) && (int) filesize(path('key')) > 0 && !in_array('--force', $args, true)) {
                fail('A house key already exists. Replacing it cuts this system off from the others until they are given the new key. Repeat with --force if that is intended.');
            }
            $key = new_key();
            store_key($key);
            out($key);
            out();
            out('Stored. Enter the same key on every other system with: sudo offline key set ' . $key);
            return 0;
        case 'set':
            $given = (string) ($args[1] ?? '');
            if ($given === '' || $given === '-') {
                // Typed or piped in, so the key stays out of the process list and shell history.
                if ($given === '' && \function_exists('posix_isatty') && @posix_isatty(STDIN)) {
                    fwrite(STDOUT, 'House key: ');
                }
                $given = trim((string) fgets(STDIN));
            }
            $key = normalise_key($given);
            if ($key === null) {
                fail('That is not a valid house key. A key is 25 letters and digits, shown as five groups of five.');
            }
            store_key($key);
            out('House key stored. This system will now exchange content with others holding the same key.');
            return 0;
        case 'refresh':
            // Rewrite the files derived from the existing key, after an upgrade.
            $key = normalise_key(trim((string) @file_get_contents(path('key'))));
            if ($key === null) {
                fail('No valid house key is stored. Create one with: sudo offline key new');
            }
            store_key($key);
            out('Key files refreshed.');
            return 0;
        default:
            fail('Usage: sudo offline key [show|new|set [KEY]]');
    }
}

function command_power(array $args): int
{
    $profile = $args[0] ?? null;
    if ($profile !== null) {
        $profile = ['saver' => 'power-saver', 'save' => 'power-saver'][$profile] ?? $profile;
        if (!in_array($profile, ['power-saver', 'balanced', 'performance'], true)) {
            fail('Usage: offline power [saver|balanced|performance]');
        }
        [$code, , $error] = run(['powerprofilesctl', 'set', $profile], 10);
        if ($code !== 0) {
            fail('Could not change the power profile: ' . printable(trim($error)));
        }
    }
    $power = power_state();
    if ($power['battery'] !== null) {
        out("Battery   {$power['battery']} % ({$power['battery_state']})");
        if ($power['seconds_left'] !== null) {
            out('Runtime   about ' . human_duration($power['seconds_left']) . ' at the present draw');
        }
    } else {
        out('Battery   none reported');
    }
    out('Supply    ' . ($power['on_ac'] === false ? 'battery' : 'mains'));
    out('Profile   ' . ($power['profile'] ?? 'unknown (power-profiles-daemon not available)'));
    return 0;
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

function usage(): void
{
    out('offline ' . VERSION . ' - manage this system\'s offline library');
    out();
    out('Information');
    out('  offline status [--json]          what is installed, running and shared');
    out('  offline doctor [--online]        health checks, each with its fix');
    out('  offline peers                    other Offline systems on this network');
    out('  offline power [saver|balanced|performance]');
    out();
    out('Content (run with sudo)');
    out('  offline catalogue [PACK] [--check]   curated packs; --check refreshes sizes from Kiwix');
    out('  offline search TEXT [--lang eng]     search the whole Kiwix library');
    out('  offline get PACK|ZIM|URL ... [--dry-run] [--yes]');
    out('  offline update [--dry-run] [--yes]   fetch newer editions of what is installed');
    out('  offline prune [--dry-run]            remove superseded editions');
    out('  offline verify [ZIM ...]             compare files with checksums published by Kiwix');
    out('  offline library                      rebuild the reader index');
    out();
    out('Maps (run with sudo, except the list)');
    out('  offline maps                         regions installed and on offer');
    out('  offline maps setup                   fetch the viewer\'s fonts, symbols and world outline');
    out('  offline maps get REGION ... [--maxzoom N] [--dry-run] [--yes]');
    out('  offline maps get --bbox W,S,E,N --name NAME');
    out('  offline maps remove REGION');
    out('  offline maps places                  fetch the place-name index for the map page\'s search');
    out('  offline maps find TEXT               look a place, a UK postcode or coordinates up');
    out('  offline maps routing [build REGION ...|on|off]   directions on the map page');
    out();
    out('Applications (run with sudo, except the list)');
    out('  offline apps                         applications on offer and their state');
    out('  offline apps add APP ...|all         install (all = the Project NOMAD set)');
    out('  offline apps start|stop|restart|enable|disable|remove|info APP');
    out('  offline apps pull APP ...|all        fetch images now, to install later without the internet');
    out();
    out('Games (run with sudo, except the list)');
    out('  offline games                        games installed, family worlds and browser activities');
    out('  offline games install [GAME ...]     install the desktop games');
    out('  offline games host [WORLD|all]       host a family Luanti world (creative, survival)');
    out('  offline games start|stop|restart|unhost WORLD');
    out('  offline games activities [remove]    browser activities for tablets (Sugarizer)');
    out();
    out('Radio');
    out('  offline radio                        receivers and mesh nodes plugged in, tools installed');
    out('  offline radio channels               the PMR446 channel table');
    out('  offline radio listen pmr [CHANNEL]   scan PMR446 with the USB receiver; also wfm|nfm|am MHZ');
    out('  offline radio mesh info|nodes|send TEXT|region EU_868');
    out('  sudo offline radio firmware          keep Meshtastic firmware on disc for offline flashing');
    out();
    out('Family broadcast');
    out('  offline broadcast "TEXT" [--urgent]  alert every screen and the Meshtastic mesh');
    out('  offline broadcast                    recent broadcasts and how the last one was delivered');
    out();
    out('Central servers (run with sudo, except the lists)');
    out('  offline server                       the servers this system pulls from');
    out('  offline server add HOST[:PORT] | remove HOST | test');
    out('  offline queue                        downloads waiting, running and done');
    out('  offline queue add URL ... [--to movies|tv|music|radio|books|children] [--audio]');
    out('  offline queue sites                  websites worth archiving, UK ones included');
    out('  offline queue site NAME|GROUP        archive a website as a library title');
    out('  offline queue run | archive | remove ID | retry ID');
    out('  offline stage                        what has been downloaded and awaits release');
    out('  offline stage publish ID|all [--to FOLDER] | discard ID');
    out();
    out('Private network (run with sudo, except the report)');
    out('  offline net                          this system\'s place on the household\'s private network');
    out('  offline net join | endpoint HOST:PORT|none | forget NAME | leave');
    out('  offline net radio on|off             carry family broadcasts over LoRa and the other links');
    out();
    out('People (run with sudo, except the list)');
    out('  offline user                         the household\'s accounts');
    out('  offline user add NAME --adult|--child [--full "Full name"] [--admin] [--portal-only]');
    out('  offline user passwd NAME | lock NAME | unlock NAME | remove NAME | apply');
    out('  offline tls [renew]                  this system\'s certificate and the household\'s certificate authority');
    out();
    out('Family vault (run as yourself)');
    out('  offline vault                        whether the encrypted family folder exists and is open');
    out('  offline vault init | open [FOLDER] | close | passwd');
    out();
    out('Rebuilding and moving by disc (run with sudo, except the report)');
    out('  offline rebuild [status --verify]    the package cache and installer images held');
    out('  offline rebuild cache on|off | warm | offline on|off | image [desktop|live-server] | clients');
    out('  offline export FOLDER [--what knowledge,media,maps,vault]   copy shared content to a disc');
    out('  offline import FOLDER [--dry-run]    bring content in from a disc, through the usual filters');
    out();
    out('Network and clock (run with sudo, except the reports)');
    out('  offline hotspot                      whether this system is acting as a Wi-Fi access point');
    out('  offline hotspot on [--ssid NAME] [--interface IF] | show | off | remove');
    out('  offline time                         the clock, and where it is set from');
    out('  offline time serve on|off | sources  serve time to the home network; re-apply time servers');
    out();
    out('Sharing (run with sudo)');
    out('  offline sync [--dry-run] [--force]   fetch new content from servers and peers now');
    out('  offline key [show|new|set [KEY]|refresh]   the house key every system must share');
}

function main(array $argv): int
{
    $command = $argv[1] ?? 'help';
    $args = array_slice($argv, 2);
    foreach ($args as $arg) {
        if ($arg === '' || strlen($arg) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $arg) === 1) {
            fail('Arguments must be non-empty and free of control characters.');
        }
    }
    // Root is only needed for the house key and for installing applications.
    // Everything else, including read-only reporting, runs as the unprivileged
    // service account.
    $keepsRoot = ['key', 'power', 'vault', 'help', '--help', '-h', 'version', '--version', '-V'];
    // Accounts belong to the system; so does its certificate. The session clean-up runs as the sign-in account.
    if (in_array($command, ['user', 'users', 'tls'], true)) {
        $keepsRoot[] = $command;
    }
    // The tunnel and its keys belong to the system; the report on them does not need root.
    if (in_array($command, ['net', 'network'], true) && ($args[0] ?? 'status') !== 'status') {
        $keepsRoot[] = $command;
    }
    // Managing applications needs root; merely listing them does not.
    if (in_array($command, ['apps', 'games'], true) && ($args[0] ?? 'list') !== 'list') {
        $keepsRoot[] = $command;
    }
    // The hotspot and the time service are system settings; reporting on them is not.
    if (in_array($command, ['hotspot', 'time'], true) && ($args[0] ?? 'status') !== 'status') {
        $keepsRoot[] = $command;
    }
    // The package cache is a system service, and a disc may be readable by root alone.
    if (($command === 'rebuild' && !in_array($args[0] ?? 'status', ['status', 'image'], true)) || in_array($command, ['export', 'import'], true)) {
        $keepsRoot[] = $command;
    }
    // Radio commands talk to USB devices as whoever ran them; only the firmware fetch writes shared content.
    if ($command === 'radio' && ($args[0] ?? '') !== 'firmware') {
        $keepsRoot[] = 'radio';
    }
    // Building a routing graph and switching directions on both start containers.
    if ($command === 'maps' && ($args[0] ?? '') === 'routing' && in_array($args[1] ?? '', ['build', 'on', 'off'], true)) {
        $keepsRoot[] = 'maps';
    }
    // Naming a server edits the settings and the firewall of the sync job; archiving a site runs a container.
    if (($command === 'server' && in_array($args[0] ?? '', ['add', 'remove'], true)) || ($command === 'queue' && ($args[0] ?? '') === 'archive')) {
        $keepsRoot[] = $command;
    }
    // The desktop alert runs as the person logged in, and so does asking for a broadcast.
    if ($command === 'broadcast' && ($args[0] ?? 'list') === 'alert') {
        $keepsRoot[] = 'broadcast';
    }
    if (prefix() === '' && is_root() && !in_array($command, $keepsRoot, true)) {
        become_service_user($command);
    }
    return match ($command) {
        'status' => command_status($args),
        'doctor' => command_doctor($args),
        'catalogue', 'catalog' => command_catalogue($args),
        'search' => command_search($args),
        'get' => command_get($args),
        'update' => command_get($args, true),
        'prune' => command_prune($args),
        'verify' => command_verify($args),
        'library' => command_library($args),
        'peers' => command_peers($args),
        'sync' => command_sync($args),
        'key' => command_key($args),
        'power' => command_power($args),
        'maps' => command_maps($args),
        'apps' => command_apps($args),
        'radio' => command_radio($args),
        'games' => command_games($args),
        'hotspot' => command_hotspot($args),
        'vault' => command_vault($args),
        'user', 'users' => command_user($args),
        'tls' => command_tls($args),
        'net', 'network' => command_net($args),
        'rebuild' => command_rebuild($args),
        'export' => command_export($args),
        'import' => command_import($args),
        'time' => command_time($args),
        'server', 'servers' => command_server($args),
        'queue' => command_queue($args),
        'stage', 'staging' => command_stage($args),
        'broadcast' => command_broadcast($args),
        'version', '--version', '-V' => (static function (): int {
            out(VERSION);
            return 0;
        })(),
        'help', '--help', '-h' => (static function (): int {
            usage();
            return 0;
        })(),
        default => (static function () use ($command): int {
            usage();
            fail('Unknown command: ' . printable($command));
        })(),
    };
}
