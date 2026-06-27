# Testavimo Dokumentacija ir Atlikimo Ataskaita
## IoT Sensorių Žemėlapis — Test Use Cases & Execution Report

**Paskutinis atnaujinimas:** 2026-06-22
**Testų aplinka:** PHP 8.3, MariaDB, Node.js · Patvirtinta XAMPP ir shared hosting suderinamumu (be shell funkcijų)

---

## 1. Testavimo strategija

Sistema testuojama keturiais lygmenimis, kad būtų užtikrinta tiek logikos, tiek realių srautų, tiek reikalavimų aprėptis:

| Lygmuo | Įrankis | Failai | Paskirtis |
|--------|---------|--------|-----------|
| Vienetų testai | Savas karkasas | tests/*.php | Izoliuota logika (skaičiavimai, validacija) |
| Integraciniai | Savas karkasas + MariaDB | ApiIntegrationTest.php | Realios DB operacijos ir API srautai |
| Reikalavimų atsekamumas | Savas karkasas | RequirementsTest.php | FR/NFR aprėptis |
| PHPUnit-stiliaus | Shim | tests/phpunit/ | Jutiklių ir saugumo logika |
| Frontend | Node.js test runner | tests/frontend/ | Klientinė JS logika |
| E2E | Playwright | tests/e2e/ | Pilni naudotojo scenarijai naršyklėje |
| Apkrovos | k6 | tests/load/ | 166 req/s mastelio patikra |

**Principai:**
- Testai **nenaudoja shell funkcijų** (shell_exec/exec) — veikia shared hosting ir XAMPP identiškai.
- Integraciniai testai tikrina **realius HTTP srautus**, ne tik DB.
- Kiekvienas reikalavimas (FR/NFR) turi atsekamą testą.

---

## 2. Testų grupės ir use case'ai

### 2.1 Cities: prefiksai, atstumai, sostinės

Tikrina geografijos logiką — miesto prefiksų priskyrimą, atstumų skaičiavimą tarp koordinačių, sostinių sąrašą.

| Use Case | Įvestis | Laukiamas rezultatas |
|----------|---------|---------------------|
| UC-CIT-1 | Koordinatės Vilniuje | Grąžinamas prefiksas VLN |
| UC-CIT-2 | Dvi koordinatės | Haversine atstumas km tikslus |
| UC-CIT-3 | Šalies kodas (BR) | Grąžinamos Brasilia koordinatės |
| UC-CIT-4 | Nežinomas kodas (XX) | Fallback į Vilnius |
| UC-CIT-5 | capitalList() | ≥195 valstybės (yra 197) |

### 2.2 Config: injekcijai atspari generacija

Tikrina, kad admin sugeneruotas config.php yra atsparus kodo injekcijai per var_export.

| Use Case | Įvestis | Laukiamas rezultatas |
|----------|---------|---------------------|
| UC-CFG-1 | Slaptažodis su `'); echo 'HACKED` | Įrašomas kaip literalas, nevykdomas |
| UC-CFG-2 | Sugeneruotas config | Galiojanti PHP sintaksė (token_get_all) |
| UC-CFG-3 | Injekcijos payload | Tik 3 teisėti define() eilutės pradžioje |
| UC-CFG-4 | LT pavadinimas su diakritika | UTF-8 išsaugomas (var_export) |
| UC-CFG-5 | writeConfig generavimas | NEturi setSecurityHeaders deklaracijos (be redeclare) |
| UC-CFG-6 | Sugeneruotas config | Įtraukia security.php (require_once) |
| UC-CFG-7 | SQL su `;` komentare | splitSqlStatements skaido teisingai |

### 2.3 Password: bcrypt + savęs neperrašymas

Tikrina slaptažodžio saugojimą ir stiprumo politiką.

| Use Case | Įvestis | Laukiamas rezultatas |
|----------|---------|---------------------|
| UC-PWD-1 | Naujas slaptažodis | bcrypt hash (cost 12) |
| UC-PWD-2 | admin.php savęs perrašymas | NEvyksta (settings faile) |
| UC-PWD-3 | Stiprus slaptažodis (Valid123!) | Priimamas |
| UC-PWD-4 | Be didžiosios / skaičiaus / spec | Atmetamas |
| UC-PWD-5 | Su tarpu / emoji | Atmetamas (neleistini simboliai) |

### 2.4 Schema: suderinimas ir ištrynimas po diegimo

Tikrina, kad reali DB palyginama su schema, prireikus praplečiama, ir tik atitikus schema.sql ištrinamas (saugumas).

| Use Case | Įvestis | Laukiamas rezultatas |
|----------|---------|---------------------|
| UC-SCH-1 | Schemos diegimas (DB atitinka) | schema.sql ištrinamas po sėkmės |
| UC-SCH-2 | deleteSchemaFile() | Failas pašalinamas, ne archyvuojamas |
| UC-SCH-3 | Schema platesnė/naujesnė už DB | DB praplečiama (`ADD COLUMN IF NOT EXISTS`), tik tada schema.sql trinama |
| UC-SCH-4 | Po migracijos DB neatitinka | schema.sql IŠLAIKOMAS, parodoma, ko trūksta |

### 2.5 Auth: administratoriaus apsauga

Tikrina autentifikaciją, CSRF, saugumo antraštes, redeclare apsaugą.

| Use Case | Įvestis | Laukiamas rezultatas |
|----------|---------|---------------------|
| UC-AUTH-1 | Neprisijungęs naudotojas | Peradresavimas į login |
| UC-AUTH-2 | CSRF žetonas | csrfField visose admin formose |
| UC-AUTH-3 | Saugumo antraštės | CSP, HSTS, X-Frame-Options security.php |
| UC-AUTH-4 | setSecurityHeaders | Deklaruota lygiai 1 kartą (be redeclare) |
| UC-AUTH-5 | config + auth įtraukia security.php | require_once abiejuose |
| UC-AUTH-6 | Slaptažodžio stiprumas | 6 atvejai (priimti/atmesti) |
| UC-AUTH-7 | SSE session_write_close | Yra (neblokuoja užklausų) |
| UC-AUTH-8 | SSE opt-in | Polling numatytasis |
| UC-AUTH-9 | admin.php `includes/` kataloge | `includes/.htaccess` leidžia `admin*.php`, draudžia `admin_file.php` (lookahead) |
| UC-AUTH-10 | First-run nukreipimas | Tuščias config (DB_NAME) → index.php nukreipia į includes/admin.php |
| UC-AUTH-11 | adminFilePath() | Grąžina includes/admin*.php; nuorodos iš šaknies teisingos |

### 2.6 BDAR/GDPR: privatumas, slapukai

Tikrina GDPR atitiktį.

| Use Case | Įvestis | Laukiamas rezultatas |
|----------|---------|---------------------|
| UC-GDPR-1 | privacy.php | Egzistuoja, dvikalbis |
| UC-GDPR-2 | cookies.php | Egzistuoja, dvikalbis |
| UC-GDPR-3 | Slapukų juosta | Sutikimo mechanizmas index.php |
| UC-GDPR-4 | Duomenų teisės | Aprašytos privatumo puslapyje |
| UC-GDPR-5 | Politika pagal tiekėją | privacy.php/cookies.php keičiasi pagal effectiveMapProvider(): Google → Google slapukai/serveriai; Yandex → Yandex; OSM/CARTO/OpenTopoMap → atitinkamas tiekėjas. Ne-Google šakose Google NEminimas. |

### 2.7 Brute-force + dviejų pakopų prisijungimas

Tikrina apsaugą nuo slaptažodžio spėliojimo ir dviejų pakopų autentifikaciją (el. paštas + slaptažodis).

| Use Case | Įvestis | Laukiamas rezultatas |
|----------|---------|---------------------|
| UC-BF-1 | 3 nesėkmingi 1 pakopos (el. pašto) bandymai | IP blokuojamas, **tylus** (anti-enumeracija) |
| UC-BF-2 | 1 pakopos blokas | Galioja 60 min |
| UC-BF-3 | Bandymų sekimas | JSON faile (be DB) — 1 pakopa |
| UC-2SA-1 | `admin_credentials` lentelė | Saugo `email_hash` (hash, ne tekstą) + `password_change_required` |
| UC-2SA-2 | 2 nesėkmingi 2 pakopos (el. paštas+slaptažodis) bandymai | 24 val. blokas + saugumo žurnalo įrašas |
| UC-2SA-3 | Sąranka | Pirma slaptažodis, tada el. paštas → prisijungimas tampa dviejų pakopų |
| UC-2SA-4 | `email_hash` šaltinis | Tikrinamas ir `schema.sql`, ir `includes/admin.php` (atsparu auto-trynimui) |

### 2.8 Requirements: FR + NFR atsekamumas

Tikrina, kad kiekvienas funkcinis ir nefunkcinis reikalavimas turi įgyvendinimą.

| Use Case | Reikalavimas | Patikra |
|----------|-------------|---------|
| UC-REQ-FR1..21 | Visi 21 FR | Endpoint'ai, funkcijos, lentelės egzistuoja |
| UC-REQ-NFR1..14 | Visi 14 NFR | Saugumas, mastelis, suderinamumas, kokybė |

Pavyzdžiai:
- **FR-3** → tikrina, kad visos 8 metrikos palaikomos
- **FR-15** → HMAC naudoja raw GET reikšmes
- **FR-16** → ≥195 valstybės
- **NFR-3** → 0 tiesioginės SQL interpoliacijos
- **NFR-8** → testai be shell funkcijų
- **TZ** → PHP + MySQL sesija UTC; API grąžina ISO 8601 su „Z" (toIsoUtc); naršyklė konvertuoja į lokalų laiką

### 2.9 API+DB: numeracija, valymas, trynimas

Integraciniai testai su realia MariaDB.

| Use Case | Įvestis | Laukiamas rezultatas |
|----------|---------|---------------------|
| UC-API-1 | Registracija | Eilės numeris VLN1, VLN2... |
| UC-API-2 | reading pending srautas | MAC priskiriamas, confirmed=1 |
| UC-API-3 | clear_readings | Matavimai trinami, jutiklis lieka |
| UC-API-4 | delete | Jutiklis + matavimai (CASCADE) |
| UC-API-5 | Trynimas be slaptažodžio | 401/403 |
| UC-API-6 | HMAC payload formatas | firmware ↔ serveris sutampa |
| UC-API-7 | Rate limiting | 2/5 min/MAC → 429 |
| UC-API-8 | clear_readings_bulk | Kelių jutiklių (ids=1,2,3) duomenys trinami, jutikliai lieka |
| UC-API-9 | delete_bulk | Keli pažymėti jutikliai trinami (CASCADE), kiti nepaliesti |
| UC-API-10 | clear_region | Viso regiono (city_prefix) duomenys trinami, jutikliai lieka |
| UC-API-11 | delete_region | Visi regiono jutikliai trinami (CASCADE) |
| UC-API-12 | Regiono kodo validacija | Injekcija (`../etc`) → 400 |
| UC-API-13 | set_secret (HMAC raktas) | Reikia slaptažodžio; raktas įrašomas į `sensors.secret`, negrąžinamas; tuščias = pašalinti |
| UC-API-14 | averages (vidurkiai pagal periodą) | `AVG()` per laikotarpį (4h..365d/all), filtruojant pagal regioną ir patalpos/lauko |

### 2.10 Frontend: filtrai, regionas, vidurkiai

Klientinės JS logikos testai (Node.js test runner), ištraukti iš `index.php`.

| Use Case | Įvestis | Laukiamas rezultatas |
|----------|---------|---------------------|
| UC-FE-1 | Vietos filtras (visi/patalpos/lauko) | Rodomi tik atitinkamo tipo jutikliai |
| UC-FE-2 | Rodmenų filtras | Tik turintys pasirinktą metriką |
| UC-FE-3 | Regiono filtras „visi" | Rodomi visų regionų jutikliai |
| UC-FE-4 | Regiono filtras „VLN" | Tik VLN jutikliai |
| UC-FE-5 | regionOf (iš city_prefix arba label) | Teisingas regiono kodas didžiosiomis |
| UC-FE-6 | Vidurkiai pagal periodą + regioną | Serverio `?action=averages`; periodo parinkiklis (AVG_PERIODS); suderinta su regiono ir patalpos/lauko filtrais |
| UC-FE-7 | Koordinačių grupavimo raktas | 7 skaitmenys, tie patys taškai → tas pats raktas |
| UC-FE-8 | Atribucijos base64 dekodavimas | Teisingas tekstas; blogas base64 → tuščia (be lūžio) |
| UC-FE-9 | OSM koordinačių suderinamumas | WGS84 lat/lng identiškos Google ir Leaflet tiekėjams |
| UC-FE-10 | Žemėlapio tiekėjo parinkimas | Eksplicitiškas MAP_TILE_PROVIDER: google → Google Maps (reikia rakto); kiti → Leaflet. effectiveMapProvider() — vienas šaltinis |
| UC-FE-11 | Plytelių tiekėjo konfigūracija | CFG.tileProvider → presets (carto_voyager/carto_light/osm/custom); lokalus Leaflet (assets/leaflet/) |
| UC-FE-12 | Leaflet kraunamas sinchroniškai | `leaflet.js` žyma BE `defer`/`async` (OSM shim fiksuoja `window.L` parse metu) |
| UC-FE-13 | Vidurkių UI yra | `AVG_PERIODS`, `#avgPeriod` parinkiklis, `action: 'averages'` užklausa |
| UC-SEO-1 | SEO matomumas | `robots.txt`, `sitemap.xml`, `canonical`, Open Graph, JSON-LD; `manage.php`/`db-check.php` → `noindex` |

---
## 3. Atlikimo ataskaita (Execution Report)

**Paleidimo data:** 2026-06-24
**Aplinka:** PHP 8.3.6, MariaDB 10.x, Node.js · Linux (patvirtinta ir XAMPP/Windows)

### 3.1 Suvestinė

| Testų grupė | Testai | Praėjo | Nepavyko | Būsena |
|-------------|--------|--------|----------|--------|
| Cities: prefiksai, atstumai, sostinės | — | ✅ | 0 | 🟢 |
| Config: injekcijai atspari generacija | — | ✅ | 0 | 🟢 |
| Password: bcrypt + savęs neperrašymas | — | ✅ | 0 | 🟢 |
| Schema: ištrynimas po diegimo | — | ✅ | 0 | 🟢 |
| Auth: administratoriaus apsauga | — | ✅ | 0 | 🟢 |
| BDAR/GDPR: privatumas, slapukai | — | ✅ | 0 | 🟢 |
| Brute-force + dviejų pakopų prisijungimas | — | ✅ | 0 | 🟢 |
| Requirements: FR + NFR atsekamumas | — | ✅ | 0 | 🟢 |
| API+DB: numeracija, valymas, trynimas | — | ✅ | 0 | 🟢 |
| **Pagrindiniai (PHP) iš viso** | **441** | **441** | **0** | 🟢 |
| PHPUnit-stiliaus | 24 | 24 | 0 | 🟢 |
| Frontend (Node.js) | 19 | 19 | 0 | 🟢 |
| **BENDRA SUMA** | **484** | **484** | **0** | 🟢 |

**Vykdymo laikas:** ~780 ms (pagrindiniai PHP testai).

### 3.2 Aplinkos suderinamumo patikra

Testai paleisti dviem režimais, kad būtų patvirtintas shared hosting / XAMPP suderinamumas:

| Režimas | Rezultatas |
|---------|-----------|
| Su įjungtais shell (shell_exec/exec prieinami) | 441 + 24 ✅ |
| Su IŠJUNGTAIS shell (disable_functions) | 441 + 24 ✅ |

**Išvada:** rezultatas identiškas abiem atvejais — testai nepriklauso nuo shell funkcijų, todėl veikia bet kuriame shared hosting'e ir XAMPP'e.

**Žemėlapio plytelių pastaba.** Leaflet talpinamas lokaliai (`assets/leaflet/`), todėl biblioteka nepriklauso nuo CDN. Plytelės kraunamos iš išorinio tiekėjo (numatyta OpenTopoMap; CARTO riba 75 000 peržiūrų/mėn. nemokamai; alternatyvos: OSM be SLA, savas URL). Plytelių tinklo pasiekiamumas nuo testų aplinkos nepriklauso ir tikrinamas tik naršyklėje; testai tikrina tiekėjo parinkimo logiką (UC-FE-10, UC-FE-11), ne tinklo pasiekiamumą.

**Admin failo pervadinimo pastaba.** Pervadinus `admin.php` per admin puslapį (saugumo funkcija), failas tampa `admin_XXXX.php`, o naujas vardas saugomas `includes/admin_file.php` žymeklyje. Testai admin failą randa **dinamiškai** per `TestCase::adminFileName()` (nuskaito žymeklį, atsarginis — `admin.php`), todėl visi admin failą skaitantys testai praeina ir po pervadinimo.

### 3.3 Funkcinis srauto testas (HTTP)

Realiu PHP serveriu patikrintas pilnas naudotojo srautas:

| Žingsnis | Rezultatas |
|----------|-----------|
| Jutiklio registracija | ✅ sensor_id grąžintas |
| Pirmas reading (MAC priskyrimas) | ✅ ok, confirmed=1 |
| map_data (jutiklis matomas, su city_prefix) | ✅ count, regionai |
| health endpoint | ✅ status=ok |
| SSE stream | ✅ event: update |
| clear_readings_bulk (ids=1,2) | ✅ sensors_affected, deleted_readings |
| delete_region (region=KAU) | ✅ deleted_sensors, kiti regionai nepaliesti |
| Trynimas teisingu slaptažodžiu (settings.php) | ✅ įvykdoma |
| Trynimas blogu slaptažodžiu | ✅ 403 password_required |
| Regiono kodo injekcija (`../etc`) | ✅ 400 |
| index.php regiono filtras + CFG.cityNames | ✅ VLN→Vilnius |
| index.php tiekėjas = ne-google | ✅ Leaflet/OSM, mapProvider='osm', shim |
| index.php tiekėjas = google | ✅ Google Maps, mapProvider='google' |
| privacy.php/cookies.php (ne-google) | ✅ atitinkamo tiekėjo tekstas (Google NEminimas) |
| privacy.php/cookies.php (google) | ✅ Google tekstas, nuoroda į Google politiką |
| privacy.php/cookies.php (yandex) | ✅ Yandex tekstas, nuoroda į Yandex politiką |

### 3.4 Reikalavimų aprėptis (FR/NFR)

| Kategorija | Reikalavimų | Padengta testais |
|-----------|-------------|------------------|
| Funkciniai (FR) | 21 | 21 (100%) ✅ |
| Nefunkciniai (NFR) | 14 | 14 (100%) ✅ |

Kiekvienas FR/NFR turi bent vieną atsekamą testą RequirementsTest faile.

---

## 4. Kaip paleisti testus

### 4.1 Per naršyklę (admin)

1. Prisijunkite prie admin skydo.
2. Atidarykite `tests.php`.
3. Testai paleidžiami tame pačiame procese (be shell), rezultatas rodomas puslapyje.

### 4.2 Per komandinę eilutę

```bash
# Pagrindiniai PHP testai (su DB, jei prieinama)
php tests/run.php

# Frontend testai
node --test tests/frontend/frontend.test.js

# E2E (reikia Playwright)
npx playwright test tests/e2e/

# Apkrovos (reikia k6)
k6 run tests/load/reading-load.js
```

### 4.3 Integraciniams testams (DB)

Integraciniai testai reikalauja test DB. Nustatykite per aplinkos kintamąjį:
```bash
export IOT_TEST_DSN="mysql:host=localhost;dbname=iot_test"
export IOT_TEST_USER="iot"
export IOT_TEST_PASS="slaptazodis"
php tests/run.php
```
Be šių kintamųjų integraciniai testai gražiai praleidžiami (produkcijoje normalu, nes jie modifikuoja duomenis).

---

## 5. Paleidimas ir priežiūra

Testai paleidžiami **vienu skriptu be išorinių priklausomybių** (be Composer, be CI paslaugų): `php tests/run.php` + `node --test tests/frontend/frontend.test.js`. PHP sintaksė tikrinama per `php -l`. Tinka ir lokaliam (XAMPP), ir shared hosting paleidimui.

---

## 6. Pastabos

- **Schema archive** use case'as istoriškai vadinosi „usedSh.<random>", dabar schema **ištrinama** po diegimo (deleteSchemaFile), todėl testas patikrina ištrynimą.
- Testai sukurti **be Composer/PHPUnit priklausomybės** — naudojamas savas minimalus karkasas + PHPUnit-stiliaus shim, kad veiktų bet kuriame PHP serveryje.
