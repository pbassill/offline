<?php
/**
 * Current family broadcasts, as JSON, for the banner on every page.
 * Read-only like the rest of the portal: it reports what the broadcast
 * service has already verified and written.
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['broadcasts' => portal_broadcasts()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP);
