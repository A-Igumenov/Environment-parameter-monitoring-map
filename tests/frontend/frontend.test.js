/**
 * 3.1 Frontend JS tests (homemade Node runner, no dependencies).
 *
 * Checks pure logic extracted from index.php:
 *   - location filter logic (indoor/outdoor/all)
 *   - reading filter logic
 *   - i18n base64 atribucijos dekodavimas
 *   - coordinate grouping key
 *
 * Paleidimas:  node tests/frontend/frontend.test.js
 * (Node 18+ has built-in `node:test` and `node:assert`.)
 */

const test = require('node:test');
const assert = require('node:assert');

// ── Logic reproduced from index.php (pure units) ──────

// Vietos filtras
function sensorLocationMatch(sensor, locFilter) {
  if (locFilter === 'indoor'  && Number(sensor.is_outdoor) === 1) return false;
  if (locFilter === 'outdoor' && Number(sensor.is_outdoor) !== 1) return false;
  return true;
}

// Reading filter (active = which metrics are enabled)
function sensorMetricMatch(sensor, allKeys, active) {
  if (active.length === allKeys.length) return true;
  if (active.length === 0) return false;
  return active.some(k => sensor[k] !== null && sensor[k] !== undefined && sensor[k] !== '');
}

// Coordinate grouping key
function coordKey(lat, lng) {
  return Number(lat).toFixed(7) + ',' + Number(lng).toFixed(7);
}

// Region extraction (reproduced from index.php regionOf)
function regionOf(s) {
  if (s.city_prefix) return String(s.city_prefix).toUpperCase();
  const m = String(s.label || '').match(/^([A-Za-z]+)/);
  return m ? m[1].toUpperCase() : '';
}

// Region filter (reproduced from part of sensorMatchesFilter)
function sensorRegionMatch(sensor, regionFilter) {
  if (regionFilter === 'all') return true;
  return regionOf(sensor) === regionFilter;
}

// base64 dekodavimas (atribucijos sargas)
function decodeAttr(b64) {
  try { return Buffer.from(b64, 'base64').toString('utf8'); }
  catch { return ''; }
}

// ── Tests ─────────────────────────────────────────────────

test('vietos filtras: visi rodo viską', () => {
  assert.strictEqual(sensorLocationMatch({ is_outdoor: 0 }, 'all'), true);
  assert.strictEqual(sensorLocationMatch({ is_outdoor: 1 }, 'all'), true);
});

test('vietos filtras: patalpos rodo tik indoor', () => {
  assert.strictEqual(sensorLocationMatch({ is_outdoor: 0 }, 'indoor'), true);
  assert.strictEqual(sensorLocationMatch({ is_outdoor: 1 }, 'indoor'), false);
});

test('vietos filtras: lauko rodo tik outdoor', () => {
  assert.strictEqual(sensorLocationMatch({ is_outdoor: 1 }, 'outdoor'), true);
  assert.strictEqual(sensorLocationMatch({ is_outdoor: 0 }, 'outdoor'), false);
});

test('regiono filtras: visi regionai rodo viską', () => {
  assert.strictEqual(sensorRegionMatch({ city_prefix: 'VLN' }, 'all'), true);
  assert.strictEqual(sensorRegionMatch({ city_prefix: 'KAU' }, 'all'), true);
});

test('regiono filtras: tik VLN rodo tik VLN', () => {
  assert.strictEqual(sensorRegionMatch({ city_prefix: 'VLN' }, 'VLN'), true);
  assert.strictEqual(sensorRegionMatch({ city_prefix: 'KAU' }, 'VLN'), false);
});

test('regiono ištraukimas: iš city_prefix arba iš label', () => {
  assert.strictEqual(regionOf({ city_prefix: 'vln' }), 'VLN'); // uppercased
  assert.strictEqual(regionOf({ label: 'KLP5' }), 'KLP');      // from the label
  assert.strictEqual(regionOf({ label: '123' }), '');          // no letters
});

test('regiono filtras: vidurkis skaičiuojamas tik pasirinktam regionui', () => {
  const sensors = [
    { city_prefix: 'VLN', temperature: 22 },
    { city_prefix: 'VLN', temperature: 21 },
    { city_prefix: 'KAU', temperature: 10 },
  ];
  const vln = sensors.filter(s => sensorRegionMatch(s, 'VLN'));
  const avg = vln.reduce((a, s) => a + s.temperature, 0) / vln.length;
  assert.strictEqual(avg, 21.5); // KAU (10) is excluded
});

