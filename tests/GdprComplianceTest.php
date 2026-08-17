<?php
/**
 * GDPR compliance tests.
 *
 * Based on public guidance (CookieYes, Cookie Information,
 * Clym, ePrivacy directive). Checks:
 *   - the existence and content of a privacy/cookie policy page,
 *   - that no tracking/analytics cookies are used before consent,
 *   - that session cookies are secure (HttpOnly, SameSite),
 *   - data minimization (no names/emails collected),
 *   - security measures (HTTPS recommendation, headers),
 *   - the statement of user rights and the data controller.
 */

require_once __DIR__ . '/TestCase.php';

final class GdprComplianceTest extends TestCase {
    private function root(): string {
        return dirname(__DIR__);
    }

    private function read(string $rel): string {
        $path = $this->root() . '/' . $rel;
        return file_exists($path) ? file_get_contents($path) : '';
    }

    public function run(Assert $t): void {
        $root = $this->root();

        // ── 1. Privacy policy page exists ──
        $t->true(file_exists("$root/privacy.php"),
            'privacy.php (privatumo/slapukų politika) egzistuoja');

        $privacy = $this->read('privacy.php');
        $t->true($privacy !== '', 'privacy.php nėra tuščias');

        // ── 2. Politika apima privalomas BDAR dalis ──
        // (data controller, what data, legal basis,
        //  retention, third parties, rights, security)
        $required = [
            'lt' => ['valdytojas', 'Teisinis pagrindas', 'saugoj', 'Trečios', 'teis', 'Saugumas', 'slapuk'],
            'en' => ['controller', 'Legal basis', 'retention', 'Third', 'rights', 'Security', 'ookie'],
        ];
        foreach ($required['lt'] as $kw) {
            $t->contains($kw, $privacy, "LT politika apima: '$kw'");
        }
        foreach ($required['en'] as $kw) {
            $t->contains($kw, $privacy, "EN politika apima: '$kw'");
        }

        // ── 3. GDPR rights listed ──
        $t->true(
            stripos($privacy, 'BDAR') !== false || stripos($privacy, 'GDPR') !== false,
            'Politika mini BDAR/GDPR');
        $t->true(
            stripos($privacy, 'ištrint') !== false || stripos($privacy, 'eras') !== false,
            'Mini teisę ištrinti duomenis');
        $t->true(
            stripos($privacy, 'VDAI') !== false || stripos($privacy, 'supervisory') !== false,
            'Mini priežiūros instituciją (VDAI)');

        // ── 4. Slapukai dokumentuoti ──
        // ── 4. Slapukai dokumentuoti (atskirame cookies.php) ──
        $cookiesPage = $this->read('cookies.php');
        foreach (['PHPSESSID', 'iot_lang', 'iot_filters'] as $cookie) {
            $t->contains($cookie, $cookiesPage, "Slapukas/saugykla dokumentuota cookies.php: $cookie");
        }
        // Google Maps third-party cookies documented (shown only with a key)
        $t->true(str_contains($cookiesPage, 'NID') && str_contains($cookiesPage, 'CONSENT'),
            'Google Maps slapukai dokumentuoti cookies.php (NID, CONSENT — sąlyginiai)');

        // Cookies/privacy adapt to the EFFECTIVE provider (not by key presence).
        $security = $this->read('includes/security.php');
        $t->true(str_contains($security, 'function effectiveMapProvider'),
            'effectiveMapProvider() — vienas tiesos šaltinis tiekėjui');
        $t->true(str_contains($cookiesPage, '$usesYandex') || str_contains($cookiesPage, 'usesYandex'),
            'cookies.php turi Yandex šaką (slapukai keičiasi pagal tiekėją)');
        $privacyPage = $this->read('privacy.php');
        $t->true(str_contains($privacyPage, 'effectiveMapProvider') || str_contains($privacyPage, 'usesYandex'),
            'privacy.php pritaikomas pagal efektyvų tiekėją');
        // The privacy page links to the cookie policy (not a table)
        $t->contains('cookies.php', $privacy, 'Privacy nukreipia į cookies.php');

        // ── 4b. The policy changes per map provider (Google/OSM) ──
        // privacy.php and cookies.php have a conditional block for both providers.
        $t->true(str_contains($privacy, 'usesGmaps'),
            'privacy.php turi tiekėjo sąlygą (usesGmaps)');
        $t->true(str_contains($privacy, 'OpenStreetMap') && str_contains($privacy, 'Google Maps'),
            'privacy.php aprašo abu tiekėjus (Google Maps ir OpenStreetMap)');
        $t->true(str_contains($cookiesPage, 'usesGmaps'),
            'cookies.php turi tiekėjo sąlygą (usesGmaps)');
        $t->true(str_contains($cookiesPage, 'tile.openstreetmap.org') && str_contains($cookiesPage, 'maps.googleapis.com'),
            'cookies.php aprašo abiejų tiekėjų serverius');
        $t->true(str_contains($privacy, 'osmfoundation.org') && str_contains($privacy, 'policies.google.com'),
            'privacy.php nuorodos į abiejų tiekėjų politikas');

        // ── 5. Only essential cookies — no tracking/analytics ──
        // We check that the code does not use Google Analytics, Facebook Pixel, etc.
        $allCode = $this->read('index.php') . $this->read(self::adminFileName())
                 . $this->read('manage.php') . $this->read('tests.php');
        $trackers = ['google-analytics', 'googletagmanager', 'gtag(', 'fbq(', 'facebook.net',
                     'hotjar', 'mixpanel', 'segment.com', 'doubleclick'];
        foreach ($trackers as $tr) {
            $t->false(stripos($allCode, $tr) !== false,
                "Nenaudojamas sekimo įrankis: $tr");
        }

        // ── 6. Session cookie is secure (HttpOnly, SameSite, Secure with HTTPS) ──
        $auth = $this->read('includes/auth.php');
        $t->contains("'httponly' => true", $auth, 'Sesijos slapukas HttpOnly');
        $t->contains("'samesite'", $auth, 'Sesijos slapukas turi SameSite');
        $t->contains("'secure'", $auth, 'Sesijos slapukas Secure (su HTTPS)');

        // ── 7. Data minimization — no visitor personal data collected ──
        // The sensor/readings schema must not have name/email/phone columns.
        // The admin_credentials table is excluded: it stores ONLY a one-way
        // hash of the admin login name (an auth credential, not visitor PII).
        $schema = $this->read('schema.sql');
        $schemaNoAdmin = preg_replace(
            '/CREATE TABLE IF NOT EXISTS admin_credentials.*?utf8mb4_unicode_ci;/s', '', $schema);
        foreach (['email', 'first_name', 'last_name', 'phone', 'password'] as $pii) {
            $t->false(stripos($schemaNoAdmin, $pii) !== false,
                "Schema nerenka asmens duomenų: $pii");
        }
        // The admin login name must be stored ONLY as a hash (never plaintext).
        // schema.sql may be hidden/auto-removed in production once the schema is
        // installed, so the credential design is verified against the installer in
        // admin.php (always present), falling back to schema.sql when available.
        $installer = $this->read(self::adminFileName());
        $hasEmailHash = str_contains($installer, 'email_hash') || str_contains($schema, 'email_hash');
        $t->true($hasEmailHash, 'Admin prisijungimo vardas saugomas tik kaip hash (email_hash)');

        // ── 8. Security measures declared ──
        $htaccess = $this->read('.htaccess');
        $t->contains('X-Content-Type-Options', $htaccess, 'X-Content-Type-Options nustatyta');
        $t->true(
            stripos($htaccess, 'Strict-Transport-Security') !== false,
            'HSTS šablonas yra (HTTPS rekomendacija)');

        // CSP must have ONE source (PHP security.php), NOT .htaccess —
        // two CSP headers apply as an intersection and block map tiles.
        $security = $this->read('includes/security.php');
        $t->contains('Content-Security-Policy', $security, 'CSP nustatyta PHP pusėje (security.php)');
        $t->true(
            !preg_match('/^\s*Header\s+set\s+Content-Security-Policy/im', $htaccess),
            '.htaccess NEnustato CSP (kad nekonfliktuotų su PHP CSP ir neblokuotų plytelių)');

        // ── 9. Sensitive files protected ──
        $t->contains('config.php', $htaccess, 'config.php apsaugotas šakniniame .htaccess');

        // The includes/ folder (with config.php holding the DB password) must be
        // fully denied by a separate includes/.htaccess file.
        $incHtaccess = $this->read('includes/.htaccess');
        $t->true(
            stripos($incHtaccess, 'Require all denied') !== false
            || stripos($incHtaccess, 'Deny from all') !== false,
            'includes/.htaccess draudžia tiesioginę prieigą (config.php su DB slaptažodžiu apsaugotas)');

        // admin.php lives in the includes/ folder — .htaccess must ALLOW admin*.php
        // (an entry point, itself password-protected), but STILL deny
        // the admin_file.php marker (otherwise the hidden admin name would leak).
        $t->true(str_contains($incHtaccess, 'FilesMatch') && stripos($incHtaccess, 'admin') !== false,
            'includes/.htaccess turi išimtį admin puslapiui (admin saugomas includes/)');
        $t->true(stripos($incHtaccess, 'admin_file.php') !== false,
            'includes/.htaccess papildomai draudžia admin_file.php žymeklį');
        // Regex with a negative lookahead: allows admin/admin_X, denies admin_file
        $t->true(preg_match('/admin\(\?\!_file/', $incHtaccess) === 1,
            'includes/.htaccess naudoja lookahead, kad admin_file.php nepatektų į išimtį');

        // The admin file is PHYSICALLY in the includes/ folder
        $t->true(is_file(dirname(__DIR__) . '/' . self::adminFileName()),
            'admin failas yra includes/ kataloge');
        $t->true(str_starts_with(self::adminFileName(), 'includes/'),
            'adminFileName() grąžina includes/ kelią');

        // config.php must not output content even if PHP executed it (only define/functions)
        $configRaw = $this->read('includes/config.php');
        $t->true(str_starts_with($configRaw, '<?php'),
            'config.php prasideda <?php be tarpų (jokio nutekėjimo prieš PHP)');

        // ── 10. Privacy link reachable from the main page ──
        $index = $this->read('index.php');
        $t->contains('privacy.php', $index, 'Nuoroda į privatumo politiką index.php');

        // ── 11. Data retention/deletion policy described ──
        $t->true(
            stripos($privacy, '3 min') !== false || stripos($privacy, '3 minutes') !== false,
            'Aprašyta nepatvirtintų jutiklių automatinė trynimo politika');

        // ── 12. Third-party (Google Maps) disclosure ──
        $t->true(
            stripos($privacy, 'Google Maps') !== false,
            'Atskleista trečioji šalis (Google Maps)');

        // ── 13. Cookie consent bar in index.php ──
        $t->contains('cookieConsent', $index, 'Slapukų sutikimo juosta yra index.php');
        $t->contains('iot_cookie_consent', $index, 'Sutikimas saugomas localStorage');
        $t->contains('cookieChoice', $index, 'Sutikimo pasirinkimo funkcija yra');

        // ── 13b. Separate cookies page ──
        $t->true(file_exists("$root/cookies.php"), 'Atskiras cookies.php puslapis egzistuoja');
        $cookies = $this->read('cookies.php');
        $t->contains('saveConsent', $cookies, 'cookies.php turi sutikimo valdiklį');
        $t->contains('resetConsent', $cookies, 'cookies.php leidžia atstatyti pasirinkimą');
        $t->contains('PHPSESSID', $cookies, 'cookies.php dokumentuoja slapukus');

        // ── 13c. Cookie link on ALL pages ──
        foreach (['index.php', self::adminFileName(), 'manage.php', 'tests.php', 'privacy.php', 'cookies.php'] as $page) {
            $code = $this->read($page);
            // cookies.php is itself the cookie page — a control is enough
            $hasLink = stripos($code, 'cookies.php') !== false
                    || stripos($code, 'saveConsent') !== false;
            $t->true($hasLink, "Slapukų valdymas pasiekiamas iš: $page");
        }

        // ── 15. Navigation structure ──
        // Public pages have links to the map, privacy, cookies
        foreach (['privacy.php', 'cookies.php'] as $page) {
            $code = $this->read($page);
            $t->contains('index.php', $code, "$page turi nuorodą į žemėlapį");
        }
        // Admin pages have full navigation (map + sensors + privacy + cookies)
        $managePage = $this->read('manage.php');
        $t->contains('index.php', $managePage, 'manage.php turi nuorodą į žemėlapį');
        $t->contains('privacy.php', $managePage, 'manage.php turi nuorodą į privatumą');
        $t->contains('cookies.php', $managePage, 'manage.php turi nuorodą į slapukus');

        $testsPage = $this->read('tests.php');
        $t->contains('index.php', $testsPage, 'tests.php turi nuorodą į žemėlapį');
        $t->contains('manage.php', $testsPage, 'tests.php turi nuorodą į jutiklius');

        // Admin has links to all pages
        $adminFull = $this->read(self::adminFileName());
        foreach (['index.php', 'manage.php', 'tests.php', 'privacy.php', 'cookies.php'] as $link) {
            $t->contains($link, $adminFull, "admin turi nuorodą į: $link");
        }

        // ── 14. Admin brute-force apsauga ──
        $admin = $this->read(self::adminFileName());
        $t->contains('MAX_LOGIN_ATTEMPTS', $admin, 'Admin turi bandymų ribą');
        $t->contains('LOGIN_BLOCK_MINUTES', $admin, 'Admin turi IP blokavimo laiką');
        $t->contains('recordFailedLogin', $admin, 'Nesėkmingi bandymai registruojami');
        $t->contains('loginBlockSeconds', $admin, 'IP blokavimo patikra yra');
    }
}
