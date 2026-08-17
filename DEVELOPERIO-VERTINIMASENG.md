# Developer Solution Evaluation
## IoT Sensor Map — Environmental Data Monitoring System

## 1. Summary

The codebase is **coherent, consistent and easy to maintain** for a small/medium team. The zero-build principle (no Composer/npm) means any developer can upload files via FTP and see the result immediately — no build chain required. The modular `includes/` structure and a single source of truth for each concern (CSP, map provider, admin filename, time zone) reduce the chance of breaking things when changing code.

**Overall developer rating: 🟢 GOOD — comfortable to maintain and extend.**

---

## 2. Code organization

| Aspect | Rating | Comment |
|--------|--------|---------|
| Separation of concerns | 🟢 | `config.php` (settings), `auth.php` (session/permissions), `security.php` (headers, time, provider), `cities.php` (geography), `api/sensors.php` (REST) |
| Single source of truth | 🟢 | `effectiveMapProvider()`, `setSecurityHeaders()`, `adminFileName()`, `toIsoUtc()`, `adminDb()` — each piece of logic in one place |
| Naming clarity | 🟢 | Function and variable names are self-explanatory (LT/EN mix, but consistent) |
| Comments | 🟢 | Critical spots explain "why", not just "what" (e.g. the reasons for the CSP conflict, the lookahead) |
| Duplication | 🟢 | Admin UI = `manage.php`; API = `api/sensors.php` + versioned `api/v1/sensors.php` — clearly distinct names |

---

## 3. Testability — 🟢 SOLID

| Test type | Count | Purpose |
|-----------|-------|---------|
| Unit + integration (PHP) | 441 | Logic, flows, FR/NFR traceability |
| PHPUnit-style | 24 | Class-level checks |
| Frontend (Node.js) | 19 | JS function logic |
| **Total** | **~484** | |

**Strengths for the developer:**
- Tests **do not depend on the shell** (`shell_exec`/`exec`) — they run identically with shared-hosting restrictions on/off.
- Tests **find the admin file dynamically** via the marker, so they do not break after security features (rename, move into `includes/`).
- The FR/NFR traceability test (`RequirementsTest`) links requirements to code — when changing a function you immediately see what must be covered.
- Integration tests use a real DB (`IOT_TEST_DSN`) and real HTTP flows, not just static analysis.

---

## 4. Change safety (how easy it is to break something)

| Scenario | Risk | Protection |
|----------|------|-----------|
| Changing the map provider | 🟢 Low | Single `MAP_TILE_PROVIDER`; cookies/privacy change automatically |
| Renaming the admin file | 🟢 Low | Marker + `adminFileName()`; tests adapt |
| Moving admin.php | 🟢 Low | Done into `includes/`; paths via `__DIR__`, links via `adminFilePath()` |
| Changing time logic | 🟢 Low | Everything UTC; `toIsoUtc()` in one place |
| Adding a new API endpoint | 🟢 Low | `action` parameter pattern; versioning via `api/v1/` |
| Changing Leaflet script loading | 🟡 Medium | The OSM shim captures `window.L` at parse time — Leaflet must stay **synchronous** (no `defer`/`async`); guarded by a frontend test |
| Changing config generation | 🟡 Medium | `writeConfig()` duplicates `db()` — change both places |

---

## 5. Tooling and development environment

- **Stack:** PHP 8.3, MariaDB/MySQL, vanilla JS, Leaflet (self-hosted).
- **Local environment:** XAMPP (Windows) or any PHP+MySQL.
- **Production:** Hostinger (shared hosting) — zero-build allows direct FTP upload.
- **Test runner:** a single script (`php tests/run.php` + `node --test`) **with no external dependencies** — no Composer, no CI services; runs locally and on shared hosting.
- **Packaging:** zip without sensitive/generated files (`admin_pass.php`, `admin_file.php`, `settings.php`, cache).

**Deployment for the developer:** open `index.php` — if the config is not yet filled, you are automatically redirected to the `includes/admin.php` setup wizard. No need to know the admin URL.

---

## 6. Weak spots / technical debt

| Item | Severity | Recommendation |
|------|----------|----------------|
| `writeConfig()` duplicates the `db()` template | 🟡 Low | Consider a single template so two places don't need syncing |
| `sensors.php` naming | 🟢 Resolved | Admin UI renamed to `manage.php`; only the API pair remains |
| `map_data` correlated subqueries | 🟡 Medium | At ~50k sensors, denormalize (`seq`, `reading_count`) |
| LT/EN mix in code | 🟢 Cosmetic | Consistent; comments LT, names mixed |

None of these block development or production.

---
 
## 7. Conclusion

From a developer's perspective the solution is **pleasant to maintain and develop in future**: a clear structure, a single source of truth for each piece of logic, strong test coverage (~484 tests) and high change safety. A new team member can get oriented quickly, and security features (admin rename, move into `includes/`) do not break tests thanks to dynamic file resolution.   
