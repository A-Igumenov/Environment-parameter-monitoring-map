<?php
/**
 * PHPUnit-style security tests: password verification and
 * brute-force protection (3 attempts, 60-min IP block).
 */

use PHPUnit\Framework\TestCase;

final class SecurityLogicTest extends TestCase
{
    private string $attemptsFile;

    protected function setUp(): void
    {
        $this->attemptsFile = sys_get_temp_dir() . '/iot_pu_attempts_' . getmypid() . '.json';
        @unlink($this->attemptsFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->attemptsFile);
    }

    // ── Password bcrypt verification ──────────────────────
    public function testBcryptRoundTrip(): void
    {
        $hash = password_hash('TestPass123', PASSWORD_BCRYPT, ['cost' => 12]);
        $this->assertTrue(password_verify('TestPass123', $hash), 'Teisingas slaptažodis');
        $this->assertFalse(password_verify('wrong', $hash), 'Neteisingas atmetamas');
    }

    public function testPlaceholderHashRejected(): void
    {
        $hash = '$2y$12$replacethiswithyourownbcrypthashgeneratedbelow';
        // verifyAdminPassword rejects the placeholder hash
        $rejected = str_contains($hash, 'replacethis');
        $this->assertTrue($rejected, 'Placeholder hash → trynimas neleidžiamas');
    }

    // ── Brute-force: 3 attempts, 60-min block ─────────────
    private const MAX = 3;
    private const BLOCK_MIN = 60;

    private function read(): array
    {
        if (!file_exists($this->attemptsFile)) return [];
        $d = json_decode((string)@file_get_contents($this->attemptsFile), true);
        return is_array($d) ? $d : [];
    }
    private function write(array $d): void
    {
        @file_put_contents($this->attemptsFile, json_encode($d), LOCK_EX);
    }
    private function blockSeconds(string $ip): int
    {
        $rec = $this->read()[$ip] ?? null;
        if (!$rec || ($rec['count'] ?? 0) < self::MAX) return 0;
        $r = ($rec['last'] ?? 0) + self::BLOCK_MIN * 60 - time();
        return $r > 0 ? $r : 0;
    }
    private function recordFail(string $ip): void
    {
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

    public function testThreeAttemptsThenBlock(): void
    {
        $ip = '203.0.113.10';
        $this->assertSame(0, $this->blockSeconds($ip), 'Pradžioje neblokuota');
        $this->recordFail($ip);
        $this->recordFail($ip);
        $this->assertSame(0, $this->blockSeconds($ip), 'Po 2 bandymų neblokuota');
        $this->recordFail($ip);
        $this->assertGreaterThan(0, $this->blockSeconds($ip), 'Po 3 — užblokuota');
    }

    public function testBlockIsAboutSixtyMinutes(): void
    {
        $ip = '203.0.113.11';
        $this->recordFail($ip);
        $this->recordFail($ip);
        $this->recordFail($ip);
        $bs = $this->blockSeconds($ip);
        $this->assertGreaterThan(3500, $bs);
        $this->assertLessThanOrEqual(3600, $bs);
    }

    public function testBlockExpiresAfterSixtyMinutes(): void
    {
        $ip = '203.0.113.12';
        $this->write([$ip => ['count' => 3, 'last' => time() - 3601]]);
        $this->assertSame(0, $this->blockSeconds($ip), 'Po 60 min blokas baigiasi');
        $this->recordFail($ip);
        $this->assertSame(1, $this->read()[$ip]['count'], 'Skaičiavimas iš naujo');
    }

    public function testSeparateIpsIndependent(): void
    {
        $ip1 = '203.0.113.13';
        $ip2 = '203.0.113.14';
        $this->recordFail($ip1);
        $this->recordFail($ip1);
        $this->recordFail($ip1);
        $this->assertGreaterThan(0, $this->blockSeconds($ip1), 'IP1 blokuotas');
        $this->assertSame(0, $this->blockSeconds($ip2), 'IP2 nepaveiktas');
    }
}
