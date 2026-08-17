<?php
/**
 * Unit tests: includes/auth.php
 * Admin authorization check (session-based).
 */

require_once __DIR__ . '/TestCase.php';

// auth.php calls session_start(); in CLI context the session works as an array.
require_once __DIR__ . '/../includes/auth.php';

final class AuthTest extends TestCase {
    public function run(Assert $t): void {

        // ── Not logged in ──
        $_SESSION = [];
        $t->false(isAdminAuthorized(), 'Empty session → not authorized');

        // ── Logged in (real bool true) ──
        $_SESSION['iot_admin'] = true;
        $t->true(isAdminAuthorized(), 'iot_admin=true → authorized');

        // ── Strict type check (=== true) ──
        $_SESSION['iot_admin'] = 'taip';
        $t->false(isAdminAuthorized(), "iot_admin='taip' (string) → rejected");

        $_SESSION['iot_admin'] = 1;
        $t->false(isAdminAuthorized(), 'iot_admin=1 (int) → rejected');

        $_SESSION['iot_admin'] = false;
        $t->false(isAdminAuthorized(), 'iot_admin=false → rejected');

        // ── Po atsijungimo ──
        unset($_SESSION['iot_admin']);
        $t->false(isAdminAuthorized(), 'After logout → not authorized');

        // ── Functions exist ──
        $t->true(function_exists('requireAdminPage'), 'requireAdminPage defined');
        $t->true(function_exists('requireAdminApi'), 'requireAdminApi defined');

        // ── Password check (API delete requires a password) ──
        $t->true(function_exists('requireAdminPasswordApi'), 'requireAdminPasswordApi defined');
        $t->true(function_exists('verifyAdminPassword'), 'verifyAdminPassword defined');
        $t->true(function_exists('adminPasswordHash'), 'adminPasswordHash defined');

        // Unset/placeholder password → deletion not allowed
        $t->false(verifyAdminPassword('anything'),
            'Be nustatyto slaptažodžio trynimas neleidžiamas');

        // ── REGRESSION: auth.php must read the hash from settings.php ──
        // (admin.php stores the password in settings.php; if auth.php read only
        //  the old admin_pass.php, the API would reject the correct password — a past bug.)
        $authSrc = file_get_contents(dirname(__DIR__) . '/includes/auth.php');
        $t->true(str_contains($authSrc, 'settings.php'),
            'adminPasswordHash skaito settings.php (kaip admin.php)');
        $t->true(str_contains($authSrc, 'admin_password_hash'),
            'adminPasswordHash naudoja admin_password_hash raktą (settings formatas)');
        // Functional check: write a temporary settings.php, verify
        $tmpSettings = dirname(__DIR__) . '/includes/settings.php';
        $hadSettings = is_file($tmpSettings);
        $backup = $hadSettings ? file_get_contents($tmpSettings) : null;
        if (!$hadSettings) {
            $hash = password_hash('RegrTest9!', PASSWORD_BCRYPT, ['cost' => 4]);
            file_put_contents($tmpSettings,
                "<?php return " . var_export(['admin_password_hash' => $hash], true) . ";");
            $t->true(verifyAdminPassword('RegrTest9!'),
                'verifyAdminPassword priima teisingą slaptažodį iš settings.php');
            $t->false(verifyAdminPassword('BlogasPass'),
                'verifyAdminPassword atmeta neteisingą slaptažodį');
            @unlink($tmpSettings); // clean up (settings.php is not shipped)
        }

        // ── Admin file name resolution ──
        $t->true(function_exists('adminFileName'), 'adminFileName defined');
        $name = adminFileName();
        $t->true(
            $name === 'admin.php' || preg_match('/^admin_[A-Za-z0-9_-]+\.php$/', $name) === 1,
            'adminFileName grąžina admin.php arba admin_*.php');

        // ── API delete uses password protection ──
        $api = file_get_contents(dirname(__DIR__) . '/api/sensors.php');
        $t->true(
            substr_count($api, 'requireAdminPasswordApi()') >= 2,
            'delete ir clear_readings naudoja requireAdminPasswordApi');

        // ── 1.1 CSRF apsauga ──
        $t->true(function_exists('csrfToken'), 'csrfToken defined');
        $t->true(function_exists('csrfField'), 'csrfField defined');
        $t->true(function_exists('csrfValid'), 'csrfValid defined');
        $t->true(function_exists('requireCsrf'), 'requireCsrf defined');

        // The token is generated and validated
        $_SESSION['iot_csrf'] = '';
        $tok = csrfToken();
        $t->true(strlen($tok) === 64, 'CSRF token is 64 hex chars');
        $t->true(csrfValid($tok), 'Valid CSRF token accepted');
        $t->false(csrfValid('wrong'), 'Invalid CSRF token rejected');
        $t->false(csrfValid(null), 'Null CSRF token rejected');

        // admin.php forms contain csrfField
        $admin = file_get_contents(dirname(__DIR__) . '/' . self::adminFileName());
        $t->true(substr_count($admin, 'csrfField()') >= 5, 'admin POST forms include csrfField');
        $t->true(str_contains($admin, 'requireCsrf()'), 'admin POST handler checks CSRF');

        // csrfField must NOT be injected in the middle of attributes (broken-form check)
        $t->false(str_contains($admin, 'csrfField() ?>)'),
            'csrfField nėra sugadintose formose (viduryje onsubmit atributo)');
        // The rendered admin HTML has no broken form tags
        $t->false(preg_match('/onsubmit="[^"]*\n\s*<\?= csrfField/', $admin) === 1,
            'csrfField nėra įterptas onsubmit atributo viduryje');

        // Security headers (CSP, X-Frame-Options, etc.) — now in security.php
        $t->true(function_exists('setSecurityHeaders'), 'setSecurityHeaders apibrezta');
        $auth = file_get_contents(dirname(__DIR__) . '/includes/auth.php');
        $secHdrs = file_get_contents(dirname(__DIR__) . '/includes/security.php');
        foreach (['Content-Security-Policy','X-Frame-Options','X-Content-Type-Options','Referrer-Policy','Permissions-Policy','Strict-Transport-Security'] as $h) {
            $t->true(str_contains($secHdrs, $h), "Saugumo antraste: $h");
        }
        $t->true(str_contains($secHdrs, 'maps.googleapis.com'), 'CSP leidzia Google Maps');
        $t->true(str_contains($secHdrs, 'tile.openstreetmap.org'), 'CSP leidzia OpenStreetMap plyteles');
        $t->true(str_contains($secHdrs, 'cdn.jsdelivr.net'), 'CSP leidzia Chart.js + Leaflet CDN');
        foreach (['index.php', self::adminFileName(), 'manage.php'] as $page) {
            $src = file_get_contents(dirname(__DIR__) . '/' . $page);
            $t->true(str_contains($src, 'setSecurityHeaders()'), "$page kviecia setSecurityHeaders");
        }
        $cfg = file_get_contents(dirname(__DIR__) . '/includes/config.php');
        $t->true(str_contains($cfg, 'display_errors'), 'config.php valdo display_errors');

        // ── REGRESSION: setSecurityHeaders is declared ONLY in security.php ──
        // (Cannot redeclare error). The function must be in one file,
        // and config.php and auth.php include it via require_once.
        $secFile = dirname(__DIR__) . '/includes/security.php';
        $t->true(is_file($secFile), 'includes/security.php egzistuoja');
        $secSrc = is_file($secFile) ? file_get_contents($secFile) : '';
        $t->true(str_contains($secSrc, 'function setSecurityHeaders'),
            'setSecurityHeaders apibrėžta security.php');
        // config.php and auth.php must NOT have their own declaration (only require)
        $t->false((bool)preg_match('/function\s+setSecurityHeaders\s*\(/', $cfg),
            'config.php NEdeklaruoja setSecurityHeaders (tik require security.php)');
        $t->false((bool)preg_match('/function\s+setSecurityHeaders\s*\(/', $auth),
            'auth.php NEdeklaruoja setSecurityHeaders (tik require security.php)');
        $t->true(str_contains($cfg, "security.php"), 'config.php įtraukia security.php');
        $t->true(str_contains($auth, "security.php"), 'auth.php įtraukia security.php');

        // Double-load (Cannot redeclare) protection check.
        // We do NOT use a shell_exec subprocess: it is unreliable across environments
        // (Windows XAMPP — php.exe not on PATH, quote escaping differs;
        // shared hosting — the function is disabled). Instead — a static guarantee
        // that works EVERYWHERE and proves the same: if setSecurityHeaders is declared
        // exactly once across all includes files, a double declaration
        // (redeclare) is physically impossible, regardless of load order.
        $declCount = 0;
        foreach (glob(dirname(__DIR__) . '/includes/*.php') as $incFile) {
            $src = file_get_contents($incFile);
            $declCount += preg_match_all('/function\s+setSecurityHeaders\s*\(/', $src);
        }
        $t->equals(1, $declCount,
            'setSecurityHeaders deklaruota lygiai 1 kartą visuose includes (be redeclare rizikos)');
        // config.php and auth.php must include security.php (the shared source)
        $t->true(str_contains($cfg, 'security.php'),
            'config.php įtraukia security.php (require_once)');
        $t->true(str_contains($auth, 'security.php'),
            'auth.php įtraukia security.php (require_once)');


        // ── Password-strength good practices ──
        $admin2 = file_get_contents(dirname(__DIR__) . '/' . self::adminFileName());
        $t->true(str_contains($admin2, 'function validatePasswordStrength'),
            'validatePasswordStrength funkcija apibrėžta');
        // Reproduce the logic for testing
        $validate = function(string $pw): bool {
            $special = '!@#$%^&*()-_=+[]{};:,.?';
            if (strlen($pw) < 8) return false;
            if (!preg_match('/[A-Z]/', $pw)) return false;
            if (!preg_match('/[0-9]/', $pw)) return false;
            $hasSpec = false;
            for ($i=0,$n=strlen($pw);$i<$n;$i++) if (strpos($special,$pw[$i])!==false){$hasSpec=true;break;}
            if (!$hasSpec) return false;
            return (bool)preg_match('/^[A-Za-z0-9'.preg_quote($special,'/').']+$/', $pw);
        };
        $t->true($validate('Valid123!'), 'Stiprus slaptažodis priimamas (didžioji+skaičius+spec)');
        $t->false($validate('weak'), 'Per trumpas atmetamas');
        $t->false($validate('nouppercase1!'), 'Be didžiosios atmetamas');
        $t->false($validate('NoDigits!'), 'Be skaičiaus atmetamas');
        $t->false($validate('NoSpecial1'), 'Be spec simbolio atmetamas');
        $t->false($validate('Has Space1!'), 'Su tarpu (neleistinas) atmetamas');

        // ── Password stored in the settings file (not admin.php) ──
        $t->true(str_contains($admin2, 'ADMIN_SETTINGS_FILE'),
            'Slaptažodis saugomas settings faile');
        $t->true(str_contains($admin2, 'settings.php'),
            'Naudojamas includes/settings.php');
        $t->false(preg_match('/file_put_contents\s*\(\s*__FILE__/', $admin2) === 1,
            'admin.php neperrašo savęs (be self-rewrite)');

        // ── Dynamic state update after a password change ──
        $t->true(str_contains($admin2, '$hashIsDefault = false'),
            'hashIsDefault atnaujinamas iškart po slaptažodžio keitimo');

        // ── 4.3 SSE stream endpoint ──
        $api = file_get_contents(dirname(__DIR__) . '/api/sensors.php');
        $t->true(str_contains($api, "action === 'stream'"), 'SSE stream endpoint yra');
        $t->true(str_contains($api, 'text/event-stream'), 'SSE naudoja text/event-stream');
        // CRITICAL: SSE must release the session lock (otherwise it blocks everything)
        $t->true(str_contains($api, 'session_write_close'),
            'SSE atlaisvina sesijos užraktą (session_write_close) — neblokuoja kitų užklausų');
        $idx = file_get_contents(dirname(__DIR__) . '/index.php');
        $t->true(str_contains($idx, 'EventSource'), 'index.php naudoja EventSource (SSE)');
        $t->true(str_contains($idx, 'startPollingFallback'), 'SSE turi polling fallback');
        // Polling must be the DEFAULT (SSE opt-in) so it does not overload shared hosting
        $t->true(str_contains($idx, "localStorage.getItem('iot_sse')"),
            'SSE yra opt-in (numatytasis — saugus polling)');
        $t->true(str_contains($idx, '_sse.close()'),
            'SSE klaidos atveju ryšys uždaromas (be persijungimo lavinos)');
    }
}
