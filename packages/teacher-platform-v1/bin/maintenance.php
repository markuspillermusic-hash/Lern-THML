<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$result = ReligionPlatform\Maintenance::run();
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
