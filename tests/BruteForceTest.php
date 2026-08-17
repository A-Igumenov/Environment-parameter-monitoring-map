<?php
/**
 * Brute-force login protection tests.
 * Checks the 3-attempt limit and the 60-min IP block logic
 * (reproduces the behavior of admin.php functions in an isolated file).
 */

require_once __DIR__ . '/TestCase.php';

final class BruteForceTest extends TestCase {

    private string $file;

    public function __construct() {
        $this->file = sys_get_temp_dir() . '/iot_bf_test_' . getmypid() . '.json';
    }

    private const MAX = 3;
    private const BLOCK_MIN = 60;

    private function read(): array {
        if (!file_exists($this->file)) return [];
        $d = json_decode((string)@file_get_contents($this->file), true);
        return is_array($d) ? $d : [];
    }
    private function write(array $d): void {
        @file_put_contents($this->file, json_encode($d), LOCK_EX);
    }
    private function blockSeconds(string $ip): int {
        $rec = $this->read()[$ip] ?? null;
        if (!$rec || ($rec['count'] ?? 0) < self::MAX) return 0;
        $r = ($rec['last'] ?? 0) + self::BLOCK_MIN * 60 - time();
        return $r > 0 ? $r : 0;
    }
    private function recordFail(string $ip): void {
        $all = $this->read();
        $rec = $all[$ip] ?? ['count' => 0, 'last' => 0];
        if (($rec['count'] ?? 0) >= self::MAX
            && (($rec['last'] ?? 0) + self::BLOCK_MIN * 60) < time()) {
            $rec = ['count' => 0, 'last' => 0];
        }
        $rec['count'] = ($rec['count'] ?? 0) + 1;
        $rec['last']  = time();
        $all[$ip] = $rec;
        $this->write($all);
    }
    private function clear(string $ip): void {
        $all = $this->read();
        unset($all[$ip]);
        $this->write($all);
    }

    public function run(Assert $t): void {
        @unlink($this->file);
        $ip = '203.0.113.7';

        // Not blocked initially
        $t->equals(0, $this->blockSeconds($ip), 'Iš pradžių IP neblokuotas');

        // The first two attempts do not block
        $this->recordFail($ip);
        $t->equals(0, $this->blockSeconds($ip), 'Po 1 bandymo neblokuota');
        $this->recordFail($ip);
        $t->equals(0, $this->blockSeconds($ip), 'Po 2 bandymų neblokuota');

        // Third attempt → block
        $this->recordFail($ip);
        $t->true($this->blockSeconds($ip) > 0, 'Po 3 bandymų IP užblokuotas');

        // Blokas ~60 min
        $bs = $this->blockSeconds($ip);
        $t->true($bs > 3500 && $bs <= 3600, 'Blokas ~60 min');

        // A successful login (clear) removes the block
        $this->clear($ip);
        $t->equals(0, $this->blockSeconds($ip), 'Po sėkmingo login blokas panaikintas');

        // Blokas pasibaigia po 60 min
        $this->write([$ip => ['count' => 3, 'last' => time() - 3601]]);
        $t->equals(0, $this->blockSeconds($ip), 'Po 60 min blokas baigiasi');

        // After the block expires the count starts over
        $this->recordFail($ip);
        $t->equals(1, $this->read()[$ip]['count'], 'Po bloko skaičiavimas iš naujo (1, ne 4)');

        // A different IP is unaffected
        $ip2 = '198.51.100.2';
        $t->equals(0, $this->blockSeconds($ip2), 'Kitas IP neblokuotas');

        @unlink($this->file);
    }
}
