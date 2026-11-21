<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$s = portal_status();
$states = array_column($s['apps'] ?? [], null, 'id');
$groups = [];
foreach (Offline\app_index() as $id => $app) {
    $groups[$app['category']][] = $app + ['state' => $states[$id] ?? ['installed' => false, 'state' => 'absent', 'boot' => false]];
}
$running = count(array_filter($states, static fn (array $a): bool => ($a['state'] ?? '') === 'active'));
$host = portal_host();

page_start('Apps', 'apps');
?>
<section class="page-head">
  <h1>Apps</h1>
  <p class="hero-sub">Tools that run on this system and open in a browser on any device at home. <?= $running ?> <?= $running === 1 ? 'is' : 'are' ?> running now. Each one runs in its own sealed container and is reached only through this system's own gateway.</p>
</section>

<?php foreach ($groups as $category => $apps) : ?>
<section aria-labelledby="h-<?= e(strtolower(preg_replace('/[^A-Za-z]/', '', $category) ?? '')) ?>">
  <h2 id="h-<?= e(strtolower(preg_replace('/[^A-Za-z]/', '', $category) ?? '')) ?>"><?= e($category) ?></h2>
  <ul class="rows">
<?php foreach ($apps as $app) :
    $state = $app['state'];
    $port = $app['proxies'][0]['lan'] ?? null;
    $isRunning = ($state['state'] ?? '') === 'active';
    $url = $port !== null ? $app['scheme'] . '://' . $host . ':' . $port . '/' : null;
    ?>
    <li>
      <span class="row-main">
        <span class="row-title"><?= e($app['title']) ?></span>
        <span class="row-note"><?= e($app['description']) ?></span>
<?php if ($state['installed'] ?? false) : ?>
        <span class="row-note">Sign-in: <?= e($app['login']) ?></span>
<?php if (!$isRunning) : ?>
        <span class="row-note">Start it with <code>sudo offline apps start <?= e($app['id']) ?></code></span>
<?php endif; ?>
<?php else : ?>
        <span class="row-note">Install it with <code>sudo offline apps add <?= e($app['id']) ?></code></span>
<?php endif; ?>
      </span>
      <span class="row-meta">
<?php if ($isRunning && $url !== null) : ?>
        <a class="btn btn-sm btn-outline-primary" href="<?= e($url) ?>">Open</a><br>
<?php elseif ($isRunning) : ?>
        <span class="is-ok"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> running</span><br>
<?php elseif ($state['installed'] ?? false) : ?>
        stopped<br>
<?php else : ?>
        not installed<br>
<?php endif; ?>
        <?= ($state['installed'] ?? false) ? (($state['boot'] ?? false) ? 'starts at boot' : 'starts on request') : e('up to ' . strtoupper($app['memory']) . ' of memory') ?>
      </span>
    </li>
<?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>

<section aria-labelledby="h-appnotes">
  <h2 id="h-appnotes">Good to know</h2>
  <ul class="plain">
    <li>Addresses beginning <code>https://</code> use a certificate this system made for itself, so a browser shows a warning the first time. Accept it once per device.</li>
    <li>Heavier apps start on request to save memory and battery. <code>sudo offline apps enable NAME</code> starts one at every boot; <code>disable</code> reverses that.</li>
    <li>Removing an app keeps its data in <code>/srv/offline/apps</code>. App data stays on this system and is not copied to the others.</li>
  </ul>
</section>
<?php page_end();
