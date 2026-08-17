<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';
if (function_exists('setSecurityHeaders')) setSecurityHeaders();
$titleLt = defined('SITE_TITLE_LT') ? SITE_TITLE_LT : 'IoT Jutiklių Žemėlapis';
$titleEn = defined('SITE_TITLE_EN') ? SITE_TITLE_EN : 'IoT Sensor Map';
$contactEmail = (defined('CONTACT_EMAIL') && CONTACT_EMAIL !== '') ? CONTACT_EMAIL : '';
// Map provider: Google Maps (with a key) or OpenStreetMap (without a key).
// The policy changes accordingly — what data is sent and to whom differs.
$usesGmaps = function_exists('effectiveMapProvider') && effectiveMapProvider() === 'google';
$usesYandex = function_exists('effectiveMapProvider') && effectiveMapProvider() === 'yandex';
// Admin visitor → back to admin AND map. Regular visitor → map only.
$isAdminVisitor = isAdminAuthorized();
$adminFile = $isAdminVisitor ? adminFilePath() : null;
?>
<!DOCTYPE html>
<html lang="lt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($titleLt, ENT_QUOTES) ?> — Privatumas / Privacy</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<style>
:root {
  --bg:#0a0f1e; --surface:#111827; --surface2:#1a2235; --border:#1e2d45;
  --accent:#00c8ff; --accent2:#7b61ff; --ok:#22d3a0; --text:#e2e8f0; --muted:#64748b;
  --mono:'JetBrains Mono','Fira Code',monospace; --sans:'Inter',system-ui,sans-serif;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: var(--sans); background: var(--bg); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; line-height: 1.65; }
header { display: flex; align-items: center; gap: 1rem; padding: .85rem 1.25rem; background: var(--surface); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 10; }
.logo { display: flex; align-items: center; gap: .55rem; font-weight: 700; font-size: 1rem; }
.logo svg { width: 22px; height: 22px; color: var(--accent); }
.spacer { flex: 1; }
.btn { background: var(--surface2); color: var(--text); border: 1px solid var(--border); border-radius: 8px; padding: .55rem .95rem; font-size: .85rem; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: .4rem; transition: .15s; }
.btn:hover { border-color: var(--muted); }
.lang-switch { display: flex; gap: .25rem; }
.lang-switch a { font-size: .72rem; font-weight: 600; padding: .25rem .55rem; border-radius: 5px; color: var(--muted); text-decoration: none; border: 1px solid var(--border); cursor: pointer; }
.lang-switch a.active { background: var(--accent); color: var(--bg); border-color: var(--accent); }

.wrap { flex: 1; width: min(820px, 94vw); margin: 2rem auto; padding: 0 1rem; }
h1 { font-size: 1.6rem; margin-bottom: .35rem; }
.updated { color: var(--muted); font-size: .8rem; margin-bottom: 2rem; }
h2 { font-size: 1.15rem; margin: 2rem 0 .75rem; color: var(--accent); }
h3 { font-size: .95rem; margin: 1.25rem 0 .4rem; }
p { margin-bottom: .85rem; font-size: .9rem; }
ul { margin: 0 0 1rem 1.25rem; font-size: .9rem; }
li { margin-bottom: .4rem; }
table { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: .82rem; }
th, td { text-align: left; padding: .6rem .75rem; border-bottom: 1px solid var(--border); vertical-align: top; }
th { color: var(--muted); text-transform: uppercase; font-size: .68rem; letter-spacing: .05em; }
td code { font-family: var(--mono); font-size: .78rem; color: var(--accent); }
.callout { background: var(--surface); border: 1px solid var(--border); border-left: 3px solid var(--accent); border-radius: 0 8px 8px 0; padding: 1rem 1.25rem; margin: 1.25rem 0; font-size: .86rem; }
.callout strong { color: var(--text); }
a.inline { color: var(--accent); }
.lang-section { display: none; }
.lang-section.active { display: block; }

.attribution-footer { flex-shrink: 0; display: flex; align-items: center; justify-content: center; gap: .5rem; padding: .5rem 1rem; background: var(--surface); border-top: 1px solid var(--border); font-size: .68rem; color: var(--muted); }
.attribution-footer strong { color: var(--text); }
.attribution-footer .sep { opacity: .5; }
</style>
</head>
<body>

