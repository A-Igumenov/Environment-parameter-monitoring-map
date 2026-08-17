<?php
// ============================================================
// admin.php  —  IoT Sensor Map — Setup administration page
//
// Funkcijos:
//   1. Password login (session)
//   2. Writing the DB configuration to includes/config.php
//   3. Saving the Google Maps API key
//   4. Loading the DB schema into MySQL (CREATE TABLE, EVENT)
//   5. Testing the DB connection
//
// IMPORTANT: after a successful setup it is recommended to:
//   a) Rename or delete this file
//   b) Or apply an IP restriction in .htaccess
// ============================================================

// Start the session with a consistent cookie path '/' (via auth.php),
// so that admin.php and api/ share the same session.
require_once __DIR__ . '/auth.php';

// Capital list for map centering (capitalList, capitalCoords)
require_once __DIR__ . '/cities.php';
if (function_exists('setSecurityHeaders')) setSecurityHeaders();

// ── Administrator password (in the settings file) ────────
// The password hash is stored in a SEPARATE settings file
// includes/settings.php (not admin.php), so it survives even
// when the admin file is renamed, is not lost when config.php is rewritten,
// and is protected via includes/.htaccess. The file is created
// automatically the first time the password is changed.
define('ADMIN_SETTINGS_FILE', __DIR__ . '/settings.php');
// Backward compatibility: the old admin_pass.php file (if still present)
define('ADMIN_PASS_FILE_LEGACY', __DIR__ . '/includes/admin_pass.php');

function loadAdminHash(): string {
    // 1. Naujas settings.php formatas (asociatyvus masyvas)
    if (is_file(ADMIN_SETTINGS_FILE)) {
        $s = @include ADMIN_SETTINGS_FILE;
        if (is_array($s) && !empty($s['admin_password_hash'])) {
            return (string)$s['admin_password_hash'];
        }
    }
    // 2. Old admin_pass.php (migration from a previous version)
    if (is_file(ADMIN_PASS_FILE_LEGACY)) {
        $h = @include ADMIN_PASS_FILE_LEGACY;
        if (is_string($h) && $h !== '') return $h;
    }
    // 3. Default (first run) — any password allows entry
    return '$2y$12$replacethiswithyourownbcrypthashgeneratedbelow';
}

define('ADMIN_PASSWORD_HASH', loadAdminHash());

// ── Keliai ────────────────────────────────────────────────
define('CONFIG_PATH', __DIR__ . '/config.php');
define('SCHEMA_PATH', __DIR__ . '/../schema.sql');
define('HTACCESS_PATH', __DIR__ . '/.htaccess');

/**
 * Checks password strength against good practices:
 *  - bent 8 simboliai
 *  - at least one UPPERCASE letter
 *  - at least one digit
 *  - at least one special character from the allowed set
 * Returns true if valid, or an error message (string).
 */
function validatePasswordStrength(string $pw, string $lang = 'lt'): true|string {
    $lt = ($lang === 'lt');
    $allowedSpecial = '!@#$%^&*()-_=+[]{};:,.?';
    if (strlen($pw) < 8) {
        return $lt ? 'Slaptažodis turi būti bent 8 simbolių.'
                   : 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Z]/', $pw)) {
        return $lt ? 'Slaptažodyje turi būti bent viena didžioji raidė (A–Z).'
                   : 'Password must contain at least one uppercase letter (A–Z).';
    }
    if (!preg_match('/[0-9]/', $pw)) {
        return $lt ? 'Slaptažodyje turi būti bent vienas skaičius (0–9).'
                   : 'Password must contain at least one digit (0–9).';
    }
    // A special character from the allowed set
    $hasSpecial = false;
    for ($i = 0, $n = strlen($pw); $i < $n; $i++) {
        if (strpos($allowedSpecial, $pw[$i]) !== false) { $hasSpecial = true; break; }
    }
    if (!$hasSpecial) {
        return $lt ? 'Slaptažodyje turi būti bent vienas specialus simbolis: ' . $allowedSpecial
                   : 'Password must contain at least one special character: ' . $allowedSpecial;
    }
    // Only allowed characters (letters, digits, allowed specials)
    if (!preg_match('/^[A-Za-z0-9' . preg_quote($allowedSpecial, '/') . ']+$/', $pw)) {
        return $lt ? 'Slaptažodyje yra neleistinų simbolių. Leidžiama: raidės, skaičiai ir ' . $allowedSpecial
                   : 'Password contains disallowed characters. Allowed: letters, digits and ' . $allowedSpecial;
    }
    return true;
}

/**
 * Computes the bcrypt hash of the new password and writes it
 * to the settings file includes/settings.php (not to admin.php).
 * Applies password-strength good practices.
 */
function changeAdminPassword(string $newPassword, string $lang = 'lt'): true|string {
    // Strength check (good practices)
    $strength = validatePasswordStrength($newPassword, $lang);
    if ($strength !== true) {
        return $strength;
    }
    $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

    // Preserve existing settings (if any) so we do not overwrite other values
    $settings = [];
    if (is_file(ADMIN_SETTINGS_FILE)) {
        $existing = @include ADMIN_SETTINGS_FILE;
        if (is_array($existing)) $settings = $existing;
    }
    $settings['admin_password_hash'] = $hash;
    $settings['password_changed_at'] = date('c');

    // A safe PHP file returning the settings array. var_export escapes quotes.
    $content = "<?php\n"
             . "// IoT Sensor Map — administrator settings.\n"
             . "// Generated automatically — do not edit by hand.\n"
             . "// Protected via includes/.htaccess (Require all denied).\n"
             . "return " . var_export($settings, true) . ";\n";

    if (file_put_contents(ADMIN_SETTINGS_FILE, $content, LOCK_EX) === false) {
        return $lang === 'lt'
            ? 'Nepavyko įrašyti į includes/settings.php — patikrinkite includes/ katalogo rašymo teises.'
            : 'Failed to write includes/settings.php — check includes/ directory write permissions.';
    }
    @chmod(ADMIN_SETTINGS_FILE, 0644);

    // Remove the old admin_pass.php after a successful migration
    if (is_file(ADMIN_PASS_FILE_LEGACY)) {
        @unlink(ADMIN_PASS_FILE_LEGACY);
    }

    return true;
}

// ── Admin email (username) + credential helpers ───────────
// The email is the login username. Its bcrypt hash is stored in the DB
// (table admin_credentials), unlike the password hash which lives in
// includes/settings.php. All DB access is guarded — on the first run the
// database may not be configured yet.

/** Normalizes an email for hashing/verification (lowercase + trim). */
function normalizeEmail(string $email): string {
    return strtolower(trim($email));
}

/** Basic email validation. Returns true or an error string. */
function validateAdminEmail(string $email, string $lang = 'lt'): true|string {
    $email = normalizeEmail($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $lang === 'lt' ? 'Neteisingas el. pašto formatas.' : 'Invalid email format.';
    }
    if (strlen($email) > 190) {
        return $lang === 'lt' ? 'El. paštas per ilgas.' : 'Email is too long.';
    }
    return true;
}

/**
 * Builds a PDO connection from the current config (null if unavailable).
 * admin.php is the setup tool and does not load config.php / define db(),
 * so credential helpers use this dedicated connection (same approach as
 * schemaStatus()). Cached for the request.
 */
function adminDb(): ?PDO {
    static $pdo = null;
    static $tried = false;
    if ($tried) return $pdo;
    $tried = true;
    $cfg = readCurrentConfig();
    if (empty($cfg['DB_NAME']) || empty($cfg['DB_USER'])) return null;
    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
            $cfg['DB_HOST'] ?? 'localhost', $cfg['DB_NAME']);
        $pdo = new PDO($dsn, $cfg['DB_USER'], $cfg['DB_PASS'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 3,
        ]);
    } catch (\Throwable) {
        $pdo = null;
    }
    return $pdo;
}

/** Reads the stored admin email hash from the DB ('' if none / DB down). */
function adminEmailHash(): string {
    $pdo = adminDb();
    if (!$pdo) return '';
    try {
        $row = $pdo->query("SELECT email_hash FROM admin_credentials WHERE id = 1")->fetch();
        return $row && !empty($row['email_hash']) ? (string)$row['email_hash'] : '';
    } catch (\Throwable) {
        return '';
    }
}

/** Whether an admin email (username) has been set. */
function adminEmailIsSet(): bool {
    return adminEmailHash() !== '';
}

/** Stores the admin email as a bcrypt hash in the DB. Returns true or an error. */
function setAdminEmail(string $email, string $lang = 'lt'): true|string {
    $valid = validateAdminEmail($email, $lang);
    if ($valid !== true) return $valid;
    $pdo = adminDb();
    if (!$pdo) {
        return $lang === 'lt' ? 'Duomenų bazė nepasiekiama (pirma sukonfigūruokite DB ir įdiekite schemą).'
                              : 'Database unavailable (configure the DB and install the schema first).';
    }
    $hash = password_hash(normalizeEmail($email), PASSWORD_BCRYPT);
    try {
        $pdo->prepare(
            "INSERT INTO admin_credentials (id, email_hash) VALUES (1, ?)
             ON DUPLICATE KEY UPDATE email_hash = VALUES(email_hash)"
        )->execute([$hash]);
        return true;
    } catch (\Throwable) {
        return $lang === 'lt' ? 'Nepavyko įrašyti el. pašto į DB.' : 'Failed to store the email in the DB.';
    }
}

/** Verifies an entered email against the stored hash. */
function verifyAdminEmail(string $email): bool {
    $hash = adminEmailHash();
    if ($hash === '') return false;
    return password_verify(normalizeEmail($email), $hash);
}

/** Whether a password change is required (set after a 24h stage-2 lockout). */
function passwordChangeRequired(): bool {
    $pdo = adminDb();
    if (!$pdo) return false;
    try {
        $row = $pdo->query("SELECT password_change_required FROM admin_credentials WHERE id = 1")->fetch();
        return $row && (int)($row['password_change_required'] ?? 0) === 1;
    } catch (\Throwable) {
        return false;
    }
}

/** Sets or clears the password-change-required flag. */
function setPasswordChangeRequired(bool $required): void {
    $pdo = adminDb();
    if (!$pdo) return;
    try {
        $pdo->prepare("UPDATE admin_credentials SET password_change_required = ? WHERE id = 1")
            ->execute([$required ? 1 : 0]);
    } catch (\Throwable) { /* ignore */ }
}

/** Writes a security event to the audit_log (admin-visible, deletable). */
function logSecurityEvent(string $action, ?string $ip = null, ?string $details = null): void {
    $pdo = adminDb();
    if (!$pdo) return;
    try {
        $pdo->prepare("INSERT INTO audit_log (actor_ip, action, details) VALUES (?, ?, ?)")
            ->execute([$ip, $action, $details]);
    } catch (\Throwable) { /* ignore */ }
}

/**
 * Renames the admin file to a user-chosen admin_<name>.php.
 * The "admin_" prefix is required. The new name is written to
 * includes/admin_file.php so other pages know the link.
 * Returns the new name (string) or an ['error'=>...] array.
 */
function renameAdminFile(string $userPart): string|array {
    $userPart = preg_replace('/[^A-Za-z0-9_-]/', '', $userPart);
    if ($userPart === '') {
        return ['error' => 'Pavadinimas negali būti tuščias (tik raidės, skaičiai, _ , -).'];
    }
    $newName = 'admin_' . $userPart . '.php';
    $dir     = __DIR__;
    $newPath = $dir . '/' . $newName;
    $current = realpath(__FILE__);

    if (basename($current) === $newName) {
        return ['error' => 'Failas jau taip pavadintas.'];
    }
    if (file_exists($newPath)) {
        return ['error' => "Failas {$newName} jau egzistuoja — pasirinkite kitą vardą."];
    }
    if (!@copy($current, $newPath)) {
        return ['error' => 'Nepavyko sukurti naujo failo — patikrinkite katalogo rašymo teises.'];
    }
    @chmod($newPath, 0644);

    // Write the new name to the marker
    $marker = $dir . '/admin_file.php';
    $markerContent = "<?php\n// Current admin file name. Generated automatically.\nreturn " . var_export($newName, true) . ";\n";
    @file_put_contents($marker, $markerContent, LOCK_EX);

    // Remove the old admin file
    @unlink($current);

    return $newName;
}

// ── .htaccess IP protection block (markers) ──────────────
const IP_BLOCK_BEGIN = '# BEGIN IOT-ADMIN-IP (managed automatically by admin.php)';
const IP_BLOCK_END   = '# END IOT-ADMIN-IP';

/**
 * The visitor's IP (the one Apache sees).
 */
function clientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Reads the list of currently allowed IPs from the .htaccess block.
 * Returns [] if protection is not enabled.
 */
function readAllowedIps(): array {
    if (!file_exists(HTACCESS_PATH)) return [];
    $content = file_get_contents(HTACCESS_PATH);
    // Anchor on the stable '# BEGIN IOT-ADMIN-IP' prefix (ignore the parenthetical
    // annotation) so blocks written by older versions are still detected.
    if (!preg_match('/# BEGIN IOT-ADMIN-IP[^\n]*(.*?)' . preg_quote(IP_BLOCK_END, '/') . '/s', $content, $m)) {
        return [];
    }
    preg_match_all('/Require ip\s+(\S+)/', $m[1], $ips);
    return $ips[1] ?? [];
}

/**
 * Writes the IP list to .htaccess (creates/updates the block).
 * An empty list — the block is removed (protection disabled).
 */
function writeAllowedIps(array $ips): true|string {
    $ips = array_values(array_unique(array_filter($ips,
        fn($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false
    )));

    // Localhost is always included — so local testing (XAMPP) works
    // and the user does not lock themselves out when disabling/changing the IP list.
    foreach (['127.0.0.1', '::1'] as $lh) {
        if (!in_array($lh, $ips, true)) array_unshift($ips, $lh);
    }

    $content = file_exists(HTACCESS_PATH) ? file_get_contents(HTACCESS_PATH) : '';

    // Remove the old block (anchored on the stable prefix so blocks written by
    // older versions, with a different parenthetical, are also removed).
    $content = preg_replace(
        '/\n?# BEGIN IOT-ADMIN-IP[^\n]*.*?' . preg_quote(IP_BLOCK_END, '/') . '\n?/s',
        '',
        $content
    );

    // Always write the block (with localhost). Protection is "on" when, besides
    // localhost, there is at least one IP; "off" means localhost only.
    // Wrapped in <IfModule> so it does not throw 500 if the module is disabled.
    $requires = implode("\n", array_map(fn($ip) => "            Require ip {$ip}", $ips));
    $block = "\n" . IP_BLOCK_BEGIN . "\n"
           . "<IfModule mod_authz_core.c>\n"
           . "    <Files \"admin.php\">\n"
           . "        <RequireAny>\n"
           . $requires . "\n"
           . "        </RequireAny>\n"
           . "    </Files>\n"
           . "</IfModule>\n"
           . IP_BLOCK_END . "\n";
    $content = rtrim($content) . "\n" . $block;

    $written = file_put_contents(HTACCESS_PATH, $content);
    return $written !== false ? true : 'Nepavyko rašyti į .htaccess — patikrinkite failo teises.';
}

// ── Logout ────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . basename(__FILE__));
    exit;
}

