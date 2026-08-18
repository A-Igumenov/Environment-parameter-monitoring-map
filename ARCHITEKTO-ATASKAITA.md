# IT Architekto Sprendimo Vertinimas
## IoT Sensorių Žemėlapis — Aplinkos Duomenų Stebėsenos Sistema

**Vertinimo data:** 2026-06-16 (atnaujinta 2026-06-22)
**Vertinimo tipas:** Nepriklausomas auditas nuo nulio (fresh assessment)
**Vertintojas:** IT sprendimų architektas

---

## 1. Santrauka (Executive Summary)

Vertinama IoT jutiklių agregavimo ir vizualizavimo sistema aplinkos duomenų stebėsenai. Sistema surenka matavimus iš ESP32/ESP8266 jutiklių per REST API ir rodo juos interaktyviame Google žemėlapyje. Suprojektuota veikti įprastame PHP + MySQL shared hosting'e be Composer/Node.js priklausomybių.

**Bendras verdiktas:** Sistema **funkcionaliai pilna, gerai struktūruota ir saugi** mokomojo/nekomercinio naudojimo kontekstui iki 49 800 jutiklių. Kodas praeina statinę analizę ir **~484 automatinių testų**, įskaitant FR/NFR reikalavimų atsekamumo testus. **Rizikos lygis: ŽEMAS.**

| Aspektas | Vertinimas | Komentaras |
|----------|-----------|-----------|
| Architektūra | 🟢 Tvirta | Modulinė, zero-build, shared-hosting suderinama |
| Funkcionalumas | 🟢 Pilnas | Visi 24 FR įgyvendinti ir testuojami |
| Saugumas | 🟢 Geras | PDO, CSRF, bcrypt, HMAC (su laiku), rate limit, brute-force |
| Mastelis | 🟢 Tinkamas | 49 800 jutiklių apibrėžtai ribai |
| Realaus laiko duomenys | 🟢 Yra | SSE su polling fallback |
| Kokybė ir testai | 🟢 Tvirtas | ~484 testų + FR/NFR atsekamumas (be CI priklausomybių) |
| Dokumentacija | 🟢 Išsami | README su ER, API, vadovais, firmware |

---

## 2. Architektūros stiprybės

1. **Zero-build perkeliamumas.** Jokio kompiliavimo, npm ar Composer žingsnio. Įkėlimas per FTP, diegimas per naršyklę.
2. **Modulinė struktūra.** Atsakomybės atskirtos: config.php, auth.php, security.php, cities.php, api/sensors.php.
3. **Apgalvotas duomenų modelis.** Jutiklio tapatybė lat+lng+MAC su UNIQUE indeksu; matavimai su kaskadiniu trynimu; tinkami indeksai.
4. **Progresyvus realaus laiko sluoksnis.** SSE kaip pasirinktinis patobulinimas su automatiniu polling fallback.
5. **Žemėlapio tiekėjo lankstumas.** Tiekėjas pasirenkamas **eksplicitiškai** admin sąsajoje (`MAP_TILE_PROVIDER`, vienas tiesos šaltinis per `effectiveMapProvider()`): Google Maps (reikia rakto), OpenTopoMap, CARTO Voyager/Light, OpenStreetMap, Yandex (EPSG:3395) arba savas URL. Leaflet talpinamas lokaliai (be CDN priklausomybės); suderinamumo sluoksnis (shim) leidžia keisti tiekėją be žemėlapio kodo perrašymo. Admin sąsajoje rakto laukas rodomas tik pasirinkus Google; **slapukų ir privatumo tekstas automatiškai atitinka pasirinktą tiekėją**. Visi naudoja WGS84 sistemą. **Pastaba dėl limitų:** CARTO nemokama riba — 75 000 peržiūrų/mėn.; OSM/OpenTopoMap — best-effort be SLA, draudžia bulk/offline. Mokomajam nekomerciniam naudojimui pakanka; komerciniam — reikia licencijos ar savo plytelių serverio.
6. **Saugumas iš principo.** Prepared statements, CSRF, bcrypt, HMAC, rate limiting įausti į architektūrą.
7. **Vienodas UTC laikas.** Visa sistema dirba UTC (PHP + MySQL sesija `+00:00`); laikas API grąžinamas ISO 8601 su „Z", o naršyklė automatiškai konvertuoja į vartotojo lokalų laiką (DST-saugu). Pašalinta serverio/naršyklės laiko juostų dviprasmybė.
8. **Admin paslėptas `includes/` kataloge.** Administravimo puslapis saugomas kartu su serverio failais `includes/` aplanke; `includes/.htaccess` leidžia HTTP tik `admin*.php` (pats apsaugotas slaptažodžiu), bet draudžia `config.php`, `settings.php` ir `admin_file.php` žymeklį (negatyvus lookahead). `index.php` pirmo paleidimo metu automatiškai nukreipia į sąranką, kol config neužpildytas.

---

## 3. Saugumo vertinimas — 🟢 GERAS

| Sritis | Būsena | Detalės |
|--------|--------|---------|
| SQL injekcija | ✅ | 19 PDO prepared statements; 0 tiesioginės interpoliacijos |
| XSS | ✅ | escapeHtml/textContent; CSP antraštė |
| CSRF | ✅ | Žetonai admin formose, hash_equals |
| Autentifikacija | ✅ | Dviejų pakopų (el. paštas + slaptažodis); bcrypt cost 12; el. pašto hash DB (`admin_credentials`), slaptažodžio hash settings faile |
| Slaptažodžio politika | ✅ | ≥8 simb., didžioji, skaičius, spec; gyvas tikrintuvas |
| Brute-force | ✅ | 1 pakopa: 3 el. pašto bandymai → 60 min (tylus); 2 pakopa: 2 → 24 val. + saugumo žurnalas |
| Rate limiting | ✅ | 2 užklausos/5 min/MAC |
| Jutiklių autentifikacija | ✅ | HMAC-SHA256 (serveris + firmware); raktas priskiriamas admin sąsajoje (`set_secret`), end-to-end |
| Trynimo apsauga | ✅ | Sesija + slaptažodis + audit log |
| Saugumo antraštės | ✅ | CSP, HSTS, X-Frame-Options ir kt. |
| Konfigūracijos injekcija | ✅ | var_export (atspari) |

