<?php
/**
 * Runs PHPUnit-style tests WITHOUT installed PHPUnit (via the shim).
 *
 * Detects test classes (extends TestCase), calls all test* methods,
 * apdoroja #[DataProvider] / @dataProvider, setUp/tearDown.
 *
 * If you have real PHPUnit — use it instead of this:
 *   vendor/bin/phpunit tests/phpunit
 */

require __DIR__ . '/phpunit-shim.php';

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\AssertionFailedError;

// Load the test files
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require_once $file;
}

$classes = array_filter(get_declared_classes(),
    fn($c) => is_subclass_of($c, TestCase::class));

$totalTests = 0;
$totalAssertions = 0;
$failures = [];

echo "\n";
echo "╔══════════════════════════════════════════════════════╗\n";
echo "║  PHPUnit-style tests (shim runner)                   ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

foreach ($classes as $class) {
    $ref = new ReflectionClass($class);
    $methods = array_filter(
        $ref->getMethods(ReflectionMethod::IS_PUBLIC),
        fn($m) => str_starts_with($m->getName(), 'test')
    );
    if (!$methods) continue;

    echo "  {$class}\n";

    foreach ($methods as $method) {
        $name = $method->getName();

        // dataProvider: @dataProvider name or #[DataProvider('name')]
        $providerData = [[]];
        $doc = $method->getDocComment() ?: '';
        if (preg_match('/@dataProvider\s+(\w+)/', $doc, $m)) {
            $providerData = $class::{$m[1]}();
        }

        foreach ($providerData as $caseKey => $args) {
            $instance = new $class();
            // setUp
            $su = new ReflectionMethod($class, 'setUp');
            $su->setAccessible(true); $su->invoke($instance);

            $totalTests++;
            try {
                $method->invokeArgs($instance, is_array($args) ? $args : [$args]);
                $totalAssertions += $instance->__assertions;
            } catch (AssertionFailedError $e) {
                $label = is_string($caseKey) ? " [{$caseKey}]" : '';
                $failures[] = "{$class}::{$name}{$label} — " . $e->getMessage();
                echo "    ✗ {$name}{$label}\n";
                // tearDown even after an error
                $td = new ReflectionMethod($class, 'tearDown');
                $td->setAccessible(true); $td->invoke($instance);
                continue;
            } catch (\Throwable $e) {
                $failures[] = "{$class}::{$name} — EXCEPTION: " . $e->getMessage();
                echo "    💥 {$name}: {$e->getMessage()}\n";
                continue;
            }

            $td = new ReflectionMethod($class, 'tearDown');
            $td->setAccessible(true); $td->invoke($instance);
        }
        echo "    ✓ {$name}\n";
    }
}

echo "\n──────────────────────────────────────────────────────\n";
$failed = count($failures);
$passed = $totalTests - $failed;
echo "  Testai: {$totalTests} · Assertion'ai: {$totalAssertions}\n";
echo "  Praėjo: {$passed} · Nepavyko: {$failed}\n";
if ($failures) {
    echo "\n  KLAIDOS:\n";
    foreach ($failures as $f) echo "   ✗ {$f}\n";
}
echo "\n";

$__phpunitExitCode = $failed > 0 ? 1 : 0;
// If the file is run DIRECTLY (php run-phpunit.php) — exit with a code.
// If INCLUDED via include (from run.php without shell functions) — return the code,
// so it does not terminate the main process.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    exit($__phpunitExitCode);
}
return $__phpunitExitCode;