// ── Kalba (LT/EN) ─────────────────────────────────────────
if (isset($_GET['lang']) && in_array($_GET['lang'], ['lt', 'en'], true)) {
    $_SESSION['iot_admin_lang'] = $_GET['lang'];
    // Remove lang from the URL after setting it
    header('Location: ' . basename(__FILE__));
    exit;
}
$adminLang = $_SESSION['iot_admin_lang']
          ?? (str_starts_with($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', 'lt') ? 'lt' : 'en');

// Translations dictionary
$L = [
  'lt' => [
    'admin_subtitle'   => 'Sąrankos administravimo puslapis',
    'password'         => 'Slaptažodis',
    'login'            => 'Prisijungti',
    'wrong_password'   => 'Neteisingas slaptažodis.',
    'setup_mode'       => 'Pirmojo paleidimo režimas — nustatykite tikrą slaptažodį',
    'setup_login_warn' => 'Pirmojo paleidimo režimas — slaptažodis dar nenustatytas. Prisijunkite bet kuriuo slaptažodžiu, tada nustatykite tikrą 4 žingsnyje.',
    'logout'           => 'Atsijungti',
    'setup_title'      => 'IoT Sensor Map — Sąranka',
    'step1_title'      => 'Duomenų bazė ir Google Maps',
    'step1_sub'        => 'MySQL prisijungimo duomenys ir Maps API raktas',
    'db_host'          => 'DB serveris (host)',
    'db_name'          => 'DB pavadinimas',
    'db_user'          => 'DB vartotojas',
    'db_pass'          => 'DB slaptažodis',
    'gmaps_key'        => 'Google Maps API raktas',
    'site_title_lt'    => 'Pavadinimas (LT versija)',
    'site_title_en'    => 'Pavadinimas (EN versija)',
    'site_title_hint'  => 'Rodomas naršyklės kortelėje ir antraštėje. Žemėlapio puslapis automatiškai parodo versiją pagal pasirinktą kalbą.',
    'prefix_example'   => 'Pvz. VLN → VLN1, VLN2... (kai automatinis miestas nepavyksta)',
    'cfg_writable'     => 'rašymas leidžiamas',
    'cfg_denied'       => 'rašymas DRAUDŽIAMAS — patikrinkite teises (chmod 644)',
    'cfg_path'         => 'Kelias',
    'dyn_ip_title'     => 'Dinaminis IP:',
    'dyn_ip_body'      => 'jei jūsų interneto tiekėjas keičia IP (dauguma namų ryšių), po IP pasikeitimo admin puslapis taps nepasiekiamas. Atrakinimas: per FTP/File Manager ištrinkite bloką tarp',
    'dyn_ip_tail'      => '.htaccess faile.',
    'schema_assume'    => 'Prielaida:',
    'schema_assume_t'  => 'duomenų bazė jau sukurta — Hostinger: Databases → MySQL Databases; XAMPP: phpMyAdmin → New.',
    'schema_creates'   => 'Diegimas sukuria sensors ir readings lenteles bei automatinio valymo Event\'ą (jei serveris leidžia).',
    'schema_idem'      => 'Operacija idempotentinė — galima paleisti kelis kartus be žalos esamiems duomenims.',
    'schema_found'     => 'rastas',
    'schema_done_title'=> '✓ Schema jau įdiegta',
    'schema_done_t'    => 'Duomenų bazė pasiekiama ir lentelės (sensors, readings) jau sukurtos. Pakartotinai diegti nereikia.',
    'schema_reinstall' => 'Vis tiek perdiegti',
    'schema_bytes'     => 'baitų',
    'schema_notfound'  => 'NERASTAS (paslėptas po diegimo)',
    'schema_upload'    => 'Įkelti schema.sql failą',
    'schema_upload_hint'=> 'schema.sql paslėpta po diegimo. Norėdami perdiegti, įkelkite SQL failą.',
    'cfg_exists'       => 'egzistuoja',
    'cfg_missing'      => 'nerastas',
    'install_confirm'  => 'Diegti schemą? Jei lentelės jau egzistuoja — duomenys išliks.',
    'pw_self_denied'   => 'includes/ katalogas neįrašomas — reikia rašymo teisių, kad slaptažodžio keitimas veiktų.',
    'sec_files'        => '.htaccess draudžia tiesioginę prieigą',
    'sec_schema_hidden'=> 'paslėpta (pervadinta — nebeegzistuoja)',
    'gmaps_hint'       => '→ APIs & Services → Credentials → Create API Key → įjunkite Maps JavaScript API ir Geocoding API',
    'optional'         => 'neprivaloma',
    'osm_fallback_hint'=> 'Jei rakto nepaliksite, žemėlapiui bus naudojamas OpenStreetMap (nemokamas, be rakto). Google Maps suteikia geokodavimą registracijai; su OSM registracija veikia per koordinates.',
    'map_country_label'=> 'Šalis (žemėlapio centras)',
    'tile_provider_label' => 'Žemėlapio tiekėjas',
    'tile_provider_hint'  => 'Pasirinkite žemėlapio tiekėją. Google reikia API rakto; kiti (OpenTopoMap, CARTO, OSM, Yandex) — be rakto. Slapukų ir privatumo tekstas pasikeičia automatiškai pagal pasirinkimą.',
    'tile_url_label'   => 'Savas plytelių URL',
    'tile_url_hint'    => 'Naudojama tik pasirinkus „Savas". Formatas: https://.../{z}/{x}/{y}.png',
    'map_country_hint' => 'Žemėlapis automatiškai centruojamas į pasirinktos šalies sostinę',
    'prefix_label'     => 'Jutiklių prefiksas (atsarginis)',
    'prefix_hint'      => 'Naudojamas tik jei automatinis miesto atpažinimas nepavyksta',
    'timeout_label'    => 'Timeout be duomenų (min)',
    'retention_label'  => 'Duomenų saugojimo laikas (dienos)',
    'rename_title'     => 'Admin failo pervadinimas',
    'rename_hint'      => 'Saugumui pakeiskite admin failo pavadinimą. Prefiksas „admin_" privalomas, toliau — jūsų pasirinkimas. Visos nuorodos atsinaujins automatiškai.',
    'rename_label'     => 'Naujas pavadinimas',
    'rename_current'   => 'Dabartinis',
    'rename_btn'       => 'Pervadinti admin failą',
    'retention_hint'   => '0 = neribotai. Senesni rodmenys valomi per cleanup.',
    'timeout_hint'     => 'Po kiek minučių jutiklis laikomas offline',
    'contact_email_label' => 'Komunikacinis el. paštas (viešas)',
    'contact_email_hint'  => 'Rodomas privatumo politikoje ir poraštėje. Turi skirtis nuo admin prisijungimo el. pašto. Palikite tuščią, jei nereikia.',
    'sec_main_data'    => 'Pagrindiniai duomenys',
    'sec_main_sub'     => 'Pavadinimas, šalis, žemėlapio tiekėjas ir rodymo nustatymai',
    'sec_database'     => 'Duomenų bazė',
    'sec_database_sub' => 'MySQL prisijungimas ir schemos diegimas',
    'sec_admin_login'  => 'Admin prisijungimas',
    'sec_admin_login_sub' => 'Prisijungimo el. paštas ir slaptažodis (dviejų pakopų)',
    'test_conn'        => '⟳ Testuoti ryšį',
    'save_config'      => '💾 Išsaugoti konfigūraciją',
    'step2_title'      => 'Duomenų bazės schema',
    'step2_sub'        => 'Sukurti lenteles ir automatinio valymo Event\'ą',
    'install_schema'   => '⚡ Įdiegti schemą',
    'step3_title'      => 'Admin prieigos IP apsauga',
    'step3_sub'        => '.htaccess perrašomas automatiškai — admin.php pasiekiamas tik iš leistinų IP',
    'ip_state'         => 'Būsena',
    'ip_on'            => 'ĮJUNGTA — admin.php riboja IP',
    'ip_off'           => 'IŠJUNGTA — saugo tik slaptažodis',
    'your_ip'          => 'Jūsų dabartinis IP',
    'allowed_ips'      => 'Leistini IP adresai:',
    'ip_you'           => 'jūs',
    'ip_enable_btn'    => '🔒 Įjungti apsaugą su mano IP',
    'ip_add_btn'       => '+ Pridėti IP',
    'ip_disable_btn'   => 'Išjungti apsaugą',
    'step4_title'      => 'Slaptažodžio keitimas',
    'step4_sub'        => 'Įveskite naują — admin.php apskaičiuos hash\'ą ir įrašys jį pats',
    'current_pw'       => 'Dabartinis slaptažodis',
    'new_pw'           => 'Naujas slaptažodis',
    'new_pw2'          => 'Pakartokite naują',
    'change_pw_btn'    => '🔑 Keisti slaptažodį',
    'pw_status_set'    => 'Slaptažodis nustatytas.',
    'pw_status_unset'  => 'NENUSTATYTAS — nustatykite naują žemiau.',
    'step5_title'      => 'Saugumo apžvalga',
    'step5_sub'        => 'Būsena prieš paleidžiant produkciją',
    'open_map'         => '🗺 Atidaryti žemėlapį',
    'manage_sensors'   => '⚙ Jutiklių valdymas',
    'test_api'         => '⚡ Testuoti API',
    'run_cleanup'      => '🧹 Paleisti cleanup',
    'test_map'         => '🧪 Testuoti žemėlapį',
    'sec_note'         => 'Šis puslapis matomas tik prisijungus. DB slaptažodis saugomas config.php — įsitikinkite, kad .htaccess draudžia prieigą prie includes/ katalogo.',
    'cancel'           => 'Atšaukti',
  ],
  'en' => [
    'admin_subtitle'   => 'Setup & administration panel',
    'password'         => 'Password',
    'login'            => 'Log in',
    'wrong_password'   => 'Incorrect password.',
    'setup_mode'       => 'First-run mode — set a real password',
    'setup_login_warn' => 'First-run mode — password not set yet. Log in with any password, then set a real one in step 4.',
    'logout'           => 'Log out',
    'setup_title'      => 'IoT Sensor Map — Setup',
    'step1_title'      => 'Database & Google Maps',
    'step1_sub'        => 'MySQL credentials and Maps API key',
    'db_host'          => 'DB host',
    'db_name'          => 'DB name',
    'db_user'          => 'DB user',
    'db_pass'          => 'DB password',
    'gmaps_key'        => 'Google Maps API key',
    'site_title_lt'    => 'Title (LT version)',
    'site_title_en'    => 'Title (EN version)',
    'site_title_hint'  => 'Shown in the browser tab and header. The map page automatically shows the version matching the selected language.',
    'prefix_example'   => 'E.g. VLN → VLN1, VLN2... (when automatic city lookup fails)',
    'cfg_writable'     => 'writable',
    'cfg_denied'       => 'WRITE DENIED — check permissions (chmod 644)',
    'cfg_path'         => 'Path',
    'dyn_ip_title'     => 'Dynamic IP:',
    'dyn_ip_body'      => 'if your ISP changes your IP (most home connections), the admin page will become unreachable after the IP changes. To unlock: via FTP/File Manager delete the block between',
    'dyn_ip_tail'      => 'in the .htaccess file.',
    'schema_assume'    => 'Assumption:',
    'schema_assume_t'  => 'the database already exists — Hostinger: Databases → MySQL Databases; XAMPP: phpMyAdmin → New.',
    'schema_creates'   => 'Installation creates the sensors and readings tables and the auto-cleanup Event (if the server allows).',
    'schema_idem'      => 'The operation is idempotent — it can be run multiple times without harming existing data.',
    'schema_found'     => 'found',
    'schema_done_title'=> '✓ Schema already installed',
    'schema_done_t'    => 'The database is reachable and the tables (sensors, readings) already exist. No need to reinstall.',
    'schema_reinstall' => 'Reinstall anyway',
    'schema_bytes'     => 'bytes',
    'schema_notfound'  => 'NOT FOUND (hidden after install)',
    'schema_upload'    => 'Upload schema.sql file',
    'schema_upload_hint'=> 'schema.sql is hidden after install. To reinstall, upload a SQL file.',
    'cfg_exists'       => 'exists',
    'cfg_missing'      => 'not found',
    'install_confirm'  => 'Install schema? If tables already exist, data is preserved.',
    'pw_self_denied'   => 'includes/ folder is not writable — write permission is required for password change.',
    'sec_files'        => '.htaccess denies direct access',
    'sec_schema_hidden'=> 'hidden (renamed, no longer present)',
    'gmaps_hint'       => '→ APIs & Services → Credentials → Create API Key → enable Maps JavaScript API and Geocoding API',
    'optional'         => 'optional',
    'osm_fallback_hint'=> 'If you leave the key empty, OpenStreetMap is used for the map (free, no key). Google Maps provides geocoding for registration; with OSM, registration works via coordinates.',
    'map_country_label'=> 'Country (map center)',
    'tile_provider_label' => 'Map provider',
    'tile_provider_hint'  => 'If map tiles don\'t appear, switch the provider — different networks can reach different servers.',
    'tile_url_label'   => 'Custom tile URL',
    'tile_url_hint'    => 'Used only when "Custom" is selected. Format: https://.../{z}/{x}/{y}.png',
    'map_country_hint' => 'The map is automatically centered on the selected country\'s capital',
    'prefix_label'     => 'Sensor prefix (fallback)',
    'prefix_hint'      => 'Used only if automatic city detection fails',
    'timeout_label'    => 'No-data timeout (min)',
    'retention_label'  => 'Data retention (days)',
    'rename_title'     => 'Rename admin file',
    'rename_hint'      => 'For security, change the admin file name. The "admin_" prefix is required, the rest is your choice. All links update automatically.',
    'rename_label'     => 'New name',
    'rename_current'   => 'Current',
    'rename_btn'       => 'Rename admin file',
    'retention_hint'   => '0 = unlimited. Older readings purged via cleanup.',
    'timeout_hint'     => 'After how many minutes a sensor is considered offline',
    'contact_email_label' => 'Communication email (public)',
    'contact_email_hint'  => 'Shown in the privacy policy and footer. Must differ from the admin login email. Leave empty if not needed.',
    'sec_main_data'    => 'Main data',
    'sec_main_sub'     => 'Title, country, map provider and display settings',
    'sec_database'     => 'Database',
    'sec_database_sub' => 'MySQL connection and schema installation',
    'sec_admin_login'  => 'Admin login',
    'sec_admin_login_sub' => 'Login email and password (two-stage)',
    'test_conn'        => '⟳ Test connection',
    'save_config'      => '💾 Save configuration',
    'step2_title'      => 'Database schema',
    'step2_sub'        => 'Create tables and the auto-cleanup Event',
    'install_schema'   => '⚡ Install schema',
    'step3_title'      => 'Admin IP protection',
    'step3_sub'        => '.htaccess is rewritten automatically — admin.php reachable only from allowed IPs',
    'ip_state'         => 'Status',
    'ip_on'            => 'ENABLED — admin.php restricts by IP',
    'ip_off'           => 'DISABLED — protected by password only',
    'your_ip'          => 'Your current IP',
    'allowed_ips'      => 'Allowed IP addresses:',
    'ip_you'           => 'you',
    'ip_enable_btn'    => '🔒 Enable protection with my IP',
    'ip_add_btn'       => '+ Add IP',
    'ip_disable_btn'   => 'Disable protection',
    'step4_title'      => 'Change password',
    'step4_sub'        => 'Enter a new one — admin.php computes the hash and writes it itself',
    'current_pw'       => 'Current password',
    'new_pw'           => 'New password',
    'new_pw2'          => 'Repeat new password',
    'change_pw_btn'    => '🔑 Change password',
    'pw_status_set'    => 'Password is set.',
    'pw_status_unset'  => 'NOT SET — set a new one below.',
    'step5_title'      => 'Security overview',
    'step5_sub'        => 'Status before going to production',
    'open_map'         => '🗺 Open map',
    'manage_sensors'   => '⚙ Manage sensors',
    'test_api'         => '⚡ Test API',
    'run_cleanup'      => '🧹 Run cleanup',
    'test_map'         => '🧪 Test map',
    'sec_note'         => 'This page is visible only after login. The DB password is stored in config.php — make sure .htaccess denies access to the includes/ folder.',
    'cancel'           => 'Cancel',
  ],
];
// Trumpoji vertimo funkcija
function tr(string $key): string {
    global $L, $adminLang;
    return $L[$adminLang][$key] ?? $L['lt'][$key] ?? $key;
}

// ── First-login convenience ───────────────────────────────
// If the hash is not changed yet — generate and show it.
$hashIsDefault = str_contains(ADMIN_PASSWORD_HASH, 'replacethis');

// ── Brute-force protection: 3 attempts, then a 60-min IP block ──
// Attempts are stored in a JSON file (no DB needed). Stored above the webroot
// if possible, otherwise — alongside with .htaccess protection.
const MAX_LOGIN_ATTEMPTS = 3;
const LOGIN_BLOCK_MINUTES = 60;
// Stage 2 (email + password): 2 wrong attempts → a 24-hour block.
const STAGE2_MAX_ATTEMPTS = 2;
const STAGE2_BLOCK_HOURS  = 24;

function loginAttemptsFile(): string {
    $above = dirname(__DIR__) . '/iot_login_attempts.json';
    // Try above the webroot (safer); if the folder is not writable — alongside
    if (is_writable(dirname($above)) || file_exists($above)) {
        return $above;
    }
    return __DIR__ . '/usedLogin.json';
}

function readAttempts(): array {
    $f = loginAttemptsFile();
    if (!file_exists($f)) return [];
    $data = json_decode((string)@file_get_contents($f), true);
    return is_array($data) ? $data : [];
}

function writeAttempts(array $data): void {
    @file_put_contents(loginAttemptsFile(), json_encode($data), LOCK_EX);
}

// Returns the remaining block seconds (0 = not blocked)
function loginBlockSeconds(string $ip): int {
    $all = readAttempts();
    $rec = $all[$ip] ?? null;
    if (!$rec) return 0;
    // An explicit block (stage-2 24h or a manual admin block) takes precedence.
    $until = (int)($rec['blocked_until'] ?? 0);
    if ($until > time()) return $until - time();
    // Stage 1: 3 email failures → a 60-min block.
    if (($rec['count'] ?? 0) >= MAX_LOGIN_ATTEMPTS) {
        $remaining = ($rec['last'] ?? 0) + LOGIN_BLOCK_MINUTES * 60 - time();
        return $remaining > 0 ? $remaining : 0;
    }
    return 0;
}

function recordFailedLogin(string $ip): void {
    $all = readAttempts();
    $rec = $all[$ip] ?? ['count' => 0, 'last' => 0];
    // If the previous block expired — start over
    if (($rec['count'] ?? 0) >= MAX_LOGIN_ATTEMPTS
        && (($rec['last'] ?? 0) + LOGIN_BLOCK_MINUTES * 60) < time()) {
        $rec = ['count' => 0, 'last' => 0];
    }
    $rec['count'] = ($rec['count'] ?? 0) + 1;
    $rec['last']  = time();
    $all[$ip] = $rec;
    // Clear old entries (>24h) so the file does not grow — but keep records
    // that still hold an active block (24h stage-2 or a manual block).
    foreach ($all as $k => $v) {
        $stillBlocked = (int)($v['blocked_until'] ?? 0) > time();
        if (!$stillBlocked && ($v['last'] ?? 0) < time() - 86400) unset($all[$k]);
    }
    writeAttempts($all);
}

// Stage 2 (email + password) failure. After STAGE2_MAX_ATTEMPTS wrong tries
// the IP is blocked for STAGE2_BLOCK_HOURS hours.
function recordStage2Fail(string $ip): bool {
    $all = readAttempts();
    $rec = $all[$ip] ?? ['count' => 0, 'last' => 0];
    $rec['s2']     = ($rec['s2'] ?? 0) + 1;
    $rec['s2last'] = time();
    $blocked = false;
    if ($rec['s2'] >= STAGE2_MAX_ATTEMPTS) {
        $rec['blocked_until'] = time() + STAGE2_BLOCK_HOURS * 3600;
        $rec['reason']        = 'creds_24h';
        $blocked = true;
    }
    $all[$ip] = $rec;
    writeAttempts($all);
    return $blocked; // true when the 24h block was just applied
}

// Manual block by the administrator (default 24h; reason = manual).
function blockIpManually(string $ip, int $hours = 24): void {
    $all = readAttempts();
    $rec = $all[$ip] ?? ['count' => 0, 'last' => 0];
    $rec['blocked_until'] = time() + max(1, $hours) * 3600;
    $rec['reason']        = 'manual';
    $all[$ip] = $rec;
    writeAttempts($all);
}

function clearFailedLogins(string $ip): void {
    $all = readAttempts();
    unset($all[$ip]);
    writeAttempts($all);
}

// ── Login ─────────────────────────────────────────────────
// Two-stage login once credentials are fully set:
//   Stage 1 — email (username) only. 3 wrong → 60-min block, NO message.
//   Stage 2 — email + password.      2 wrong → 24-h block + audit log + force pw change.
// Before credentials are complete (first run) — a single password step.
$loginError = '';
$loginStage = 1;       // which login form to show (1 = email, 2 = email+password)
$lt = (($adminLang ?? 'lt') === 'lt');
$credentialsComplete = !$hashIsDefault && adminEmailIsSet();
$blockSeconds = loginBlockSeconds(clientIp());

// Allow returning to stage 1 (re-enter the email) from the stage-2 form.
if (isset($_GET['reset_login']) && !isset($_SESSION['iot_admin'])) {
    unset($_SESSION['login_stage2']);
    header('Location: ' . basename(__FILE__));
    exit;
}

if (!isset($_SESSION['iot_admin']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = clientIp();
    $blockSeconds = loginBlockSeconds($ip);

    if (!$credentialsComplete && isset($_POST['password'])) {
        // ---- FIRST RUN / no email yet: single password step ----
        if ($blockSeconds > 0) {
            $mins = (int)ceil($blockSeconds / 60);
            $loginError = $lt ? "Per daug nesėkmingų bandymų. IP užblokuotas dar ~{$mins} min."
                              : "Too many failed attempts. IP blocked for ~{$mins} more min.";
        } elseif (!$hashIsDefault && password_verify($_POST['password'], ADMIN_PASSWORD_HASH)) {
            clearFailedLogins($ip);
            $_SESSION['iot_admin'] = true;
            session_regenerate_id(true);
            header('Location: ' . basename(__FILE__));
            exit;
        } elseif ($hashIsDefault) {
            // First-run mode: any password lets you in, but we warn to set a real password.
            clearFailedLogins($ip);
            $_SESSION['iot_admin']      = true;
            $_SESSION['iot_setup_mode'] = true;
            session_regenerate_id(true);
            header('Location: ' . basename(__FILE__));
            exit;
        } else {
            recordFailedLogin($ip);
            $blockSeconds = loginBlockSeconds($ip);
            $remaining = MAX_LOGIN_ATTEMPTS - (readAttempts()[$ip]['count'] ?? 0);
            $loginError = $blockSeconds > 0
                ? ($lt ? 'Per daug nesėkmingų bandymų. IP užblokuotas 60 min.' : 'Too many failed attempts. IP blocked for 60 min.')
                : ($lt ? "Neteisingas slaptažodis. Liko bandymų: {$remaining}." : "Incorrect password. Attempts left: {$remaining}.");
            sleep(1);
        }
    } elseif ($credentialsComplete && !empty($_SESSION['login_stage2']) && isset($_POST['stage2_password'])) {
        // ---- STAGE 2: email + password (only after stage 1 passed) ----
        $loginStage = 2;
        if ($blockSeconds > 0) {
            $loginError = $lt ? 'Per daug nesėkmingų bandymų. Bandykite vėliau.'
                              : 'Too many failed attempts. Try again later.';
        } elseif (verifyAdminEmail($_POST['stage2_email'] ?? '')
                  && password_verify($_POST['stage2_password'], ADMIN_PASSWORD_HASH)) {
            clearFailedLogins($ip);
            unset($_SESSION['login_stage2']);
            $_SESSION['iot_admin'] = true;
            session_regenerate_id(true);
            header('Location: ' . basename(__FILE__));
            exit;
        } else {
            $blockedNow = recordStage2Fail($ip);
            if ($blockedNow) {
                // 2nd wrong attempt → 24h block, log it, require a password change.
                logSecurityEvent('login_blocked_24h', $ip, 'Stage 2 (email+password) failed twice → 24h block');
                setPasswordChangeRequired(true);
                unset($_SESSION['login_stage2']);
                $loginStage = 1;
                $loginError = $lt ? 'Per daug nesėkmingų bandymų. IP užblokuotas 24 val.'
                                  : 'Too many failed attempts. IP blocked for 24h.';
            } else {
                $loginError = $lt ? 'Neteisingi prisijungimo duomenys.' : 'Incorrect login details.';
            }
            sleep(1);
        }
    } elseif ($credentialsComplete && isset($_POST['stage1_email'])) {
        // ---- STAGE 1: email (username) only. Failures are SILENT (no message). ----
        $loginStage = 1;
        if ($blockSeconds > 0) {
            // Silent — no message at all (do not reveal the block / valid email).
        } elseif (verifyAdminEmail($_POST['stage1_email'])) {
            $_SESSION['login_stage2'] = true;
            $loginStage = 2;
        } else {
            recordFailedLogin($ip);
            // Silent — no error message (prevents email enumeration). Just re-show stage 1.
            if (loginBlockSeconds($ip) > 0) {
                logSecurityEvent('login_email_blocked', $ip, 'Stage 1 email failed 3 times → 60-min block');
            }
            sleep(1);
        }
    }
}
// If stage 1 was already passed in this session — keep showing stage 2.
if ($credentialsComplete && !empty($_SESSION['login_stage2']) && !isset($_SESSION['iot_admin'])) {
    $loginStage = 2;
}

$isAdmin   = isset($_SESSION['iot_admin']);
$setupMode = isset($_SESSION['iot_setup_mode']);

// ── Automatic schema.sql hiding ──────────────────────────
// If a logged-in admin loads the page and the DB already works with
// all tables (e.g. connected to an existing DB, install
// not performed) — immediately DELETE schema.sql so it does not remain
// accessible. Checked only once per load, silently.
$autoDeleted = false;
$migrationMsg = null;
if ($isAdmin) {
    $st = schemaStatus();
    if ($st['db_ok'] && $st['tables_ok']) {
        // Automatic schema migration (old DB → new mac NULL format)
        $migrationMsg = migrateSchema();
        if (file_exists(SCHEMA_PATH)) {
            // The real DB already has the core tables. Before deleting schema.sql,
            // check it against the live DB: if the schema is broader/newer, FIRST
            // extend the DB with the new elements, and only delete once they match.
            $cfg = readCurrentConfig();
            $res = installSchema(
                $cfg['DB_HOST'] ?? 'localhost',
                $cfg['DB_NAME'] ?? '',
                $cfg['DB_USER'] ?? '',
                $cfg['DB_PASS'] ?? ''
            );
            if ($res['ok'] && ($res['matchesSchema'] ?? false)) {
                $autoDeleted = deleteSchemaFile();   // DB matches → safe to delete
                if (str_contains($res['msg'] ?? '', 'pra') || str_contains($res['msg'] ?? '', 'extend')) {
                    $migrationMsg = trim(($migrationMsg ? $migrationMsg . ' ' : '') . $res['msg']);
                }
            } else {
                // DB not yet aligned with the schema → KEEP schema.sql, surface why.
                $migrationMsg = trim(($migrationMsg ? $migrationMsg . ' ' : '') . ($res['msg'] ?? ''));
            }
        }
    }
}

// ── Actions (only when logged in) ─────────────────────────
$msg = $msgType = '';

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF protection: all admin POST actions must have a valid token
    requireCsrf();

    // ── 1. Save configuration ─────────────────────────────
    if (isset($_POST['save_config'])) {
        $dbHost   = trim($_POST['db_host']   ?? 'localhost');
        $dbName   = trim($_POST['db_name']   ?? '');
        $dbUser   = trim($_POST['db_user']   ?? '');
        $dbPass   = $_POST['db_pass']        ?? '';
        $gmapsKey = trim($_POST['gmaps_key'] ?? '');
        $prefix   = strtoupper(trim($_POST['city_prefix'] ?? 'VLN'));
        $timeout  = max(1, (int)($_POST['sensor_timeout'] ?? 3));
        $titleLt  = trim($_POST['site_title_lt'] ?? 'IoT Jutiklių Žemėlapis');
        $titleEn  = trim($_POST['site_title_en'] ?? 'IoT Sensor Map');
        $country  = strtoupper(trim($_POST['map_country'] ?? 'LT'));
        $retention = max(0, (int)($_POST['data_retention'] ?? 0));
        $tileProvider = trim($_POST['tile_provider'] ?? 'opentopomap');
        $tileUrl  = trim($_POST['tile_url'] ?? '');
        $contactEmail = normalizeEmail($_POST['contact_email'] ?? '');

        $contactErr = '';
        if ($contactEmail !== '') {
            if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL) || strlen($contactEmail) > 190) {
                $contactErr = ['lt' => 'Neteisingas komunikacinio el. pašto formatas.',
                               'en' => 'Invalid communication email format.'][$adminLang];
            } elseif (adminEmailIsSet() && verifyAdminEmail($contactEmail)) {
                // The public contact address must NOT equal the admin login email.
                $contactErr = ['lt' => 'Komunikacinis el. paštas negali sutapti su admin prisijungimo el. paštu.',
                               'en' => 'The communication email must not match the admin login email.'][$adminLang];
            }
        }

        if (!$dbName || !$dbUser) {
            $msg = ['lt' => 'DB pavadinimas ir vartotojas yra privalomi.',
                    'en' => 'DB name and user are required.'][$adminLang];
            $msgType = 'err';
        } elseif ($contactErr !== '') {
            $msg = $contactErr;
            $msgType = 'err';
        } else {
            $result = writeConfig($dbHost, $dbName, $dbUser, $dbPass, $gmapsKey, $prefix, $timeout, $titleLt, $titleEn, $country, $retention, $tileProvider, $tileUrl, $contactEmail);
            if ($result === true) {
                $msg = ['lt' => 'Konfigūracija išsaugota sėkmingai.',
                        'en' => 'Configuration saved successfully.'][$adminLang];
                $msgType = 'ok';
            } else {
                $msg = 'config.php: ' . $result;
                $msgType = 'err';
            }
        }
    }

    // ── 2. Test DB connection ─────────────────────────────
    if (isset($_POST['test_db'])) {
        $result = testDb(
            trim($_POST['db_host'] ?? 'localhost'),
            trim($_POST['db_name'] ?? ''),
            trim($_POST['db_user'] ?? ''),
            $_POST['db_pass'] ?? ''
        );
        $msg     = $result['msg'];
        $msgType = $result['ok'] ? 'ok' : 'err';
    }

    // ── 3. Load DB schema ─────────────────────────────────
    if (isset($_POST['install_schema'])) {
        // SQL source: an uploaded file (if any) or schema.sql
        $uploadedSql = null;
        if (isset($_FILES['schema_file']) && $_FILES['schema_file']['error'] === UPLOAD_ERR_OK) {
            $tmp  = $_FILES['schema_file']['tmp_name'];
            $size = $_FILES['schema_file']['size'];
            $ext  = strtolower(pathinfo($_FILES['schema_file']['name'], PATHINFO_EXTENSION));
            // Validation: only .sql, up to 1 MB, uploaded via HTTP
            if ($ext !== 'sql') {
                $msg = ['lt' => 'Leidžiamas tik .sql failas.',
                        'en' => 'Only .sql files allowed.'][$adminLang];
                $msgType = 'err';
            } elseif ($size > 1048576) {
                $msg = ['lt' => 'Failas per didelis (maks. 1 MB).',
                        'en' => 'File too large (max 1 MB).'][$adminLang];
                $msgType = 'err';
            } elseif (!is_uploaded_file($tmp)) {
                $msg = ['lt' => 'Įkėlimo klaida.', 'en' => 'Upload error.'][$adminLang];
                $msgType = 'err';
            } else {
                $uploadedSql = file_get_contents($tmp);
            }
        }

        // If there was no upload error — install
        if ($msgType !== 'err') {
            $result = installSchema(
                trim($_POST['db_host'] ?? 'localhost'),
                trim($_POST['db_name'] ?? ''),
                trim($_POST['db_user'] ?? ''),
                $_POST['db_pass'] ?? '',
                $uploadedSql // null → will use schema.sql; content → the uploaded file
            );
            // Delete schema.sql ONLY after the live DB MATCHES the schema
            // (a broader/newer schema first extends the DB, then is removed).
            if ($result['ok'] && ($result['matchesSchema'] ?? false) && file_exists(SCHEMA_PATH)) {
                $deleted = deleteSchemaFile();
                if ($deleted) {
                    $result['msg'] .= $adminLang === 'lt'
                        ? ' schema.sql ištrintas (DB atitinka schemą).'
                        : ' schema.sql deleted (DB matches the schema).';
                }
            } elseif ($result['ok'] && !($result['matchesSchema'] ?? false)) {
                $result['msg'] .= $adminLang === 'lt'
                    ? ' schema.sql IŠLAIKYTAS (kol DB nesutampa su schema).'
                    : ' schema.sql KEPT (until the DB matches the schema).';
            }
            $msg     = $result['msg'];
            $msgType = $result['ok'] ? 'ok' : 'err';
        }
    }

    // ── 4. IP protection: enable with my IP ───────────────
    if (isset($_POST['ip_enable'])) {
        $ips = readAllowedIps();
        $my  = clientIp();
        if (!in_array($my, $ips)) $ips[] = $my;
        $result = writeAllowedIps($ips);
        if ($result === true) {
            $msg = "✓ IP apsauga įjungta. Jūsų IP {$my} įrašytas į .htaccess — admin.php dabar pasiekiamas tik iš leistinų adresų.";
            $msgType = 'ok';
        } else { $msg = $result; $msgType = 'err'; }
    }

    // ── 5. IP protection: add an extra IP ─────────────────
    if (isset($_POST['ip_add'])) {
        $newIp = trim($_POST['new_ip'] ?? '');
        if (!filter_var($newIp, FILTER_VALIDATE_IP)) {
            $msg = "Neteisingas IP adresas: '{$newIp}'";
            $msgType = 'err';
        } else {
            $ips = readAllowedIps();
            // Protection is enabled by adding — always include your own IP too,
            // so we do not lock ourselves out
            $my = clientIp();
            if (!in_array($my, $ips))    $ips[] = $my;
            if (!in_array($newIp, $ips)) $ips[] = $newIp;
            $result = writeAllowedIps($ips);
            if ($result === true) {
                $msg = "✓ IP {$newIp} pridėtas. Jūsų IP {$my} taip pat sąraše.";
                $msgType = 'ok';
            } else { $msg = $result; $msgType = 'err'; }
        }
    }

    // ── 6. IP protection: remove an IP ────────────────────
    if (isset($_POST['ip_remove'])) {
        $rmIp = trim($_POST['rm_ip'] ?? '');
        $my   = clientIp();
        if ($rmIp === $my) {
            $msg = "Negalima pašalinti savo paties IP ({$my}) — užsirakintumėte. Pirmiau išjunkite apsaugą.";
            $msgType = 'err';
        } else {
            $ips = array_values(array_diff(readAllowedIps(), [$rmIp]));
            $result = writeAllowedIps($ips);
            if ($result === true) {
                $msg = "✓ IP {$rmIp} pašalintas iš leistinų sąrašo.";
                $msgType = 'ok';
            } else { $msg = $result; $msgType = 'err'; }
        }
    }

    // ── 7. IP protection: disable completely ──────────────
    if (isset($_POST['ip_disable'])) {
        $result = writeAllowedIps([]); // localhost remains automatically
        if ($result === true) {
            $msg = ['lt' => 'IP apsauga išjungta — admin.php pasiekiamas tik iš localhost. Iš kitų IP — nepasiekiamas.',
                    'en' => 'IP protection disabled — admin.php is reachable only from localhost. Unreachable from other IPs.'][$adminLang];
            $msgType = 'ok';
        } else { $msg = $result; $msgType = 'err'; }
    }

    // ── 8. Password change ────────────────────────────────
    if (isset($_POST['change_password'])) {
        $new1 = $_POST['new_password']  ?? '';
        $new2 = $_POST['new_password2'] ?? '';

        // In setup mode the old password is not checked (there is none)
        $currentOk = $setupMode
                  || (isset($_POST['current_password'])
                      && password_verify($_POST['current_password'], ADMIN_PASSWORD_HASH));

        if (!$currentOk) {
            $msg = ['lt' => 'Neteisingas dabartinis slaptažodis.',
                    'en' => 'Current password is incorrect.'][$adminLang];
            $msgType = 'err';
        } elseif ($new1 !== $new2) {
            $msg = ['lt' => 'Nauji slaptažodžiai nesutampa.',
                    'en' => 'New passwords do not match.'][$adminLang];
            $msgType = 'err';
        } else {
            $result = changeAdminPassword($new1, $adminLang);
            if ($result === true) {
                // After the change we leave setup mode
                unset($_SESSION['iot_setup_mode']);
                // Update the hash stored in the session so the security state
                // shows "set" IMMEDIATELY without needing to log out/in.
                $_SESSION['iot_pw_set'] = true;
                // Recompute the state flags RIGHT NOW so the security
                // overview and indicators refresh within this same request.
                $hashIsDefault = false;
                $setupMode = false;
                setPasswordChangeRequired(false); // a fresh password clears the lockout request
                $msg = ['lt' => '✓ Slaptažodis pakeistas ir saugiai įrašytas į settings failą.',
                        'en' => '✓ Password changed and securely saved to settings file.'][$adminLang];
                $msgType = 'ok';
            } else {
                $msg = $result;
                $msgType = 'err';
            }
        }
    }

    // ── Admin email (username) — stored as a hash in the DB ─
    if (isset($_POST['set_email'])) {
        $result = setAdminEmail($_POST['admin_email'] ?? '', $adminLang);
        if ($result === true) {
            $msg = ['lt' => '✓ Administratoriaus el. paštas išsaugotas (hash DB).',
                    'en' => '✓ Administrator email saved (as a hash in the DB).'][$adminLang];
            $msgType = 'ok';
        } else {
            $msg = $result; $msgType = 'err';
        }
    }

    // ── Security log: block / unblock an IP, delete entries ─
    if (isset($_POST['block_ip'])) {
        $bip = trim((string)$_POST['block_ip']);
        if (filter_var($bip, FILTER_VALIDATE_IP)) {
            blockIpManually($bip, 24);
            logSecurityEvent('ip_blocked_manual', $bip, 'Blocked manually by the administrator (24h)');
            $msg = ['lt' => "✓ IP {$bip} užblokuotas 24 val.",
                    'en' => "✓ IP {$bip} blocked for 24h."][$adminLang];
            $msgType = 'ok';
        }
    }
    if (isset($_POST['unblock_ip'])) {
        $uip = trim((string)$_POST['unblock_ip']);
        if (filter_var($uip, FILTER_VALIDATE_IP)) {
            clearFailedLogins($uip);
            $msg = ['lt' => "✓ IP {$uip} atblokuotas.",
                    'en' => "✓ IP {$uip} unblocked."][$adminLang];
            $msgType = 'ok';
        }
    }
    if (isset($_POST['delete_log'])) {
        $logId = (int)$_POST['delete_log'];
        $pdo = adminDb();
        if ($logId > 0 && $pdo) {
            try { $pdo->prepare("DELETE FROM audit_log WHERE id = ?")->execute([$logId]); } catch (\Throwable) {}
            $msg = ['lt' => '✓ Žurnalo įrašas ištrintas.', 'en' => '✓ Log entry deleted.'][$adminLang];
            $msgType = 'ok';
        }
    }
    if (isset($_POST['clear_log'])) {
        $pdo = adminDb();
        if ($pdo) {
            try { $pdo->exec("DELETE FROM audit_log"); } catch (\Throwable) {}
            $msg = ['lt' => '✓ Saugumo žurnalas išvalytas.', 'en' => '✓ Security log cleared.'][$adminLang];
            $msgType = 'ok';
        }
    }

    // ── Admin file rename ─────────────────────────────────
    if (isset($_POST['rename_admin'])) {
        $userPart = $_POST['admin_new_name'] ?? '';
        $result = renameAdminFile($userPart);
        if (is_array($result)) {
            $msg = $result['error'];
            $msgType = 'err';
        } else {
            // Success — redirect to the new file (the old one is already deleted)
            $_SESSION['iot_admin_renamed_to'] = $result;
            header('Location: ' . $result);
            exit;
        }
    }
}

