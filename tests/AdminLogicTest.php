<?php
/**
 * Unit tests: admin.php logic
 * Config generation (injection protection), password hash, schema archive.
 *
 * These tests reproduce the logic of admin.php functions in isolation
 * (admin.php cannot be included directly — it starts HTML/session).
 */

require_once __DIR__ . '/TestCase.php';

final class ConfigGenTest extends TestCase {
    public function run(Assert $t): void {

        // ── var_export injection apsauga ──
        $genConfig = function(string $pass, string $titleLt, string $titleEn): string {
            $vp = var_export($pass, true);
            $vl = var_export($titleLt, true);
            $ve = var_export($titleEn, true);
            return "<?php\ndefine('DB_PASS', {$vp});\ndefine('SITE_TITLE_LT', {$vl});\ndefine('SITE_TITLE_EN', {$ve});\n";
        };

        // Injection attempt in the password
        $evil = "x'); echo 'HACKED'; define('X','";
        $code = $genConfig($evil, 'Žemėlapis', 'Map');

        // The syntax must be valid. We do NOT use an exec("php -l") subprocess —
        // it is unreliable across environments (Windows XAMPP php.exe not on PATH,
        // disabled on shared hosting). token_get_all with TOKEN_PARSE
        // checks the syntax in the same runtime, without a subprocess.
        $tokensOk = true;
        try { token_get_all($code, TOKEN_PARSE); }
        catch (\ParseError $e) { $tokensOk = false; }
        $t->true($tokensOk, 'Generated config is valid PHP despite injection attempt (token_get_all)');

        // After loading, the value must be EXACTLY what the input was.
        // Shell-free check: the payload must be var_export-ed (a literal
        // in the string), not left as executable PHP code.
        $t->true(str_contains($code, var_export($evil, true)),
            'Injection payload įrašytas kaip literalas (var_export), ne vykdomas kodas');
        // Legitimate define()s are at the start of a line (define('...'); without indentation).
        // The injection define('X' is INSIDE a var_export string — with a
        // with a backslash before the quote after loading, so it is not executed.
        $legitDefines = preg_match_all('/^define\(/m', $code);
        $t->equals(3, $legitDefines, 'Tik 3 teisėti define() eilutės pradžioje (injekcija — literalas)');

        // ── Lithuanian letters + hyphens in the name ──
        $code2 = $genConfig('pass', 'IoT Jutiklių Žemėlapis — Vilnius', 'IoT Sensor Map');
        // Shell-free: we check that var_export encoded the UTF-8 text correctly.
        // Loading such a file, the constant would equal the original — guaranteed by
        // var_export. We check that the name is unchanged in the code.
        $t->true(str_contains($code2, 'IoT Jutiklių Žemėlapis — Vilnius'),
            'LT title with diacritics+dash preserved (var_export išsaugo UTF-8)');

        // ── SQL splitting: semicolons inside a COMMENT must not split ──
        // Reproduces the splitSqlStatements logic (apostrophe-aware splitting)
        $splitSql = function(string $sql): array {
            $statements = []; $current = ''; $len = strlen($sql);
            $inSingle = $inDouble = $inBacktick = false;
            for ($i = 0; $i < $len; $i++) {
                $ch = $sql[$i];
                if ($ch === "'" && !$inDouble && !$inBacktick) { $prev = $i>0?$sql[$i-1]:''; if ($prev!=='\\') $inSingle=!$inSingle; }
                elseif ($ch === '"' && !$inSingle && !$inBacktick) { $prev = $i>0?$sql[$i-1]:''; if ($prev!=='\\') $inDouble=!$inDouble; }
                elseif ($ch === '`' && !$inSingle && !$inDouble) { $inBacktick=!$inBacktick; }
                if ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                    $tr = trim($current); if ($tr!=='') $statements[]=$tr; $current='';
                } else { $current .= $ch; }
            }
            $tr = trim($current); if ($tr!=='') $statements[]=$tr;
            return $statements;
        };

        // SQL with a semicolon inside a COMMENT (a real bug from schema.sql)
        $tricky = "CREATE TABLE x (mac VARCHAR(17) COMMENT 'WiFi MAC; NULL kol laukia', n INT);\nCREATE TABLE y (id INT);";
        $parts = $splitSql($tricky);
        $t->equals(2, count($parts), 'SQL su ; COMMENT viduje skaidomas teisingai (2 komandos)');
        $t->true(str_contains($parts[0], 'COMMENT'), 'Pirma komanda turi pilną COMMENT su ;');
        $t->true(str_contains($parts[0], 'n INT'), 'Pirma komanda nesukapota ties ; COMMENT viduje');

        // A naive explode WOULD CUT it (for comparison)
        $naive = array_filter(array_map('trim', explode(';', $tricky)));
        $t->true(count($naive) > 2, 'Naivus explode sukapoja (todėl reikia splitSqlStatements)');

        // admin.php uses splitSqlStatements, not a naive explode
        $adminSrc = file_get_contents(dirname(__DIR__) . '/' . self::adminFileName());
        $t->true(str_contains($adminSrc, 'splitSqlStatements'),
            'admin.php naudoja splitSqlStatements (gerbia apostrofus)');

