<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$dir = Offline\path('data');
$conditions = array_column(Offline\read_json("$dir/conditions.json")['conditions'] ?? [], null, 'slug');
$sets = ['Home measures' => Offline\read_json("$dir/home_remedies.json"), 'Herbs and natural products' => Offline\read_json("$dir/natural_remedies.json")];
$only = (string) ($_GET['c'] ?? '');
$only = isset($conditions[$only]) ? $only : '';
$byCategory = [];
foreach ($conditions as $slug => $condition) {
    $byCategory[(string) ($condition['category'] ?? 'Other')][$slug] = (string) ($condition['label'] ?? $slug);
}

page_start('Remedies', 'library');
?>
<section class="page-head">
  <nav aria-label="Section"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="/library.php">Library</a></li></ol></nav>
  <h1>Remedies for minor ailments</h1>
  <p class="hero-sub">Short summaries of United States government health guidance, compiled by Project NOMAD. They cover minor complaints only and are not a substitute for a clinician. For anything serious, use the medical titles in the <a href="/library.php">library</a> and seek help.</p>
</section>

<section aria-labelledby="h-cond">
  <h2 id="h-cond">By complaint</h2>
<?php foreach ($byCategory as $category => $items) : ?>
  <p class="condition-row"><span class="condition-group"><?= e($category) ?></span>
<?php foreach ($items as $slug => $label) : ?>
    <a class="<?= $slug === $only ? 'active' : '' ?>" href="/remedies.php?c=<?= e(rawurlencode((string) $slug)) ?>"><?= e($label) ?></a>
<?php endforeach; ?>
  </p>
<?php endforeach; ?>
<?php if ($only !== '') : ?>
  <p><a href="/remedies.php">Show everything</a></p>
<?php endif; ?>
</section>

<?php foreach ($sets as $heading => $set) :
    $remedies = array_values(array_filter($set['remedies'] ?? [], static fn (array $r): bool => $only === '' || in_array($only, $r['conditions'] ?? [], true)));
    if ($remedies === []) {
        continue;
    }
    ?>
<section>
  <h2><?= e($heading) ?></h2>
  <p class="row-note">Source: <?= e((string) ($set['source']['name'] ?? '')) ?>. Version <?= e((string) ($set['version'] ?? '')) ?>.</p>
<?php foreach ($remedies as $remedy) : ?>
  <details class="pack"<?= $only !== '' ? ' open' : '' ?>>
    <summary>
      <span class="pack-name"><?= e((string) ($remedy['name'] ?? '')) ?></span>
      <span class="pack-count"><?= e(implode(', ', array_map(static fn (string $c): string => (string) ($conditions[$c]['label'] ?? $c), $remedy['conditions'] ?? []))) ?></span>
    </summary>
    <dl class="facts facts-stack remedy">
<?php foreach (['uses' => 'What it is used for', 'how' => 'How', 'evidence' => 'Evidence', 'cautions' => 'Cautions'] as $key => $label) :
    $value = $remedy[$key] ?? '';
    $value = is_array($value) ? implode(' ', array_map('strval', $value)) : (string) $value;
    if ($value === '') {
        continue;
    }
    ?>
      <dt><?= e($label) ?></dt><dd><?= e($value) ?></dd>
<?php endforeach; ?>
    </dl>
  </details>
<?php endforeach; ?>
</section>
<?php endforeach; ?>
<?php page_end();
