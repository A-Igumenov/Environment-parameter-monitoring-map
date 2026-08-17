<?php
require_once __DIR__ . '/includes/auth.php';
requireAdminPage(); // Logged-in administrators only

require_once __DIR__ . '/includes/config.php';
if (function_exists('setSecurityHeaders')) setSecurityHeaders();
$titleLt = defined('SITE_TITLE_LT') ? SITE_TITLE_LT : 'IoT Jutiklių Žemėlapis';
$titleEn = defined('SITE_TITLE_EN') ? SITE_TITLE_EN : 'IoT Sensor Map';
$adminFile = adminFilePath(); // includes/admin*.php (admin saugomas includes/)
?>
<!DOCTYPE html>
<html lang="lt">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow"><!-- admin UI: keep out of search indexes -->
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($titleLt, ENT_QUOTES) ?> — <?= htmlspecialchars('Jutiklių valdymas') ?></title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<style>
:root {
  --bg:#0a0f1e; --surface:#111827; --surface2:#1a2235; --border:#1e2d45;
  --accent:#00c8ff; --accent2:#7b61ff; --ok:#22d3a0; --warn:#f59e0b;
  --danger:#ef4444; --text:#e2e8f0; --muted:#64748b;
  --mono:'JetBrains Mono','Fira Code',monospace; --sans:'Inter',system-ui,sans-serif;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: var(--sans); background: var(--bg); color: var(--text);
  min-height: 100vh; display: flex; flex-direction: column;
}
header {
  display: flex; align-items: center; gap: 1rem;
  padding: .85rem 1.25rem; background: var(--surface);
  border-bottom: 1px solid var(--border);
}
.logo { display: flex; align-items: center; gap: .55rem; font-weight: 700; font-size: 1rem; }
.logo svg { width: 22px; height: 22px; color: var(--accent); }
header .spacer { flex: 1; }
.btn {
  background: var(--surface2); color: var(--text); border: 1px solid var(--border);
  border-radius: 8px; padding: .5rem .9rem; font-size: .85rem; cursor: pointer;
  font-family: var(--sans); text-decoration: none; display: inline-flex;
  align-items: center; gap: .4rem; transition: .15s;
}
.btn:hover { border-color: var(--muted); }
.btn-danger { background: rgba(239,68,68,.12); border-color: rgba(239,68,68,.3); color: #fca5a5; }
.btn-danger:hover { background: rgba(239,68,68,.2); }
.btn-warn { background: rgba(245,158,11,.12); border-color: rgba(245,158,11,.3); color: #fcd34d; }
.btn-hmac-on { background: rgba(34,197,94,.12); border-color: rgba(34,197,94,.35); color: #86efac; }
.btn-sm { padding: .3rem .65rem; font-size: .76rem; }
.lang-switch { display: flex; gap: .25rem; }
.lang-switch a {
  font-size: .72rem; font-weight: 600; padding: .25rem .55rem; border-radius: 5px;
  color: var(--muted); text-decoration: none; border: 1px solid var(--border);
}
.lang-switch a.active { background: var(--accent); color: var(--bg); border-color: var(--accent); }

.wrap { flex: 1; width: min(1000px, 96vw); margin: 1.5rem auto; padding: 0 1rem; }
h1 { font-size: 1.3rem; margin-bottom: .35rem; }
.subtitle { color: var(--muted); font-size: .85rem; margin-bottom: 1.5rem; }

.toolbar { display: flex; gap: .75rem; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; }
.search {
  flex: 1; min-width: 200px; background: var(--surface2); border: 1px solid var(--border);
  border-radius: 8px; color: var(--text); padding: .55rem .85rem; font-size: .85rem;
}

table { width: 100%; border-collapse: collapse; background: var(--surface); border-radius: 12px; overflow: hidden; }
thead th {
  text-align: left; font-size: .72rem; text-transform: uppercase; letter-spacing: .05em;
  color: var(--muted); padding: .75rem 1rem; border-bottom: 1px solid var(--border); font-weight: 600;
}
tbody td { padding: .75rem 1rem; border-bottom: 1px solid var(--border); font-size: .85rem; }
tbody tr:last-child td { border-bottom: none; }
tbody tr.selected { background: rgba(0,200,255,.06); }
tbody tr:hover { background: rgba(255,255,255,.02); cursor: pointer; }
.label-cell { font-family: var(--mono); font-weight: 600; color: var(--accent); }
.dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: .4rem; }
.dot-on { background: var(--ok); }
.dot-off { background: var(--muted); }
.muted { color: var(--muted); }
.row-actions { display: flex; gap: .4rem; justify-content: flex-end; }

/* ── Region grouping ── */
.region {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 12px; margin-bottom: 1rem; overflow: hidden;
}
.region-head {
  display: flex; align-items: center; gap: .75rem;
  padding: .85rem 1rem; cursor: pointer; user-select: none;
  background: var(--surface2); border-bottom: 1px solid transparent;
}
.region.open .region-head { border-bottom-color: var(--border); }
.region-head:hover { background: rgba(255,255,255,.03); }
.region-caret { transition: transform .2s; color: var(--muted); font-size: .8rem; }
.region.open .region-caret { transform: rotate(90deg); }
.region-name { font-family: var(--mono); font-weight: 700; color: var(--accent); font-size: .95rem; }
.region-meta { color: var(--muted); font-size: .78rem; }
.region-head .spacer { flex: 1; }
.region-actions { display: flex; gap: .4rem; }
.region-body { display: none; }
.region.open .region-body { display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; }
.region-body table { border-radius: 0; background: transparent; min-width: 760px; }
/* Flat (ungrouped) table container also scrolls horizontally on narrow screens */
#tableContainer { overflow-x: auto; -webkit-overflow-scrolling: touch; }
#tableContainer > table { min-width: 760px; }

/* ── Bulk action bar ── */
.bulk-bar {
  display: none; align-items: center; gap: .6rem; flex-wrap: wrap;
  padding: .6rem 1rem; background: rgba(0,200,255,.06);
  border-bottom: 1px solid var(--border);
}
.region.has-selection .bulk-bar { display: flex; }
.bulk-bar .count { font-size: .8rem; color: var(--accent); font-weight: 600; }
.bulk-bar .spacer { flex: 1; }

/* ── Filtrai ── */
.filters { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.chk {
  display: inline-flex; align-items: center; gap: .4rem; cursor: pointer;
  font-size: .82rem; color: var(--text); user-select: none;
}
.chk input { width: 16px; height: 16px; accent-color: var(--accent); cursor: pointer; }
.col-chk { width: 38px; text-align: center; }
.col-chk input { width: 16px; height: 16px; accent-color: var(--accent); cursor: pointer; }

/* ── Password modal (masked input) ── */
.modal-overlay {
  display: none; position: fixed; inset: 0; z-index: 2000;
  background: rgba(0,0,0,.55); align-items: center; justify-content: center;
}
.modal-overlay.show { display: flex; }
.modal {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 12px; padding: 1.5rem; width: min(380px, 92vw);
}
.modal h3 { font-size: 1rem; margin-bottom: .35rem; }
.modal p { color: var(--muted); font-size: .82rem; margin-bottom: 1rem; }
.modal input[type="password"] {
  width: 100%; background: var(--surface2); border: 1px solid var(--border);
  border-radius: 8px; color: var(--text); padding: .6rem .85rem; font-size: .9rem;
  margin-bottom: 1rem; font-family: var(--sans);
}
.modal input[type="password"]:focus { outline: none; border-color: var(--accent); }
.modal-actions { display: flex; gap: .6rem; justify-content: flex-end; }

.empty { text-align: center; padding: 3rem 1rem; color: var(--muted); }
.loading { text-align: center; padding: 3rem 1rem; color: var(--muted); }

.toast {
  position: fixed; bottom: 1.5rem; left: 50%; transform: translateX(-50%) translateY(20px);
  background: var(--surface2); border: 1px solid var(--border); border-radius: 10px;
  padding: .75rem 1.25rem; font-size: .85rem; opacity: 0; pointer-events: none;
  transition: .3s; z-index: 1000;
}
.toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
.toast.ok { border-color: rgba(34,211,160,.4); }
.toast.err { border-color: rgba(239,68,68,.4); }

.attribution-footer {
  flex-shrink: 0; display: flex; align-items: center; justify-content: center;
  gap: .5rem; padding: .4rem 1rem; background: var(--surface);
  border-top: 1px solid var(--border); font-size: .68rem; color: var(--muted);
}
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
    <a href="#" id="langLt" class="active" onclick="setLang('lt');return false">LT</a>
    <a href="#" id="langEn" onclick="setLang('en');return false">EN</a>
  </div>
  <a class="btn" href="<?= htmlspecialchars($adminFile, ENT_QUOTES) ?>">← <span data-i18n="back_to_admin">Į administravimą</span></a>
  <a class="btn" href="index.php">🗺 <span data-i18n="back_to_map">Į žemėlapį</span></a>
</header>

<div class="wrap">
  <h1 data-i18n="page_title">Jutiklių valdymas</h1>
  <div class="subtitle" data-i18n="page_sub">Visi patvirtinti jutikliai. Pasirinkite jutiklį, kad ištrintumėte jo duomenis arba patį jutiklį.</div>

  <div class="toolbar">
    <input type="search" class="search" id="search" name="sensor-filter" oninput="renderTable()"
           autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"
           data-lpignore="true" data-1p-ignore data-form-type="other" aria-autocomplete="none"
           readonly onfocus="this.removeAttribute('readonly')"
           data-i18n-ph="search_ph" placeholder="Ieškoti pagal pavadinimą...">
    <button class="btn" onclick="loadSensors()">↺ <span data-i18n="refresh">Atnaujinti</span></button>
  </div>

  <!-- Decoy fields absorb aggressive password-manager autofill so the real
       search box above stays empty until the user types. Visually hidden. -->
  <div aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden">
    <input type="text" name="fake-user" tabindex="-1" autocomplete="username">
    <input type="password" name="fake-pass" tabindex="-1" autocomplete="new-password">
  </div>

  <div class="filters">
    <span class="muted" style="font-size:.8rem" data-i18n="filter_label">Rodyti:</span>
    <label class="chk">
      <input type="checkbox" id="fOutdoor" checked onchange="renderTable()">
      🌳 <span data-i18n="loc_outdoor">Lauko</span>
    </label>
    <label class="chk">
      <input type="checkbox" id="fIndoor" checked onchange="renderTable()">
      🏠 <span data-i18n="loc_indoor">Patalpos</span>
    </label>
  </div>

  <div id="tableContainer">
    <div class="loading" data-i18n="loading">Kraunama...</div>
  </div>
</div>

<div class="modal-overlay" id="passModal">
  <div class="modal">
    <h3 id="passModalTitle" data-i18n="pass_modal_title">Patvirtinimas</h3>
    <p id="passModalText" data-i18n="enter_admin_pass">Įveskite administratoriaus slaptažodį patvirtinimui:</p>
    <input type="password" id="passModalInput" autocomplete="current-password"
           onkeydown="if(event.key==='Enter')passModalOk();if(event.key==='Escape')passModalCancel()">
    <div class="modal-actions">
      <button class="btn btn-sm" onclick="passModalCancel()" data-i18n="cancel">Atšaukti</button>
      <button class="btn btn-sm btn-danger" onclick="passModalOk()" data-i18n="confirm">Patvirtinti</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<footer class="attribution-footer" id="attFooter"></footer>

<script>
// ─── I18N ─────────────────────────────────────────────────
const I18N = {
  lt: {
    page_title: 'Jutiklių valdymas',
    page_sub: 'Visi patvirtinti jutikliai. Pasirinkite jutiklį, kad ištrintumėte jo duomenis arba patį jutiklį.',
    back_to_admin: 'Į administravimą',
    back_to_map: 'Į žemėlapį',
    refresh: 'Atnaujinti',
    search_ph: 'Ieškoti pagal pavadinimą...',
    loading: 'Kraunama...',
    col_label: 'Pavadinimas',
    col_status: 'Būsena',
    col_coords: 'Koordinatės',
    col_mac: 'MAC',
    col_location: 'Vieta',
    loc_indoor: 'Patalpos',
    loc_outdoor: 'Lauko',
    col_readings: 'Įrašų',
    col_lastseen: 'Paskutinis',
    col_actions: 'Veiksmai',
    online: 'Aktyvus',
    offline: 'Neaktyvus',
    clear_data: 'Trinti duomenis',
    delete_sensor: 'Trinti jutiklį',
    hmac_btn: '🔐 HMAC',
    hmac_on_title: 'HMAC parašas įjungtas — paspauskite pakeisti ar išjungti',
    hmac_off_title: 'Nustatyti HMAC secret (parašo apsauga)',
    hmac_prompt: (l) => `HMAC secret jutikliui ${l} (8–64 simboliai).\nTą patį secret įrašykite į jutiklio firmware.\nPalikite tuščią, kad išjungtumėte:`,
    hmac_set_ok: 'HMAC secret nustatytas. Įrašykite tą patį į firmware.',
    hmac_cleared: 'HMAC parašas išjungtas.',
    no_sensors: 'Patvirtintų jutiklių nėra.',
    confirm_clear: (l, n) => `Ištrinti VISUS jutiklio ${l} duomenų įrašus (${n})? Pats jutiklis liks.`,
    confirm_delete: (l) => `Visiškai ištrinti jutiklį ${l} ir VISUS jo duomenis? Šio veiksmo atšaukti negalima.`,
    enter_admin_pass: 'Įveskite administratoriaus slaptažodį patvirtinimui:',
    cleared: (n) => `Ištrinta ${n} įrašų.`,
    deleted: (l) => `Jutiklis ${l} ištrintas.`,
    error: 'Klaida',
    filter_label: 'Rodyti:',
    region_sensors: (n) => `${n} jutiklių`,
    region_clear: 'Valyti regiono duomenis',
    region_delete: 'Trinti regioną',
    select_all: 'Žymėti visus',
    selected_n: (n) => `Pažymėta: ${n}`,
    bulk_clear: 'Valyti pažymėtų duomenis',
    bulk_delete: 'Trinti pažymėtus',
    confirm_region_clear: (r, n) => `Ištrinti VISŲ regiono ${r} jutiklių (${n}) duomenis? Jutikliai liks.`,
    confirm_region_delete: (r, n) => `Visiškai ištrinti VISUS regiono ${r} jutiklius (${n}) ir jų duomenis? Neatšaukiama!`,
    confirm_bulk_clear: (n) => `Ištrinti ${n} pažymėtų jutiklių duomenis? Jutikliai liks.`,
    confirm_bulk_delete: (n) => `Visiškai ištrinti ${n} pažymėtus jutiklius ir jų duomenis? Neatšaukiama!`,
    region_cleared: (n) => `Regiono duomenys ištrinti (${n} įrašų).`,
    region_deleted: (r, n) => `Regionas ${r} ištrintas (${n} jutiklių).`,
    bulk_cleared: (s, r) => `Išvalyta ${s} jutiklių (${r} įrašų).`,
    bulk_deleted: (n) => `Ištrinta ${n} jutiklių.`,
    nothing_selected: 'Nepažymėta nė vieno jutiklio.',
    pass_modal_title: 'Patvirtinimas',
    cancel: 'Atšaukti',
    confirm: 'Patvirtinti',
  },
  en: {
    page_title: 'Sensor management',
    page_sub: 'All confirmed sensors. Select a sensor to delete its readings or the sensor itself.',
    back_to_admin: 'To admin',
    back_to_map: 'To map',
    refresh: 'Refresh',
    search_ph: 'Search by name...',
    loading: 'Loading...',
    col_label: 'Name',
    col_status: 'Status',
    col_coords: 'Coordinates',
    col_mac: 'MAC',
    col_location: 'Location',
    loc_indoor: 'Indoor',
    loc_outdoor: 'Outdoor',
    col_readings: 'Readings',
    col_lastseen: 'Last seen',
    col_actions: 'Actions',
    online: 'Online',
    offline: 'Offline',
    clear_data: 'Clear data',
    delete_sensor: 'Delete sensor',
    hmac_btn: '🔐 HMAC',
    hmac_on_title: 'HMAC signature enabled — click to change or disable',
    hmac_off_title: 'Set an HMAC secret (signature protection)',
    hmac_prompt: (l) => `HMAC secret for sensor ${l} (8–64 chars).\nConfigure the same secret in the sensor firmware.\nLeave empty to disable:`,
    hmac_set_ok: 'HMAC secret set. Configure the same value in the firmware.',
    hmac_cleared: 'HMAC signature disabled.',
    no_sensors: 'No confirmed sensors.',
    confirm_clear: (l, n) => `Delete ALL reading records for sensor ${l} (${n})? The sensor itself stays.`,
    confirm_delete: (l) => `Permanently delete sensor ${l} and ALL its data? This cannot be undone.`,
    enter_admin_pass: 'Enter the administrator password to confirm:',
    cleared: (n) => `Deleted ${n} records.`,
    deleted: (l) => `Sensor ${l} deleted.`,
    error: 'Error',
    filter_label: 'Show:',
    region_sensors: (n) => `${n} sensors`,
    region_clear: 'Clear region data',
    region_delete: 'Delete region',
    select_all: 'Select all',
    selected_n: (n) => `Selected: ${n}`,
    bulk_clear: 'Clear selected data',
    bulk_delete: 'Delete selected',
    confirm_region_clear: (r, n) => `Delete data for ALL sensors in region ${r} (${n})? The sensors stay.`,
    confirm_region_delete: (r, n) => `Permanently delete ALL sensors in region ${r} (${n}) and their data? Irreversible!`,
    confirm_bulk_clear: (n) => `Delete data for ${n} selected sensors? The sensors stay.`,
    confirm_bulk_delete: (n) => `Permanently delete ${n} selected sensors and their data? Irreversible!`,
    region_cleared: (n) => `Region data deleted (${n} records).`,
    region_deleted: (r, n) => `Region ${r} deleted (${n} sensors).`,
    bulk_cleared: (s, r) => `Cleared ${s} sensors (${r} records).`,
    bulk_deleted: (n) => `Deleted ${n} sensors.`,
    nothing_selected: 'No sensors selected.',
    pass_modal_title: 'Confirmation',
    cancel: 'Cancel',
    confirm: 'Confirm',
  },
};
let lang = localStorage.getItem('iot_lang') || (navigator.language?.startsWith('lt') ? 'lt' : 'en');
function t(k) { return I18N[lang][k] ?? I18N.lt[k] ?? k; }
function locale() { return lang === 'lt' ? 'lt-LT' : 'en-GB'; }

const TITLES = {
  lt: <?= json_encode($titleLt, JSON_UNESCAPED_UNICODE) ?>,
  en: <?= json_encode($titleEn, JSON_UNESCAPED_UNICODE) ?>,
};

function setLang(l) {
  lang = l;
  localStorage.setItem('iot_lang', l);
  applyLang();
  renderTable();
}
function applyLang() {
  document.getElementById('langLt').classList.toggle('active', lang === 'lt');
  document.getElementById('langEn').classList.toggle('active', lang === 'en');
  document.documentElement.lang = lang;
  document.title = (TITLES[lang] || TITLES.lt) + ' — ' + t('page_title');
  const st = document.getElementById('siteTitle');
  if (st) st.textContent = TITLES[lang] || TITLES.lt;
  document.querySelectorAll('[data-i18n]').forEach(el => el.textContent = t(el.dataset.i18n));
  document.querySelectorAll('[data-i18n-ph]').forEach(el => el.placeholder = t(el.dataset.i18nPh));
  renderAttribution();
}

// ─── HTML escape ──────────────────────────────────────────
function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  })[c]);
}

