<?php
// SECAUDIT 050-B — direct-access guard: backend include, never a URL entry point.
// Refuse a direct HTTP request regardless of web server (the Apache deny-list is
// Apache-only and drifts). CLI and normal includes pass through untouched.
if (PHP_SAPI !== 'cli' && !empty($_SERVER['SCRIPT_FILENAME'])
    && @realpath($_SERVER['SCRIPT_FILENAME']) === @realpath(__FILE__)) {
    http_response_code(404);
    exit;
}
/**
 * SNAPSMACK - Release Verification Public Key
 *
 * Ed25519 public key used to verify the signature on release packages
 * downloaded by the self-update system.
 *
 * This holds the PUBLIC release-verification key. As of 0.7.313 it is TRACKED
 * in git and SHIPPED in the release package — a public key is not a secret, and
 * the updater self-heals it from its canonical value (core/updater.php) so
 * placeholder installs converge on signed verification automatically.
 *
 * The matching private key lives in sc-config.php on your Smack Central
 * hub (never committed to git — keep it secret).
 *
 * A key of all zeros disables Ed25519 signature verification and falls
 * back to SHA-256 checksum-only verification.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


define('SNAPSMACK_RELEASE_PUBKEY', 'b0cbadef25a6aca5292e5c31b29dededb3f710f1d57908ba3c83a5e641f53bc2');
// ===== SNAPSMACK EOF =====
