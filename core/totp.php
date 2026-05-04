<?php
/**
 * SNAPSMACK - TOTP (Time-based One-Time Password) Library
 *
 * Pure PHP implementation of RFC 6238 TOTP.
 * No external dependencies. Works with Google Authenticator, Authy,
 * 1Password, Bitwarden, and any RFC 6238 compatible app.
 */

// ── Secret generation ──────────────────────────────────────────────────────

/**
 * Generate a cryptographically random Base32 TOTP secret.
 * 20 characters = 100 bits of entropy.
 */
function totp_generate_secret(): string {
    $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < 20; $i++) {
        $secret .= $chars[random_int(0, 31)];
    }
    return $secret;
}

// ── Base32 decode ──────────────────────────────────────────────────────────

/**
 * Decode a Base32 string to raw bytes.
 * Handles lowercase input and ignores padding characters.
 */
function totp_base32_decode(string $secret): string {
    $map = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));

    $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret));
    $bits   = '';
    foreach (str_split($secret) as $char) {
        if (!isset($map[$char])) continue;
        $bits .= str_pad(decbin($map[$char]), 5, '0', STR_PAD_LEFT);
    }

    $bytes = '';
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
        $bytes .= chr(bindec(substr($bits, $i, 8)));
    }
    return $bytes;
}

// ── Code generation ────────────────────────────────────────────────────────

/**
 * Generate the TOTP code for a given secret and time offset.
 *
 * @param string $secret  Base32-encoded secret
 * @param int    $offset  Time step offset (0 = current, -1 = previous, +1 = next)
 * @return string  6-digit zero-padded code
 */
function totp_code(string $secret, int $offset = 0): string {
    $step      = (int)floor(time() / 30) + $offset;
    $step_bytes = pack('J', $step);          // 8-byte big-endian unsigned
    $key       = totp_base32_decode($secret);
    $hash      = hash_hmac('sha1', $step_bytes, $key, true);

    // Dynamic truncation (RFC 4226 §5.4)
    $offset_byte = ord($hash[19]) & 0x0F;
    $code = (
        ((ord($hash[$offset_byte])     & 0x7F) << 24) |
        ((ord($hash[$offset_byte + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset_byte + 2]) & 0xFF) <<  8) |
        ( ord($hash[$offset_byte + 3]) & 0xFF)
    ) % 1000000;

    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

// ── Verification ───────────────────────────────────────────────────────────

/**
 * Verify a TOTP code against a secret with a ±1 step window.
 * The window allows for up to 30 seconds of clock drift.
 * Uses hash_equals() to prevent timing attacks.
 *
 * @param string $secret  Base32-encoded secret
 * @param string $code    6-digit code from user
 * @return bool
 */
function totp_verify(string $secret, string $code): bool {
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) return false;

    foreach ([-1, 0, 1] as $offset) {
        if (hash_equals(totp_code($secret, $offset), $code)) {
            return true;
        }
    }
    return false;
}

// ── Recovery codes ─────────────────────────────────────────────────────────

/**
 * Generate 8 one-time recovery codes.
 * Returns ['plain' => [...], 'hashed' => [...]]
 * Store hashed in DB. Show plain to user once.
 */
function totp_generate_recovery_codes(): array {
    $plain  = [];
    $hashed = [];
    for ($i = 0; $i < 8; $i++) {
        $code     = strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(4)));
        $plain[]  = $code;
        $hashed[] = password_hash($code, PASSWORD_DEFAULT);
    }
    return ['plain' => $plain, 'hashed' => $hashed];
}

/**
 * Check a submitted recovery code against stored hashed codes.
 * Returns the index of the matched code, or -1 if no match.
 *
 * Codes are generated as XXXXXXXX-XXXXXXXX (hex-dash-hex, uppercase).
 * Normalise the submitted value to that format: uppercase and strip
 * whitespace only — the dash must be preserved so password_verify()
 * compares against the exact string that was originally hashed.
 */
function totp_verify_recovery(string $submitted, array $hashed_codes): int {
    // Normalise: uppercase + strip whitespace. Keep the dash separator.
    $submitted = strtoupper(preg_replace('/\s+/', '', $submitted));
    foreach ($hashed_codes as $i => $hash) {
        if (password_verify($submitted, $hash)) {
            return $i;
        }
    }
    return -1;
}

// ── OTPAuth URI (for QR codes) ─────────────────────────────────────────────

/**
 * Build the otpauth:// URI used to provision an authenticator app.
 */
function totp_uri(string $secret, string $label, string $issuer = 'SnapSmack'): string {
    return 'otpauth://totp/'
        . rawurlencode($issuer . ':' . $label)
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

/**
 * Return a Google Charts QR code image URL for the given TOTP URI.
 * Used once on the setup page. No data is sent to Google except
 * the URL-encoded provisioning string.
 */
function totp_qr_url(string $totp_uri, int $size = 220): string {
    return 'https://chart.googleapis.com/chart'
        . '?chs=' . $size . 'x' . $size
        . '&chld=M|0'
        . '&cht=qr'
        . '&chl=' . rawurlencode($totp_uri);
}
// EOF
