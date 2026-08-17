# IT architekto vertinimas — Production lygio analizė (nuo nulio)

**Sprendimas:** IoT jutiklių žemėlapis (PHP 8.3 + MySQL/MariaDB + vanilla JS)
**Vertinimo data:** atlikta peržiūrint realų kodą, ne dokumentaciją
**Tikslinė apimtis:** iki 49 800 jutiklių (166 INSERT/sek., 1 siuntimas / 5 min)
**Paskirtis:** edukacinis / regioninis / nekomercinis naudojimas

---

## Santrauka

| Sritis | Vertinimas | Komentaras |
|--------|-----------|-----------|
| Funkcionalumas | 🟢 Aukštas | Visi reikalavimai įgyvendinti, veikia |
| Saugumas | 🟢 Geras | OWASP pagrindai padengti; CSP, CSRF, HMAC, rate limit |
| Patikimumas | 🟢 Geras | Health, backup, audit log, klaidų valdymas |
| Mastelis | 🟢 Apibrėžtas | Tinka iki 49 800 jutiklių; indeksai, viewport |
| Testavimas | 🟢 Tvirtas | ~484 testai (be CI priklausomybių) |
| Prižiūrimumas | 🟢 Geras | Dvikalbė dokumentacija, API versijavimas |

**Verdiktas: TINKA produkcijai** apibrėžtoje apimtyje. **Rizikos lygis: ŽEMAS.**

---

## 1. Architektūra

Vieno serverio PHP + MySQL, be build žingsnio (nereikia Composer/npm). Tinka shared-hosting (Hostinger) ir XAMPP. Aiškus sluoksnių atskyrimas:

- **Pateikimas:** `index.php` (viešas žemėlapis), `includes/admin.php` (sąranka), `manage.php` (valdymas)
- **API:** `api/sensors.php` (REST), `api/v1/` (versijuotas), `api/backup.php`, `api/cleanup.php`
- **Logika:** `includes/auth.php` (auth + CSRF + saugumo antraštės), `includes/cities.php`, `includes/config.php`
- **Duomenys:** `schema.sql` (5 lentelės su indeksais ir FK; įsk. `admin_credentials` dviejų pakopų prisijungimui)

**Stiprybės:** paprastas diegimas, savarankiškas (zero-build), automatinė schemos migracija, dvikalbė sąsaja.

---

## 2. Saugumas (pagal OWASP Top 10)

| OWASP rizika | Būsena | Įgyvendinimas |
|--------------|--------|---------------|
| A01 Prieigos kontrolė | 🟢 | Dviejų pakopų admin prisijungimas (el. paštas + slaptažodis); sesija + slaptažodis admin veiksmams; API delete/set_secret reikalauja slaptažodžio |
| A02 Kriptografija | 🟢 | bcrypt (cost 12); HMAC-SHA256 jutikliams |
| A03 Injekcijos | 🟢 | PDO prepared (18 vietų); 0 tiesioginių užklausų su įvestimi |
| A04 Nesaugus dizainas | 🟢 | Rate limiting, brute-force apsauga, audit log |
| A05 Klaidinga konfigūracija | 🟢 | `display_errors=0`, saugumo antraštės, .htaccess apsauga |
| A06 Pažeidžiami komponentai | 🟢 | Minimalios priklausomybės (Leaflet lokaliai; Chart.js tinginiškai tik grafikui; Maps tik pasirinkus Google) |
| A07 Autentifikacija | 🟢 | Dviejų pakopų: 1 pak. 3 bandymai→60min (tylus); 2 pak. 2→24val.+saugumo žurnalas; CSRF visose formose |
| A08 Duomenų vientisumas | 🟢 | HMAC parašas, var_export config generavimas |
| A09 Žurnalavimas | 🟢 | Audit log, error_log, pasirinktinis webhook |
| A10 SSRF | 🟢 | Nėra vartotojo valdomų išorinių užklausų |

**Konkretūs saugumo mechanizmai (patikrinti kode):**
- **CSRF:** 10 admin formų su `csrfField()`, tikrinama per `hash_equals` (`requireCsrf()`)
- **Saugumo antraštės:** CSP (leidžia Maps + Chart.js), X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, HSTS (per HTTPS) — visuose 6 puslapiuose
- **Rate limiting:** `reading` ribojamas 2 užklausomis / 5 min per MAC (DB pagrindu)
- **HMAC:** per-jutiklio secret priskiriamas admin sąsajoje (`set_secret`, `🔐 HMAC` mygtukas); serveris pasirašo žalią GET reikšmę (sutampa su firmware); raktas negrąžinamas per API
- **Brute-force:** dviejų pakopų — 1 pak. 3 el. pašto bandymai → 60 min (tylus, anti-enumeracija); 2 pak. 2 → 24 val. + saugumo žurnalas; IP blokuojamas/atblokuojamas admin skyde
- **Slaptažodis:** bcrypt cost 12, `includes/settings.php` faile (be self-rewrite); admin el. paštas (prisijungimo vardas) saugomas tik kaip hash DB (`admin_credentials`)
- **Audit log:** delete/clear veiksmai su IP, laiku, target_id

