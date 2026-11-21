<?php
/**
 * The children's shelf: the library titles chosen for children, and nothing
 * else. They open in the children's reader, whose index holds only these.
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$adult = portal_is_adult();
$shelf = array_values(array_filter(portal_status()['knowledge']['zims'] ?? [], static fn (array $z): bool => Offline\is_children_title((string) ($z['base'] ?? ''))));

page_start('Read', $adult ? 'library' : 'read');
?>
<section class="page-head">
  <h1><?= $adult ? 'The children\'s shelf' : 'Read and find out' ?></h1>
<?php if ($adult) : ?>
  <p class="hero-sub">These are the library titles children can open without signing in. A title is here only if it is listed in <code>children.tsv</code>; add your own choices to <code>/etc/offline/children.local.tsv</code> and run <code>sudo offline library</code>.</p>
<?php elseif ($shelf !== []) : ?>
  <p class="hero-sub">Choose a book. You can search inside each one.</p>
<?php endif; ?>
</section>

<?php if ($shelf === []) : ?>
<p class="empty"><?= $adult ? 'Nothing yet. Fetch the starter set with <code>sudo offline get children</code>.' : 'There are no books here yet. Ask a grown-up to add some.' ?></p>
<?php else : ?>
<ul class="shelf">
<?php foreach ($shelf as $zim) : ?>
  <li>
<?php if ($zim['indexed'] ?? false) : ?>
    <a href="<?= e(reader_url((string) $zim['file'], false)) ?>">
<?php else : ?>
    <div>
<?php endif; ?>
      <i class="fa-solid fa-book fa-fw shelf-icon" aria-hidden="true"></i>
      <span class="shelf-title"><?= e(($zim['title'] ?? '') !== '' ? $zim['title'] : $zim['base']) ?></span>
      <span class="shelf-line"><?= e(($zim['indexed'] ?? false) ? (string) ($zim['description'] ?? '') : 'Not ready yet') ?></span>
<?= ($zim['indexed'] ?? false) ? '    </a>' : '    </div>' ?>

  </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php page_end();
