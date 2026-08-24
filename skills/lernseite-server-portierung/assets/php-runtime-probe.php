<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');

echo json_encode(['ok' => true, 'phpExecuted' => true], JSON_UNESCAPED_SLASHES);

