<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use function Offline\{human_bytes, utc_display};

$s = portal_status();
$peers = $s['sync']['peers'] ?? [];
$servers = $s['servers'] ?? [];
$role = ($s['role'] ?? 'workstation') === 'server' ? 'server' : 'workstation';
$queue = $s['queue'] ?? ['total' => 0, 'states' => [], 'staging_bytes' => 0, 'items' => []];
$broadcasts = portal_broadcasts();
$last = $s['sync']['last_run'] ?? null;
$enabled = $s['sync']['enabled'] ?? true;
$access = [
    'ok' => ['Trusted', 'is-ok', 'fa-circle-check'],
    'rejected' => ['Different house key', 'is-bad', 'fa-circle-xmark'],
    'error' => ['Could not connect', 'is-off', 'fa-circle-minus'],
    'unknown' => ['Not yet contacted', 'is-off', 'fa-circle-minus'],
];

page_start('Systems', 'peers');
?>
<section class="page-head">
  <h1>Other systems</h1>
  <p class="hero-sub">This is a <?= e($role) ?>. Systems that hold the same house key copy new library titles, media and maps from one another every five minutes, from the central servers first and then from whatever else is on the home network. Every connection is encrypted and opens only for a system that holds the key. Nothing is ever deleted from one system because it is missing on another.</p>
</section>

<?php if (!($s['house_key'] ?? false)) : ?>
<div class="notice notice-warn" role="status"><i class="fa-solid fa-key" aria-hidden="true"></i> This system has no house key, so it will not exchange anything. Create one with <code>sudo offline key new</code>, or enter the key from another system with <code>sudo offline key set KEY</code>.</div>
<?php elseif (!$enabled) : ?>
<div class="notice notice-warn" role="status"><i class="fa-solid fa-circle-pause" aria-hidden="true"></i> Sharing is switched off in <code>/etc/offline/offline.conf</code> (<code>SYNC_ENABLED=no</code>).</div>
<?php endif; ?>

<section aria-labelledby="h-last">
  <h2 id="h-last">Last exchange</h2>
<?php $running = $s['sync']['running'] ?? null; ?>
<?php if (is_array($running) && $enabled) : ?>
  <div class="notice" role="status"><i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i> An exchange is running now, since <?= e(utc_display((string) ($running['started'] ?? ''))) ?>: <?= e((string) ($running['stage'] ?? '')) ?>. A first copy from a server can take hours, and what it fetched is counted here when it ends.</div>
<?php endif; ?>
<?php if ($last === null) : ?>
  <p class="empty">None has run yet. Start one now with <code>sudo offline sync</code>.</p>
<?php else : ?>
  <dl class="facts facts-wide">
    <dt>Finished</dt><dd><?= e(utc_display($last['finished'] ?? null)) ?></dd>
    <dt>Result</dt><dd><?= e(ucfirst((string) ($last['message'] ?? ''))) ?></dd>
  </dl>
<?php endif; ?>
</section>

<section aria-labelledby="h-servers">
  <h2 id="h-servers">Central servers</h2>
<?php if ($servers === []) : ?>
  <p class="empty">None is configured, so this system exchanges only with others on the home network. Name a server with <code>sudo offline server add HOST</code>.</p>
<?php else : ?>
  <p>This system pulls from <?= count($servers) === 1 ? 'this server' : 'these servers' ?> wherever it has a route to <?= count($servers) === 1 ? 'it' : 'them' ?>, at home or away: <?= implode(', ', array_map(static fn (string $h): string => '<code>' . e($h) . '</code>', $servers)) ?>. Check the link with <code>sudo offline server test</code>.</p>
<?php endif; ?>
</section>

<section aria-labelledby="h-known">
  <h2 id="h-known">Known systems</h2>
<?php if ($peers === []) : ?>
  <p class="empty">No other system has been seen. Check that it is switched on, joined to the same network, and shows “House key: set” on its own overview page.</p>
<?php else : ?>
  <div class="table-responsive">
  <table class="table align-middle">
    <thead><tr><th scope="col">System</th><th scope="col">Access</th><th scope="col">Last seen</th><th scope="col">Last exchange</th><th scope="col" class="text-end">It offers</th><th scope="col" class="text-end">Received from it</th></tr></thead>
    <tbody>
