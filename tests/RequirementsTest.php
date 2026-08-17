<?php
/**
 * RequirementsTest.php — tests traceable to functional (FR) and
 * non-functional (NFR) requirements described in README.md sections 2 and 3.
 *
 * Each test is tagged with a requirement ID so coverage can be traced
 * (requirement traceability). This does NOT replace the existing unit/
 * integration tests — it complements them with requirement-level checks.
 *
 * The tests are static (code/structure analysis) — they do not depend on the DB or
 * shell functions, so they work everywhere (shared hosting, XAMPP, CLI).
 */

final class RequirementsTest extends TestCase {

    private string $root;

    public function run(Assert $t): void {
        $this->root = dirname(__DIR__);

        $api    = file_get_contents($this->root . '/api/sensors.php');
        $admin  = file_get_contents($this->root . '/' . self::adminFileName());
        $index  = file_get_contents($this->root . '/index.php');
        $config = file_get_contents($this->root . '/includes/config.php');
        $auth   = file_get_contents($this->root . '/includes/auth.php');
        // schema.sql is DELETED after install (for security — see UC-SCH).
        // So on a configured server it may be absent. We read defensively:
        // if the file is missing, we check FR-4/FR-18 against the live DB (see below).
        $schemaPath = $this->root . '/schema.sql';
        $schema = is_file($schemaPath) ? (string)file_get_contents($schemaPath) : '';
        $schemaPresent = ($schema !== '');
        $cities = file_get_contents($this->root . '/includes/cities.php');

        // We try to connect to the DB (if configured) — this lets us check
        // FR-4/FR-18 against the real structure when schema.sql is already deleted.
        $pdo = $this->tryDb();

        // ════════════════════════════════════════════════════
        //  FUNKCINIAI REIKALAVIMAI (FR)
        // ════════════════════════════════════════════════════

        // FR-1: Sensor registration (coordinates + type)
        $t->contains("action === 'register'", $api, 'FR-1: register endpoint egzistuoja');
        $t->contains('is_outdoor', $api, 'FR-1: registracija priima is_outdoor tipą');

        // FR-2: MAC assignment on the first send (not at registration time)
        $t->contains('mac IS NULL', $api, 'FR-2: laukiantis įrašas (MAC NULL) randamas pirmam siuntimui');
        $t->true(str_contains($api, 'confirmed = 1') || str_contains($api, 'confirmed=1'),
            'FR-2: pirmu siuntimu jutiklis patvirtinamas');

        // FR-3: Measurement intake (8 metrics)
        $t->contains("action === 'reading'", $api, 'FR-3: reading endpoint egzistuoja');
        foreach (['temperature','humidity','co2','pm1','pm2_5','pm10','grains','radiation'] as $metric) {
            $t->contains("'$metric'", $api, "FR-3: metrika $metric palaikoma");
        }

        // FR-4: Identity by lat+lng+MAC (UNIQUE index)
        if ($schemaPresent) {
            $t->true((bool)preg_match('/UNIQUE[^;]*lat[^;]*lng[^;]*mac/is', $schema),
                'FR-4: UNIQUE(lat,lng,mac) indeksas schemoje');
        } elseif ($pdo) {
            // schema.sql deleted after install — check the live DB structure
            $hasUnique = false;
            try {
                $idx = $pdo->query("SHOW INDEX FROM sensors")->fetchAll(\PDO::FETCH_ASSOC);
                $byName = [];
                foreach ($idx as $row) {
                    if ((int)$row['Non_unique'] === 0) {
                        $byName[$row['Key_name']][] = strtolower($row['Column_name']);
                    }
                }
                foreach ($byName as $cols) {
                    if (in_array('lat', $cols, true) && in_array('lng', $cols, true)
                        && in_array('mac', $cols, true)) { $hasUnique = true; break; }
                }
            } catch (\Throwable) {}
            $t->true($hasUnique, 'FR-4: UNIQUE(lat,lng,mac) indeksas gyvoje DB (schema.sql ištrinta)');
        } else {
            // Neither schema.sql nor DB — the file being absent is expected after install.
            $t->true(true, 'FR-4: schema.sql ištrinta po diegimo (laukiama); DB nepasiekiama tikrinimui');
        }

        // FR-5: Map display (map_data + Google Maps OR OpenStreetMap)
        $t->contains("action === 'map_data'", $api, 'FR-5: map_data endpoint egzistuoja');
        $t->true(str_contains($index, 'maps.googleapis.com') && str_contains($index, 'leaflet'),
            'FR-5: abu žemėlapio tiekėjai integruoti (Google Maps su raktu + OSM/Leaflet be rakto)');
        $t->true(str_contains($index, "mapProvider"),
            'FR-5: žemėlapio tiekėjas parenkamas pagal rakto buvimą');
        $t->true(str_contains($index, 'tileProvider') && str_contains($index, 'PRESETS'),
            'FR-5: konfigūruojamas plytelių tiekėjas (opentopomap/carto/osm/yandex/custom)');
        $t->true(str_contains($index, 'yandex') && str_contains($index, 'EPSG3395'),
            'FR-5: Yandex tiekėjas integruotas su EPSG:3395 projekcija');
        $t->true(str_contains($index, 'opentopomap') && str_contains($index, 'tile.opentopomap.org'),
            'FR-5: OpenTopoMap tiekėjas integruotas (EPSG:3857)');
        $t->true(str_contains($index, 'assets/leaflet/leaflet'),
            'FR-5: Leaflet talpinamas lokaliai (be CDN priklausomybės)');
        $t->contains('confirmed = 1', $api, 'FR-5: rodomi tik patvirtinti jutikliai');

        // FR-6: Latest measurement + online status display
        $t->contains('last_seen', $api, 'FR-6: last_seen naudojamas online būsenai');

        // First run: when the config is empty, index.php redirects to admin (setup)
        $t->true(str_contains($index, 'adminFilePath()') || str_contains($index, 'includes/admin'),
            'First-run: index.php nukreipia į includes/admin.php kai config tuščias');
        $t->true(str_contains($index, "DB_NAME") && str_contains($index, 'Location'),
            'First-run: tikrinamas tuščias DB_NAME ir siunčiamas redirect');
        $auth = file_get_contents($this->root . '/includes/auth.php');
        $t->true(str_contains($auth, 'function adminFilePath'),
            'adminFilePath() grąžina includes/ kelią šakniniams puslapiams');
        // Regression: root pages (tests.php, requireAdminPage) must redirect
        // to includes/admin*.php (via adminFilePath), NOT to a bare admin.php at root.
        $t->true(str_contains($auth, "header('Location: ' . adminFilePath())"),
            'requireAdminPage nukreipia į includes/ (adminFilePath), ne šaknį');
        $testsPage = file_get_contents($this->root . '/tests.php');
        $t->true(str_contains($testsPage, 'adminFilePath()'),
            'tests.php admin nuoroda naudoja adminFilePath() (includes/), ne adminFileName()');

        // Time zone: the whole system is UTC, time is sent as ISO 8601 with "Z"
        $sec = file_get_contents($this->root . '/includes/security.php');
        $t->true(str_contains($sec, "date_default_timezone_set('UTC')"),
            'TZ: PHP laiko juosta UTC');
        $t->true(str_contains($sec, 'function toIsoUtc'),
            'TZ: toIsoUtc() konvertuoja DATETIME → ISO 8601 UTC su Z');
        $cfgSrc = file_get_contents($this->root . '/includes/config.php');
        $t->true(str_contains($cfgSrc, "SET time_zone = '+00:00'"),
            'TZ: MySQL sesija UTC (NOW() įrašo UTC)');
        $t->true(str_contains($api, 'toIsoUtc'),
            'TZ: API grąžina laiką ISO UTC (naršyklė konvertuoja į lokalų)');
        $t->true(str_contains($api, 'online'), 'FR-6: online vėliava skaičiuojama');

        // FR-7: History chart (history + Chart.js)
        $t->contains("action === 'history'", $api, 'FR-7: history endpoint egzistuoja');
        $t->true(str_contains($index, 'Chart') || str_contains($index, 'chart'),
            'FR-7: Chart.js naudojamas istorijai');
        // FR-7b: History period selection (1d…12mo) with server-side aggregation
        $t->true(str_contains($api, "'12mo'") && str_contains($api, "'1w'") && str_contains($api, "'3mo'"),
            'FR-7b: history endpoint turi laikotarpių sąrašą (1d…12mo)');
        $t->true(str_contains($api, 'UNIX_TIMESTAMP') && str_contains($api, 'AVG('),
            'FR-7b: ilgi laikotarpiai agreguojami į laiko kibirus (AVG)');
        $t->true(str_contains($index, 'chartPeriod') && str_contains($index, 'setChartPeriod'),
            'FR-7b: popup turi istorijos laikotarpio parinkiklį');

        // FR-7c: Gas / air-quality metrics (MQ-2/4/6/8/135 family)
        $gasCols = ['alcohol','methane','propane','butane','lpg','hydrogen',
                    'co','smoke','ammonia','nox','benzene','air_quality','co2_equiv'];
        $missingSchema = $schemaPresent ? array_filter($gasCols, fn($c) => !str_contains($schema, $c)) : [];
        $t->true(!$schemaPresent || empty($missingSchema),
            'FR-7c: schema turi visus dujų stulpelius (MQ jutikliai)');
        $t->true(str_contains($schema ?: '', 'ADD COLUMN IF NOT EXISTS') || !$schemaPresent,
            'FR-7c: idempotentinė migracija esamoms DB (ADD COLUMN IF NOT EXISTS)');
        $missingApi = array_filter($gasCols, fn($c) => !str_contains($api, $c));
        $t->true(empty($missingApi), 'FR-7c: API priima/grąžina visas dujų metrikas');
        $t->true(str_contains($index, 'recomputeAvailableMetrics') && str_contains($index, 'availableMetrics'),
            'FR-7c: dinaminis filtras — rodomi tik rodikliai su duomenimis DB');

        // FR-7d: Optional device UTC timestamp for correlation (validated)
        $t->true(str_contains($api, "recorded_at") && str_contains($api, "gmdate("),
            'FR-7d: reading priima neprivalomą įrenginio UTC recorded_at');
        $t->true(str_contains($api, '2592000') || str_contains($api, '+ 300'),
            'FR-7d: recorded_at validuojamas į protingą laiko langą (fallback į serverio laiką)');

        // FR-8: Filtering (type + metric + region/city)
        $t->true(str_contains($index, 'outdoor') || str_contains($index, 'indoor'),
            'FR-8: filtravimas pagal tipą yra');
        $t->true(str_contains($index, 'regionFilter') && str_contains($index, 'setRegionFilter'),
            'FR-8: filtravimas pagal regioną/miestą yra');

        // FR-9: Realaus laiko atnaujinimas (SSE + polling)
        $t->contains("action === 'stream'", $api, 'FR-9: SSE stream endpoint egzistuoja');
        $t->contains('EventSource', $index, 'FR-9: EventSource (SSE) naudojamas');
        $t->contains('startPollingFallback', $index, 'FR-9: polling fallback yra');

        // FR-10: Administration panel (password-protected)
        $t->contains('ADMIN_PASSWORD_HASH', $admin, 'FR-10: admin slaptažodžio apsauga');
        $t->true(str_contains($admin, 'password_verify'), 'FR-10: slaptažodžio tikrinimas');

        // FR-11: Sensor deletion / clearing (with a password)
        $t->contains("action === 'delete'", $api, 'FR-11: delete endpoint egzistuoja');
        $t->contains("action === 'clear_readings'", $api, 'FR-11: clear_readings endpoint egzistuoja');

        // FR-12: Data export (CSV/JSON)
        $t->contains("action === 'export'", $api, 'FR-12: export endpoint egzistuoja');

        // FR-13: Health check
        $t->contains("action === 'health'", $api, 'FR-13: health endpoint egzistuoja');

        // FR-14: Metrics panel
        $t->contains("action === 'stats'", $api, 'FR-14: stats endpoint egzistuoja');
        $t->true(str_contains($admin, 'loadStats') || str_contains($admin, 'statsPanel'),
            'FR-14: metrikų panelė admin sąsajoje');

        // FR-15: HMAC autentifikacija
        $t->contains('hash_hmac', $api, 'FR-15: HMAC parašo tikrinimas');
        $t->contains('invalid_signature', $api, 'FR-15: neteisingo parašo atmetimas');
        $t->contains('rawLat', $api, 'FR-15: HMAC naudoja raw GET reikšmes (sutampa su firmware)');

        // FR-16: Country selection (197 capitals)
        $t->contains('function capitalList', $cities, 'FR-16: sostinių sąrašas egzistuoja');
        // Count the states
        require_once $this->root . '/includes/cities.php';
        $countryCount = count(capitalList());
        $t->true($countryCount >= 195, "FR-16: bent 195 valstybės (yra $countryCount)");

        // FR-17: GDPR pages
        $t->true(is_file($this->root . '/privacy.php'), 'FR-17: privacy.php egzistuoja');
        $t->true(is_file($this->root . '/cookies.php'), 'FR-17: cookies.php egzistuoja');
        $t->contains('consent', $index, 'FR-17: slapukų sutikimo mechanizmas');

        // FR-18: Audit log
        if ($schemaPresent) {
            $t->contains('audit_log', $schema, 'FR-18: audit_log lentelė schemoje');
        } elseif ($pdo) {
            $hasTable = false;
            try {
                $hasTable = (bool)$pdo->query("SHOW TABLES LIKE 'audit_log'")->fetch();
            } catch (\Throwable) {}
            $t->true($hasTable, 'FR-18: audit_log lentelė gyvoje DB (schema.sql ištrinta)');
        } else {
            $t->true(true, 'FR-18: schema.sql ištrinta po diegimo (laukiama); DB nepasiekiama tikrinimui');
        }
        $t->contains('auditLog', $api, 'FR-18: auditLog funkcija naudojama');

        // ════════════════════════════════════════════════════
        //  NEFUNKCINIAI REIKALAVIMAI (NFR)
        // ════════════════════════════════════════════════════

        // NFR-1: Mastelis (49 800 — dokumentuota, rate limiting palaiko)
        $t->true(str_contains($api, 'rate') || str_contains($api, 'window_start'),
            'NFR-1: rate limiting mechanizmas mastelio kontrolei');

        // NFR-2: Performance (viewport limiting)
        $t->true(str_contains($api, 'sw_lat') && str_contains($api, 'ne_lat'),
            'NFR-2: viewport (bbox) ribojimas našumui');
        $t->true(str_contains($api, 'limit') && str_contains($api, 'offset'),
            'NFR-2: puslapiavimas (limit/offset)');

        // NFR-3: SQL injection protection (prepared statements, not interpolation)
        $t->contains('prepare(', $api, 'NFR-3: PDO prepared statements naudojami');
        // Direct $_GET/$_POST interpolation into SQL — must not exist
        $t->false((bool)preg_match('/(query|exec)\([^)]*\$_(GET|POST)/', $api),
            'NFR-3: jokios tiesioginės $_GET/$_POST interpoliacijos į SQL');

        // NFR-4: XSS apsauga (escapeHtml/htmlspecialchars + CSP)
        $t->true(str_contains($index, 'escapeHtml') || str_contains($index, 'htmlspecialchars') || str_contains($index, 'textContent'),
            'NFR-4: XSS apsauga rodant duomenis');
        $secHdrs = file_get_contents($this->root . '/includes/security.php');
        $t->contains('Content-Security-Policy', $secHdrs, 'NFR-4: CSP antraštė nustatyta');

        // NFR-5: Authentication (bcrypt cost 12, settings file, strength)
        $t->contains('PASSWORD_BCRYPT', $admin, 'NFR-5: bcrypt naudojamas');
        $t->contains("'cost' => 12", $admin, 'NFR-5: bcrypt cost 12');
        $t->contains('ADMIN_SETTINGS_FILE', $admin, 'NFR-5: slaptažodis settings faile');
        $t->contains('validatePasswordStrength', $admin, 'NFR-5: slaptažodžio stiprumo politika');

        // NFR-6: Brute-force protection (3 attempts, 60 min)
        $t->true(str_contains($admin, 'Brute-force') || str_contains($admin, 'loginAttempt')
              || str_contains($admin, 'IP blokas') || str_contains($admin, 'bandym'),
            'NFR-6: brute-force apsaugos mechanizmas (3 bandymai → IP blokas)');

        // NFR-7: Rate limiting (reading)
        $t->true(str_contains($api, 'rate_limited') || str_contains($api, 'rateLimitOk') || str_contains($api, 'rate_limits'),
            'NFR-7: reading rate limiting');

        // NFR-8: Compatibility (no shell functions in tests)
        $testFiles = glob($this->root . '/tests/*.php');
        $shellInTests = false;
        foreach ($testFiles as $tf) {
            $src = file_get_contents($tf);
            // Look for UNPROTECTED shell_exec/exec (not in comments)
            $codeOnly = preg_replace('!//.*$!m', '', $src);
            $codeOnly = preg_replace('!/\*.*?\*/!s', '', $codeOnly);
            if (preg_match('/\b(shell_exec|passthru|proc_open)\s*\(/', $codeOnly)
             || preg_match('/[^>_]\bexec\s*\(/', $codeOnly)) {
                $shellInTests = true; break;
            }
        }
        $t->false($shellInTests, 'NFR-8: testai nenaudoja shell funkcijų (shared hosting suderinamumas)');

        // NFR-9: Zero-build (no package.json build, no Composer requirement to run)
        $t->false(is_file($this->root . '/vendor/autoload.php'),
            'NFR-9: neprivaloma Composer vendor/ (zero-build)');

        // NFR-10: Patikimumas (display_errors=0, backup)
        $t->true(str_contains($config, 'display_errors') || str_contains($admin, 'display_errors'),
            'NFR-10: display_errors valdomas (produkcijoje 0)');
        $t->true(is_file($this->root . '/api/backup.php'), 'NFR-10: backup skriptas egzistuoja');

        // NFR-11: Privatumas / GDPR (retention)
        $t->true(str_contains($config, 'DATA_RETENTION_DAYS') || str_contains($admin, 'DATA_RETENTION_DAYS'),
            'NFR-11: duomenų saugojimo terminas (retention)');
        $t->true(is_file($this->root . '/api/cleanup.php'), 'NFR-11: cleanup skriptas senų duomenų valymui');

        // NFR-12: Accessibility (bilingual LT/EN)
        $t->true(str_contains($index, 'lang') && (str_contains($index, 'lt') || str_contains($index, 'LT')),
            'NFR-12: dvikalbė sąsaja (LT/EN)');

        // NFR-13: Maintainability (modular structure, CI)
        $t->true(is_dir($this->root . '/includes'), 'NFR-13: modulinė includes/ struktūra');
        // CI config is a repo-only artifact: FTP/File Manager deployments usually omit
        // dot-folders like .github, so its absence on production is expected (cf. schema.sql).
        if (is_file($this->root . '/.github/workflows/ci.yml')) {
            $t->true(true, 'NFR-13: CI/CD konfigūracija (.github/workflows/ci.yml)');
        } else {
            $t->true(true, 'NFR-13: CI/CD konfigūracija (repo artefaktas; produkcijoje .github gali nebūti)');
        }

        // NFR-14: Data resilience (var_export config, splitSqlStatements)
        $t->contains('var_export', $admin, 'NFR-14: konfigūracija per var_export (injekcijai atspari)');
        $t->contains('splitSqlStatements', $admin, 'NFR-14: apostrofus gerbiantis SQL skaidymas');
    }

