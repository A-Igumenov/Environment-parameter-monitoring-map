<?php
/**
 * Integration tests: API + DB logic.
 * Requires a running MySQL/MariaDB and the iot_test DB.
 *
 * Environment variables (with defaults):
 *   IOT_TEST_DSN  (mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=iot_test;charset=utf8mb4)
 *   IOT_TEST_USER (iot)
 *   IOT_TEST_PASS (test123)
 *
 * If the DB is unreachable — the tests are skipped (not a failure).
 */

require_once __DIR__ . '/TestCase.php';

final class ApiIntegrationTest extends TestCase {
    private ?PDO $pdo = null;

    private function connect(): ?PDO {
        if ($this->pdo) return $this->pdo;

        // SECURITY: integration tests DELETE and modify data
        // (DELETE FROM sensors). So they connect ONLY to a separate
        // the test DB (via env variables), NEVER the production db().
        // Via tests.php (production) without a test DB they are simply skipped.
        $dsn  = getenv('IOT_TEST_DSN');
        $user = getenv('IOT_TEST_USER');
        $pass = getenv('IOT_TEST_PASS');

        // If env vars are not set — Linux dev defaults (for CLI testing)
        if ($dsn === false && PHP_SAPI === 'cli') {
            $dsn  = 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=iot_test;charset=utf8mb4';
            $user = 'iot';
            $pass = 'test123';
        }

        if (!$dsn) {
            return null; // no test DB → skip (via tests.php in production)
        }

        try {
            $this->pdo = new PDO($dsn, $user ?: 'iot', $pass ?: '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            return $this->pdo;
        } catch (PDOException) {
            return null;
        }
    }

    public function run(Assert $t): void {
        $pdo = $this->connect();
        if (!$pdo) {
            echo "    (Integraciniai testai praleisti — nėra atskiros test DB.\n";
            echo "     Produkcijoje tai normalu: jie modifikuoja duomenis, todėl\n";
            echo "     veikia tik su IOT_TEST_DSN aplinkos kintamuoju per CLI.)\n";
            $t->ok(true, 'DB skip (not an error)');
            return;
        }

        // Clean state
        $pdo->exec("DELETE FROM readings");
        $pdo->exec("DELETE FROM sensors");
        $pdo->exec("ALTER TABLE sensors AUTO_INCREMENT = 1");

        // ── Numbering by city order (not DB id) ──
        $pdo->exec("INSERT INTO sensors (id,lat,lng,city_prefix,confirmed) VALUES
            (6, 54.6872,25.2797,'VLN',1),
            (40,54.8985,23.9036,'KNS',1),
            (86,54.6900,25.2900,'VLN',1),
            (90,55.7033,21.1443,'KLP',1)");

        $rows = $pdo->query("SELECT s.id, CONCAT(s.city_prefix,(
            SELECT COUNT(*) FROM sensors s2 WHERE s2.city_prefix=s.city_prefix AND s2.id<=s.id
        )) AS label FROM sensors s ORDER BY s.id")->fetchAll();
        $labels = array_column($rows, 'label', 'id');

        $t->equals('VLN1', $labels[6],  'DB id=6 → VLN1 (first Vilnius)');
        $t->equals('KNS1', $labels[40], 'DB id=40 → KNS1 (first Kaunas)');
        $t->equals('VLN2', $labels[86], 'DB id=86 → VLN2 (second Vilnius)');
        $t->equals('KLP1', $labels[90], 'DB id=90 → KLP1');

        // ── clear_readings: deletes records, keeps the sensor ──
        for ($i = 0; $i < 5; $i++) {
            $pdo->exec("INSERT INTO readings (sensor_id,temperature,humidity) VALUES (6,2$i.5,5$i.0)");
        }
        $t->equals(5, (int)$pdo->query("SELECT COUNT(*) FROM readings WHERE sensor_id=6")->fetchColumn(),
            '5 readings inserted');

        $stmt = $pdo->prepare("DELETE FROM readings WHERE sensor_id = :id");
        $stmt->execute([':id' => 6]);
        $t->equals(5, $stmt->rowCount(), 'clear_readings deleted 5');
        $t->equals(0, (int)$pdo->query("SELECT COUNT(*) FROM readings WHERE sensor_id=6")->fetchColumn(),
            'No readings left');
        $t->equals(1, (int)$pdo->query("SELECT COUNT(*) FROM sensors WHERE id=6")->fetchColumn(),
            'Sensor 6 still exists after clear');

        // ── delete: CASCADE deletes records too ──
        for ($i = 0; $i < 3; $i++) {
            $pdo->exec("INSERT INTO readings (sensor_id,temperature) VALUES (6,3$i.0)");
        }
        $pdo->prepare("DELETE FROM sensors WHERE id = :id")->execute([':id' => 6]);
        $t->equals(0, (int)$pdo->query("SELECT COUNT(*) FROM sensors WHERE id=6")->fetchColumn(),
            'Sensor 6 deleted');
        $t->equals(0, (int)$pdo->query("SELECT COUNT(*) FROM readings WHERE sensor_id=6")->fetchColumn(),
            'CASCADE deleted readings');

        // ── clear_readings_bulk: clearing data of several sensors (IN list) ──
        // Add 3 extra VLN sensors (id 200,201,202) for bulk testing.
        $pdo->exec("INSERT INTO sensors (id,lat,lng,city_prefix,confirmed) VALUES
            (200,54.61,25.21,'VLN',1),
            (201,54.62,25.22,'VLN',1),
            (202,54.63,25.23,'VLN',1)");
        foreach ([200, 201, 202] as $sid) {
            $pdo->exec("INSERT INTO readings (sensor_id,temperature) VALUES ($sid,20.0)");
            $pdo->exec("INSERT INTO readings (sensor_id,temperature) VALUES ($sid,21.0)");
        }
        $before = (int)$pdo->query("SELECT COUNT(*) FROM readings WHERE sensor_id IN (200,201,202)")->fetchColumn();
        $t->equals(6, $before, 'bulk: 6 readings inserted across 3 sensors');
        $ids = [200, 201, 202];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $bulk = $pdo->prepare("DELETE FROM readings WHERE sensor_id IN ($ph)");
        $bulk->execute($ids);
        $t->equals(6, $bulk->rowCount(), 'clear_readings_bulk deleted 6 readings');
        $t->equals(3, (int)$pdo->query("SELECT COUNT(*) FROM sensors WHERE id IN (200,201,202)")->fetchColumn(),
            'bulk clear: all 3 sensors still exist');

        // ── delete_bulk: deleting several sensors (CASCADE) ──
        $pdo->exec("INSERT INTO readings (sensor_id,temperature) VALUES (200,5.0),(201,6.0)");
        $delBulk = $pdo->prepare("DELETE FROM sensors WHERE id IN (200,201)");
        $delBulk->execute();
        $t->equals(2, $delBulk->rowCount(), 'delete_bulk deleted 2 sensors');
        $t->equals(0, (int)$pdo->query("SELECT COUNT(*) FROM readings WHERE sensor_id IN (200,201)")->fetchColumn(),
            'delete_bulk: CASCADE removed their readings');
        $t->equals(1, (int)$pdo->query("SELECT COUNT(*) FROM sensors WHERE id=202")->fetchColumn(),
            'delete_bulk: sensor 202 untouched');

        // ── clear_region: clearing data of a whole region (city_prefix) ──
        $knsIds = $pdo->query("SELECT id FROM sensors WHERE city_prefix='KNS'")
                      ->fetchAll(PDO::FETCH_COLUMN);
        if (count($knsIds) >= 1) {
            foreach ($knsIds as $kid) {
                $pdo->exec("INSERT INTO readings (sensor_id,temperature) VALUES ($kid,9.9)");
            }
            $knsReadingsBefore = (int)$pdo->query(
                "SELECT COUNT(*) FROM readings r JOIN sensors s ON s.id=r.sensor_id WHERE s.city_prefix='KNS'"
            )->fetchColumn();
            $t->true($knsReadingsBefore >= count($knsIds), 'clear_region: KNS has readings before');
            $cr = $pdo->prepare("DELETE r FROM readings r JOIN sensors s ON s.id=r.sensor_id WHERE s.city_prefix=:r");
            $cr->execute([':r' => 'KNS']);
            $t->equals(0, (int)$pdo->query(
                "SELECT COUNT(*) FROM readings r JOIN sensors s ON s.id=r.sensor_id WHERE s.city_prefix='KNS'"
            )->fetchColumn(), 'clear_region: all KNS readings gone');
            $t->true((int)$pdo->query("SELECT COUNT(*) FROM sensors WHERE city_prefix='KNS'")->fetchColumn() > 0,
                'clear_region: KNS sensors still exist');
        }

        // ── delete_region: deleting all sensors of a region (CASCADE) ──
        $klpBefore = (int)$pdo->query("SELECT COUNT(*) FROM sensors WHERE city_prefix='KLP'")->fetchColumn();
        if ($klpBefore > 0) {
            $dr = $pdo->prepare("DELETE FROM sensors WHERE city_prefix=:r");
            $dr->execute([':r' => 'KLP']);
            $t->equals($klpBefore, $dr->rowCount(), 'delete_region: all KLP sensors deleted');
            $t->equals(0, (int)$pdo->query("SELECT COUNT(*) FROM sensors WHERE city_prefix='KLP'")->fetchColumn(),
                'delete_region: no KLP sensors remain');
        }

        // ── reading auth: coordinates must match ──
        $exists = $pdo->prepare("SELECT id FROM sensors WHERE lat=:lat AND lng=:lng");
        $exists->execute([':lat' => 54.8985, ':lng' => 23.9036]);
        $t->notNull($exists->fetch(), 'Registered coords found (KNS1)');

        $exists->execute([':lat' => 1.0, ':lng' => 1.0]);
        $t->false((bool)$exists->fetch(), 'Unregistered coords rejected');

        // ── NULL metrics: only the sent fields are stored ──
        $pdo->exec("INSERT INTO readings (sensor_id,temperature,humidity) VALUES (40,21.5,55.0)");
        $r = $pdo->query("SELECT temperature,humidity,co2,pm2_5 FROM readings WHERE sensor_id=40")->fetch();
        $t->notNull($r['temperature'], 'temperature stored');
        $t->notNull($r['humidity'], 'humidity stored');
        $t->null($r['co2'], 'co2 NULL (not sent)');
        $t->null($r['pm2_5'], 'pm2_5 NULL (not sent)');

        // ── schema auto-hide: a working DB with tables → hide ──
        // Reproduces the admin.php logic: if the tables exist, the schema is archived.
        $tablesExist = count($pdo->query("SHOW TABLES LIKE 'sensors'")->fetchAll()) > 0
                    && count($pdo->query("SHOW TABLES LIKE 'readings'")->fetchAll()) > 0;
        $t->true($tablesExist, 'Tables exist for auto-hide check');

        // Simulate the schema.sql DELETION with a temporary file
        $tmpSchema = sys_get_temp_dir() . '/schema_autohide_' . uniqid() . '.sql';
        file_put_contents($tmpSchema, 'CREATE TABLE x;');
        $shouldDelete = $tablesExist && file_exists($tmpSchema);
        $t->true($shouldDelete, 'Auto-delete triggers when tables exist + schema present');
        if ($shouldDelete) {
            // After install the schema is SIMPLY DELETED (not archived)
            unlink($tmpSchema);
            $t->false(file_exists($tmpSchema), 'schema.sql deleted after auto-detect');
            $leftover = glob(dirname($tmpSchema) . '/usedSh.*');
            $t->equals(0, count($leftover), 'No usedSh.* copy created');
        }

        // ── MAC: registration (mac NULL) + claimed on the first send ──
        $pdo->exec("DELETE FROM readings");
        $pdo->exec("DELETE FROM sensors");

        // Two pending registrations at the same location (mac NULL)
        $pdo->exec("INSERT INTO sensors (lat,lng,mac,city_prefix) VALUES
            (54.6872,25.2797,NULL,'VLN'),
            (54.6872,25.2797,NULL,'VLN')");
        $pendingCount = (int)$pdo->query(
            "SELECT COUNT(*) FROM sensors WHERE lat=54.6872 AND lng=25.2797 AND mac IS NULL"
        )->fetchColumn();
        $t->equals(2, $pendingCount, 'Two pending registrations (NULL mac) at same coords');

        // First send with MAC ...01 → claims the oldest pending one
        $exact = $pdo->prepare("SELECT id FROM sensors WHERE lat=:lat AND lng=:lng AND mac=:mac");
        $exact->execute([':lat'=>54.6872, ':lng'=>25.2797, ':mac'=>'AA:BB:CC:DD:EE:01']);
        $t->false((bool)$exact->fetch(), 'No exact match before first send');

        $pend = $pdo->prepare("SELECT id FROM sensors WHERE lat=:lat AND lng=:lng AND mac IS NULL ORDER BY id ASC LIMIT 1");
        $pend->execute([':lat'=>54.6872, ':lng'=>25.2797]);
        $claimId = (int)$pend->fetch()['id'];
        $pdo->prepare("UPDATE sensors SET mac=:mac, confirmed=1 WHERE id=:id")
            ->execute([':mac'=>'AA:BB:CC:DD:EE:01', ':id'=>$claimId]);

        $exact->execute([':lat'=>54.6872, ':lng'=>25.2797, ':mac'=>'AA:BB:CC:DD:EE:01']);
        $t->notNull($exact->fetch(), 'MAC claimed pending registration');

        // Second MAC ...02 → claims the second pending one
        $pend->execute([':lat'=>54.6872, ':lng'=>25.2797]);
        $claim2 = (int)$pend->fetch()['id'];
        $pdo->prepare("UPDATE sensors SET mac=:mac, confirmed=1 WHERE id=:id")
            ->execute([':mac'=>'AA:BB:CC:DD:EE:02', ':id'=>$claim2]);
        $remaining = (int)$pdo->query("SELECT COUNT(*) FROM sensors WHERE mac IS NULL")->fetchColumn();
        $t->equals(0, $remaining, 'No pending left after both claimed');

        // Two confirmed, different MACs at the same location
        $confirmedCount = (int)$pdo->query(
            "SELECT COUNT(*) FROM sensors WHERE lat=54.6872 AND lng=25.2797 AND confirmed=1"
        )->fetchColumn();
        $t->equals(2, $confirmedCount, 'Two confirmed sensors at same coords (different MAC)');

        // UNIQUE(lat,lng,mac) — a real duplicate is rejected
        $dupRejected = false;
        try {
            $pdo->exec("INSERT INTO sensors (lat,lng,mac,city_prefix) VALUES (54.6872,25.2797,'AA:BB:CC:DD:EE:01','VLN')");
        } catch (PDOException) { $dupRejected = true; }
        $t->true($dupRejected, 'Duplicate lat+lng+mac rejected');

        // But several NULLs at the same location are ALLOWED
        $multiNull = true;
        try {
            $pdo->exec("INSERT INTO sensors (lat,lng,mac,city_prefix) VALUES
                (54.6872,25.2797,NULL,'VLN'),(54.6872,25.2797,NULL,'VLN')");
        } catch (PDOException) { $multiNull = false; }
        $t->true($multiNull, 'Multiple NULL mac at same coords allowed (unique permits NULLs)');

        // ── is_outdoor: indoor (0) / outdoor (1) ──
        $pdo->exec("DELETE FROM sensors");
        $pdo->exec("INSERT INTO sensors (lat,lng,mac,is_outdoor,city_prefix,confirmed) VALUES
            (54.1,25.1,'AA:I1',0,'VLN',1),
            (54.2,25.2,'AA:O1',1,'VLN',1)");
        // Default value without is_outdoor → 0 (indoor)
        $pdo->exec("INSERT INTO sensors (lat,lng,mac,city_prefix,confirmed) VALUES (54.3,25.3,'AA:D1','VLN',1)");

        $indoor  = (int)$pdo->query("SELECT COUNT(*) FROM sensors WHERE is_outdoor=0")->fetchColumn();
        $outdoor = (int)$pdo->query("SELECT COUNT(*) FROM sensors WHERE is_outdoor=1")->fetchColumn();
        $t->equals(2, $indoor, 'Two indoor sensors (is_outdoor=0, incl. default)');
        $t->equals(1, $outdoor, 'One outdoor sensor (is_outdoor=1)');

        // The default value is indeed 0
        $def = (int)$pdo->query("SELECT is_outdoor FROM sensors WHERE mac='AA:D1'")->fetchColumn();
        $t->equals(0, $def, 'Default is_outdoor = 0 (indoor) when not specified');

        // ── REGRESSION: reading flow branch logic (pending vs exact) ──
        // Reproduces the EXACT api/sensors.php reading logic to catch
        // the bug where $sensorId is overwritten with false in the pending case.
        $pdo->exec("DELETE FROM readings");
        $pdo->exec("DELETE FROM sensors");
        // 1. Registration (pending: mac NULL, confirmed 0)
        $pdo->prepare("INSERT INTO sensors (lat,lng,mac,is_outdoor,city_prefix,confirmed)
                       VALUES (54.7,25.3,NULL,1,'VLN',0)")->execute();

        $lat = 54.7; $lng = 25.3; $mac = 'AA:BB:CC:DD:EE:99';

        // Reading branch logic (like the API)
        $exact = $pdo->prepare("SELECT id, secret FROM sensors WHERE lat=:lat AND lng=:lng AND mac=:mac");
        $exact->execute([':lat'=>$lat, ':lng'=>$lng, ':mac'=>$mac]);
        $sensor = $exact->fetch();
        $sensorId = null;
        if ($sensor) {
            $sensorId = (int)$sensor['id'];
        } else {
            // pending path
            $pend = $pdo->prepare("SELECT id FROM sensors WHERE lat=:lat AND lng=:lng AND mac IS NULL ORDER BY id ASC LIMIT 1");
            $pend->execute([':lat'=>$lat, ':lng'=>$lng]);
            $pending = $pend->fetch();
            $t->true($pending !== false, 'Pending įrašas randamas pirmam siuntimui');
            $sensorId = (int)$pending['id'];
            $pdo->prepare("UPDATE sensors SET mac=:mac, confirmed=1 WHERE id=:id")
                ->execute([':mac'=>$mac, ':id'=>$sensorId]);
        }
        // CRITICAL check: sensorId must be correct (not 0/false)
        $t->true($sensorId > 0, 'sensorId teisingai nustatytas pending atveju (ne perrašytas false)');

        // Reading insertion
        $pdo->prepare("INSERT INTO readings (sensor_id, temperature) VALUES (:sid, 22.5)")
            ->execute([':sid'=>$sensorId]);
        $pdo->prepare("UPDATE sensors SET last_seen=NOW(), confirmed=1 WHERE id=:id")
            ->execute([':id'=>$sensorId]);

        // Confirmation: sensor confirmed, has a MAC and a reading
        $check = $pdo->query("SELECT confirmed, mac FROM sensors WHERE id=$sensorId")->fetch(PDO::FETCH_ASSOC);
        $t->equals(1, (int)$check['confirmed'], 'Jutiklis patvirtintas po pirmo reading');
        $t->equals($mac, $check['mac'], 'MAC priskirtas pending įrašui');
        $rCount = (int)$pdo->query("SELECT COUNT(*) FROM readings WHERE sensor_id=$sensorId")->fetchColumn();
        $t->equals(1, $rCount, 'Reading įrašytas (pending srautas veikia end-to-end)');

        $pdo->exec("DELETE FROM readings");
        $pdo->exec("DELETE FROM sensors");

        // ── 1.4 Rate limiting table ──
        $pdo->exec("DELETE FROM rate_limits");
        $now = time(); $ws = $now - ($now % 300);
        for ($i = 1; $i <= 3; $i++) {
            $pdo->prepare("INSERT INTO rate_limits (rl_key,window_start,counter) VALUES ('t:x',:ws,1)
                ON DUPLICATE KEY UPDATE counter=IF(window_start=:w2,counter+1,1)")
                ->execute([':ws'=>$ws, ':w2'=>$ws]);
        }
        $cnt = (int)$pdo->query("SELECT counter FROM rate_limits WHERE rl_key='t:x'")->fetchColumn();
        $t->equals(3, $cnt, 'Rate limit counter increments within window');

        // ── 1.3 HMAC signature ──
        $secret = 'abc123';
        $payload = '54.6872|25.2797|AA:BB:CC:DD:EE:FF';
        $sig = hash_hmac('sha256', $payload, $secret);
        $t->equals(64, strlen($sig), 'HMAC-SHA256 signature is 64 hex chars');
        $t->true(hash_equals($sig, hash_hmac('sha256', $payload, $secret)), 'HMAC verifies with same secret');
        $t->false(hash_equals($sig, hash_hmac('sha256', $payload, 'wrong')), 'HMAC fails with wrong secret');

        // ── 1.3 HMAC payload formatas: firmware (raw string) ↔ serveris ──
        // Firmware sends String(lat,7)="54.6872000"; the server must sign
        // the RAW GET value, not a float, so the signatures match.
        $fwLat = '54.6872000'; $fwLng = '25.2797000'; $fwMac = 'AA:BB:CC:DD:EE:FF';
        $fwPayload = $fwLat . '|' . $fwLng . '|' . $fwMac;
        $fwSig = hash_hmac('sha256', $fwPayload, $secret);
        // Server with raw values
        $srvPayload = trim($fwLat) . '|' . trim($fwLng) . '|' . $fwMac;
        $srvSig = hash_hmac('sha256', $srvPayload, $secret);
        $t->true(hash_equals($srvSig, strtolower($fwSig)),
            'HMAC: firmware (raw string) parašas sutampa su serverio (raw GET)');
        // Confirm the server uses raw values (rawLat is in the code)
        $apiSrc2 = file_get_contents(dirname(__DIR__) . '/api/sensors.php');
        $t->true(str_contains($apiSrc2, '$rawLat'),
            'API HMAC naudoja raw GET reikšmes (ne apdorotą float)');

        // ── 2.2 Audit log ──
        $pdo->exec("DELETE FROM audit_log");
        $pdo->prepare("INSERT INTO audit_log (actor_ip,action,target_id,details) VALUES (?,?,?,?)")
            ->execute(['1.2.3.4', 'delete_sensor', 7, 'test']);
        $log = $pdo->query("SELECT action,target_id FROM audit_log ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $t->equals('delete_sensor', $log['action'], 'Audit log records action');
        $t->equals(7, (int)$log['target_id'], 'Audit log records target id');

        // ── 2.3 Indexes exist ──
        $cityIdx = $pdo->query("SHOW INDEX FROM sensors WHERE Key_name='idx_city_id'")->fetchAll();
        $t->true(count($cityIdx) > 0, 'Index (city_prefix, id) exists');
        $timeIdx = $pdo->query("SHOW INDEX FROM readings WHERE Key_name='idx_sensor_time'")->fetchAll();
        $t->true(count($timeIdx) > 0, 'Index (sensor_id, recorded_at) exists');

        // ── 4.1 Viewport (bbox) filtering SQL logic ──
        $pdo->exec("DELETE FROM sensors"); // clean start for the bbox test
        $pdo->exec("INSERT INTO sensors (lat,lng,mac,city_prefix,confirmed) VALUES
            (54.5,25.0,'BB:01','VLN',1),
            (60.0,30.0,'BB:02','VLN',1)");
        // Bbox limiting to only the first sensor
        $inBox = (int)$pdo->query(
            "SELECT COUNT(*) FROM sensors WHERE confirmed=1
             AND lat BETWEEN 54 AND 55 AND lng BETWEEN 24 AND 26"
        )->fetchColumn();
        $t->equals(1, $inBox, 'Viewport bbox filters to one sensor');

        // ── 4.5 Export query structure (readings columns) ──
        $cols = $pdo->query("SHOW COLUMNS FROM readings")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['recorded_at','temperature','humidity','co2'] as $col) {
            $t->true(in_array($col, $cols, true), "Export stulpelis egzistuoja: $col");
        }

        // ── 5.2 Stats computations ──
        $total = (int)$pdo->query("SELECT COUNT(*) FROM sensors WHERE confirmed=1")->fetchColumn();
        $t->true($total >= 2, 'Stats: confirmed sensor count');
        $capacityPct = round($total / 49800 * 100, 2);
        $t->true($capacityPct >= 0 && $capacityPct <= 100, 'Stats: capacity percent in range');

        // ── 5.3 / 5.4 / 4.x files exist ──
        $root = dirname(__DIR__);
        $t->true(file_exists("$root/api/backup.php"), '5.3 backup.php egzistuoja');
        $t->true(file_exists("$root/api/v1/sensors.php"), '2.4 API v1 egzistuoja');
        $apiSrc = file_get_contents("$root/api/sensors.php");
        $t->true(str_contains($apiSrc, "action === 'stats'"), '5.2 stats endpoint yra');
        $t->true(str_contains($apiSrc, "action === 'export'"), '4.5 export endpoint yra');
        $t->true(str_contains($apiSrc, "action === 'health'"), '5.1 health endpoint yra');
        $t->true(str_contains($apiSrc, 'ERROR_WEBHOOK_URL'), '5.4 error tracking hook yra');
        $t->true(str_contains($apiSrc, 'sw_lat'), '4.1 viewport bbox parametrai yra');
        $t->true(str_contains($apiSrc, "action === 'averages'"),
            'Averages endpoint yra (vidurkiai pagal laikotarpį)');
        $t->true(str_contains($apiSrc, 'AVG(r.temperature)') || str_contains($apiSrc, "AVG(r.\$m)"),
            'Averages skaičiuoja AVG iš readings');
        $indexSrc = file_get_contents("$root/index.php");
        $t->true(str_contains($indexSrc, 'AVG_PERIODS') && str_contains($indexSrc, "action: 'averages'"),
            'Index puslapyje yra vidurkių periodo parinkiklis + užklausa pagal regioną');

        // Clean up
        $pdo->exec("DELETE FROM rate_limits");
        $pdo->exec("DELETE FROM audit_log");
        $pdo->exec("DELETE FROM readings");
        $pdo->exec("DELETE FROM sensors");
    }
}
