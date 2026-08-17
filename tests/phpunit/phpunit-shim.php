<?php
/**
 * Minimal PHPUnit compatibility layer (shim).
 *
 * Allows running PHPUnit-style tests (extends TestCase, assertSame,
 * assertTrue, setUp/tearDown, dataProvider) WITHOUT PHPUnit installed.
 *
 * When real PHPUnit is INSTALLED (vendor/bin/phpunit), this file is NOT loaded —
 * the real PHPUnit\Framework\TestCase is used instead. This way the same
 * tests work in both cases:
 *   - Be PHPUnit:  php tests/phpunit/run-phpunit.php
 *   - With PHPUnit:  vendor/bin/phpunit  (or phpunit.phar)
 *
 * The shim loads ONLY if the class does not yet exist.
 */

namespace PHPUnit\Framework {

    if (!class_exists(TestCase::class, false)) {

        class AssertionFailedError extends \Exception {}

        abstract class TestCase {
            public int $__assertions = 0;
            public array $__failures = [];

            protected function setUp(): void {}
            protected function tearDown(): void {}

            // ── Assertion'ai (PHPUnit API poaibis) ──
            public function assertSame($expected, $actual, string $m = ''): void {
                $this->__assertions++;
                if ($expected !== $actual) {
                    $this->fail($m ?: "assertSame: tikėtasi " . var_export($expected, true)
                        . ", gauta " . var_export($actual, true));
                }
            }
            public function assertEquals($expected, $actual, string $m = ''): void {
                $this->__assertions++;
                if ($expected != $actual) {
                    $this->fail($m ?: "assertEquals: tikėtasi " . var_export($expected, true)
                        . ", gauta " . var_export($actual, true));
                }
            }
            public function assertTrue($cond, string $m = ''): void {
                $this->__assertions++;
                if ($cond !== true) $this->fail($m ?: 'assertTrue nepavyko');
            }
            public function assertFalse($cond, string $m = ''): void {
                $this->__assertions++;
                if ($cond !== false) $this->fail($m ?: 'assertFalse nepavyko');
            }
            public function assertNull($v, string $m = ''): void {
                $this->__assertions++;
                if ($v !== null) $this->fail($m ?: 'assertNull nepavyko');
            }
            public function assertNotNull($v, string $m = ''): void {
                $this->__assertions++;
                if ($v === null) $this->fail($m ?: 'assertNotNull nepavyko');
            }
            public function assertGreaterThan($expected, $actual, string $m = ''): void {
                $this->__assertions++;
                if (!($actual > $expected)) $this->fail($m ?: "assertGreaterThan nepavyko");
            }
            public function assertLessThanOrEqual($expected, $actual, string $m = ''): void {
                $this->__assertions++;
                if (!($actual <= $expected)) $this->fail($m ?: "assertLessThanOrEqual nepavyko");
            }
            public function assertCount(int $n, $arr, string $m = ''): void {
                $this->__assertions++;
                if (count($arr) !== $n) $this->fail($m ?: "assertCount: tikėtasi $n, gauta " . count($arr));
            }
            public function assertStringContainsString(string $needle, string $haystack, string $m = ''): void {
                $this->__assertions++;
                if (!str_contains($haystack, $needle)) $this->fail($m ?: "assertStringContainsString: nerasta '$needle'");
            }
            public function assertMatchesRegularExpression(string $pattern, string $s, string $m = ''): void {
                $this->__assertions++;
                if (!preg_match($pattern, $s)) $this->fail($m ?: "assertMatchesRegularExpression nepavyko");
            }

            public function fail(string $m): void {
                throw new AssertionFailedError($m);
            }
        }
    }
}
