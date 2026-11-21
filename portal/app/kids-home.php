<?php
/**
 * The children's front page: what anyone sees who has not signed in.
 * Included by index.php. Five doors, each to something made for children.
 */
declare(strict_types=1);

$s = portal_status();
$shelf = count(array_filter($s['knowledge']['zims'] ?? [], static fn (array $z): bool => Offline\is_children_title((string) ($z['base'] ?? ''))));
$watch = Offline\tree_stats(Offline\path('media') . '/children', 5000)['files'] ?? 0;
$games = $s['games'] ?? [];
$installed = count(array_filter($games['desktop'] ?? [], static fn (array $g): bool => (bool) ($g['installed'] ?? false)));
$worlds = count(array_filter($games['worlds'] ?? [], static fn (array $w): bool => (bool) ($w['hosted'] ?? false)));
$activities = $games['activities'] ?? null;
$maps = count($s['maps']['regions'] ?? []);
$host = portal_host();

$doors = [
    ['play', 'Play', 'fa-gamepad', '/games.php', match (true) {
        $installed > 0 && $worlds > 0 => "$installed games, and worlds to build in together",
        $installed > 0 => "$installed " . ($installed === 1 ? 'game' : 'games') . ' on this computer',
        $worlds > 0 => 'Worlds to build in together',
        default => 'See how to join the family worlds',
    }],
    ['watch', 'Watch', 'fa-film', '/media.php', $watch > 0 ? "$watch " . ($watch === 1 ? 'film or song' : 'films and songs') : 'Nothing here yet'],
    ['read', 'Read', 'fa-book-open', '/read.php', $shelf > 0 ? "$shelf " . ($shelf === 1 ? 'book of things to find out' : 'books of things to find out') : 'Nothing here yet'],
    ['make', 'Make', 'fa-shapes', $activities !== null ? 'http://' . $host . ':' . (int) $activities['port'] . '/' : null, $activities !== null ? 'Paint, puzzle, count and code' : 'Not set up yet'],
    ['maps', 'Maps', 'fa-map-location-dot', '/maps.php', $maps > 0 ? 'Find a place and see how to get there' : 'A map of the whole world'],
];

page_start('Home', 'index');
?>
<section class="kids-hello">
  <h1>What would you like to do?</h1>
</section>

<ul class="doors">
<?php foreach ($doors as [$id, $label, $icon, $href, $line]) : ?>
  <li class="door door-<?= e($id) ?><?= $href === null ? ' door-closed' : '' ?>">
<?php if ($href !== null) : ?>
    <a href="<?= e($href) ?>">
<?php else : ?>
    <div>
<?php endif; ?>
      <span class="door-label"><?= e($label) ?></span>
      <span class="door-line"><?= e($line) ?></span>
      <i class="fa-solid <?= e($icon) ?> door-icon" aria-hidden="true"></i>
<?= $href !== null ? '    </a>' : '    </div>' ?>

  </li>
<?php endforeach; ?>
</ul>
<?php page_end();
