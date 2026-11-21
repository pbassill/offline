<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use function Offline\{human_bytes, human_duration, utc_display};

// Whoever has not signed in gets the children's front page.
if (!portal_is_adult()) {
    require __DIR__ . '/../app/kids-home.php';
    exit;
}

$s = portal_status();
$zims = $s['knowledge']['zims'] ?? [];
$mediaFolders = $s['media'] ?? [];
$mediaFiles = array_sum(array_column($mediaFolders, 'files'));
$power = $s['power'] ?? [];
$storage = $s['storage'] ?? ['total' => 0, 'free' => 0, 'used_percent' => 0];
$host = $s['host'] ?? [];

$titles = count($zims);
$headline = match (true) {
    $titles > 0 && $mediaFiles > 0 => "$titles library " . ($titles === 1 ? 'title' : 'titles') . " and $mediaFiles media " . ($mediaFiles === 1 ? 'file' : 'files') . ', ready with no internet.',
    $titles > 0 => "$titles library " . ($titles === 1 ? 'title' : 'titles') . ', ready with no internet.',
    $mediaFiles > 0 => "$mediaFiles media " . ($mediaFiles === 1 ? 'file' : 'files') . ', ready with no internet. The library is still empty.',
    default => 'This system is set up, but nothing has been downloaded yet.',
};
if (($power['battery'] ?? null) !== null && ($power['on_ac'] ?? true) === false) {
    $powerLine = "Running on battery at {$power['battery']} %" . (($power['seconds_left'] ?? null) !== null ? ', with about ' . human_duration((int) $power['seconds_left']) . ' left at the present draw.' : '.');
} elseif (($power['battery'] ?? null) !== null) {
    $powerLine = "On mains power, battery at {$power['battery']} %.";
} else {
    $powerLine = 'On mains power.';
}

page_start('Overview', 'index');
?>
<section class="hero">
  <h1><?= e($headline) ?></h1>
  <p class="hero-sub"><?= e($powerLine) ?> <?= e(human_bytes((int) $storage['free'])) ?> of storage is free.</p>
  <div class="hero-actions">
<?php if ($titles > 0) : ?>
    <a class="btn btn-primary btn-lg" href="<?= e(reader_url()) ?>"><i class="fa-solid fa-book-open" aria-hidden="true"></i> Open the library reader</a>
<?php else : ?>
    <a class="btn btn-primary btn-lg" href="/guide.php#add-knowledge"><i class="fa-solid fa-download" aria-hidden="true"></i> Add the first titles</a>
<?php endif; ?>
    <a class="btn btn-outline-primary btn-lg" href="/media.php"><i class="fa-solid fa-film" aria-hidden="true"></i> Browse media</a>
  </div>
</section>

<div class="row g-5">
  <div class="col-lg-7">
    <section aria-labelledby="h-library">
      <div class="section-head">
        <h2 id="h-library">In the library</h2>
        <a href="/library.php">All titles and what else is available</a>
      </div>
<?php if ($zims === []) : ?>
      <p class="empty">No titles yet. With an internet connection, run <code>sudo offline get essential</code> in a terminal, or copy <code>.zim</code> files into <code>Documents/Kiwix-zims</code>.</p>
<?php else : ?>
      <ul class="rows">
<?php foreach (array_slice($zims, 0, 8) as $zim) : ?>
        <li>
          <a class="row-main" href="<?= e(reader_url($zim['file'])) ?>">
            <span class="row-title"><?= e($zim['title'] !== '' ? $zim['title'] : $zim['base']) ?></span>
            <span class="row-note"><?= e($zim['description'] ?? '') ?></span>
          </a>
          <span class="row-meta"><?= e($zim['date']) ?><br><?= e(human_bytes((int) $zim['size'])) ?></span>
        </li>
<?php endforeach; ?>
      </ul>
<?php if ($titles > 8) : ?>
      <p class="more"><a href="/library.php"><?= $titles - 8 ?> more in the library</a></p>
<?php endif; ?>
<?php endif; ?>
    </section>

    <section aria-labelledby="h-media">
      <div class="section-head">
        <h2 id="h-media">Media</h2>
        <a href="/media.php">Browse everything</a>
      </div>
<?php if ($mediaFolders === []) : ?>
      <p class="empty">No media folders found under <code>/srv/offline/media</code>.</p>
<?php else : ?>
      <ul class="rows">
