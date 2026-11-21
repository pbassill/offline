<?php
/**
 * Place search for the map page: names, UK postcodes and coordinates, answered
 * from the index on this system. Read-only like the rest of the portal.
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
$query = (string) ($_GET['q'] ?? '');
if (strlen($query) > 200 || preg_match('/[\x00-\x1f\x7f]/', $query) === 1) {
    http_response_code(400);
    echo '{"places":[]}';
    exit;
}
$places = Offline\places_search($query, 10);
// Of several places with one name, the one nearest what the map is showing comes first.
if (preg_match('/^(-?\d{1,2}(?:\.\d{1,6})?),(-?\d{1,3}(?:\.\d{1,6})?)$/', (string) ($_GET['near'] ?? ''), $m) === 1 && count($places) > 1) {
    $first = Offline\places_key($places[0]['name']);
    $same = array_values(array_filter($places, static fn (array $p): bool => Offline\places_key($p['name']) === $first));
    $rest = array_values(array_filter($places, static fn (array $p): bool => Offline\places_key($p['name']) !== $first));
    usort($same, static fn (array $a, array $b): int => (($a['lat'] - (float) $m[1]) ** 2 + ($a['lon'] - (float) $m[2]) ** 2) <=> (($b['lat'] - (float) $m[1]) ** 2 + ($b['lon'] - (float) $m[2]) ** 2));
    $places = array_merge($same, $rest);
}
echo json_encode(['places' => $places], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP);