<?php foreach ($peers as $peer) :
    [$label, $class, $icon] = $access[$peer['auth'] ?? 'unknown'] ?? $access['unknown'];
    $offers = array_sum(array_column($peer['offers'] ?? [], 'bytes'));
    ?>
      <tr>
        <th scope="row"><?= e(($peer['name'] ?? '') !== '' ? $peer['name'] : 'Unnamed') ?><div class="row-note"><?= e($peer['address'] ?? '') ?><?= ($peer['method'] ?? '') === 'server' ? ', central server' : '' ?></div></th>
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

<section aria-labelledby="h-broadcast">
  <h2 id="h-broadcast">Family broadcast</h2>
  <p>A broadcast appears on every screen in the household within seconds, at the top of these pages, and is sent to the mesh radios by any system with a node plugged in. Send one from the <em>Family broadcast</em> entry in the applications menu, or from a terminal:</p>
  <pre><code>offline broadcast "Dinner is ready"
offline broadcast --urgent "Power cut: torches are in the hall cupboard"</code></pre>
<?php if ($broadcasts === []) : ?>
  <p class="empty">No broadcast in the last <?= (int) ($s['broadcast']['hours'] ?? 12) ?> hours.</p>
<?php else : ?>
  <div class="table-responsive">
  <table class="table align-middle">
    <thead><tr><th scope="col">Sent</th><th scope="col">From</th><th scope="col">Message</th></tr></thead>
    <tbody>
<?php foreach ($broadcasts as $b) : ?>
      <tr><td class="text-nowrap"><?= e($b['sent']) ?></td><td><?= e($b['from']) ?></td><td><?= $b['urgent'] ? '<strong>Urgent.</strong> ' : '' ?><?= e($b['text']) ?></td></tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
  <p class="row-note">Each broadcast is signed with a key derived from the house key and is shown only where that signature checks. These pages cannot send one: the portal stays read-only.</p>
</section>

<?php if ($role === 'server' || (int) ($queue['total'] ?? 0) > 0) : ?>
<section aria-labelledby="h-queue">
  <h2 id="h-queue">Download queue and staging</h2>
  <p>Downloads arrive in a staging area that is not shared. Publishing moves them into the shared folders, and every other system fetches them at its next exchange. Staging holds <?= e(human_bytes((int) ($queue['staging_bytes'] ?? 0))) ?>.</p>
<?php if (($queue['items'] ?? []) === []) : ?>
  <p class="empty">The queue is empty. Add to it with <code>sudo offline queue add ADDRESS</code> or <code>sudo offline queue site uk</code>.</p>
<?php else : ?>
  <div class="table-responsive">
  <table class="table align-middle">
    <thead><tr><th scope="col">Id</th><th scope="col">What</th><th scope="col">Goes to</th><th scope="col">State</th><th scope="col">Result</th></tr></thead>
    <tbody>
<?php foreach ($queue['items'] as $item) : ?>
      <tr><td><code><?= e($item['id'] ?? '') ?></code></td><td><?= e($item['what'] ?? '') ?><div class="row-note"><?= e($item['kind'] ?? '') ?></div></td><td><?= e($item['to'] ?? '') ?></td><td><?= e($item['state'] ?? '') ?></td><td><?= e($item['message'] ?? '') ?></td></tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <pre><code>offline stage
sudo offline stage publish all</code></pre>
<?php endif; ?>
</section>
<?php endif; ?>

<section aria-labelledby="h-rules">
  <h2 id="h-rules">What is accepted from another system</h2>
  <ul class="plain">
    <li>Only library files (<code>.zim</code>), documents, films, music, subtitles, pictures and map files. Programs, scripts and web pages are refused.</li>
    <li>An older edition of a title this system already holds is not fetched again.</li>
    <li>A file that claims to be a library title but is not a valid one is discarded and not requested again.</li>
    <li>Fetching stops before free space falls below <?= e((string) Offline\config_int('SYNC_MIN_FREE_GB')) ?> GiB, and waits on battery below <?= e((string) Offline\config_int('SYNC_MIN_BATTERY', 0, 100)) ?> %.</li>
  </ul>
</section>
<?php page_end();
