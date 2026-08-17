<?php
// ============================================================
// tests.php — run the map / system tests from admin
//
// Protected: logged-in administrator only.
// Runs tests/run.php and shows the result with a back button.
// ============================================================

require_once __DIR__ . '/includes/auth.php';
requireAdminPage(); // logged-in users only

require_once __DIR__ . '/includes/config.php';
$titleLt = defined('SITE_TITLE_LT') ? SITE_TITLE_LT : 'IoT Jutiklių Žemėlapis';

// Language (same as admin)
$lang = ($_SESSION['iot_admin_lang'] ?? 'lt') === 'en' ? 'en' : 'lt';
$adminFile = adminFilePath(); // includes/admin*.php (admin saugomas includes/)
$T = [
  'lt' => [
    'title'      => 'Žemėlapio testai',
    'subtitle'   => 'Automatiniai vienetų ir integraciniai testai. Patikrina DB ryšį, jutiklių logiką, API ir saugumą.',
    'back'       => 'Į administravimą',
    'run'        => '▶ Paleisti testus',
    'running'    => 'Vykdoma...',
    'passed'     => 'Praėjo',
    'failed'     => 'Nepavyko',
    'duration'   => 'Trukmė',
    'all_ok'     => 'Visi testai praėjo sėkmingai',
    'some_fail'  => 'Yra nepavykusių testų',
    'no_runner'  => 'Testų paleidiklis nerastas (tests/run.php).',
    'php_missing'=> 'PHP CLI nepasiekiamas šiame serveryje — testų automatiškai paleisti negalima. Paleiskite rankiniu būdu: php tests/run.php',
  ],
  'en' => [
    'title'      => 'Map tests',
    'subtitle'   => 'Automated unit and integration tests. Checks DB connection, sensor logic, API and security.',
    'back'       => 'To admin',
    'run'        => '▶ Run tests',
    'running'    => 'Running...',
    'passed'     => 'Passed',
    'failed'     => 'Failed',
    'duration'   => 'Duration',
    'all_ok'     => 'All tests passed successfully',
    'some_fail'  => 'Some tests failed',
    'no_runner'  => 'Test runner not found (tests/run.php).',
    'php_missing'=> 'PHP CLI is not available on this server — tests cannot run automatically. Run manually: php tests/run.php',
  ],
][$lang];

// ── Run tests (POST) ──────────────────────────────────────
$output = null;
$exitCode = null;
$ran = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_tests'])) {
    $ran = true;
    if (!is_dir(__DIR__ . '/tests')) {
        $output = $T['no_runner'];
        $exitCode = -1;
    } else {
        // Always run in the SAME process (runInline).
        // We do NOT use an external PHP process (shell_exec) — on XAMPP/Windows
        // this starts a new Apache child process, which
        // luzta su „AH02965: Unable to retrieve my generation".
        $output = runInline(__DIR__ . '/tests');
        $exitCode = (strpos($output, 'Nepavyko: 0') !== false
                  || strpos($output, 'Failed: 0') !== false) ? 0 : 1;
    }
}

// Run the tests in the same process (without external commands).
function runInline(string $dir): string {
    ob_start();
    try {
        require_once $dir . '/TestCase.php';
        require_once $dir . '/CitiesTest.php';
        require_once $dir . '/AdminLogicTest.php';
        require_once $dir . '/AuthTest.php';
        require_once $dir . '/GdprComplianceTest.php';
        require_once $dir . '/BruteForceTest.php';
        require_once $dir . '/ApiIntegrationTest.php';
        require_once $dir . '/RequirementsTest.php';

        $r = new TestRunner();
        $r->add('Cities: prefix, distance, capitals',  new CitiesTest());
        $r->add('Config: injection-safe generation',   new ConfigGenTest());
        $r->add('Password: bcrypt + self-rewrite',     new PasswordTest());
        $r->add('Schema archive: usedSh.<random>',     new SchemaArchiveTest());
        $r->add('Auth: admin authorization guard',     new AuthTest());
        $r->add('BDAR/GDPR: privacy, cookies, rights', new GdprComplianceTest());
        $r->add('Brute-force: 3 attempts, 60min IP block', new BruteForceTest());
        $r->add('Requirements: FR + NFR traceability', new RequirementsTest());
        $r->add('API+DB: numbering, clear, delete',    new ApiIntegrationTest());
        $r->execute();

        // PHPUnit-style tests (via the shim, in the same process)
        $purunner = dirname($dir) . '/tests/phpunit/run-phpunit.php';
        if (is_file($purunner)) {
            require_once dirname($dir) . '/tests/phpunit/phpunit-shim.php';
            foreach (glob(dirname($dir) . '/tests/phpunit/*Test.php') as $f) require_once $f;
            runPhpunitInline();
        }
    } catch (\Throwable $e) {
        echo "EXCEPTION: " . $e->getMessage() . "\n";
    }
    return ob_get_clean();
}

