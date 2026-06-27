# IT Architect Evaluation — Production-level analysis (from scratch)

**Solution:** IoT sensor map (PHP 8.3 + MySQL/MariaDB + vanilla JS)
**Evaluation date:** performed by reviewing the actual code, not the documentation
**Target scope:** up to 49,800 sensors (166 INSERT/sec, 1 submission / 5 min)
**Purpose:** educational / regional / non-commercial use

> English version of `PRODUCTION-VERTINIMAS.md`.

---

## Summary

| Area | Rating | Comment |
|------|--------|---------|
| Functionality | 🟢 High | All requirements implemented, working |
| Security | 🟢 Good | OWASP basics covered; CSP, CSRF, HMAC, rate limit |
| Reliability | 🟢 Good | Health, backup, audit log, error handling |
| Scale | 🟢 Defined | Suitable up to 49,800 sensors; indexes, viewport |
| Testing | 🟢 Solid | ~484 tests (no CI dependencies) |
| Maintainability | 🟢 Good | Bilingual documentation, API versioning |

**Verdict: PRODUCTION-SUITABLE** within the defined scope. **Risk level: LOW.**

---

## 1. Architecture

Single-server PHP + MySQL, no build step (no Composer/npm). Suitable for shared hosting (Hostinger) and XAMPP. Clear layer separation:

- **Presentation:** `index.php` (public map), `includes/admin.php` (setup), `manage.php` (management)
- **API:** `api/sensors.php` (REST), `api/v1/` (versioned), `api/backup.php`, `api/cleanup.php`
- **Logic:** `includes/auth.php` (auth + CSRF), `includes/security.php` (security headers, UTC time, provider), `includes/cities.php`, `includes/config.php`
- **Data:** `schema.sql` (5 tables with indexes and FKs; incl. `admin_credentials` for two-stage login)

**Strengths:** simple deployment, self-contained (zero-build), automatic schema migration, bilingual UI.

---

## 2. Security (per OWASP Top 10)

| OWASP risk | Status | Implementation |
|------------|--------|----------------|
| A01 Access control | 🟢 | Two-stage admin login (email + password); session + password for admin actions; API delete/set_secret require password |
| A02 Cryptography | 🟢 | bcrypt (cost 12); HMAC-SHA256 for sensors |
| A03 Injection | 🟢 | PDO prepared (18 places); 0 direct queries with input |
| A04 Insecure design | 🟢 | Rate limiting, brute-force protection, audit log |
| A05 Misconfiguration | 🟢 | `display_errors=0`, security headers, .htaccess protection |
| A06 Vulnerable components | 🟢 | Minimal dependencies (Leaflet local; Chart.js lazy, only for charts; Maps only when Google is chosen) |
| A07 Authentication | 🟢 | Two-stage: stage 1 3 attempts→60min (silent); stage 2 2→24h+security log; CSRF in all forms |
| A08 Data integrity | 🟢 | HMAC signature, var_export config generation |
| A09 Logging | 🟢 | Audit log, error_log, optional webhook |
| A10 SSRF | 🟢 | No user-controlled external requests |

**Concrete security mechanisms (verified in code):**
- **CSRF:** 10 admin forms with `csrfField()`, checked via `hash_equals` (`requireCsrf()`)
- **Security headers:** CSP (allows Maps + Chart.js), X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, HSTS (over HTTPS) — on all 6 pages
- **Rate limiting:** `reading` limited to 2 requests / 5 min per MAC (DB-based)
- **HMAC:** per-sensor secret assigned in the admin UI (`set_secret`, `🔐 HMAC` button); the server signs the raw GET value (matches firmware); the key is never returned by the API
- **Brute-force:** two-stage — stage 1 3 email attempts → 60 min (silent, anti-enumeration); stage 2 2 → 24 h + security log; IP blockable/unblockable in the admin panel
- **Password:** bcrypt cost 12, in `includes/settings.php` (no self-rewrite); the admin email (login username) is stored only as a hash in the DB (`admin_credentials`)
- **Audit log:** delete/clear actions with IP, time, target_id
- **Admin location:** the admin page lives in `includes/`; `includes/.htaccess` allows only `admin*.php` while denying config.php, settings.php and the admin_file.php marker (negative lookahead so the obscured admin name does not leak)

---

## 3. Reliability and monitoring

- **Health-check** (`?action=health`): DB + disk status
- **Metrics panel** in admin: sensor count, offline %, capacity, records/day
- **DB backups** (`api/backup.php`): mysqldump + gzip, rotation (7 copies), cron or HTTP with a key
- **Error handling:** `display_errors=0` (config.php), `set_exception_handler` (API), structured JSON errors
- **Error tracking:** optional webhook (`ERROR_WEBHOOK_URL` → Sentry/Slack)
- **Cleanup:** MySQL Event or `api/cleanup.php` (cron)
- **Time zone:** the whole system runs in UTC (PHP + MySQL session `+00:00`); timestamps are returned in ISO 8601 with "Z" and the browser converts to local time

---

**Security log (admin).** `manage.php` shows a security log: failed logins, blocked IPs (with reason and remaining time), actions; IPs can be blocked for 24 h / unblocked / entries deleted.

