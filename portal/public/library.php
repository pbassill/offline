<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use function Offline\{human_bytes, read_json};

$s = portal_status();
$zims = $s['knowledge']['zims'] ?? [];
$installed = array_column($zims, 'date', 'base');
$cache = read_json(Offline\path('cache'));
$packs = [];
foreach (Offline\catalogue() as $entry) {
    $packs[$entry['pack']][] = $entry;
}

page_start('Library', 'library');
?>
<section class="page-head">
  <h1>Library</h1>
  <p class="hero-sub"><?= count($zims) ?> <?= count($zims) === 1 ? 'title' : 'titles' ?> on this laptop, <?= e(human_bytes((int) ($s['knowledge']['bytes'] ?? 0))) ?> in all. Titles open in the reader, which searches inside every one of them.</p>
<?php if ($zims !== []) : ?>
  <a class="btn btn-primary" href="<?= e(reader_url()) ?>"><i class="fa-solid fa-book-open" aria-hidden="true"></i> Open the library reader</a>
<?php endif; ?>
</section>

<section aria-labelledby="h-installed">
  <h2 id="h-installed">On this laptop</h2>
<?php if ($zims === []) : ?>
  <p class="empty">Nothing yet. Pick a pack below and run its command in a terminal while this laptop is online.</p>
<?php else : ?>
  <div class="table-responsive">
  <table class="table align-middle">
    <thead><tr><th scope="col">Title</th><th scope="col">Edition</th><th scope="col" class="text-end">Articles</th><th scope="col" class="text-end">Size</th><th scope="col"><span class="visually-hidden">Open</span></th></tr></thead>
    <tbody>
<?php foreach ($zims as $zim) : ?>
      <tr>
        <th scope="row">
          <?= e($zim['title'] !== '' ? $zim['title'] : $zim['base']) ?>
          <div class="row-note"><?= e($zim['description'] ?? '') ?></div>
          <div class="row-note"><code><?= e($zim['file']) ?></code></div>
        </th>
        <td><?= e($zim['date']) ?></td>
        <td class="text-end"><?= ($zim['articles'] ?? 0) > 0 ? e(number_format((int) $zim['articles'])) : '' ?></td>
        <td class="text-end text-nowrap"><?= e(human_bytes((int) $zim['size'])) ?></td>
        <td class="text-end">
<?php if ($zim['indexed'] ?? false) : ?>
          <a class="btn btn-sm btn-outline-primary" href="<?= e(reader_url($zim['file'])) ?>">Open</a>
<?php else : ?>
          <span class="row-note">Not in the reader yet</span>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>

<section aria-labelledby="h-available">
  <h2 id="h-available">Available to add</h2>
  <p>These packs come from the Kiwix library; most are the curated collections of Project NOMAD, in three tiers per subject. A standard pack brings its essential pack with it, and a comprehensive pack brings both. <code>sudo offline get essential</code> fetches the essential tier of every subject at once. Downloading needs an internet connection and a terminal; each file is checked against the checksum Kiwix publishes before it is added. Sizes marked “about” are estimates until this laptop has looked them up (<code>sudo offline catalogue --check</code>).</p>
  <p>For minor ailments there is also a short <a href="/remedies.php">remedies reference</a> that needs no download.</p>
<?php foreach ($packs as $pack => $entries) :
    $have = count(array_filter($entries, static fn (array $e): bool => isset($installed[$e['zim']])));
    ?>
  <details class="pack">
    <summary>
      <span class="pack-name"><?= e(ucfirst(str_replace('-', ' ', $pack))) ?></span>
      <span class="pack-count"><?= $have ?> of <?= count($entries) ?> installed<?php $approx = array_sum(array_column($entries, 'approx')); echo $approx > 0 ? ', about ' . e(human_bytes($approx)) . ' in all' : ''; ?></span>
      <code class="pack-cmd">sudo offline get <?= e($pack) ?></code>
    </summary>
    <ul class="rows">
<?php foreach ($entries as $entry) :
    $live = $cache[$entry['zim']] ?? null;
    ?>
      <li>
        <span class="row-main">
          <span class="row-title"><?= e($entry['title']) ?></span>
          <span class="row-note"><?= e($entry['description']) ?></span>
          <span class="row-note"><code><?= e($entry['zim']) ?></code></span>
        </span>
        <span class="row-meta">
<?php if (isset($installed[$entry['zim']])) : ?>
          <i class="fa-solid fa-circle-check" aria-hidden="true"></i> installed <?= e($installed[$entry['zim']]) ?>
<?php if (is_array($live) && ($live['date'] ?? '') > $installed[$entry['zim']]) : ?>
          <br>newer edition <?= e($live['date']) ?> available
<?php endif; ?>
<?php elseif (is_array($live)) : ?>
          <?= e(human_bytes((int) ($live['size'] ?? 0))) ?><br><?= e($live['date'] ?? '') ?>
<?php elseif ($entry['approx'] > 0) : ?>
          about <?= e(human_bytes($entry['approx'])) ?>
<?php else : ?>
          size not looked up
<?php endif; ?>
        </span>
      </li>
<?php endforeach; ?>
    </ul>
  </details>
<?php endforeach; ?>
</section>
<?php page_end();
