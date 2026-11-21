<?php
/**
 * Offline portal — shared set-up for every page.
 *
 * The portal is read-only: it renders the status file written by the
 * offline-status timer and lists files under /srv/offline. It accepts GET and
 * HEAD only, keeps no session, sets no cookie and runs no other program.
 */

declare(strict_types=1);

if (!function_exists('Offline\\path')) {
    require '/usr/local/lib/offline/core.php';
}

header_remove('X-Powered-By');
// Scripts may fetch from this portal alone, which is how a page learns of a new family broadcast.
// A page that needs more (only the map viewer does) defines PORTAL_CSP before loading this file.
header('Content-Security-Policy: ' . (defined('PORTAL_CSP') ? PORTAL_CSP : "default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self' data:; font-src 'self'; media-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'"));
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=UTF-8');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

/** Escape text for HTML. Every value that reaches a page goes through this. */
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** The status file written each minute by the offline-status timer. */
function portal_status(): array
{
    static $status = null;
    return $status ??= Offline\read_json(Offline\path('status'));
}

/**
 * Family broadcasts still current, newest first. The broadcast service writes
 * this file only after checking each one's signature against the house key.
 */
function portal_broadcasts(): array
{
    $hours = Offline\config_int('BROADCAST_HOURS', 1, 24);
    $current = [];
    foreach (Offline\read_json(Offline\path('alerts'))['broadcasts'] ?? [] as $entry) {
        $sent = strtotime((string) ($entry['sent'] ?? ''));
        if (!is_array($entry) || $sent === false || time() - $sent > $hours * 3600
            || preg_match('/^\d{8}T\d{6}Z-[0-9a-f]{8}$/', (string) ($entry['id'] ?? '')) !== 1) {
            continue;
        }
        $current[] = [
            'id' => (string) $entry['id'],
            'urgent' => ($entry['level'] ?? '') === 'urgent',
            'from' => ((string) ($entry['by'] ?? '') !== '' ? $entry['by'] . ' at ' : '') . (string) ($entry['from'] ?? ''),
            'sent' => Offline\utc_display((string) $entry['sent']),
            'text' => (string) ($entry['text'] ?? ''),
        ];
    }
    return array_slice($current, 0, 5);
}

/** Host name the visitor used, validated, without any port. */
function portal_host(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (preg_match('/^(\[[0-9a-f:]{2,45}\]|[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?)(:\d{1,5})?$/', $host, $m) === 1) {
        return $m[1];
    }
    $address = (string) ($_SERVER['SERVER_ADDR'] ?? '127.0.0.1');
    return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $address : '127.0.0.1';
}

function reader_url(string $zimFile = ''): string
{
    $base = 'http://' . portal_host() . ':' . Offline\READER_PORT . '/';
    return $zimFile === '' ? $base : $base . 'content/' . rawurlencode(preg_replace('/\.zim$/i', '', $zimFile) ?? '');
}

/** Encode a relative path for use in a URL, one segment at a time. */
function url_path(string $relative): string
{
    return implode('/', array_map('rawurlencode', explode('/', $relative)));
}

function media_icon(string $name, bool $isDir = false): string
{
    if ($isDir) {
        return match (true) {
            (bool) preg_match('/film|movie|video|tv/i', $name) => 'fa-film',
            (bool) preg_match('/music|audio/i', $name) => 'fa-music',
            (bool) preg_match('/radio|podcast/i', $name) => 'fa-tower-broadcast',
            (bool) preg_match('/book|doc/i', $name) => 'fa-book',
            default => 'fa-folder',
        };
    }
    $ext = Offline\extension($name);
    return match (true) {
        in_array($ext, ['mp4', 'm4v', 'mkv', 'webm', 'avi', 'mov', 'mpg', 'mpeg', 'ts'], true) => 'fa-film',
        in_array($ext, Offline\PLAYABLE_AUDIO, true) || $ext === 'm4b' => 'fa-music',
        in_array($ext, ['pdf', 'epub', 'cbz', 'txt', 'nfo'], true) => 'fa-book',
        in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) => 'fa-image',
        default => 'fa-file',
    };
}

/** A labelled meter. Uses the native progress element, so no inline styles are needed. */
function meter(string $label, int $percent, string $detail, string $tone = ''): string
{
    $percent = max(0, min(100, $percent));
    return '<div class="meter' . ($tone !== '' ? ' meter-' . e($tone) : '') . '">'
        . '<div class="meter-head"><span>' . e($label) . '</span><span>' . e($detail) . '</span></div>'
        . '<progress max="100" value="' . $percent . '">' . $percent . ' %</progress></div>';
}