**Averages by period.** `index.php` shows measurement averages computed on the server (`?action=averages`) from the `readings` history over a chosen period (4/6/12/24 h, 7/30/90/180/365 d or "all time"), combined with the region and indoor/outdoor filters.

**SEO visibility.** Added `robots.txt`, `sitemap.xml`, a dynamic `canonical`, Open Graph + Twitter Card, schema.org JSON-LD; admin/diagnostics marked `noindex`.

**Initial-load performance.** ~0.3 s to interactivity; `preconnect` to tiles, lazy Chart.js, attribution guard via `MutationObserver` (no periodic polling).

## 4. Scale (up to 49,800 sensors)

- **Load ceiling:** 49,800 × (1/300s) = 166 INSERT/sec — large headroom for a single MySQL server
- **Indexes:** `(lat,lng,mac)` unique, `(city_prefix,id)` for numbering, `(sensor_id,recorded_at)` for history
- **Viewport:** `map_data` supports bbox + pagination for high density
- **Map load:** one latest reading per sensor + grouping by coordinates
- **Retention:** `DATA_RETENTION_DAYS` limits `readings` growth

Beyond the scope — the path described in the architect report (message queues, time-series DB).

---

## 5. Quality and testing

- **~484 automated tests:** 441 unit/integration + 24 PHPUnit-style + 19 frontend
- **Runner:** a single script (`tests/run.php`) with no CI dependencies; PHP lint via `php -l`
- **E2E + load** frameworks (Playwright, k6)
- **Regression protection:** SQL splitting, HMAC payload, CSRF forms, security header tests
- **New-feature tests:** gas metrics + dynamic filter (FR-7c), history periods (FR-7b), `recorded_at` validation (FR-7d), "ping"/`received_at` (FR-7e), schema↔DB reconciliation; after the CSS/JS extraction the tests also read `assets/app.js`/`styles.css`

---

## 6. Defects identified and fixed (during this review)

The from-scratch review found and fixed:

1. **`display_errors` was not disabled** on HTML pages — errors could reveal paths/data. Added to config.php (and the generated config).
2. **Missing CSP and security headers** on HTML pages (only the API had them). Added `setSecurityHeaders()` on all 6 pages with a CSP allowing Google Maps and Chart.js.

Earlier reviews also fixed: SQL splitting bug (semicolon in a comment), HMAC payload format mismatch (float vs raw string).

**Additionally fixed / improved in the latest review:**

3. **CSP conflict** — `.htaccess` had a static CSP that overlapped with the PHP CSP and blocked map tiles (the browser applies the stricter of the two). Fix: CSP only on the PHP side (single source), `.htaccess` CSP removed.
4. **Explicit map provider** — previously Google vs Leaflet was decided by the presence of the API key, so selecting Yandex with an existing Google key showed Google cookies. Fix: `MAP_TILE_PROVIDER` as a single source of truth (`effectiveMapProvider()`), the key field only for Google, cookies/privacy per provider.
5. **UTC time zone** — `DATETIME` fields had no time zone, so the browser displayed the wrong time. Fix: PHP + MySQL session UTC, the API returns ISO 8601 with "Z", the browser converts to local time automatically.
6. **admin.php moved into `includes/`** — the admin page is hidden in the server folder with a precise `.htaccess` exception (allows `admin*.php`, denies `admin_file.php`/config/settings); `index.php` redirects to setup on first run. All paths fixed without breaking anything.
7. **Test infrastructure resilient to security features** — tests find the admin file dynamically via the marker, so they pass even after the admin file is renamed.
8. **HMAC key was never written to the DB** — the feature only verified, with no way to assign a `secret`. Fix: an admin `set_secret` action + `🔐 HMAC` button in the `manage.php` sensor row; the key is never returned by the API.
9. **GDPR test depended on `schema.sql`** — which the admin auto-deletes after install. Fix: the test also checks the `includes/admin.php` installer (always present).
10. **The map broke when leaflet.js was `defer`-ed** — the OSM compatibility shim captures `window.L` at parse time, and `defer` leaves it `undefined`. Fix: Leaflet loads synchronously; a frontend guard test prevents regression.

---

## 7. Conditions before production

Mandatory before launch:

1. **HTTPS** with a valid certificate (the HSTS header then activates automatically)
2. **DB backups** — a cron calling `api/backup.php`
3. **HMAC signatures** enabled at least for critical sensors (`secret` column + firmware)
4. **Monitoring** — `ERROR_WEBHOOK_URL` and periodic audit log review
5. **Cleanup** running (Event or cron) so `readings` does not grow unbounded
6. **After setup** — change the admin password, enable IP protection (if static IP)

---

## 8. Remaining improvements (non-blocking)

- Marker clustering at high density (Leaflet.markercluster / MarkerClusterer)
- `map_data` correlated subquery optimization at ~50k sensors (precomputed `seq` column + `reading_count` denormalization)
- Response caching with many viewers

These are optimizations, not security or functionality gaps. SSE real-time updates are already implemented (with polling fallback).

---

## Final conclusion

The solution is **functionally complete, secure and production-suitable** for an educational/non-commercial environment up to 49,800 sensors. Code quality is high, security fundamentals (OWASP Top 10) are covered, and test coverage is solid (**~484 tests**, no CI dependencies). There are **no** unresolved critical or high security deficiencies.

**Production risk level (within the defined scope): LOW.**
