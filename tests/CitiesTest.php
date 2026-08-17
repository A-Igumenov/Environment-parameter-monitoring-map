<?php
/**
 * Unit tests: includes/cities.php
 * City recognition, prefix generation, capital lookup.
 */

require_once __DIR__ . '/TestCase.php';

// cities.php uses GMAPS_API_KEY — define an empty one (we test without network)
if (!defined('GMAPS_API_KEY')) define('GMAPS_API_KEY', '');
require_once __DIR__ . '/../includes/cities.php';

final class CitiesTest extends TestCase {
    public function run(Assert $t): void {

        // ── makePrefix: diakritikos transliteracija ──
        $t->equals('SAO', makePrefix('São Paulo'), 'São Paulo → SAO');
        $t->equals('KOB', makePrefix('København'), 'København → KOB');
        $t->equals('LOD', makePrefix('Łódź'),      'Łódź → LOD');
        $t->equals('NEW', makePrefix('New York'),  'New York → NEW');
        $t->equals('VIL', makePrefix('Vilnius'),   'Vilnius → VIL');
        $t->equals('GEO', makePrefix('東京'),       'Non-Latin → GEO fallback');
        $t->equals('GEO', makePrefix(''),          'Empty → GEO');

        // ── haversineKm: known distances ──
        $vlnKns = haversineKm(54.6872, 25.2797, 54.8985, 23.9036);
        $t->ok($vlnKns > 85 && $vlnKns < 100, "Vilnius–Kaunas ~92 km (got " . round($vlnKns) . ")");
        $t->equals(0.0, round(haversineKm(54.6872, 25.2797, 54.6872, 25.2797), 1),
            'Same point → 0 km');

        // ── nearestCity: local list (no network) ──
        $vln = nearestCity(54.6872, 25.2797);
        $t->equals('VLN', $vln['prefix'], 'Vilnius coords → VLN');
        $t->equals('local', $vln['source'], 'Vilnius → local source');

        $rix = nearestCity(56.9496, 24.1052);
        $t->equals('RIX', $rix['prefix'], 'Riga coords → RIX');

        // ── nearestCity: a distant point without API → GEO (not the wrong city) ──
        // Radun, BY (~60 km from Druskininkai) without Geocoding → GEO, NOT DRS
        $radun = nearestCity(54.0561, 24.9528);
        $t->notEquals('DRS', $radun['prefix'], 'Radun BY → NOT DRS (fallback >50km)');
        $t->equals('GEO', $radun['prefix'], 'Radun BY (no API) → GEO');

        // ── capitalCoords ──
        $t->equals('Vilnius',    capitalCoords('LT')['city'], 'LT → Vilnius');
        $t->equals('London',     capitalCoords('GB')['city'], 'GB → London');
        $t->equals('Tokyo',      capitalCoords('JP')['city'], 'JP → Tokyo');
        $t->equals('Vilnius',    capitalCoords('XX')['city'], 'Unknown → default Vilnius');

        $lt = capitalCoords('LT');
        $t->ok(abs($lt['lat'] - 54.6872) < 0.001, 'LT lat correct');
        $t->ok($lt['zoom'] >= 10 && $lt['zoom'] <= 13, 'LT zoom reasonable');

        // ── capitalList structure ──
        $list = capitalList();
        $t->ok(count($list) >= 15, 'capitalList has 15+ countries');
        $t->ok(isset($list['LT']) && isset($list['US']), 'LT and US present');

        // ── capitalNameLt: translation of capital names into Lithuanian ──
        $t->equals('Varšuva', capitalNameLt('Warsaw'),     'Warsaw → Varšuva');
        $t->equals('Kopenhaga', capitalNameLt('Copenhagen'), 'Copenhagen → Kopenhaga');
        $t->equals('Roma', capitalNameLt('Rome'),          'Rome → Roma');
        $t->equals('Viena', capitalNameLt('Vienna'),       'Vienna → Viena');
        $t->equals('Vilnius', capitalNameLt('Vilnius'),    'Vilnius nekeičiamas');
        $t->equals('Riga', capitalNameLt('Riga'),          'Riga nekeičiamas');
        $t->equals('Foobar', capitalNameLt('Foobar'),      'Nežinomas → toks pat');
    }
}