        // ── CRITICAL: writeConfig does NOT generate a setSecurityHeaders declaration ──
        // (Cannot redeclare after setup). The generated config.php has only
        // require security.php, not its own copy of the function. This bug appeared
        // ONLY after admin setup, because admin.php rewrites config.php.
        // We extract the writeConfig heredoc content from the admin.php source.
        $wcStart = strpos($adminSrc, 'function writeConfig');
        $t->true($wcStart !== false, 'writeConfig funkcija egzistuoja admin.php');
        if ($wcStart !== false) {
            // Heredoc block: from <<<PHP to PHP;
            $heredocStart = strpos($adminSrc, '<<<PHP', $wcStart);
            $heredocEnd   = strpos($adminSrc, "\nPHP;", $heredocStart);
            $generatedTemplate = substr($adminSrc, $heredocStart, $heredocEnd - $heredocStart);
            // The generated config must NOT contain the function DECLARATION
            $t->false((bool)preg_match('/function\s+setSecurityHeaders\s*\(/', $generatedTemplate),
                'writeConfig NEGENERUOJA setSecurityHeaders deklaracijos (be redeclare po sąrankos)');
            // But it MUST include security.php
            $t->true(str_contains($generatedTemplate, 'security.php'),
                'Sugeneruotas config įtraukia security.php (require_once)');
            // db() and jsonResponse must still be present (they are needed in the config)
            $t->true(str_contains($generatedTemplate, 'function db()'),
                'Sugeneruotas config turi db() funkciją');
            // ── Communication email (CONTACT_EMAIL) ──
            // The generated config must define CONTACT_EMAIL (public contact address).
            $t->true(str_contains($generatedTemplate, "define('CONTACT_EMAIL'"),
                'Sugeneruotas config apibrėžia CONTACT_EMAIL (viešas komunikacinis el. paštas)');
        }
        // writeConfig must accept the contactEmail parameter (last, optional).
        $t->true((bool)preg_match('/function\s+writeConfig\([^)]*\$contactEmail/s', $adminSrc),
            'writeConfig priima $contactEmail parametrą');
        // The save handler must reject a contact email equal to the admin login email.
        $t->true(str_contains($adminSrc, 'verifyAdminEmail($contactEmail)'),
            'Komunikacinis el. paštas validuojamas, kad nesutaptų su admin prisijungimo el. paštu');
    }
}

final class PasswordTest extends TestCase {
    public function run(Assert $t): void {

        // ── bcrypt hash + verify ──
        $hash = password_hash('TestSlaptas123', PASSWORD_BCRYPT, ['cost' => 12]);
        $t->matches('/^\$2y\$12\$/', $hash, 'bcrypt hash starts with $2y$12$');
        $t->equals(60, strlen($hash), 'bcrypt hash is 60 chars');
        $t->true(password_verify('TestSlaptas123', $hash), 'verify succeeds for correct password');
        $t->false(password_verify('wrong', $hash), 'verify fails for wrong password');

        // ── Self-rewrite logika: preg_replace_callback (ne $-backreference bug) ──
        $rewrite = function(string $src, string $newHash) {
            $pattern = "/define\(\s*'ADMIN_PASSWORD_HASH'\s*,\s*'[^']*'/s";
            return preg_replace_callback($pattern,
                fn() => "define('ADMIN_PASSWORD_HASH',\n    '" . $newHash . "'",
                $src, 1, $count) . "|count=$count";
        };
        $src = "<?php\ndefine('ADMIN_PASSWORD_HASH',\n    '\$2y\$12\$old');\n";
        [$result, $meta] = explode('|', $rewrite($src, $hash));
        $t->contains($hash, $result, 'New hash inserted intact (no $ corruption)');
        $t->contains('count=1', $meta, 'Exactly one replacement');
        // Critical: a hash with $ characters must not be corrupted
        $t->matches('/\$2y\$12\$/', $result, 'Inserted hash keeps $2y$12$ prefix');
    }
}

final class SchemaArchiveTest extends TestCase {
    public function run(Assert $t): void {

        // ── schema.sql DELETION-after-install logic ──
        // After installation schema.sql is no longer archived, but SIMPLY DELETED.
        $tmpDir = sys_get_temp_dir() . '/sch_' . uniqid();
        mkdir($tmpDir);
        $schemaPath = $tmpDir . '/schema.sql';
        file_put_contents($schemaPath, 'CREATE TABLE x;');

        $t->true(file_exists($schemaPath), 'schema.sql egzistuoja pries trynima');

        $deleted = unlink($schemaPath);
        $t->true($deleted, 'unlink succeeds');
        $t->false(file_exists($schemaPath), 'schema.sql istrintas (ne archyvuotas)');

        // No usedSh.* copies must be created
        $leftover = glob($tmpDir . '/usedSh.*');
        $t->equals(0, count($leftover), 'Jokiu usedSh.* kopiju nesukurta');

        rmdir($tmpDir);

        // ── admin.php uses deleteSchemaFile, not archiving ──
        $admin = file_get_contents(dirname(__DIR__) . '/' . self::adminFileName());
        $t->true(str_contains($admin, 'function deleteSchemaFile'),
            'admin.php turi deleteSchemaFile funkcija');
        $t->false(str_contains($admin, 'function archiveSchemaFile'),
            'admin.php nebeturi archiveSchemaFile');
        $t->false(str_contains($admin, "'usedSh.'"),
            'admin.php nebekuria usedSh.* kopiju');

        // ── Schema ↔ DB match before deletion ──
        // schema.sql is deleted ONLY after the live DB matches the schema; a
        // broader/newer schema first extends the DB, then is removed.
        $t->true(str_contains($admin, 'function parseSchemaStructure')
              && str_contains($admin, 'function liveDbStructure')
              && str_contains($admin, 'function schemaDbDiff'),
            'admin.php turi schemos↔DB palyginimo funkcijas');
        $t->true(str_contains($admin, "'matchesSchema'"),
            'installSchema grąžina matchesSchema vėliavą');
        $t->true(str_contains($admin, "(\$result['matchesSchema'] ?? false)")
              || str_contains($admin, "\$res['matchesSchema']"),
            'schema.sql trinama tik kai DB atitinka schemą');
    }
}