<header>
  <div class="logo">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <circle cx="12" cy="10" r="3"/>
      <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/>
    </svg>
    <span id="siteTitle"><?= htmlspecialchars($titleLt, ENT_QUOTES) ?></span>
  </div>
  <div class="spacer"></div>
  <div class="lang-switch">
    <a id="langLt" class="active" onclick="setLang('lt')">LT</a>
    <a id="langEn" onclick="setLang('en')">EN</a>
  </div>
  <?php if ($isAdminVisitor): ?>
  <a class="btn" href="<?= htmlspecialchars($adminFile, ENT_QUOTES) ?>">⚙ <span id="backAdminBtn">Administravimas</span></a>
  <?php endif; ?>
  <a class="btn" href="index.php">🗺 <span id="backBtn">Į žemėlapį</span></a>
</header>

<div class="wrap">

  <!-- ════════════════ LITHUANIAN ════════════════ -->
  <section class="lang-section active" id="section-lt">
    <h1>Privatumo politika</h1>
    <div class="updated">Atnaujinta: 2026-06-20</div>

    <div class="callout">
      <strong>Trumpai:</strong> ši svetainė yra vieša IoT jutiklių aplinkos duomenų stebėsenos sistema. Ji naudoja tik <strong>būtinuosius slapukus</strong> (administratoriaus prisijungimo sesijai) ir naršyklės vietinę saugyklą (jūsų rodymo nuostatoms). Sekimo, reklamos ar analitikos slapukų NĖRA.
    </div>

    <h2>1. Bendroji informacija ir duomenų valdytojas</h2>
    <p>Ši svetainė yra vieša IoT jutiklių aplinkos duomenų stebėsenos sistema. Joje rodomi savanoriškai prijungtų jutiklių perduodami aplinkos matavimai ir jų vieta žemėlapyje. Svetainę administruoja Vilniaus universiteto Metodinis STEAM ugdymo centras. Klausimais dėl duomenų tvarkymo kreipkitės į svetainės administratorių<?php if ($contactEmail): ?> el. paštu <a href="mailto:<?= htmlspecialchars($contactEmail) ?>"><?= htmlspecialchars($contactEmail) ?></a><?php endif; ?>.</p>

    <h2>2. Kokius duomenis tvarko sistema</h2>
    <p>Svetainė nekaupia lankytojų vardų, pavardžių, el. pašto adresų ar kitų tiesiogiai asmenį identifikuojančių duomenų. Sistemoje saugomi tik savanoriškai prijungtų IoT jutiklių perduodami duomenys:</p>
    <table>
      <thead><tr><th>Duomuo</th><th>Paskirtis</th><th>Teisinis pagrindas</th></tr></thead>
      <tbody>
        <tr><td>Jutiklio koordinatės (platuma, ilguma)</td><td>Jutiklio vietai parodyti žemėlapyje</td><td>Teisėtas interesas</td></tr>
        <tr><td>Jutiklio WiFi MAC adresas</td><td>Jutikliams identifikuoti ir atskirti</td><td>Teisėtas interesas</td></tr>
        <tr><td>Jutiklio matavimai (temperatūra, drėgmė ir kt.)</td><td>Aplinkos duomenims rodyti ir analizuoti</td><td>Teisėtas interesas</td></tr>
        <tr><td>Serverio žurnaluose registruojamas IP adresas</td><td>Sistemos saugumui ir trikdžių diagnostikai</td><td>Teisėtas interesas</td></tr>
      </tbody>
    </table>
    <p>MAC adresas naudojamas tik jutikliams identifikuoti. Jis nėra naudojamas naudotojų sekimui ar profiliavimui.</p>

    <h2>3. Duomenų tvarkymo tikslas</h2>
    <p>Duomenys tvarkomi siekiant: priimti jutiklių perduodamus matavimus; atvaizduoti duomenis žemėlapyje; užtikrinti sistemos veikimą ir saugumą; sudaryti galimybę peržiūrėti istorinius matavimus.</p>

    <h2>4. Slapukai ir vietinė saugykla</h2>
    <p>Naudojami tik techniškai būtini slapukai (administratoriaus sesijai) ir naršyklės vietinė saugykla jūsų nuostatoms. Sekimo, reklamos ar analitikos slapukų nėra. Išsamus naudojamų slapukų sąrašas, jų paskirtis ir valdymo galimybės pateikiamos atskiroje <a class="inline" href="cookies.php">slapukų politikoje</a>.</p>

    <h2>5. Duomenų saugojimas ir šalinimas</h2>
    <ul>
      <li>Jutiklių matavimai saugomi administratoriaus nustatytą laikotarpį. Numatytoji konfigūracija gali numatyti neribotą istorinių duomenų saugojimą.</li>
      <li>Jeigu naujai užregistruotas jutiklis per 3 minutes neperduoda duomenų, jis automatiškai pašalinamas iš sistemos.</li>
      <li>Jutiklio savininkui paprašius, administratorius gali ištrinti jutiklį ir visus su juo susijusius duomenis per valdymo skydelį.</li>
      <li>Administratorius taip pat gali pašalinti testinius, neveikiančius ar klaidingai užregistruotus jutiklius.</li>
    </ul>

    <h2>6. Trečiosios šalys (žemėlapio paslaugos)</h2>
    <?php if ($usesGmaps): ?>
    <p>Žemėlapiui rodyti naudojama <strong>Google Maps</strong> paslauga — jai siunčiamos jutiklių koordinatės ir jūsų naršyklės IP adresas (kad būtų pristatytos žemėlapio plytelės). Taikoma <a class="inline" href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer">Google privatumo politika</a>. Diagramoms naudojama Chart.js biblioteka, kuri veikia jūsų naršyklėje ir duomenų neperduoda. Daugiau trečiųjų šalių sekimo priemonių nenaudojama.</p>
    <?php elseif ($usesYandex): ?>
    <p>Žemėlapiui rodyti naudojama <strong>Yandex Maps</strong> paslauga — žemėlapio plytelės užkraunamos iš Yandex serverių (<code>*.maps.yandex.net</code>), jiems matomas jūsų naršyklės IP adresas (techniškai būtina plytelėms pristatyti). Taikoma <a class="inline" href="https://yandex.com/legal/confidential/" target="_blank" rel="noopener noreferrer">Yandex privatumo politika</a>. Leaflet biblioteka (talpinama šioje svetainėje) ir Chart.js diagramos veikia jūsų naršyklėje ir duomenų neperduoda. Daugiau trečiųjų šalių sekimo priemonių nenaudojama.</p>
    <?php else: ?>
    <p>Žemėlapiui rodyti gali būti naudojama <strong>OpenStreetMap</strong>, <strong>OpenTopoMap</strong>, <strong>CARTO</strong> ar kitas administratoriaus sukonfigūruotas tiekėjas. Žemėlapio plytelės užkraunamos iš plytelių tiekėjo serverių (pvz. <code>tile.opentopomap.org</code>, <code>basemaps.cartocdn.com</code> ar <code>tile.openstreetmap.org</code>) — jiems matomas jūsų naršyklės IP adresas (techniškai būtina plytelėms pristatyti). Taikoma <a class="inline" href="https://wiki.osmfoundation.org/wiki/Privacy_Policy" target="_blank" rel="noopener noreferrer">OpenStreetMap Foundation privatumo politika</a>. Leaflet biblioteka (talpinama šioje svetainėje) ir Chart.js diagramos veikia jūsų naršyklėje ir duomenų neperduoda. Daugiau trečiųjų šalių sekimo priemonių nenaudojama.</p>
    <?php endif; ?>

    <h2>7. Saugumas (duomenų apsauga)</h2>
    <p>Sistemoje naudojamos techninės ir organizacinės saugumo priemonės: parametrizuotos (PDO paruoštos) SQL užklausos — apsauga nuo SQL injekcijų; išvesties filtravimas — apsauga nuo XSS atakų; turinio saugumo politika (CSP) ir kitos saugumo antraštės; administratoriaus slaptažodis saugomas naudojant bcrypt; prieigos kontrolė administravimo funkcijoms; audito žurnalai jautriems veiksmams; jautrūs failai apsaugoti per .htaccess. Taip pat rekomenduojama naudotis svetaine per saugų HTTPS ryšį.</p>

    <h2>8. Jūsų teisės</h2>
    <p>Kadangi svetainė paprastai nekaupia lankytojų asmens duomenų, dauguma BDAR teisių įprastiems lankytojams nėra aktualios. Jeigu esate prijungto jutiklio savininkas ir norite susipažinti su duomenimis, juos ištaisyti ar ištrinti, kreipkitės į sistemos administratorių. Taip pat turite teisę pateikti skundą Valstybinei duomenų apsaugos inspekcijai (VDAI, vdai.lrv.lt).</p>

    <h2>9. Politikos pakeitimai</h2>
    <p>Ši privatumo politika gali būti atnaujinama keičiantis sistemos funkcionalumui arba taikomiems teisės aktams. Naujausia versija visada skelbiama šioje svetainėje.</p>
  </section>

  <!-- ════════════════ ENGLISH ════════════════ -->
  <section class="lang-section" id="section-en">
    <h1>Privacy Policy</h1>
    <div class="updated">Updated: 2026-06-20</div>

    <div class="callout">
      <strong>In short:</strong> this site is a public IoT environmental-sensor monitoring system. It uses only <strong>strictly necessary cookies</strong> (for the admin login session) and browser local storage (for your display preferences). There are NO tracking, advertising or analytics cookies.
    </div>

    <h2>1. General information and data controller</h2>
    <p>This site is a public IoT environmental-sensor monitoring system. It shows the environmental readings transmitted by voluntarily connected sensors and their location on the map. The site is operated by the Vilnius University Methodical STEAM Education Centre. For data-processing questions, contact the site administrator<?php if ($contactEmail): ?> at <a href="mailto:<?= htmlspecialchars($contactEmail) ?>"><?= htmlspecialchars($contactEmail) ?></a><?php endif; ?>.</p>

    <h2>2. What data the system processes</h2>
    <p>The site does not collect visitors' names, surnames, email addresses, or other directly identifying personal data. Only the data transmitted by voluntarily connected IoT sensors is stored:</p>
    <table>
      <thead><tr><th>Data</th><th>Purpose</th><th>Legal basis</th></tr></thead>
      <tbody>
        <tr><td>Sensor coordinates (latitude, longitude)</td><td>Show the sensor's location on the map</td><td>Legitimate interest</td></tr>
        <tr><td>Sensor WiFi MAC address</td><td>Identify and distinguish sensors</td><td>Legitimate interest</td></tr>
        <tr><td>Sensor readings (temperature, humidity, etc.)</td><td>Display and analyse environmental data</td><td>Legitimate interest</td></tr>
        <tr><td>IP address recorded in server logs</td><td>System security and fault diagnostics</td><td>Legitimate interest</td></tr>
      </tbody>
    </table>
    <p>The MAC address is used only to identify sensors. It is not used to track or profile users.</p>

    <h2>3. Purpose of processing</h2>
    <p>Data is processed in order to: receive the readings transmitted by sensors; display the data on the map; ensure the system's operation and security; and allow historical readings to be reviewed.</p>

    <h2>4. Cookies and local storage</h2>
    <p>Only strictly necessary cookies (for the admin session) and browser local storage for your preferences are used. There are no tracking, advertising or analytics cookies. A full list of cookies, their purpose and management options is provided in the separate <a class="inline" href="cookies.php">cookie policy</a>.</p>

    <h2>5. Data retention and deletion</h2>
    <ul>
      <li>Sensor readings are kept for an administrator-defined period. The default configuration may retain historical data indefinitely.</li>
      <li>If a newly registered sensor transmits no data within 3 minutes, it is automatically removed from the system.</li>
      <li>At the sensor owner's request, the administrator can erase the sensor and all its associated data via the management panel.</li>
      <li>The administrator may also remove test, non-working or incorrectly registered sensors.</li>
    </ul>

    <h2>6. Third parties (map services)</h2>
    <?php if ($usesGmaps): ?>
    <p>The map uses the <strong>Google Maps</strong> service — sensor coordinates and your browser's IP address are sent to it (to deliver the map tiles). <a class="inline" href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer">Google's privacy policy</a> applies. Charts use the Chart.js library, which runs in your browser and transmits no data. No other third-party tracking tools are used.</p>
    <?php elseif ($usesYandex): ?>
    <p>The map uses the <strong>Yandex Maps</strong> service — map tiles are loaded from Yandex servers (<code>*.maps.yandex.net</code>), to which your browser's IP address is visible (technically required to deliver the tiles). <a class="inline" href="https://yandex.com/legal/confidential/" target="_blank" rel="noopener noreferrer">Yandex's privacy policy</a> applies. The Leaflet library (self-hosted on this site) and Chart.js charts run in your browser and transmit no data. No other third-party tracking tools are used.</p>
    <?php else: ?>
    <p>The map may use <strong>OpenStreetMap</strong>, <strong>OpenTopoMap</strong>, <strong>CARTO</strong> or another provider configured by the administrator. Map tiles are loaded from the tile provider's servers (e.g. <code>tile.opentopomap.org</code>, <code>basemaps.cartocdn.com</code> or <code>tile.openstreetmap.org</code>) — your browser's IP address is visible to them (technically required to deliver the tiles). The <a class="inline" href="https://wiki.osmfoundation.org/wiki/Privacy_Policy" target="_blank" rel="noopener noreferrer">OpenStreetMap Foundation privacy policy</a> applies. The Leaflet library (self-hosted on this site) and Chart.js charts run in your browser and transmit no data. No other third-party tracking tools are used.</p>
    <?php endif; ?>

    <h2>7. Data security</h2>
    <p>The system applies technical and organizational security measures: parameterized (PDO prepared) SQL statements — protection against SQL injection; output escaping — protection against XSS; a Content Security Policy (CSP) and other security headers; the admin password stored with bcrypt; access control for administration functions; audit logs for sensitive actions; sensitive files protected via .htaccess. Using the site over a secure HTTPS connection is also recommended.</p>

    <h2>8. Your rights</h2>
    <p>Because the site generally does not collect visitors' personal data, most GDPR rights are not relevant to ordinary visitors. If you are the owner of a connected sensor and wish to access, rectify or erase the related data, contact the system administrator. You also have the right to lodge a complaint with the supervisory authority (in Lithuania: the State Data Protection Inspectorate, vdai.lrv.lt).</p>

    <h2>9. Changes to this policy</h2>
    <p>This privacy policy may be updated as the system's functionality or applicable legislation changes. The latest version is always published on this site.</p>
  </section>

