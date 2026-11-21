<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$s = portal_status();
$games = $s['games'] ?? ['desktop' => [], 'worlds' => [], 'mineclonia' => null, 'activities' => null];
$host = portal_host();
$name = (string) ($s['host']['name'] ?? '');
$hosted = array_values(array_filter($games['worlds'] ?? [], static fn (array $w): bool => (bool) ($w['hosted'] ?? false)));
$installed = array_values(array_filter($games['desktop'] ?? [], static fn (array $g): bool => (bool) ($g['installed'] ?? false)));
$servers = $s['servers'] ?? [];

page_start('Games', 'games');
?>
<section class="page-head">
  <h1>Games</h1>
  <p class="hero-sub">Things to play and learn with when there is no internet: worlds the family builds together, games on this computer, and activities for a tablet. Nothing here talks to anyone outside the house.</p>
</section>

<section aria-labelledby="h-worlds">
  <h2 id="h-worlds">Family worlds</h2>
  <p>The family worlds are Luanti with the Mineclonia game, a block-building world in the style of Minecraft. Everyone who joins the same world sees the same place and can build in it together.</p>
<?php if ($hosted !== []) : ?>
  <ul class="rows">
<?php foreach ($hosted as $world) :
    $running = ($world['state'] ?? '') === 'active';
    ?>
    <li>
      <span class="row-main">
        <span class="row-title"><?= e($world['title'] ?? '') ?></span>
        <span class="row-note"><?= e($world['about'] ?? '') ?></span>
      </span>
      <span class="row-meta <?= $running ? 'is-ok' : 'is-off' ?>"><i class="fa-solid <?= $running ? 'fa-circle-check' : 'fa-circle-minus' ?>" aria-hidden="true"></i> <?= $running ? 'open' : 'closed' ?><br>address <code><?= e($name !== '' ? $name . '.local' : $host) ?></code>, port <code><?= (int) ($world['port'] ?? 0) ?></code></span>
    </li>
<?php endforeach; ?>
  </ul>
<?php else : ?>
  <p class="empty">No world is hosted on this system.<?php if ($servers !== []) : ?> Try the address of a central server: <?= implode(', ', array_map(static fn (string $h): string => '<code>' . e(explode(':', $h)[0]) . '</code>', $servers)) ?>, port <code>30000</code> for creative building or <code>30001</code> for gentle survival.<?php else : ?> An adult can host one here with <code>sudo offline games host</code>.<?php endif; ?></p>
<?php endif; ?>
  <h3>How to join</h3>
  <ol class="plain">
    <li>Open <strong>Luanti</strong> from the applications menu and choose <strong>Join Game</strong>.</li>
    <li>Type the address and the port of the world.</li>
    <li>Choose a name and a password of your own. The world remembers both, so use the same ones next time.</li>
  </ol>
  <p class="row-note">An adult should join each world first under the name <code>parent</code>: that name is the world's administrator. Worlds are reachable from the home network only and are never listed publicly.</p>
</section>

<section aria-labelledby="h-desktop">
  <h2 id="h-desktop">On this computer</h2>
<?php if ($installed === []) : ?>
  <p class="empty">No games are installed on this system. An adult can add them with <code>sudo offline games install</code>.</p>
<?php else : ?>
  <p>Open these from the applications menu.</p>
  <ul class="rows">
<?php foreach ($installed as $game) : ?>
    <li>
      <span class="row-main">
        <span class="row-title"><?= e($game['title'] ?? '') ?></span>
        <span class="row-note"><?= e($game['about'] ?? '') ?></span>
      </span>
      <span class="row-meta">ages <?= e($game['for'] ?? '') ?></span>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>

<section aria-labelledby="h-tablet">
  <h2 id="h-tablet">On a tablet</h2>
<?php if (($games['activities'] ?? null) !== null) : ?>
  <p>Sugarizer is a set of about sixty activities that run in a browser: painting, memory games, mazes, chess, a calculator, and blocks for learning to program. It works on an iPad, a telephone or a computer, and keeps what a child makes on that device.</p>
  <p><a class="btn btn-primary" href="http://<?= e($host) ?>:<?= (int) $games['activities']['port'] ?>/" rel="noopener"><i class="fa-solid fa-shapes" aria-hidden="true"></i> Open the activities</a></p>
<?php else : ?>
  <p class="empty">The browser activities are not installed on this system. An adult can add them with <code>sudo offline games activities</code>.</p>
<?php endif; ?>
  <p class="row-note">The <a href="/apps.php">Kolibri</a> app holds lessons and exercises from Khan Academy and others, and also runs in a tablet's browser.</p>
</section>
<?php page_end();