// ─── Attribution (encoded) ───────────────────────────────
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
              + `<span class="sep">·</span><a href="privacy.php" style="color:var(--accent);text-decoration:none">${lang === 'lt' ? 'Privatumas' : 'Privacy'}</a>`
              + `<span class="sep">·</span><a href="cookies.php" style="color:var(--accent);text-decoration:none">${lang === 'lt' ? 'Slapukai' : 'Cookies'}</a>`;
}
(function guard() {
  setInterval(() => {
    const f = document.getElementById('attFooter');
    const author = __dec(__a[0]) + __dec(__a[1]);
    if (!f || !f.textContent.includes(author)) {
      if (!f) {
        const nf = document.createElement('footer');
        nf.className = 'attribution-footer'; nf.id = 'attFooter';
        document.body.appendChild(nf);
      }
      renderAttribution();
    }
  }, 2000);
})();

// ─── Data ─────────────────────────────────────────────────
let sensors = [];

// Region (city_prefix) extraction: if the API returns city_prefix — use it;
// otherwise extract the alphabetic prefix from the label (e.g. "VLN12" → "VLN").
function regionOf(s) {
  if (s.city_prefix) return String(s.city_prefix).toUpperCase();
  const m = String(s.label || '').match(/^([A-Za-z]+)/);
  return m ? m[1].toUpperCase() : '—';
}

