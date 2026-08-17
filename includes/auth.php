<?php
// ============================================================
// includes/auth.php — shared admin authorization check
//
// Uses the same session as admin.php ($_SESSION['iot_admin']).
// Included in pages/endpoints that require authentication.
// ============================================================

// ── Security headers (shared file, to avoid a double declaration) ──
// setSecurityHeaders() is defined ONLY in includes/security.php. Both auth.php
// and config.php include it via require_once — so the function is declared
// once, regardless of load order.
require_once __DIR__ . '/security.php';

// The session must be started before any output.
if (session_status() === PHP_SESSION_NONE) {
    // Explicit cookie path '/' — so admin.php and api/ (subfolder)
    // share the same session. Without it some servers restrict
    // cookie to the folder and the API would not see the admin login → 401/403.
    if (PHP_SAPI !== 'cli') {
        $params = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => $params['lifetime'],
            'path'     => '/',
            'domain'   => $params['domain'],
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_start();
}

/**
 * ── CSRF apsauga ──────────────────────────────────────────
 * Generates (if not present) and returns the session CSRF token.
 * Use in all admin POST forms as a hidden field.
 */
function csrfToken(): string {
    if (empty($_SESSION['iot_csrf'])) {
        $_SESSION['iot_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['iot_csrf'];
}

/**
 * HTML for the hidden CSRF input field (convenience for forms).
 */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="'
         . htmlspecialchars(csrfToken(), ENT_QUOTES) . '">';
}

/**
 * Checks the submitted CSRF token against the one stored in the session.
 * Uses hash_equals (protection against timing attacks).
 */
function csrfValid(?string $token): bool {
    return is_string($token)
        && !empty($_SESSION['iot_csrf'])
        && hash_equals($_SESSION['iot_csrf'], $token);
}

/**
 * Requires a valid CSRF token in the POST request.
 * If invalid — stops execution with 403.
 */
function requireCsrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && !csrfValid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('CSRF patikra nepavyko. Atnaujinkite puslapį ir bandykite dar kartą. / CSRF check failed.');
    }
}

/**
 * Whether the current visitor is a logged-in administrator.
 */
function isAdminAuthorized(): bool {
    return isset($_SESSION['iot_admin']) && $_SESSION['iot_admin'] === true;
}

/**
 * Protects an HTML page: if not logged in — redirects to includes/admin.php.
 * Use in pages (manage.php) BEFORE any output.
 */
function requireAdminPage(): void {
    if (!isAdminAuthorized()) {
        header('Location: ' . adminFilePath());
        exit;
    }
}

/**
 * Protects an API endpoint: if not logged in — 401 JSON and stop.
 * Naudoti api/sensors.php jautriems veiksmams (delete, clear_readings).
 */
function requireAdminApi(): void {
    if (!isAdminAuthorized()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Neautorizuota. Prisijunkite per includes/admin.php.',
            'code'  => 'unauthorized',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * Resolves the current admin file name. The admin file may be
 * renamed to admin_*.php (for security). Looks for admin_*.php; if absent —
 * returns the default 'admin.php'. The name is stored in includes/admin_file.php.
 */
function adminFileName(): string {
    $marker = __DIR__ . '/admin_file.php';
    if (is_file($marker)) {
        $name = @include $marker;
        if (is_string($name) && preg_match('/^admin_[A-Za-z0-9_-]+\.php$/', $name)
            && is_file(__DIR__ . '/' . $name)) {
            return $name;
        }
    }
    return 'admin.php';
}

/**
 * Admin file path for ROOT pages (index, sensors, cookies, privacy).
 * admin.php lives in the includes/ folder, so from the root the link is
 * "includes/admin.php" (or "includes/admin_XXXX.php" after a rename).
 */
function adminFilePath(): string {
    return 'includes/' . adminFileName();
}

/**
 * Reads the administrator password hash from a separate file.
 * Returns '' if not yet set (first run).
 */
function adminPasswordHash(): string {
    // 1. Naujas formatas: includes/settings.php (asociatyvus masyvas).
    //    Here admin.php writes the password via changeAdminPassword().
    //    IMPORTANT: read the SAME location as admin.php loadAdminHash(),
    //    otherwise the API would reject the correct password (hash read elsewhere).
    $settings = __DIR__ . '/settings.php';
    if (is_file($settings)) {
        $s = @include $settings;
        if (is_array($s) && !empty($s['admin_password_hash'])) {
            return (string)$s['admin_password_hash'];
        }
    }
    // 2. Old admin_pass.php (migration from a previous version).
    $legacy = __DIR__ . '/admin_pass.php';
    if (is_file($legacy)) {
        $h = @include $legacy;
        if (is_string($h) && $h !== '') return $h;
    }
    return '';
}

/**
 * Verifies the administrator password.
 * If the password is not yet set (first run) — returns false
 * (sensitive actions are not allowed until a real password is set).
 */
function verifyAdminPassword(string $password): bool {
    $hash = adminPasswordHash();
    if ($hash === '' || str_contains($hash, 'replacethis')) {
        return false; // password not set yet — disallow deletion
    }
    return password_verify($password, $hash);
}

/**
 * Protects a SENSITIVE API action (delete, clear_readings): requires
 * BOTH a logged-in admin session AND the correct password in the request.
 * The password is passed via the `pass` parameter (POST or GET).
 * This ensures data deletion is always confirmed with a password.
 */
function requireAdminPasswordApi(): void {
    requireAdminApi(); // first — the session

    $pass = $_POST['pass'] ?? $_GET['pass'] ?? '';
    if ($pass === '' || !verifyAdminPassword($pass)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Reikalingas administratoriaus slaptažodis duomenų trynimui.',
            'code'  => 'password_required',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