// Auto-hide notice (if the schema was hidden automatically on load,
// and no other action left a message)
if ($autoDeleted && $msg === '') {
    $msg = $adminLang === 'lt'
        ? '✓ Aptikta veikianti DB su lentelėmis — schema.sql automatiškai ištrintas (nebereikalingas).'
        : '✓ Working DB with tables detected — schema.sql automatically deleted (no longer needed).';
    $msgType = 'ok';
}
// Schema migration notice (if an old DB was upgraded)
if ($migrationMsg && $msg === '') {
    $msg = ($adminLang === 'lt' ? '✓ DB schema atnaujinta: ' : '✓ DB schema migrated: ') . $migrationMsg;
    $msgType = 'ok';
} elseif ($migrationMsg) {
    $msg .= ($adminLang === 'lt' ? ' · DB schema atnaujinta: ' : ' · DB schema migrated: ') . $migrationMsg;
}

// ── Funkcijos ─────────────────────────────────────────────

function writeConfig(string $host, string $name, string $user, string $pass,
                     string $gmaps, string $prefix, int $timeout,
                     string $titleLt = 'IoT Jutiklių Žemėlapis',
                     string $titleEn = 'IoT Sensor Map',
                     string $country = 'LT', int $retention = 0,
                     string $tileProvider = 'opentopomap', string $tileUrl = '',
                     string $contactEmail = ''): true|string
{
    // Safe value insertion: var_export properly escapes
    // quotes, backslashes and other characters — addslashes does not do that
    // reliably (e.g. it would break with a '$' or a newline).
    $vHost    = var_export($host, true);
    $vName    = var_export($name, true);
    $vUser    = var_export($user, true);
    $vPass    = var_export($pass, true);
    $vGmaps   = var_export($gmaps, true);
    $vPrefix  = var_export($prefix, true);
    $vTitleLt = var_export($titleLt, true);
    $vTitleEn = var_export($titleEn, true);
    // Tile provider — validated before writing (only known values or custom)
    $allowedProviders = ['google', 'opentopomap', 'carto_voyager', 'carto_light', 'osm', 'yandex', 'custom'];
    if (!in_array($tileProvider, $allowedProviders, true)) $tileProvider = 'opentopomap';
    $vTileProvider = var_export($tileProvider, true);
    $vTileUrl = var_export($tileUrl, true);
    // Communication/contact email — a PUBLIC address (shown in the privacy
    // policy and footer). Must differ from the admin login email; validated
    // by the caller before this point. Stored in plain config (not a secret).
    $vContactEmail = var_export($contactEmail, true);
    $ts       = date('Y-m-d H:i:s');

    // Map center based on the selected country (capital)
    $cap      = capitalCoords($country);
    $vCountry = var_export($country, true);
    $vLat     = var_export($cap['lat'], true);
    $vLng     = var_export($cap['lng'], true);
    $vZoom    = var_export($cap['zoom'], true);

    $content = <<<PHP
<?php
// ============================================================
// config.php — generated automatically by admin.php
// Paskutinis atnaujinimas: {$ts}
// ============================================================

// ── Production error handling ─────────────────────────────
// Errors are NOT shown in the browser (security), but written to the log.
if (!defined('IOT_DEBUG') || !IOT_DEBUG) {
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

define('DB_HOST',    {$vHost});
define('DB_NAME',    {$vName});
define('DB_USER',    {$vUser});
define('DB_PASS',    {$vPass});
define('DB_CHARSET', 'utf8mb4');

define('GMAPS_API_KEY', {$vGmaps});

// Page title — separately for the LT and EN versions
define('SITE_TITLE_LT', {$vTitleLt});
define('SITE_TITLE_EN', {$vTitleEn});

// Map center — based on the selected country (capital)
define('MAP_COUNTRY', {$vCountry});
define('MAP_CENTER_LAT', {$vLat});
define('MAP_CENTER_LNG', {$vLng});
define('MAP_ZOOM', {$vZoom});

// Map tile provider (when Google Maps is not used)
define('MAP_TILE_PROVIDER', {$vTileProvider});
define('MAP_TILE_URL', {$vTileUrl});

define('CITY_PREFIX', {$vPrefix});
define('SENSOR_TIMEOUT_MIN', {$timeout});
define('CHART_HOURS', 24);

// Public communication/contact email (privacy policy + footer).
// MUST differ from the admin login email. Empty = not shown.
define('CONTACT_EMAIL', {$vContactEmail});

// Data retention time in days (0 = unlimited).
// Readings older than N days may be cleaned via cleanup.
define('DATA_RETENTION_DAYS', {$retention});

function db(): PDO {
    static \$pdo = null;
    if (\$pdo === null) {
        \$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        \$pdo = new PDO(\$dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
        ]);
    }
    return \$pdo;
}

function setCorsHeaders(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    if (\$_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        exit(0);
    }
}

// Security headers — shared file (the ONLY place for setSecurityHeaders).
// We do NOT declare it here to avoid "Cannot redeclare" with security.php, which
// is also included by auth.php. require_once guarantees a single load.
require_once __DIR__ . '/security.php';

function jsonResponse(mixed \$data, int \$status = 200): never {
    http_response_code(\$status);
    echo json_encode(\$data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
PHP;

    $written = file_put_contents(CONFIG_PATH, $content);
    return $written !== false ? true : 'file_put_contents() grąžino false. Patikrinkite failų teises.';
}

function testDb(string $host, string $name, string $user, string $pass): array {
    if (!$name || !$user) {
        return ['ok' => false, 'msg' => 'DB pavadinimas ir vartotojas privalomi.'];
    }
    try {
        // We connect directly to the specified DB — it must already exist
        // (created via the Hostinger control panel or phpMyAdmin)
        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $ver = $pdo->query('SELECT VERSION()')->fetchColumn();

        // Check whether the tables are already installed
        $tables = $pdo->query("SHOW TABLES LIKE 'sensors'")->fetchAll();
        $installed = count($tables) > 0;

        return [
            'ok'  => true,
            'msg' => "✓ Ryšys su DB '{$name}' sėkmingas. MySQL {$ver}. "
                   . ($installed ? "Lentelės jau įdiegtos." : "Lentelės dar neįdiegtos — paleiskite 2 žingsnį."),
        ];
    } catch (PDOException $e) {
        $errMsg = $e->getMessage();
        $hint = '';
        if (str_contains($errMsg, 'Unknown database')) {
            $hint = " → Sukurkite DB '{$name}' per Hostinger skydelį (Databases → MySQL) arba phpMyAdmin ir bandykite vėl.";
        } elseif (str_contains($errMsg, 'Access denied')) {
            $passInfo = $pass === '' ? 'TUŠČIAS' : strlen($pass) . ' simb.';
            $passWarn = '';
            if ($pass !== trim($pass)) {
                $passWarn = ' ⚠ DĖMESIO: slaptažodyje yra tarpų arba nematomų simbolių pradžioje/pabaigoje — '
                          . 'tai dažna „Access denied" priežastis kopijuojant. Įveskite slaptažodį ranka.';
            }
            $hint = " → Vartotojas '{$user}'@'{$host}', slaptažodis: {$passInfo}.{$passWarn} "
                  . "Patikrinkite: (1) ar vartotojas '{$user}' turi teisę jungtis iš '{$host}' "
                  . "(XAMPP: paprastai 'localhost'); (2) ar slaptažodis tiksliai sutampa; "
                  . "(3) ar suteiktos teisės: GRANT ALL ON {$name}.* TO '{$user}'@'localhost'; FLUSH PRIVILEGES;";
        } elseif (str_contains($errMsg, 'Connection refused') || str_contains($errMsg, "Can't connect")) {
            $hint = " → MySQL serveris nepasiekiamas. Patikrinkite, ar MySQL paleistas (XAMPP Control Panel → Start MySQL) "
                  . "ir ar host '{$host}' teisingas.";
        }
        return ['ok' => false, 'msg' => 'Ryšio klaida: ' . $errMsg . $hint];
    }
}

/**
 * Splits the SQL script into separate statements by semicolon,
 * RESPECTING apostrophes ('...'), quotes ("..."), and backticks (`...`).
 * Semicolons inside string literals or COMMENT text are NOT separators.
 * This is needed because e.g. COMMENT 'WiFi MAC; NULL while pending' has a ; inside.
 */
function splitSqlStatements(string $sql): array {
    $statements = [];
    $current = '';
    $len = strlen($sql);
    $inSingle = $inDouble = $inBacktick = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];

        // Quote-state toggling (only if not inside the other quote)
        if ($ch === "'" && !$inDouble && !$inBacktick) {
            // Check escaping (doubled '' or \')
            $prev = $i > 0 ? $sql[$i - 1] : '';
            if ($prev !== '\\') $inSingle = !$inSingle;
        } elseif ($ch === '"' && !$inSingle && !$inBacktick) {
            $prev = $i > 0 ? $sql[$i - 1] : '';
            if ($prev !== '\\') $inDouble = !$inDouble;
        } elseif ($ch === '`' && !$inSingle && !$inDouble) {
            $inBacktick = !$inBacktick;
        }

        // Semicolon as a separator ONLY outside quotes
        if ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
            $trimmed = trim($current);
            if ($trimmed !== '') $statements[] = $trimmed;
            $current = '';
        } else {
            $current .= $ch;
        }
    }
    // The last statement (if without a trailing ;)
    $trimmed = trim($current);
    if ($trimmed !== '') $statements[] = $trimmed;

    return $statements;
}

