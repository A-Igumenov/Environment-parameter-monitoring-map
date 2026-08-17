<?php
/**
 * db-check.php — standalone DB connection diagnostic tool.
 *
 * Usage: upload to the server and open in a browser:
 *   http://localhost/iot/db-check.php
 *
 * Enter the same DB details as in admin setup and you will see
 * EXACTLY what is wrong. AFTER DIAGNOSING, DELETE THIS FILE (security).
 */

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow'); // diagnostic tool: keep out of search indexes

// ── SECURITY: access only while the DB is not yet configured (first run)
//    OR for a logged-in administrator. Once configured, the tool
//    closes automatically — so no open DB probe stays public.
$configured = false;
$cfgPath = __DIR__ . '/includes/config.php';
if (is_file($cfgPath)) {
    $src = @file_get_contents($cfgPath);
    // Considered configured if DB_NAME and DB_USER are filled
    if ($src && preg_match("/define\('DB_NAME',\s*'[^']+'\)/", $src)
            && preg_match("/define\('DB_USER',\s*'[^']+'\)/", $src)) {
        $configured = true;
    }
}
if ($configured) {
    // Configured — require an admin session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['iot_admin'])) {
        http_response_code(403);
        exit('<!DOCTYPE html><meta charset="utf-8"><body style="font-family:sans-serif;max-width:600px;margin:3rem auto;color:#334155">'
           . '<h2>🔒 Prieiga uždaryta</h2>'
           . '<p>DB jau sukonfigūruota, todėl šis diagnostikos įrankis pasiekiamas tik prisijungusiam administratoriui.</p>'
           . '<p><strong>Rekomendacija:</strong> ištrinkite <code>db-check.php</code> iš serverio — jis nebereikalingas.</p>'
           . '</body>');
    }
}

$host = $_POST['host'] ?? 'localhost';
$name = $_POST['name'] ?? 'iot';
$user = $_POST['user'] ?? 'iot';
$pass = $_POST['pass'] ?? '';
$run  = isset($_POST['run']);
?>
<!DOCTYPE html>
<html lang="lt">
<head>
<meta charset="UTF-8">
<title>DB ryšio diagnostika</title>
<style>
body { font-family: system-ui, sans-serif; max-width: 700px; margin: 2rem auto; padding: 0 1rem; background: #0a0f1e; color: #e2e8f0; }
h1 { font-size: 1.3rem; }
input { width: 100%; padding: .5rem; margin: .3rem 0 1rem; background: #1a2235; border: 1px solid #1e2d45; border-radius: 6px; color: #e2e8f0; font-size: .95rem; }
label { font-size: .8rem; color: #64748b; }
button { background: #00c8ff; color: #0a0f1e; border: none; border-radius: 6px; padding: .7rem 1.5rem; font-weight: 600; cursor: pointer; }
.result { margin-top: 1.5rem; padding: 1rem; border-radius: 8px; font-family: monospace; font-size: .85rem; white-space: pre-wrap; line-height: 1.6; }
.ok { background: rgba(34,211,160,.1); border: 1px solid #22d3a0; }
.err { background: rgba(239,68,68,.1); border: 1px solid #ef4444; }
.warn { color: #f59e0b; }
code { color: #00c8ff; }
</style>
</head>
<body>
<h1>🔍 DB ryšio diagnostika</h1>
<p style="color:#64748b;font-size:.85rem">Įveskite tuos pačius duomenis kaip admin sąrankoje. Po diagnostikos <strong>ištrinkite šį failą</strong>.</p>

<form method="POST">
  <label>DB serveris (host)</label>
  <input type="text" name="host" value="<?= htmlspecialchars($host) ?>">
  <label>DB pavadinimas</label>
  <input type="text" name="name" value="<?= htmlspecialchars($name) ?>">
  <label>DB vartotojas</label>
  <input type="text" name="user" value="<?= htmlspecialchars($user) ?>">
  <label>DB slaptažodis</label>
  <input type="text" name="pass" value="<?= htmlspecialchars($pass) ?>" placeholder="(matomas, kad pamatytumėte tikslų tekstą)">
  <button type="submit" name="run" value="1">Tikrinti ryšį</button>
</form>

<?php if ($run): ?>
<div class="result <?= '' ?>">
<?php
echo "── Įvesties analizė ──\n";
echo "Host:        '" . htmlspecialchars($host) . "' (" . strlen($host) . " simb.)\n";
echo "DB:          '" . htmlspecialchars($name) . "' (" . strlen($name) . " simb.)\n";
echo "Vartotojas:  '" . htmlspecialchars($user) . "' (" . strlen($user) . " simb.)\n";
echo "Slaptažodis: " . ($pass === '' ? 'TUŠČIAS' : strlen($pass) . " simb.") . "\n";

// Hidden-character check
$warnings = [];
foreach (['Host'=>$host, 'DB'=>$name, 'Vartotojas'=>$user, 'Slaptažodis'=>$pass] as $lbl => $val) {
    if ($val !== trim($val)) $warnings[] = "$lbl turi tarpų/nematomų simbolių pradžioje ar pabaigoje!";
    if (preg_match('/[\r\n\t]/', $val)) $warnings[] = "$lbl turi tab/naujos eilutės simbolį!";
}
if ($warnings) {
    echo "\n⚠ PROBLEMOS RASTOS:\n";
    foreach ($warnings as $w) echo "  • $w\n";
    echo "  -> Tai dazniausia Access denied priezastis. Iveskite reiksmes ranka.\n";
}

echo "\n── Bandymas 1: su nurodytu host ('{$host}') ──\n";
try {
    $pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $ver = $pdo->query("SELECT VERSION()")->fetchColumn();
    echo "✅ PAVYKO! MySQL/MariaDB {$ver}\n";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Lentelės DB: " . (count($tables) ? implode(', ', $tables) : '(nėra — paleiskite schemą)') . "\n";
} catch (PDOException $e) {
    echo "❌ " . htmlspecialchars($e->getMessage()) . "\n";

    // Attempt with 127.0.0.1 (TCP instead of socket)
    if ($host === 'localhost') {
        echo "\n── Bandymas 2: 127.0.0.1 (TCP vietoj socket) ──\n";
        try {
            $pdo = new PDO("mysql:host=127.0.0.1;dbname={$name};charset=utf8mb4", $user, $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            echo "✅ PAVYKO su 127.0.0.1! → Naudokite '127.0.0.1' vietoj 'localhost' DB serverio lauke.\n";
        } catch (PDOException $e2) {
            echo "❌ " . htmlspecialchars($e2->getMessage()) . "\n";
        }
    }

    // Attempt without a DB (whether the user can connect at all)
    echo "\n── Bandymas 3: be DB pavadinimo (ar vartotojas/slaptažodis teisingi) ──\n";
    try {
        $pdo = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo "✅ Vartotojas ir slaptažodis TEISINGI! Problema tik su DB '{$name}' (gal neegzistuoja arba nėra teisių).\n";
        echo "   → Sukurkite DB arba suteikite teises: GRANT ALL ON {$name}.* TO '{$user}'@'{$host}';\n";
    } catch (PDOException $e3) {
        echo "❌ Vartotojas arba slaptažodis NETEISINGAS: " . htmlspecialchars($e3->getMessage()) . "\n";
        echo "   → Patikrinkite vartotoją/slaptažodį phpMyAdmin → User accounts.\n";
    }
}
?>
</div>
<?php endif; ?>

<p style="margin-top:2rem;color:#ef4444;font-size:.85rem">⚠ Po diagnostikos IŠTRINKITE šį failą (db-check.php) iš serverio.</p>
</body>
</html>