// OSM/Leaflet and Google Maps use THE SAME WGS84 (lat/lng) coordinate
// system — the sensor coordinates are identical for both providers.
function gmapsPosition(s) { return { lat: Number(s.lat), lng: Number(s.lng) }; }
function leafletPosition(s) { return [Number(s.lat), Number(s.lng)]; }

test('OSM suderinamumas: WGS84 koordinatės identiškos abiem tiekėjams', () => {
  const s = { lat: '54.6872000', lng: '25.2797000' };
  const g = gmapsPosition(s);
  const l = leafletPosition(s);
  assert.strictEqual(g.lat, l[0]); // ta pati platuma
  assert.strictEqual(g.lng, l[1]); // ta pati ilguma
  assert.strictEqual(g.lat, 54.6872);
  assert.strictEqual(g.lng, 25.2797);
});

test('OSM tiekėjo parinkimas pagal rakto buvimą', () => {
  const pick = (key) => (key && key.trim() !== '') ? 'google' : 'osm';
  assert.strictEqual(pick('AIzaSyXXX'), 'google');
  assert.strictEqual(pick(''), 'osm');
  assert.strictEqual(pick('   '), 'osm');
});

test('rodmenų filtras: visi įjungti → viskas matoma', () => {
  const keys = ['temperature', 'humidity', 'co2'];
  assert.strictEqual(sensorMetricMatch({ temperature: 21 }, keys, keys), true);
});

test('rodmenų filtras: nė vieno → nieko', () => {
  const keys = ['temperature', 'humidity'];
  assert.strictEqual(sensorMetricMatch({ temperature: 21 }, keys, []), false);
});

test('rodmenų filtras: dalinis → tik turintys duomenų', () => {
  const keys = ['temperature', 'humidity', 'co2'];
  assert.strictEqual(sensorMetricMatch({ temperature: 21, co2: null }, keys, ['co2']), false);
  assert.strictEqual(sensorMetricMatch({ temperature: 21, co2: 400 }, keys, ['co2']), true);
});

test('koordinačių raktas: 7 skaitmenys', () => {
  assert.strictEqual(coordKey(54.6872, 25.2797), '54.6872000,25.2797000');
  assert.strictEqual(coordKey(54.68720001, 25.27970009), '54.6872000,25.2797001');
});

test('koordinačių raktas: tie patys taškai → tas pats raktas', () => {
  assert.strictEqual(coordKey('54.6872000', '25.2797000'), coordKey(54.6872, 25.2797));
});

test('atribucijos dekodavimas: base64 → tekstas', () => {
  const author = decodeAttr('QWxla3NhbmRy') + decodeAttr('IElndW1lbm92');
  assert.strictEqual(author, 'Aleksandr Igumenov');
});

test('atribucijos dekodavimas: blogas base64 → tuščia (be lūžio)', () => {
  // Buffer.from is lenient, but the function must not throw an error
  assert.doesNotThrow(() => decodeAttr('!!!notbase64!!!'));
});

// ── Index page integrity (regression guards) ──────────────
const fs = require('node:fs');
const path = require('node:path');
const indexSrc = fs.readFileSync(path.join(__dirname, '..', '..', 'index.php'), 'utf8');

test('Leaflet kraunamas SINCHRONIŠKAI (be defer/async)', () => {
  // The OSM→Leaflet shim captures `const L = window.L` at parse time, so
  // leaflet.js MUST NOT be deferred/async — otherwise window.L is undefined
  // when the shim runs and the map fails to initialise.
  const m = indexSrc.match(/<script[^>]*assets\/leaflet\/leaflet\.js[^>]*>/);
  assert.ok(m, 'leaflet.js <script> žyma rasta');
  assert.ok(!/\bdefer\b/.test(m[0]), 'leaflet.js NETURI defer');
  assert.ok(!/\basync\b/.test(m[0]), 'leaflet.js NETURI async');
});

test('OSM shim fiksuoja window.L (priklausomybės dokumentas)', () => {
  assert.ok(indexSrc.includes('const L = window.L;'),
    'shim fiksuoja window.L — todėl Leaflet turi būti įkeltas pirma');
});

test('Vidurkių periodo parinkiklis ir AVG_PERIODS yra', () => {
  assert.ok(indexSrc.includes('AVG_PERIODS'), 'AVG_PERIODS sąrašas yra');
  assert.ok(indexSrc.includes('id="avgPeriod"'), 'periodo parinkiklis yra');
  assert.ok(indexSrc.includes("action: 'averages'"), 'vidurkių užklausa yra');
});
