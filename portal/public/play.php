<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use function Offline\{extension, human_bytes, safe_relative_path};

$root = realpath(Offline\path('media'));
$relative = (string) ($_GET['f'] ?? '');
// Without an adult's session only what is in the children's folder can be played.
$adult = portal_is_adult();
if (!$adult && !Offline\is_children_media(dirname($relative))) {
    header('Location: ' . portal_signin_url(), true, 302);
    exit;
}
$top = $adult ? 'Media' : 'Watch and listen';
$file = safe_relative_path($relative) && $root !== false ? realpath($root . '/' . $relative) : false;
$ext = $file === false ? '' : extension($file);
$isVideo = in_array($ext, Offline\PLAYABLE_VIDEO, true);
$isAudio = in_array($ext, Offline\PLAYABLE_AUDIO, true);

if ($file === false || !is_file($file) || !str_starts_with($file, $root . ($adult ? '/' : '/children/')) || !($isVideo || $isAudio)) {
    http_response_code(404);
    page_start('Not found', 'media');
    echo '<section class="page-head"><h1>That file cannot be played here</h1><p class="hero-sub">It may have been moved, or it is in a format browsers do not play.</p><a class="btn btn-primary" href="/media.php">Back to media</a></section>';
    page_end();
    exit;
}

$folder = dirname($relative) === '.' ? '' : dirname($relative);
$source = '/files/media/' . url_path($relative);
$name = pathinfo($relative, PATHINFO_FILENAME);
$subtitles = is_file(preg_replace('/\.[^.]+$/', '.vtt', $file) ?? '') ? preg_replace('/\.[^.]+$/', '.vtt', $relative) : null;

page_start($name, 'media');
?>
<section class="page-head">
  <nav aria-label="Folder path">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="/media.php"><?= e($top) ?></a></li>
<?php if ($folder !== '' && ($adult || $folder !== 'children')) : ?>
      <li class="breadcrumb-item"><a href="/media.php?p=<?= e(rawurlencode($folder)) ?>"><?= e($adult ? $folder : (string) preg_replace('/^children\//', '', $folder)) ?></a></li>
<?php endif; ?>
    </ol>
  </nav>
  <h1><?= e($name) ?></h1>
</section>

<div class="player">
<?php if ($isVideo) : ?>
  <video controls preload="metadata" src="<?= e($source) ?>">
<?php if ($subtitles !== null) : ?>
    <track kind="subtitles" src="/files/media/<?= e(url_path($subtitles)) ?>" srclang="en" label="Subtitles" default>
<?php endif; ?>
  </video>
<?php else : ?>
  <audio controls preload="metadata" src="<?= e($source) ?>"></audio>
<?php endif; ?>
</div>
<p class="player-meta"><?= e(human_bytes((int) filesize($file))) ?>, <?= e(strtoupper($ext)) ?>. <a href="<?= e($source) ?>" download>Download this file</a></p>
<?php page_end();
