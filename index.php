<?php require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/cities.php';
require_once __DIR__ . '/includes/auth.php';

// First run: if the config is not yet filled (no DB details),
// redirect to the administration (setup) page includes/admin.php.
// This way the user need not know the admin URL — index.php leads there itself.
if (!defined('DB_NAME') || trim((string)DB_NAME) === ''
    || !defined('DB_USER') || trim((string)DB_USER) === '') {
    header('Location: ' . adminFilePath());
    exit;
}

if (function_exists('setSecurityHeaders')) setSecurityHeaders();
$titleLt = defined('SITE_TITLE_LT') ? SITE_TITLE_LT : 'IoT Jutiklių Žemėlapis';
$titleEn = defined('SITE_TITLE_EN') ? SITE_TITLE_EN : 'IoT Sensor Map';

// Region (city_prefix) → city name map for the filter.
// The main city (CITY_PREFIX) is mapped to the selected country's capital.
// Both names (LT/EN) are kept so the filter shows them per the page language.
$regionCityMap = [];
if (function_exists('capitalCoords') && defined('MAP_COUNTRY')) {
    $cap = capitalCoords(MAP_COUNTRY);
    if (defined('CITY_PREFIX') && !empty($cap['city'])) {
        $cityEn = $cap['city'];
        $cityLt = function_exists('capitalNameLt') ? capitalNameLt($cityEn) : $cityEn;
        $regionCityMap[CITY_PREFIX] = ['lt' => $cityLt, 'en' => $cityEn];
    }
}
?>
<!DOCTYPE html>
<html lang="lt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($titleLt, ENT_QUOTES) ?></title>
<?php
  // ── SEO / social visibility ──────────────────────────────────────────────
  // Base URL is derived from the request, so canonical & social tags are correct
  // on any deployment (root or sub-path) without hardcoding the domain.
  $scheme  = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443)) ? 'https' : 'http';
  $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $dir     = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
  $baseUrl = $scheme . '://' . $host . $dir;
  $pageUrl = $baseUrl . '/index.php';
  $ogImage = $baseUrl . '/assets/favicon.svg';
  $seoDesc = $titleLt . ' — realaus laiko aplinkos jutiklių (temperatūros, drėgmės, oro kokybės) žemėlapis. IoT stebėsenos sistema su interaktyviu žemėlapiu ir matavimų istorija.';
?>
<meta name="description" content="<?= htmlspecialchars($seoDesc, ENT_QUOTES) ?>">
<meta name="author" content="Aleksandr Igumenov">
<meta name="robots" content="index, follow">
<link rel="canonical" href="<?= htmlspecialchars($pageUrl, ENT_QUOTES) ?>">

<!-- Open Graph (Facebook, LinkedIn, messaging previews) -->
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= htmlspecialchars($titleLt, ENT_QUOTES) ?>">
<meta property="og:title" content="<?= htmlspecialchars($titleLt, ENT_QUOTES) ?>">
<meta property="og:description" content="<?= htmlspecialchars($seoDesc, ENT_QUOTES) ?>">
<meta property="og:url" content="<?= htmlspecialchars($pageUrl, ENT_QUOTES) ?>">
<meta property="og:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES) ?>">
<meta property="og:locale" content="lt_LT">
<meta property="og:locale:alternate" content="en_US">

<!-- Twitter / X card -->
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="<?= htmlspecialchars($titleLt, ENT_QUOTES) ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($seoDesc, ENT_QUOTES) ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES) ?>">

