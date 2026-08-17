<?php
/**
 * PHPUnit bootstrap.
 *
 * If real PHPUnit is installed (autoload registers
 * PHPUnit\Framework\TestCase) — the shim is NOT loaded.
 * If not — we load the shim so the tests still work.
 */

// Composer autoload (if present)
$autoload = __DIR__ . '/../../vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
}

// The shim loads only if TestCase does not yet exist
if (!class_exists(\PHPUnit\Framework\TestCase::class, false)) {
    require __DIR__ . '/phpunit-shim.php';
}
