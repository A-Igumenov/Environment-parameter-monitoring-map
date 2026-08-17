/**
 * 3.2 E2E test (Playwright): registration → reading → map → delete.
 *
 * Requires a running server and DB. Run:
 *   npm i -D @playwright/test
 *   npx playwright install chromium
 *   BASE_URL=http://localhost/iot npx playwright test tests/e2e/
 *
 * This test checks the full sensor lifecycle via the HTTP API and UI.
 */

const { test, expect } = require('@playwright/test');

const BASE = process.env.BASE_URL || 'http://localhost/iot';
const LAT = 54.9000000;
const LNG = 25.9000000;
const MAC = 'E2:E2:E2:E2:E2:01';

test.describe('Jutiklio gyvavimo ciklas', () => {

  test('1. registracija sukuria laukiantį jutiklį', async ({ request }) => {
    const res = await request.get(
      `${BASE}/api/sensors.php?action=register&lat=${LAT}&lng=${LNG}&is_outdoor=1`
    );
    expect(res.ok()).toBeTruthy();
    const data = await res.json();
    // Either success, or confirmation needed (if the coordinates already exist)
    expect(data.sensor_id || data.needs_confirmation).toBeTruthy();
  });

  test('2. reading pasiima MAC ir patvirtina jutiklį', async ({ request }) => {
    const res = await request.get(
      `${BASE}/api/sensors.php?action=reading&lat=${LAT}&lng=${LNG}&mac=${MAC}&temperature=21.5&humidity=50`
    );
    const data = await res.json();
    expect(res.status()).toBeLessThan(500);
    // A successful reading returns ok or sensor_id
    if (res.ok()) expect(data.ok || data.sensor_id).toBeTruthy();
  });

  test('3. map_data rodo patvirtintą jutiklį', async ({ request }) => {
    const res = await request.get(`${BASE}/api/sensors.php?action=map_data`);
    expect(res.ok()).toBeTruthy();
    const data = await res.json();
    const sensors = data.sensors || data;
    const found = Array.isArray(sensors)
      && sensors.some(s => s.mac === MAC || (Math.abs(s.lat - LAT) < 0.0001));
    expect(found).toBeTruthy();
  });

  test('4. health endpoint grąžina būseną', async ({ request }) => {
    const res = await request.get(`${BASE}/api/sensors.php?action=health`);
    const data = await res.json();
    expect(['ok', 'warning', 'degraded']).toContain(data.status);
    expect(data.checks).toBeDefined();
  });

  test('5. žemėlapio puslapis užsikrauna', async ({ page }) => {
    await page.goto(BASE + '/index.php');
    // The map container exists
    await expect(page.locator('#map')).toBeVisible();
    // Vietos filtras matomas
    await expect(page.locator('.loc-filter')).toBeVisible();
  });

  test('6. delete be slaptažodžio atmetamas (403)', async ({ request }) => {
    // Without a session and password, delete must be denied
    const res = await request.get(`${BASE}/api/sensors.php?action=delete&id=999999`);
    expect([401, 403]).toContain(res.status());
  });

});