**Sąlygos produkcijai:** HTTPS; HMAC kritiniams jutikliams; audit log peržiūra; db-check.php ištrynimas.

---

## 4. Patikimumas ir stebėsena — 🟢 GERAS

- ✅ Health-check endpoint DB + disko būsenai
- ✅ Metrikų panelė (jutikliai, offline %, talpa, įrašai/dieną)
- ✅ Audit log jautriems veiksmams
- ✅ Automatinė DB atsarginė kopija (mysqldump + gzip, rotacija)
- ✅ Pasirinktinis error tracking (webhook → Slack/Sentry)
- ✅ Globalus klaidų valdymas (display_errors=0)
- ✅ Duomenų valymas (cleanup, DATA_RETENTION_DAYS)

**Administravimo valdymas.** `manage.php` jutikliai grupuojami pagal regioną (city_prefix), su sulankstomais blokais. Palaikomas: pavienis trynimas/valymas, masinis pažymėtų jutiklių trynimas/valymas (checkbox), viso regiono valymas/trynimas. Visi jautrūs veiksmai apsaugoti sesija + slaptažodžiu (maskuotas įvedimas) + audit log; masiniai veiksmai naudoja parametrizuotą `IN (?,...)` su limitu 1000, regiono kodas validuojamas. `index.php` turi regiono/miesto filtrą, kuris paveikia ir žemėlapį, ir vidurkius. **Vidurkiai skaičiuojami serveryje** (`?action=averages`) pagal pasirinktą laikotarpį (4/6/12/24 val., 7/30/90/180/365 d. arba „visas laikas“), suderinant su regiono ir patalpos/lauko filtrais.

**SSE pastaba.** Numatytasis režimas — saugus 30 s polling; SSE pasirinktinis (?sse=1). SSE atlaisvina sesijos užraktą (session_write_close), neblokuoja kitų užklausų. Shared hosting'e prie didelio žiūrovų skaičiaus rekomenduojama polling arba VPS.


**SEO matomumas ir socialinis dalijimasis.** Pridėti atrandamumo elementai: `robots.txt`, `sitemap.xml`, dinaminis `canonical`, Open Graph + Twitter Card žymenys, schema.org JSON-LD (`index.php`). Admin puslapis (`manage.php`) ir diagnostika (`db-check.php`) pažymėti `noindex` (+ `manage.php` peradresuoja anonimus į prisijungimą).

**Pradinio krovimo našumas.** Žemėlapis pasiekia interaktyvumą per ~0,3 s. Optimizacijos: `preconnect` į aktyvaus tiekėjo plyteles, Chart.js kraunamas tinginiškai (tik atidarius istorijos grafiką), atribucijos sargas naudoja `MutationObserver` vietoj periodinio `setInterval`.

---

## 5. Mastelis — 🟢 TINKAMAS apibrėžtai ribai

Tikslinė riba: 49 800 jutiklių, ~166 įrašymai/sek.

| Aspektas | Vertinimas |
|----------|-----------|
| Įrašymo apkrova | ✅ Rate limiting garantuoja ≤166/sek |
| Skaitymo apkrova | 🟡 Viewport (bbox) ribojimas; be jo koreliuota subužklausa |
| Indeksai | ✅ idx_coords, idx_city_id, idx_last_seen, idx_sensor_time |
| Puslapiavimas | ✅ limit/offset |

**Rekomendacija:** prie pilno apkrovimo naudoti viewport užklausas (jau įgyvendinta).

---

## 6. Kokybė ir testavimas — 🟢 TVIRTAS

| Testų tipas | Skaičius |
|-------------|----------|
| Vienetų + integraciniai (PHP) | 441 |
| PHPUnit-stiliaus | 24 |
| Frontend (Node.js) | 19 |
| **Iš viso** | **~484** |

**Stiprybės:** FR/NFR atsekamumas (RequirementsTest), aplinkos nepriklausomumas (be shell), realūs HTTP srautai; testai paleidžiami vienu skriptu **be išorinių priklausomybių** (be Composer, be CI paslaugų).

---

## 7. Galutinis verdiktas

**Sprendimas TINKA produkcijai** edukaciniam/nekomerciniam naudojimui iki 49 800 jutiklių, **su sąlygomis:**

1. HTTPS su sertifikatu (būtina)
2. DB atsarginės kopijos (cron → api/backup.php)
3. HMAC kritiniams jutikliams
4. Stebėsena (ERROR_WEBHOOK_URL + audit log)
5. Valymas (cleanup cron)
6. db-check.php ištrynimas
7. SSE tik VPS/galingame hostinge (kitaip polling)


---

## 9. Išvada

IoT Sensorių Žemėlapis — brandus, dokumentuotas, saugus mokomasis sprendimas aplinkos stebėsenai. Architektūra subalansuota tarp paprastumo (zero-build) ir funkcionalumo (realaus laiko žemėlapis, HMAC, 197 šalys). Su standartinėmis produkcijos sąlygomis paruošta naudojimui apibrėžtoje apimtyje.