---

## 3. Patikimumas ir stebėsena

- **Health-check** (`?action=health`): DB + disko būsena
- **Metrikų panelė** admin'e: jutiklių sk., offline %, talpa, įrašai/dieną
- **DB atsarginės kopijos** (`api/backup.php`): mysqldump + gzip, rotacija (7 kopijos), cron arba HTTP su raktu
- **Klaidų valdymas:** `display_errors=0` (config.php), `set_exception_handler` (API), struktūrizuotos JSON klaidos
- **Error tracking:** pasirinktinis webhook (`ERROR_WEBHOOK_URL` → Sentry/Slack)
- **Cleanup:** MySQL Event arba `api/cleanup.php` (cron)


**Saugumo žurnalas (admin).** `manage.php` rodo saugumo žurnalą: nesėkmingi prisijungimai, blokuoti IP (su priežastimi ir likusiu laiku), veiksmai; IP galima blokuoti 24 val. / atblokuoti / trinti įrašus.

**Vidurkiai pagal periodą.** `index.php` rodo matavimų vidurkius, skaičiuojamus serveryje (`?action=averages`) iš `readings` istorijos pagal pasirinktą laikotarpį (4/6/12/24 val., 7/30/90/180/365 d. arba „visas laikas“), suderinant su regiono ir patalpos/lauko filtrais.

**SEO matomumas.** Pridėti `robots.txt`, `sitemap.xml`, dinaminis `canonical`, Open Graph + Twitter Card, schema.org JSON-LD; admin/diagnostika pažymėti `noindex`.

**Pradinio krovimo našumas.** ~0,3 s iki interaktyvumo; `preconnect` į plyteles, Chart.js tinginis krovimas, atribucijos sargas per `MutationObserver` (be periodinio budėjimo).

---

## 4. Mastelis (iki 49 800 jutiklių)

- **Apkrovos riba:** 49 800 × (1/300s) = 166 INSERT/sek. — vieno MySQL serverio rezervas didelis
- **Indeksai:** `(lat,lng,mac)` unikalus, `(city_prefix,id)` numeracijai, `(sensor_id,recorded_at)` istorijai
- **Viewport:** `map_data` palaiko bbox + puslapiavimą dideliam tankiui
- **Žemėlapio krūvis:** vienas naujausias rodmuo/jutiklį + grupavimas pagal koordinates
- **Saugojimo laikas:** `DATA_RETENTION_DAYS` riboja `readings` augimą

Viršijus apimtį — architektūros ataskaitoje aprašytas kelias (žinučių eilės, laiko eilučių DB).

---

## 5. Kokybė ir testavimas

- **~484 automatinių testų:** 441 vienetų/integraciniai + 24 PHPUnit-stiliaus + 19 frontend
- **Paleidimas:** vienas skriptas (`tests/run.php`) be CI priklausomybių; PHP lint per `php -l`
- **E2E + apkrovos** karkasai (Playwright, k6)
- **Regresijos apsauga:** SQL skaidymo, HMAC payload, CSRF formų, saugumo antraščių testai
- **Naujų funkcijų testai:** dujų metrikos + dinaminis filtras (FR-7c), istorijos laikotarpiai (FR-7b), `recorded_at` validacija (FR-7d), „ping"/`received_at` (FR-7e), schemos↔DB suderinimas; po CSS/JS iškėlimo testai skaito ir `assets/app.js`/`styles.css`

---

## 6. Sąlygos prieš produkciją

Privaloma prieš paleidimą:

1. **HTTPS** su galiojančiu sertifikatu (HSTS antraštė tada aktyvuojasi automatiškai)
2. **DB atsarginės kopijos** — cron, kviečiantis `api/backup.php`
3. **HMAC parašai** įjungti bent kritiniams jutikliams (`secret` stulpelis + firmware)
4. **Stebėsena** — `ERROR_WEBHOOK_URL` ir periodinė audit log peržiūra
5. **Cleanup** veikia (Event arba cron), kad `readings` neišaugtų
6. **Po sąrankos** — pakeisti admin slaptažodį, įjungti IP apsaugą (jei statinis IP)

---

## 7. Likę patobulinimai 

- Marker clustering prie didelio tankio (Leaflet.markercluster / MarkerClusterer)
- `map_data` koreliuotų subužklausų optimizacija prie ~50k jutiklių (precomputed `seq` stulpelis + `reading_count` denormalizacija)
- Atsako kešavimas (response cache) prie didelio žiūrovų skaičiaus

Šie punktai yra optimizacijos, ne saugumo ar funkcionalumo trūkumai. SSE realaus laiko atnaujinimai jau įgyvendinti (su polling fallback).

---

## Galutinė išvada

Sprendimas yra **funkcionaliai pilnas, saugus ir tinkamas produkcijai** edukacinei/nekomercinei aplinkai iki 49 800 jutiklių. Kodo kokybė aukšta, saugumo pagrindai (OWASP Top 10) padengti, testų aprėptis tvirta (**~484 testų**, be CI priklausomybių). Kritinių ar aukštų neišspręstų saugumo trūkumų **nėra**.
