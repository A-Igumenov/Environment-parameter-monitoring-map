<?php
/**
 * PHPUnit-style tests for the most important functions.
 *
 * Uses the standard PHPUnit API (PHPUnit\Framework\TestCase, assertSame,
 * dataProvider). Works with real PHPUnit AND with the included shim.
 *
 * Paleidimas:
 *   - su PHPUnit:  vendor/bin/phpunit tests/phpunit
 *   - be PHPUnit:  php tests/phpunit/run-phpunit.php
 */

use PHPUnit\Framework\TestCase;

final class SensorLogicTest extends TestCase
{
    // ── MAC normalizavimas ────────────────────────────────
    private function normalizeMac(string $raw): string
    {
        if ($raw === '') return '';
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $raw);
        if (strlen($hex) !== 12) return '';
        return implode(':', str_split(strtoupper($hex), 2));
    }

    /** @dataProvider macProvider */
    public function testMacNormalization(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->normalizeMac($input));
    }

    public static function macProvider(): array
    {
        return [
            'colon uppercase' => ['AA:BB:CC:DD:EE:FF', 'AA:BB:CC:DD:EE:FF'],
            'lowercase plain' => ['aabbccddeeff',      'AA:BB:CC:DD:EE:FF'],
            'dash separated'  => ['AA-BB-CC-DD-EE-FF', 'AA:BB:CC:DD:EE:FF'],
            'mixed case'      => ['Aa:bB:cC:dD:eE:fF', 'AA:BB:CC:DD:EE:FF'],
            'too short'       => ['AABBCC',            ''],
            'too long'        => ['AABBCCDDEEFF00',    ''],
            'empty'           => ['',                  ''],
            'invalid chars'   => ['ZZ:YY:XX:WW:VV:UU', ''],
        ];
    }

    // ── Coordinate rounding (7 digits) ────────────────────
    private function roundCoord(float $v): float
    {
        return round($v, 7);
    }

    /** @dataProvider coordProvider */
    public function testCoordRounding(float $input, float $expected): void
    {
        $this->assertSame($expected, $this->roundCoord($input));
    }

    public static function coordProvider(): array
    {
        return [
            'integer'    => [54.0,          54.0],
            'seven dp'   => [54.6872123,    54.6872123],
            'eight dp'   => [54.68721239,   54.6872124],
            'negative'   => [-25.2797,      -25.2797],
        ];
    }

    // ── is_outdoor logic (indoor/outdoor) ─────────────────
    private function parseOutdoor($value): int
    {
        return ($value === '1' || $value === 'true' || $value === 1) ? 1 : 0;
    }

    public function testOutdoorDefaultsToIndoor(): void
    {
        $this->assertSame(0, $this->parseOutdoor(null), 'Nenurodyta → patalpos (0)');
        $this->assertSame(0, $this->parseOutdoor(''),   'Tuščia → patalpos (0)');
        $this->assertSame(0, $this->parseOutdoor('0'),  'Aiškiai 0 → patalpos');
    }

    public function testOutdoorWhenSet(): void
    {
        $this->assertSame(1, $this->parseOutdoor('1'),    "'1' → lauko");
        $this->assertSame(1, $this->parseOutdoor('true'), "'true' → lauko");
    }

    // ── Per-city numbering prefix ─────────────────────────
    private function makePrefix(string $city): string
    {
        $translit = strtr($city, [
            'ą'=>'a','č'=>'c','ę'=>'e','ė'=>'e','į'=>'i','š'=>'s',
            'ų'=>'u','ū'=>'u','ž'=>'z',
            'Ą'=>'A','Č'=>'C','Ę'=>'E','Ė'=>'E','Į'=>'I','Š'=>'S',
            'Ų'=>'U','Ū'=>'U','Ž'=>'Z',
        ]);
        $clean = preg_replace('/[^A-Za-z]/', '', $translit);
        return strtoupper(substr($clean, 0, 3));
    }

    /** @dataProvider prefixProvider */
    public function testCityPrefix(string $city, string $expected): void
    {
        $this->assertSame($expected, $this->makePrefix($city));
    }

    public static function prefixProvider(): array
    {
        return [
            'Vilnius'  => ['Vilnius',  'VIL'],
            'Kaunas'   => ['Kaunas',   'KAU'],
            'Šiauliai' => ['Šiauliai', 'SIA'],
            'New York' => ['New York', 'NEW'],
        ];
    }
}
