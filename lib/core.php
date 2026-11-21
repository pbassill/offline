<?php
/**
 * Offline — shared read-only helpers.
 *
 * Used by both the `offline` command and the web portal. Nothing in this file
 * executes another program, opens a network connection or writes to disc, so
 * the portal can load it with every dangerous PHP function disabled.
 */

declare(strict_types=1);

namespace Offline;

const VERSION = '2.2.0';
/** The sharing daemon itself, on 127.0.0.1 only. */
const SYNC_PORT = 47001;
/** What other systems connect to: the same daemon behind TLS keyed from the house key. */
const TLS_PORT = 47002;
const READER_PORT = 8080;
const SERVICE_USER = 'offline';
const MDNS_TYPE = '_offline-sync._tcp';

/** File types a peer is allowed to send us, per shared module. */
const SYNC_EXTENSIONS = [
    'knowledge' => ['zim', 'pdf', 'epub', 'djvu', 'txt', 'md'],
    'media' => [
        'mp4', 'm4v', 'mkv', 'webm', 'avi', 'mov', 'mpg', 'mpeg', 'ts',
        'mp3', 'flac', 'ogg', 'oga', 'opus', 'm4a', 'm4b', 'wav', 'aac',
        'srt', 'vtt', 'ass', 'sub',
        'jpg', 'jpeg', 'png', 'webp',
        'pdf', 'epub', 'cbz', 'txt', 'nfo',
    ],
    // Map regions, plus the fonts and symbols the map viewer draws with.
    'maps' => ['pmtiles', 'pbf', 'png', 'json'],
];

/** Formats a web browser can play without help. */
const PLAYABLE_VIDEO = ['mp4', 'm4v', 'webm'];
const PLAYABLE_AUDIO = ['mp3', 'flac', 'ogg', 'oga', 'opus', 'm4a', 'wav', 'aac'];

const DEFAULTS = [
    'ROLE' => 'workstation',
    'PORTAL_SCOPE' => 'lan',
    'SERVERS' => '',
    'SYNC_EXPOSE' => 'lan',
    'STAGING_AUTOPUBLISH' => 'no',
    'MESH_GATEWAY' => 'yes',
    'BROADCAST_HOURS' => '12',
    'SYNC_ENABLED' => 'yes',
    'SYNC_MODULES' => 'knowledge media maps',
    'SYNC_MIN_FREE_GB' => '10',
    'SYNC_MIN_BATTERY' => '30',
    'SYNC_BWLIMIT_KBPS' => '0',
    'SYNC_SCAN_FALLBACK' => 'yes',
    'AUTO_PRUNE' => 'yes',
    'DOWNLOAD_CONNECTIONS' => '4',
    'DOWNLOAD_MIN_FREE_GB' => '5',
    'CATALOGUE_URL' => 'https://library.kiwix.org/catalog/v2/entries',
    'OLLAMA_MODELS' => 'qwen2.5:0.5b',
];

/**
 * Optional path prefix. Only the command-line tool and the test suite ever
 * define OFFLINE_PREFIX; the portal never does.
 */
function prefix(): string
{
    return \defined('OFFLINE_PREFIX') ? rtrim((string) \constant('OFFLINE_PREFIX'), '/') : '';
}