async function loadSensors() {
  document.getElementById('tableContainer').innerHTML =
    `<div class="loading">${t('loading')}</div>`;
  try {
    const res = await fetch('api/sensors.php?action=map_data', { credentials: 'same-origin' });
    const data = await res.json();
    sensors = (data.sensors || []).sort((a, b) =>
      a.label.localeCompare(b.label, undefined, { numeric: true }));
    renderTable();
  } catch (e) {
    document.getElementById('tableContainer').innerHTML =
      `<div class="empty">${t('error')}: ${escapeHtml(e.message)}</div>`;
  }
}

function renderTable() {
  const q = document.getElementById('search').value.trim().toLowerCase();
  const showOutdoor = document.getElementById('fOutdoor').checked;
  const showIndoor  = document.getElementById('fIndoor').checked;

  // Filtering: search + indoor/outdoor
  const filtered = sensors.filter(s => {
    if (!s.label.toLowerCase().includes(q)) return false;
    const outdoor = Number(s.is_outdoor) === 1;
    if (outdoor && !showOutdoor) return false;
    if (!outdoor && !showIndoor) return false;
    return true;
  });

  const box = document.getElementById('tableContainer');
  if (filtered.length === 0) {
    box.innerHTML = `<div class="empty">${t('no_sensors')}</div>`;
    return;
  }

  // Grouping by region
  const groups = {};
  for (const s of filtered) {
    const r = regionOf(s);
    (groups[r] ??= []).push(s);
  }
  const regionKeys = Object.keys(groups).sort();

  box.innerHTML = regionKeys.map(region => {
    const list = groups[region];
    const rows = list.map(s => renderRow(s, region)).join('');
    // The open state is preserved via the openRegions set
    const openCls = openRegions.has(region) ? ' open' : '';
    return `<div class="region${openCls}" data-region="${escapeHtml(region)}">
      <div class="region-head" onclick="toggleRegion('${escapeHtml(region)}')">
        <span class="region-caret">▶</span>
        <span class="region-name">${escapeHtml(region)}</span>
        <span class="region-meta">${t('region_sensors')(list.length)}</span>
        <div class="spacer"></div>
        <div class="region-actions" onclick="event.stopPropagation()">
          <button class="btn btn-warn btn-sm"
                  onclick="clearRegion('${escapeHtml(region)}', ${list.length})">
            ${t('region_clear')}
          </button>
          <button class="btn btn-danger btn-sm"
                  onclick="deleteRegion('${escapeHtml(region)}', ${list.length})">
            ${t('region_delete')}
          </button>
        </div>
      </div>
      <div class="bulk-bar" id="bulk-${escapeHtml(region)}">
        <span class="count" id="selcount-${escapeHtml(region)}">${t('selected_n')(0)}</span>
        <div class="spacer"></div>
        <button class="btn btn-warn btn-sm" onclick="bulkClear('${escapeHtml(region)}')">
          ${t('bulk_clear')}
        </button>
        <button class="btn btn-danger btn-sm" onclick="bulkDelete('${escapeHtml(region)}')">
          ${t('bulk_delete')}
        </button>
      </div>
      <div class="region-body">
        <table>
          <thead><tr>
            <th class="col-chk">
              <input type="checkbox" title="${t('select_all')}"
                     onchange="toggleAll('${escapeHtml(region)}', this.checked)">
            </th>
            <th>${t('col_label')}</th>
            <th>${t('col_status')}</th>
            <th>${t('col_coords')}</th>
            <th>${t('col_mac')}</th>
            <th>${t('col_location')}</th>
            <th>${t('col_readings')}</th>
            <th>${t('col_lastseen')}</th>
            <th style="text-align:right">${t('col_actions')}</th>
          </tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
    </div>`;
  }).join('');

  // Restore checkbox states after re-rendering
  refreshAllBulkBars();
}

