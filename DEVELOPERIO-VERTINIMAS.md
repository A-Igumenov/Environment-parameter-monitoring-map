# Programuotojo (Developerio) Sprendimo Vertinimas
## IoT Sensorių Žemėlapis — Aplinkos Duomenų Stebėsenos Sistema

## 1. Santrauka

Kodo bazė yra **vientisa, nuosekli ir lengvai palaikoma** mažos/vidutinės komandos. Zero-build principas (be Composer/npm) reiškia, kad bet kuris programuotojas gali įkelti failus per FTP ir iškart matyti rezultatą — nereikia diegimo grandinės. Modulinė `includes/` struktūra ir vienas tiesos šaltinis kiekvienai atsakomybei (CSP, žemėlapio tiekėjas, admin failo vardas, laiko juosta) mažina klaidų tikimybę keičiant kodą.

**Bendras developerio vertinimas: 🟢 GERAS — patogu palaikyti ir plėsti.**

---

## 2. Kodo organizavimas

| Aspektas | Vertinimas | Komentaras |
|----------|-----------|-----------|
| Atsakomybių atskyrimas | 🟢 | `config.php` (nustatymai), `auth.php` (sesija/teisės), `security.php` (antraštės, laikas, tiekėjas), `cities.php` (geografija), `api/sensors.php` (REST) |
| Vienas tiesos šaltinis | 🟢 | `effectiveMapProvider()`, `setSecurityHeaders()`, `adminFileName()`, `toIsoUtc()`, `adminDb()` — kiekviena logika vienoje vietoje |
| Pavadinimų aiškumas | 🟢 | Funkcijų ir kintamųjų vardai savaime paaiškinantys (LT/EN mišinys, bet nuoseklus) |
| Komentarai | 🟢 | Kritinės vietos paaiškintos „kodėl", ne tik „kas" (pvz. CSP konflikto, lookahead priežastys) |
| Dubliavimas | 🟢 | Admin UI = `manage.php`; API = `api/sensors.php` + versijuotas `api/v1/sensors.php` — aiškiai atskiri vardai |

---

## 3. Testuojamumas — 🟢 TVIRTAS

| Testų tipas | Skaičius | Paskirtis |
|-------------|----------|-----------|
| Vienetų + integraciniai (PHP) | 441 | Logika, srautai, FR/NFR atsekamumas |
| PHPUnit-stiliaus | 24 | Klasių lygio patikros |
| Frontend (Node.js) | 19 | JS funkcijų logika |
| **Iš viso** | **~484** | |

**Stiprybės developeriui:**
- Testai **nepriklauso nuo shell** (`shell_exec`/`exec`) — veikia identiškai įjungus/išjungus shared hosting apribojimus.
- Testai **dinamiškai randa admin failą** per žymeklį, todėl nelūžta po saugumo funkcijų (pervadinimo, perkėlimo į `includes/`).
- FR/NFR atsekamumo testas (`RequirementsTest`) susieja reikalavimus su kodu — keičiant funkciją iškart matyti, kas turi būti padengta.
- Integraciniai testai naudoja realią DB (`IOT_TEST_DSN`) ir realius HTTP srautus, ne tik statinę analizę.

---

## 4. Keitimo saugumas (kaip lengva ką nors sulaužyti)

| Scenarijus | Rizika | Apsauga |
|-----------|--------|---------|
| Žemėlapio tiekėjo keitimas | 🟢 Žema | Vienas `MAP_TILE_PROVIDER`; slapukai/privatumas keičiasi automatiškai |
| Admin failo pervadinimas | 🟢 Žema | Žymeklis + `adminFileName()`; testai prisitaiko |
| admin.php perkėlimas | 🟢 Žema | Atlikta į `includes/`; keliai per `__DIR__`, nuorodos per `adminFilePath()` |
| Laiko logikos keitimas | 🟢 Žema | Viskas UTC; `toIsoUtc()` vienoje vietoje |
| Naujo API endpoint pridėjimas | 🟢 Žema | `action` parametro šablonas; versijavimas per `api/v1/` |
| Leaflet skripto krovimo keitimas | 🟡 Vidutinė | OSM shim fiksuoja `window.L` parse metu — Leaflet turi likti **sinchroniškas** (be `defer`/`async`); saugo frontend testas |
| Config generavimo keitimas | 🟡 Vidutinė | `writeConfig()` šablonas dubliuoja `db()` — keisti abi vietas |

---

## 5. Įrankiai ir vystymo aplinka

- **Stack:** PHP 8.3, MariaDB/MySQL, vanilla JS, Leaflet (talpinamas lokaliai).
- **Lokali aplinka:** XAMPP (Windows) arba bet koks PHP+MySQL.
- **Produkcija:** Hostinger (shared hosting) — zero-build leidžia tiesioginį FTP įkėlimą.
- **Testų paleidimas:** vienas skriptas (`php tests/run.php` + `node --test`) **be išorinių priklausomybių** — be Composer, be CI paslaugų; veikia lokaliai ir shared hosting'e.
- **Pakavimas:** zip be jautrių/generuojamų failų (`admin_pass.php`, `admin_file.php`, `settings.php`, cache).

**Diegimas developeriui:** atidarykite `index.php` — jei config dar neužpildytas, būsite automatiškai nukreipti į `includes/admin.php` sąrankos vedlį. Nereikia žinoti admin URL.

---

## 6. Silpnos vietos / techninė skola

| Punktas | Sunkumas | Rekomendacija |
|---------|----------|---------------|
| `writeConfig()` dubliuoja `db()` šabloną | 🟡 Žema | Apsvarstyti vieną šabloną, kad nereiktų sinchronizuoti dviejų vietų |
| `sensors.php` vardai | 🟢 Išspręsta | Admin UI pervadintas į `manage.php`; liko tik API pora |
| `map_data` koreliuotos subužklausos | 🟡 Vidutinė | Prie ~50k jutiklių denormalizuoti (`seq`, `reading_count`) |
| LT/EN mišinys kode | 🟢 Kosmetinė | Nuoseklus; komentarai LT, vardai mišrūs |

Nė viena iš šių vietų neblokuoja vystymo ar produkcijos.

---

## 7. Išvada

Iš programuotojo perspektyvos sprendimas yra **nėra problemų tolimesniam vystimuisi**: aiški struktūra, vienas tiesos šaltinis kiekvienai logikai, tvirta testų aprėptis (~484 testų) ir aukštas keitimo saugumas. Naujas komandos narys gali greitai susiorientuoti, o saugumo funkcijos (admin pervadinimas, perkėlimas į `includes/`) nelaužia testų dėl dinaminio failo radimo.

