# IoT Sensor Map — Environmental Monitoring System

A real-time, map-based IoT sensor aggregation platform for monitoring environmental metrics. Sensors (ESP32 / ESP8266) send measurements to a REST API, and the map displays their location and latest data. Built to run on a standard PHP + MySQL server (Hostinger, XAMPP) with no Composer or Node.js dependencies.

> 🇱🇹 **Lietuviška versija:** see [`README.md`](README.md).

### 📚 Related documentation

| Document | Contents |
|----------|----------|
| [`README.md`](README.md) | 🇱🇹 This document in Lithuanian |
| [`ARCHITEKTO-ATASKAITAENG.md`](ARCHITEKTO-ATASKAITAENG.md) | Architecture report — system structure, components, design rationale ([LT](ARCHITEKTO-ATASKAITA.md)) |
| [`DEVELOPERIO-VERTINIMASENG.md`](DEVELOPERIO-VERTINIMASENG.md) | Developer assessment — code review, implementation decisions ([LT](DEVELOPERIO-VERTINIMAS.md)) |
| [`PRODUCTION-VERTINIMASENG.md`](PRODUCTION-VERTINIMASENG.md) | Production-readiness assessment — security, deployment, maintenance ([LT](PRODUCTION-VERTINIMAS.md)) |
| [`testsENG.md`](testsENG.md) | Testing documentation — test suites, coverage, traceability ([LT](tests.md)) |

Policy pages (HTML, bilingual): privacy — [`privacy.php`](privacy.php), cookies — [`cookies.php`](cookies.php).

---

## Table of Contents

