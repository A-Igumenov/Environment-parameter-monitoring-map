<?php
/**
 * 5.3 Automatic DB backup (cron + mysqldump).
 *
 * Naudojimas (cron, pvz. kasdien 3:00):
 *   0 3 * * * php /path/to/api/backup.php >> /path/to/backups/backup.log 2>&1
 *
 * Or via HTTP with a secret key (if there is no CLI access):
 *   ?key=YOUR_BACKUP_KEY
 *
 * Security:
 *   - CLI: always allowed.
 *   - HTTP: reikalingas raktas, sutampantis su BACKUP_KEY config'e
 *     (if not set — HTTP access is DENIED).
 *   - Backups are stored ABOVE the webroot (if possible) or in a protected folder.
 *   - The last N copies are kept (older ones are deleted automatically).
 */

require_once __DIR__ . '/../includes/config.php';

$isCli = (php_sapi_name() === 'cli');

// HTTP prieigos autorizacija
if (!$isCli) {
    $key = $_GET['key'] ?? '';
    $expected = defined('BACKUP_KEY') ? BACKUP_KEY : '';
    if ($expected === '' || !hash_equals($expected, $key)) {
        http_response_code(403);
        exit('Forbidden. Set BACKUP_KEY in config and pass ?key=...');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// Backup folder: above the webroot if possible
$backupDir = dirname(__DIR__, 2) . '/iot_backups';
if (!is_dir($backupDir)) {
    if (!@mkdir($backupDir, 0700, true)) {
        // Fallback — alongside, protected with .htaccess
        $backupDir = __DIR__ . '/../backups';
        @mkdir($backupDir, 0700, true);
        @file_put_contents($backupDir . '/.htaccess', "Require all denied\n");
    }
}

$keepCount = 7; // laikyti paskutines 7 kopijas
$timestamp = date('Y-m-d_His');
$file = $backupDir . "/iot_db_{$timestamp}.sql.gz";

// mysqldump command (password via an environment variable, not the command line)
$cmd = sprintf(
    'mysqldump --host=%s --user=%s %s --single-transaction --skip-lock-tables 2>/dev/null | gzip > %s',
    escapeshellarg(DB_HOST),
    escapeshellarg(DB_USER),
    escapeshellarg(DB_NAME),
    escapeshellarg($file)
);

putenv('MYSQL_PWD=' . DB_PASS); // safer than -p on the command line

$start = microtime(true);
exec($cmd, $output, $code);
putenv('MYSQL_PWD'); // clean up

if ($code !== 0 || !file_exists($file) || filesize($file) === 0) {
    @unlink($file);
    $msg = date('Y-m-d H:i:s') . " — BACKUP FAILED (code {$code}). Patikrinkite ar mysqldump prieinamas.\n";
    echo $msg;
    if (!$isCli) http_response_code(500);
    exit(1);
}

// Rotation: keep only the last $keepCount copies
$backups = glob($backupDir . '/iot_db_*.sql.gz');
if ($backups !== false && count($backups) > $keepCount) {
    usort($backups, fn($a, $b) => filemtime($a) <=> filemtime($b));
    foreach (array_slice($backups, 0, count($backups) - $keepCount) as $old) {
        @unlink($old);
    }
}

$sizeMb = round(filesize($file) / 1048576, 2);
$secs = round(microtime(true) - $start, 1);
echo date('Y-m-d H:i:s') . " — BACKUP OK: " . basename($file)
   . " ({$sizeMb} MB, {$secs}s, laikoma {$keepCount} kopijų)\n";