// ── Schema ↔ DB structure comparison ───────────────────────────────────────
// Parses the *expected* structure (table → columns) from schema.sql, including
// columns added later via "ALTER TABLE … ADD COLUMN". Used to decide whether the
// live DB already matches the schema, or must first be extended (migrated).
function parseSchemaStructure(string $sql): array {
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);   // drop line comments
    $tables = [];

    // CREATE TABLE [IF NOT EXISTS] name ( … ) ENGINE
    if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?\s*\((.*?)\)\s*ENGINE/is', $sql, $m, PREG_SET_ORDER)) {
        foreach ($m as $tbl) {
            $name = strtolower($tbl[1]);
            $cols = [];
            foreach (preg_split('/,\s*\n/', $tbl[2]) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if (preg_match('/^\s*(INDEX|KEY|PRIMARY|UNIQUE|CONSTRAINT|FOREIGN)\b/i', $line)) continue;
                if (preg_match('/^[`"]?(\w+)[`"]?\s+\S/', $line, $cm)) $cols[strtolower($cm[1])] = true;
            }
            $tables[$name] = $cols;
        }
    }
    // ALTER TABLE name … ADD COLUMN [IF NOT EXISTS] col …  (migrations)
    if (preg_match_all('/ALTER\s+TABLE\s+[`"]?(\w+)[`"]?(.*?);/is', $sql, $am, PREG_SET_ORDER)) {
        foreach ($am as $alt) {
            $name = strtolower($alt[1]);
            if (!isset($tables[$name])) $tables[$name] = [];
            if (preg_match_all('/ADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?/i', $alt[2], $cm2)) {
                foreach ($cm2[1] as $c) $tables[$name][strtolower($c)] = true;
            }
        }
    }
    return $tables; // [table => [col => true]]
}

// Live DB structure: table → columns.
function liveDbStructure(PDO $pdo): array {
    $tables = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $cols = [];
        $safe = str_replace('`', '', (string)$t);
        foreach ($pdo->query("SHOW COLUMNS FROM `$safe`")->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $cols[strtolower($c)] = true;
        }
        $tables[strtolower((string)$t)] = $cols;
    }
    return $tables;
}

// What the schema has that the live DB does NOT (→ DB must be extended).
function schemaDbDiff(array $expected, array $live): array {
    $missingTables = [];
    $missingColumns = [];
    foreach ($expected as $tbl => $cols) {
        if (!isset($live[$tbl])) {
            $missingTables[] = $tbl;
            if ($cols) $missingColumns[$tbl] = array_keys($cols);
            continue;
        }
        foreach ($cols as $col => $_) {
            if (!isset($live[$tbl][$col])) $missingColumns[$tbl][] = $col;
        }
    }
    return ['tables' => $missingTables, 'columns' => array_filter($missingColumns)];
}

