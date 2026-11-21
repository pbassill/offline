<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use function Offline\{extension, human_bytes, safe_relative_path};

$root = realpath(Offline\path('media'));
$relative = trim((string) ($_GET['p'] ?? ''), '/');
// Without an adult's session this page shows the children's folder and nothing above or beside it.
$adult = portal_is_adult();
if (!$adult) {
    if ($relative === '') {
        $relative = 'children';
    } elseif (!Offline\is_children_media($relative)) {
        header('Location: ' . portal_signin_url(), true, 302);
        exit;
    }
}
$dir = $root;
if (!$adult && $relative === 'children' && ($root === false || !is_dir($root . '/children'))) {
    // Nobody has put anything there yet.
    $dir = false;
} elseif ($relative !== '') {
    $candidate = safe_relative_path($relative) && $root !== false ? realpath($root . '/' . $relative) : false;
    // The resolved folder must still sit inside the media root.
    // For anyone not signed in, where a link leads counts as well as what it is called.
    $within = $adult ? $root : $root . '/children';
    if ($candidate === false || !is_dir($candidate) || !str_starts_with($candidate . '/', $within . '/')) {
        http_response_code(404);
        page_start('Folder not found', 'media');
        echo '<section class="page-head"><h1>That folder does not exist</h1><p class="hero-sub">It may have been moved or renamed.</p><a class="btn btn-primary" href="/media.php">Back to media</a></section>';
        page_end();
        exit;
    }
    $dir = $candidate;
}

$folders = [];
$files = [];
foreach ($dir === false ? [] : (scandir($dir) ?: []) as $entry) {
    if ($entry[0] === '.' || !safe_relative_path($entry)) {
        continue;
    }
    $full = $dir . '/' . $entry;
    if (is_link($full)) {
        continue;
    }
    if (is_dir($full)) {
        $folders[] = $entry;
    } elseif (is_file($full) && in_array(extension($entry), Offline\SYNC_EXTENSIONS['media'], true)) {
        $files[] = ['name' => $entry, 'size' => (int) filesize($full)];
    }
}
natcasesort($folders);
usort($files, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
$prefix = $relative === '' ? '' : $relative . '/';
$crumbs = $relative === '' ? [] : explode('/', $relative);
$top = $adult ? 'Media' : 'Watch and listen';
$atTop = $relative === '' || (!$adult && $relative === 'children');

page_start($atTop ? $top : end($crumbs), 'media');
?>
<section class="page-head">
  <nav aria-label="Folder path">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><?= $atTop ? e($top) : '<a href="/media.php">' . e($top) . '</a>' ?></li>
<?php
$walk = '';
foreach ($crumbs as $i => $crumb) {
    $walk .= ($walk === '' ? '' : '/') . $crumb;
    if (!$adult && $i === 0) {
        continue;
    }
    echo $i === count($crumbs) - 1
        ? '<li class="breadcrumb-item active" aria-current="page">' . e($crumb) . '</li>'
        : '<li class="breadcrumb-item"><a href="/media.php?p=' . e(rawurlencode($walk)) . '">' . e($crumb) . '</a></li>';
}
?>
    </ol>
  </nav>
  <h1><?= e($atTop ? $top : end($crumbs)) ?></h1>
</section>

<?php if ($folders === [] && $files === []) : ?>
<?php if ($adult) : ?>
<p class="empty">This folder is empty. Copy films, music or books into <code>/srv/offline/media/<?= e($prefix) ?></code> and they appear here, and on the other systems within a few minutes.<?= Offline\is_children_media($relative) ? ' Whatever is put in this folder can be watched by everyone in the house without signing in.' : '' ?></p>
<?php else : ?>
<p class="empty">There is nothing to watch or listen to here yet. Ask a grown-up to add some.</p>
<?php endif; ?>
<?php endif; ?>

<?php if ($folders !== []) : ?>
<ul class="rows">
<?php foreach ($folders as $folder) : ?>
  <li>
    <a class="row-main" href="/media.php?p=<?= e(rawurlencode($prefix . $folder)) ?>">
      <span class="row-title"><i class="fa-solid <?= e(media_icon($folder, true)) ?> fa-fw" aria-hidden="true"></i> <?= e($folder) ?></span>
    </a>
    <span class="row-meta">folder</span>
  </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if ($files !== []) : ?>
<ul class="rows">
<?php foreach ($files as $file) :
    $ext = extension($file['name']);
    $playable = in_array($ext, Offline\PLAYABLE_VIDEO, true) || in_array($ext, Offline\PLAYABLE_AUDIO, true);
    $direct = '/files/media/' . url_path($prefix . $file['name']);
    ?>
  <li>
    <a class="row-main" href="<?= $playable ? '/play.php?f=' . e(rawurlencode($prefix . $file['name'])) : e($direct) ?>">
      <span class="row-title"><i class="fa-solid <?= e(media_icon($file['name'])) ?> fa-fw" aria-hidden="true"></i> <?= e($file['name']) ?></span>
<?php if (!$playable && in_array($ext, ['mkv', 'avi', 'mov', 'mpg', 'mpeg', 'ts', 'm4b'], true)) : ?>
      <span class="row-note">Browsers cannot play this format. Download it, or open it in VLC.</span>
<?php endif; ?>
    </a>
    <span class="row-meta"><?= e(human_bytes($file['size'])) ?><br><a href="<?= e($direct) ?>" download>Download</a></span>
  </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php page_end();