function path(string $key): string
{
    static $map = [
        'conf' => '/etc/offline/offline.conf',
        'key' => '/etc/offline/house.key',
        'psk' => '/etc/offline/sync.psk',
        'secrets' => '/etc/offline/rsyncd.secrets',
        'catalogue' => '/usr/local/lib/offline/catalogue.tsv',
        'catalogue_local' => '/etc/offline/catalogue.local.tsv',
        'share' => '/srv/offline',
        'knowledge' => '/srv/offline/knowledge',
        'zim' => '/srv/offline/knowledge/zim',
        'incoming' => '/srv/offline/knowledge/zim/.incoming',
        'media' => '/srv/offline/media',
        'maps' => '/srv/offline/maps',
        'maps_assets' => '/srv/offline/maps/assets',
        'maps_catalogue' => '/usr/local/lib/offline/maps.tsv',
        'maps_catalogue_local' => '/etc/offline/maps.local.tsv',
        'radio' => '/srv/offline/radio',
        'apps' => '/srv/offline/apps',
        'apps_catalogue' => '/usr/local/lib/offline/apps',
        'data' => '/usr/local/lib/offline/data',
        'state' => '/var/lib/offline',
        'broadcasts' => '/var/lib/offline/broadcasts',
        'inbox' => '/var/lib/offline/inbox',
        'outbox' => '/var/lib/offline/outbox',
        'queue' => '/var/lib/offline/queue.json',
        'staging' => '/srv/offline/staging',
        'alerts' => '/run/offline/broadcasts.json',
        'library' => '/var/lib/offline/library.xml',
        'peers' => '/var/lib/offline/peers.json',
        'cache' => '/var/lib/offline/catalogue-cache.json',
        'lock' => '/var/lib/offline/offline.lock',
        'status' => '/run/offline/status.json',
    ];
    if (!isset($map[$key])) {
        throw new \InvalidArgumentException("Unknown path key: $key");
    }
    return prefix() . $map[$key];
}

/** Parse /etc/offline/offline.conf. Plain KEY=value lines; nothing is evaluated. */
function config(?string $key = null): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = DEFAULTS;
        $lines = @file(path('conf'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*([A-Z][A-Z0-9_]{1,40})\s*=\s*(.*?)\s*$/', $line, $m) !== 1) {
                continue;
            }
            $value = $m[2];
            if (preg_match('/^"(.*)"$|^\'(.*)\'$/', $value, $q) === 1) {
                $value = ($q[1] ?? '') !== '' ? $q[1] : ($q[2] ?? '');
            }
            if (array_key_exists($m[1], DEFAULTS)) {
                $cfg[$m[1]] = $value;
            }
        }
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}

/**
 * The central servers this system pulls from, in order of preference:
 * [['host' => 'server.example.org', 'port' => 47002], ...]. Entries that are
 * not a plain host name or IPv4 address are ignored.
 */