// Single-row rendering
function renderRow(s, region) {
  const online = s.online == 1;
  const lastSeen = s.last_seen
    ? new Date(s.last_seen).toLocaleString(locale())
    : '—';
  const checked = selected.has(s.id) ? 'checked' : '';
  return `<tr class="${selected.has(s.id) ? 'selected' : ''}" data-id="${s.id}">
    <td class="col-chk">
      <input type="checkbox" ${checked}
             onchange="toggleOne(${s.id}, '${escapeHtml(region)}', this.checked)">
    </td>
    <td class="label-cell">${escapeHtml(s.label)}</td>
    <td><span class="dot ${online ? 'dot-on' : 'dot-off'}"></span>${online ? t('online') : t('offline')}</td>
    <td class="muted" style="font-family:var(--mono);font-size:.78rem">
      ${Number(s.lat).toFixed(5)}, ${Number(s.lng).toFixed(5)}</td>
    <td class="muted" style="font-family:var(--mono);font-size:.74rem">${escapeHtml(s.mac || '—')}</td>
    <td>${Number(s.is_outdoor) === 1 ? '🌳 ' + t('loc_outdoor') : '🏠 ' + t('loc_indoor')}</td>
    <td>${s.reading_count ?? 0}</td>
    <td class="muted">${lastSeen}</td>
    <td>
      <div class="row-actions">
        <button class="btn btn-sm${s.has_secret ? ' btn-hmac-on' : ''}"
                title="${s.has_secret ? t('hmac_on_title') : t('hmac_off_title')}"
                onclick="setSecret(${s.id}, '${escapeHtml(s.label)}', ${s.has_secret ? 'true' : 'false'})">
          ${t('hmac_btn')}${s.has_secret ? ' ✓' : ''}
        </button>
        <button class="btn btn-warn btn-sm"
                onclick="clearData(${s.id}, '${escapeHtml(s.label)}', ${s.reading_count ?? 0})">
          ${t('clear_data')}
        </button>
        <button class="btn btn-danger btn-sm"
                onclick="deleteSensor(${s.id}, '${escapeHtml(s.label)}')">
          ${t('delete_sensor')}
        </button>
      </div>
    </td>
  </tr>`;
}

