<?php
// ============================================================
// api/cleanup.php  —  Cron Job alternatyva MySQL Event Scheduler
//
// Only UNCONFIRMED sensors (confirmed=0) are deleted.
// Confirmed sensors and their history are NEVER deleted.
//
// Hostinger → Advanced → Cron Jobs:
//   Frequency:  * * * * *  (every minute)
//   Komanda: php /home/u123456/public_html/api/cleanup.php
//
// Or via HTTP:
//   wget -q -O /dev/null "https://yourdomain.lt/api/cleanup.php?key=CRON_SECRET"
// ============================================================

$isCli    = PHP_SAPI === 'cli';
$secret   = 'REPLACE_WITH_CRON_SECRET_KEY';
$provided = $_GET['key'] ?? '';

if (!$isCli && !hash_equals($secret, $provided)) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../includes/config.php';

try {
    // Delete ONLY unconfirmed sensors (sent no record within 3 min).
    // Confirmed sensors (confirmed=1) and their readings are NEVER deleted
    // automatically — historical data must persist even when a sensor goes silent.
    $stmt = db()->prepare("
        DELETE FROM sensors
        WHERE confirmed = 0
          AND registered_at < DATE_SUB(NOW(), INTERVAL :mins MINUTE)
    ");
    $stmt->execute([':mins' => SENSOR_TIMEOUT_MIN]);
    $deleted = $stmt->rowCount();

    // Optionally: clean old readings per DATA_RETENTION_DAYS.
    // 0 = unlimited (nothing deleted). The sensors themselves remain —
    // only readings older than N days are deleted.
    $purged = 0;
    $retention = defined('DATA_RETENTION_DAYS') ? (int)DATA_RETENTION_DAYS : 0;
    if ($retention > 0) {
        $rStmt = db()->prepare("
            DELETE FROM readings
            WHERE recorded_at < DATE_SUB(NOW(), INTERVAL :days DAY)
        ");
        $rStmt->execute([':days' => $retention]);
        $purged = $rStmt->rowCount();
    }

    $msg = date('Y-m-d H:i:s')
         . " — cleanup OK: nepatvirtinti ištrinti={$deleted}, seni rodmenys ištrinti={$purged}\n";

    if ($isCli) {
        echo $msg;
    } else {
        header('Content-Type: text/plain');
        echo $msg;
    }

} catch (Throwable $e) {
    $err = date('Y-m-d H:i:s') . " — cleanup KLAIDA: " . $e->getMessage() . "\n";
    if ($isCli) { fwrite(STDERR, $err); } else { http_response_code(500); echo $err; }
    exit(1);
}