function servers(): array
{
    $servers = [];
    foreach (preg_split('/[\s,]+/', (string) config('SERVERS'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
        if (preg_match('/^([A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?)(?::(\d{2,5}))?$/', $entry, $m) !== 1) {
            continue;
        }
        $port = isset($m[3]) ? (int) $m[3] : TLS_PORT;
        if ($port >= 1 && $port <= 65535) {
            $servers[strtolower($m[1])] = ['host' => strtolower($m[1]), 'port' => $port];
        }
    }
    return array_values($servers);
}

function is_server(): bool
{
    return config('ROLE') === 'server';
}

function config_bool(string $key): bool
{
    return in_array(strtolower((string) config($key)), ['yes', 'true', '1', 'on'], true);
}

function config_int(string $key, int $min = 0, int $max = PHP_INT_MAX): int
{
    $value = (string) config($key);
    $int = ctype_digit($value) ? (int) $value : (int) DEFAULTS[$key];
    return max($min, min($max, $int));
}

function utc_now(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

/** "2026-11-21T08:12:03Z" becomes "2026-11-21 08:12:03 UTC". */
function utc_display(?string $iso): string
{
    if ($iso === null || $iso === '') {
        return 'never';
    }
    $ts = strtotime($iso);
    return $ts === false ? 'unknown' : gmdate('Y-m-d H:i:s', $ts) . ' UTC';
}

function human_bytes(int|float $bytes): string
{
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    $value = (float) max(0, $bytes);
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }
    return ($unit === 0 ? (string) (int) $value : number_format($value, $value >= 100 ? 0 : 1)) . ' ' . $units[$unit];
}

function human_duration(int $seconds): string
{
    $seconds = max(0, $seconds);
    if ($seconds < 60) {
        return $seconds . ' s';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . ' min';
    }
    if ($seconds < 86400) {
        $minutes = intdiv($seconds % 3600, 60);
        return intdiv($seconds, 3600) . ' h' . ($minutes > 0 ? ' ' . $minutes . ' min' : '');
    }
    $hours = intdiv($seconds % 86400, 3600);
    return intdiv($seconds, 86400) . ' d' . ($hours > 0 ? ' ' . $hours . ' h' : '');
}

/** "1 file", "3 files". */
function plural(int $count, string $singular, ?string $plural = null): string
{
    return $count . ' ' . ($count === 1 ? $singular : ($plural ?? $singular . 's'));
}

function read_json(string $file): array
{
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true, 32);
    return is_array($data) ? $data : [];
}

/**
 * Split a Kiwix file name into its edition-independent base and its date.
 * "wikipedia_en_medicine_maxi_2026-01.zim" gives base "wikipedia_en_medicine_maxi"
 * and date "2026-01". Returns null for anything that is not a dated ZIM name.
 */
function parse_zim_name(string $file): ?array
{
    // Kiwix sometimes reissues a month's edition with a letter: 2026-09, then 2026-09b.
    if (preg_match('/^([A-Za-z0-9][A-Za-z0-9._+-]{0,180})_(\d{4}-\d{2}[a-z]?)\.zim$/', $file, $m) !== 1) {
        return null;
    }
    return ['base' => $m[1], 'date' => $m[2]];
}

/** ZIM files present on this machine, newest edition of each title first. */
function list_zims(): array
{
    $dir = path('zim');
    $zims = [];
    foreach (@scandir($dir) ?: [] as $file) {
        if ($file[0] === '.' || !str_ends_with(strtolower($file), '.zim')) {
            continue;
        }
        $full = $dir . '/' . $file;
        if (!is_file($full)) {
            continue;
        }
        $parsed = parse_zim_name($file);
        $zims[] = [
            'file' => $file,
            'base' => $parsed['base'] ?? substr($file, 0, -4),
            'date' => $parsed['date'] ?? '',
            'size' => (int) @filesize($full),
            'mtime' => (int) @filemtime($full),
        ];
    }
    usort($zims, static fn (array $a, array $b): int => [$a['base'], $b['date']] <=> [$b['base'], $a['date']]);
    return $zims;
}

/**
 * Titles and descriptions the reader extracted from each ZIM, keyed by file
 * name. The text comes from the ZIM files themselves, so callers must treat it
 * as untrusted and escape it before display.
 */
function library_books(): array
{
    $file = path('library');
    if (!is_file($file) || !\function_exists('simplexml_load_string')) {
        return [];
    }
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return [];
    }
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw, \SimpleXMLElement::class, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($xml === false) {
        return [];
    }
    $books = [];
    foreach ($xml->book as $book) {
        $name = basename((string) $book['path']);
        if ($name === '') {
            continue;
        }
        $books[$name] = [
            'title' => substr(trim((string) $book['title']), 0, 200),
            'description' => substr(trim((string) $book['description']), 0, 400),
            'language' => substr((string) $book['language'], 0, 40),
            'articles' => (int) $book['articleCount'],
            'media' => (int) $book['mediaCount'],
        ];
    }
    return $books;
}

/**
 * The curated content catalogue plus any local additions. One entry per pack
 * and ZIM; the same ZIM may sit in several packs.
 */
function catalogue(): array
{
    $entries = [];
    foreach ([path('catalogue'), path('catalogue_local')] as $file) {
        foreach (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if ($line[0] === '#') {
                continue;
            }
            $cols = array_map('trim', explode("\t", $line));
            if (count($cols) < 4) {
                continue;
            }
            [$pack, $zim, $title, $description] = $cols;
            if (preg_match('/^[a-z][a-z0-9-]{1,40}$/', $pack) !== 1
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{1,180}$/', $zim) !== 1) {
                continue;
            }
            $entries["$pack\t$zim"] = [
                'pack' => $pack,
                'zim' => $zim,
                'title' => $title,
                'description' => $description,
                'approx' => ctype_digit($cols[4] ?? '') ? (int) $cols[4] * 1048576 : 0,
            ];
        }
    }
    return array_values($entries);
}