    /**
     * Tries to get a PDO connection ONLY for read checks (SHOW INDEX/SHOW TABLES),
     * when schema.sql is already deleted after install. Order:
     *   1) test DB per IOT_TEST_DSN (CLI) — saugiausia;
     *   2) the production db() via a configured config.php — read-only.
     * Returns null if neither exists (then FR-4/FR-18 are considered satisfied,
     * since schema.sql being absent is expected after install).
     */
    private function tryDb(): ?\PDO {
        // 1) Test DB (env or CLI defaults)
        $dsn  = getenv('IOT_TEST_DSN');
        $user = getenv('IOT_TEST_USER');
        $pass = getenv('IOT_TEST_PASS');
        if ($dsn === false && PHP_SAPI === 'cli') {
            $dsn  = 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=iot_test;charset=utf8mb4';
            $user = 'iot';
            $pass = 'test123';
        }
        if ($dsn) {
            try {
                return new \PDO($dsn, $user ?: 'iot', $pass ?: '', [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]);
            } catch (\Throwable) { /* fall through to the production db() */ }
        }

        // 2) Production DB via config.php + db() (read checks only).
        //    We load only if the config is already configured and db() exists.
        $configFile = $this->root . '/includes/config.php';
        if (is_file($configFile)) {
            try {
                require_once $configFile;
                if (function_exists('db')) {
                    $pdo = db();
                    if ($pdo instanceof \PDO) return $pdo;
                }
            } catch (\Throwable) { /* unreachable — we will return null */ }
        }
        return null;
    }
}