// ─── Region open / selection ─────────────────────────────
const openRegions = new Set();   // which regions are open
const selected = new Set();       // IDs of selected sensors

function toggleRegion(region) {
  if (openRegions.has(region)) openRegions.delete(region);
  else openRegions.add(region);
  const el = document.querySelector(`.region[data-region="${cssEsc(region)}"]`);
  if (el) el.classList.toggle('open', openRegions.has(region));
}

function toggleOne(id, region, on) {
  if (on) selected.add(id); else selected.delete(id);
  const tr = document.querySelector(`tr[data-id="${id}"]`);
  if (tr) tr.classList.toggle('selected', on);
  refreshBulkBar(region);
}

function toggleAll(region, on) {
  const el = document.querySelector(`.region[data-region="${cssEsc(region)}"]`);
  if (!el) return;
  el.querySelectorAll('tbody input[type="checkbox"]').forEach(cb => {
    const id = Number(cb.closest('tr').dataset.id);
    cb.checked = on;
    if (on) selected.add(id); else selected.delete(id);
    cb.closest('tr').classList.toggle('selected', on);
  });
  refreshBulkBar(region);
}

// How many are selected in a specific region
function selectedInRegion(region) {
  const el = document.querySelector(`.region[data-region="${cssEsc(region)}"]`);
  if (!el) return [];
  return [...el.querySelectorAll('tbody tr')]
    .map(tr => Number(tr.dataset.id))
    .filter(id => selected.has(id));
}

