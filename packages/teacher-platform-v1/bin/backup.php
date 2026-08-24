<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use ReligionPlatform\Config;

$sourcePath = (string)Config::get('database');
$backupDirectory = (string)Config::get('backup_dir', '/var/backups/teacher-platform');
$retention = max(3, min(120, (int)Config::get('backup_retention_days', 30)));

if (!is_file($sourcePath)) throw new RuntimeException('Die Plattformdatenbank wurde nicht gefunden.');
if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Das Backupverzeichnis konnte nicht angelegt werden.');
}

umask(0077);
$stamp = gmdate('Ymd-His');
$finalPath = $backupDirectory . '/platform-' . $stamp . '.sqlite';
$temporaryPath = $finalPath . '.partial-' . bin2hex(random_bytes(4));

$source = new SQLite3($sourcePath, SQLITE3_OPEN_READONLY);
$destination = new SQLite3($temporaryPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
try {
    if (!$source->backup($destination)) throw new RuntimeException('SQLite konnte kein konsistentes Backup erzeugen.');
    $check = $destination->querySingle('PRAGMA integrity_check');
    if ($check !== 'ok') throw new RuntimeException('Die Integritätsprüfung des Backups ist fehlgeschlagen.');
} finally {
    $destination->close();
    $source->close();
}

if (!rename($temporaryPath, $finalPath)) {
    @unlink($temporaryPath);
    throw new RuntimeException('Das geprüfte Backup konnte nicht übernommen werden.');
}
chmod($finalPath, 0600);

$cutoff = time() - ($retention * 86400);
foreach (glob($backupDirectory . '/platform-*.sqlite') ?: [] as $candidate) {
    if (is_file($candidate) && filemtime($candidate) < $cutoff) unlink($candidate);
}

echo $finalPath, "\n", hash_file('sha256', $finalPath), "\n";
