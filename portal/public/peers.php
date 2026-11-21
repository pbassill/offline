<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use function Offline\{human_bytes, utc_display};

$s = portal_status();
$peers = $s['sync']['peers'] ?? [];
$last = $s['sync']['last_run'] ?? null;
$enabled = $s['sync']['enabled'] ?? true;
$access = [
    'ok' => ['Trusted', 'is-ok', 'fa-circle-check'],
    'rejected' => ['Different house key', 'is-bad', 'fa-circle-xmark'],
    'error' => ['Could not connect', 'is-off', 'fa-circle-minus'],
    'unknown' => ['Not yet contacted', 'is-off', 'fa-circle-minus'],
];

page_start('Laptops', 'peers');
?>
<section class="page-head">
  <h1>Other laptops</h1>
  <p class="hero-sub">Laptops on the same network that hold the same house key copy new library titles and media from one another every five minutes. Nothing is ever deleted from one laptop because it is missing on another.</p>
</section>

<?php if (!($s['house_key'] ?? false)) : ?>
<div class="notice notice-warn" role="status"><i class="fa-solid fa-key" aria-hidden="true"></i> This laptop has no house key, so it will not exchange anything. Create one with <code>sudo offline key new</code>, or enter the key from another laptop with <code>sudo offline key set KEY</code>.</div>
<?php elseif (!$enabled) : ?>
<div class="notice notice-warn" role="status"><i class="fa-solid fa-circle-pause" aria-hidden="true"></i> Sharing is switched off in <code>/etc/offline/offline.conf</code> (<code>SYNC_ENABLED=no</code>).</div>
<?php endif; ?>

<section aria-labelledby="h-last">
  <h2 id="h-last">Last exchange</h2>
<?php if ($last === null) : ?>
  <p class="empty">None has run yet. Start one now with <code>sudo offline sync</code>.</p>
<?php else : ?>
  <dl class="facts facts-wide">
    <dt>Finished</dt><dd><?= e(utc_display($last['finished'] ?? null)) ?></dd>
    <dt>Result</dt><dd><?= e(ucfirst((string) ($last['message'] ?? ''))) ?></dd>
  </dl>
<?php endif; ?>
</section>

<section aria-labelledby="h-known">
  <h2 id="h-known">Known laptops</h2>
<?php if ($peers === []) : ?>
  <p class="empty">No other laptop has been seen. Check that it is switched on, joined to the same network, and shows “House key: set” on its own overview page.</p>
<?php else : ?>
  <div class="table-responsive">
  <table class="table align-middle">
    <thead><tr><th scope="col">Laptop</th><th scope="col">Access</th><th scope="col">Last seen</th><th scope="col">Last exchange</th><th scope="col" class="text-end">It offers</th><th scope="col" class="text-end">Received from it</th></tr></thead>
    <tbody>
<?php foreach ($peers as $peer) :
    [$label, $class, $icon] = $access[$peer['auth'] ?? 'unknown'] ?? $access['unknown'];
    $offers = array_sum(array_column($peer['offers'] ?? [], 'bytes'));
    ?>
      <tr>
        <th scope="row"><?= e(($peer['name'] ?? '') !== '' ? $peer['name'] : 'Unnamed') ?><div class="row-note"><?= e($peer['address'] ?? '') ?></div></th>
        <td class="<?= e($class) ?>"><i class="fa-solid <?= e($icon) ?>" aria-hidden="true"></i> <?= e($label) ?>
<?php if (($peer['last_error'] ?? '') !== '') : ?>
          <div class="row-note"><?= e($peer['last_error']) ?></div>
<?php endif; ?>
        </td>
        <td><?= e(utc_display($peer['last_seen'] ?? null)) ?></td>
        <td><?= e(utc_display($peer['last_sync'] ?? null)) ?></td>
        <td class="text-end text-nowrap"><?= $offers > 0 ? e(human_bytes($offers)) : '' ?></td>
        <td class="text-end text-nowrap"><?= (int) ($peer['total_files'] ?? 0) ?> files, <?= e(human_bytes((int) ($peer['total_bytes'] ?? 0))) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>

<section aria-labelledby="h-rules">
  <h2 id="h-rules">What is accepted from another laptop</h2>
  <ul class="plain">
    <li>Only library files (<code>.zim</code>), documents, films, music, subtitles and pictures. Programs, scripts and web pages are refused.</li>
    <li>An older edition of a title this laptop already holds is not fetched again.</li>
    <li>A file that claims to be a library title but is not a valid one is discarded and not requested again.</li>
    <li>Fetching stops before free space falls below <?= e((string) Offline\config_int('SYNC_MIN_FREE_GB')) ?> GiB, and waits on battery below <?= e((string) Offline\config_int('SYNC_MIN_BATTERY', 0, 100)) ?> %.</li>
  </ul>
</section>
<?php page_end();