/** Runs PHPUnit-style tests inline (for the web context). */
function runPhpunitInline(): void {
    $classes = array_filter(get_declared_classes(),
        fn($c) => is_subclass_of($c, \PHPUnit\Framework\TestCase::class));
    $tests = 0; $fails = [];
    echo "\n-- PHPUnit-style --\n";
    foreach ($classes as $class) {
        $ref = new \ReflectionClass($class);
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!str_starts_with($method->getName(), 'test')) continue;
            $providerData = [[]];
            $doc = $method->getDocComment() ?: '';
            if (preg_match('/@dataProvider\s+(\w+)/', $doc, $m)) {
                $providerData = $class::{$m[1]}();
            }
            foreach ($providerData as $args) {
                $inst = new $class();
                (new \ReflectionMethod($class, 'setUp'))->invoke($inst);
                $tests++;
                try {
                    $method->invokeArgs($inst, is_array($args) ? $args : [$args]);
                } catch (\Throwable $e) {
                    $fails[] = $class . '::' . $method->getName() . ' -- ' . $e->getMessage();
                }
                (new \ReflectionMethod($class, 'tearDown'))->invoke($inst);
            }
        }
    }
    $passed = $tests - count($fails);
    echo "  PHPUnit: Praejo {$passed} - Nepavyko " . count($fails) . " (is {$tests})\n";
    foreach ($fails as $f) echo "   x {$f}\n";
}