function page_start(string $title, string $active, array $extraStyles = []): void
{
    $status = portal_status();
    $host = (string) ($status['host']['name'] ?? gethostname());
    $nav = [
        'index' => ['Overview', 'fa-gauge'], 'library' => ['Library', 'fa-book-open'], 'media' => ['Media', 'fa-film'],
        'maps' => ['Maps', 'fa-map-location-dot'], 'apps' => ['Apps', 'fa-table-cells-large'], 'games' => ['Games', 'fa-gamepad'], 'radio' => ['Radio', 'fa-tower-broadcast'],
        'peers' => ['Systems', 'fa-network-wired'], 'guide' => ['Guide', 'fa-circle-question'],
    ];
    ?>
<!doctype html>
<html lang="en-GB" data-bs-theme="light" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:,">
<title><?= e($title) ?> · Offline on <?= e($host) ?></title>
<link rel="stylesheet" href="/assets/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="/assets/fontawesome/css/fontawesome.min.css">
<link rel="stylesheet" href="/assets/fontawesome/css/solid.min.css">
<link rel="stylesheet" href="/assets/css/offline.css">
<?php foreach ($extraStyles as $href) : ?>
<link rel="stylesheet" href="<?= e($href) ?>">
<?php endforeach; ?>
<script src="/assets/js/theme.js"></script>
<script src="/assets/bootstrap/bootstrap.bundle.min.js" defer></script>
</head>
<body>
<a class="visually-hidden-focusable skip-link" href="#content">Skip to content</a>
<nav class="navbar navbar-expand-xl masthead" aria-label="Main">
  <div class="container-xl">
    <a class="navbar-brand" href="/"><i class="fa-solid fa-book-atlas" aria-hidden="true"></i> Offline <span class="brand-host"><?= e($host) ?></span></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainnav" aria-controls="mainnav" aria-expanded="false" aria-label="Show menu"><i class="fa-solid fa-bars" aria-hidden="true"></i></button>
    <div class="collapse navbar-collapse" id="mainnav">
      <ul class="navbar-nav me-auto">
<?php foreach ($nav as $page => [$label, $icon]) : ?>
        <li class="nav-item"><a class="nav-link<?= $page === $active ? ' active' : '' ?>"<?= $page === $active ? ' aria-current="page"' : '' ?> href="/<?= $page === 'index' ? '' : e($page) . '.php' ?>"><i class="fa-solid <?= e($icon) ?> fa-fw" aria-hidden="true"></i> <?= e($label) ?></a></li>
<?php endforeach; ?>
      </ul>
      <div class="theme-switch" role="group" aria-label="Colour theme">
        <button type="button" class="btn btn-sm" data-set-theme="light" aria-pressed="false"><i class="fa-solid fa-sun" aria-hidden="true"></i> Light</button>
        <button type="button" class="btn btn-sm" data-set-theme="dark" aria-pressed="false"><i class="fa-solid fa-moon" aria-hidden="true"></i> Dark</button>
        <button type="button" class="btn btn-sm" data-set-theme="vt100" aria-pressed="false"><i class="fa-solid fa-terminal" aria-hidden="true"></i> VT100</button>
      </div>
    </div>
  </div>
</nav>
<div id="broadcasts" class="broadcasts container-xl" aria-live="assertive">
<?php foreach (portal_broadcasts() as $b) : ?>
  <div class="broadcast<?= $b['urgent'] ? ' broadcast-urgent' : '' ?>" role="<?= $b['urgent'] ? 'alert' : 'status' ?>" data-broadcast="<?= e($b['id']) ?>">
    <i class="fa-solid <?= $b['urgent'] ? 'fa-triangle-exclamation' : 'fa-bullhorn' ?> fa-fw" aria-hidden="true"></i>
    <div class="broadcast-body">
      <p class="broadcast-text"><?= e($b['text']) ?></p>
      <p class="broadcast-meta"><?= $b['urgent'] ? 'Urgent family broadcast' : 'Family broadcast' ?> from <?= e($b['from']) ?>, <?= e($b['sent']) ?></p>
    </div>
    <button type="button" class="btn btn-sm broadcast-dismiss" data-dismiss-broadcast="<?= e($b['id']) ?>"><i class="fa-solid fa-check" aria-hidden="true"></i> Seen</button>
  </div>
<?php endforeach; ?>
</div>
<main id="content" class="container-xl">
<?php
    $generated = strtotime((string) ($status['generated'] ?? ''));
    if ($status === []) {
        echo '<div class="notice notice-warn" role="status"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> No status has been recorded yet. Start it with <code>sudo systemctl start offline-status.service</code>.</div>';
    } elseif ($generated !== false && time() - $generated > 600) {
        echo '<div class="notice notice-warn" role="status"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> These figures were last refreshed at ' . e(Offline\utc_display($status['generated'])) . '. Check the timer with <code>systemctl status offline-status.timer</code>.</div>';
    }
}

function page_end(): void
{
    $status = portal_status();
    ?>
</main>
<footer class="container-xl site-footer">
  <span>Offline <?= e(Offline\VERSION) ?></span>
  <span>Status as at <?= e(Offline\utc_display($status['generated'] ?? null)) ?></span>
  <span>Everything on this page is served from this system.</span>
</footer>
<script src="/assets/js/offline.js" defer></script>
<script src="/assets/js/broadcasts.js" defer></script>
</body>
</html>
<?php
}
