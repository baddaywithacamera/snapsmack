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

    /** Site encryption salt (download_salt), resolved once per request. */
    function snap_secret_salt(): string {
        static $salt = null;
        if ($salt !== null) return $salt;
        global $pdo;
        $salt = '';
        try {
            if ($pdo instanceof PDO) {
                $salt = (string)($pdo->query(
                    "SELECT setting_val FROM snap_settings WHERE setting_key='download_salt' LIMIT 1"
                )->fetchColumn() ?: '');
            }
        } catch (\Throwable $e) {
            $salt = '';
        }
        return $salt;
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
