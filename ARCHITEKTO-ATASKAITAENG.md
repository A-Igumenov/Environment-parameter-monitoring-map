# IT Architect Solution Evaluation
## IoT Sensor Map — Environmental Data Monitoring System

**Evaluation date:** 2026-06-16 (updated 2026-06-22)
**Evaluation type:** Independent fresh assessment (from scratch)
**Evaluator:** IT solutions architect

> English version of `ARCHITEKTO-ATASKAITA.md`.

---

## 1. Executive Summary

This evaluates an IoT sensor aggregation and visualization system for environmental monitoring. The system collects measurements from ESP32/ESP8266 sensors via a REST API and shows them on an interactive map. It is designed to run on ordinary PHP + MySQL shared hosting with no Composer/Node.js dependencies.

**Overall verdict:** The system is **functionally complete, well-structured and secure** for educational/non-commercial use up to 49,800 sensors. The code passes static analysis and **~484 automated tests**, including FR/NFR requirement-traceability tests. **Risk level: LOW.**

| Aspect | Rating | Comment |
|--------|--------|---------|
| Architecture | 🟢 Solid | Modular, zero-build, shared-hosting compatible |
| Functionality | 🟢 Complete | All 24 FRs implemented and tested |
| Security | 🟢 Good | PDO, CSRF, bcrypt, HMAC, rate limit, brute-force |
| Scale | 🟢 Adequate | 49,800 sensors for the defined limit |
| Real-time data | 🟢 Present | SSE with polling fallback |
| Quality & tests | 🟢 Solid | ~484 tests + FR/NFR traceability (no CI dependencies) |
| Documentation | 🟢 Thorough | README with ER, API, guides, firmware |

---

## 2. Architecture strengths

1. **Zero-build portability.** No compilation, npm or Composer step. Upload via FTP, install via browser.
2. **Modular structure.** Responsibilities separated: config.php, auth.php, security.php, cities.php, api/sensors.php.
3. **Thoughtful data model.** Sensor identity is lat+lng+MAC with a UNIQUE index; readings with cascade delete; appropriate indexes.
4. **Progressive real-time layer.** SSE as an optional enhancement with automatic polling fallback.
5. **Map provider flexibility.** The provider is chosen **explicitly** in the admin UI (`MAP_TILE_PROVIDER`, a single source of truth via `effectiveMapProvider()`): Google Maps (needs a key), OpenTopoMap, CARTO Voyager/Light, OpenStreetMap, Yandex (EPSG:3395) or a custom URL. Leaflet is self-hosted (no CDN dependency); a compatibility shim allows switching providers without rewriting the map code. The key field appears only when Google is selected; **cookies and privacy text automatically match the chosen provider**. All use WGS84. **Limits note:** CARTO free tier — 75,000 views/month; OSM/OpenTopoMap — best-effort with no SLA, prohibits bulk/offline. Sufficient for educational non-commercial use; commercial use needs a license or own tile server.
6. **Security by design.** Prepared statements, CSRF, bcrypt, HMAC, rate limiting woven into the architecture.
7. **Consistent UTC time.** The whole system runs in UTC (PHP + MySQL session `+00:00`); time is returned from the API in ISO 8601 with "Z", and the browser converts to the user's local time automatically (DST-safe). The server/browser time-zone ambiguity is eliminated.
8. **Admin hidden in `includes/`.** The admin page is stored alongside server files in the `includes/` folder; `includes/.htaccess` allows HTTP only for `admin*.php` (itself password-protected) but denies `config.php`, `settings.php` and the `admin_file.php` marker (negative lookahead). On first run `index.php` automatically redirects to setup while the config is empty.

---

## 3. Security assessment — 🟢 GOOD

| Area | Status | Details |
|------|--------|---------|
| SQL injection | ✅ | 19 PDO prepared statements; 0 direct interpolation |
| XSS | ✅ | escapeHtml/textContent; CSP header |
| CSRF | ✅ | Tokens in admin forms, hash_equals |
| Authentication | ✅ | Two-stage (email + password); bcrypt cost 12; email hash in DB (`admin_credentials`), password hash in settings file |
| Password policy | ✅ | ≥8 chars, uppercase, digit, special; live validator |
| Brute-force | ✅ | Stage 1: 3 email attempts → 60 min (silent); Stage 2: 2 → 24 h + security log |
| Rate limiting | ✅ | 2 requests/5 min/MAC |
| Sensor authentication | ✅ | HMAC-SHA256 (server + firmware); key assigned in the admin UI (`set_secret`), end-to-end |
| Delete protection | ✅ | Session + password + audit log |
| Security headers | ✅ | CSP, HSTS, X-Frame-Options etc. |
| Config injection | ✅ | var_export (injection-safe) |

