<?php
/**
 * includes/security.php — shared security headers.
 *
 * This file is the ONLY place where setSecurityHeaders() is declared.
 * Both config.php (for public pages) and auth.php (for admin pages)
 * include it via require_once — so the function is declared only once,
 * regardless of load order. This eliminates the "Cannot redeclare"
 * error that occurred when config.php and auth.php each had their own copy.
 *
 * require_once guarantees the file is loaded only once per request.
 */

// The whole system runs in UTC (zone 0). Sensor time is stored in UTC,
// and the browser automatically converts to the user's local time (toLocaleString).
// This removes the ambiguity between the server and browser time zones.
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('UTC');
}

if (!function_exists('setSecurityHeaders')) {
    /**
     * Security headers for HTML pages (index, admin, sensors, etc.).
     * CSP allows Google Maps and Chart.js (CDN), but blocks other sources.
     * Call at the start of the page, before any HTML output.
     */
    function setSecurityHeaders(): void {
        if (headers_sent()) return;
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(self), microphone=(), camera=()');
        // HSTS only over HTTPS (so it does not lock the HTTP dev environment)
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        // CSP: Google Maps + Leaflet/OSM + Chart.js CDN; inline allowed
        header(
            "Content-Security-Policy: " .
            "default-src 'self'; " .
            "script-src 'self' 'unsafe-inline' https://maps.googleapis.com https://maps.gstatic.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; " .
            "img-src 'self' data: https: blob: https://tile.openstreetmap.org https://*.tile.openstreetmap.org https://*.tile.opentopomap.org https://*.basemaps.cartocdn.com https://*.maps.yandex.net; " .
            "connect-src 'self' https://maps.googleapis.com https://maps.gstatic.com https://tile.openstreetmap.org https://*.tile.openstreetmap.org https://*.tile.opentopomap.org https://*.basemaps.cartocdn.com https://*.maps.yandex.net; " .
            "font-src 'self' https://fonts.gstatic.com data:; " .
            "frame-ancestors 'self'; " .
            "base-uri 'self'"
        );
    }
}

/**
 * Returns the EFFECTIVE map provider — a single source of truth for all
 * pages (index.php, cookies.php, privacy.php, admin.php).
 *
 * Values: 'google' | 'opentopomap' | 'carto_voyager' | 'carto_light'
 *           | 'osm' | 'yandex' | 'custom'
 *
 * Logic: MAP_TILE_PROVIDER is the primary choice. 'google' means
 * Google Maps (requires GMAPS_API_KEY). All other values — OpenStreetMap/
 * Leaflet with the corresponding tiles. This way the provider is chosen
 * EXPLICITLY, not by the presence of a key — so cookies/privacy
 * automatically match the actual choice.
 */
function effectiveMapProvider(): string {
    $p = defined('MAP_TILE_PROVIDER') ? (string)MAP_TILE_PROVIDER : '';
    $hasKey = defined('GMAPS_API_KEY') && trim((string)GMAPS_API_KEY) !== '';
    if ($p === 'google') {
        return $hasKey ? 'google' : 'opentopomap'; // be rakto — atsarginis OSM
    }
    if ($p === '') {
        // Backward compatibility: provider not specified → by key presence
        return $hasKey ? 'google' : 'opentopomap';
    }
    return $p; // opentopomap / carto_voyager / carto_light / osm / yandex / custom
}

/** Whether the map uses Google Maps (shows Google cookies/text). */
function mapUsesGoogle(): bool { return effectiveMapProvider() === 'google'; }

/** Whether the map uses Yandex (shows the Yandex privacy note). */
function mapUsesYandex(): bool { return effectiveMapProvider() === 'yandex'; }

/**
 * Converts a MySQL DATETIME ("2026-06-20 14:30:00", stored as UTC) into
 * ISO 8601 with "Z" ("2026-06-20T14:30:00Z"), so the browser knows it is
 * UTC and automatically converts to the user's local time via
 * toLocaleString(). Returns null if the value is empty.
 *
 * @param string|null $mysqlDatetime MySQL DATETIME value (UTC)
 * @return string|null ISO 8601 UTC or null
 */
function toIsoUtc(?string $mysqlDatetime): ?string {
    if ($mysqlDatetime === null || $mysqlDatetime === '') return null;
    // Replace the space with "T" and append "Z" (value is already UTC due to SET time_zone)
    $iso = str_replace(' ', 'T', trim($mysqlDatetime));
    return $iso . 'Z';
}
