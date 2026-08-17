<?php
/**
 * Two-stage admin authentication tests.
 *
 * Covers the email-as-username flow added on top of the password login:
 *   - Stage 1: email (username) only — 3 wrong → 60-min block, silent.
 *   - Stage 2: email + password     — 2 wrong → 24-h block + audit log + force pw change.
 *   - Email stored as a one-way hash in the admin_credentials DB table.
 *   - Admin security log: block/unblock an IP, delete entries.
 *
 * Source checks are static (no execution); the lockout maths is reproduced in
 * isolation (same approach as BruteForceTest) so the suite stays shell-free.
 */

require_once __DIR__ . '/TestCase.php';

final class TwoStageAuthTest extends TestCase {

    private string $admin;
    private string $schema;

    public function __construct() {
        $root = dirname(__DIR__);
        $this->admin  = (string)@file_get_contents($root . '/' . self::adminFileName());
        $this->schema = (string)@file_get_contents($root . '/schema.sql');
    }

    // Reproduces the stage-2 block logic (2 attempts → 24h) in isolation.
    private const STAGE2_MAX   = 2;
    private const STAGE2_HOURS = 24;

    private function stage2Block(array &$rec): bool {
        $rec['s2'] = ($rec['s2'] ?? 0) + 1;
        if ($rec['s2'] >= self::STAGE2_MAX) {
            $rec['blocked_until'] = time() + self::STAGE2_HOURS * 3600;
            $rec['reason'] = 'creds_24h';
            return true;
        }
        return false;
    }

    private function blockSeconds(array $rec): int {
        $until = (int)($rec['blocked_until'] ?? 0);
        return $until > time() ? $until - time() : 0;
    }

    public function run(Assert $t): void {
        // ── 1. admin_credentials table (schema + installer) ──
        // schema.sql may be hidden/auto-removed in production once installed,
        // so these are verified against schema.sql OR the admin.php installer.
        $schemaOrInstaller = $this->schema . "\n" . $this->admin;
        $t->contains('admin_credentials', $schemaOrInstaller, 'admin_credentials lentelė apibrėžta');
        $t->contains('email_hash', $schemaOrInstaller, 'admin_credentials saugo email_hash (hash, ne tekstą)');
        $t->contains('password_change_required', $schemaOrInstaller, 'admin_credentials turi password_change_required vėliavą');
        $t->contains('CREATE TABLE IF NOT EXISTS admin_credentials', $this->admin,
            'admin.php diegiklis sukuria admin_credentials lentelę');

        // ── 2. Email (username) credential helpers ──
        foreach (['adminEmailHash', 'adminEmailIsSet', 'setAdminEmail', 'verifyAdminEmail', 'normalizeEmail'] as $fn) {
            $t->contains("function $fn", $this->admin, "admin.php turi funkciją $fn()");
        }
        $t->contains('password_hash(normalizeEmail', $this->admin, 'El. paštas saugomas kaip hash (password_hash)');
        $t->contains('password_verify(normalizeEmail', $this->admin, 'El. paštas tikrinamas su password_verify');

        // ── 3. Two-stage login constants + flow ──
        $t->contains('STAGE2_MAX_ATTEMPTS = 2', $this->admin, 'STAGE2_MAX_ATTEMPTS = 2');
        $t->contains('STAGE2_BLOCK_HOURS  = 24', $this->admin, 'STAGE2_BLOCK_HOURS = 24');
        $t->contains('MAX_LOGIN_ATTEMPTS = 3', $this->admin, '1 pakopa: 3 bandymai (išlaikyta)');
        $t->contains('LOGIN_BLOCK_MINUTES = 60', $this->admin, '1 pakopa: 60 min blokas (išlaikytas)');
        $t->contains('function recordStage2Fail', $this->admin, 'recordStage2Fail() egzistuoja');
        $t->contains('function blockIpManually', $this->admin, 'blockIpManually() egzistuoja');
        $t->contains('$credentialsComplete', $this->admin, 'Sąlyga credentialsComplete (slaptažodis + el. paštas)');

        // ── 4. Login forms (stage 1 email, stage 2 email+password) ──
        $t->contains('name="stage1_email"', $this->admin, 'Login forma: 1 pakopa — tik el. paštas');
        $t->contains('name="stage2_email"', $this->admin, 'Login forma: 2 pakopa — el. paštas');
        $t->contains('name="stage2_password"', $this->admin, 'Login forma: 2 pakopa — slaptažodis');

        // ── 5. Silent stage-1 failure (no enumeration) ──
        // The stage-1 branch must record a failure but NOT set a $loginError.
        $t->contains('// Silent — no error message', $this->admin, '1 pakopos klaida tyli (jokio pranešimo)');
        $t->contains("logSecurityEvent('login_email_blocked'", $this->admin, '60-min blokas registruojamas į žurnalą');

        // ── 6. Stage-2 lockout → audit log + force password change ──
        $t->contains("logSecurityEvent('login_blocked_24h'", $this->admin, '24h blokas registruojamas į žurnalą');
        $t->contains('setPasswordChangeRequired(true)', $this->admin, '24h blokas reikalauja keisti slaptažodį');
        $t->contains('function passwordChangeRequired', $this->admin, 'passwordChangeRequired() egzistuoja');

        // ── 7. Security log admin panel: block/unblock IP + delete ──
        $t->contains("isset(\$_POST['block_ip'])", $this->admin, 'Veiksmas: blokuoti IP');
        $t->contains("isset(\$_POST['unblock_ip'])", $this->admin, 'Veiksmas: atblokuoti IP');
        $t->contains("isset(\$_POST['delete_log'])", $this->admin, 'Veiksmas: trinti žurnalo įrašą');
        $t->contains("isset(\$_POST['clear_log'])", $this->admin, 'Veiksmas: valyti visą žurnalą');
        $t->contains('logSecurityEvent(', $this->admin, 'logSecurityEvent() naudojamas');

        // ── 8. Reproduced stage-2 maths: 2 attempts → 24h block ──
        $rec = ['count' => 0, 'last' => 0];
        $blocked1 = $this->stage2Block($rec);
        $t->false($blocked1, '1-as klaidingas 2-os pakopos bandymas dar neblokuoja');
        $t->equals(0, $this->blockSeconds($rec), 'Po 1 bandymo — neblokuota');
        $blocked2 = $this->stage2Block($rec);
        $t->true($blocked2, '2-as klaidingas bandymas → blokas');
        $secs = $this->blockSeconds($rec);
        $t->true($secs > 86000 && $secs <= 86400, 'Blokas ~24 val. (86400 s)');
        $t->equals('creds_24h', $rec['reason'] ?? '', 'Bloko priežastis: creds_24h');

        // ── 9. Manual block overrides (blocked_until honoured) ──
        $manual = ['count' => 0, 'last' => 0, 'blocked_until' => time() + 3600, 'reason' => 'manual'];
        $t->true($this->blockSeconds($manual) > 0, 'Rankinis blokas (blocked_until) galioja');
    }
}