<?php foreach ($mediaFolders as $folder => $stats) : ?>
        <li>
          <a class="row-main" href="/media.php?p=<?= e(rawurlencode((string) $folder)) ?>">
            <span class="row-title"><i class="fa-solid <?= e(media_icon((string) $folder, true)) ?> fa-fw" aria-hidden="true"></i> <?= e(ucfirst((string) $folder)) ?></span>
          </a>
          <span class="row-meta"><?= (int) $stats['files'] ?> <?= (int) $stats['files'] === 1 ? 'file' : 'files' ?><br><?= e(human_bytes((int) $stats['bytes'])) ?></span>
        </li>
<?php endforeach; ?>
      </ul>
<?php endif; ?>
    </section>
  </div>

  <aside class="col-lg-5" aria-labelledby="h-system">
    <h2 id="h-system">This system</h2>
<?php
if (($power['battery'] ?? null) !== null) {
    $battery = (int) $power['battery'];
    echo meter('Battery', $battery, $battery . ' %, ' . ($power['battery_state'] ?? ''), $battery < 20 ? 'bad' : ($battery < 40 ? 'warn' : ''));
}
$used = (int) $storage['used_percent'];
echo meter('Storage used', $used, human_bytes((int) $storage['free']) . ' free of ' . human_bytes((int) $storage['total']), $used > 92 ? 'bad' : ($used > 80 ? 'warn' : ''));
?>
    <dl class="facts">
<?php if (($power['profile'] ?? null) !== null) : ?>
      <dt>Power profile</dt><dd><?= e($power['profile']) ?></dd>
<?php endif; ?>
      <dt>Address</dt>
      <dd><?php
        $addresses = array_column($host['addresses'] ?? [], 'ip');
        echo $addresses === [] ? 'Not connected to a network' : e(implode(', ', $addresses));
      ?></dd>
      <dt>Internet</dt><dd><?= ($host['online'] ?? false) ? 'A route to the internet is present' : 'Working offline' ?></dd>
      <dt>Memory</dt><dd><?= e(human_bytes((int) ($host['memory_available'] ?? 0))) ?> available of <?= e(human_bytes((int) ($host['memory_total'] ?? 0))) ?></dd>
      <dt>Running for</dt><dd><?= e(human_duration((int) ($host['uptime'] ?? 0))) ?></dd>
      <dt>System</dt><dd><?= e($host['os'] ?? '') ?></dd>
    </dl>

    <h3>Services</h3>
    <ul class="services">
<?php foreach ($s['services'] ?? [] as $service) :
    $ok = ($service['state'] ?? '') === 'active' && ($service['listening'] ?? true) !== false;
    $bad = in_array($service['state'] ?? '', ['failed', 'disabled'], true) || (($service['state'] ?? '') === 'active' && ($service['listening'] ?? true) === false);
    ?>
      <li class="<?= $ok ? 'is-ok' : ($bad ? 'is-bad' : 'is-off') ?>">
        <i class="fa-solid <?= $ok ? 'fa-circle-check' : ($bad ? 'fa-circle-xmark' : 'fa-circle-minus') ?> fa-fw" aria-hidden="true"></i>
        <span><?= e($service['label'] ?? '') ?></span>
        <span class="service-state"><?= $ok ? 'running' : e($service['state'] ?? 'unknown') ?></span>
      </li>
<?php endforeach; ?>
      <li class="<?= ($s['house_key'] ?? false) ? 'is-ok' : 'is-bad' ?>">
        <i class="fa-solid <?= ($s['house_key'] ?? false) ? 'fa-circle-check' : 'fa-circle-xmark' ?> fa-fw" aria-hidden="true"></i>
        <span>House key</span>
        <span class="service-state"><?= ($s['house_key'] ?? false) ? 'set' : 'not set' ?></span>
      </li>
    </ul>

    <h3>Other systems</h3>
<?php
$last = $s['sync']['last_run'] ?? null;
$peers = $s['sync']['peers'] ?? [];
if (!($s['sync']['enabled'] ?? true)) {
    echo '<p>Sharing between systems is switched off.</p>';
} elseif ($last === null) {
    echo '<p>No exchange has run yet. It runs every five minutes.</p>';
} else {
    echo '<p>Last checked ' . e(utc_display($last['finished'] ?? null)) . '. ' . e(ucfirst((string) ($last['message'] ?? ''))) . '.</p>';
}
if ($peers !== []) {
    echo '<p><a href="/peers.php">' . count($peers) . ' known ' . (count($peers) === 1 ? 'system' : 'systems') . '</a></p>';
}
$models = $s['ai']['models'] ?? [];
if ($models !== []) {
    echo '<h3>AI models</h3><p>' . e(implode(', ', array_map(static fn (array $m): string => $m['name'] . ' (' . human_bytes((int) $m['size']) . ')', $models))) . '</p>';
}
?>
  </aside>
</div>
<?php page_end();