/**
 * The packs a name stands for. A tier name on its own ("essential", "standard",
 * "comprehensive") means that tier of every subject; a standard pack brings its
 * essential pack with it, and a comprehensive pack brings both.
 */
function expand_pack(string $name, array $packs): array
{
    $tiers = ['essential', 'standard', 'comprehensive'];
    $wanted = [];
    $include = static function (string $subject, int $upTo) use (&$wanted, $packs, $tiers): void {
        for ($i = 0; $i <= $upTo; $i++) {
            if (in_array("$subject-{$tiers[$i]}", $packs, true)) {
                $wanted["$subject-{$tiers[$i]}"] = true;
            }
        }
    };
    $level = array_search($name, $tiers, true);
    if ($level !== false) {
        foreach ($packs as $pack) {
            if (preg_match('/^(.+)-(essential|standard|comprehensive)$/', $pack, $m) === 1) {
                $include($m[1], $level);
            }
        }
    } elseif (preg_match('/^(.+)-(essential|standard|comprehensive)$/', $name, $m) === 1 && in_array($name, $packs, true)) {
        $include($m[1], (int) array_search($m[2], $tiers, true));
    } elseif (in_array($name, $packs, true)) {
        $wanted[$name] = true;
    }
    return array_keys($wanted);
}

function is_private_ipv4(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }
    $long = ip2long($ip);
    foreach ([['10.0.0.0', 8], ['172.16.0.0', 12], ['192.168.0.0', 16], ['169.254.0.0', 16]] as [$net, $bits]) {
        $mask = -1 << (32 - $bits);
        if (($long & $mask) === (ip2long($net) & $mask)) {
            return true;
        }
    }
    return false;
}

/**
 * Accept a path sent by another machine only if it is relative, free of
 * traversal, hidden components and control characters, and of sane length.
 */
function safe_relative_path(string $path): bool
{
    if ($path === '' || strlen($path) > 600 || $path[0] === '/' || preg_match('/[\x00-\x1f\x7f\\\\]/', $path) === 1) {
        return false;
    }
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part[0] === '.' || strlen($part) > 255) {
            return false;
        }
    }
    return true;
}

function extension(string $file): string
{
    return strtolower(pathinfo($file, PATHINFO_EXTENSION));
}

/** Recursive file count and size of a directory, ignoring hidden entries and symlinks. */
function tree_stats(string $dir, int $limit = 200000): array
{
    $files = 0;
    $bytes = 0;
    $stack = [$dir];
    while ($stack !== [] && $files < $limit) {
        $current = array_pop($stack);
        foreach (@scandir($current) ?: [] as $entry) {
            if ($entry[0] === '.') {
                continue;
            }
            $full = $current . '/' . $entry;
            if (is_link($full)) {
                continue;
            }
            if (is_dir($full)) {
                $stack[] = $full;
            } elseif (is_file($full)) {
                $files++;
                $bytes += (int) @filesize($full);
            }
        }
    }
    return ['files' => $files, 'bytes' => $bytes];
}

/** The applications on offer, from the shipped index. */
function app_index(): array
{
    $apps = [];
    foreach (@file(path('apps_catalogue') . '/apps.tsv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if ($line[0] === '#') {
            continue;
        }
        $cols = array_map('trim', explode("\t", $line));
        if (count($cols) < 11 || preg_match('/^[a-z][a-z0-9-]{1,30}$/', $cols[0]) !== 1) {
            continue;
        }
        $proxies = [];
        foreach ($cols[6] === '-' ? [] : explode(',', $cols[6]) as $pair) {
            if (preg_match('/^(\d{2,5})>(\d{2,5})$/', $pair, $m) === 1) {
                $proxies[] = ['lan' => (int) $m[1], 'local' => (int) $m[2]];
            }
        }
        $apps[$cols[0]] = [
            'id' => $cols[0], 'title' => $cols[1], 'category' => $cols[2], 'set' => $cols[3], 'boot' => $cols[4],
            'scheme' => $cols[5] === 'https' ? 'https' : 'http', 'proxies' => $proxies, 'memory' => $cols[7],
            'arch' => explode(',', $cols[8]), 'description' => $cols[9], 'login' => $cols[10],
        ];
    }
    return $apps;
}

