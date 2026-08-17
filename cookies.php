<?php
require_once __DIR__ . '/includes/auth.php';   // to check the session
require_once __DIR__ . '/includes/config.php';
if (function_exists('setSecurityHeaders')) setSecurityHeaders();
$titleLt = defined('SITE_TITLE_LT') ? SITE_TITLE_LT : 'IoT Jutiklių Žemėlapis';
$titleEn = defined('SITE_TITLE_EN') ? SITE_TITLE_EN : 'IoT Sensor Map';
// Map provider — the policy changes per the EFFECTIVE choice
// (explicit MAP_TILE_PROVIDER), not by the presence of a key.
$mapProv   = function_exists('effectiveMapProvider') ? effectiveMapProvider() : 'opentopomap';
$usesGmaps = ($mapProv === 'google');
$usesYandex = ($mapProv === 'yandex');

// Is the visitor a logged-in administrator?
// If YES — show a link back to admin AND to the map.
// If NO (a regular visitor from the map) — the admin link
// NOT ADDED (for security we do not expose the admin file).
$isAdminVisitor = isAdminAuthorized();
$adminFile = $isAdminVisitor ? adminFilePath() : null;
$contactEmail = (defined('CONTACT_EMAIL') && CONTACT_EMAIL !== '') ? CONTACT_EMAIL : '';
?>
<!DOCTYPE html>
<html lang="lt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($titleLt, ENT_QUOTES) ?> — Slapukai / Cookies</title>
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
.btn-primary { background: var(--accent); color: var(--bg); border-color: var(--accent); font-weight: 600; }
.lang-switch { display: flex; gap: .25rem; }
.lang-switch a { font-size: .72rem; font-weight: 600; padding: .25rem .55rem; border-radius: 5px; color: var(--muted); text-decoration: none; border: 1px solid var(--border); cursor: pointer; }
.lang-switch a.active { background: var(--accent); color: var(--bg); border-color: var(--accent); }

.wrap { flex: 1; width: min(820px, 94vw); margin: 2rem auto; padding: 0 1rem; }
h1 { font-size: 1.6rem; margin-bottom: .35rem; }
.updated { color: var(--muted); font-size: .8rem; margin-bottom: 2rem; }
h2 { font-size: 1.15rem; margin: 2rem 0 .75rem; color: var(--accent); }
p { margin-bottom: .85rem; font-size: .9rem; }
table { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: .82rem; }
th, td { text-align: left; padding: .6rem .75rem; border-bottom: 1px solid var(--border); vertical-align: top; }
th { color: var(--muted); text-transform: uppercase; font-size: .68rem; letter-spacing: .05em; }
td code { font-family: var(--mono); font-size: .78rem; color: var(--accent); }
a.inline { color: var(--accent); }
.lang-section { display: none; }
.lang-section.active { display: block; }