</div>

<footer class="attribution-footer" id="attFooter"></footer>

<script>
let lang = localStorage.getItem('iot_lang') || (navigator.language?.startsWith('lt') ? 'lt' : 'en');
const TITLES = {
  lt: <?= json_encode($titleLt, JSON_UNESCAPED_UNICODE) ?>,
  en: <?= json_encode($titleEn, JSON_UNESCAPED_UNICODE) ?>,
};

function setLang(l) {
  lang = l;
  localStorage.setItem('iot_lang', l);
  applyLang();
}
function applyLang() {
  document.getElementById('langLt').classList.toggle('active', lang === 'lt');
  document.getElementById('langEn').classList.toggle('active', lang === 'en');
  document.getElementById('section-lt').classList.toggle('active', lang === 'lt');
  document.getElementById('section-en').classList.toggle('active', lang === 'en');
  document.documentElement.lang = lang;
  document.getElementById('backBtn').textContent = lang === 'lt' ? 'Į žemėlapį' : 'To map';
  const adminBtn = document.getElementById('backAdminBtn');
  if (adminBtn) adminBtn.textContent = lang === 'lt' ? 'Administravimas' : 'Admin';
  document.getElementById('siteTitle').textContent = TITLES[lang] || TITLES.lt;
  document.title = (TITLES[lang] || TITLES.lt) + (lang === 'lt' ? ' — Privatumas' : ' — Privacy');
  renderAttribution();
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// Attribution (base64-encoded)
const __a = ['QWxla3NhbmRy', 'IElndW1lbm92'];
const __i = {
  lt: 'VmlsbmlhdXMgdW5pdmVyc2l0ZXRvIE1ldG9kaW5pcyBTVEVBTSB1Z2R5bW8gY2VudHJhcw==',
  en: 'Vmlsbml1cyBVbml2ZXJzaXR5IE1ldGhvZGljYWwgU1RFQU0gRWR1Y2F0aW9uIENlbnRyZQ=='
};
function __dec(b) { try { return decodeURIComponent(escape(atob(b))); } catch { return ''; } }
function renderAttribution() {
  const f = document.getElementById('attFooter');
  if (!f) return;
  const author = __dec(__a[0]) + __dec(__a[1]);
  const inst = __dec(__i[lang] || __i.lt);
  f.innerHTML = `<span><strong>${escapeHtml(author)}</strong></span><span class="sep">·</span><span>${escapeHtml(inst)}</span>`
    + `<span class="sep">·</span><a href="cookies.php" style="color:var(--accent);text-decoration:none">${lang === 'lt' ? 'Slapukai' : 'Cookies'}</a>`;
}

applyLang();
</script>
</body>
</html>
