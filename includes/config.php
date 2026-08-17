<?php
// ============================================================
// config.php — IoT Sensor Map configuration
//
// This file is rewritten by admin.php during setup. You can also edit it
// manually. After setup the file is protected by .htaccess (Require all denied).
// ============================================================

// ── Production error handling ─────────────────────────────
// Errors are NOT shown in the browser (to avoid revealing paths/data),
// but are written to the server log. Applies to all pages,
// because they all load config.php.
if (!defined('IOT_DEBUG') || !IOT_DEBUG) {
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

// ── Database ──────────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_NAME',    '');           // filled in via admin.php
define('DB_USER',    '');           // filled in via admin.php
define('DB_PASS',    '');           // filled in via admin.php
define('DB_CHARSET', 'utf8mb4');

// ── Google Maps API raktas ────────────────────────────────
define('GMAPS_API_KEY', '');        // filled in via admin.php

// ── Page title (separately LT / EN) ───────────────────────
define('SITE_TITLE_LT', 'IoT Jutiklių Žemėlapis');
define('SITE_TITLE_EN', 'IoT Sensor Map');

// ── Map center — based on the selected country (capital) ──
define('MAP_COUNTRY', 'LT');
define('MAP_CENTER_LAT', 54.6872);
define('MAP_CENTER_LNG', 25.2797);
define('MAP_ZOOM', 12);

// ── OSM map tile provider (when Google Maps is not used) ──
// Values: 'opentopomap' (default), 'carto_voyager', 'carto_light',
// 'osm', 'yandex' (EPSG:3395), 'custom' (custom URL below).
// Configurable, because different networks/regions may reach different
// servers (the OSM tile usage policy recommends allowing provider switching).
define('MAP_TILE_PROVIDER', 'opentopomap');
define('MAP_TILE_URL', ''); // used only when MAP_TILE_PROVIDER='custom'

// ── Kiti ──────────────────────────────────────────────────
define('CITY_PREFIX', 'VLN');
define('SENSOR_TIMEOUT_MIN', 3);
define('CHART_HOURS', 24);

// Data retention time in days (0 = unlimited)
define('DATA_RETENTION_DAYS', 0);

// Public communication/contact email (privacy policy + footer). Filled via admin.php.
define('CONTACT_EMAIL', '');

// ============================================================
//  Helper functions (DB connection, API headers, JSON response)
// ============================================================

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // The MySQL session runs in UTC — NOW()/CURRENT_TIMESTAMP write UTC,
            // and comparisons (DATE_SUB(NOW()...)) are computed in UTC. A single
            // source of truth for time; the browser converts to local time.
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
        ]);
    }
    return $pdo;
}

function setCorsHeaders(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        exit(0);
    }
}

/**
 * Security headers for HTML pages (index, admin, sensors, etc.).
 * CSP allows Google Maps and Chart.js (CDN), but blocks other sources.
 * Call at the start of the page, before any HTML output.
 */
// ── Security headers (shared file, to avoid a double declaration) ──
require_once __DIR__ . '/security.php';


function jsonResponse(mixed $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