/* Sutikimo valdiklis */
.consent-panel { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 1.5rem; margin: 1.5rem 0; }
.consent-status { display: flex; align-items: center; gap: .6rem; font-size: .9rem; margin-bottom: 1.25rem; padding-bottom: 1.25rem; border-bottom: 1px solid var(--border); }
.status-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.status-dot.accepted { background: var(--ok); box-shadow: 0 0 8px var(--ok); }
.status-dot.necessary { background: var(--muted); }
.status-dot.none { background: var(--accent2); }
.toggle-row { display: flex; align-items: flex-start; gap: 1rem; padding: 1rem 0; border-bottom: 1px solid var(--border); }
.toggle-row:last-of-type { border-bottom: none; }
.toggle-info { flex: 1; }
.toggle-info .name { font-weight: 600; font-size: .9rem; margin-bottom: .2rem; }
.toggle-info .desc { font-size: .8rem; color: var(--muted); }
.switch { position: relative; width: 46px; height: 26px; flex-shrink: 0; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider { position: absolute; inset: 0; background: var(--surface2); border: 1px solid var(--border); border-radius: 26px; cursor: pointer; transition: .2s; }
.slider::before { content: ''; position: absolute; width: 18px; height: 18px; left: 3px; top: 3px; background: var(--muted); border-radius: 50%; transition: .2s; }
.switch input:checked + .slider { background: var(--accent); border-color: var(--accent); }
.switch input:checked + .slider::before { transform: translateX(20px); background: var(--bg); }
.switch input:disabled + .slider { opacity: .6; cursor: not-allowed; }
.consent-actions { display: flex; gap: .6rem; margin-top: 1.25rem; flex-wrap: wrap; }

.attribution-footer { flex-shrink: 0; display: flex; align-items: center; justify-content: center; gap: .5rem; padding: .5rem 1rem; background: var(--surface); border-top: 1px solid var(--border); font-size: .68rem; color: var(--muted); flex-wrap: wrap; }
.attribution-footer strong { color: var(--text); }
.attribution-footer .sep { opacity: .5; }
.attribution-footer a { color: var(--accent); text-decoration: none; }
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
    <h1>Slapukų politika</h1>
    <div class="updated">Atnaujinta: 2026-06-20</div>

    <p>Slapukai (angl. <em>cookies</em>) — tai maži tekstiniai įrašai, kuriuos svetainė išsaugo jūsų naršyklėje. Ši svetainė naudoja <strong>tik techniškai būtinus</strong> slapukus ir naršyklės vietinę saugyklą. Sekimo, reklamos ar analitikos slapukų <strong>nėra</strong>.</p>

    <!-- Sutikimo valdiklis -->
    <div class="consent-panel">
      <div class="consent-status">
        <span class="status-dot" id="statusDotLt"></span>
        <span id="statusTextLt">—</span>
      </div>

      <div class="toggle-row">
        <div class="toggle-info">
          <div class="name">Būtinieji slapukai</div>
          <div class="desc">Reikalingi pagrindiniam veikimui (administratoriaus sesija, nuostatų įsiminimas). Be jų svetainė neveiktų tinkamai.</div>
        </div>
        <label class="switch"><input type="checkbox" checked disabled><span class="slider"></span></label>
      </div>

      <div class="toggle-row">
        <div class="toggle-info">
          <div class="name">Nuostatų saugykla</div>
          <div class="desc">Įsimena kalbą, filtrus, žemėlapio stilių ir žymeklių dydį (localStorage). Pasirenkama.</div>
        </div>
        <label class="switch"><input type="checkbox" id="prefToggleLt"><span class="slider"></span></label>
      </div>

      <div class="consent-actions">
        <button class="btn btn-primary" onclick="saveConsent('accepted')">Priimti viską</button>
        <button class="btn" onclick="saveConsent('necessary')">Tik būtinieji</button>
        <button class="btn" onclick="resetConsent()">Atstatyti pasirinkimą</button>
      </div>
    </div>

    <h2>Naudojami slapukai ir saugykla</h2>
    <table>
      <thead><tr><th>Pavadinimas</th><th>Tipas</th><th>Paskirtis</th><th>Trukmė</th></tr></thead>
      <tbody>
        <tr><td><code>PHPSESSID</code></td><td>Slapukas (sesijos)</td><td>Administratoriaus prisijungimo sesija</td><td>Iki naršyklės uždarymo</td></tr>
        <tr><td><code>iot_cookie_consent</code></td><td>Vietinė saugykla</td><td>Jūsų slapukų pasirinkimas</td><td>Kol neištrinama</td></tr>
        <tr><td><code>iot_lang</code></td><td>Vietinė saugykla</td><td>Kalbos pasirinkimas (LT/EN)</td><td>Kol neištrinama</td></tr>
        <tr><td><code>iot_filters</code></td><td>Vietinė saugykla</td><td>Žemėlapio rodmenų filtras</td><td>Kol neištrinama</td></tr>
        <tr><td><code>iot_map_style</code></td><td>Vietinė saugykla</td><td>Žemėlapio stilius (diena/naktis)</td><td>Kol neištrinama</td></tr>
        <tr><td><code>iot_marker_size</code></td><td>Vietinė saugykla</td><td>Žymeklių dydis</td><td>Kol neištrinama</td></tr>
        <?php if ($usesGmaps): ?>
        <tr><td colspan="4" style="background:var(--surface2);font-weight:600">Google Maps (trečiosios šalies — nustato Google rodant žemėlapį)</td></tr>
        <tr><td><code>NID</code></td><td>Slapukas (Google)</td><td>Google naudotojo nuostatos, saugumas</td><td>~6 mėn.</td></tr>
        <tr><td><code>CONSENT</code></td><td>Slapukas (Google)</td><td>Google paslaugų sutikimo būsena</td><td>~2 m.</td></tr>
        <tr><td><code>SOCS</code></td><td>Slapukas (Google)</td><td>Google sutikimo/nuostatų sesija</td><td>~13 mėn.</td></tr>
        <tr><td><code>1P_JAR</code></td><td>Slapukas (Google)</td><td>Google reklamos/analizės statistika</td><td>~1 mėn.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <h2>Kodėl naudojami šie duomenys?</h2>
    <p>Šie duomenys naudojami tik administratoriaus prisijungimo sesijai palaikyti, naudotojo pasirinktoms nuostatoms išsaugoti ir patogesniam svetainės naudojimui. Jie nėra naudojami reklamai, profiliavimui ar naudotojų elgsenos analizei.</p>

    <h2>Ar reikalingas sutikimas?</h2>
    <p>Kadangi naudojami tik techniškai būtini slapukai ir vietinė saugykla, reikalinga svetainės funkcijoms veikti arba naudotojo pasirinktoms nuostatoms įsiminti, papildomas slapukų sutikimas nėra privalomas. Vis dėlto pasirenkamą nuostatų saugyklą galite bet kada įjungti ar išjungti aukščiau esančiu valdikliu.</p>

    <h2>Trečiųjų šalių ištekliai (žemėlapis)</h2>
    <?php if ($usesGmaps): ?>
    <p>Žemėlapiui rodyti naudojama <strong>Google Maps</strong>. Užkraunant žemėlapio plyteles ir biblioteką, jūsų naršyklė jungiasi prie Google serverių (<code>maps.googleapis.com</code>, <code>maps.gstatic.com</code>), kuriems matomas jūsų IP adresas. Google gali naudoti slapukus pagal savo <a class="inline" href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer">privatumo politiką</a>. Šie slapukai nustatomi Google, ne šios svetainės.</p>
    <?php elseif ($usesYandex): ?>
    <p>Žemėlapiui rodyti naudojama <strong>Yandex Maps</strong>. Užkraunant žemėlapio plyteles, jūsų naršyklė jungiasi prie Yandex serverių (<code>*.maps.yandex.net</code>), kuriems matomas jūsų IP adresas (techniškai būtina plytelėms pristatyti). Yandex gali naudoti slapukus pagal savo <a class="inline" href="https://yandex.com/legal/confidential/" target="_blank" rel="noopener noreferrer">privatumo politiką</a>. Leaflet biblioteka talpinama šioje svetainėje (be CDN).</p>
    <?php else: ?>
    <p>Žemėlapiui rodyti naudojama <strong>OpenStreetMap</strong> (atvirojo kodo). Užkraunant žemėlapio plyteles, jūsų naršyklė jungiasi prie plytelių tiekėjo serverių (pvz. <code>tile.opentopomap.org</code>, <code>basemaps.cartocdn.com</code> ar <code>tile.openstreetmap.org</code>), kuriems matomas jūsų IP adresas (techniškai būtina plytelėms pristatyti). Leaflet biblioteka talpinama šioje svetainėje (be CDN). Taikoma <a class="inline" href="https://wiki.osmfoundation.org/wiki/Privacy_Policy" target="_blank" rel="noopener noreferrer">OpenStreetMap Foundation privatumo politika</a>.</p>
    <?php endif; ?>

    <h2>Kaip valdyti slapukus</h2>
    <p>Savo pasirinkimą galite keisti bet kada šiame puslapyje (mygtukai aukščiau) arba per nuorodą „Slapukai", esančią kiekvieno puslapio poraštėje. Taip pat galite ištrinti slapukus ir vietinę saugyklą savo naršyklės nustatymuose.</p>
    <p>Daugiau apie duomenų tvarkymą — <a class="inline" href="privacy.php">Privatumo politikoje</a>.</p>

    <h2>Kontaktai</h2>
    <p>Klausimais dėl slapukų naudojimo ar duomenų tvarkymo kreipkitės į svetainės administratorių<?php if ($contactEmail): ?> el. paštu <a href="mailto:<?= htmlspecialchars($contactEmail) ?>"><?= htmlspecialchars($contactEmail) ?></a><?php endif; ?>.</p>
  </section>

  <!-- ════════════════ ENGLISH ════════════════ -->
  <section class="lang-section" id="section-en">
    <h1>Cookie Policy</h1>
    <div class="updated">Updated: 2026-06-20</div>

    <p>Cookies are small text records a website stores in your browser. This site uses <strong>only strictly necessary</strong> cookies and browser local storage. There are <strong>no</strong> tracking, advertising or analytics cookies.</p>

    <!-- Consent control -->
    <div class="consent-panel">
      <div class="consent-status">
        <span class="status-dot" id="statusDotEn"></span>
        <span id="statusTextEn">—</span>
      </div>

      <div class="toggle-row">
        <div class="toggle-info">
          <div class="name">Strictly necessary cookies</div>
          <div class="desc">Required for core functionality (admin session, remembering settings). The site would not work properly without them.</div>
        </div>
        <label class="switch"><input type="checkbox" checked disabled><span class="slider"></span></label>
      </div>

      <div class="toggle-row">
        <div class="toggle-info">
          <div class="name">Preference storage</div>
          <div class="desc">Remembers language, filters, map style and marker size (localStorage). Optional.</div>
        </div>
        <label class="switch"><input type="checkbox" id="prefToggleEn"><span class="slider"></span></label>
      </div>

      <div class="consent-actions">
        <button class="btn btn-primary" onclick="saveConsent('accepted')">Accept all</button>
        <button class="btn" onclick="saveConsent('necessary')">Necessary only</button>
        <button class="btn" onclick="resetConsent()">Reset choice</button>
      </div>
    </div>

    <h2>Cookies and storage used</h2>
    <table>
      <thead><tr><th>Name</th><th>Type</th><th>Purpose</th><th>Duration</th></tr></thead>
      <tbody>
        <tr><td><code>PHPSESSID</code></td><td>Cookie (session)</td><td>Admin login session</td><td>Until browser closes</td></tr>
        <tr><td><code>iot_cookie_consent</code></td><td>Local storage</td><td>Your cookie choice</td><td>Until cleared</td></tr>
        <tr><td><code>iot_lang</code></td><td>Local storage</td><td>Language choice (LT/EN)</td><td>Until cleared</td></tr>
        <tr><td><code>iot_filters</code></td><td>Local storage</td><td>Map metric filter</td><td>Until cleared</td></tr>
        <tr><td><code>iot_map_style</code></td><td>Local storage</td><td>Map style (day/night)</td><td>Until cleared</td></tr>
        <tr><td><code>iot_marker_size</code></td><td>Local storage</td><td>Marker size</td><td>Until cleared</td></tr>
        <?php if ($usesGmaps): ?>
        <tr><td colspan="4" style="background:var(--surface2);font-weight:600">Google Maps (third-party — set by Google when displaying the map)</td></tr>
        <tr><td><code>NID</code></td><td>Cookie (Google)</td><td>Google user preferences, security</td><td>~6 months</td></tr>
        <tr><td><code>CONSENT</code></td><td>Cookie (Google)</td><td>Google services consent state</td><td>~2 years</td></tr>
        <tr><td><code>SOCS</code></td><td>Cookie (Google)</td><td>Google consent/preferences session</td><td>~13 months</td></tr>
        <tr><td><code>1P_JAR</code></td><td>Cookie (Google)</td><td>Google advertising/analytics statistics</td><td>~1 month</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <h2>Why is this data used?</h2>
    <p>This data is used only to maintain the admin login session, to save your chosen preferences, and to make the site more convenient to use. It is not used for advertising, profiling, or analysing user behaviour.</p>

    <h2>Is consent required?</h2>
    <p>Because only strictly necessary cookies and local storage — needed for the site to function or to remember your chosen preferences — are used, no additional cookie consent is required. You can still enable or disable the optional preference storage at any time using the control above.</p>

    <h2>Third-party resources (map)</h2>
    <?php if ($usesGmaps): ?>
    <p>The map uses <strong>Google Maps</strong>. When loading map tiles and the library, your browser connects to Google servers (<code>maps.googleapis.com</code>, <code>maps.gstatic.com</code>), to which your IP address is visible. Google may set cookies under its <a class="inline" href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer">privacy policy</a>. These cookies are set by Google, not by this site.</p>
    <?php elseif ($usesYandex): ?>
    <p>The map uses <strong>Yandex Maps</strong>. When loading map tiles, your browser connects to Yandex servers (<code>*.maps.yandex.net</code>), to which your IP address is visible (technically required to deliver the tiles). Yandex may set cookies under its <a class="inline" href="https://yandex.com/legal/confidential/" target="_blank" rel="noopener noreferrer">privacy policy</a>. The Leaflet library is self-hosted on this site (no CDN).</p>
    <?php else: ?>
    <p>The map uses <strong>OpenStreetMap</strong> (open-source). When loading map tiles, your browser connects to the tile provider's servers (e.g. <code>tile.opentopomap.org</code>, <code>basemaps.cartocdn.com</code> or <code>tile.openstreetmap.org</code>), to which your IP address is visible (technically required to deliver the tiles). The Leaflet library is self-hosted on this site (no CDN). The <a class="inline" href="https://wiki.osmfoundation.org/wiki/Privacy_Policy" target="_blank" rel="noopener noreferrer">OpenStreetMap Foundation privacy policy</a> applies.</p>
    <?php endif; ?>

    <h2>How to manage cookies</h2>
    <p>You can change your choice at any time on this page (buttons above) or via the "Cookies" link in the footer of every page. You can also delete cookies and local storage in your browser settings.</p>
    <p>More about data processing in the <a class="inline" href="privacy.php">Privacy Policy</a>.</p>

    <h2>Contacts</h2>
    <p>For questions about cookie use or data processing, contact the site administrator<?php if ($contactEmail): ?> at <a href="mailto:<?= htmlspecialchars($contactEmail) ?>"><?= htmlspecialchars($contactEmail) ?></a><?php endif; ?>.</p>
  </section>

</div>

<footer class="attribution-footer" id="attFooter"></footer>

<script>
let lang = localStorage.getItem('iot_lang') || (navigator.language?.startsWith('lt') ? 'lt' : 'en');
const TITLES = {
  lt: <?= json_encode($titleLt, JSON_UNESCAPED_UNICODE) ?>,
  en: <?= json_encode($titleEn, JSON_UNESCAPED_UNICODE) ?>,
};
const STR = {
  lt: { back:'Į žemėlapį', accepted:'Pasirinkta: visi slapukai priimti', necessary:'Pasirinkta: tik būtinieji', none:'Pasirinkimas dar nepadarytas', saved:'Pasirinkimas išsaugotas', reset:'Pasirinkimas atstatytas', privacy:'Privatumas', cookies:'Slapukai' },
  en: { back:'To map', accepted:'Selected: all cookies accepted', necessary:'Selected: necessary only', none:'No choice made yet', saved:'Choice saved', reset:'Choice reset', privacy:'Privacy', cookies:'Cookies' },
};

function setLang(l) { lang = l; localStorage.setItem('iot_lang', l); applyLang(); }

function applyLang() {
  document.getElementById('langLt').classList.toggle('active', lang === 'lt');
  document.getElementById('langEn').classList.toggle('active', lang === 'en');
  document.getElementById('section-lt').classList.toggle('active', lang === 'lt');
  document.getElementById('section-en').classList.toggle('active', lang === 'en');
  document.documentElement.lang = lang;
  document.getElementById('backBtn').textContent = STR[lang].back;
  const adminBtn = document.getElementById('backAdminBtn');
  if (adminBtn) adminBtn.textContent = lang === 'lt' ? 'Administravimas' : 'Admin';
  document.getElementById('siteTitle').textContent = TITLES[lang] || TITLES.lt;
  document.title = (TITLES[lang] || TITLES.lt) + (lang === 'lt' ? ' — Slapukai' : ' — Cookies');
  renderStatus();
  renderAttribution();
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// ─── Consent state ───────────────────────────────────────
function renderStatus() {
  const choice = localStorage.getItem('iot_cookie_consent'); // accepted | necessary | null
  const cls = choice === 'accepted' ? 'accepted' : (choice === 'necessary' ? 'necessary' : 'none');
  const txt = STR[lang][choice === 'accepted' ? 'accepted' : (choice === 'necessary' ? 'necessary' : 'none')];
  ['Lt', 'En'].forEach(suf => {
    const dot = document.getElementById('statusDot' + suf);
    const t   = document.getElementById('statusText' + suf);
    if (dot) dot.className = 'status-dot ' + cls;
    if (t)   t.textContent = txt;
    const pref = document.getElementById('prefToggle' + suf);
    if (pref) pref.checked = (choice === 'accepted');
  });
}

function saveConsent(value) {
  localStorage.setItem('iot_cookie_consent', value);
  localStorage.setItem('iot_cookie_consent_at', new Date().toISOString());
  // If preference storage is declined — clear non-essential values
  if (value === 'necessary') {
    ['iot_filters', 'iot_map_style', 'iot_marker_size'].forEach(k => localStorage.removeItem(k));
  }
  renderStatus();
  flash(STR[lang].saved);
}

function resetConsent() {
  localStorage.removeItem('iot_cookie_consent');
  localStorage.removeItem('iot_cookie_consent_at');
  renderStatus();
  flash(STR[lang].reset);
}

function flash(msg) {
  let f = document.getElementById('flashMsg');
  if (!f) {
    f = document.createElement('div');
    f.id = 'flashMsg';
    f.style.cssText = 'position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);background:var(--ok);color:var(--bg);padding:.7rem 1.4rem;border-radius:8px;font-weight:600;font-size:.85rem;z-index:9999;box-shadow:0 8px 30px rgba(0,0,0,.4)';
    document.body.appendChild(f);
  }
  f.textContent = msg;
  f.style.opacity = '1';
  clearTimeout(f._t);
  f._t = setTimeout(() => { f.style.opacity = '0'; f.style.transition = 'opacity .4s'; }, 2200);
}

// ─── Attribution (base64-encoded) ────────────────────────
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
  f.innerHTML = `<span><strong>${escapeHtml(author)}</strong></span>`
    + `<span class="sep">·</span><span>${escapeHtml(inst)}</span>`
    + `<span class="sep">·</span><a href="privacy.php">${STR[lang].privacy}</a>`;
}

applyLang();
</script>
</body>
</html>