// Extract passed/failed counts from the output
$passed = $failed = null;
if ($output && preg_match('/(?:Praėjo|Passed):\s*(\d+)/u', $output, $m)) $passed = (int)$m[1];
if ($output && preg_match('/(?:Nepavyko|Failed):\s*(\d+)/u', $output, $m)) $failed = (int)$m[1];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($titleLt, ENT_QUOTES) ?> — <?= htmlspecialchars($T['title']) ?></title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<style>
:root {
  --bg:#0a0f1e; --surface:#111827; --surface2:#1a2235; --border:#1e2d45;
  --accent:#00c8ff; --ok:#22d3a0; --warn:#f59e0b; --danger:#ef4444;
  --text:#e2e8f0; --muted:#64748b; --mono:'JetBrains Mono','Fira Code',monospace; --sans:'Inter',system-ui,sans-serif;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: var(--sans); background: var(--bg); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; }
header { display: flex; align-items: center; gap: 1rem; padding: .85rem 1.25rem; background: var(--surface); border-bottom: 1px solid var(--border); }
.logo { display: flex; align-items: center; gap: .55rem; font-weight: 700; font-size: 1rem; }
.logo svg { width: 22px; height: 22px; color: var(--accent); }
.spacer { flex: 1; }
.btn { background: var(--surface2); color: var(--text); border: 1px solid var(--border); border-radius: 8px; padding: .55rem .95rem; font-size: .85rem; cursor: pointer; font-family: var(--sans); text-decoration: none; display: inline-flex; align-items: center; gap: .4rem; transition: .15s; }
.btn:hover { border-color: var(--muted); }
.btn-primary { background: var(--accent); color: var(--bg); border-color: var(--accent); font-weight: 600; }
.wrap { flex: 1; width: min(900px, 96vw); margin: 1.5rem auto; padding: 0 1rem; }
h1 { font-size: 1.3rem; margin-bottom: .35rem; }
.subtitle { color: var(--muted); font-size: .85rem; margin-bottom: 1.5rem; line-height: 1.6; }
.summary { display: flex; gap: 1rem; margin: 1.25rem 0; flex-wrap: wrap; }
.stat-card { flex: 1; min-width: 120px; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 1rem 1.25rem; }
.stat-card .num { font-size: 1.8rem; font-weight: 700; font-family: var(--mono); }
.stat-card .lbl { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); margin-top: .2rem; }
.stat-ok .num { color: var(--ok); }
.stat-fail .num { color: var(--danger); }
.banner { border-radius: 10px; padding: 1rem 1.25rem; margin: 1rem 0; font-weight: 600; display: flex; align-items: center; gap: .6rem; }
.banner-ok { background: rgba(34,211,160,.1); border: 1px solid rgba(34,211,160,.3); color: var(--ok); }
.banner-fail { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.3); color: #fca5a5; }
pre { background: #060912; border: 1px solid var(--border); border-radius: 10px; padding: 1.25rem; overflow-x: auto; font-family: var(--mono); font-size: .8rem; line-height: 1.7; color: var(--text); white-space: pre-wrap; }
.run-form { margin: 1rem 0; }
</style>
</head>
<body>
<header>
  <div class="logo">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <circle cx="12" cy="10" r="3"/>
      <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/>
    </svg>
    <span><?= htmlspecialchars($titleLt, ENT_QUOTES) ?></span>
  </div>
  <div class="spacer"></div>
  <a class="btn" href="<?= htmlspecialchars($adminFile, ENT_QUOTES) ?>">← <?= htmlspecialchars($T['back']) ?></a>
  <a class="btn" href="index.php">🗺 <?= $lang === 'lt' ? 'Žemėlapis' : 'Map' ?></a>
  <a class="btn" href="manage.php"><?= $lang === 'lt' ? '⚙ Jutikliai' : '⚙ Sensors' ?></a>
</header>

<div class="wrap">
  <h1><?= htmlspecialchars($T['title']) ?></h1>
  <div class="subtitle"><?= htmlspecialchars($T['subtitle']) ?></div>

  <form method="POST" class="run-form">
    <button type="submit" name="run_tests" class="btn btn-primary"><?= htmlspecialchars($T['run']) ?></button>
  </form>

  <?php if ($ran): ?>
    <?php if ($passed !== null || $failed !== null): ?>
      <div class="summary">
        <div class="stat-card stat-ok">
          <div class="num"><?= $passed ?? '—' ?></div>
          <div class="lbl"><?= htmlspecialchars($T['passed']) ?></div>
        </div>
        <div class="stat-card <?= ($failed ?? 1) === 0 ? '' : 'stat-fail' ?>">
          <div class="num"><?= $failed ?? '—' ?></div>
          <div class="lbl"><?= htmlspecialchars($T['failed']) ?></div>
        </div>
      </div>

      <?php if (($failed ?? 1) === 0): ?>
        <div class="banner banner-ok">✓ <?= htmlspecialchars($T['all_ok']) ?></div>
      <?php else: ?>
        <div class="banner banner-fail">✗ <?= htmlspecialchars($T['some_fail']) ?></div>
      <?php endif; ?>
    <?php endif; ?>

    <pre><?= htmlspecialchars($output ?? '') ?></pre>
  <?php endif; ?>
</div>
<footer style="text-align:center;padding:1rem;font-size:.72rem;color:var(--muted);border-top:1px solid var(--border)">
  <a href="cookies.php" style="color:var(--muted);text-decoration:none"><?= $lang === 'lt' ? 'Slapukai' : 'Cookies' ?></a>
  <span style="opacity:.4">·</span>
  <a href="privacy.php" style="color:var(--muted);text-decoration:none"><?= $lang === 'lt' ? 'Privatumas' : 'Privacy' ?></a>
</footer>
</body>
</html>