1. [Relevance — environmental monitoring](#1-relevance)
2. [Functional requirements (FR)](#2-functional-requirements-fr)
3. [Non-functional requirements (NFR)](#3-non-functional-requirements-nfr)
4. [Tools and technology stack](#4-tools-and-technology-stack)
5. [Evaluation of alternatives](#5-evaluation-of-alternatives)
6. [Database structure and model](#6-database-structure-and-model)
7. [ER diagram](#7-er-diagram)
8. [Implementation notes](#8-implementation-notes)
9. [ESP32 / ESP8266 firmware examples](#9-esp32--esp8266-firmware-examples)
10. [API reference with examples](#10-api-reference-with-examples)
11. [Administrator guide](#11-administrator-guide)
12. [User guide](#12-user-guide)
13. [Deployment](#13-deployment)
14. [License and sources](#14-license-and-sources)

---

## 1. Relevance

**Environmental data monitoring.** Air quality, temperature, humidity, CO₂ concentration, particulate matter (PM1/PM2.5/PM10), pollen, and background radiation directly affect human health and well-being. Traditional government measurement stations are expensive and sparse — in densely populated areas there may be only a few for an entire city. This creates large "blind" zones where actual conditions remain unknown.

**The solution concept.** Inexpensive IoT sensors (ESP32 / ESP8266 with digital sensors) enable a dense, citizen-run measurement network. Each sensor costs a few euros, is mounted indoors or outdoors, and sends measurements every 5 minutes. This system collects them, ties them to a geographic location, and displays everything on an interactive map so anyone can see environmental conditions in their area in real time.

**Use cases:**
- **Education (STEAM)** — schools and universities can build sensors and learn IoT, data collection, and visualization.
- **Citizen science** — communities monitor air pollution, microclimate, and allergen levels.
- **Local government** — supplementary data alongside official stations; identifying "hot spots".
- **Research** — dense spatio-temporal data for microclimate and pollution-dispersion analysis.

**Target scale.** The system is designed to monitor up to **49,800 sensors** on a single commodity server, each sending ≤1 measurement every 5 minutes (~166 inserts per second at peak). The primary city is Vilnius (prefix VLN), but the map can be centered on any of **197 world capitals**.

---

## 2. Functional Requirements (FR)

Functional requirements define **what** the system does. Each has a unique ID so it can be traced to tests (see `tests.md`).

| ID | Requirement | Description |
|----|-------------|-------------|
| **FR-1** | Sensor registration | The user can register a new sensor by providing coordinates (lat/lng) and type (indoor/outdoor). The system assigns a city prefix and sequence number (e.g. VLN1). |
| **FR-2** | MAC assignment on first reading | The sensor's MAC address is captured during the first measurement, not at registration. This allows preparing firmware without coordinates. |
| **FR-3** | Measurement ingestion | The API accepts measurements (temperature, humidity, CO₂, PM1, PM2.5, PM10, pollen, radiation, plus gases/air quality: alcohol, methane, propane, butane, lpg, hydrogen, co, smoke, ammonia, nox, benzene, air_quality, co2_equiv — 21 metrics total) via HTTP GET/POST. Optionally accepts a device UTC timestamp `recorded_at` (validated). |
| **FR-4** | Identity by lat+lng+MAC | A sensor is uniquely identified by the triple (lat, lng, MAC). The same MAC at different coordinates means separate sensors. |
| **FR-5** | Map display | A public page shows all confirmed sensors on a map. Markers are **clustered by zoom level** (zoom-aware, Web-Mercator pixel grid): zooming out merges nearby markers, zooming in splits them. Optionally — a **"Visible in viewport only"** filter (shows only sensors in the current map view). The map provider is chosen **explicitly** in the admin UI (`MAP_TILE_PROVIDER`): **Google Maps** (needs an API key), **OpenTopoMap**, **CARTO**, **OpenStreetMap** or **Yandex** (via Leaflet, no key). All use the same WGS84 coordinate system. Cookies and privacy text update automatically based on the choice. |
| **FR-6** | Latest measurement display | Each sensor's marker shows the last measurement and "online/offline" status (based on `last_seen`). The popup also shows a **"Ping"** = server insert time − sensor send time (`received_at − recorded_at`). |
| **FR-7** | History chart | The user can view a selected sensor's history as a chart (Chart.js) with a **period selector** (1 d / 1 w / 2 w / 1 mo / 3 mo / 6 mo / 12 mo); long ranges are aggregated server-side (AVG into time buckets). |
| **FR-8** | Filtering | The map can be filtered by sensor type (indoor/outdoor), displayed metric, and **region/city**. The metric filter is **dynamic** — only metrics that have data in the DB are shown. The filter affects both the map and the averaged data. |
| **FR-9** | Real-time updates | The map refreshes automatically — 30 s polling by default, optionally SSE (Server-Sent Events) in real time. |
| **FR-10** | Administration panel | A password-protected panel for DB configuration, schema installation, sensor management, and metrics. |
| **FR-11** | Sensor deletion / clearing | The administrator can: (a) delete one sensor or clear its measurements; (b) **select several sensors (checkbox) and bulk-delete them or their data**; (c) **clear or delete an entire region** (city_prefix). All actions require the password; deletion cascades. |
| **FR-12** | Data export | Measurements can be exported in CSV or JSON format. |
| **FR-13** | Health check | The `health` endpoint returns DB and disk status for monitoring. |
| **FR-14** | Metrics panel | The administrator sees sensor count, offline percentage, record capacity, and records per day. |
| **FR-15** | HMAC authentication | Sensors can sign measurements with an HMAC-SHA256 signature. The shared key (`secret`) is assigned to a sensor **in the admin interface** (the "🔐 HMAC" button in the sensor row) and stored in the DB; the same key is configured in the firmware. Once a key is set, unsigned or incorrectly signed measurements are rejected (HTTP 401), protecting against forged data. The key is never returned by the API. |
| **FR-16** | Country selection | The administrator can pick a country from 197; the map automatically centers on its capital. Country and capital names are shown bilingually (LT / EN). |
| **FR-17** | GDPR pages | The system has bilingual privacy and cookie pages with a consent mechanism. The policy adapts automatically to the map provider (Google Maps or OpenStreetMap) — describing what data is sent and to whom. |
| **FR-18** | Audit log | Sensitive actions (deletion, clearing, key assignment, login blocks) are logged in an audit log with IP and timestamp. |
| **FR-19** | Two-stage login | Admin login proceeds in two stages: 1) username (email); 2) email + password. The email is stored only as a hash in the DB (see NFR-5). On first run, both the password and the email are configured. |
| **FR-20** | Security-log panel | The admin panel shows a security log: failed logins, blocked IPs and actions. The administrator can block / unblock an IP address and delete log entries (see NFR-6). |
| **FR-21** | Averages by period | The home page shows measurement averages (for every metric), computed on the server from the `readings` history over a chosen period: 4/6/12/24 h, 7/30/90/180/365 d, or "all time" (from the first sensor start). The calculation combines with the existing region (city) and indoor/outdoor filters. |
| **FR-22** | Device time and "ping" | The sensor fetches time from NTP at the 0 meridian (UTC) and sends it as `recorded_at`. The server separately records the insert time (`received_at`). The map popup shows a **Ping = `received_at − recorded_at`** (always, when a reading exists). |
| **FR-23** | Schema ↔ DB reconciliation | When installing the DB, the live DB structure is compared to `schema.sql`. If they match — the schema is deleted; if the schema is broader/newer — the DB is **first extended** with the missing elements (`ADD COLUMN IF NOT EXISTS`), match is verified, and **only then** the schema is deleted. If reconciliation fails — `schema.sql` is kept. |
| **FR-24** | Separated static assets | CSS is extracted to `assets/styles.css` (loaded via PHP `include`), JS to `assets/app.js` (imported via `<script src>`). PHP injections remain only in a small `window.CFG` block. |

---

### Traceability

All FRs trace to tests in `tests.md` (FR-1…FR-24, NFR-1…NFR-12). Full suite: **441 internal tests · 24 PHPUnit · 19 frontend** (0 failures).

---

## 3. Non-functional Requirements (NFR)

Non-functional requirements define **how well** the system performs (quality attributes).

| ID | Category | Requirement |
|----|----------|-------------|
| **NFR-1** | Scale | The system must serve up to 49,800 sensors, ~166 inserts/sec at peak, on a single server. |
| **NFR-2** | Performance | `map_data` and `reading` requests must return within <100 ms under typical load; viewport queries limit the number of sensors returned. |
| **NFR-3** | Security — SQL | All DB access via PDO prepared statements; no direct input interpolation. |
| **NFR-4** | Security — XSS | All user input is escaped (escapeHtml) before display; CSP headers are restrictive. |
| **NFR-5** | Security — authentication | **Two-stage login**: username = email (stored only as a hash in the `admin_credentials` DB table), password with bcrypt (cost 12) in a separate settings file. Stage 1 — email only; stage 2 — email + password. Strength policy (≥8 chars, uppercase, digit, special). |
| **NFR-6** | Security — brute-force | **Stage 1:** 3 wrong emails → 60-min IP block (no error message — protects against email enumeration). **Stage 2:** 2 wrong credentials → 24h block + a security-log entry + a password-change request. The admin panel has a security log with IP block/unblock and entry deletion. |
| **NFR-7** | Security — rate limiting | `reading` is limited to 2 requests per 5 min per MAC (flooding protection). |
| **NFR-8** | Compatibility | Runs on PHP 8.1+ on standard shared hosting (Hostinger, XAMPP) without Composer/Node.js. Tests use no shell functions (which may be disabled). |
| **NFR-9** | Portability | Zero-build: no compilation; upload via FTP, install via browser. |
| **NFR-10** | Reliability | Errors not shown in the browser (`display_errors=0`), written to a log; automatic DB backup (cron). |
| **NFR-11** | Privacy / GDPR | Cookie consent, bilingual privacy pages, data retention period (DATA_RETENTION_DAYS). |
| **NFR-12** | Accessibility | The map works on mobile and desktop devices; bilingual interface (LT/EN). |
| **NFR-13** | Maintainability | Modular structure (`includes/`); ~466 automated tests (PHP unit and integration, PHPUnit-style, frontend) run by a single script **with no external dependencies** (no Composer, no CI services) — suitable for both local and shared-hosting runs. |
| **NFR-14** | Data resilience | Schema installed resiliently (quote-aware SQL splitting); configuration via var_export (injection-resistant). |

---

## 4. Tools and Technology Stack

| Layer | Technology | Version | Source / documentation |
|-------|-----------|---------|------------------------|
| Server language | PHP | 8.1+ | https://www.php.net/docs.php |
| Database | MySQL / MariaDB | 10.4+ | https://mariadb.org/documentation/ |
| DB access | PDO (prepared statements) | — | https://www.php.net/manual/en/book.pdo.php |
| Map (with key) | Google Maps JavaScript API | v3 | https://developers.google.com/maps/documentation/javascript |
| Map (no key) | Leaflet (self-hosted) + OpenTopoMap/CARTO/OSM/Yandex tiles | 1.9.4 | https://leafletjs.com/ · https://carto.com/basemaps/ · https://www.openstreetmap.org/ |
| Geocoding | Google Geocoding API | — | https://developers.google.com/maps/documentation/geocoding |
| Charts | Chart.js | 4.x | https://www.chartjs.org/docs/latest/ |
| Client logic | Vanilla JavaScript (ES6+) | — | https://developer.mozilla.org/en-US/docs/Web/JavaScript |
| Real-time | Server-Sent Events (EventSource) | — | https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events |
| Sensor platform | ESP32 / ESP8266 | — | https://docs.espressif.com/projects/esp-idf/ |
| Firmware | Arduino framework | — | https://docs.arduino.cc/ |
| HMAC (ESP8266) | BearSSL | — | https://www.bearssl.org/ |
| HMAC (ESP32) | mbedTLS | — | https://www.trustedfirmware.org/projects/mbed-tls/ |
| Passwords | bcrypt (password_hash) | — | https://www.php.net/manual/en/function.password-hash.php |
| Tests (PHP) | Custom minimal framework + PHPUnit-style shim | — | (no Composer; see `tests/`) |
| Tests (frontend) | Node.js test runner | 18+ | https://nodejs.org/api/test.html |
| E2E tests | Playwright | — | https://playwright.dev/ |
| Load tests | k6 | — | https://k6.io/docs/ |
| Test runner | Single PHP/Node script (`tests/run.php`) | — | (no CI dependencies; runs locally and on shared hosting) |

**Architecture principles:**
- **Zero-build** — no Webpack/Vite/npm build step. All files run directly.
- **No server dependencies** — no Composer required; PHPUnit replaced with a custom shim.
- **Progressive** — the map works with polling; SSE is an optional enhancement.

---

## 5. Evaluation of Alternatives

Before building a custom solution, existing platforms were evaluated. All are conceptually similar but did not fit for specific reasons (code was **not copied**).

| Solution | Advantages | Why it did not fit this project |
|----------|-----------|--------------------------------|
| **[Sensor.Community](https://sensor.community/)** (formerly luftdaten.info) | Large global air-quality network, open platform | Centralized global infrastructure; hard to self-host for education; fixed data model |
| **[OpenSenseMap](https://opensensemap.org/)** | Open-source, education-friendly, REST API | Node.js + MongoDB stack — unsuitable for PHP shared hosting; requires server administration |
| **[ThingsBoard](https://thingsboard.io/)** | Powerful IoT platform, rules, dashboards | Java + complex infrastructure (Cassandra/PostgreSQL); too heavy for an educational project on shared hosting |
| **[Grafana](https://grafana.com/) + [InfluxDB](https://www.influxdata.com/)** | Professional time-series dashboards | Requires a dedicated server/VPS; not map-centric; complex setup |
| **[Home Assistant](https://www.home-assistant.io/)** | Broad IoT integration | Designed for home automation, not a public city-wide map |
| **[Leaflet](https://leafletjs.com/) + [OpenStreetMap](https://www.openstreetmap.org/)** | Open, free, no API key | **Implemented as the alternative** — when no Google Maps key is set, OSM via Leaflet is used automatically (see 8.10). Google Maps remains an option for its integrated geocoding |

**Rationale for the chosen solution.** A **custom PHP + MySQL solution** was built because:
1. **Shared-hosting compatibility** — runs on any €3/month PHP host without a VPS.
2. **Zero-build** — students can upload via FTP and run via the browser.
3. **Educational value** — all code is transparent, no black boxes.
4. **Full control** — the data model is tailored exactly to environmental metrics.

---

## 6. Database Structure and Model

The database consists of **5 tables**. The model is designed so that a sensor's identity is `lat + lng + MAC`, while measurements are separate records with cascading deletion.

### 6.1 Table `sensors` — sensors

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT AUTO_INCREMENT PK | Primary key |
| `lat` | DECIMAL(10,7) | Latitude (7 digits ≈ 1 cm precision) |
| `lng` | DECIMAL(10,7) | Longitude |
| `mac` | VARCHAR(17) NULL | WiFi MAC (AA:BB:CC:DD:EE:FF); NULL while awaiting first reading |
| `is_outdoor` | TINYINT(1) | 0 = indoor, 1 = outdoor |
| `secret` | VARCHAR(64) NULL | Optional HMAC shared-secret |
| `city_prefix` | VARCHAR(10) | City code (e.g. VLN) |
| `confirmed` | TINYINT(1) | 0 = awaiting confirmation, 1 = confirmed |
| `registered_at` | DATETIME | Registration time |
| `last_seen` | DATETIME | Last measurement time (online/offline) |

**Indexes:** `UNIQUE uq_coords_mac(lat,lng,mac)`, `idx_coords(lat,lng)`, `idx_city_id(city_prefix,id)`, `idx_last_seen`, `idx_confirmed`.

### 6.2 Table `readings` — measurements

| Column | Type | Units |
|--------|------|-------|
| `id` | BIGINT AUTO_INCREMENT PK | — |
| `sensor_id` | INT FK→sensors | — |
| `recorded_at` | DATETIME | sensor measurement/send time (UTC; if the device sends none — server time) |
| `received_at` | DATETIME | server insert time (DEFAULT CURRENT_TIMESTAMP) — used for the "ping" |
| `temperature` | DECIMAL(6,2) | °C |
| `humidity` | DECIMAL(5,2) | % |
| `co2` | DECIMAL(8,2) | ppm (real CO₂, NDIR) |
| `pm1` | DECIMAL(8,2) | µg/m³ |
| `pm2_5` | DECIMAL(8,2) | µg/m³ |
| `pm10` | DECIMAL(8,2) | µg/m³ |
| `grains` | DECIMAL(8,2) | grains/m³ |
| `radiation` | DECIMAL(8,4) | µSv/h |
| `alcohol`, `methane`, `propane`, `butane`, `lpg`, `hydrogen`, `co`, `smoke`, `ammonia`, `nox`, `benzene` | DECIMAL(8,2) | ppm (gases — MQ-2/4/6/8/135 family) |
| `air_quality` | DECIMAL(8,2) | air-quality index (MQ-135) |
| `co2_equiv` | DECIMAL(8,2) | CO₂ equivalent, ppm (MQ-135 — **not** real CO₂) |

**Indexes:** `idx_sensor_time(sensor_id,recorded_at)`, `idx_recorded_at`. **FK:** `ON DELETE CASCADE` (deleting a sensor removes its measurements automatically). All metric columns are NULL — a sensor sends only the metrics it has. **"Ping"** = `received_at − recorded_at` (transmission latency), shown in the map popup. **Migration:** gas columns and `received_at` are added to existing DBs idempotently (`ALTER TABLE … ADD COLUMN IF NOT EXISTS`).

### 6.3 Table `rate_limits` — request limiting

| Column | Type | Description |
|--------|------|-------------|
| `rl_key` | VARCHAR(120) PK | Key (e.g. MAC or IP) |
| `window_start` | INT | Unix time, window start |
| `counter` | INT | Request count in window |

### 6.4 Table `audit_log` — audit log

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT AUTO_INCREMENT PK | — |
| `occurred_at` | DATETIME | Event time |
| `actor_ip` | VARCHAR(45) | IP (IPv4/IPv6) |
| `action` | VARCHAR(40) | Action (e.g. delete_sensor) |
| `target_id` | INT NULL | Affected sensor |
| `details` | VARCHAR(255) | Additional information |

**Index:** `idx_audit_time(occurred_at)`.

### 6.5 Table `admin_credentials` — administrator credentials

A single-row (`id = 1`) table for the two-stage login. It stores **only the email hash** (the username), never plaintext. The password hash stays in the `includes/settings.php` file.

| Column | Type | Purpose |
|--------|------|---------|
| `id` | TINYINT (=1) | The single row |
| `email_hash` | VARCHAR(255) | One-way hash of the admin email (username) |
| `password_change_required` | TINYINT(1) | Flag: set after a 24h stage-2 lockout |
| `updated_at` | DATETIME | Last change time |

---

## 7. ER Diagram

Entity-Relationship diagram. `sensors` ↔ `readings` is a one-to-many relationship with cascading deletion. `rate_limits` and `audit_log` are independent helper tables.

```
┌─────────────────────────────────┐
│            sensors              │
├─────────────────────────────────┤
│ PK  id            INT           │
│     lat           DECIMAL(10,7) │
│     lng           DECIMAL(10,7) │
│     mac           VARCHAR(17)   │◄──┐ UNIQUE(lat,lng,mac)
│     is_outdoor    TINYINT(1)    │   │
│     secret        VARCHAR(64)   │   │
│     city_prefix   VARCHAR(10)   │   │
│     confirmed     TINYINT(1)    │   │
│     registered_at DATETIME      │   │
│     last_seen     DATETIME      │   │
└────────────┬────────────────────┘   │
             │ 1                      │
             │                        │
             │ N     ON DELETE CASCADE│
             ▼                        │
┌─────────────────────────────────┐   │
│           readings              │   │
├─────────────────────────────────┤   │
│ PK  id            BIGINT        │   │
│ FK  sensor_id     INT ──────────┼───┘
│     recorded_at   DATETIME      │
│     temperature   DECIMAL(6,2)  │
│     humidity      DECIMAL(5,2)  │
│     co2           DECIMAL(8,2)  │
│     pm1           DECIMAL(8,2)  │
│     pm2_5         DECIMAL(8,2)  │
│     pm10          DECIMAL(8,2)  │
│     grains        DECIMAL(8,2)  │
│     radiation     DECIMAL(8,4)  │
└─────────────────────────────────┘

┌──────────────────────┐   ┌──────────────────────────┐
│     rate_limits      │   │        audit_log         │
├──────────────────────┤   ├──────────────────────────┤
│ PK rl_key VARCHAR    │   │ PK  id          BIGINT   │
│    window_start INT  │   │     occurred_at DATETIME │
│    counter      INT  │   │     actor_ip    VARCHAR  │
└──────────────────────┘   │     action      VARCHAR  │
                           │     target_id   INT      │
                           │     details     VARCHAR  │
                           └──────────────────────────┘
```

**Cardinality:** 1 sensor → N measurements (1:N). Deleting a sensor automatically removes all its measurements (CASCADE). `rate_limits` and `audit_log` have no direct FK relationship — they are linked logically (via MAC/IP and target_id).

---

## 8. Implementation Notes

This section describes important implementation decisions and "lessons learned" while building the system.

### 8.1 Sensor identity and MAC assignment

A sensor is identified by the triple **(lat, lng, MAC)**. At registration, only coordinates and type are provided — **the MAC is not yet known**. The MAC is captured during the first measurement:
- **Exact match** (lat+lng+MAC already exists) → a measurement is added.
- **Pending record** (lat+lng with MAC=NULL) → the MAC is assigned, the sensor is confirmed (`confirmed=1`).
- **No match** → 403 (the sensor is not registered at those coordinates).

This allows preparing firmware **without coordinates** — the sensor sends its MAC automatically, while the location is set during registration in the browser.

### 8.2 HMAC signature format (critical)

The server signs the **raw GET value**, not a processed float. Firmware sends `String(lat, 7)` = `"54.6872000"` (with trailing zeros). If the server signed the PHP float `54.6872` (without zeros), the signatures would **never match**. Therefore:

```
payload = rawLat + "|" + rawLng + "|" + mac
sig = HMAC-SHA256(payload, secret)   // lowercase hex
```

The order is strict: **lat|lng|mac**. Metrics are not part of the signature.

**Key provisioning.** The shared key (`secret`) is assigned to a sensor in the admin interface (`manage.php` → the sensor row's "🔐 HMAC" button; requires the admin password). The entered key is stored in the `sensors.secret` DB column, and the same key is configured in the firmware (`const char* SECRET`). Typical flow: the sensor registers and is confirmed by its first measurement → the administrator assigns it a key → from then on every measurement must carry a valid `sig`, otherwise it is rejected (HTTP 401). An empty key disables HMAC for that sensor. The key is never returned by the API (only an "enabled/disabled" status is shown).

### 8.3 SQL schema installation

The schema is installed with a **quote-aware** SQL splitter (`splitSqlStatements`), not a naive `explode(';')`. A naive split would cut a statement at a semicolon inside a `COMMENT '...'`. Additionally, comments contain no `;`, making the schema resilient even to naive splitting.

### 8.4 SSE and the session lock

The SSE (Server-Sent Events) endpoint **immediately releases the session lock** (`session_write_close()`). PHP locks the session file during a request; if SSE kept it open, **all other requests from the same user would block**. Also, SSE is **optional** (the default is safe 30 s polling), because on shared hosting open connections can exhaust PHP processes.

### 8.5 Shared security-headers file

`setSecurityHeaders()` is defined in **a single file** (`includes/security.php`), included by both `config.php` and `auth.php`. Previously the function was in two files and caused a "Cannot redeclare" error when a page loaded both. The admin-generated `config.php` also includes `security.php`, not its own copy.

### 8.6 Tests without shell functions

Tests do **not** use `shell_exec`/`exec`/`passthru` — these are often disabled on shared hosting (and on Windows XAMPP php.exe may not be in PATH). Instead of subprocesses, static code analysis is used (`token_get_all`, `preg_match`), which works identically everywhere.

### 8.7 Shared password-hash source

The admin password hash is stored in `includes/settings.php` (an associative array with `admin_password_hash`). **Both the admin login (`admin.php`) and the API deletion guard (`auth.php`) read the hash from the same place.** Previously `auth.php` read only the old `admin_pass.php`, so a correct password was rejected when deleting data. Now `adminPasswordHash()` checks `settings.php` first, then — for backward compatibility — the old `admin_pass.php`.

### 8.8 Bulk and region deletion

In `manage.php`, sensors are grouped by region (city_prefix). For bulk deletion the API accepts an ID list (`ids=1,2,3`) — safely split with dedupe and a 1000-element limit, using a parameterized `IN (?,?,...)` query. Region operations (`clear_region`/`delete_region`) use `DELETE ... JOIN`/`WHERE city_prefix`, with the region code validated (`[A-Z0-9]{1,10}`). All bulk/region actions have the same protection as single ones (session + password + audit log).

### 8.9 Region filter and averages

In `index.php`, filtering is centralized in `sensorMatchesFilter()`, used by both the map rendering (`renderMarkers`) and the averaged-data calculation (`renderAverages`). So the region/city, location (indoor/outdoor), and metric filters affect both at once — selecting a region recalculates the averages for that region only. Password entry uses a masked modal (not `prompt()`).

### 8.10 Map providers (Google / OSM / Yandex)

The map provider is chosen **explicitly** in the admin UI (`MAP_TILE_PROVIDER`) — a single source of truth (`effectiveMapProvider()` in `includes/security.php`):
- **Google Maps** (`google`) → requires an API key (the field is shown only when Google is selected).
- **OpenTopoMap / CARTO / OpenStreetMap / Yandex / Custom** → via Leaflet, no key.

In the admin UI the Google key field appears **only** when Google is selected; the Custom URL field only when Custom is selected. **Cookies and privacy pages adapt automatically** to the chosen provider (e.g. selecting Yandex shows Yandex privacy info, not Google).

**Leaflet is self-hosted** (`assets/leaflet/`, not from a CDN), so the library loads reliably on shared hosting without external requests or CSP issues.

**Configurable tile provider.** Following the [OSM tile usage policy](https://operations.osmfoundation.org/policies/tiles/) (which recommends not hard-coding the URL and allowing provider switching), the tile provider is chosen in the admin UI (`MAP_TILE_PROVIDER`):
- `google` — Google Maps (needs an API key; field shown only when selected)
- `opentopomap` (default) — OpenTopoMap topographic (OSM + SRTM); often reachable when other servers are blocked
- `carto_voyager` — CARTO colorful with streets
- `carto_light` — CARTO light
- `osm` — OpenStreetMap standard (`tile.openstreetmap.org`)
- `yandex` — Yandex Maps (regional, uses EPSG:3395 projection; useful when Western servers are blocked)
- `custom` — custom URL (`MAP_TILE_URL`)

This helps because different networks/regions can reach different servers. If tiles don't appear, the admin can switch the provider without changing code. Night mode uses CARTO dark. All providers use OSM data with proper attribution. If tiles still fail (network block), the map functionality — markers, filters, averages — works, and an error is logged to the browser console.

#### 8.10.1 Tile provider limits and restrictions

Tile servers belong to external organisations and have usage restrictions. These matter when choosing a provider:

| Provider | Limit / restriction | Notes |
|----------|--------------------|-------|
| **OpenStreetMap** (`osm`) | No fixed numeric limit, but best-effort with no SLA; bulk download, scraping and offline use are prohibited | Intended for modest interactive use. Requires a valid `User-Agent` and `Referer` (browsers send these automatically). Bulk/prefetch is forbidden. See [OSM tile policy](https://operations.osmfoundation.org/policies/tiles/) |
| **OpenTopoMap** (`opentopomap`) | Small volunteer project, limited capacity; no fixed limit but asks not to overload | Topographic style (OSM + SRTM elevation). EPSG:3857. Separate domain `tile.opentopomap.org` — often reachable when others are blocked. Max zoom 17 |
| **CARTO** (`carto_voyager`, `carto_light`) | **75,000 mapviews per month** free | Commercial use, private apps, asset-tracking or SLA require a commercial license (from ~$6000/yr). Cached requests (via CDN) don't count toward the limit |
| **Google Maps** (with key) | Pay-as-you-go, billed per 1000 requests | Requires an API key and billing enabled. Has daily quota and per-minute request limits |
| **Yandex Maps** (`yandex`) | No clear free tile limit, but ToS recommends using their JS API with a key | Uses EPSG:3395 projection (map is created with the matching CRS). Useful in regions where Western servers are blocked. Direct tile use may change — for production the Yandex JS API with a key is recommended (developer.tech.yandex.ru) |
| **Custom** (`custom`) | Depends on the chosen provider | Use your own tile server or another OSM-based service |

**Recommendations for this project** (educational/non-commercial, low traffic):
- **CARTO** (default) is fine — the project is non-commercial and 75,000 mapviews/month is more than enough for typical school/university use.
- If traffic grows or commercial use is needed, a commercial CARTO license or a self-hosted tile server is required.
- **The browser caches tiles** (HTTP cache), so repeat views don't overload the server.
- If tiles are critical and guarantees are needed — self-host tiles (see [switch2osm.org](https://switch2osm.org/)) or use a commercial provider.

The key point — **OSM and Google use the same WGS84 (lat/lng) coordinate system**, so all existing sensor, coordinate-grouping, and registration logic works unchanged. To avoid rewriting the map code, a thin **compatibility layer (shim)** emulates the subset of the `google.maps` API used (`Map`, `Marker` with `setIcon`/`setLabel`/`setMap`/`addListener`, `SymbolPath.CIRCLE`, `setOptions`) via Leaflet. Sensor markers are rendered as round SVG `divIcon`s with labels; day/night mode switches tiles (CARTO Voyager / CARTO dark). Registration works via coordinates and the local list of 197 capitals, so a geocoding key is not required.

---

## 9. ESP32 / ESP8266 Firmware Examples

Below are complete, working firmware examples for sensors. All send measurements via HTTP(S) GET, take **several samples per cycle and average them** (reliability), fetch **time from the internet (NTP) at the 0 meridian (UTC)**, and send it as `recorded_at` (for measurement correlation / the "ping"). Full `.ino` files live in `firmware/`:

| File | Sensors | Features |
|------|---------|----------|
| `firmware/esp_dht22_minimal/` | DHT22 | **Minimal** example (ESP8266 **and** ESP32): NTP UTC time, 5× averaging, `recorded_at` sending. No MQ/HMAC/EEPROM. |
| `firmware/esp8266_dht22_mq135/` | DHT22 + MQ-135 | Multi-gas (MQUnifiedsensor): co2_equiv, alcohol, co, ammonia, benzene, air_quality; EEPROM R0; temp/humidity correction; 5× averaging. |
| `firmware/esp8266_dht22_mq135_hmac/` | DHT22 + MQ-135 | HMAC-SHA256 (BearSSL) + HTTPS; EEPROM RZero; NTP UTC + longitude-based offset; `recorded_at`. |

### 9.0 Minimal example (DHT22 + NTP UTC time + averaging)

The simplest case, working on both ESP8266 and ESP32. Demonstrates three essentials: NTP time at the 0 meridian (UTC), multi-sample averaging, and sending the UTC timestamp.

```cpp
#if defined(ESP32)
  #include <WiFi.h>
  #include <HTTPClient.h>
#else
  #include <ESP8266WiFi.h>
  #include <ESP8266HTTPClient.h>
  #include <WiFiClientSecure.h>
#endif
#include <DHT.h>
#include <time.h>

const int SAMPLES = 5;                              // averaging window

void setupTime() {
  configTime(0, 0, "pool.ntp.org", "time.google.com"); // 0,0 → UTC (0 meridian)
  time_t now = time(nullptr);
  unsigned long t0 = millis();
  while (now < 1000000000UL && millis() - t0 < 15000) { delay(250); now = time(nullptr); }
}

String utcIso() {                                   // ISO 8601 UTC → recorded_at
  time_t now = time(nullptr); struct tm g; gmtime_r(&now, &g);
  char b[25]; strftime(b, sizeof(b), "%Y-%m-%dT%H:%M:%SZ", &g); return String(b);
}

void loop() {
  float tSum = 0, hSum = 0; int n = 0;
  for (int i = 0; i < SAMPLES; i++) {               // multi-sample average
    float t = dht.readTemperature(), h = dht.readHumidity();
    if (!isnan(t) && !isnan(h)) { tSum += t; hSum += h; n++; }
    delay(200);
  }
  if (n) {
    String url = "/api/sensors.php?action=reading&lat=...&lng=...&mac=" + WiFi.macAddress()
               + "&temperature=" + String(tSum/n, 1)
               + "&humidity="    + String(hSum/n, 1)
               + "&recorded_at=" + utcIso();         // UTC timestamp
    // … HTTP(S) GET …
  }
  delay(60000);
}
```

> Full file with WiFi/HTTP(S) logic: `firmware/esp_dht22_minimal/esp_dht22_minimal.ino`.

### 9.1 ESP8266 (with BearSSL HMAC)

```cpp
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <bearssl/bearssl_hmac.h>

const char* SSID   = "your-wifi";
const char* WIFIPW = "password";
const char* HOST   = "https://your-server.com/iot/api/sensors.php";
const char* MY_LAT = 54.6872000;
const char* MY_LNG = 25.2797000;

// HMAC shared-secret — must match the sensor's `secret` value in the DB.
// Leave empty ("") if the sensor has no secret (signature optional).
const char* SECRET = "my-sensor-secret-123";

String macAddr;

void setup() {
  Serial.begin(115200);
  WiFi.mode(WIFI_STA);
  WiFi.begin(SSID, WIFIPW);
  while (WiFi.status() != WL_CONNECTED) { delay(500); }
  macAddr = WiFi.macAddress();        // AA:BB:CC:DD:EE:FF
}

// HMAC-SHA256(payload, SECRET) → lowercase hex (matches PHP hash_hmac)
String hmacSha256(const String& payload) {
  if (strlen(SECRET) == 0) return "";
  br_hmac_key_context kc;
  br_hmac_key_init(&kc, &br_sha256_vtable, SECRET, strlen(SECRET));
  br_hmac_context hc;
  br_hmac_init(&hc, &kc, 0);
  br_hmac_update(&hc, payload.c_str(), payload.length());
  unsigned char out[32];
  br_hmac_out(&hc, out);
  String hex;
  for (int i = 0; i < 32; i++) {
    char b[3]; sprintf(b, "%02x", out[i]); hex += b;
  }
  return hex;
}

void sendReading(float temp, float hum) {
  if (WiFi.status() != WL_CONNECTED) return;
  WiFiClientSecure client;
  client.setInsecure();   // for demo; use a certificate in production
  HTTPClient http;

  // Signed text: lat|lng|mac (SAME order as on the server)
  String payload = String(MY_LAT, 7) + "|" + String(MY_LNG, 7) + "|" + macAddr;
  String sig = hmacSha256(payload);

  String url = String(HOST) + "?action=reading"
             + "&lat=" + String(MY_LAT, 7)
             + "&lng=" + String(MY_LNG, 7)
             + "&mac=" + macAddr
             + "&temperature=" + String(temp, 1)
             + "&humidity="    + String(hum, 1);
  if (sig.length() > 0) url += "&sig=" + sig;

  http.begin(client, url);
  int code = http.GET();
  Serial.printf("HTTP %d\n", code);
  http.end();
}

void loop() {
  sendReading(21.4, 55.0);
  delay(300000);   // 5 min — per the reading rate limit
}
```

### 9.2 ESP32 (with mbedTLS HMAC)

```cpp
#include <WiFi.h>
#include <HTTPClient.h>
#include <mbedtls/md.h>

const char* SSID   = "your-wifi";
const char* WIFIPW = "password";
const char* HOST   = "https://your-server.com/iot/api/sensors.php";
const char* MY_LAT = 54.6872000;
const char* MY_LNG = 25.2797000;
const char* SECRET = "my-sensor-secret-123";

String macAddr;

void setup() {
  Serial.begin(115200);
  WiFi.mode(WIFI_STA);
  WiFi.begin(SSID, WIFIPW);
  while (WiFi.status() != WL_CONNECTED) { delay(500); }
  macAddr = WiFi.macAddress();
}

// HMAC-SHA256 with mbedTLS → lowercase hex
String hmacSha256(const String& payload) {
  if (strlen(SECRET) == 0) return "";
  byte hmacResult[32];
  mbedtls_md_context_t ctx;
  mbedtls_md_type_t md_type = MBEDTLS_MD_SHA256;
  mbedtls_md_init(&ctx);
  mbedtls_md_setup(&ctx, mbedtls_md_info_from_type(md_type), 1);
  mbedtls_md_hmac_starts(&ctx, (const unsigned char*)SECRET, strlen(SECRET));
  mbedtls_md_hmac_update(&ctx, (const unsigned char*)payload.c_str(), payload.length());
  mbedtls_md_hmac_finish(&ctx, hmacResult);
  mbedtls_md_free(&ctx);
  String hex;
  for (int i = 0; i < 32; i++) {
    char b[3]; sprintf(b, "%02x", hmacResult[i]); hex += b;
  }
  return hex;
}

void sendReading(float temp, float hum, float co2) {
  if (WiFi.status() != WL_CONNECTED) return;
  HTTPClient http;

  String payload = String(MY_LAT, 7) + "|" + String(MY_LNG, 7) + "|" + macAddr;
  String sig = hmacSha256(payload);

  String url = String(HOST) + "?action=reading"
             + "&lat=" + String(MY_LAT, 7)
             + "&lng=" + String(MY_LNG, 7)
             + "&mac=" + macAddr
             + "&temperature=" + String(temp, 1)
             + "&humidity="    + String(hum, 1)
             + "&co2="         + String(co2, 0);
  if (sig.length() > 0) url += "&sig=" + sig;

  http.begin(url);
  int code = http.GET();
  Serial.printf("HTTP %d\n", code);
  http.end();
}

void loop() {
  sendReading(21.4, 55.0, 650);
  delay(300000);   // 5 min
}
```

### 9.3 Registration flow (firmware setup)

1. **Flash the firmware** with your WiFi credentials and coordinates. Leave `SECRET` empty or set it if you will use HMAC.
2. **Register the sensor** on the map page (by clicking a location or entering coordinates) — choose indoor/outdoor type.
3. **Power on the sensor** — the first measurement captures the MAC and confirms the sensor.
4. The sensor appears on the map with real-time updating data.

---

## 10. API Reference with Examples

All endpoints are reachable via `api/sensors.php?action=<action>`. The versioned variant is `api/v1/sensors.php`. Responses are JSON with structured error codes.

> **Time zone (UTC).** The whole system runs in UTC (zone 0): PHP (`date_default_timezone_set('UTC')`) and the MySQL session (`SET time_zone = '+00:00'`). All time fields (`recorded_at`, `last_seen`, `registered_at`) are returned as **ISO 8601 UTC with "Z"** (e.g. `2026-06-20T14:30:00Z`). The browser automatically converts to the user's local time via `toLocaleString()` — a DST-safe "0 + browser offset", so no manual offset math is needed.

| Endpoint | Access | Description |
|----------|--------|-------------|
| `?action=map_data` | Public | All confirmed sensors + latest measurement. Optional viewport (`sw_lat/sw_lng/ne_lat/ne_lng`) + pagination (`limit/offset`) |
| `?action=stream` | Public | SSE real-time stream (5 s interval, only on change). Optional (`?sse=1`) |
| `?action=history&id=N` | Public | Sensor N's 24-hour history for the chart |
| `?action=averages&period=24h&region=VLN` | Public | Metric averages over a period (4h/6h/12h/24h/7d/30d/90d/180d/365d/all), filtered by region and indoor/outdoor (`location`) |
| `?action=reading&lat=&lng=&mac=&<metrics>[&sig]` | Public | Measurement submission. Rate-limited (2/5 min/MAC). HMAC `sig` if the sensor has a secret |
| `?action=register&lat=&lng=&is_outdoor=` | Public | New sensor registration |
| `?action=health` | Public | DB + disk status |
| `?action=export&format=csv\|json` | Public | Data export |
| `?action=stats` | 🔒 Admin | Metrics statistics |
| `?action=delete&id=N` | 🔒 Admin | Sensor deletion (with measurements). Requires password |
| `?action=clear_readings&id=N` | 🔒 Admin | Measurement-only deletion. Requires password |
| `?action=delete_bulk&ids=1,2,3` | 🔒 Admin | Bulk deletion of selected sensors (CASCADE) |
| `?action=clear_readings_bulk&ids=1,2,3` | 🔒 Admin | Bulk data clearing of selected sensors |
| `?action=delete_region&region=VLN` | 🔒 Admin | Delete all sensors in a region (CASCADE) |
| `?action=clear_region&region=VLN` | 🔒 Admin | Clear all data in a region (sensors stay) |
| `?action=set_secret&id=N&secret=...` | 🔒 Admin | Assign a sensor's HMAC key (empty = remove). Requires the password. The key is never returned |

### 10.1 Measurement submission (`reading`)

**Request (without HMAC):**
```
GET /api/sensors.php?action=reading&lat=54.6872000&lng=25.2797000&mac=AA:BB:CC:DD:EE:FF&temperature=22.5&humidity=55.0
```

**Request (with HMAC):**
```
GET /api/sensors.php?action=reading&lat=54.6872000&lng=25.2797000&mac=AA:BB:CC:DD:EE:FF&temperature=22.5&humidity=55.0&sig=e9370a742c1b8ee51758dcdf5e8d7e6ee73672af91a9d087cd6beb4a9459fe7a
```

**Success response:**
```json
{
  "ok": true,
  "sensor_id": 42,
  "reading_id": 1337,
  "stored": ["temperature", "humidity"]
}
```

**Error responses:**
```json
{ "error": "Per dažni siuntimai.", "code": "rate_limited" }          // 429
{ "error": "Neteisingas parašas (sig).", "code": "invalid_signature" } // 401
{ "error": "Jutiklis neregistruotas.", "code": "not_registered" }     // 403
```

Supported metrics: `temperature` (°C), `humidity` (%), `co2` (ppm — real CO₂), `pm1`, `pm2_5`, `pm10` (µg/m³), `grains` (grains/m³), `radiation` (µSv/h), plus gases/air quality: `alcohol`, `methane`, `propane`, `butane`, `lpg`, `hydrogen`, `co`, `smoke`, `ammonia`, `nox`, `benzene` (ppm), `air_quality` (index), `co2_equiv` (CO₂ equivalent, ppm).

**Optional `recorded_at` (UTC timestamp).** A device may send `&recorded_at=<ISO 8601 UTC "…Z">` (or `&ts=<epoch>`) — the sensor's measurement/send time. Validated to a sane window (≤30 d old, ≤5 min in the future for clock skew); otherwise the server time is used. Used for measurement correlation and the "ping" (= `received_at − recorded_at`) on the map.

### 10.2 Map data (`map_data`)

**Request:**
```
GET /api/sensors.php?action=map_data
GET /api/sensors.php?action=map_data&sw_lat=54.5&sw_lng=25.1&ne_lat=54.8&ne_lng=25.4&limit=500
```

**Response:**
```json
{
  "sensors": [
    {
      "id": 42, "label": "VLN1", "lat": 54.6872, "lng": 25.2797,
      "mac": "AA:BB:CC:DD:EE:FF", "is_outdoor": 1, "online": 1,
      "temperature": 22.5, "humidity": 55.0, "co2": null,
      "co2_equiv": 612, "air_quality": 87,
      "recorded_at": "2026-06-16T14:30:00Z", "received_at": "2026-06-16T14:30:07Z"
    }
  ],
  "count": 1, "total": 1, "bbox": false, "paginated": false
}
```

### 10.3 Registration (`register`)

**Request:**
```
GET /api/sensors.php?action=register&lat=54.6872&lng=25.2797&is_outdoor=1
```

**Response:**
```json
{
  "ok": true, "sensor_id": 42, "label": "VLN1",
  "city": "Vilnius", "distance_km": 1.9, "geo_source": "local"
}
```

### 10.4 Health check (`health`)

**Response:**
```json
{
  "status": "ok",
  "checks": { "database": "ok", "disk_free_mb": 9882.7, "disk": "ok" },
  "timestamp": "2026-06-16T14:30:00+00:00"
}
```

### 10.5 SSE stream (`stream`)

```
GET /api/sensors.php?action=stream
```
Returns `text/event-stream`:
```
event: update
data: {"sensors":[...],"count":42,"ts":"2026-06-16T14:30:00+00:00"}

: heartbeat

event: bye
data: {"reason":"max_duration"}
```

---

## 11. Administrator Guide

### 11.1 First launch (setup)

1. Open `https://your-server.com/iot/includes/admin.php (or just open index.php — it redirects automatically)`.
2. **Step 1 — DB configuration.** Enter the DB host, name, user, password, and (optional) Google Maps API key. **If you leave the key empty, OpenStreetMap is used for the map.** Pick a country (the map will center on its capital). Click "Save configuration".
3. **Step 2 — schema installation.** Click "Install schema" — the 5 tables are created. After installation, `schema.sql` is automatically deleted (security).
4. **Step 3 — password.** Set the admin password (requirements: ≥8 characters, uppercase letter, digit, special character). A live indicator shows progress.
5. **Step 4b — administrator email.** Set the administrator email (it becomes the login **username**). The email is stored only as a hash in the `admin_credentials` DB table. Once both the password and the email are set, login becomes **two-stage**.

**Two-stage login (after setup):** stage 1 asks only for the email (username); stage 2 asks for the email + password. See NFR-5/NFR-6 for the lockout policy.

### 11.2 Security settings

- **IP restriction** — you can restrict admin access to your IP only ("Enable protection with my IP").
- **Admin file rename** — rename `admin.php` to `admin_<name>.php` for additional obscurity.
- **db-check.php** — delete this diagnostic file after setup.
- **Security log** — the admin panel shows a security log: failed logins, currently blocked IPs (with reason and remaining time), and actions. You can **block** a suspicious IP for 24h, **unblock** it, or **delete** log entries (one or all). After repeated failed logins, a password-change prompt is shown.

### 11.3 Sensor management

The `manage.php` page (requires an admin session and password for sensitive actions):

- **Grouping by region** — sensors are grouped into collapsible blocks by region (city_prefix, e.g. VLN). The header shows the region code and sensor count.
- **Region actions** — in each region header: "Clear region data" (keeps sensors) and "Delete region" (removes all region sensors with data).
- **Bulk selection** — after opening a region, select several sensors with checkboxes (or "Select all"), then "Clear selected data" or "Delete selected".
- **Single actions** — each row has "Clear data" / "Delete sensor".
- **Filter** — indoor/outdoor checkboxes at the top.
- **HMAC key** — each row's "🔐 HMAC" button assigns a shared signing key (8–64 chars) to the sensor, or removes it. Configure the same key in the sensor firmware. When enabled, a green "🔐 HMAC ✓" indicates the sensor accepts only signed measurements (see 8.2).
- **Password confirmation** — every sensitive action requires the admin password (entered in a masked dialog, not in plain text).
- **Metrics panel** — sensor count, offline %, capacity, records/day.
- **Tests** (`tests.php`) — run the automated test suite in the browser.

### 11.4 Maintenance

- **Backups** — configure a cron job calling `api/backup.php` (mysqldump + gzip, 7-day rotation).
- **Cleanup** — `api/cleanup.php` removes old measurements per `DATA_RETENTION_DAYS`.
- **Monitoring** — set `ERROR_WEBHOOK_URL` for error notifications (Slack/Sentry).

---

## 12. User Guide

### 12.1 Using the map

1. Open the main page (`index.php`).
2. **Accept cookies** (GDPR bar at the bottom).
3. On the map you will see sensors as markers. Green = online, gray = offline.
4. **Click a marker** — you will see the sensor's name, type, and latest measurements.
5. **History** — in the popup, click "History" for a 24-hour chart.

### 12.2 Filters

In the filter sidebar (right of the map):

- **Location** — show all / indoor only / outdoor only sensors.
- **Region / city** — pick a specific region (e.g. Vilnius) or all. Selecting a region makes the map and the **averaged values** show only that region's data.
- **Metrics** — choose which metrics are displayed.
- **Averaged values** — below the filters, the averages of visible sensors' metrics are shown (recalculated per active filters).

All filters work together and are remembered (localStorage).

### 12.3 Sensor registration

1. Click "Register sensor".
2. Click a location on the map or enter coordinates.
3. Choose the type (indoor/outdoor).
4. Confirm — you will receive a sensor label (e.g. VLN5).
5. Power on the physical sensor — after the first submission it will appear on the map.

### 12.4 Real-time mode

By default the map refreshes every 30 s. To enable real-time SSE updates, append `?sse=1` to the URL (recommended only on a VPS or powerful host).

---

## 13. Deployment

### 13.1 Requirements

- PHP 8.1+ with the PDO MySQL extension
- MySQL 5.7+ / MariaDB 10.4+
- Apache with mod_rewrite (or nginx)
- Google Maps JavaScript API key — **optional**. Without it, OpenStreetMap is used automatically (free, no key).

### 13.2 Steps

1. **Upload the files** via FTP to the server (e.g. `public_html/iot/`).
2. **Open** `admin.php` in the browser and follow the setup wizard (see 11.1).
3. **Install the schema** and set the password.
4. **Configure cron** for backups and cleanup.
5. **Enable HTTPS** (required for HMAC and security).

### 13.3 XAMPP (for local testing)

1. Upload to `C:\xampp\htdocs\iot\`.
2. Start Apache and MySQL via the XAMPP control panel.
3. Create the DB and user via phpMyAdmin.
4. Open `http://localhost/iot/includes/admin.php (or index.php — it redirects)`.

---

## 14. License and Sources

**License.** CC BY-NC 4.0 (educational / non-commercial, attribution required). See `LICENSE`. The author and institution must remain visible in the footer of every page.

**Author:** Aleksandr Igumenov
**Institution:** Vilnius University Methodical STEAM Education Centre

**Sources.** Technologies: PHP, MySQL/MariaDB, vanilla JavaScript, Google Maps JavaScript API, Google Geocoding API, Chart.js. Conceptually similar open-source projects (loock at an alternatives): Sensor.Community, OpenSenseMap, ThingsBoard, Leaflet.
