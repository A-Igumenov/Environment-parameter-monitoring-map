# Testing Documentation and Execution Report
## IoT Sensor Map — Test Use Cases & Execution Report

**Last updated:** 2026-06-24
**Test environment:** PHP 8.3, MariaDB, Node.js · Verified for XAMPP and shared hosting compatibility (no shell functions)

> English version of `tests.md`.

---

## 1. Testing strategy

The system is tested at several levels to ensure coverage of logic, real flows and requirements:

| Level | Tool | Files | Purpose |
|-------|------|-------|---------|
| Unit tests | Custom framework | tests/*.php | Isolated logic (calculations, validation) |
| Integration | Custom framework + MariaDB | ApiIntegrationTest.php | Real DB operations and API flows |
| Requirement traceability | Custom framework | RequirementsTest.php | FR/NFR coverage |
| PHPUnit-style | Shim | tests/phpunit/ | Sensor and security logic |
| Frontend | Node.js test runner | tests/frontend/ | Client-side JS logic |
| E2E | Playwright | tests/e2e/ | Full user scenarios in a browser |
| Load | k6 | tests/load/ | 166 req/s scale check |

**Principles:**
- Tests **do not use shell functions** (shell_exec/exec) — they run identically on shared hosting and XAMPP.
- Integration tests verify **real HTTP flows**, not just the DB.
- Every requirement (FR/NFR) has a traceable test.

---

## 2. Test groups and use cases

### 2.1 Cities: prefixes, distances, capitals

Verifies geography logic — city prefix assignment, distance calculation between coordinates, capital list.

| Use Case | Input | Expected result |
|----------|-------|-----------------|
| UC-CIT-1 | Coordinates in Vilnius | Returns prefix VLN |
| UC-CIT-2 | Two coordinates | Haversine distance in km is accurate |
| UC-CIT-3 | Country code (BR) | Returns Brasilia coordinates |
| UC-CIT-4 | Unknown code (XX) | Fallback to Vilnius |
| UC-CIT-5 | capitalList() | ≥195 states (there are 197) |

### 2.2 Config: injection-safe generation

Verifies that the admin-generated config.php is resistant to code injection via var_export.

| Use Case | Input | Expected result |
|----------|-------|-----------------|
| UC-CFG-1 | Password with `'); echo 'HACKED` | Written as a literal, not executed |
| UC-CFG-2 | Generated config | Valid PHP syntax (token_get_all) |
| UC-CFG-3 | Injection payload | Only 3 legitimate define() lines at the start |
| UC-CFG-4 | LT name with diacritics | UTF-8 preserved (var_export) |
| UC-CFG-5 | writeConfig generation | Has NO setSecurityHeaders declaration (no redeclare) |
| UC-CFG-6 | Generated config | Includes security.php (require_once) |
| UC-CFG-7 | SQL with `;` in a comment | splitSqlStatements splits correctly |

### 2.3 Password: bcrypt + no self-rewrite

Verifies password storage and the strength policy.

| Use Case | Input | Expected result |
|----------|-------|-----------------|
| UC-PWD-1 | New password | bcrypt hash (cost 12) |
| UC-PWD-2 | admin self-rewrite | Does NOT happen (in settings file) |
| UC-PWD-3 | Strong password (Valid123!) | Accepted |
| UC-PWD-4 | Without uppercase / digit / special | Rejected |
| UC-PWD-5 | With space / emoji | Rejected (illegal characters) |

### 2.4 Schema: deletion after install

Verifies that schema.sql is deleted after install (security).

| Use Case | Input | Expected result |
|----------|-------|-----------------|
| UC-SCH-1 | Schema install | schema.sql deleted on success |
| UC-SCH-2 | deleteSchemaFile() | File removed, not archived |

### 2.5 Auth: administrator protection

Verifies authentication, CSRF, security headers, redeclare protection, and the admin-in-includes structure.

| Use Case | Input | Expected result |
|----------|-------|-----------------|
| UC-AUTH-1 | Unauthenticated user | Redirect to login |
| UC-AUTH-2 | CSRF token | csrfField in all admin forms |
| UC-AUTH-3 | Security headers | CSP, HSTS, X-Frame-Options in security.php |
| UC-AUTH-4 | setSecurityHeaders | Declared exactly once (no redeclare) |
| UC-AUTH-5 | config + auth include security.php | require_once in both |
| UC-AUTH-6 | Password strength | 6 cases (accepted/rejected) |
| UC-AUTH-7 | SSE session_write_close | Present (does not block requests) |
| UC-AUTH-8 | SSE opt-in | Polling is the default |
| UC-AUTH-9 | admin.php in `includes/` | `includes/.htaccess` allows `admin*.php`, denies `admin_file.php` (lookahead) |
| UC-AUTH-10 | First-run redirect | Empty config (DB_NAME) → index.php redirects to includes/admin.php |
| UC-AUTH-11 | adminFilePath() | Returns includes/admin*.php; links from root resolve correctly |

### 2.6 GDPR: privacy, cookies

Verifies GDPR compliance.

| Use Case | Input | Expected result |
|----------|-------|-----------------|
| UC-GDPR-1 | privacy.php | Exists, bilingual |
| UC-GDPR-2 | cookies.php | Exists, bilingual |
| UC-GDPR-3 | Cookie banner | Consent mechanism in index.php |
| UC-GDPR-4 | Data rights | Described on the privacy page |
| UC-GDPR-5 | Policy per provider | privacy.php/cookies.php change per effectiveMapProvider(): Google → Google cookies/servers; Yandex → Yandex; OSM/CARTO/OpenTopoMap → the respective provider. Google is NOT mentioned in non-Google branches. |

### 2.7 Brute-force + two-stage login

Verifies protection against password guessing.

| Use Case | Input | Expected result |
|----------|-------|-----------------|
| UC-BF-1 | 3 failed stage-1 (email) attempts | IP blocked, **silent** (anti-enumeration) |
| UC-BF-2 | Stage-1 block | Valid for 60 min |
| UC-BF-3 | Attempt tracking | In a JSON file (no DB) — stage 1 |
| UC-2SA-1 | `admin_credentials` table | Stores `email_hash` (hash, not text) + `password_change_required` |
| UC-2SA-2 | 2 failed stage-2 (email+password) attempts | 24 h block + security-log entry |
| UC-2SA-3 | Setup | Password first, then email → login becomes two-stage |
| UC-2SA-4 | `email_hash` source | Checked in both `schema.sql` and `includes/admin.php` (robust to auto-delete) |

### 2.8 Requirements: FR + NFR traceability

Verifies that every functional and non-functional requirement has an implementation.

| Use Case | Requirement | Check |
|----------|-------------|-------|
| UC-REQ-FR1..21 | All 21 FRs | Endpoints, functions, tables exist |
| UC-REQ-NFR1..14 | All 14 NFRs | Security, scale, compatibility, quality |

Examples:
- **FR-3** → verifies all 8 metrics are supported
- **FR-15** → HMAC uses raw GET values
- **FR-16** → ≥195 states
- **NFR-3** → 0 direct SQL interpolation
- **NFR-8** → tests without shell functions
- **TZ** → PHP + MySQL session UTC; the API returns ISO 8601 with "Z" (toIsoUtc); the browser converts to local time

### 2.9 API+DB: numbering, clearing, deletion

Integration tests with a real MariaDB.

| Use Case | Input | Expected result |
|----------|-------|-----------------|
| UC-API-1 | Registration | Sequence number VLN1, VLN2... |
| UC-API-2 | reading pending flow | MAC assigned, confirmed=1 |
| UC-API-3 | clear_readings | Readings deleted, sensor stays |
| UC-API-4 | delete | Sensor + readings (CASCADE) |
| UC-API-5 | Delete without password | 401/403 |
| UC-API-6 | HMAC payload format | firmware ↔ server match |
| UC-API-7 | Rate limiting | 2/5 min/MAC → 429 |
| UC-API-8 | clear_readings_bulk | Data of multiple sensors (ids=1,2,3) deleted, sensors stay |
| UC-API-9 | delete_bulk | Several selected sensors deleted (CASCADE), others untouched |
| UC-API-10 | clear_region | Data of a whole region (city_prefix) deleted, sensors stay |
| UC-API-11 | delete_region | All region sensors deleted (CASCADE) |
| UC-API-12 | Region code validation | Injection (`../etc`) → 400 |
| UC-API-13 | set_secret (HMAC key) | Password required; key stored in `sensors.secret`, never returned; empty = remove |
| UC-API-14 | averages (by period) | `AVG()` over a period (4h..365d/all), filtered by region and indoor/outdoor |

### 2.10 Frontend: filters, region, averages

Client-side JS logic tests (Node.js test runner), extracted from `index.php`.

| Use Case | Input | Expected result |
|----------|-------|-----------------|
| UC-FE-1 | Location filter (all/indoor/outdoor) | Only matching sensor type shown |
| UC-FE-2 | Reading filter | Only those with the selected metric |
| UC-FE-3 | Region filter "all" | Sensors of all regions shown |
| UC-FE-4 | Region filter "VLN" | Only VLN sensors |
| UC-FE-5 | regionOf (from city_prefix or label) | Correct region code in uppercase |
| UC-FE-6 | Averages by period + region | Server `?action=averages`; period selector (AVG_PERIODS); combined with region and indoor/outdoor filters |
| UC-FE-7 | Coordinate grouping key | 7 digits, same points → same key |
| UC-FE-8 | Attribution base64 decode | Correct text; bad base64 → empty (no crash) |
| UC-FE-9 | OSM coordinate compatibility | WGS84 lat/lng identical for Google and Leaflet providers |
| UC-FE-10 | Map provider selection | Explicit MAP_TILE_PROVIDER: google → Google Maps (needs key); others → Leaflet. effectiveMapProvider() — single source |
| UC-FE-11 | Tile provider configuration | CFG.tileProvider → presets (carto_voyager/carto_light/osm/custom); local Leaflet (assets/leaflet/) |
| UC-FE-12 | Leaflet loaded synchronously | `leaflet.js` tag has NO `defer`/`async` (the OSM shim captures `window.L` at parse time) |
| UC-FE-13 | Averages UI present | `AVG_PERIODS`, `#avgPeriod` selector, `action: 'averages'` request |
| UC-SEO-1 | SEO visibility | `robots.txt`, `sitemap.xml`, `canonical`, Open Graph, JSON-LD; `manage.php`/`db-check.php` → `noindex` |

---

## 3. Execution Report

**Run date:** 2026-06-24
**Environment:** PHP 8.3.6, MariaDB 10.x, Node.js · Linux (also verified on XAMPP/Windows)

### 3.1 Summary

| Test group | Tests | Passed | Failed | Status |
|------------|-------|--------|--------|--------|
| Cities: prefixes, distances, capitals | — | ✅ | 0 | 🟢 |
| Config: injection-safe generation | — | ✅ | 0 | 🟢 |
| Password: bcrypt + no self-rewrite | — | ✅ | 0 | 🟢 |
| Schema: deletion after install | — | ✅ | 0 | 🟢 |
| Auth: administrator protection | — | ✅ | 0 | 🟢 |
| GDPR: privacy, cookies | — | ✅ | 0 | 🟢 |
| Brute-force + two-stage login | — | ✅ | 0 | 🟢 |
| Requirements: FR + NFR traceability | — | ✅ | 0 | 🟢 |
| API+DB: numbering, clearing, deletion | — | ✅ | 0 | 🟢 |
| **Core (PHP) total** | **441** | **441** | **0** | 🟢 |
| PHPUnit-style | 24 | 24 | 0 | 🟢 |
| Frontend (Node.js) | 19 | 19 | 0 | 🟢 |
| **GRAND TOTAL** | **484** | **484** | **0** | 🟢 |

**Execution time:** ~780 ms (core PHP tests).

### 3.2 Environment compatibility check

Tests were run in two modes to confirm shared hosting / XAMPP compatibility:

| Mode | Result |
|------|--------|
| With shell enabled (shell_exec/exec available) | 441 + 24 ✅ |
| With shell DISABLED (disable_functions) | 441 + 24 ✅ |

**Conclusion:** the result is identical in both cases — tests do not depend on shell functions, so they work on any shared hosting and XAMPP.

**Map tiles note.** Leaflet is self-hosted (`assets/leaflet/`), so the library does not depend on a CDN. Tiles are loaded from an external provider (default OpenTopoMap; CARTO free limit 75,000 views/month; alternatives: OSM without SLA, custom URL). Tile network reachability does not depend on the test environment and is checked only in the browser; tests verify provider selection logic (UC-FE-10, UC-FE-11), not network reachability.

**Admin file rename note.** After renaming `admin.php` via the admin page (a security feature), the file becomes `admin_XXXX.php`, and the new name is stored in the `includes/admin_file.php` marker. Tests find the admin file **dynamically** via `TestCase::adminFileName()` (reads the marker, falls back to `admin.php`), so all admin-file-reading tests pass even after a rename. The admin page lives in `includes/`; the `.htaccess` exception allows `admin*.php` but denies the `admin_file.php` marker.

### 3.3 Functional flow test (HTTP)

The full user flow verified with a real PHP server:

| Step | Result |
|------|--------|
| Sensor registration | ✅ sensor_id returned |
| First reading (MAC assignment) | ✅ ok, confirmed=1 |
| map_data (sensor visible, with city_prefix) | ✅ count, regions |
| health endpoint | ✅ status=ok |
| SSE stream | ✅ event: update |
| clear_readings_bulk (ids=1,2) | ✅ sensors_affected, deleted_readings |
| delete_region (region=KAU) | ✅ deleted_sensors, other regions untouched |
| Delete with correct password (settings.php) | ✅ executes |
| Delete with wrong password | ✅ 403 password_required |
| Region code injection (`../etc`) | ✅ 400 |
| index.php region filter + CFG.cityNames | ✅ VLN→Vilnius |
| index.php provider = non-google | ✅ Leaflet/OSM, mapProvider='osm', shim |
| index.php provider = google | ✅ Google Maps, mapProvider='google' |
| privacy.php/cookies.php (non-google) | ✅ respective provider text (Google NOT mentioned) |
| privacy.php/cookies.php (google) | ✅ Google text, link to Google policy |
| privacy.php/cookies.php (yandex) | ✅ Yandex text, link to Yandex policy |
| First-run redirect (empty config) | ✅ index.php → 302 includes/admin.php |
| Timestamps (map_data, history) | ✅ ISO 8601 UTC with "Z" |

### 3.4 Requirement coverage (FR/NFR)

| Category | Requirements | Covered by tests |
|----------|--------------|------------------|
| Functional (FR) | 21 | 21 (100%) ✅ |
| Non-functional (NFR) | 14 | 14 (100%) ✅ |

Every FR/NFR has at least one traceable test in the RequirementsTest file.

---

## 4. How to run the tests

### 4.1 Via the browser (admin)

1. Log in to the admin panel.
2. Open `tests.php`.
3. Tests run in the same process (no shell), the result is shown on the page.

### 4.2 Via the command line

```bash
# Core PHP tests (with DB, if available)
php tests/run.php

# Frontend tests
node --test tests/frontend/frontend.test.js

# E2E (requires Playwright)
npx playwright test tests/e2e/

# Load (requires k6)
k6 run tests/load/reading-load.js
```

### 4.3 For integration tests (DB)

Integration tests require a test DB. Set it via environment variables:
```bash
export IOT_TEST_DSN="mysql:host=localhost;dbname=iot_test"
export IOT_TEST_USER="iot"
export IOT_TEST_PASS="password"
php tests/run.php
```
Without these variables the integration tests are gracefully skipped (normal in production, since they modify data).

---

## 5. Running and maintenance

Tests run via **a single script with no external dependencies** (no Composer, no CI services): `php tests/run.php` + `node --test tests/frontend/frontend.test.js`. PHP syntax is checked via `php -l`. Suitable for both local (XAMPP) and shared-hosting runs.