// ---------------------------------------------------------------------------
// Radio reference data
// ---------------------------------------------------------------------------

/** The 38 standard CTCSS tones in hertz, numbered from 1 as handsets number them. */
const CTCSS_TONES = [
    67.0, 71.9, 74.4, 77.0, 79.7, 82.5, 85.4, 88.5, 91.5, 94.8, 97.4, 100.0, 103.5, 107.2, 110.9, 114.8, 118.8, 123.0, 127.3,
    131.8, 136.5, 141.3, 146.2, 151.4, 156.7, 162.2, 167.9, 173.8, 179.9, 186.2, 192.8, 203.5, 210.7, 218.1, 225.7, 233.6, 241.8, 250.3,
];

/** USB identifiers of radio hardware worth recognising: "vendor:product" => [kind, name]. */
const RADIO_USB_IDS = [
    '0bda:2832' => ['sdr', 'RTL-SDR receiver (RTL2832U)'],
    '0bda:2838' => ['sdr', 'RTL-SDR receiver (RTL2832U)'],
    '1d50:6089' => ['sdr', 'HackRF One'],
    '1d50:60a1' => ['sdr', 'Airspy'],
    '03eb:800c' => ['sdr', 'Airspy HF+'],
    '1df7:2500' => ['sdr', 'SDRplay RSP1'],
    '1df7:3000' => ['sdr', 'SDRplay RSP1A'],
    '10c4:ea60' => ['serial', 'CP210x serial adaptor (common on Meshtastic boards)'],
    '1a86:7523' => ['serial', 'CH340 serial adaptor (common on Meshtastic boards)'],
    '1a86:55d4' => ['serial', 'CH9102 serial adaptor (common on Meshtastic boards)'],
    '303a:1001' => ['serial', 'Espressif ESP32-S3/C3 (native USB)'],
    '239a:8029' => ['serial', 'RAK4631 or other nRF52840 board'],
    '239a:0029' => ['serial', 'nRF52840 board in bootloader mode'],
    '2e8a:000a' => ['serial', 'Raspberry Pi Pico (RP2040)'],
];

/**
 * PMR446: sixteen analogue channels, 12.5 kHz apart, from 446.00625 MHz.
 * Licence-free in the UK and across the EU for approved handsets with fixed
 * aerials at up to 0.5 W. Returns channel number => frequency in hertz.
 */
function pmr446_channels(): array
{
    $channels = [];
    for ($channel = 1; $channel <= 16; $channel++) {
        $channels[$channel] = 446006250 + ($channel - 1) * 12500;
    }
    return $channels;
}

function format_mhz(int $hertz): string
{
    return rtrim(rtrim(number_format($hertz / 1000000, 5, '.', ''), '0'), '.');
}

/** Radio hardware plugged into this machine, read from sysfs. */
function radio_devices(): array
{
    $devices = [];
    foreach (@glob(prefix() . '/sys/bus/usb/devices/*/idVendor') ?: [] as $file) {
        $dir = dirname($file);
        $id = strtolower(trim((string) @file_get_contents($file)) . ':' . trim((string) @file_get_contents("$dir/idProduct")));
        if (isset(RADIO_USB_IDS[$id])) {
            $devices[] = [
                'id' => $id, 'kind' => RADIO_USB_IDS[$id][0], 'name' => RADIO_USB_IDS[$id][1],
                'product' => substr(preg_replace('/[^\x20-\x7e]/', '', trim((string) @file_get_contents("$dir/product"))) ?? '', 0, 60),
            ];
        }
    }
    return $devices;
}