**Production conditions:** HTTPS; HMAC for critical sensors; audit log review; delete db-check.php.

---

## 4. Reliability and monitoring — 🟢 GOOD

- ✅ Health-check endpoint for DB + disk status
- ✅ Metrics panel (sensors, offline %, capacity, records/day)
- ✅ Audit log for sensitive actions
- ✅ Automatic DB backup (mysqldump + gzip, rotation)
- ✅ Optional error tracking (webhook → Slack/Sentry)
- ✅ Global error handling (display_errors=0)
- ✅ Data cleanup (cleanup, DATA_RETENTION_DAYS)

**Admin management.** In `manage.php` sensors are grouped by region (city_prefix) with collapsible blocks. Supported: single delete/clear, bulk delete/clear of selected sensors (checkbox), whole-region clear/delete. All sensitive actions are protected by session + password (masked input) + audit log; bulk actions use parameterized `IN (?,...)` with a limit of 1000, region code validated. `index.php` has a region/city filter affecting both the map and the averages. **Averages are computed on the server** (`?action=averages`) over a chosen period (4/6/12/24 h, 7/30/90/180/365 d or "all time"), combined with the region and indoor/outdoor filters.

**SSE note.** Default mode is safe 30 s polling; SSE optional (?sse=1). SSE releases the session lock (session_write_close), does not block other requests. On shared hosting with many viewers, polling or a VPS is recommended.


**SEO visibility and social sharing.** Discoverability elements added: `robots.txt`, `sitemap.xml`, a dynamic `canonical`, Open Graph + Twitter Card tags, schema.org JSON-LD (`index.php`). The admin page (`manage.php`) and diagnostics (`db-check.php`) are marked `noindex` (+ `manage.php` redirects anonymous visitors to login).

**Initial-load performance.** The map reaches interactivity in ~0.3 s. Optimisations: `preconnect` to the active tile origins, Chart.js loaded lazily (only when a history chart is opened), and the attribution guard uses a `MutationObserver` instead of a periodic `setInterval`.

---

## 5. Scale — 🟢 SUITABLE for the defined limit

Target limit: 49,800 sensors, ~166 writes/sec.

| Aspect | Assessment |
|--------|-----------|
| Write load | ✅ Rate limiting guarantees ≤166/sec |
| Read load | 🟡 Viewport (bbox) limiting; without it a correlated subquery |
| Indexes | ✅ idx_coords, idx_city_id, idx_last_seen, idx_sensor_time |
| Pagination | ✅ limit/offset |

**Recommendation:** at full load use viewport queries (already implemented).

---

## 6. Quality and testing — 🟢 SOLID

| Test type | Count |
|-----------|-------|
| Unit + integration (PHP) | 441 |
| PHPUnit-style | 24 |
| Frontend (Node.js) | 19 |
| **Total** | **~484** |

**Strengths:** FR/NFR traceability (RequirementsTest), environment independence (no shell), real HTTP flows; tests run by a single script **with no external dependencies** (no Composer, no CI services).

---

## 7. Final verdict

**The solution IS production-suitable** for educational/non-commercial use up to 49,800 sensors, **with conditions:**

1. HTTPS with a certificate (required)
2. DB backups (cron → api/backup.php)
3. HMAC for critical sensors
4. Monitoring (ERROR_WEBHOOK_URL + audit log)
5. Cleanup (cleanup cron)
6. Delete db-check.php
7. SSE only on a VPS/powerful host (otherwise polling)

**Risk level: LOW.** There are no unresolved critical deficiencies. All 19 defects found have been fixed and covered by regression tests.

---

## 9. Conclusion

The system reflects a mature engineering approach: a clear modular structure, a single source of truth for each concern (CSP, map provider, admin filename, time zone), strong test coverage and security woven into the architecture rather than bolted on. For the defined scope it is ready for production once the listed conditions are met.
