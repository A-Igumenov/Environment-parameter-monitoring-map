<?php
/**
 * Minimal testing framework (no Composer/PHPUnit dependency).
 * Works on any PHP 8+ server, including Hostinger.
 *
 * Naudojimas:
 *   php tests/run.php
 */

final class TestResult {
    public int $passed = 0;
    public int $failed = 0;
    public array $failures = [];
}

final class Assert {
    public function __construct(private TestResult $r, private string $test) {}

    public function ok(bool $cond, string $msg = ''): void {
        if ($cond) {
            $this->r->passed++;
        } else {
            $this->r->failed++;
            $this->r->failures[] = "{$this->test}: " . ($msg ?: 'assertion failed');
        }
    }

    public function equals(mixed $expected, mixed $actual, string $msg = ''): void {
        $this->ok($expected === $actual,
            $msg ?: 'expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }

    public function notEquals(mixed $a, mixed $b, string $msg = ''): void {
        $this->ok($a !== $b, $msg ?: 'values should differ');
    }

    public function true(bool $v, string $msg = ''): void  { $this->ok($v === true, $msg); }
    public function false(bool $v, string $msg = ''): void { $this->ok($v === false, $msg); }
    public function null(mixed $v, string $msg = ''): void { $this->ok($v === null, $msg); }
    public function notNull(mixed $v, string $msg = ''): void { $this->ok($v !== null, $msg); }

    public function matches(string $pattern, string $subject, string $msg = ''): void {
        $this->ok(preg_match($pattern, $subject) === 1,
            $msg ?: "'{$subject}' does not match {$pattern}");
    }

    public function contains(string $needle, string $haystack, string $msg = ''): void {
        $this->ok(str_contains($haystack, $needle),
            $msg ?: "'{$haystack}' does not contain '{$needle}'");
    }

    public function throws(callable $fn, string $msg = ''): void {
        try {
            $fn();
            $this->ok(false, $msg ?: 'expected exception was not thrown');
        } catch (\Throwable) {
            $this->ok(true);
        }
    }
}

abstract class TestCase {
    abstract public function run(Assert $t): void;

    /**
     * Returns the current admin file name. After a rename via the admin page
     * the file becomes admin_XXXX.php, and the new name is stored
     * in the includes/admin_file.php marker. Without this function tests would look for
     * a fixed admin.php and fail after a rename.
     */
    protected static function adminFileName(): string {
        $root   = dirname(__DIR__);            // tests/ → project root
        $marker = $root . '/includes/admin_file.php';
        if (is_file($marker)) {
            $name = @include $marker;
            if (is_string($name)
                && preg_match('/^admin_[A-Za-z0-9_-]+\.php$/', $name)
                && is_file($root . '/includes/' . $name)) {
                return 'includes/' . $name;
            }
        }
        return 'includes/admin.php';
    }
}

final class TestRunner {
    /** @var TestCase[] */
    private array $tests = [];

    public function add(string $name, TestCase $tc): void {
        $this->tests[$name] = $tc;
    }

    public function execute(): int {
        $result = new TestResult();
        $start = microtime(true);

        echo "\n╔══════════════════════════════════════════════════════╗\n";
        echo "║  IoT Sensor Map — Unit Tests                         ║\n";
        echo "╚══════════════════════════════════════════════════════╝\n\n";

        foreach ($this->tests as $name => $tc) {
            $before = $result->failed;
            $assert = new Assert($result, $name);
            try {
                $tc->run($assert);
                $status = ($result->failed === $before) ? "✅" : "❌";
            } catch (\Throwable $e) {
                $result->failed++;
                $result->failures[] = "{$name}: EXCEPTION " . $e->getMessage();
                $status = "💥";
            }
            echo sprintf("  %s %s\n", $status, $name);
        }

        $elapsed = round((microtime(true) - $start) * 1000, 1);
        echo "\n──────────────────────────────────────────────────────\n";
        echo sprintf("  Praėjo: %d · Nepavyko: %d · Laikas: %s ms\n",
            $result->passed, $result->failed, $elapsed);

        if ($result->failures) {
            echo "\n  Klaidos:\n";
            foreach ($result->failures as $f) {
                echo "    ✗ {$f}\n";
            }
        }
        echo "\n";

        return $result->failed === 0 ? 0 : 1;
    }
}