function installSchema(string $host, string $name, string $user, string $pass, ?string $sqlContent = null): array {
    global $adminLang;
    if (!isset($adminLang)) $adminLang = 'lt';
    // SQL source: either uploaded content or the schema.sql file
    if ($sqlContent === null) {
        if (!file_exists(SCHEMA_PATH)) {
            return ['ok' => false, 'msg' => 'schema.sql nerastas. Įkelkite SQL failą rankiniu būdu.'];
        }
        $sqlContent = file_get_contents(SCHEMA_PATH);
    }
    if (!$name || !$user) {
        return ['ok' => false, 'msg' => 'DB pavadinimas ir vartotojas privalomi.'];
    }
    try {
        // We connect DIRECTLY to the specified DB — the schema creates only tables
        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $sql = $sqlContent;

        // Expected structure from the schema + the live DB state BEFORE applying.
        // Lets us tell "already matches" from "DB must be extended (migrated)".
        $expected   = parseSchemaStructure($sqlContent);
        $liveBefore = liveDbStructure($pdo);
        $diffBefore = schemaDbDiff($expected, $liveBefore);

        // 1. Remove comment lines (-- ...) BEFORE splitting.
        //    Previously, blocks starting with a comment were dropped
        //    together with their CREATE TABLE — so the tables were not created.
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);

        // 2. Split into separate statements by ; — BUT respecting
        //    apostrophes/quotes (comments and values may contain ;).
        //    A naive explode(';') would cut a statement at a ; inside a line.
        $statements = splitSqlStatements($sql);

        $executed     = 0;
        $eventWarning = '';
        $errors       = [];

        foreach ($statements as $stmt) {
            try {
                $pdo->exec($stmt);
                $executed++;
            } catch (PDOException $e) {
                $isEventStmt = stripos($stmt, 'EVENT') !== false;
                if ($isEventStmt) {
                    // Shared hosting often disallows EVENT — this is not a critical error
                    $eventWarning = ' ⚠ EVENT nesukurtas (trūksta teisių) — naudokite api/cleanup.php per Cron Job.';
                } elseif ($e->getCode() !== '42S01') { // 42S01 = table already exists
                    $errors[] = substr($stmt, 0, 50) . '… → ' . $e->getMessage();
                }
            }
        }

        // Check the result — whether the tables were actually created
        $sensorsOk  = count($pdo->query("SHOW TABLES LIKE 'sensors'")->fetchAll()) > 0;
        $readingsOk = count($pdo->query("SHOW TABLES LIKE 'readings'")->fetchAll()) > 0;

        if (!$sensorsOk || !$readingsOk) {
            return [
                'ok'  => false,
                'msg' => 'Lentelės nesusikūrė. Klaidos: ' . implode(' | ', array_slice($errors, 0, 3)),
            ];
        }

        // Try to enable the Event Scheduler (may lack SUPER privilege — non-critical)
        try { $pdo->exec('SET GLOBAL event_scheduler = ON'); } catch (PDOException) {}

        // ── Verify the live DB now MATCHES the schema ──
        // The schema runs CREATE TABLE IF NOT EXISTS + ALTER … ADD COLUMN IF NOT
        // EXISTS, so an existing DB gets EXTENDED with any newer elements. Only
        // when no schema element is missing may schema.sql be safely deleted.
        $liveAfter = liveDbStructure($pdo);
        $diffAfter = schemaDbDiff($expected, $liveAfter);
        $matchesSchema = empty($diffAfter['tables']) && empty($diffAfter['columns']);

        // Count what was added (DB was broader-extended during this install).
        $addedTables = count($diffBefore['tables']);
        $addedCols   = 0;
        foreach ($diffBefore['columns'] as $cols) $addedCols += count($cols);

        if (!$matchesSchema) {
            // Migration could not fully align the DB (e.g. MySQL without
            // ADD COLUMN IF NOT EXISTS, or insufficient privileges). Keep schema.sql.
            $stillT = implode(', ', $diffAfter['tables']);
            $stillC = [];
            foreach ($diffAfter['columns'] as $tb => $cs) $stillC[] = "$tb(" . implode(',', $cs) . ')';
            return [
                'ok'            => true,
                'matchesSchema' => false,
                'msg'           => $adminLang === 'lt'
                    ? "✓ Schema paleista, BET DB dar neatitinka schemos — schema.sql NETrinamas. Trūksta: "
                      . trim(($stillT ? "lentelių: $stillT; " : '') . ($stillC ? 'stulpelių: ' . implode(' ', $stillC) : ''))
                      . '. Patikrinkite DB teises (ALTER) arba MariaDB versiją.'
                    : "✓ Schema applied, BUT the DB does not yet match — schema.sql NOT deleted. Missing: "
                      . trim(($stillT ? "tables: $stillT; " : '') . ($stillC ? 'columns: ' . implode(' ', $stillC) : '')),
            ];
        }

        // DB matches the schema. Describe whether it already matched or was extended.
        if ($addedTables === 0 && $addedCols === 0) {
            $detail = $adminLang === 'lt'
                ? 'DB jau atitiko schemą.'
                : 'DB already matched the schema.';
        } else {
            $detail = $adminLang === 'lt'
                ? "DB praplėsta naujais elementais (+{$addedTables} lent., +{$addedCols} stulp.)."
                : "DB extended with new elements (+{$addedTables} tables, +{$addedCols} columns).";
        }

        return [
            'ok'            => true,
            'matchesSchema' => true,
            'msg'           => ($adminLang === 'lt'
                    ? "✓ Schema įdiegta į DB '{$name}'. {$detail}"
                    : "✓ Schema installed into DB '{$name}'. {$detail}")
                   . $eventWarning
                   . ($errors ? ' Kitos klaidos: ' . implode(' | ', array_slice($errors, 0, 2)) : ''),
        ];

    } catch (PDOException $e) {
        $hint = str_contains($e->getMessage(), 'Unknown database')
              ? " → Pirmiau sukurkite DB '{$name}' per Hostinger skydelį arba phpMyAdmin."
              : '';
        return ['ok' => false, 'msg' => 'Klaida: ' . $e->getMessage() . $hint];
    }
}

// ── Check whether the DB is reachable AND tables are installed ──
// Used to decide whether to show the schema install block.
function schemaStatus(): array {
    $cfg = readCurrentConfig();
    if (empty($cfg['DB_NAME']) || empty($cfg['DB_USER'])) {
        return ['db_ok' => false, 'tables_ok' => false, 'reason' => 'no_config'];
    }
    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
            $cfg['DB_HOST'] ?? 'localhost', $cfg['DB_NAME']);
        $pdo = new PDO($dsn, $cfg['DB_USER'], $cfg['DB_PASS'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
        $sensors  = count($pdo->query("SHOW TABLES LIKE 'sensors'")->fetchAll()) > 0;
        $readings = count($pdo->query("SHOW TABLES LIKE 'readings'")->fetchAll()) > 0;
        return ['db_ok' => true, 'tables_ok' => ($sensors && $readings), 'reason' => 'ok'];
    } catch (PDOException $e) {
        return ['db_ok' => false, 'tables_ok' => false, 'reason' => 'connect_fail'];
    }
}

// ── Automatic schema migration ───────────────────────────
// Checks whether `sensors.mac` already allows NULL and whether the required
// unique key exists. If the DB was created by an older version (mac NOT NULL),
// it migrates to the new format. Returns a message about the actions taken
// or null if nothing needed changing.
function migrateSchema(): ?string {
    $cfg = readCurrentConfig();
    if (empty($cfg['DB_NAME']) || empty($cfg['DB_USER'])) return null;
    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
            $cfg['DB_HOST'] ?? 'localhost', $cfg['DB_NAME']);
        $pdo = new PDO($dsn, $cfg['DB_USER'], $cfg['DB_PASS'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);

        // Does the sensors table exist?
        if (count($pdo->query("SHOW TABLES LIKE 'sensors'")->fetchAll()) === 0) {
            return null; // not installed yet — no migration needed
        }

        $changes = [];

        // 1. Is there a `mac` column?
        $cols = $pdo->query("SHOW COLUMNS FROM sensors LIKE 'mac'")->fetchAll(PDO::FETCH_ASSOC);
        if (count($cols) === 0) {
            // Old schema without MAC — add the column
            $pdo->exec("ALTER TABLE sensors ADD COLUMN mac VARCHAR(17) NULL DEFAULT NULL AFTER lng");
            $changes[] = 'pridėtas mac stulpelis';
        } else {
            // Does mac allow NULL?
            $macCol = $cols[0];
            if (strtoupper($macCol['Null'] ?? '') === 'NO') {
                // Empty '' → NULL, then allow NULL
                $pdo->exec("UPDATE sensors SET mac = NULL WHERE mac = ''");
                $pdo->exec("ALTER TABLE sensors MODIFY mac VARCHAR(17) NULL DEFAULT NULL");
                $changes[] = 'mac stulpelis pakeistas į NULL';
            }
        }

        // 1b. Is there an `is_outdoor` column (indoor/outdoor)?
        $outCol = $pdo->query("SHOW COLUMNS FROM sensors LIKE 'is_outdoor'")->fetchAll(PDO::FETCH_ASSOC);
        if (count($outCol) === 0) {
            $pdo->exec("ALTER TABLE sensors ADD COLUMN is_outdoor TINYINT(1) NOT NULL DEFAULT 0 AFTER mac");
            $changes[] = 'pridėtas is_outdoor stulpelis (patalpos/lauko)';
        }

        // 1c. Is there a `secret` column (for the HMAC signature)?
        $secCol = $pdo->query("SHOW COLUMNS FROM sensors LIKE 'secret'")->fetchAll(PDO::FETCH_ASSOC);
        if (count($secCol) === 0) {
            $pdo->exec("ALTER TABLE sensors ADD COLUMN secret VARCHAR(64) NULL DEFAULT NULL AFTER is_outdoor");
            $changes[] = 'pridėtas secret stulpelis (HMAC)';
        }

        // 1d. Index (city_prefix, id) for numbering performance
        $idxCity = $pdo->query("SHOW INDEX FROM sensors WHERE Key_name = 'idx_city_id'")->fetchAll();
        if (count($idxCity) === 0) {
            try { $pdo->exec("ALTER TABLE sensors ADD INDEX idx_city_id (city_prefix, id)"); $changes[] = 'pridėtas indeksas (city_prefix, id)'; } catch (PDOException) {}
        }

        // 1e. Helper tables: rate_limits, audit_log
        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            rl_key VARCHAR(120) NOT NULL,
            window_start INT NOT NULL,
            counter INT NOT NULL DEFAULT 0,
            PRIMARY KEY (rl_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actor_ip VARCHAR(45) NULL,
            action VARCHAR(40) NOT NULL,
            target_id INT NULL,
            details VARCHAR(255) NULL,
            INDEX idx_audit_time (occurred_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 1f. Admin credentials: email hash (username) + password-change flag
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_credentials (
            id TINYINT UNSIGNED NOT NULL DEFAULT 1,
            email_hash VARCHAR(255) NOT NULL,
            password_change_required TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $idx = $pdo->query("SHOW INDEX FROM sensors")->fetchAll(PDO::FETCH_ASSOC);
        $keyCols = [];
        foreach ($idx as $row) {
            if (($row['Non_unique'] ?? 1) == 0 && ($row['Key_name'] ?? '') !== 'PRIMARY') {
                $keyCols[$row['Key_name']][] = $row['Column_name'];
            }
        }
        $hasMacKey = false;
        foreach ($keyCols as $name => $c) {
            if (in_array('mac', $c, true)) { $hasMacKey = true; break; }
        }
        if (!$hasMacKey) {
            // Remove old unique coordinate keys
            foreach ($keyCols as $name => $c) {
                if (in_array('lat', $c, true) && in_array('lng', $c, true) && !in_array('mac', $c, true)) {
                    try { $pdo->exec("ALTER TABLE sensors DROP INDEX `" . str_replace('`','',$name) . "`"); } catch (PDOException) {}
                }
            }
            try {
                $pdo->exec("ALTER TABLE sensors ADD UNIQUE KEY uq_coords_mac (lat, lng, mac)");
                $changes[] = 'sukurtas unikalus raktas (lat, lng, mac)';
            } catch (PDOException) {}
        }

        return $changes ? implode('; ', $changes) : null;

    } catch (PDOException $e) {
        return null;
    }
}

// ── Delete schema.sql after a successful install ───────────
// After installation schema.sql is no longer needed and may reveal the DB
// structure — so it is SIMPLY DELETED (not archived to a copy).
// Returns true if deleted, false if it failed or the file was absent.
function deleteSchemaFile(): bool {
    if (!file_exists(SCHEMA_PATH)) {
        return false; // already deleted or absent
    }
    return @unlink(SCHEMA_PATH);
}

// ── Read existing configuration values (if the file exists) ──
function readCurrentConfig(): array {
    if (!file_exists(CONFIG_PATH)) return [];
    $src = file_get_contents(CONFIG_PATH);
    $out = [];
    foreach (['DB_HOST','DB_NAME','DB_USER','DB_PASS','GMAPS_API_KEY','SITE_TITLE_LT','SITE_TITLE_EN','MAP_COUNTRY','CITY_PREFIX','SENSOR_TIMEOUT_MIN','DATA_RETENTION_DAYS','MAP_TILE_PROVIDER','MAP_TILE_URL'] as $key) {
        if (preg_match("/define\('{$key}',\s*'([^']*)'\)/", $src, $m)) $out[$key] = $m[1];
        if (preg_match("/define\('{$key}',\s*(\d+)\)/", $src, $m))     $out[$key] = $m[1];
    }
    return $out;
}
$cfg = readCurrentConfig();

?>
<!DOCTYPE html>
<html lang="lt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>IoT Admin — <?= $adminLang === 'lt' ? 'Sąranka' : 'Setup' ?></title>
<link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
<style>
:root {
  --bg:      #0a0f1e;
  --surf:    #111827;
  --surf2:   #1a2235;
  --bord:    #1e2d45;
  --accent:  #00c8ff;
  --ok:      #22d3a0;
  --err:     #ef4444;
  --warn:    #f59e0b;
  --text:    #e2e8f0;
  --muted:   #64748b;
  --mono:    'JetBrains Mono', 'Fira Code', monospace;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: system-ui, sans-serif; background: var(--bg); color: var(--text);
       min-height: 100vh; display: flex; align-items: flex-start; justify-content: center; padding: 2rem 1rem 3.5rem; }
/* The login box is centered vertically, the dashboard starts from the top */
.login-box { margin-top: max(0px, calc(50vh - 280px)); }

/* ─ Login ─ */
.login-box { background: var(--surf); border: 1px solid var(--bord); border-radius: 14px;
             padding: 2.5rem 2rem; width: min(400px, 96vw); }
.login-box h1 { font-size: 1.1rem; font-weight: 700; letter-spacing: .06em; color: var(--accent);
                text-transform: uppercase; margin-bottom: .3rem; }
.login-box p  { font-size: .8rem; color: var(--muted); margin-bottom: 1.5rem; }
.login-setup  { background: rgba(245,158,11,.08); border: 1px solid rgba(245,158,11,.3);
                border-radius: 8px; padding: .75rem 1rem; margin-bottom: 1rem;
                font-size: .78rem; color: var(--warn); line-height: 1.6; }
.login-setup code { font-family: var(--mono); background: rgba(245,158,11,.12);
                    padding: 1px 5px; border-radius: 3px; font-size: .75rem; }

/* ─ Admin layout ─ */
.admin-wrap { width: min(780px, 96vw); }
.admin-header { display: flex; align-items: center; justify-content: space-between;
                margin-bottom: 1.5rem; }
.admin-header h1 { font-size: 1rem; font-weight: 700; letter-spacing: .08em;
                   color: var(--accent); text-transform: uppercase; }
.admin-header a { font-size: .78rem; color: var(--muted); text-decoration: none; }
.admin-header a:hover { color: var(--text); }

/* ─ Language switch ─ */
.lang-switch { display: flex; gap: .25rem; }
.lang-switch a {
  font-size: .72rem; font-weight: 600;
  padding: .2rem .5rem; border-radius: 5px;
  color: var(--muted); text-decoration: none;
  border: 1px solid var(--bord);
}
.lang-switch a.active { background: var(--accent); color: var(--bg); border-color: var(--accent); }
.lang-switch a:hover:not(.active) { color: var(--text); border-color: var(--muted); }

/* ─ Steps ─ */
.step { background: var(--surf); border: 1px solid var(--bord); border-radius: 12px;
        margin-bottom: 1rem; overflow: hidden; }
.step-head { display: flex; align-items: center; gap: .75rem;
             padding: 1rem 1.25rem; cursor: pointer; }
.step-num { width: 28px; height: 28px; border-radius: 50%; background: var(--surf2);
            border: 1px solid var(--bord); display: flex; align-items: center; justify-content: center;
            font-size: .75rem; font-weight: 700; color: var(--accent); flex-shrink: 0; }
.step-head h2 { font-size: .9rem; font-weight: 600; }
.step-head p  { font-size: .75rem; color: var(--muted); }
.step-body { padding: 0 1.25rem 1.25rem; border-top: 1px solid var(--bord); }

/* ─ Form ─ */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; margin-top: 1rem; }
.form-grid.single { grid-template-columns: 1fr; }
.field { display: flex; flex-direction: column; gap: .3rem; }
.field label { font-size: .7rem; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); }
.field input  { background: var(--surf2); border: 1px solid var(--bord); border-radius: 6px;
                color: var(--text); padding: .55rem .75rem; font-family: var(--mono); font-size: .85rem;
                outline: none; transition: border-color .15s; }
.field input:focus { border-color: var(--accent); }
.field small  { font-size: .68rem; color: var(--muted); line-height: 1.5; }

/* ─ Buttons ─ */
.btn-row { display: flex; gap: .6rem; margin-top: 1rem; flex-wrap: wrap; }
.btn { padding: .5rem 1.1rem; border-radius: 7px; border: 1px solid var(--bord);
       background: var(--surf2); color: var(--text); font-size: .82rem; cursor: pointer;
       transition: background .15s; }
.btn:hover { background: var(--bord); }
.btn-primary { background: var(--accent); color: var(--bg); border-color: var(--accent); font-weight: 700; }
.btn-primary:hover { background: #00a8d8; }
.btn-danger  { background: transparent; color: var(--err); border-color: var(--err); }
.btn-danger:hover  { background: rgba(239,68,68,.1); }

/* ─ Alert ─ */
.alert { border-radius: 8px; padding: .7rem 1rem; font-size: .82rem; margin-bottom: 1rem;
         line-height: 1.5; }
.alert-ok   { background: rgba(34,211,160,.1); border: 1px solid rgba(34,211,160,.3); color: var(--ok); }
.alert-err  { background: rgba(239,68,68,.1);  border: 1px solid rgba(239,68,68,.3);  color: var(--err); }
.alert-warn { background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.3); color: var(--warn); }

