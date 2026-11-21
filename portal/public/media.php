<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use function Offline\{extension, human_bytes, safe_relative_path};

$root = realpath(Offline\path('media'));
$relative = trim((string) ($_GET['p'] ?? ''), '/');
$dir = $root;
if ($relative !== '') {
    $candidate = safe_relative_path($relative) && $root !== false ? realpath($root . '/' . $relative) : false;
    // The resolved folder must still sit inside the media root.
    if ($candidate === false || !is_dir($candidate) || !str_starts_with($candidate . '/', $root . '/')) {
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

page_start($relative === '' ? 'Media' : end($crumbs), 'media');
?>
<section class="page-head">
  <nav aria-label="Folder path">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><?= $relative === '' ? 'Media' : '<a href="/media.php">Media</a>' ?></li>
<?php
$walk = '';
foreach ($crumbs as $i => $crumb) {
    $walk .= ($walk === '' ? '' : '/') . $crumb;
    echo $i === count($crumbs) - 1
        ? '<li class="breadcrumb-item active" aria-current="page">' . e($crumb) . '</li>'
        : '<li class="breadcrumb-item"><a href="/media.php?p=' . e(rawurlencode($walk)) . '">' . e($crumb) . '</a></li>';
}
?>
    </ol>
  </nav>
  <h1><?= e($relative === '' ? 'Media' : end($crumbs)) ?></h1>
</section>

<?php if ($folders === [] && $files === []) : ?>
<p class="empty">This folder is empty. Copy films, music or books into <code>/srv/offline/media/<?= e($prefix) ?></code> and they appear here, and on the other laptops within a few minutes.</p>
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