<!-- Structured data (schema.org WebApplication) for richer search results -->
<script type="application/ld+json">
<?= json_encode([
  '@context'            => 'https://schema.org',
  '@type'               => 'WebApplication',
  'name'                => $titleLt,
  'alternateName'       => $titleEn,
  'url'                 => $pageUrl,
  'applicationCategory' => 'EnvironmentalApplication',
  'operatingSystem'     => 'Web',
  'inLanguage'          => ['lt', 'en'],
  'description'         => $seoDesc,
  'author'              => ['@type' => 'Person', 'name' => 'Aleksandr Igumenov'],
  'offers'              => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>

<!-- Favicon -->
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">

<?php
  // Effective provider — an explicit choice (MAP_TILE_PROVIDER).
  // 'google' → Google Maps; any other → OpenStreetMap/Leaflet.
  $mapProv = effectiveMapProvider();
  $useGoogle = ($mapProv === 'google');
?>
<?php if ($useGoogle): ?>
<!-- Google Maps (used because the selected provider = Google) -->
<script src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars(GMAPS_API_KEY, ENT_QUOTES) ?>&callback=initMap" async defer></script>
<?php else: ?>
<?php
  // Preconnect to the active tile origin(s) so the first map tiles start loading
  // sooner (PageSpeed: ~80 ms LCP improvement per origin). Only the selected
  // provider's hosts are hinted, to stay within the recommended preconnect budget.
  $tileProv = defined('MAP_TILE_PROVIDER') ? MAP_TILE_PROVIDER : 'opentopomap';
  $preconnectMap = [
    'opentopomap'   => ['https://a.tile.opentopomap.org', 'https://b.tile.opentopomap.org', 'https://c.tile.opentopomap.org'],
    'osm'           => ['https://tile.openstreetmap.org'],
    'carto_voyager' => ['https://a.basemaps.cartocdn.com', 'https://b.basemaps.cartocdn.com', 'https://c.basemaps.cartocdn.com'],
    'carto_light'   => ['https://a.basemaps.cartocdn.com', 'https://b.basemaps.cartocdn.com', 'https://c.basemaps.cartocdn.com'],
    'yandex'        => ['https://core-renderer-tiles.maps.yandex.net'],
  ];
  foreach (($preconnectMap[$tileProv] ?? []) as $o) {
    echo '<link rel="preconnect" href="' . htmlspecialchars($o, ENT_QUOTES) . '" crossorigin>' . "\n";
  }
?>
<!-- OpenStreetMap via Leaflet (a non-Google provider was selected).
     OSM uses the same WGS84 (lat/lng) coordinate system as Google Maps,
     so all existing sensor/coordinate logic works unchanged.
     Leaflet is hosted LOCALLY (assets/leaflet/) — there is no CDN dependency,
     so it works reliably on shared hosting without external library requests. -->
<link rel="stylesheet" href="assets/leaflet/leaflet.css">
<!-- Loaded SYNCHRONOUSLY (not deferred): the OSM→Leaflet compatibility shim in
     the inline script below captures `window.L` at parse time, so Leaflet must be
     available before that script runs. -->
<script src="assets/leaflet/leaflet.js"></script>
<?php endif; ?>

<!-- Chart.js is loaded lazily (only when a sensor's history chart is opened),
     so it is not in the initial critical path — see loadChartJs() / drawChart(). -->

<style>
/* ─── Tokens ─────────────────────────────────────────────── */
:root {
  --bg:        #0a0f1e;
  --surface:   #111827;
  --surface2:  #1a2235;
  --border:    #1e2d45;
  --accent:    #00c8ff;
  --accent2:   #7b61ff;
  --ok:        #22d3a0;
  --warn:      #f59e0b;
  --danger:    #ef4444;
  --text:      #e2e8f0;
  --muted:     #64748b;
  --mono:      'JetBrains Mono', 'Fira Code', monospace;
  --sans:      'Inter', system-ui, sans-serif;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: var(--sans);
  background: var(--bg);
  color: var(--text);
  height: 100vh;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

/* ─── Header ─────────────────────────────────────────────── */
header {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: .75rem 1.5rem;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  z-index: 10;
  flex-shrink: 0;
}

.logo {
  display: flex;
  align-items: center;
  gap: .5rem;
  font-size: .95rem;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: var(--accent);
}
.logo svg { width: 22px; height: 22px; }

.stats-bar {
  display: flex;
  gap: 1.5rem;
  margin-left: auto;
  font-size: .78rem;
  font-family: var(--mono);
}
.stat { color: var(--muted); }
.stat span { color: var(--text); font-weight: 600; }

.btn {
  padding: .45rem 1rem;
  border-radius: 6px;
  border: 1px solid var(--border);
  background: var(--surface2);
  color: var(--text);
  font-size: .82rem;
  cursor: pointer;
  transition: background .15s, border-color .15s;
}
.btn:hover { background: var(--border); }
.btn-accent {
  background: var(--accent);
  color: var(--bg);
  border-color: var(--accent);
  font-weight: 700;
}
.btn-accent:hover { background: #00a8d8; }

/* ─── Map controls (style toggle + marker size) ──────────── */
.map-controls {
  display: flex;
  align-items: center;
  gap: .75rem;
}
/* ─── Map area with the right-hand filter panel ─── */
.map-area {
  flex: 1;
  display: flex;
  min-height: 0;
  position: relative;
}
#map { flex: 1; height: 100%; min-width: 0; }

/* Leaflet (OSM) protection from the global `* { ... }` reset and site styles.
   Without these rules the tiles may be sized incorrectly or not show. */
.leaflet-container {
  background: #aad3df; font-family: var(--sans);
  overflow: hidden;        /* layers do not spill past #map bounds (do not overlap the sidebar) */
  position: relative;
  z-index: 0;              /* lieka po sidebar/header */
}
.leaflet-container img { max-width: none !important; max-height: none !important; }
.leaflet-tile { width: 256px !important; height: 256px !important; }
.leaflet-pane img,
.leaflet-marker-icon,
.leaflet-marker-shadow { max-width: none !important; }
/* #map must clip content so Leaflet layers do not overlap adjacent elements */
#map { overflow: hidden; position: relative; z-index: 0; }
.filter-sidebar { position: relative; z-index: 1; }   /* sidebar above the map */
header { position: relative; z-index: 2; }             /* header above everything */

.filter-sidebar {
  flex-shrink: 0;
  width: 210px;
  background: var(--surface);
  border-left: 1px solid var(--border);
  padding: 1rem .85rem;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
}
.filter-sidebar-title {
  font-size: .72rem;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: var(--muted);
  margin-bottom: .75rem;
  font-weight: 600;
}
.filter-opt {
  display: flex; align-items: center; gap: .55rem;
  padding: .4rem .3rem; font-size: .85rem; cursor: pointer;
  border-radius: 6px; transition: background .12s;
}
.filter-opt:hover { background: var(--surface2); }
.filter-opt input { accent-color: var(--accent); cursor: pointer; width: 16px; height: 16px; }
.filter-opt .unit { color: var(--muted); font-size: .72rem; margin-left: auto; font-family: var(--mono); }
.filter-actions { display: flex; gap: .5rem; margin-top: .85rem; padding-top: .75rem; border-top: 1px solid var(--border); }
.loc-filter { display: flex; gap: .3rem; margin-top: .4rem; }
.loc-btn { flex: 1; padding: .4rem .2rem; background: var(--surface2); border: 1px solid var(--border); border-radius: 6px; color: var(--muted); font-size: .76rem; cursor: pointer; transition: .15s; }
.loc-btn:hover { border-color: var(--muted); color: var(--text); }
.loc-btn.active { background: rgba(0,200,255,.12); border-color: var(--accent); color: var(--accent); font-weight: 600; }
.region-select {
  width: 100%; margin-top: .4rem; background: var(--surface2);
  border: 1px solid var(--border); border-radius: 6px; color: var(--text);
  padding: .4rem .5rem; font-size: .78rem; font-family: var(--sans); cursor: pointer;
}
.region-select:focus { outline: none; border-color: var(--accent); }
.btn-sm { padding: .3rem .65rem; font-size: .74rem; flex: 1; }

/* ─── Vidurkiai po filtru ─── */
.filter-averages { margin-top: .85rem; padding-top: .75rem; border-top: 1px solid var(--border); }
.avg-period-row { margin-top: .85rem; display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
.avg-period-label { font-size: .76rem; color: var(--muted); }
.avg-period-row .region-select { flex: 1; min-width: 9rem; }
.avg-meta { margin-top: .5rem; font-size: .7rem; color: var(--muted); opacity: .85; }
.avg-empty { font-size: .78rem; color: var(--muted); padding: .3rem 0; }
.avg-title { font-size: .68rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); margin-bottom: .6rem; font-weight: 600; }
.avg-row { display: flex; align-items: baseline; justify-content: space-between; padding: .3rem 0; font-size: .8rem; }
.avg-name { color: var(--muted); }
.avg-val { font-weight: 600; color: var(--text); font-family: var(--mono); }
.avg-val .avg-unit { color: var(--muted); font-size: .68rem; font-weight: 400; margin-left: .15rem; }
.avg-count { color: var(--muted); font-size: .62rem; margin-left: .35rem; }

/* ─── Phone / narrow screen: measurement units only ─── */
@media (max-width: 640px) {
  /* ── Header fits a phone: wrap to rows, shrink, collapse labels ── */
  header { flex-wrap: wrap; gap: .4rem .5rem; padding: .55rem .7rem; }
  .logo { font-size: .78rem; }
  .logo span { max-width: 8.5rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .logo svg { width: 18px; height: 18px; }
  .stats-bar { order: 5; width: 100%; margin-left: 0; gap: 1rem; font-size: .66rem;
               justify-content: space-between; border-top: 1px solid var(--border); padding-top: .4rem; }
  .map-controls { gap: .4rem; }
  .size-control { display: none; }            /* marker-size slider hidden on phones */
  header .btn { padding: .38rem .5rem; font-size: .72rem; }
  /* keep only the leading symbol (↺ / +) on the action buttons */
  header > .btn > span[data-i18n="refresh"],
  header > .btn > span[data-i18n="add_sensor"] { display: none; }
  .filter-sidebar { width: 56px; padding: .75rem .35rem; align-items: stretch; }
  .filter-sidebar-title { display: none; }
  .filter-opt { flex-direction: column; gap: .2rem; padding: .45rem .1rem; align-items: center; text-align: center; }
  .filter-opt .metric-name { display: none; }       /* hide the name */
  .filter-opt .unit { margin-left: 0; font-size: .64rem; text-align: center; line-height: 1.1; }
  .filter-actions { flex-direction: column; gap: .35rem; }
  .region-select { font-size: .6rem; padding: .35rem .15rem; }
  .btn-sm { font-size: .58rem; padding: .3rem .15rem; }
  /* Averages on mobile: value + unit only, stacked */
  .avg-title { display: none; }
  .avg-row { flex-direction: column; align-items: center; text-align: center; gap: .05rem; padding: .35rem 0; }
  .avg-name { display: none; }
  .avg-val { font-size: .68rem; }
  .avg-val .avg-unit { display: block; margin-left: 0; }
  .avg-count { display: none; }
}
.btn-icon {
  padding: .4rem .65rem;
  font-size: 1rem;
  line-height: 1;
}
.size-control {
  display: flex;
  align-items: center;
  gap: .4rem;
  padding: .3rem .7rem;
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 6px;
}
.size-icon { color: var(--muted); font-size: .65rem; line-height: 1; }
.size-control input[type="range"] {
  width: 90px;
  height: 4px;
  -webkit-appearance: none;
  appearance: none;
  background: var(--border);
  border-radius: 2px;
  outline: none;
  cursor: pointer;
}
.size-control input[type="range"]::-webkit-slider-thumb {
  -webkit-appearance: none;
  appearance: none;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: var(--accent);
  cursor: pointer;
}
.size-control input[type="range"]::-moz-range-thumb {
  width: 14px;
  height: 14px;
  border: none;
  border-radius: 50%;
  background: var(--accent);
  cursor: pointer;
}

/* ─── Attribution footer (required by the license) ─────── */
.attribution-footer {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: .5rem;
  padding: .4rem 1rem;
  background: var(--surface);
  border-top: 1px solid var(--border);
  font-size: .68rem;
  color: var(--muted);
  text-align: center;
}
.attribution-footer strong { color: var(--text); font-weight: 600; }
.attribution-footer .sep { opacity: .5; }

/* ─── Cookie consent ─── */
.cookie-consent {
  position: fixed; bottom: 0; left: 0; right: 0; z-index: 4000;
  display: none; align-items: center; gap: 1rem;
  padding: 1rem 1.5rem; background: var(--surface);
  border-top: 1px solid var(--border);
  box-shadow: 0 -8px 30px rgba(0,0,0,.4);
}
.cookie-consent.show { display: flex; }
.cookie-text { flex: 1; font-size: .82rem; color: var(--text); line-height: 1.55; }
.cookie-text a { color: var(--accent); text-decoration: none; }
.cookie-actions { display: flex; gap: .6rem; flex-shrink: 0; }
.cookie-accept { background: var(--accent); color: var(--bg); border-color: var(--accent); font-weight: 600; }
.cookie-accept:hover { filter: brightness(1.1); }
.cookie-decline { background: var(--surface2); }
@media (max-width: 640px) {
  .cookie-consent { flex-direction: column; align-items: stretch; padding: 1rem; gap: .75rem; }
  .cookie-actions { justify-content: stretch; }
  .cookie-actions .btn { flex: 1; }
}
@media (max-width: 600px) {
  .attribution-footer { font-size: .6rem; padding: .35rem .5rem; line-height: 1.3; }
}

/* ─── Popup overlay ──────────────────────────────────────── */
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.55);
  z-index: 200;
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 0;
  pointer-events: none;
  transition: opacity .2s;
}
.overlay.open { opacity: 1; pointer-events: all; }

.popup {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 14px;
  width: min(680px, 96vw);
  max-height: 88vh;
  overflow-y: auto;
  padding: 0;
  box-shadow: 0 24px 64px rgba(0,0,0,.6);
  transform: translateY(16px);
  transition: transform .25s;
}
.overlay.open .popup { transform: translateY(0); }

.popup-header {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.25rem 1.5rem 1rem;
  border-bottom: 1px solid var(--border);
  position: sticky;
  top: 0;
  background: var(--surface);
  z-index: 2;
}
/* ─── Group selection (several sensors at one location) ─── */
.group-selector { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .5rem; }
.group-selector:empty { margin-top: 0; }
.group-tab {
  display: inline-flex; align-items: center;
  background: var(--surface2); border: 1px solid var(--border);
  color: var(--muted); border-radius: 6px; padding: .25rem .6rem;
  font-size: .76rem; font-family: var(--mono); cursor: pointer; transition: .12s;
}
.group-tab:hover { border-color: var(--muted); color: var(--text); }
.group-tab.active { background: var(--accent); color: var(--bg); border-color: var(--accent); font-weight: 600; }
.popup-header h2 {
  font-family: var(--mono);
  font-size: 1.2rem;
  color: var(--accent);
  letter-spacing: .06em;
}
.popup-header p { font-size: .78rem; color: var(--muted); margin-top: .15rem; }
.popup-close {
  margin-left: auto;
  background: none;
  border: none;
  color: var(--muted);
  font-size: 1.4rem;
  cursor: pointer;
  line-height: 1;
  padding: .2rem .4rem;
}
.popup-close:hover { color: var(--text); }

.popup-body { padding: 1.25rem 1.5rem; }

/* ─── Metric grid ────────────────────────────────────────── */
.metrics-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: .75rem;
  margin-bottom: 1.5rem;
}
.metric-card {
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: .9rem 1rem;
}
.metric-label {
  font-size: .68rem;
  text-transform: uppercase;
  letter-spacing: .1em;
  color: var(--muted);
  margin-bottom: .35rem;
}
.metric-value {
  font-family: var(--mono);
  font-size: 1.35rem;
  font-weight: 700;
  color: var(--text);
}
.metric-value.na { color: var(--border); font-size: 1.1rem; }
.metric-unit {
  font-size: .7rem;
  color: var(--muted);
  margin-top: .1rem;
}
.metric-card.temp   { border-top: 3px solid #f97316; }
.metric-card.hum    { border-top: 3px solid #3b82f6; }
.metric-card.co2    { border-top: 3px solid #a855f7; }
.metric-card.pm     { border-top: 3px solid #22d3a0; }
.metric-card.rad    { border-top: 3px solid #ef4444; }
.metric-card.grain  { border-top: 3px solid #f59e0b; }

/* ─── Chart section ──────────────────────────────────────── */
.chart-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .5rem;
  margin-bottom: .75rem;
  flex-wrap: wrap;
}
.chart-section h3 {
  font-size: .78rem;
  text-transform: uppercase;
  letter-spacing: .1em;
  color: var(--muted);
  margin: 0;
}
.chart-period {
  width: auto;
  min-width: 8rem;
  font-size: .76rem;
  padding: .3rem .5rem;
}
.chart-tabs {
  display: flex;
  gap: .4rem;
  margin-bottom: 1rem;
  flex-wrap: wrap;
}
.chart-tab {
  padding: .3rem .75rem;
  border-radius: 20px;
  border: 1px solid var(--border);
  background: var(--surface2);
  font-size: .75rem;
  font-family: var(--mono);
  color: var(--muted);
  cursor: pointer;
  transition: all .15s;
}
.chart-tab.active {
  background: var(--accent);
  color: var(--bg);
  border-color: var(--accent);
  font-weight: 700;
}
.chart-tab:disabled { opacity: .3; cursor: default; }
.chart-wrap { position: relative; height: 200px; }

/* ─── Info footer inside popup ───────────────────────────── */
.popup-meta {
  display: flex;
  justify-content: space-between;
  font-size: .72rem;
  font-family: var(--mono);
  color: var(--muted);
  padding-top: 1rem;
  margin-top: 1rem;
  border-top: 1px solid var(--border);
}

/* ─── Register panel ─────────────────────────────────────── */
.reg-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.6);
  z-index: 300;
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 0;
  pointer-events: none;
  transition: opacity .2s;
}
.reg-overlay.open { opacity: 1; pointer-events: all; }
.loc-type-toggle { display: flex; gap: .6rem; margin-top: .4rem; }
.loc-type-opt { flex: 1; display: flex; align-items: center; justify-content: center; gap: .4rem; padding: .6rem; background: var(--surface2); border: 1px solid var(--border); border-radius: 8px; cursor: pointer; font-size: .85rem; transition: .15s; }
.loc-type-opt:has(input:checked) { background: rgba(0,200,255,.12); border-color: var(--accent); color: var(--accent); font-weight: 600; }
.loc-type-opt input { accent-color: var(--accent); }
.reg-panel {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 14px;
  width: min(520px, 96vw);
  padding: 1.75rem;
  box-shadow: 0 24px 64px rgba(0,0,0,.6);
}
.reg-panel h2 { font-size: 1.05rem; font-weight: 700; margin-bottom: .5rem; }
.reg-panel p  { font-size: .82rem; color: var(--muted); margin-bottom: 1.25rem; line-height: 1.6; }

.form-row { display: flex; gap: .75rem; margin-bottom: .75rem; }
.form-field { flex: 1; display: flex; flex-direction: column; gap: .35rem; }
.form-field label { font-size: .72rem; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); }
.form-field input {
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 6px;
  color: var(--text);
  padding: .55rem .75rem;
  font-family: var(--mono);
  font-size: .9rem;
  outline: none;
  transition: border-color .15s;
}
.form-field input:focus { border-color: var(--accent); }

.code-block {
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 1rem 1.1rem;
  font-family: var(--mono);
  font-size: .75rem;
  color: var(--ok);
  line-height: 1.7;
  overflow-x: auto;
  margin: .75rem 0;
  white-space: pre;
}
.form-actions { display: flex; gap: .75rem; justify-content: flex-end; margin-top: 1rem; }

/* ─── Marker pulse (online indicator) ───────────────────── */
.pulse-ring {
  border: 3px solid var(--accent);
  border-radius: 50%;
  animation: pulse 2s ease-out infinite;
}
@keyframes pulse {
  0%   { transform: scale(1);   opacity: .9; }
  70%  { transform: scale(2.2); opacity: 0; }
  100% { transform: scale(2.2); opacity: 0; }
}

/* ─── Toast ──────────────────────────────────────────────── */
#toast {
  position: fixed;
  bottom: 2rem;
  left: 50%;
  transform: translateX(-50%) translateY(80px);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: .65rem 1.25rem;
  font-size: .82rem;
  color: var(--text);
  z-index: 999;
  transition: transform .25s;
  white-space: nowrap;
}
#toast.show { transform: translateX(-50%) translateY(0); }
#toast.ok   { border-color: var(--ok); }
#toast.err  { border-color: var(--danger); }

/* ─── Scrollbar ──────────────────────────────────────────── */
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
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
  <div class="stats-bar">
    <div class="stat"><span data-i18n="sensors_label">Jutikliai</span>: <span id="sensorCount">—</span></div>
    <div class="stat"><span data-i18n="updated_label">Atnaujinta</span>: <span id="lastUpdate">—</span> <span id="liveDot" title="" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--muted);margin-left:4px;vertical-align:middle"></span></div>
  </div>
  <div class="map-controls">
    <button class="btn btn-icon" id="styleToggle" onclick="toggleMapStyle()" title="Keisti žemėlapio stilių">🌙</button>
    <div class="size-control" title="Jutiklių dydis žemėlapyje">
      <span class="size-icon">●</span>
      <input type="range" id="markerSize" min="6" max="20" value="10" oninput="setMarkerSize(this.value)" aria-label="Jutiklių dydis žemėlapyje" data-i18n-aria="aria_marker_size">
      <span class="size-icon" style="font-size:1.1rem">●</span>
    </div>
  </div>
  <button class="btn btn-icon" id="langToggle" onclick="toggleLang()" title="Language">EN</button>
  <button class="btn" onclick="loadMap()">↺ <span data-i18n="refresh">Atnaujinti</span></button>
  <button class="btn btn-accent" onclick="openRegister()">+ <span data-i18n="add_sensor">Pridėti jutiklį</span></button>
</header>

<div class="map-area">
  <div id="map"></div>
  <aside class="filter-sidebar" id="filterSidebar">
    <div class="filter-sidebar-title" data-i18n="loc_filter_title">Vieta</div>
    <div class="loc-filter" id="locFilter">
      <button class="loc-btn active" data-loc="all" onclick="setLocFilter('all')" data-i18n="loc_all">Visi</button>
      <button class="loc-btn" data-loc="indoor" onclick="setLocFilter('indoor')" data-i18n="loc_indoor_short">🏠</button>
      <button class="loc-btn" data-loc="outdoor" onclick="setLocFilter('outdoor')" data-i18n="loc_outdoor_short">🌳</button>
    </div>
    <div class="filter-sidebar-title" data-i18n="region_filter_title" style="margin-top:1rem">Regionas / miestas</div>
    <select id="regionFilter" class="region-select" onchange="setRegionFilter(this.value)" aria-label="Regionas arba miestas" data-i18n-aria="aria_region_filter">
      <option value="all" data-i18n="region_all">Visi regionai</option>
    </select>
    <label class="filter-opt viewport-opt" style="margin-top:.5rem" title="Rodyti tik žemėlapio lange matomus jutiklius">
      <input type="checkbox" id="viewportToggle" onchange="setViewportOnly(this.checked)">
      <span class="vp-icon" aria-hidden="true">👁</span>
      <span class="metric-name" data-i18n="viewport_label">Tik matomi lange</span>
    </label>
    <div class="filter-sidebar-title" data-i18n="filter_title" style="margin-top:1rem">Rodmenys</div>
    <div id="filterOptions"></div>
    <div class="filter-actions">
      <button class="btn btn-sm" onclick="setAllFilters(true)" data-i18n="filter_all">Visi</button>
      <button class="btn btn-sm" onclick="setAllFilters(false)" data-i18n="filter_none">Nė vieno</button>
    </div>
    <div class="avg-period-row">
      <label class="avg-period-label" for="avgPeriod" data-i18n="avg_period_label">Laikotarpis</label>
      <select id="avgPeriod" class="region-select" onchange="setAvgPeriod(this.value)" aria-label="Vidurkių laikotarpis" data-i18n-aria="aria_avg_period"></select>
    </div>
    <div class="filter-averages" id="filterAverages"></div>
  </aside>
</div>

<!-- ─── Sensor popup ──────────────────────────────────────── -->
<div class="overlay" id="sensorOverlay" onclick="closeSensor(event)">
  <div class="popup" id="sensorPopup">
    <div class="popup-header">
      <div style="flex:1">
        <h2 id="popLabel">—</h2>
        <div class="group-selector" id="groupSelector"></div>
      </div>
      <button class="popup-close" onclick="closeSensor()">✕</button>
    </div>
    <div class="popup-body">
      <div class="metrics-grid" id="metricsGrid"></div>
      <div class="chart-section">
        <div class="chart-head">
          <h3 data-i18n="history_title">Istorija</h3>
          <select id="chartPeriod" class="region-select chart-period" onchange="setChartPeriod(this.value)"
                  aria-label="Istorijos laikotarpis" data-i18n-aria="aria_chart_period">
            <option value="1d"   data-i18n="per_1d">1 diena</option>
            <option value="1w"   data-i18n="per_1w">1 savaitė</option>
            <option value="2w"   data-i18n="per_2w">2 savaitės</option>
            <option value="1mo"  data-i18n="per_1mo">1 mėnuo</option>
            <option value="3mo"  data-i18n="per_3mo">3 mėnesiai</option>
            <option value="6mo"  data-i18n="per_6mo">6 mėnesiai</option>
            <option value="12mo" data-i18n="per_12mo">12 mėnesių</option>
          </select>
        </div>
        <div class="chart-tabs" id="chartTabs"></div>
        <div class="chart-wrap"><canvas id="historyChart"></canvas></div>
      </div>
      <div class="popup-meta">
        <span id="popReadings">—</span>
        <span id="popLastSeen">—</span>
      </div>
    </div>
  </div>
</div>

<!-- ─── Register panel ────────────────────────────────────── -->
<div class="reg-overlay" id="regOverlay" onclick="closeRegister(event)">
  <div class="reg-panel">
    <h2 data-i18n="reg_title">Registruoti naują jutiklį</h2>
    <p data-i18n-html="reg_desc">
      Jutiklis identifikuojamas pagal jo <strong>GPS koordinates</strong> (7 dešimtainių ženklų tikslumu).<br>
      Pavadinimas suteikiamas automatiškai pagal <strong>artimiausią miestą</strong> — pvz. Vilniuje → VLN7, Kaune → KNS3.<br>
      Po registracijos jutiklis turi per <strong>3 minutes</strong> išsiųsti pirmą matavimą, kitaip įrašas automatiškai ištrinamas.
    </p>

    <div class="form-row">
      <div class="form-field">
        <label data-i18n="lat_label">Platuma (lat) — 7 sk.</label>
        <input type="number" id="regLat" placeholder="54.6872000" step="0.0000001" min="-90" max="90">
      </div>
      <div class="form-field">
        <label data-i18n="lng_label">Ilguma (lng) — 7 sk.</label>
        <input type="number" id="regLng" placeholder="25.2797000" step="0.0000001" min="-180" max="180">
      </div>
    </div>

    <div class="form-field" style="margin-bottom:1rem">
      <label data-i18n="location_type_label">Jutiklio vieta</label>
      <div class="loc-type-toggle">
        <label class="loc-type-opt">
          <input type="radio" name="regLocType" value="0" checked>
          <span data-i18n="loc_indoor">🏠 Patalpos</span>
        </label>
        <label class="loc-type-opt">
          <input type="radio" name="regLocType" value="1">
          <span data-i18n="loc_outdoor">🌳 Lauko</span>
        </label>
      </div>
    </div>

    <p style="margin-bottom:.4rem; font-size:.8rem; color:var(--muted)" data-i18n="api_hint">
      Jutiklis duomenis siunčia GET užklausa — įprogramuokite šį URL į firmware:
    </p>
    <div class="code-block" id="apiExample">GET /api/sensors.php?action=reading
  &lat=54.6872000
  &lng=25.2797000
  &mac=AA:BB:CC:DD:EE:FF
  &temperature=21.5
  &humidity=56.2
  &co2=415.0
  &pm2_5=8.4</div>

    <div class="form-actions">
      <button class="btn" onclick="closeRegister()" data-i18n="cancel">Atšaukti</button>
      <button class="btn btn-accent" onclick="registerSensor()" data-i18n="register">Registruoti</button>
    </div>
  </div>
</div>

<div id="toast"></div>

<!-- ─── Cookie consent ──────────────────────────────── -->
<div class="cookie-consent" id="cookieConsent" role="dialog" aria-live="polite" aria-label="Slapukų sutikimas" data-i18n-aria="aria_cookie_dialog">
  <div class="cookie-text" id="cookieText"></div>
  <div class="cookie-actions">
    <button class="btn cookie-decline" id="cookieDecline" onclick="cookieChoice(false)"></button>
    <button class="btn cookie-accept" id="cookieAccept" onclick="cookieChoice(true)"></button>
  </div>
</div>

<!-- Attribution — required by CC BY-NC 4.0. The content is inserted from encoded
     parts in JS (see __att()) with an integrity check; it must not be removed. -->
<footer class="attribution-footer" id="attFooter"></footer>

<script>
// ─── Config (injected from PHP) ───────────────────────────
const CFG = {
  center: { lat: <?= MAP_CENTER_LAT ?>, lng: <?= MAP_CENTER_LNG ?> },
  zoom:   <?= MAP_ZOOM ?>,
  apiBase: 'api/sensors.php',
  mapProvider: <?= $useGoogle ? "'google'" : "'osm'" ?>,
  tileProvider: <?= json_encode(defined('MAP_TILE_PROVIDER') ? MAP_TILE_PROVIDER : 'opentopomap') ?>,
  tileUrl: <?= json_encode(defined('MAP_TILE_URL') ? MAP_TILE_URL : '') ?>,
  cityNames: <?= json_encode($regionCityMap, JSON_UNESCAPED_UNICODE) ?: '{}' ?>,
  titles: {
    lt: <?= json_encode($titleLt, JSON_UNESCAPED_UNICODE) ?>,
    en: <?= json_encode($titleEn, JSON_UNESCAPED_UNICODE) ?>
  }
};

// ─── OpenStreetMap (Leaflet) compatibility layer ─────────
// When there is no Google Maps key, we use OSM via Leaflet. This "shim"
// emulates the SMALL subset of the google.maps API that the map code uses
// (Map, Marker su setIcon/setLabel/setMap/addListener, SymbolPath.CIRCLE),
// so ALL the remaining code works unchanged. OSM and Google use THE
// SAME WGS84 (lat/lng) coordinate system, so the coordinates are identical.
if (CFG.mapProvider === 'osm' && typeof window.google === 'undefined') {
  const L = window.L;

  // Leaflet marker with a round SVG "divIcon" + label (matches the Google style)
  function buildLeafletIcon(iconOpts, labelOpts) {
    const scale = (iconOpts && iconOpts.scale) || 10;
    const d = Math.round(scale * 2);          // skersmuo px
    const fill = (iconOpts && iconOpts.fillColor) || '#888';
    const stroke = (iconOpts && iconOpts.strokeColor) || '#333';
    const sw = (iconOpts && iconOpts.strokeWeight) || 2;
    const labelText = labelOpts ? (labelOpts.text || '') : '';
    const labelColor = labelOpts ? (labelOpts.color || '#fff') : '#fff';
    const labelSize = labelOpts ? (labelOpts.fontSize || '11px') : '11px';
    const total = d + sw * 2;
    const html =
      `<div style="position:relative;width:${total}px;height:${total}px">
        <div style="width:${d}px;height:${d}px;border-radius:50%;background:${fill};
             border:${sw}px solid ${stroke};box-sizing:border-box"></div>
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);
             color:${labelColor};font-size:${labelSize};font-weight:bold;white-space:nowrap;
             text-shadow:0 0 3px rgba(0,0,0,.4);pointer-events:none">${labelText}</div>
       </div>`;
    return L.divIcon({
      html, className: '', iconSize: [total, total], iconAnchor: [total / 2, total / 2],
    });
  }

  // google.maps shim
  window.google = { maps: {
    SymbolPath: { CIRCLE: 'circle' },
    Map: class {
      constructor(el, opts) {
        // Yandex uses the EPSG:3395 projection (not the standard EPSG:3857),
        // so when Yandex is selected the map is created with that CRS so the tiles
        // and markers (lat/lng) align. Other providers — the default EPSG:3857.
        const mapOpts = { zoomControl: true, attributionControl: true };
        if (CFG.tileProvider === 'yandex') {
          mapOpts.crs = L.CRS.EPSG3395;
        }
        this._map = L.map(el, mapOpts)
          .setView([opts.center.lat, opts.center.lng], opts.zoom || 12);
        this._setTiles(opts.styles && opts.styles.length ? 'night' : 'day');
      }
      _setTiles(mode) {
        if (this._tiles) this._map.removeLayer(this._tiles);
        const isNight = (mode === 'night');
        // Tile provider presets. Configured via admin (MAP_TILE_PROVIDER),
        // because different networks reach different servers (OSM tile policy
        // recommends allowing provider switching without code changes).
        const PRESETS = {
          carto_voyager: {
            url: 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
            opts: { maxZoom: 20, subdomains: 'abcd',
              attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>' },
          },
          carto_light: {
            url: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
            opts: { maxZoom: 20, subdomains: 'abcd',
              attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>' },
          },
          osm: {
            url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            opts: { maxZoom: 19,
              attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' },
          },
          opentopomap: {
            // OpenTopoMap — topographic (OSM + SRTM data), EPSG:3857.
            // A separate domain; often reachable when other CDNs are blocked.
            url: 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',
            opts: { maxZoom: 17, subdomains: 'abc',
              attribution: 'Žemėlapio duomenys: &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, SRTM | stilius: &copy; <a href="https://opentopomap.org">OpenTopoMap</a> (CC-BY-SA)' },
          },
          yandex: {
            // Yandex tiles (EPSG:3395 — the map is created with the matching CRS).
            // Pastaba: be subdomeno; lang galima keisti (en_US / ru_RU / tr_TR).
            url: 'https://core-renderer-tiles.maps.yandex.net/tiles?l=map&x={x}&y={y}&z={z}&scale=1&lang=en_US',
            opts: { maxZoom: 19,
              attribution: '&copy; <a href="https://yandex.com/maps">Yandex</a>' },
          },
        };
        const nightPreset = {
          url: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
          opts: { maxZoom: 20, subdomains: 'abcd',
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>' },
        };

        let chosen;
        const p = CFG.tileProvider;
        if (p === 'custom' && CFG.tileUrl) {
          chosen = { url: CFG.tileUrl, opts: { maxZoom: 20,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' } };
        } else if (p === 'yandex' || p === 'osm' || p === 'opentopomap') {
          // These providers have no "dark" variant and may be the only ones
          // reachable on the network — so at night we use their own tiles
          // (the dark UI is still applied), not CARTO dark (which may be blocked).
          chosen = PRESETS[p] || PRESETS.carto_voyager;
        } else if (isNight) {
          chosen = nightPreset; // only for CARTO providers — switch to dark
        } else {
          chosen = PRESETS[p] || PRESETS.carto_voyager;
        }

        this._tiles = L.tileLayer(chosen.url, chosen.opts).addTo(this._map);
        // If tiles fail to load, show a message in the console
        let tileErrors = 0;
        this._tiles.on('tileerror', () => {
          tileErrors++;
          if (tileErrors === 4) {
            console.warn('[Žemėlapis] Plytelės nesikrauna iš pasirinkto tiekėjo. '
              + 'Pakeiskite plytelių tiekėją admin nustatymuose (MAP_TILE_PROVIDER) — '
              + 'pvz. carto_voyager, carto_light, osm arba custom. '
              + 'Žemėlapio funkcionalumas (žymekliai, filtrai) veikia ir be plytelių.');
          }
        });
      }
      // google.maps.Map.setOptions({styles}) → switches tiles (day/night)
      setOptions(opts) {
        if (opts && 'styles' in opts) {
          this._setTiles(opts.styles && opts.styles.length ? 'night' : 'day');
        }
      }
      // ── Methods mirrored from the google.maps.Map API so that clustering
      //    and the viewport filter use one code path for both backends. ──
      getZoom() { return this._map.getZoom(); }
      getBounds() {
        const b = this._map.getBounds();
        // Mimic google.maps.LatLngBounds.contains(LatLngLiteral)
        return { contains: (p) => b.contains([p.lat, p.lng]) };
      }
      addListener(ev, cb) {
        // google event name → Leaflet event name
        const map = { idle: 'moveend', zoom_changed: 'zoomend',
                      bounds_changed: 'moveend', dragend: 'dragend' };
        this._map.on(map[ev] || ev, cb);
      }
    },
    Marker: class {
      constructor(opts) {
        this._iconOpts = opts.icon || null;
        this._labelOpts = opts.label || null;
        this._pos = [opts.position.lat, opts.position.lng];
        this._marker = L.marker(this._pos, {
          icon: buildLeafletIcon(this._iconOpts, this._labelOpts),
          title: opts.title || '',
          zIndexOffset: (opts.zIndex || 0) * 100,
        });
        if (opts.map && opts.map._map) this._marker.addTo(opts.map._map);
        this._gmap = opts.map || null;
      }
      setIcon(iconOpts) {
        this._iconOpts = iconOpts;
        this._marker.setIcon(buildLeafletIcon(this._iconOpts, this._labelOpts));
      }
      setLabel(labelOpts) {
        this._labelOpts = labelOpts;
        this._marker.setIcon(buildLeafletIcon(this._iconOpts, this._labelOpts));
      }
      setMap(m) {
        if (m === null) { if (this._gmap) this._marker.remove(); }
        else if (m._map) { this._marker.addTo(m._map); this._gmap = m; }
      }
      addListener(ev, cb) {
        const lev = ev === 'click' ? 'click' : ev;
        this._marker.on(lev, cb);
      }
    },
  }};

  // Leaflet is already loaded (synchronous <script>), so we call initMap immediately.
  document.addEventListener('DOMContentLoaded', () => {
    if (typeof initMap === 'function') initMap();
  });
}

// ─── I18N — language dictionary ───────────────────────────
const I18N = {
  lt: {
    sensors_label: 'Jutikliai',
    filter_title: 'Rodmenys',
    filter_all: 'Visi',
    filter_none: 'Nė vieno',
    avg_title: 'Vidurkiai pagal laikotarpį',
    avg_sensors: 'jutiklių',
    avg_period_label: 'Laikotarpis',
    avg_readings: 'matavimų',
    avg_based_on: 'Pagal',
    avg_none: 'Nėra duomenų pasirinktu laikotarpiu',
    aria_avg_period: 'Vidurkių laikotarpis',
    cookie_text: 'Ši svetainė naudoja tik būtinuosius slapukus (administratoriaus sesijai) ir naršyklės vietinę saugyklą jūsų nuostatoms įsiminti. Sekimo ar reklamos slapukų nėra.',
    cookie_more: 'Daugiau apie slapukus',
    cookie_accept: 'Sutinku',
    cookie_decline: 'Tik būtinieji',
    cookie_settings: 'Slapukų nuostatos',
    updated_label: 'Atnaujinta',
    refresh:       'Atnaujinti',
    add_sensor:    'Pridėti jutiklį',
    history_24h:   '24 val. istorija',
    history_title: 'Istorija',
    aria_chart_period: 'Istorijos laikotarpis',
    per_1d:  '1 diena',
    per_1w:  '1 savaitė',
    per_2w:  '2 savaitės',
    per_1mo: '1 mėnuo',
    per_3mo: '3 mėnesiai',
    per_6mo: '6 mėnesiai',
    per_12mo:'12 mėnesių',
    reg_title:     'Registruoti naują jutiklį',
    reg_desc:      'Jutiklis identifikuojamas pagal jo <strong>GPS koordinates</strong> (7 dešimtainių ženklų tikslumu).<br>Pavadinimas suteikiamas automatiškai pagal <strong>artimiausią miestą</strong> — pvz. Vilniuje → VLN7, Londone → LON2.<br>Po registracijos jutiklis turi per <strong>3 minutes</strong> išsiųsti pirmą matavimą, kitaip įrašas automatiškai ištrinamas.',
    lat_label:     'Platuma (lat) — 7 sk.',
    lng_label:     'Ilguma (lng) — 7 sk.',
    location_type_label: 'Jutiklio vieta',
    loc_indoor:    '🏠 Patalpos',
    loc_outdoor:   '🌳 Lauko',
    loc_filter_title: 'Vieta',
    loc_all:       'Visi',
    loc_indoor_short: '🏠',
    loc_outdoor_short: '🌳',
    region_filter_title: 'Regionas / miestas',
    region_all:    'Visi regionai',
    viewport_label: 'Tik matomi lange',
    aria_marker_size: 'Jutiklių dydis žemėlapyje',
    aria_region_filter: 'Regionas arba miestas',
    aria_cookie_dialog: 'Slapukų sutikimas',
    api_hint:      'Jutiklis duomenis siunčia GET užklausa — įprogramuokite šį URL į firmware:',
    cancel:        'Atšaukti',
    register:      'Registruoti',
    readings_total:'Įrašai iš viso',
    last_reading:  'Paskutinis',
    no_data:       'Nėra duomenų',
    no_data_short: 'nėra duomenų',
    loading:       'Kraunama...',
    hist_error:    'Klaida kraunant istoriją',
    enter_coords:  'Įveskite koordinates',
    reg_success:   (label, city) => `Jutiklis ${label} (${city}) užregistruotas! Per 3 min siųskite duomenis.`,
    conn_error:    'Ryšio klaida',
    api_error:     'API klaida',
    load_fail:     'Nepavyko įkelti jutiklių',
    not_json:      'API grąžino ne JSON — patikrinkite api/sensors.php',
    unknown_err:   'Nežinoma klaida',
    online:        'ONLINE',
    offline:       'OFFLINE',
    metrics: {
      temperature: 'Temperatūra', humidity: 'Drėgmė', co2: 'CO₂',
      pm1: 'PM1.0', pm2_5: 'PM2.5', pm10: 'PM10',
      grains: 'Žiedadulkės', radiation: 'Radiacija',
      alcohol: 'Alkoholis', methane: 'Metanas (CH₄)', propane: 'Propanas',
      butane: 'Butanas', lpg: 'LPG', hydrogen: 'Vandenilis (H₂)',
      co: 'CO', smoke: 'Dūmai', ammonia: 'Amoniakas (NH₃)',
      nox: 'Azoto oksidai (NOₓ)', benzene: 'Benzenas',
      air_quality: 'Oro kokybė', co2_equiv: 'CO₂ ekv.',
    },
    chart: {
      temperature: 'Temp °C', humidity: 'Drėgmė %', co2: 'CO₂ ppm',
      pm2_5: 'PM2.5', pm10: 'PM10', radiation: 'Rad µSv/h',
      alcohol: 'Alkoholis ppm', methane: 'Metanas ppm', propane: 'Propanas ppm',
      butane: 'Butanas ppm', lpg: 'LPG ppm', hydrogen: 'H₂ ppm',
      co: 'CO ppm', smoke: 'Dūmai ppm', ammonia: 'NH₃ ppm',
      nox: 'NOₓ ppm', benzene: 'Benzenas ppm', air_quality: 'Oro kokybė',
      co2_equiv: 'CO₂ ekv. ppm',
    },
  },
  en: {
    sensors_label: 'Sensors',
    filter_title: 'Metrics',
    filter_all: 'All',
    filter_none: 'None',
    avg_title: 'Averages by period',
    avg_sensors: 'sensors',
    avg_period_label: 'Period',
    avg_readings: 'readings',
    avg_based_on: 'Based on',
    avg_none: 'No data for the selected period',
    aria_avg_period: 'Averaging period',
    cookie_text: 'This site uses only strictly necessary cookies (for the admin session) and browser local storage to remember your preferences. There are no tracking or advertising cookies.',
    cookie_more: 'Learn more about cookies',
    cookie_accept: 'I agree',
    cookie_decline: 'Necessary only',
    cookie_settings: 'Cookie settings',
    updated_label: 'Updated',
    refresh:       'Refresh',
    add_sensor:    'Add sensor',
    history_24h:   '24-hour history',
    history_title: 'History',
    aria_chart_period: 'History period',
    per_1d:  '1 day',
    per_1w:  '1 week',
    per_2w:  '2 weeks',
    per_1mo: '1 month',
    per_3mo: '3 months',
    per_6mo: '6 months',
    per_12mo:'12 months',
    reg_title:     'Register a new sensor',
    reg_desc:      'A sensor is identified by its <strong>GPS coordinates</strong> (7 decimal places).<br>The name is assigned automatically by the <strong>nearest city</strong> — e.g. Vilnius → VLN7, London → LON2.<br>After registration the sensor must send its first reading within <strong>3 minutes</strong>, otherwise the record is deleted automatically.',
    lat_label:     'Latitude (lat) — 7 dec.',
    lng_label:     'Longitude (lng) — 7 dec.',
    location_type_label: 'Sensor location',
    loc_indoor:    '🏠 Indoor',
    loc_outdoor:   '🌳 Outdoor',
    loc_filter_title: 'Location',
    loc_all:       'All',
    loc_indoor_short: '🏠',
    loc_outdoor_short: '🌳',
    region_filter_title: 'Region / city',
    region_all:    'All regions',
    viewport_label: 'Only visible in view',
    aria_marker_size: 'Sensor size on the map',
    aria_region_filter: 'Region or city',
    aria_cookie_dialog: 'Cookie consent',
    api_hint:      'The sensor sends data via a GET request — program this URL into the firmware:',
    cancel:        'Cancel',
    register:      'Register',
    readings_total:'Total readings',
    last_reading:  'Last seen',
    no_data:       'No data',
    no_data_short: 'no data',
    loading:       'Loading...',
    hist_error:    'Failed to load history',
    enter_coords:  'Enter coordinates',
    reg_success:   (label, city) => `Sensor ${label} (${city}) registered! Send data within 3 minutes.`,
    conn_error:    'Connection error',
    api_error:     'API error',
    load_fail:     'Failed to load sensors',
    not_json:      'API returned non-JSON — check api/sensors.php',
    unknown_err:   'Unknown error',
    online:        'ONLINE',
    offline:       'OFFLINE',
    metrics: {
      temperature: 'Temperature', humidity: 'Humidity', co2: 'CO₂',
      pm1: 'PM1.0', pm2_5: 'PM2.5', pm10: 'PM10',
      grains: 'Pollen', radiation: 'Radiation',
      alcohol: 'Alcohol', methane: 'Methane (CH₄)', propane: 'Propane',
      butane: 'Butane', lpg: 'LPG', hydrogen: 'Hydrogen (H₂)',
      co: 'CO', smoke: 'Smoke', ammonia: 'Ammonia (NH₃)',
      nox: 'Nitrogen oxides (NOₓ)', benzene: 'Benzene',
      air_quality: 'Air quality', co2_equiv: 'CO₂ eq.',
    },
    chart: {
      temperature: 'Temp °C', humidity: 'Humidity %', co2: 'CO₂ ppm',
      pm2_5: 'PM2.5', pm10: 'PM10', radiation: 'Rad µSv/h',
      alcohol: 'Alcohol ppm', methane: 'Methane ppm', propane: 'Propane ppm',
      butane: 'Butane ppm', lpg: 'LPG ppm', hydrogen: 'H₂ ppm',
      co: 'CO ppm', smoke: 'Smoke ppm', ammonia: 'NH₃ ppm',
      nox: 'NOₓ ppm', benzene: 'Benzene ppm', air_quality: 'Air quality',
      co2_equiv: 'CO₂ eq. ppm',
    },
  },
};

// Language: localStorage → browser language → lt
let lang = localStorage.getItem('iot_lang')
        || (navigator.language?.startsWith('lt') ? 'lt' : 'en');

function t(key) { return I18N[lang][key] ?? I18N.lt[key] ?? key; }
function locale() { return lang === 'lt' ? 'lt-LT' : 'en-GB'; }

function toggleLang() {
  lang = lang === 'lt' ? 'en' : 'lt';
  localStorage.setItem('iot_lang', lang);
  applyLang();
}

function applyLang() {
  // The button shows the OTHER language (the one it will switch to)
  document.getElementById('langToggle').textContent = lang === 'lt' ? 'EN' : 'LT';
  document.documentElement.lang = lang;

  // Title — browser tab and logo (separate LT/EN version)
  const title = CFG.titles[lang] || CFG.titles.lt;
  document.title = title;
  const logo = document.getElementById('siteTitle');
  if (logo) logo.textContent = title;

  // Footer institution name per language (the author is not changed)
  // The attribution footer is updated per language (encoded content)
  renderAttribution();
  renderCookieConsent();

  // Statiniai elementai
  document.querySelectorAll('[data-i18n]').forEach(el => {
    el.textContent = t(el.dataset.i18n);
  });
  document.querySelectorAll('[data-i18n-aria]').forEach(el => {
    el.setAttribute('aria-label', t(el.dataset.i18nAria));
  });
  buildAvgPeriodSelect();
  document.querySelectorAll('[data-i18n-html]').forEach(el => {
    el.innerHTML = t(el.dataset.i18nHtml);
  });

  // Filter panel — re-render with the new language names
  buildFilterOptions();
  populateRegionFilter(); // region city names per language
  renderAverages();

  // Dynamic — re-render the open popup, if any
  if (activeSensor) {
    renderMetrics(activeSensor);
    buildChartTabs();
    updatePopupMeta(activeSensor);
  }
}

// ─── Globals ──────────────────────────────────────────────
let map, markers = {}, histChart = null, activeSensor = null;
let mapStyleMode = localStorage.getItem('iot_map_style') || 'night'; // 'night' | 'day'
let markerScale  = parseInt(localStorage.getItem('iot_marker_size') || '10');
let allSensors   = []; // the last loaded sensors (for filtering)

// ─── Filter by readings ───────────────────────────────────
// By default all readings are enabled → all sensors are shown.
const FILTER_KEYS = ['temperature','humidity','co2','pm1','pm2_5','pm10','grains','radiation',
  'alcohol','methane','propane','butane','lpg','hydrogen','co','smoke',
  'ammonia','nox','benzene','air_quality','co2_equiv'];
let activeFilters = (() => {
  try {
    const saved = JSON.parse(localStorage.getItem('iot_filters') || 'null');
    if (saved && typeof saved === 'object') return saved;
  } catch {}
  return FILTER_KEYS.reduce((o, k) => (o[k] = true, o), {});
})();

// Measurement units for the filter (shown on mobile instead of names)
const FILTER_UNITS = {
  temperature: '°C', humidity: '%', co2: 'ppm',
  pm1: 'µg/m³', pm2_5: 'µg/m³', pm10: 'µg/m³',
  grains: 'gr/m³', radiation: 'µSv/h',
  alcohol: 'ppm', methane: 'ppm', propane: 'ppm', butane: 'ppm', lpg: 'ppm',
  hydrogen: 'ppm', co: 'ppm', smoke: 'ppm', ammonia: 'ppm', nox: 'ppm',
  benzene: 'ppm', air_quality: 'idx', co2_equiv: 'ppm',
};
// Metrics that currently have at least one value across the loaded sensors.
// Only these get a filter checkbox — a metric with no data anywhere in the DB
// is not shown at all. Recomputed whenever the sensor list changes.
let availableMetrics = FILTER_KEYS.slice();
function recomputeAvailableMetrics() {
  const prev = availableMetrics.join(',');
  availableMetrics = FILTER_KEYS.filter(k =>
    allSensors.some(s => s[k] !== null && s[k] !== undefined && s[k] !== '')
  );
  // Never end up with an empty set mid-load (before data arrives) → fall back to all.
  if (availableMetrics.length === 0 && allSensors.length === 0) availableMetrics = FILTER_KEYS.slice();
  return prev !== availableMetrics.join(',');   // true if the set changed
}

function buildFilterOptions() {
  const box = document.getElementById('filterOptions');
  if (!box) return;
  if (availableMetrics.length === 0) {
    box.innerHTML = `<span style="color:var(--muted);font-size:.74rem">${t('no_data')}</span>`;
    return;
  }
  box.innerHTML = availableMetrics.map(k => `
    <label class="filter-opt" title="${I18N[lang].metrics[k]} (${FILTER_UNITS[k]})">
      <input type="checkbox" ${activeFilters[k] ? 'checked' : ''}
             onchange="setFilter('${k}', this.checked)">
      <span class="metric-name">${I18N[lang].metrics[k]}</span>
      <span class="unit">${FILTER_UNITS[k]}</span>
    </label>`).join('');
}
function setFilter(key, on) {
  activeFilters[key] = on;
  localStorage.setItem('iot_filters', JSON.stringify(activeFilters));
  renderMarkers(allSensors);
  renderAverages();
}
function setAllFilters(on) {
  // Only toggles the metrics that are actually shown (have data).
  availableMetrics.forEach(k => activeFilters[k] = on);
  localStorage.setItem('iot_filters', JSON.stringify(activeFilters));
  buildFilterOptions();
  renderMarkers(allSensors);
  renderAverages();
}

// ─── Averages: for each enabled metric — of all visible ──
// sensors that have that value.
// Available averaging periods (key → time window resolved on the server).
const AVG_PERIODS = [
  { key: '4h',   lt: '4 val.',          en: '4 h'           },
  { key: '6h',   lt: '6 val.',          en: '6 h'           },
  { key: '12h',  lt: '12 val.',         en: '12 h'          },
  { key: '24h',  lt: '24 val.',         en: '24 h'          },
  { key: '7d',   lt: '7 d.',            en: '7 d'           },
  { key: '30d',  lt: '30 d. (1 mėn.)',  en: '30 d (1 mo.)'  },
  { key: '90d',  lt: '90 d. (3 mėn.)',  en: '90 d (3 mo.)'  },
  { key: '180d', lt: '180 d. (6 mėn.)', en: '180 d (6 mo.)' },
  { key: '365d', lt: '365 d. (1 m.)',   en: '365 d (1 yr)'  },
  { key: 'all',  lt: 'Visas laikas',    en: 'All time'      },
];
let avgPeriod = localStorage.getItem('iot_avg_period') || '24h';
if (!AVG_PERIODS.some(p => p.key === avgPeriod)) avgPeriod = '24h';

// (Re)builds the period dropdown with localized labels.
function buildAvgPeriodSelect() {
  const sel = document.getElementById('avgPeriod');
  if (!sel) return;
  sel.innerHTML = AVG_PERIODS
    .map(p => `<option value="${p.key}">${escapeHtml(p[lang] || p.en)}</option>`).join('');
  sel.value = avgPeriod;
}

function setAvgPeriod(value) {
  avgPeriod = value;
  localStorage.setItem('iot_avg_period', value);
  renderAverages();
}

// Averages are computed on the SERVER over the chosen period, respecting the
// region (city_prefix) and indoor/outdoor filters. Debounced so rapid filter
// toggles coalesce into one request; a request id guard drops stale responses.
let _avgTimer = null, _avgReqId = 0;
function renderAverages() {
  clearTimeout(_avgTimer);
  _avgTimer = setTimeout(_renderAveragesNow, 200);
}
async function _renderAveragesNow() {
  const box = document.getElementById('filterAverages');
  if (!box) return;
  const reqId = ++_avgReqId;
  const qs = new URLSearchParams({
    action: 'averages', period: avgPeriod, region: regionFilter, location: locFilter,
  });
  try {
    const res  = await fetch(`${CFG.apiBase}?${qs.toString()}`);
    const data = await res.json();
    if (reqId !== _avgReqId) return; // superseded by a newer request
    const activeKeys = FILTER_KEYS.filter(k => activeFilters[k]);
    const rows = activeKeys.map(k => {
      const m = data.metrics && data.metrics[k];
      if (!m || m.avg === null || m.count === 0) return null;
      const name = I18N[lang].metrics[k];
      return `<div class="avg-row" title="${name}: ${m.count} ${t('avg_readings')}">
      <span class="avg-name">${name}</span>
      <span class="avg-val">${Number(m.avg).toLocaleString(locale(), {maximumFractionDigits: 1})}<span class="avg-unit">${FILTER_UNITS[k]}</span><span class="avg-count">(${m.count})</span></span>
    </div>`;
    }).filter(Boolean);
    const head = `<div class="avg-title">${t('avg_title')}</div>`;
    box.innerHTML = rows.length
      ? head + rows.join('') +
        `<div class="avg-meta">${t('avg_based_on')}: ${data.sensor_count} ${t('avg_sensors')} · ${data.reading_count} ${t('avg_readings')}</div>`
      : head + `<div class="avg-empty">${t('avg_none')}</div>`;
  } catch (e) {
    if (reqId === _avgReqId) box.innerHTML = '';
  }
}
// Whether a sensor matches the filter: has at least one selected reading with a value.
function sensorMatchesFilter(s) {
  // Viewport filter — only sensors currently visible in the map window
  if (viewportOnly && typeof map !== 'undefined' && map && map.getBounds) {
    try {
      if (!map.getBounds().contains({ lat: Number(s.lat), lng: Number(s.lng) })) return false;
    } catch (e) { /* bounds not ready yet — ignore */ }
  }

  // Location filter (indoor/outdoor/all)
  if (locFilter === 'indoor'  && Number(s.is_outdoor) === 1) return false;
  if (locFilter === 'outdoor' && Number(s.is_outdoor) !== 1) return false;

  // Region / city filter
  if (regionFilter !== 'all' && regionOf(s) !== regionFilter) return false;

  // Only metrics that actually have data participate in the filter.
  const pool   = availableMetrics.length ? availableMetrics : FILTER_KEYS;
  const active = pool.filter(k => activeFilters[k]);
  if (active.length === pool.length) return true; // all enabled → all visible
  if (active.length === 0) return false;          // none → nieko
  return active.some(k => s[k] !== null && s[k] !== undefined && s[k] !== '');
}

// Vietos filtras: 'all' | 'indoor' | 'outdoor'
let locFilter = localStorage.getItem('iot_loc_filter') || 'all';

function setLocFilter(value) {
  locFilter = value;
  localStorage.setItem('iot_loc_filter', value);
  document.querySelectorAll('.loc-btn').forEach(b =>
    b.classList.toggle('active', b.dataset.loc === value));
  renderMarkers(allSensors);
  renderAverages();
}

// ─── Region / city filter ─────────────────────────────────
// 'all' or a specific city_prefix (e.g. 'VLN'). Filters the map
// AND the averaged data (via sensorMatchesFilter).
let regionFilter = localStorage.getItem('iot_region_filter') || 'all';

function setRegionFilter(value) {
  regionFilter = value;
  localStorage.setItem('iot_region_filter', value);
  renderMarkers(allSensors);
  renderAverages();
}

// ─── Viewport filter ──────────────────────────────────────
// When on, only sensors currently inside the map window are shown;
// the list updates automatically as the user pans / zooms.
let viewportOnly = localStorage.getItem('iot_viewport_only') === '1';

function setViewportOnly(on) {
  viewportOnly = !!on;
  localStorage.setItem('iot_viewport_only', viewportOnly ? '1' : '0');
  const cb = document.getElementById('viewportToggle');
  if (cb) cb.checked = viewportOnly;
  renderMarkers(allSensors);
  renderAverages();
}

// Fills the region dropdown from loaded sensors (unique city_prefix).
// The city name is taken from CFG.cityNames (if known), otherwise — the code.
function populateRegionFilter() {
  const sel = document.getElementById('regionFilter');
  if (!sel) return;
  const regions = [...new Set(allSensors.map(s => regionOf(s)).filter(Boolean))].sort();
  const cur = regionFilter;
  // First option "All regions" + one for each region
  let html = `<option value="all">${t('region_all')}</option>`;
  for (const r of regions) {
    // City name per language (CFG.cityNames[r] = {lt, en}).
    let city = null;
    const entry = CFG.cityNames && CFG.cityNames[r];
    if (entry) city = (typeof entry === 'object') ? (entry[lang] || entry.en || entry.lt) : entry;
    const label = city ? `${r} — ${city}` : r;
    html += `<option value="${escapeHtml(r)}">${escapeHtml(label)}</option>`;
  }
  sel.innerHTML = html;
  // Restore the selection if the region still exists, otherwise — 'all'
  if (cur !== 'all' && !regions.includes(cur)) {
    regionFilter = 'all';
    localStorage.setItem('iot_region_filter', 'all');
  }
  sel.value = regionFilter;
}

// Region (city_prefix) extraction from a sensor.
function regionOf(s) {
  if (s.city_prefix) return String(s.city_prefix).toUpperCase();
  const m = String(s.label || '').match(/^([A-Za-z]+)/);
  return m ? m[1].toUpperCase() : '';
}

// ─── Init Google Map ──────────────────────────────────────
function initMap() {
  map = new google.maps.Map(document.getElementById('map'), {
    center: CFG.center,
    zoom:   CFG.zoom,
    mapTypeId: 'roadmap',
    styles: mapStyleMode === 'night' ? darkMapStyle() : [],
    disableDefaultUI: false,
    zoomControl: true,
    mapTypeControl: false,
    streetViewControl: false,
    fullscreenControl: true,
  });
  // Restore control states from localStorage
  document.getElementById('styleToggle').textContent = mapStyleMode === 'night' ? '🌙' : '☀️';
  document.getElementById('markerSize').value = markerScale;
  // Restore the location filter button state
  document.querySelectorAll('.loc-btn').forEach(b =>
    b.classList.toggle('active', b.dataset.loc === locFilter));
  // Restore the viewport-filter checkbox
  const vpCb = document.getElementById('viewportToggle');
  if (vpCb) vpCb.checked = viewportOnly;
  applyLang();

  // Re-cluster markers and refresh the viewport filter whenever the map
  // settles after a pan or zoom. Debounced so rapid moves don't thrash.
  let _idleT = null;
  map.addListener('idle', () => {
    clearTimeout(_idleT);
    _idleT = setTimeout(() => {
      if (allSensors.length) { renderMarkers(allSensors); if (viewportOnly) renderAverages(); }
    }, 120);
  });

  loadMap();             // first load (fast start)
  // Real-time updates. The default is safe 30s polling.
  // SSE (real-time) is OPT-IN, because on shared hosting it can
  // exhaust PHP processes. Enabling:
  //   - URL: add ?sse=1 (remembered), ?sse=0 disables
  //   - or localStorage 'iot_sse' = '1'
  const sseParam = new URLSearchParams(location.search).get('sse');
  if (sseParam === '1') localStorage.setItem('iot_sse', '1');
  if (sseParam === '0') localStorage.removeItem('iot_sse');

  if (localStorage.getItem('iot_sse') === '1') {
    startSensorStream();   // SSE su polling fallback
  } else {
    startPollingFallback(); // safe default (recommended on shared hosting)
  }
}

// ─── 4.3 SSE: real-time sensor updates ───────────────────
let _sse = null;
let _pollTimer = null;

function startSensorStream() {
  // If the browser supports EventSource — use SSE
  if (typeof EventSource !== 'undefined') {
    try {
      _sse = new EventSource(`${CFG.apiBase}?action=stream`, { withCredentials: true });

      _sse.addEventListener('update', (ev) => {
        try {
          const data = JSON.parse(ev.data);
          renderMarkers(data.sensors);
          document.getElementById('sensorCount').textContent = data.count;
          document.getElementById('lastUpdate').textContent =
            new Date().toLocaleTimeString(locale());
          // Indicator that the real-time connection is active
          setLiveIndicator(true);
        } catch (e) { /* ignore a bad packet */ }
      });

      _sse.addEventListener('error', () => {
        // Connection error. IMPORTANT: EventSource by default AUTOMATICALLY
        // tries to reconnect every few seconds — on shared hosting this
        // would cause a flood of requests. So we CLOSE the connection and switch to
        // safe polling (without automatic SSE reconnection).
        setLiveIndicator(false);
        if (_sse) {
          _sse.close();   // stop automatic reconnection
          _sse = null;
        }
        startPollingFallback();
      });

      // The server ended the connection cleanly (reached the duration limit).
      // We switch to polling, we do NOT try to reconnect.
      _sse.addEventListener('bye', () => {
        if (_sse) { _sse.close(); _sse = null; }
        startPollingFallback();
      });

      return; // SSE paleista
    } catch (e) {
      // EventSource creation failed — fallback
    }
  }
  // No EventSource support — polling
  startPollingFallback();
}

function startPollingFallback() {
  if (_pollTimer) return; // already running
  setLiveIndicator(false);
  _pollTimer = setInterval(loadMap, 30_000); // auto-refresh kas 30 s
}

// A small visual indicator for the "live" state (if the element exists)
function setLiveIndicator(on) {
  const el = document.getElementById('liveDot');
  if (!el) return;
  el.style.background = on ? 'var(--ok, #22d3a0)' : 'var(--muted, #64748b)';
  el.title = on ? 'Realaus laiko ryšys aktyvus (SSE)' : 'Periodinis atnaujinimas (30s)';
}

// ─── Map style switching (night / day) ──────────────────
function toggleMapStyle() {
  mapStyleMode = mapStyleMode === 'night' ? 'day' : 'night';
  localStorage.setItem('iot_map_style', mapStyleMode);
  map.setOptions({ styles: mapStyleMode === 'night' ? darkMapStyle() : [] });
  document.getElementById('styleToggle').textContent = mapStyleMode === 'night' ? '🌙' : '☀️';
  refreshAllMarkers();
}

// Re-render markers (size + colors + labels per style)
function refreshAllMarkers() {
  // requestAnimationFrame ensures the re-render happens on the next
  // frame — markers update instantly and smoothly, without lag.
  if (refreshAllMarkers._raf) cancelAnimationFrame(refreshAllMarkers._raf);
  refreshAllMarkers._raf = requestAnimationFrame(() => {
    Object.values(markers).forEach(m => {
      const s = m._sdata;
      if (!s) return;
      m.setIcon(markerIcon(s));
      m.setLabel(markerLabel(s._labelText || s.label));
    });
  });
}

// ─── Sensor marker size adjustment ───────────────────────
function setMarkerSize(v) {
  markerScale = parseInt(v);
  localStorage.setItem('iot_marker_size', markerScale);
  refreshAllMarkers();
}

// Label above the marker — color and font per style/size
function markerLabel(text) {
  return {
    text,
    color: mapStyleMode === 'day' ? '#1e293b' : '#ffffff',
    fontSize: Math.max(9, Math.round(markerScale * 1.1)) + 'px',
    fontWeight: 'bold',
  };
}

// ─── Load sensors from API ────────────────────────────────
async function loadMap() {
  try {
    // Cache-busting (_t) — so the browser does not return a stale response
    // and new sensors/data appear immediately.
    const res = await fetch(`${CFG.apiBase}?action=map_data&_t=${Date.now()}`, {
      cache: 'no-store',
      credentials: 'same-origin',
    });
    if (!res.ok) {
      toast(`${t('api_error')}: HTTP ${res.status}`, 'err');
      return;
    }
    const data = await res.json();
    renderMarkers(data.sensors);
    document.getElementById('sensorCount').textContent = data.count;
    document.getElementById('lastUpdate').textContent  = new Date().toLocaleTimeString(locale());
  } catch (e) {
    toast(`${t('load_fail')}: ${e.message}`, 'err');
  }
}

// ─── Render markers ───────────────────────────────────────
// Coordinate key for grouping (7-digit precision, like the DB)
// Markers whose centres fall within ~CLUSTER_PX screen pixels of each other
// merge into one cluster point. Zooming in shrinks the grid cell, so clusters
// split apart; sensors sharing the exact same coordinate always stay merged.
const CLUSTER_PX = 38;
function coordKey(s) {
  const lat = Number(s.lat), lng = Number(s.lng);
  const z = (typeof map !== 'undefined' && map && map.getZoom) ? map.getZoom() : 12;
  const worldPx = 256 * Math.pow(2, z);     // Web-Mercator world size at this zoom
  const cell = CLUSTER_PX / worldPx;        // grid cell as a fraction of the world
  const x = (lng + 180) / 360;
  const sin = Math.max(-0.9999, Math.min(0.9999, Math.sin(lat * Math.PI / 180)));
  const y = 0.5 - Math.log((1 + sin) / (1 - sin)) / (4 * Math.PI);
  return `${Math.floor(x / cell)}_${Math.floor(y / cell)}`;
}

let sensorGroups = {}; // coordKey → [sensors at that location]

function renderMarkers(sensors) {
  allSensors = sensors; // save for filtering
  if (recomputeAvailableMetrics()) buildFilterOptions(); // rebuild only when the metric set changes
  populateRegionFilter(); // refresh the region dropdown from the loaded sensors

  // 1. Filter the visible sensors
  const visible = sensors.filter(sensorMatchesFilter);

  // 2. Group by coordinates — several sensors (different MACs)
  //    at the same location form one group / one marker.
  sensorGroups = {};
  visible.forEach(s => {
    const key = coordKey(s);
    (sensorGroups[key] ||= []).push(s);
  });

  const seen = new Set();
  Object.entries(sensorGroups).forEach(([key, group]) => {
    seen.add(key);
    // Representative sensor: first online, otherwise first by id
    group.sort((a, b) => (b.online - a.online) || (a.id - b.id));
    const rep = group[0];
    const isOnline = group.some(g => g.online == 1);
    // Label: if several in a group — show a representative + count
    const labelText = group.length > 1 ? `${rep.label}+${group.length - 1}` : rep.label;

    if (markers[key]) {
      markers[key].setIcon(markerIcon(rep));
      markers[key].setLabel(markerLabel(labelText));
    } else {
      // 4.4 NOTE: google.maps.Marker is "deprecated" (2024-02), but
      // is still fully supported. Migration to AdvancedMarkerElement
      // requires a Map ID and the "marker" library; it is planned, but
      // does not change functionality. The classic Marker works reliably with
      // SymbolPath.CIRCLE icon and a text label.
      const marker = new google.maps.Marker({
        position: { lat: Number(rep.lat), lng: Number(rep.lng) },
        map,
        title: group.map(g => g.label).join(', '),
        icon: markerIcon(rep),
        label: markerLabel(labelText),
        zIndex: isOnline ? 10 : 5,
      });
      marker.addListener('click', () => openGroup(markers[key]._group));
      markers[key] = marker;
    }
    // Save the representative sensor and label for size adjustment
    markers[key]._sdata = rep;
    markers[key]._labelText = labelText;
    markers[key]._group = group;
  });

  // Remove markers whose groups no longer exist
  Object.keys(markers).forEach(key => {
    if (!seen.has(key)) {
      markers[key].setMap(null);
      delete markers[key];
    }
  });

  // If a popup is open — update it with the latest data.
  if (activeSensor) {
    const fresh = sensors.find(s => s.id === activeSensor.id);
    if (fresh) {
      activeSensor = fresh;
      // Also update the group selection (if new ones appeared at the location)
      const group = sensorGroups[coordKey(fresh)] || [fresh];
      activeGroup = group;
      buildGroupSelector(group, fresh.id);
      renderMetrics(fresh);
      updatePopupMeta(fresh);
    }
  }

  // Update the averages in the side panel
  renderAverages();
}

// ─── Random but stable color for each sensor ──
// Auksinio kampo metodas: spalvos tolygiai pasiskirsto spektre
// and does not change between updates (generated from the sensor id).
function sensorColor(id, online) {
  const hue = (id * 137.508) % 360; // golden angle
  if (!online) {
    // Offline — the same color, but faded
    return {
      fill:   `hsl(${hue}, 25%, ${mapStyleMode === 'day' ? 60 : 40}%)`,
      stroke: `hsl(${hue}, 20%, ${mapStyleMode === 'day' ? 45 : 28}%)`,
    };
  }
  return {
    fill:   `hsl(${hue}, ${mapStyleMode === 'day' ? 75 : 85}%, ${mapStyleMode === 'day' ? 45 : 58}%)`,
    stroke: `hsl(${hue}, 80%, ${mapStyleMode === 'day' ? 28 : 78}%)`,
  };
}

function markerIcon(s) {
  const c = sensorColor(s.id, s.online == 1);
  return {
    path: google.maps.SymbolPath.CIRCLE,
    scale: markerScale,
    fillColor:   c.fill,
    fillOpacity: 1,
    strokeColor: c.stroke,
    strokeWeight: 2,
  };
}

// ─── Open sensor group popup ──────────────────────────────
let activeGroup = [];

// Open a group (one or several sensors at the same location).
async function openGroup(group) {
  activeGroup = group;
  // Initial sensor: first online, otherwise first
  const sorted = [...group].sort((a, b) => (b.online - a.online) || (a.id - b.id));
  const first = sorted[0];
  buildGroupSelector(group, first.id);
  await openSensor(first);
}

// Group selection bar in the popup — shown ONLY when >1 sensor at a location.
function buildGroupSelector(group, activeId) {
  const box = document.getElementById('groupSelector');
  if (!box) return;
  if (group.length <= 1) { box.innerHTML = ''; return; }

  box.innerHTML = group
    .slice()
    .sort((a, b) => a.id - b.id)
    .map(s => {
      const c = sensorColor(s.id, s.online == 1);
      const active = s.id === activeId ? 'active' : '';
      return `<button class="group-tab ${active}" onclick="selectGroupSensor(${s.id})"
        title="MAC: ${escapeHtml(s.mac || '—')}">
        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;
              background:${c.fill};border:1px solid ${c.stroke};margin-right:.35rem"></span>${escapeHtml(s.label)}</button>`;
    }).join('');
}

// Switch to another sensor at the same location.
async function selectGroupSensor(id) {
  const s = activeGroup.find(g => g.id === id);
  if (!s) return;
  buildGroupSelector(activeGroup, id);
  await openSensor(s);
}

async function openSensor(s) {
  activeSensor = s;
  updatePopupMeta(s);

  renderMetrics(s);
  document.getElementById('sensorOverlay').classList.add('open');

  // Load chart data
  await loadHistory(s.id);
}

function closeSensor(e) {
  if (e && e.target !== document.getElementById('sensorOverlay')) return;
  document.getElementById('sensorOverlay').classList.remove('open');
  activeSensor = null;
  activeGroup = [];
}

// ─── Metrics grid ─────────────────────────────────────────

// Popup header and meta — depend on the language
function updatePopupMeta(s) {
  const c = sensorColor(s.id, s.online == 1);
  const colorDot = `<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:${c.fill};border:2px solid ${c.stroke};margin-right:.5rem;vertical-align:baseline"></span>`;
  const onlineBadge = s.online == 1
    ? `<span style="color:var(--ok);font-size:.7rem;margin-left:.5rem">● ${t('online')}</span>`
    : `<span style="color:var(--muted);font-size:.7rem;margin-left:.5rem">● ${t('offline')}</span>`;
  document.getElementById('popLabel').innerHTML  = colorDot + escapeHtml(s.label) + onlineBadge;
  document.getElementById('popReadings').textContent = `${t('readings_total')}: ${s.reading_count}`;
  document.getElementById('popLastSeen').textContent =
    s.last_seen ? `${t('last_reading')}: ${new Date(s.last_seen).toLocaleString(locale())}` : t('no_data');
}

const METRICS = [
  { key: 'temperature', unit: '°C',    cls: 'temp' },
  { key: 'humidity',    unit: '%',     cls: 'hum'  },
  { key: 'co2',         unit: 'ppm',   cls: 'co2'  },
  { key: 'pm1',         unit: 'µg/m³', cls: 'pm'   },
  { key: 'pm2_5',       unit: 'µg/m³', cls: 'pm'   },
  { key: 'pm10',        unit: 'µg/m³', cls: 'pm'   },
  { key: 'grains',      unit: 'gr/m³', cls: 'grain'},
  { key: 'radiation',   unit: 'µSv/h', cls: 'rad'  },
  { key: 'alcohol',     unit: 'ppm',   cls: 'gas'  },
  { key: 'methane',     unit: 'ppm',   cls: 'gas'  },
  { key: 'propane',     unit: 'ppm',   cls: 'gas'  },
  { key: 'butane',      unit: 'ppm',   cls: 'gas'  },
  { key: 'lpg',         unit: 'ppm',   cls: 'gas'  },
  { key: 'hydrogen',    unit: 'ppm',   cls: 'gas'  },
  { key: 'co',          unit: 'ppm',   cls: 'gas'  },
  { key: 'smoke',       unit: 'ppm',   cls: 'gas'  },
  { key: 'ammonia',     unit: 'ppm',   cls: 'gas'  },
  { key: 'nox',         unit: 'ppm',   cls: 'gas'  },
  { key: 'benzene',     unit: 'ppm',   cls: 'gas'  },
  { key: 'air_quality', unit: 'idx',   cls: 'gas'  },
  { key: 'co2_equiv',   unit: 'ppm',   cls: 'gas'  },
];

function renderMetrics(s) {
  const grid = document.getElementById('metricsGrid');

  // Show ONLY the metrics for which the sensor has a value.
  // If a sensor sends only temp+humidity → only 2 cards are visible.
  const available = METRICS.filter(m => {
    const v = s[m.key];
    return v !== null && v !== undefined && v !== '';
  });

  if (available.length === 0) {
    // The sensor has not sent any measurement yet
    grid.innerHTML =
      `<div style="grid-column:1/-1;text-align:center;color:var(--muted);
        font-size:.85rem;padding:1rem">${t('no_data')}</div>`;
    return;
  }

  grid.innerHTML = available.map(m => {
    const val    = s[m.key];
    const mLabel = I18N[lang].metrics[m.key];
    return `<div class="metric-card ${m.cls}">
      <div class="metric-label">${mLabel}</div>
      <div class="metric-value">${parseFloat(val).toLocaleString(locale(), {maximumFractionDigits:2})}</div>
      <div class="metric-unit">${m.unit}</div>
    </div>`;
  }).join('');
}

// ─── History chart ────────────────────────────────────────
const CHART_METRICS = [
  { key: 'temperature', color: '#f97316' },
  { key: 'humidity',    color: '#3b82f6' },
  { key: 'co2',         color: '#a855f7' },
  { key: 'pm2_5',       color: '#22d3a0' },
  { key: 'pm10',        color: '#00c8ff' },
  { key: 'radiation',   color: '#ef4444' },
  { key: 'alcohol',     color: '#eab308' },
  { key: 'methane',     color: '#84cc16' },
  { key: 'propane',     color: '#14b8a6' },
  { key: 'butane',      color: '#06b6d4' },
  { key: 'lpg',         color: '#f59e0b' },
  { key: 'hydrogen',    color: '#8b5cf6' },
  { key: 'co',          color: '#dc2626' },
  { key: 'smoke',       color: '#737373' },
  { key: 'ammonia',     color: '#ec4899' },
  { key: 'nox',         color: '#a16207' },
  { key: 'benzene',     color: '#be123c' },
  { key: 'air_quality', color: '#10b981' },
  { key: 'co2_equiv',   color: '#c026d3' },
];

let histData = [];
let activeChartKey = 'temperature';
let chartPeriod = localStorage.getItem('iot_chart_period') || '1d';
let currentHistId = null;   // sensor whose history is currently shown

async function loadHistory(sensorId) {
  currentHistId = sensorId;
  const sel = document.getElementById('chartPeriod');
  if (sel) sel.value = chartPeriod;
  const tabs = document.getElementById('chartTabs');
  tabs.innerHTML = `<span style="color:var(--muted);font-size:.78rem">${t('loading')}</span>`;
  try {
    const res  = await fetch(`${CFG.apiBase}?action=history&id=${sensorId}&period=${encodeURIComponent(chartPeriod)}`);
    const data = await res.json();
    // Ignore a stale response if the user already switched sensors.
    if (currentHistId !== sensorId) return;
    histData = data.points || [];
    buildChartTabs();
    drawChart(activeChartKey);
  } catch {
    tabs.innerHTML = `<span style="color:var(--danger);font-size:.78rem">${t('hist_error')}</span>`;
  }
}

// Switch the history range (1 d … 12 mo) and reload the chart.
function setChartPeriod(value) {
  chartPeriod = value;
  localStorage.setItem('iot_chart_period', value);
  if (currentHistId !== null) loadHistory(currentHistId);
}

function buildChartTabs() {
  const tabs = document.getElementById('chartTabs');
  // Show ONLY the metrics that have at least one data point.
  // A sensor sending only temp+humidity → only 2 buttons are visible.
  const available = CHART_METRICS.filter(m =>
    histData.some(p => p[m.key] !== null && p[m.key] !== undefined)
  );

  if (available.length === 0) {
    tabs.innerHTML = `<span style="color:var(--muted);font-size:.78rem">${t('no_data')}</span>`;
    return;
  }

  // If the active metric no longer has data — switch to the first available
  if (!available.some(m => m.key === activeChartKey)) {
    activeChartKey = available[0].key;
  }

  tabs.innerHTML = available.map(m =>
    `<button class="chart-tab ${m.key === activeChartKey ? 'active' : ''}"
      onclick="switchChart('${m.key}',this)">${I18N[lang].chart[m.key]}</button>`
  ).join('');
}

function switchChart(key, btn) {
  activeChartKey = key;
  document.querySelectorAll('.chart-tab').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  drawChart(key);
}

function drawChart(key) {
  // Chart.js is loaded on demand the first time a history chart is drawn.
  loadChartJs().then(() => _drawChart(key)).catch(() => {
    const c = document.getElementById('historyChart');
    if (c) toast('⚠ Chart.js', 'err');
  });
}

// Loads Chart.js once, returning a cached promise. Keeps the 70 KB library out
// of the initial page load (PageSpeed: ~61 KB unused JS on first paint).
let _chartJsPromise = null;
function loadChartJs() {
  if (window.Chart) return Promise.resolve();
  if (_chartJsPromise) return _chartJsPromise;
  _chartJsPromise = new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
    s.onload = resolve;
    s.onerror = reject;
    document.head.appendChild(s);
  });
  return _chartJsPromise;
}

function _drawChart(key) {
  const meta   = CHART_METRICS.find(m => m.key === key);
  const labels = histData.map(p => {
    const d = new Date(p.recorded_at);
    if (chartPeriod === '1d') {
      return d.toLocaleTimeString(locale(), { hour: '2-digit', minute: '2-digit' });
    }
    if (chartPeriod === '1w' || chartPeriod === '2w') {
      return d.toLocaleString(locale(), { month: 'short', day: 'numeric', hour: '2-digit' });
    }
    return d.toLocaleDateString(locale(), { year: '2-digit', month: 'short', day: 'numeric' });
  });
  const values = histData.map(p => p[key] !== null ? parseFloat(p[key]) : null);

  if (histChart) histChart.destroy();
  const ctx = document.getElementById('historyChart').getContext('2d');
  histChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: I18N[lang].chart[key],
        data: values,
        borderColor: meta.color,
        backgroundColor: meta.color + '18',
        pointRadius: 2,
        borderWidth: 2,
        fill: true,
        spanGaps: true,
        tension: 0.3,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { mode: 'index', intersect: false }
      },
      scales: {
        x: {
          ticks: { color: '#64748b', maxTicksLimit: 12, font: { family: 'monospace', size: 10 } },
          grid:  { color: '#1e2d45' }
        },
        y: {
          ticks: { color: '#64748b', font: { family: 'monospace', size: 10 } },
          grid:  { color: '#1e2d45' }
        }
      }
    }
  });
}

// ─── Register sensor ──────────────────────────────────────
function openRegister() {
  document.getElementById('regOverlay').classList.add('open');
}
function closeRegister(e) {
  if (e && e.target !== document.getElementById('regOverlay')) return;
  document.getElementById('regOverlay').classList.remove('open');
}

async function registerSensor(force = false) {
  const lat = parseFloat(document.getElementById('regLat').value);
  const lng = parseFloat(document.getElementById('regLng').value);
  if (isNaN(lat) || isNaN(lng)) { toast(t('enter_coords'), 'err'); return; }

  const latStr = lat.toFixed(7);
  const lngStr = lng.toFixed(7);
  const forceParam = force ? '&force=1' : '';
  // Indoor (0) / outdoor (1) — if unchecked, indoor by default
  const locType = document.querySelector('input[name="regLocType"]:checked')?.value || '0';

  try {
    const res = await fetch(`${CFG.apiBase}?action=register&lat=${latStr}&lng=${lngStr}&is_outdoor=${locType}${forceParam}`);

    if (!res.ok && res.status !== 409 && res.status !== 400) {
      // 404 = bad path, 500 = PHP/DB error, etc.
      toast(`${t('api_error')}: HTTP ${res.status} ${res.statusText}`, 'err');
      return;
    }

    let data;
    try {
      data = await res.json();
    } catch {
      toast(t('not_json'), 'err');
      return;
    }

    if (data.ok) {
      toast(I18N[lang].reg_success(data.label, data.city || ''), 'ok');
      if (data.warning) {
        setTimeout(() => toast(`⚠ Geocoding: ${data.warning}`, 'err'), 3500);
      }
      closeRegister();
      loadMap();
    } else if (data.needs_confirmation) {
      // Coordinates taken — ask whether to register a new one at the same location
      if (confirm(t('coords_exist_confirm'))) {
        await registerSensor(true); // pakartoti su force
      }
    } else {
      toast(data.error || t('unknown_err'), 'err');
    }
  } catch (e) {
    toast(`${t('conn_error')}: ${e.message}`, 'err');
  }
}

// Update the code example when the coordinates change
['regLat','regLng'].forEach(id => {
  document.getElementById(id).addEventListener('input', () => {
    const lat = parseFloat(document.getElementById('regLat').value) || 54.6872000;
    const lng = parseFloat(document.getElementById('regLng').value) || 25.2797000;
    const latS = lat.toFixed(7);
    const lngS = lng.toFixed(7);
    document.getElementById('apiExample').textContent =
`GET /api/sensors.php?action=reading
  &lat=${latS}
  &lng=${lngS}
  &mac=AA:BB:CC:DD:EE:FF
  &temperature=21.5
  &humidity=56.2
  &co2=415.0
  &pm2_5=8.4

-- Send every 1 minute. Optional extra fields:
-- &pm1=5.1 &pm10=12.3 &grains=23.0 &radiation=0.12`;
  });
});

// ─── Toast ────────────────────────────────────────────────
// ─── HTML escaping (XSS protection in innerHTML writes) ───
// Although the prefix is filtered on the server, the city names come
// from external Google Geocoding — always escaped before innerHTML.
function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[c]);
}

// ─── Atribucija (CC BY-NC 4.0, privaloma) ─────────────────
// The content is stored base64-encoded so it does not appear in a direct
// text search. NOTE: this is an obstacle, not cryptographic protection —
// legal force is provided by the LICENSE file. The integrity guard restores
// the footer if it is removed or emptied in the DOM.
const __a = ['QWxla3NhbmRy', 'IElndW1lbm92']; // autorius (2 dalys)
const __i = {
  lt: 'VmlsbmlhdXMgdW5pdmVyc2l0ZXRvIE1ldG9kaW5pcyBTVEVBTSB1Z2R5bW8gY2VudHJhcw==',
  en: 'Vmlsbml1cyBVbml2ZXJzaXR5IE1ldGhvZGljYWwgU1RFQU0gRWR1Y2F0aW9uIENlbnRyZQ=='
};
function __dec(b) { try { return decodeURIComponent(escape(atob(b))); } catch { return ''; } }
function __att() {
  const author = __dec(__a[0]) + __dec(__a[1]);
  const inst   = __dec(__i[lang] || __i.lt);
  return { author, inst };
}
function renderAttribution() {
  const f = document.getElementById('attFooter');
  if (!f) return;
  const { author, inst } = __att();
  const privacyLabel = lang === 'lt' ? 'Privatumas ir slapukai' : 'Privacy & cookies';
  f.innerHTML = `<span><strong>${escapeHtml(author)}</strong></span>`
              + `<span class="sep">·</span>`
              + `<span>${escapeHtml(inst)}</span>`
              + `<span class="sep">·</span>`
              + `<a href="privacy.php" style="color:var(--accent);text-decoration:none">${privacyLabel}</a>`
              + `<span class="sep">·</span>`
              + `<a href="cookies.php" style="color:var(--accent);text-decoration:none">${t('cookie_settings')}</a>`;
}

// ─── Cookie consent ──────────────────────────────────────
// Only essential cookies are used, so the consent is informational.
// The choice is stored in localStorage; it can be changed any time.
function renderCookieConsent() {
  const box = document.getElementById('cookieConsent');
  if (!box) return;
  const choice = localStorage.getItem('iot_cookie_consent');
  // Show only if not chosen yet
  box.classList.toggle('show', !choice);

  document.getElementById('cookieText').innerHTML =
    escapeHtml(t('cookie_text')) +
    ` <a href="cookies.php">${escapeHtml(t('cookie_more'))}</a>`;
  document.getElementById('cookieAccept').textContent  = t('cookie_accept');
  document.getElementById('cookieDecline').textContent = t('cookie_decline');
}

function cookieChoice(accepted) {
  localStorage.setItem('iot_cookie_consent', accepted ? 'accepted' : 'necessary');
  localStorage.setItem('iot_cookie_consent_at', new Date().toISOString());
  document.getElementById('cookieConsent').classList.remove('show');
  toast(lang === 'lt' ? 'Pasirinkimas išsaugotas' : 'Choice saved', 'ok');
}

// From the footer "Cookie preferences" — show the bar again so it can be changed.
function reopenCookieConsent() {
  renderCookieConsent();
  document.getElementById('cookieConsent').classList.add('show');
}
// Integrity guard: if the footer disappears or is emptied — it restores it.
(function attributionGuard() {
  // Restores the attribution footer if it is removed or its content cleared.
  function ensure() {
    let f = document.getElementById('attFooter');
    if (!f) {
      f = document.createElement('footer');
      f.className = 'attribution-footer';
      f.id = 'attFooter';
      document.body.appendChild(f);
    }
    const { author } = __att();
    if (!f.textContent.includes(author)) renderAttribution();
    return f;
  }
  // Watches the footer's own content (text/children) for tampering.
  function watchContent(f) {
    new MutationObserver(() => {
      const { author } = __att();
      if (!f.textContent.includes(author)) renderAttribution();
    }).observe(f, { childList: true, characterData: true, subtree: true });
  }
  function start() {
    watchContent(ensure());
    // The footer is a direct child of <body>; a top-level childList observer
    // reacts only if it is removed/replaced — no idle polling, no map-update noise.
    new MutationObserver(() => {
      if (!document.getElementById('attFooter')) watchContent(ensure());
    }).observe(document.body, { childList: true });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();


function toast(msg, type = 'ok') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className   = `show ${type}`;
  setTimeout(() => { t.className = ''; }, 3200);
}

// ─── Dark map style ───────────────────────────────────────
function darkMapStyle() {
  return [
    { elementType:'geometry', stylers:[{color:'#0a0f1e'}] },
    { elementType:'labels.text.stroke', stylers:[{color:'#0a0f1e'}] },
    { elementType:'labels.text.fill', stylers:[{color:'#64748b'}] },
    { featureType:'road', elementType:'geometry', stylers:[{color:'#1a2235'}] },
    { featureType:'road', elementType:'geometry.stroke', stylers:[{color:'#1e2d45'}] },
    { featureType:'road.highway', elementType:'geometry', stylers:[{color:'#1e2d45'}] },
    { featureType:'water', elementType:'geometry', stylers:[{color:'#071020'}] },
    { featureType:'water', elementType:'labels.text.fill', stylers:[{color:'#1a3a5c'}] },
    { featureType:'poi', stylers:[{visibility:'off'}] },
    { featureType:'transit', stylers:[{visibility:'off'}] },
    { featureType:'administrative', elementType:'geometry', stylers:[{color:'#1e2d45'}] },
    { featureType:'administrative.country', elementType:'labels.text.fill', stylers:[{color:'#9aa5b4'}] },
    { featureType:'administrative.locality', elementType:'labels.text.fill', stylers:[{color:'#c4cfdb'}] },
  ];
}

// Render the attribution immediately (independent of Google Maps loading)
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', renderAttribution);
} else {
  renderAttribution();
}
</script>
</body>
</html>
