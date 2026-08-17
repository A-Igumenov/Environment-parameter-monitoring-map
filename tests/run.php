<?php
/**
 * Test runner.
 *
 * Run all tests:
 *   php tests/run.php
 *
 * Returns exit code 0 (all passed) or 1 (failures) — suitable for CI/CD.
 */

require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/CitiesTest.php';
require_once __DIR__ . '/AdminLogicTest.php';
require_once __DIR__ . '/AuthTest.php';
require_once __DIR__ . '/GdprComplianceTest.php';
require_once __DIR__ . '/BruteForceTest.php';
require_once __DIR__ . '/ApiIntegrationTest.php';
require_once __DIR__ . '/RequirementsTest.php';
require_once __DIR__ . '/TwoStageAuthTest.php';

$runner = new TestRunner();

// Unit tests (without DB)
$runner->add('Cities: prefix, distance, capitals',  new CitiesTest());
$runner->add('Config: injection-safe generation',   new ConfigGenTest());
$runner->add('Password: bcrypt + self-rewrite',     new PasswordTest());
$runner->add('Schema delete after install',          new SchemaArchiveTest());
$runner->add('Auth: admin authorization guard',     new AuthTest());
$runner->add('BDAR/GDPR: privacy, cookies, rights', new GdprComplianceTest());
$runner->add('Brute-force: 3 attempts, 60min IP block', new BruteForceTest());
$runner->add('Requirements: FR + NFR traceability', new RequirementsTest());
$runner->add('Two-stage auth: email + 2-stage lockout', new TwoStageAuthTest());

// Integration tests (with DB, skipped if unreachable)
$runner->add('API+DB: numbering, clear, delete, auth', new ApiIntegrationTest());

$mainResult = $runner->execute();

// ── PHPUnit-style tests (a separate runner via the shim) ──
echo "\n";
$phpunitRunner = __DIR__ . '/phpunit/run-phpunit.php';
$phpunitResult = 0;
if (is_file($phpunitRunner)) {
    // We run DIRECTLY via include — NEVER via passthru/subprocess.
    // Shell functions are unreliable across environments: disabled on shared hosting,
    // and on Windows XAMPP php.exe is often not on PATH (a subprocess would break even if
    // passthru „prieinamas"). include veikia visur — CLI, web (tests.php),
    // Windows, Linux. run-phpunit.php returns its code via return.
    $phpunitResult = (int) (function () use ($phpunitRunner) {
        $ret = include $phpunitRunner;
        return is_int($ret) ? $ret : 0;
    })();
}

exit($mainResult || $phpunitResult ? 1 : 0);