/* ─ Schema preview ─ */
.schema-box { background: var(--surf2); border: 1px solid var(--bord); border-radius: 8px;
              padding: .9rem 1rem; font-family: var(--mono); font-size: .7rem; color: var(--muted);
              max-height: 180px; overflow-y: auto; white-space: pre; margin-top: 1rem;
              line-height: 1.6; }

/* ─ Status indicator ─ */
.status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: .4rem; }
.dot-ok   { background: var(--ok); }
.dot-warn { background: var(--warn); }
.dot-err  { background: var(--err); }

.divider { border: none; border-top: 1px solid var(--bord); margin: 1rem 0; }

/* ─ Security note ─ */
.security-note { background: rgba(239,68,68,.07); border: 1px solid rgba(239,68,68,.2);
                 border-radius: 8px; padding: .75rem 1rem; font-size: .75rem;
                 color: #fca5a5; margin-top: 1.25rem; line-height: 1.6; }

.btn-sm { padding: .28rem .6rem; font-size: .72rem; }
.log-table { width: 100%; border-collapse: collapse; font-size: .76rem; }
.log-table th, .log-table td { text-align: left; padding: .4rem .5rem; border-bottom: 1px solid var(--bord); vertical-align: top; }
.log-table th { color: var(--muted); font-weight: 600; }
.log-table code { font-family: var(--mono); font-size: .72rem; }

@media (max-width: 520px) { .form-grid { grid-template-columns: 1fr; } }

/* ─ Attribution footer (required by the license) ─ */
.admin-attribution {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  text-align: center;
  padding: .6rem 1rem;
  background: var(--surf);
  border-top: 1px solid var(--bord);
  font-size: .72rem;
  color: var(--muted);
  line-height: 1.5;
  z-index: 100;
}
.admin-attribution strong { color: var(--text); font-weight: 600; }
.admin-attribution .sep { opacity: .5; margin: 0 .35rem; }
</style>
</head>
<body>

<?php if (!$isAdmin): ?>
<!-- ════════════════ LOGIN ════════════════ -->
<div class="login-box">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:.3rem">
    <h1>IoT Admin</h1>
    <div class="lang-switch">
      <a href="?lang=lt" class="<?= $adminLang === 'lt' ? 'active' : '' ?>">LT</a>
      <a href="?lang=en" class="<?= $adminLang === 'en' ? 'active' : '' ?>">EN</a>
    </div>
  </div>
  <p><?= tr('admin_subtitle') ?></p>

  <?php if ($hashIsDefault): ?>
  <div class="login-setup">
    ⚠ <strong><?= tr('setup_login_warn') ?></strong>
  </div>
  <?php endif; ?>

  <?php if (!$credentialsComplete): ?>
    <?php /* First run / no email set yet — single password step. */ ?>
    <?php if ($loginError): ?>
      <div class="alert alert-err"><?= htmlspecialchars($loginError) ?></div>
    <?php endif; ?>
    <?php if ($blockSeconds > 0): ?>
      <div class="alert alert-err" style="text-align:center">
        <?php $bm = (int)ceil($blockSeconds / 60); ?>
        <?= $lt ? "🔒 IP adresas užblokuotas dėl per daug nesėkmingų bandymų.<br>Bandykite vėl po ~{$bm} min."
                : "🔒 IP address blocked due to too many failed attempts.<br>Try again in ~{$bm} min." ?>
      </div>
    <?php else: ?>
      <form method="POST">
        <?= csrfField() ?>
        <div class="field" style="margin-bottom:.75rem">
          <label><?= tr('password') ?></label>
          <input type="password" name="password" autofocus autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%"><?= tr('login') ?></button>
      </form>
    <?php endif; ?>

  <?php elseif ($loginStage === 2): ?>
    <?php /* Stage 2 — full credentials: email + password. */ ?>
    <?php if ($loginError): ?>
      <div class="alert alert-err"><?= htmlspecialchars($loginError) ?></div>
    <?php endif; ?>
    <?php if ($blockSeconds > 0): ?>
      <div class="alert alert-err" style="text-align:center">
        <?php $bh = (int)ceil($blockSeconds / 3600); ?>
        <?= $lt ? "🔒 IP adresas užblokuotas. Bandykite vėl po ~{$bh} val."
                : "🔒 IP address blocked. Try again in ~{$bh}h." ?>
      </div>
    <?php else: ?>
      <form method="POST">
        <?= csrfField() ?>
        <div class="field" style="margin-bottom:.6rem">
          <label><?= $lt ? 'El. paštas (naudotojo vardas)' : 'Email (username)' ?></label>
          <input type="email" name="stage2_email" autocomplete="username" required>
        </div>
        <div class="field" style="margin-bottom:.75rem">
          <label><?= tr('password') ?></label>
          <input type="password" name="stage2_password" autofocus autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%"><?= tr('login') ?></button>
      </form>
      <div style="text-align:center;margin-top:.6rem;font-size:.72rem">
        <a href="?reset_login=1" style="color:var(--muted);text-decoration:none"><?= $lt ? '← Pradėti iš naujo' : '← Start over' ?></a>
      </div>
    <?php endif; ?>

  <?php else: ?>
    <?php /* Stage 1 — email (username) only. Failures are SILENT (no message). */ ?>
    <form method="POST">
      <?= csrfField() ?>
      <div class="field" style="margin-bottom:.75rem">
        <label><?= $lt ? 'El. paštas (naudotojo vardas)' : 'Email (username)' ?></label>
        <input type="email" name="stage1_email" autofocus autocomplete="username" required>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%"><?= $lt ? 'Tęsti' : 'Continue' ?></button>
    </form>
  <?php endif; ?>
  <div style="text-align:center;margin-top:1rem;font-size:.72rem">
    <a href="../cookies.php" style="color:var(--muted);text-decoration:none"><?= $adminLang === 'lt' ? 'Slapukai' : 'Cookies' ?></a>
    <span style="opacity:.4">·</span>
    <a href="../privacy.php" style="color:var(--muted);text-decoration:none"><?= $adminLang === 'lt' ? 'Privatumas' : 'Privacy' ?></a>
  </div>
</div>

<?php else: ?>
<!-- ════════════════ ADMIN DASHBOARD ════════════════ -->
<div class="admin-wrap">

  <div class="admin-header">
    <div>
      <h1><?= tr('setup_title') ?></h1>
      <?php if ($setupMode): ?>
        <span style="font-size:.72rem;color:var(--warn)">⚠ <?= tr('setup_mode') ?></span>
      <?php endif; ?>
    </div>
    <div style="display:flex;align-items:center;gap:1rem">
      <div class="lang-switch">
        <a href="?lang=lt" class="<?= $adminLang === 'lt' ? 'active' : '' ?>">LT</a>
        <a href="?lang=en" class="<?= $adminLang === 'en' ? 'active' : '' ?>">EN</a>
      </div>
      <a href="?logout=1"><?= tr('logout') ?></a>
    </div>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <?php if (passwordChangeRequired()): ?>
    <div class="alert alert-err" style="line-height:1.6">
      🔒 <strong><?= $adminLang === 'lt' ? 'Saugumo įspėjimas:' : 'Security warning:' ?></strong>
      <?= $adminLang === 'lt'
          ? 'buvo užfiksuoti pakartotiniai nesėkmingi prisijungimo bandymai (24 val. blokas). Rekomenduojama nedelsiant pakeisti slaptažodį žemiau esančiame skyriuje „Slaptažodžio keitimas".'
          : 'repeated failed login attempts were detected (24h block). It is strongly recommended to change your password now in the “Change password” section below.' ?>
    </div>
  <?php endif; ?>

  <!-- ─── 1. DB AND GMAPS CONFIGURATION ─────────────────── -->
  <div class="step">
    <div class="step-head">
      <div class="step-num">1</div>
      <div>
        <h2><?= tr('sec_main_data') ?></h2>
        <p><?= tr('sec_main_sub') ?></p>
      </div>
    </div>
    <div class="step-body">
      <form method="POST">
        <?= csrfField() ?>
        <div class="form-grid">
          <!-- ══ Pagrindiniai duomenys ══ -->
          <div class="field">
            <label><?= tr('site_title_lt') ?></label>
            <input type="text" name="site_title_lt" maxlength="80"
                   value="<?= htmlspecialchars($cfg['SITE_TITLE_LT'] ?? 'IoT Jutiklių Žemėlapis') ?>"
                   placeholder="IoT Jutiklių Žemėlapis">
          </div>
          <div class="field">
            <label><?= tr('site_title_en') ?></label>
            <input type="text" name="site_title_en" maxlength="80"
                   value="<?= htmlspecialchars($cfg['SITE_TITLE_EN'] ?? 'IoT Sensor Map') ?>"
                   placeholder="IoT Sensor Map">
          </div>
          <div class="field" style="grid-column:1/-1">
            <small style="color:var(--muted)"><?= tr('site_title_hint') ?></small>
          </div>
          <div class="field" style="grid-column:1/-1">
            <label><?= tr('contact_email_label') ?></label>
            <input type="email" name="contact_email" maxlength="190"
                   value="<?= htmlspecialchars($cfg['CONTACT_EMAIL'] ?? '') ?>"
                   placeholder="info@example.com"
                   autocomplete="off" data-lpignore="true" data-1p-ignore>
            <small style="color:var(--muted)"><?= tr('contact_email_hint') ?></small>
          </div>
          <div class="field">
            <label><?= tr('map_country_label') ?></label>
            <select name="map_country" style="background:var(--surf2);border:1px solid var(--bord);
                    border-radius:6px;color:var(--text);padding:.5rem .6rem;font-size:.85rem;width:100%">
              <?php
              $selCountry = $cfg['MAP_COUNTRY'] ?? 'LT';
              foreach (capitalList() as $code => $info):
              ?>
                <option value="<?= $code ?>" <?= $code === $selCountry ? 'selected' : '' ?>>
                  <?php
                    $cityEn = $info[1];
                    $cityLt = capitalNameLt($cityEn);
                    $cityLabel = ($cityLt !== $cityEn) ? "$cityLt / $cityEn" : $cityEn;
                  ?>
                  <?= htmlspecialchars($info[0]) ?> — <?= htmlspecialchars($cityLabel) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <small><?= tr('map_country_hint') ?></small>
          </div>
          <div class="field">
            <label><?= tr('prefix_label') ?></label>
            <input type="text" name="city_prefix"
                   value="<?= htmlspecialchars($cfg['CITY_PREFIX'] ?? 'VLN') ?>"
                   placeholder="VLN" maxlength="10">
            <small><?= tr('prefix_example') ?></small>
          </div>
          <div class="field">
            <label><?= tr('timeout_label') ?></label>
            <input type="number" name="sensor_timeout"
                   value="<?= htmlspecialchars($cfg['SENSOR_TIMEOUT_MIN'] ?? '3') ?>"
                   min="1" max="60">
            <small><?= tr('timeout_hint') ?></small>
          </div>
          <div class="field">
            <label><?= tr('retention_label') ?></label>
            <input type="number" name="data_retention"
                   value="<?= htmlspecialchars($cfg['DATA_RETENTION_DAYS'] ?? '0') ?>"
                   min="0" max="3650">
            <small><?= tr('retention_hint') ?></small>
          </div>
          <div class="field" style="grid-column:1/-1">
            <label><?= tr('tile_provider_label') ?></label>
            <?php
              $selTile = $cfg['MAP_TILE_PROVIDER'] ?? '';
              if ($selTile === '') {
                  $selTile = !empty($cfg['GMAPS_API_KEY']) ? 'google' : 'opentopomap';
              }
            ?>
            <select name="tile_provider" id="tileProviderSelect" onchange="updateMapFields()"
                    style="background:var(--surf2);border:1px solid var(--bord);
                    border-radius:6px;color:var(--text);padding:.5rem .6rem;font-size:.85rem;width:100%">
              <option value="google" <?= $selTile === 'google' ? 'selected' : '' ?>>Google Maps (reikia API rakto)</option>
              <option value="opentopomap" <?= $selTile === 'opentopomap' ? 'selected' : '' ?>>OpenTopoMap (topografinis)</option>
              <option value="carto_voyager" <?= $selTile === 'carto_voyager' ? 'selected' : '' ?>>CARTO Voyager (spalvotas)</option>
              <option value="carto_light" <?= $selTile === 'carto_light' ? 'selected' : '' ?>>CARTO Light (šviesus)</option>
              <option value="osm" <?= $selTile === 'osm' ? 'selected' : '' ?>>OpenStreetMap (standartinis)</option>
              <option value="yandex" <?= $selTile === 'yandex' ? 'selected' : '' ?>>Yandex Maps (regioninis, EPSG:3395)</option>
              <option value="custom" <?= $selTile === 'custom' ? 'selected' : '' ?>>Savas URL / Custom</option>
            </select>
            <small style="color:var(--muted)"><?= tr('tile_provider_hint') ?></small>
          </div>

          <!-- Google key field — shown ONLY when Google Maps is selected -->
          <div class="field" id="gmapsKeyField" style="grid-column:1/-1;display:none">
            <label><?= tr('gmaps_key') ?></label>
            <input type="text" name="gmaps_key"
                   value="<?= htmlspecialchars($cfg['GMAPS_API_KEY'] ?? '') ?>"
                   placeholder="AIzaSy...">
            <small>
              <a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer" style="color:var(--accent)">console.cloud.google.com</a>
              <?= tr('gmaps_hint') ?>
            </small>
          </div>

          <!-- Custom tile URL — shown ONLY when Custom is selected -->
          <div class="field" id="tileUrlField" style="grid-column:1/-1;display:none">
            <label><?= tr('tile_url_label') ?></label>
            <input type="text" name="tile_url"
                   value="<?= htmlspecialchars($cfg['MAP_TILE_URL'] ?? '') ?>"
                   placeholder="https://.../{z}/{x}/{y}.png">
            <small style="color:var(--muted)"><?= tr('tile_url_hint') ?></small>
          </div>

          <!-- ══ Duomenų bazė (MySQL prisijungimas) ══ -->
          <div class="field" style="grid-column:1/-1;margin-top:.5rem;border-top:1px solid var(--bord);padding-top:1rem">
            <strong style="font-size:.9rem;color:var(--text)">🗄 <?= tr('sec_database') ?></strong>
            <small style="color:var(--muted);display:block"><?= tr('sec_database_sub') ?></small>
          </div>
          <div class="field">
            <label><?= tr('db_host') ?></label>
            <input type="text" name="db_host" value="<?= htmlspecialchars($cfg['DB_HOST'] ?? 'localhost') ?>" placeholder="localhost">
            <small>Hostinger: paprastai <code>localhost</code></small>
          </div>
          <div class="field">
            <label><?= tr('db_name') ?></label>
            <input type="text" name="db_name" value="<?= htmlspecialchars($cfg['DB_NAME'] ?? '') ?>" placeholder="u123456_iot" required>
          </div>
          <div class="field">
            <label><?= tr('db_user') ?></label>
            <input type="text" name="db_user" value="<?= htmlspecialchars($cfg['DB_USER'] ?? '') ?>" placeholder="u123456_user" required>
          </div>
          <div class="field">
            <label><?= tr('db_pass') ?></label>
            <input type="password" name="db_pass" value="<?= htmlspecialchars($cfg['DB_PASS'] ?? '') ?>" placeholder="••••••••" autocomplete="new-password">
          </div>
        </div>
        <hr class="divider">
        <div class="btn-row">
          <button type="submit" name="test_db" class="btn"><?= tr('test_conn') ?></button>
          <button type="submit" name="save_config" class="btn btn-primary"><?= tr('save_config') ?></button>
        </div>
      </form>

      <?php
      // Show config.php status
      $configExists = file_exists(CONFIG_PATH);
      $configWritable = $configExists ? is_writable(CONFIG_PATH) : is_writable(dirname(CONFIG_PATH));
      ?>
      <hr class="divider">
      <div style="font-size:.75rem;color:var(--muted)">
        <span class="status-dot <?= $configExists ? 'dot-ok' : 'dot-warn' ?>"></span>
        config.php: <?= $configExists ? tr('cfg_exists') : tr('cfg_missing') ?>
        &nbsp;|&nbsp;
        <span class="status-dot <?= $configWritable ? 'dot-ok' : 'dot-err' ?>"></span>
        <?= $configWritable ? tr('cfg_writable') : tr('cfg_denied') ?>
        &nbsp;|&nbsp;
        <?= tr('cfg_path') ?>: <code style="font-family:var(--mono)"><?= htmlspecialchars(CONFIG_PATH) ?></code>
      </div>
    </div>
  </div>

  <!-- ─── 2. DB SCHEMA INSTALLATION ──────────────────────── -->
  <div class="step">
    <div class="step-head">
      <div class="step-num">2</div>
      <div>
        <h2><?= tr('step2_title') ?></h2>
        <p><?= tr('step2_sub') ?></p>
      </div>
    </div>
    <div class="step-body">
      <?php $schStatus = schemaStatus(); ?>
      <?php if ($schStatus['tables_ok']): ?>
        <!-- DB reachable and tables already exist — no install needed -->
        <div style="background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25);
                    border-radius:8px;padding:1rem;margin-top:.75rem">
          <div style="font-weight:600;color:var(--ok);margin-bottom:.3rem"><?= tr('schema_done_title') ?></div>
          <div style="font-size:.82rem;color:var(--muted);line-height:1.6"><?= tr('schema_done_t') ?></div>
        </div>
        <details style="margin-top:1rem">
          <summary style="cursor:pointer;font-size:.8rem;color:var(--muted)"><?= tr('schema_reinstall') ?></summary>
          <form method="POST" enctype="multipart/form-data" onsubmit="return confirm(<?= htmlspecialchars(json_encode(tr('install_confirm')), ENT_QUOTES) ?>)" style="margin-top:.75rem">
            <?= csrfField() ?>
            <input type="hidden" name="db_host" value="<?= htmlspecialchars($cfg['DB_HOST'] ?? 'localhost') ?>">
            <input type="hidden" name="db_name" value="<?= htmlspecialchars($cfg['DB_NAME'] ?? '') ?>">
            <input type="hidden" name="db_user" value="<?= htmlspecialchars($cfg['DB_USER'] ?? '') ?>">
            <input type="hidden" name="db_pass" value="<?= htmlspecialchars($cfg['DB_PASS'] ?? '') ?>">
            <?php if (!file_exists(SCHEMA_PATH)): ?>
              <div style="font-size:.78rem;color:var(--muted);margin-bottom:.5rem"><?= tr('schema_upload_hint') ?></div>
              <input type="file" name="schema_file" accept=".sql" required
                     style="font-size:.8rem;margin-bottom:.75rem;color:var(--text)">
              <br>
            <?php endif; ?>
            <button type="submit" name="install_schema" class="btn"><?= tr('install_schema') ?></button>
          </form>
        </details>
      <?php else: ?>
      <p style="font-size:.8rem;color:var(--muted);line-height:1.6;margin-top:.75rem">
        <strong style="color:var(--warn)"><?= tr('schema_assume') ?></strong> <?= tr('schema_assume_t') ?><br>
        <?= tr('schema_creates') ?><br>
        <?= tr('schema_idem') ?>
      </p>

      <?php
      $schemaExists = file_exists(SCHEMA_PATH);
      $schemaSize   = $schemaExists ? number_format(filesize(SCHEMA_PATH)) : 0;
      ?>
      <div style="font-size:.75rem;color:var(--muted);margin:.75rem 0 0">
        <span class="status-dot <?= $schemaExists ? 'dot-ok' : 'dot-err' ?>"></span>
        schema.sql: <?= $schemaExists ? tr('schema_found') . " ({$schemaSize} " . tr('schema_bytes') . ')' : tr('schema_notfound') ?>
      </div>

      <?php if ($schemaExists): ?>
        <div class="schema-box"><?= htmlspecialchars(file_get_contents(SCHEMA_PATH)) ?></div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data" onsubmit="return confirm(<?= htmlspecialchars(json_encode(tr('install_confirm')), ENT_QUOTES) ?>)">
        <?= csrfField() ?>
        <?php if (!$schemaExists): ?>
          <div style="background:rgba(245,158,11,.07);border-left:3px solid var(--warn);
                      border-radius:0 8px 8px 0;padding:.7rem 1rem;margin-top:1rem;font-size:.8rem;color:var(--warn)">
            <?= tr('schema_upload_hint') ?>
          </div>
          <div class="field" style="margin-top:.75rem">
            <label><?= tr('schema_upload') ?></label>
            <input type="file" name="schema_file" accept=".sql" required
                   style="font-size:.82rem;color:var(--text);padding:.4rem 0">
          </div>
        <?php endif; ?>
        <div class="form-grid" style="margin-top:1rem">
          <div class="field">
            <label><?= tr('db_host') ?></label>
            <input type="text" name="db_host" value="<?= htmlspecialchars($cfg['DB_HOST'] ?? 'localhost') ?>">
          </div>
          <div class="field">
            <label><?= tr('db_name') ?></label>
            <input type="text" name="db_name" value="<?= htmlspecialchars($cfg['DB_NAME'] ?? '') ?>" required>
          </div>
          <div class="field">
            <label><?= tr('db_user') ?></label>
            <input type="text" name="db_user" value="<?= htmlspecialchars($cfg['DB_USER'] ?? '') ?>" required>
          </div>
          <div class="field">
            <label><?= tr('db_pass') ?></label>
            <input type="password" name="db_pass" value="<?= htmlspecialchars($cfg['DB_PASS'] ?? '') ?>" autocomplete="new-password">
          </div>
        </div>
        <div class="btn-row">
          <button type="submit" name="install_schema" class="btn btn-primary">
            <?= tr('install_schema') ?>
          </button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- ─── 3. IP APSAUGA (.htaccess) ─────────────────────── -->
  <?php
  $localhostIps = ['127.0.0.1', '::1'];
  $allowedIps   = readAllowedIps();
  // "Real" IPs — excluding localhost. Protection is on when there is at least one.
  $realIps      = array_values(array_diff($allowedIps, $localhostIps));
  $myIp         = clientIp();
  $ipActive     = count($realIps) > 0;
  ?>
  <div class="step">
    <div class="step-head">
      <div class="step-num">3</div>
      <div>
        <h2><?= tr('step3_title') ?></h2>
        <p><?= $adminLang === 'lt'
            ? 'Pagal numatymą admin.php pasiekiamas iš bet kur (saugo slaptažodis). Įjunkite IP apribojimą tik įsitikinę savo IP.'
            : 'By default admin.php is reachable from anywhere (password-protected). Enable IP restriction only once you are sure of your IP.' ?></p>
      </div>
    </div>
    <div class="step-body">
      <div style="font-size:.8rem;margin-top:.75rem;line-height:1.7">
        <span class="status-dot <?= $ipActive ? 'dot-ok' : 'dot-warn' ?>"></span>
        <?= tr('ip_state') ?>: <strong><?= $ipActive ? tr('ip_on') : tr('ip_off') ?></strong><br>
        <?= tr('your_ip') ?>: <code style="font-family:var(--mono);color:var(--accent)"><?= htmlspecialchars($myIp) ?></code>
      </div>

      <div style="margin-top:1rem;font-size:.78rem;color:var(--muted)"><?= tr('allowed_ips') ?></div>
      <div style="margin-top:.4rem;display:flex;flex-direction:column;gap:.35rem">
        <?php // Localhost — always allowed, cannot be removed ?>
        <div style="display:flex;align-items:center;gap:.6rem;background:var(--surf2);
                    border:1px solid var(--bord);border-radius:6px;padding:.4rem .75rem;opacity:.75">
          <code style="font-family:var(--mono);font-size:.82rem;flex:1">
            127.0.0.1, ::1
            <span style="color:var(--muted);font-size:.7rem"> ← localhost (<?= $adminLang === 'lt' ? 'visada' : 'always' ?>)</span>
          </code>
        </div>
        <?php foreach ($realIps as $ip): ?>
          <div style="display:flex;align-items:center;gap:.6rem;background:var(--surf2);
                      border:1px solid var(--bord);border-radius:6px;padding:.4rem .75rem">
            <code style="font-family:var(--mono);font-size:.82rem;flex:1">
              <?= htmlspecialchars($ip) ?>
              <?= $ip === $myIp ? '<span style="color:var(--ok);font-size:.7rem"> ← ' . tr('ip_you') . '</span>' : '' ?>
            </code>
            <form method="POST" style="margin:0">
        <?= csrfField() ?>
              <input type="hidden" name="rm_ip" value="<?= htmlspecialchars($ip) ?>">
              <button type="submit" name="ip_remove" class="btn btn-danger"
                      style="padding:.2rem .6rem;font-size:.72rem"
                      onclick="return confirm('Pašalinti IP <?= htmlspecialchars($ip) ?>?')">✕</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>

      <hr class="divider">

      <div class="btn-row" style="flex-direction:column;align-items:stretch;gap:.75rem">
        <?php if (!$ipActive): ?>
        <!-- First run: a quick button with YOUR IP -->
        <form method="POST" style="margin:0">
        <?= csrfField() ?>
          <button type="submit" name="ip_enable" class="btn btn-primary"
                  onclick="return confirm('<?= $adminLang === 'lt'
                    ? 'Įjungti IP apsaugą? Admin puslapis bus pasiekiamas iš localhost ir jūsų dabartinio IP: ' . htmlspecialchars($myIp) . '. Jei jūsų IP dinaminis (keičiasi), galite užsirakinti — tuomet bloką reikės pašalinti iš .htaccess per FTP/File Manager.'
                    : 'Enable IP protection? The admin page will be reachable from localhost and your current IP: ' . htmlspecialchars($myIp) . '. If your IP is dynamic (changes), you may lock yourself out — then the block must be removed from .htaccess via FTP/File Manager.' ?>')">
            <?= tr('ip_enable_btn') ?> (<?= htmlspecialchars($myIp) ?>)
          </button>
        </form>
        <?php endif; ?>

        <!-- Adding a specific IP — ALWAYS visible -->
        <form method="POST" style="margin:0;display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
        <?= csrfField() ?>
          <input type="text" name="new_ip" placeholder="185.xxx.xxx.xxx"
                 style="background:var(--surf2);border:1px solid var(--bord);border-radius:6px;
                        color:var(--text);padding:.45rem .75rem;font-family:var(--mono);font-size:.82rem;width:180px">
          <button type="submit" name="ip_add" class="btn"><?= tr('ip_add_btn') ?></button>
          <span style="font-size:.72rem;color:var(--muted)"><?= $adminLang === 'lt'
              ? '(įjungia apsaugą su šiuo IP + localhost)'
              : '(enables protection with this IP + localhost)' ?></span>
        </form>

        <?php if ($ipActive): ?>
        <form method="POST" style="margin:0">
        <?= csrfField() ?>
          <button type="submit" name="ip_disable" class="btn btn-danger"
                  onclick="return confirm('Išjungti IP apsaugą? Liks tik localhost prieiga; iš kitų IP admin.php bus nepasiekiamas (saugos slaptažodis tik localhost).')">
            <?= tr('ip_disable_btn') ?>
          </button>
        </form>
        <?php endif; ?>
      </div>

      <div class="note" style="background:rgba(245,158,11,.07);border-left:3px solid var(--warn);
                  border-radius:0 8px 8px 0;padding:.7rem 1rem;margin-top:1rem;
                  font-size:.75rem;color:var(--warn);line-height:1.6">
        ⚠ <strong><?= tr('dyn_ip_title') ?></strong> <?= tr('dyn_ip_body') ?>
        <code><?= htmlspecialchars(IP_BLOCK_BEGIN) ?></code> <?= $adminLang === 'lt' ? 'ir' : 'and' ?> <code><?= htmlspecialchars(IP_BLOCK_END) ?></code> <?= tr('dyn_ip_tail') ?>
      </div>
    </div>
  </div>

  <!-- ─── 4. PASSWORD CHANGE ───────────────────────────── -->
  <div class="step">
    <div class="step-head">
      <div class="step-num">4</div>
      <div>
        <h2><?= tr('sec_admin_login') ?></h2>
        <p><?= tr('sec_admin_login_sub') ?></p>
      </div>
    </div>
    <div class="step-body">
      <!-- ══ Prisijungimo el. paštas (naudotojo vardas) ══ -->
      <h3 style="font-size:.92rem;margin:.25rem 0 .5rem">
        <?= $adminLang === 'lt' ? '1) Prisijungimo el. paštas (naudotojo vardas)' : '1) Login email (username)' ?>
      </h3>
      <?php $emailSet = adminEmailIsSet(); ?>
      <div style="font-size:.8rem;margin-bottom:.5rem">
        <span class="status-dot <?= $emailSet ? 'dot-ok' : 'dot-err' ?>"></span>
        <?= $emailSet
            ? ($adminLang === 'lt' ? 'El. paštas nustatytas' : 'Email is set')
            : ($adminLang === 'lt' ? 'NENUSTATYTAS — būtina nustatyti (reikia veikiančios DB)' : 'NOT SET — required (needs a working DB)') ?>
      </div>
      <p style="font-size:.76rem;color:var(--muted);margin-bottom:.5rem">
        <?= $adminLang === 'lt'
            ? 'Saugomas kaip hash duomenų bazėje. Skiriasi nuo viešo komunikacinio el. pašto (1 žingsnyje).'
            : 'Stored as a hash in the database. Different from the public communication email (in step 1).' ?>
      </p>
      <form method="POST" autocomplete="off">
        <?= csrfField() ?>
        <div class="field">
          <label><?= $adminLang === 'lt' ? 'El. paštas (naudotojo vardas)' : 'Email (username)' ?></label>
          <input type="email" name="admin_email" autocomplete="off" required placeholder="admin@example.com">
        </div>
        <div class="btn-row">
          <button type="submit" name="set_email" class="btn btn-primary">
            <?= $emailSet
                ? ($adminLang === 'lt' ? '✉ Keisti el. paštą' : '✉ Change email')
                : ($adminLang === 'lt' ? '✉ Išsaugoti el. paštą' : '✉ Save email') ?>
          </button>
        </div>
      </form>

      <!-- ══ Slaptažodis ══ -->
      <hr class="divider">
      <h3 style="font-size:.92rem;margin:.25rem 0 .5rem">
        <?= $adminLang === 'lt' ? '2) Slaptažodis' : '2) Password' ?>
      </h3>
      <div style="font-size:.8rem;margin-bottom:.5rem">
        <span class="status-dot <?= !$hashIsDefault ? 'dot-ok' : 'dot-err' ?>"></span>
        <?= !$hashIsDefault ? tr('pw_status_set') : tr('pw_status_unset') ?>
      </div>
      <form method="POST" autocomplete="off">
        <?= csrfField() ?>
        <div class="form-grid">
          <?php if (!$setupMode && !$hashIsDefault): ?>
          <div class="field" style="grid-column:1/-1">
            <label><?= tr('current_pw') ?></label>
            <input type="password" name="current_password" autocomplete="off" required>
          </div>
          <?php endif; ?>
          <div class="field">
            <label><?= tr('new_pw') ?></label>
            <input type="password" name="new_password" id="newPwInput" minlength="8" autocomplete="new-password" required>
            <small id="pwReqHint" style="display:block;margin-top:.4rem;line-height:1.7;color:var(--muted)">
              <?= $adminLang === 'lt' ? 'Reikalavimai:' : 'Requirements:' ?><br>
              <span data-req="len">○ <?= $adminLang === 'lt' ? 'bent 8 simboliai' : 'at least 8 characters' ?></span><br>
              <span data-req="upper">○ <?= $adminLang === 'lt' ? 'bent viena didžioji raidė (A–Z)' : 'one uppercase letter (A–Z)' ?></span><br>
              <span data-req="digit">○ <?= $adminLang === 'lt' ? 'bent vienas skaičius (0–9)' : 'one digit (0–9)' ?></span><br>
              <span data-req="special">○ <?= $adminLang === 'lt' ? 'bent vienas specialus simbolis' : 'one special character' ?> (!@#$%^&amp;*()-_=+[]{};:,.?)</span>
            </small>
          </div>
          <div class="field">
            <label><?= tr('new_pw2') ?></label>
            <input type="password" name="new_password2" minlength="8" autocomplete="new-password" required>
          </div>
        </div>
        <div class="btn-row">
          <button type="submit" name="change_password" class="btn btn-primary"><?= tr('change_pw_btn') ?></button>
        </div>
      </form>
      <?php
      $selfWritable = is_writable(__DIR__ . '/includes');
      if (!$selfWritable): ?>
        <div style="font-size:.75rem;color:var(--err);margin-top:.75rem">
          <span class="status-dot dot-err"></span>
          <?= tr('pw_self_denied') ?>
        </div>
      <?php endif; ?>

      <!-- Admin file rename -->
      <hr class="divider">
      <h3 style="font-size:.92rem;margin-bottom:.5rem"><?= tr('rename_title') ?></h3>
      <p style="font-size:.78rem;color:var(--muted);margin-bottom:.75rem"><?= tr('rename_hint') ?></p>
      <form method="POST">
        <?= csrfField() ?>
        <div class="field">
          <label><?= tr('rename_label') ?></label>
          <div style="display:flex;align-items:center;gap:.4rem">
            <span style="font-family:var(--mono);color:var(--muted)">admin_</span>
            <input type="text" name="admin_new_name" placeholder="vilnius2026"
                   pattern="[A-Za-z0-9_-]+" maxlength="40" required
                   style="flex:1;font-family:var(--mono)">
            <span style="font-family:var(--mono);color:var(--muted)">.php</span>
          </div>
          <small><?= tr('rename_current') ?>: <code><?= htmlspecialchars(basename(__FILE__)) ?></code></small>
        </div>
        <div class="btn-row">
          <button type="submit" name="rename_admin" class="btn"
                  onclick="return confirm('<?= $adminLang === 'lt' ? 'Pervadinti admin failą? Senas pavadinimas nustos veikti.' : 'Rename admin file? The old name will stop working.' ?>')">
            <?= tr('rename_btn') ?>
          </button>
        </div>
      </form>
    </div>
  </div>

  <div class="step">
    <div class="step-head">
      <div class="step-num">5</div>
      <div>
        <h2><?= tr('step5_title') ?></h2>
        <p><?= tr('step5_sub') ?></p>
      </div>
    </div>
    <div class="step-body">
      <?php
        $lt = ($adminLang === 'lt');
        $emailSetOv  = adminEmailIsSet();
        $schemaHidn  = !file_exists(SCHEMA_PATH);
        $dbUp        = (adminDb() !== null);
        $isHttps     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                       || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                       || (($_SERVER['SERVER_PORT'] ?? '') == 443);
        $hasPrivacy  = file_exists(__DIR__ . '/../privacy.php') && file_exists(__DIR__ . '/../cookies.php');
        $pwChange    = function_exists('passwordChangeRequired') ? passwordChangeRequired() : false;
        // ok | warn | err  → status dot + label
        $ck = function (string $state, string $textLt, string $textEn) use ($lt): string {
            $dot = $state === 'ok' ? 'dot-ok' : ($state === 'warn' ? 'dot-warn' : 'dot-err');
            $txt = htmlspecialchars($lt ? $textLt : $textEn);
            return "<div style=\"margin-bottom:.45rem\"><span class=\"status-dot $dot\"></span> $txt</div>";
        };
        $checklist = [
          $ck(!$hashIsDefault ? 'ok' : 'err',
              'Admin slaptažodis nustatytas (bcrypt cost 12, atskirame settings faile)',
              'Admin password set (bcrypt cost 12, in a separate settings file)'),
          $ck($emailSetOv ? 'ok' : 'err',
              'Admin prisijungimo el. paštas nustatytas (saugomas kaip hash DB)',
              'Admin login email set (stored as a hash in the DB)'),
          $ck($emailSetOv ? 'ok' : 'warn',
              'Dviejų pakopų prisijungimas: 1) el. paštas → 2) el. paštas + slaptažodis',
              'Two-stage login: 1) email → 2) email + password'),
          $ck('ok',
              'Brute-force apsauga: 1 pak. 3 bandymai → 60 min (tylus); 2 pak. 2 → 24 val.',
              'Brute-force protection: stage 1 3 attempts → 60 min (silent); stage 2 2 → 24 h'),
          $ck($ipActive ? 'ok' : 'warn',
              $ipActive ? 'Admin IP apsauga (.htaccess) įjungta' : 'Admin IP apsauga (.htaccess) išjungta — saugo tik slaptažodis',
              $ipActive ? 'Admin IP protection (.htaccess) enabled'  : 'Admin IP protection (.htaccess) off — password only'),
          $ck('ok',
              'CSRF žetonai visose admin formose',
              'CSRF tokens in all admin forms'),
          $ck(function_exists('setSecurityHeaders') ? 'ok' : 'err',
              'Saugumo antraštės (CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy)',
              'Security headers (CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy)'),
          $ck('ok',
              'Sesijos slapukas: HttpOnly + SameSite (Secure su HTTPS)',
              'Session cookie: HttpOnly + SameSite (Secure on HTTPS)'),
          $ck($isHttps ? 'ok' : 'warn',
              $isHttps ? 'HTTPS aktyvus' : 'HTTPS nerastas — produkcijoje būtinas TLS',
              $isHttps ? 'HTTPS active'  : 'HTTPS not detected — TLS required in production'),
          $ck($dbUp ? 'ok' : 'err',
              $dbUp ? 'Duomenų bazė pasiekiama' : 'Duomenų bazė nepasiekiama',
              $dbUp ? 'Database reachable' : 'Database unreachable'),
          $ck($schemaHidn ? 'ok' : 'warn',
              $schemaHidn ? 'schema.sql paslėptas/ištrintas po diegimo' : 'schema.sql vis dar prieinamas — ištrinkite po diegimo',
              $schemaHidn ? 'schema.sql hidden/removed after install' : 'schema.sql still present — delete after install'),
          $ck('ok',
              'HMAC-SHA256 jutiklių autentifikacija (raktas priskiriamas admin sąsajoje)',
              'HMAC-SHA256 sensor authentication (key assigned in the admin UI)'),
          $ck($dbUp ? 'ok' : 'warn',
              'Saugumo žurnalas (audit_log): nesėkmės, blokai, veiksmai',
              'Security log (audit_log): failures, blocks, actions'),
          $ck($hasPrivacy ? 'ok' : 'warn',
              'BDAR: privatumo politika + slapukų puslapis (tik būtini slapukai)',
              'GDPR: privacy policy + cookies page (essential cookies only)'),
        ];
        if ($pwChange) {
          $checklist[] = $ck('warn',
              'Reikalingas slaptažodžio keitimas (po 24 val. 2 pakopos bloko)',
              'Password change required (after a 24 h stage-2 lockout)');
        }
      ?>
      <div style="font-size:.82rem;line-height:1.6;margin-top:.75rem;color:var(--text)">
        <?= implode("\n", $checklist) ?>
      </div>
      <p style="font-size:.72rem;color:var(--muted);margin-top:.75rem">
        <?= $lt ? '🟢 atlikta · 🟠 rekomenduojama · 🔴 būtina sutvarkyti'
                : '🟢 done · 🟠 recommended · 🔴 must fix' ?>
      </p>
    </div>
  </div>

  <!-- ─── 5.2 METRICS PANEL ────────────────────────────── -->
  <div class="step" style="margin-bottom:1rem">
    <div class="step-head">
      <div class="step-num">📊</div>
      <h2><?= $adminLang === 'lt' ? 'Metrikos' : 'Metrics' ?></h2>
    </div>
    <div id="statsPanel" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem;margin-top:.5rem">
      <div style="color:var(--muted);font-size:.85rem"><?= $adminLang === 'lt' ? 'Kraunama…' : 'Loading…' ?></div>
    </div>
  </div>

  <!-- ─── 6. SECURITY LOG ──────────────────────────────── -->
  <div class="step" style="margin-bottom:1rem">
    <div class="step-head">
      <div class="step-num">🛡</div>
      <div>
        <h2><?= $adminLang === 'lt' ? 'Saugumo žurnalas' : 'Security log' ?></h2>
        <p><?= $adminLang === 'lt'
            ? 'Nesėkmingi prisijungimai, blokai ir veiksmai. Galite blokuoti IP arba trinti įrašus.'
            : 'Failed logins, blocks and actions. You can block an IP or delete entries.' ?></p>
      </div>
    </div>
    <div class="step-body">
      <?php
      // Active blocks from the attempts file
      $activeBlocks = [];
      foreach (readAttempts() as $aip => $arec) {
          $secs = loginBlockSeconds((string)$aip);
          if ($secs > 0) $activeBlocks[(string)$aip] = ['secs' => $secs, 'reason' => $arec['reason'] ?? 'email_60min'];
      }
      // Recent audit log (latest 50)
      $logRows = [];
      $logPdo = adminDb();
      if ($logPdo) {
          try {
              $logRows = $logPdo->query("SELECT id, occurred_at, actor_ip, action, details FROM audit_log ORDER BY id DESC LIMIT 50")->fetchAll();
          } catch (\Throwable) { $logRows = []; }
      }
      $reasonLabel = function (string $r) use ($adminLang): string {
          $map = [
              'email_60min' => $adminLang === 'lt' ? '3× blogas el. paštas (60 min)' : '3× wrong email (60 min)',
              'creds_24h'   => $adminLang === 'lt' ? '2× blogi duomenys (24 val.)'   : '2× wrong credentials (24h)',
              'manual'      => $adminLang === 'lt' ? 'rankinis blokas' : 'manual block',
          ];
          return $map[$r] ?? $r;
      };
      ?>
      <?php if ($activeBlocks): ?>
        <h3 style="font-size:.9rem;margin:.75rem 0 .5rem"><?= $adminLang === 'lt' ? 'Šiuo metu užblokuoti IP' : 'Currently blocked IPs' ?></h3>
        <table class="log-table">
          <thead><tr>
            <th>IP</th>
            <th><?= $adminLang === 'lt' ? 'Priežastis' : 'Reason' ?></th>
            <th><?= $adminLang === 'lt' ? 'Liko' : 'Remaining' ?></th>
            <th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($activeBlocks as $aip => $b):
              $rmin = (int)ceil($b['secs'] / 60);
              $remTxt = $rmin >= 60 ? round($rmin / 60, 1) . ($adminLang === 'lt' ? ' val.' : 'h') : $rmin . ' min'; ?>
            <tr>
              <td><code><?= htmlspecialchars((string)$aip) ?></code></td>
              <td><?= htmlspecialchars($reasonLabel((string)$b['reason'])) ?></td>
              <td><?= htmlspecialchars($remTxt) ?></td>
              <td>
                <form method="POST" style="margin:0">
                  <?= csrfField() ?>
                  <input type="hidden" name="unblock_ip" value="<?= htmlspecialchars((string)$aip) ?>">
                  <button type="submit" class="btn btn-sm"><?= $adminLang === 'lt' ? 'Atblokuoti' : 'Unblock' ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <div style="display:flex;justify-content:space-between;align-items:center;margin:1rem 0 .5rem">
        <h3 style="font-size:.9rem;margin:0"><?= $adminLang === 'lt' ? 'Įvykiai' : 'Events' ?></h3>
        <?php if ($logRows): ?>
        <form method="POST" style="margin:0" onsubmit="return confirm('<?= $adminLang === 'lt' ? 'Išvalyti visą žurnalą?' : 'Clear the whole log?' ?>')">
          <?= csrfField() ?>
          <button type="submit" name="clear_log" value="1" class="btn btn-sm"><?= $adminLang === 'lt' ? 'Valyti viską' : 'Clear all' ?></button>
        </form>
        <?php endif; ?>
      </div>

      <?php if (!$logRows): ?>
        <p style="font-size:.8rem;color:var(--muted)"><?= $adminLang === 'lt' ? 'Žurnalas tuščias.' : 'The log is empty.' ?></p>
      <?php else: ?>
        <div style="overflow-x:auto">
        <table class="log-table">
          <thead><tr>
            <th><?= $adminLang === 'lt' ? 'Laikas (UTC)' : 'Time (UTC)' ?></th>
            <th>IP</th>
            <th><?= $adminLang === 'lt' ? 'Veiksmas' : 'Action' ?></th>
            <th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($logRows as $row):
              $canBlock = !empty($row['actor_ip']) && filter_var($row['actor_ip'], FILTER_VALIDATE_IP); ?>
            <tr>
              <td style="white-space:nowrap"><?= htmlspecialchars((string)$row['occurred_at']) ?></td>
              <td><code><?= htmlspecialchars((string)($row['actor_ip'] ?? '—')) ?></code></td>
              <td>
                <?= htmlspecialchars((string)$row['action']) ?>
                <?php if (!empty($row['details'])): ?><br><small style="color:var(--muted)"><?= htmlspecialchars((string)$row['details']) ?></small><?php endif; ?>
              </td>
              <td style="white-space:nowrap">
                <?php if ($canBlock): ?>
                <form method="POST" style="display:inline;margin:0">
                  <?= csrfField() ?>
                  <input type="hidden" name="block_ip" value="<?= htmlspecialchars((string)$row['actor_ip']) ?>">
                  <button type="submit" class="btn btn-sm" title="<?= $adminLang === 'lt' ? 'Blokuoti šį IP 24 val.' : 'Block this IP for 24h' ?>">⛔ IP</button>
                </form>
                <?php endif; ?>
                <form method="POST" style="display:inline;margin:0">
                  <?= csrfField() ?>
                  <input type="hidden" name="delete_log" value="<?= (int)$row['id'] ?>">
                  <button type="submit" class="btn btn-sm" title="<?= $adminLang === 'lt' ? 'Trinti įrašą' : 'Delete entry' ?>">✕</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ─── GREITOS NUORODOS ─────────────────────────────── -->
  <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.5rem">
    <a href="../index.php" class="btn"><?= tr('open_map') ?></a>
    <a href="../manage.php" class="btn"><?= tr('manage_sensors') ?></a>
    <a href="../api/sensors.php?action=map_data" class="btn" target="_blank" rel="noopener"><?= tr('test_api') ?></a>
    <a href="../tests.php" class="btn"><?= tr('test_map') ?></a>
    <a href="../privacy.php" class="btn"><?= $adminLang === 'lt' ? '📄 Privatumas' : '📄 Privacy' ?></a>
    <a href="../cookies.php" class="btn"><?= $adminLang === 'lt' ? '🍪 Slapukai' : '🍪 Cookies' ?></a>
  </div>

  <div class="security-note">
    ⚠ <?= tr('sec_note') ?>
  </div>

</div>
<?php endif; ?>

<!-- Attribution — required by the CC BY-NC 4.0 license, must not be removed -->
<!-- Attribution — required by CC BY-NC 4.0. The content is base64-encoded,
     so it does not appear in a direct text search. Legal force — in the LICENSE file. -->
<?php
// Encoded parts (the author is assembled from 2 fragments)
$__a = base64_decode('QWxla3NhbmRy') . base64_decode('IElndW1lbm92');
$__i = $adminLang === 'lt'
     ? base64_decode('VmlsbmlhdXMgdW5pdmVyc2l0ZXRvIE1ldG9kaW5pcyBTVEVBTSB1Z2R5bW8gY2VudHJhcw==')
     : base64_decode('Vmlsbml1cyBVbml2ZXJzaXR5IE1ldGhvZGljYWwgU1RFQU0gRWR1Y2F0aW9uIENlbnRyZQ==');
?>
<footer class="admin-attribution">
  <strong><?= htmlspecialchars($__a, ENT_QUOTES) ?></strong>
  <span class="sep">·</span>
  <?= htmlspecialchars($__i, ENT_QUOTES) ?>
  <span class="sep">·</span>
  <a href="../cookies.php" style="color:var(--muted);text-decoration:none"><?= $adminLang === 'lt' ? 'Slapukai' : 'Cookies' ?></a>
  <span class="sep">·</span>
  <a href="../privacy.php" style="color:var(--muted);text-decoration:none"><?= $adminLang === 'lt' ? 'Privatumas' : 'Privacy' ?></a>
</footer>

<script>
// Show/hide the map provider fields based on the selection.
// The Google key field is shown ONLY when Google is selected; Custom URL — only for Custom.
function updateMapFields() {
  var sel = document.getElementById('tileProviderSelect');
  if (!sel) return;
  var p = sel.value;
  var keyField = document.getElementById('gmapsKeyField');
  var urlField = document.getElementById('tileUrlField');
  if (keyField) keyField.style.display = (p === 'google') ? '' : 'none';
  if (urlField) urlField.style.display = (p === 'custom') ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', updateMapFields);

// Live password requirements checker
(function () {
  const input = document.getElementById('newPwInput');
  const hint = document.getElementById('pwReqHint');
  if (!input || !hint) return;
  const allowedSpecial = "!@#$%^&*()-_=+[]{};:,.?";
  const checks = {
    len:     pw => pw.length >= 8,
    upper:   pw => /[A-Z]/.test(pw),
    digit:   pw => /[0-9]/.test(pw),
    special: pw => [...pw].some(c => allowedSpecial.includes(c)),
  };
  input.addEventListener('input', function () {
    const pw = input.value;
    for (const key in checks) {
      const el = hint.querySelector('[data-req="' + key + '"]');
      if (!el) continue;
      const ok = checks[key](pw);
      el.style.color = pw === '' ? 'var(--muted)' : (ok ? 'var(--ok)' : 'var(--err)');
      el.textContent = el.textContent.replace(/^[○✓✗]/, ok ? '✓' : (pw === '' ? '○' : '✗'));
    }
  });
})();

// 5.2 Metrics panel loading
(async function loadStats() {
  const panel = document.getElementById('statsPanel');
  if (!panel) return;
  const lt = <?= $adminLang === 'lt' ? 'true' : 'false' ?>;
  const L = lt
    ? { total:'Jutiklių', offline:'Neprisijungę', today:'Įrašų šiandien', readings:'Viso įrašų', indoor:'Patalpos', outdoor:'Lauko', cap:'Talpos panaud.', pending:'Laukiantys' }
    : { total:'Sensors', offline:'Offline', today:'Readings today', readings:'Total readings', indoor:'Indoor', outdoor:'Outdoor', cap:'Capacity used', pending:'Pending' };
  try {
    const res = await fetch('../api/sensors.php?action=stats', { credentials: 'same-origin' });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const d = await res.json();
    if (d.error || d.code === 'db_unavailable') {
      panel.innerHTML = `<div style="color:var(--muted);font-size:.85rem">${d.error || (lt ? 'Duomenų bazė nepasiekiama' : 'Database unavailable')}</div>`;
      return;
    }
    const card = (label, value, sub) =>
      `<div style="background:var(--surf2);border:1px solid var(--bord);border-radius:10px;padding:.85rem">
        <div style="font-size:1.5rem;font-weight:700;color:var(--accent)">${value}</div>
        <div style="font-size:.72rem;color:var(--muted);margin-top:.2rem">${label}</div>
        ${sub ? `<div style="font-size:.68rem;color:var(--muted);margin-top:.15rem">${sub}</div>` : ''}
      </div>`;
    panel.innerHTML =
      card(L.total, d.sensors_total, `${d.sensors_pending} ${L.pending}`) +
      card(L.offline, d.sensors_offline, d.offline_percent + '%') +
      card(L.today, d.readings_today) +
      card(L.readings, d.readings_total.toLocaleString()) +
      card(L.indoor + ' / ' + L.outdoor, d.sensors_indoor + ' / ' + d.sensors_outdoor) +
      card(L.cap, d.capacity_used + '%', '/ 49 800');
  } catch (e) {
    panel.innerHTML = `<div style="color:var(--err);font-size:.85rem">${lt ? 'Nepavyko įkelti metrikų' : 'Failed to load metrics'}: ${e.message}</div>`;
  }
})();
</script>
</body>
</html>
