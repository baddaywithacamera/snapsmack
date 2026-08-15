<?php
/**
 * SNAPSMACK - Secret Store (at-rest encryption for snap_settings secrets)
 *
 * Canonical AES-256-CBC helper for secret values (API keys, tokens, service
 * passwords) stored in snap_settings. Reuses the key-derivation pattern proven
 * in core/ftp-engine.php (sha256 of the site download_salt).
 *
 * SENTINEL DESIGN — the whole point:
 *   Encrypted values carry the prefix "enc:v1:". secret_decrypt() returns any
 *   value WITHOUT that prefix verbatim, so a legacy plaintext value passes
 *   straight through. That makes wiring a read site to secret_decrypt() a
 *   NO-OP until the value is actually encrypted — no flag-day, safe rollback.
 *
 * STAGING (see _continuity + memory):
 *   Step 1 (this release): helper + read-site wiring only. Nothing is encrypted
 *           yet, so behaviour is identical. secret_encrypt()/migration exist but
 *           are NOT called from any write path.
 *   Step 2 (next): wire the write paths + admin display decrypt, then migrate.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

if (!function_exists('secret_decrypt')) {

    if (!defined('SNAPSMACK_SECRET_SENTINEL')) {
        define('SNAPSMACK_SECRET_SENTINEL', 'enc:v1:');
    }

    /** setting_key fragments that identify a secret (for the future write path + migration). */
    function snap_secret_key_fragments(): array {
        return ['_key', '_token', '_secret', 'password', '_pass', '_salt',
                'api_key', 'apikey', 'client_secret', 'bearer', 'private_key'];
    }

    /** True if a setting_key names a secret value. Public keys + the salt itself are excluded. */
    function snap_is_secret_setting(string $key): bool {
        $k = strtolower($key);
        if ($k === 'download_salt') return false;              // that IS the encryption key material
        if (strpos($k, 'public_key') !== false) return false;  // public keys are not secret
        foreach (snap_secret_key_fragments() as $frag) {
            if (strpos($k, $frag) !== false) return true;
        }
        return false;
    }

    /** The public placeholder salt that shipped in source before SECAUDIT 047. */
    if (!defined('SNAPSMACK_DEFAULT_SALT')) {
        define('SNAPSMACK_DEFAULT_SALT', 'snapsmack-default-salt-change-me');
    }

    /**
     * SECAUDIT 047 — resolve the site's real download_salt, generating and
     * persisting a per-install random value the first time (self-heal).
     *
     * Before 046 the salt was never generated: install.php didn't create it and
     * every consumer fell back to the hardcoded, GitHub-public string
     * 'snapsmack-default-salt-change-me'. That defeated FTP-password at-rest
     * encryption (key = sha256 of a public constant) AND let anyone forge the
     * HMAC download tokens that gate original-file downloads. This heals it:
     * if the stored salt is absent or the public default, mint 32 random bytes,
     * re-encrypt the one at-rest secret keyed off it (the FTP password) so it
     * survives rotation, then persist. Cached per request; best-effort on any
     * DB error (never fatal on a public endpoint).
     */
    function snap_ensure_download_salt(?PDO $pdo = null): string {
        static $ensured = null;
        if ($ensured !== null) return $ensured;
        if (!($pdo instanceof PDO)) { $pdo = $GLOBALS['pdo'] ?? null; }
        if (!($pdo instanceof PDO)) return SNAPSMACK_DEFAULT_SALT;

        try {
            $cur = (string)($pdo->query(
                "SELECT setting_val FROM snap_settings WHERE setting_key='download_salt' LIMIT 1"
            )->fetchColumn() ?: '');
        } catch (\Throwable $e) {
            return SNAPSMACK_DEFAULT_SALT; // schema not ready — don't crash the request
        }

        // Already a real, per-install salt → use it.
        if ($cur !== '' && $cur !== SNAPSMACK_DEFAULT_SALT && strlen($cur) >= 16) {
            return $ensured = $cur;
        }

        // SECAUDIT 047: serialize the one-time heal. Without this, two concurrent
        // first-boot requests could each mint a different salt and re-encrypt the
        // FTP password under their own, orphaning the loser's ciphertext. Take a
        // short advisory lock, then RE-READ under it — if a peer already healed,
        // use their value instead of minting a second salt.
        $gotLock = false;
        try {
            $gotLock = (bool)$pdo->query("SELECT GET_LOCK('snap_download_salt_heal', 5)")->fetchColumn();
            if ($gotLock) {
                $cur = (string)($pdo->query(
                    "SELECT setting_val FROM snap_settings WHERE setting_key='download_salt' LIMIT 1"
                )->fetchColumn() ?: '');
                if ($cur !== '' && $cur !== SNAPSMACK_DEFAULT_SALT && strlen($cur) >= 16) {
                    $pdo->query("SELECT RELEASE_LOCK('snap_download_salt_heal')");
                    return $ensured = $cur;
                }
            }
        } catch (\Throwable $e) { $gotLock = false; /* advisory lock unsupported — proceed best-effort */ }

        // Need to heal. Everything encrypted so far used the effective old salt.
        $old = ($cur !== '') ? $cur : SNAPSMACK_DEFAULT_SALT;
        try {
            $new = bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            return $ensured = $old; // no CSPRNG — keep behaviour consistent, don't half-rotate
        }

        // Migrate the one at-rest secret keyed off the salt: the FTP password.
        try {
            $ftp = (string)($pdo->query(
                "SELECT setting_val FROM snap_settings WHERE setting_key='ftp_pass' LIMIT 1"
            )->fetchColumn() ?: '');
            if ($ftp !== '' && class_exists('SnapSmackFTP')) {
                $plain = SnapSmackFTP::decryptPassword($ftp, $old);
                if ($plain !== '') {
                    $re = SnapSmackFTP::encryptPassword($plain, $new);
                    $pdo->prepare("UPDATE snap_settings SET setting_val = ? WHERE setting_key = 'ftp_pass'")
                        ->execute([$re]);
                }
            }
        } catch (\Throwable $e) { /* FTP creds can be re-entered; never block the heal */ }

        $persisted = true;
        try {
            $pdo->prepare(
                "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('download_salt', ?)
                 ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
            )->execute([$new]);
        } catch (\Throwable $e) {
            $persisted = false; // couldn't persist — stay on old so tokens/creds remain consistent
        }
        if ($gotLock) {
            try { $pdo->query("SELECT RELEASE_LOCK('snap_download_salt_heal')"); } catch (\Throwable $e) {}
        }
        return $ensured = ($persisted ? $new : $old);
    }

    /** Site encryption salt (download_salt), resolved + self-healed once per request. */
    function snap_secret_salt(): string {
        static $salt = null;
        if ($salt !== null) return $salt;
        return $salt = snap_ensure_download_salt($GLOBALS['pdo'] ?? null);
    }

    function snap_secret_is_encrypted(string $value): bool {
        $s = SNAPSMACK_SECRET_SENTINEL;
        return strncmp($value, $s, strlen($s)) === 0;
    }

    /**
     * Encrypt a plaintext secret for storage. Idempotent; empties pass through.
     * NOT wired into any write path in step 1 — provided for step 2 + migration.
     */
    function secret_encrypt(string $plaintext, ?string $salt = null): string {
        if ($plaintext === '') return '';
        if (snap_secret_is_encrypted($plaintext)) return $plaintext;
        $salt = $salt ?? snap_secret_salt();
        if ($salt === '') return $plaintext;                 // no salt: never lose the value
        $key = hash('sha256', $salt, true);
        $iv  = openssl_random_pseudo_bytes(16);
        $ct  = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($ct === false) return $plaintext;
        return SNAPSMACK_SECRET_SENTINEL . base64_encode($iv . $ct);
    }

    /**
     * Decrypt a stored secret. A value without the sentinel is legacy plaintext
     * and is returned UNCHANGED — this is what makes read-site wiring a no-op.
     */
    function secret_decrypt(?string $value, ?string $salt = null): string {
        $value = (string)$value;
        if (!snap_secret_is_encrypted($value)) return $value;   // legacy plaintext -> verbatim
        $salt = $salt ?? snap_secret_salt();
        if ($salt === '') return $value;
        $raw = base64_decode(substr($value, strlen(SNAPSMACK_SECRET_SENTINEL)), true);
        if ($raw === false || strlen($raw) < 17) return '';
        $key = hash('sha256', $salt, true);
        $iv  = substr($raw, 0, 16);
        $ct  = substr($raw, 16);
        $pt  = openssl_decrypt($ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return ($pt !== false) ? $pt : '';
    }

}

// ===== SNAPSMACK EOF =====