function refreshBulkBar(region) {
  const el = document.querySelector(`.region[data-region="${cssEsc(region)}"]`);
  if (!el) return;
  const ids = selectedInRegion(region);
  el.classList.toggle('has-selection', ids.length > 0);
  const c = document.getElementById('selcount-' + region);
  if (c) c.textContent = t('selected_n')(ids.length);
}

function refreshAllBulkBars() {
  document.querySelectorAll('.region').forEach(el =>
    refreshBulkBar(el.dataset.region));
}

// Safe CSS selector escaping (region code is only [A-Z0-9], but for safety)
function cssEsc(s) {
  return String(s).replace(/["\\\]]/g, '\\$&');
}

// ─── Password prompt (masked modal) ──────────────────────
let _passResolve = null;
function askPass() {
  // Returns a Promise that resolves with the password or null (on cancel).
  return new Promise(resolve => {
    _passResolve = resolve;
    const inp = document.getElementById('passModalInput');
    inp.value = '';
    document.getElementById('passModal').classList.add('show');
    setTimeout(() => inp.focus(), 50);
  });
}
function passModalOk() {
  const v = document.getElementById('passModalInput').value;
  closePassModal();
  if (_passResolve) { _passResolve(v || null); _passResolve = null; }
}
function passModalCancel() {
  closePassModal();
  if (_passResolve) { _passResolve(null); _passResolve = null; }
}
function closePassModal() {
  document.getElementById('passModal').classList.remove('show');
  document.getElementById('passModalInput').value = ''; // neliks atmintyje DOM'e
}

async function postAction(body) {
  const res = await fetch('api/sensors.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body,
  });
  return res.json();
}

async function clearData(id, label, count) {
  if (!confirm(I18N[lang].confirm_clear(label, count))) return;
  const pass = await askPass();
  if (!pass) return;
  try {
    const data = await postAction(
      `action=clear_readings&id=${encodeURIComponent(id)}&pass=${encodeURIComponent(pass)}`);
    if (data.ok) { toast(I18N[lang].cleared(data.deleted_readings), 'ok'); loadSensors(); }
    else toast(data.error || t('error'), 'err');
  } catch (e) { toast(`${t('error')}: ${e.message}`, 'err'); }
}

async function deleteSensor(id, label) {
  if (!confirm(I18N[lang].confirm_delete(label))) return;
  const pass = await askPass();
  if (!pass) return;
  try {
    const data = await postAction(
      `action=delete&id=${encodeURIComponent(id)}&pass=${encodeURIComponent(pass)}`);
    if (data.ok) { toast(I18N[lang].deleted(label), 'ok'); loadSensors(); }
    else toast(data.error || t('error'), 'err');
  } catch (e) { toast(`${t('error')}: ${e.message}`, 'err'); }
}

// Set / clear a sensor's HMAC shared-secret. The same value must be
// configured in the sensor firmware so signed readings can be verified.
async function setSecret(id, label, hasSecret) {
  const secret = prompt(I18N[lang].hmac_prompt(label), '');
  if (secret === null) return; // cancelled
  const pass = await askPass();
  if (!pass) return;
  try {
    const data = await postAction(
      `action=set_secret&id=${encodeURIComponent(id)}&secret=${encodeURIComponent(secret.trim())}&pass=${encodeURIComponent(pass)}`);
    if (data.ok) { toast(data.hmac ? t('hmac_set_ok') : t('hmac_cleared'), 'ok'); loadSensors(); }
    else toast(data.error || t('error'), 'err');
  } catch (e) { toast(`${t('error')}: ${e.message}`, 'err'); }
}

// ─── Region actions ───────────────────────────────────────
async function clearRegion(region, count) {
  if (!confirm(I18N[lang].confirm_region_clear(region, count))) return;
  const pass = await askPass();
  if (!pass) return;
  try {
    const data = await postAction(
      `action=clear_region&region=${encodeURIComponent(region)}&pass=${encodeURIComponent(pass)}`);
    if (data.ok) { toast(I18N[lang].region_cleared(data.deleted_readings), 'ok'); loadSensors(); }
    else toast(data.error || t('error'), 'err');
  } catch (e) { toast(`${t('error')}: ${e.message}`, 'err'); }
}

async function deleteRegion(region, count) {
  if (!confirm(I18N[lang].confirm_region_delete(region, count))) return;
  const pass = await askPass();
  if (!pass) return;
  try {
    const data = await postAction(
      `action=delete_region&region=${encodeURIComponent(region)}&pass=${encodeURIComponent(pass)}`);
    if (data.ok) { toast(I18N[lang].region_deleted(region, data.deleted_sensors), 'ok'); loadSensors(); }
    else toast(data.error || t('error'), 'err');
  } catch (e) { toast(`${t('error')}: ${e.message}`, 'err'); }
}

// ─── Bulk (selected) actions ─────────────────────────────
async function bulkClear(region) {
  const ids = selectedInRegion(region);
  if (ids.length === 0) { toast(t('nothing_selected'), 'err'); return; }
  if (!confirm(I18N[lang].confirm_bulk_clear(ids.length))) return;
  const pass = await askPass();
  if (!pass) return;
  try {
    const data = await postAction(
      `action=clear_readings_bulk&ids=${encodeURIComponent(ids.join(','))}&pass=${encodeURIComponent(pass)}`);
    if (data.ok) {
      toast(I18N[lang].bulk_cleared(data.sensors_affected, data.deleted_readings), 'ok');
      selected.clear(); loadSensors();
    } else toast(data.error || t('error'), 'err');
  } catch (e) { toast(`${t('error')}: ${e.message}`, 'err'); }
}

async function bulkDelete(region) {
  const ids = selectedInRegion(region);
  if (ids.length === 0) { toast(t('nothing_selected'), 'err'); return; }
  if (!confirm(I18N[lang].confirm_bulk_delete(ids.length))) return;
  const pass = await askPass();
  if (!pass) return;
  try {
    const data = await postAction(
      `action=delete_bulk&ids=${encodeURIComponent(ids.join(','))}&pass=${encodeURIComponent(pass)}`);
    if (data.ok) {
      toast(I18N[lang].bulk_deleted(data.deleted_sensors), 'ok');
      selected.clear(); loadSensors();
    } else toast(data.error || t('error'), 'err');
  } catch (e) { toast(`${t('error')}: ${e.message}`, 'err'); }
}

function toast(msg, type = 'ok') {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.className = `toast show ${type}`;
  setTimeout(() => { el.className = 'toast'; }, 3200);
}

// Init
applyLang();
loadSensors();
</script>
</body>
</html>
