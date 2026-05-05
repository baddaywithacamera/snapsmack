<?php
/**
 * SNAPSMACK - Release Verification Public Key (SAMPLE)
 *
 * Copy this file to core/release-pubkey.php on your server and replace
 * the placeholder below with your actual Ed25519 public key hex.
 *
 * core/release-pubkey.php is gitignored — it never ships in the repo or
 * in release packages. Each server carries its own copy, protected from
 * being overwritten by updates via protected_paths.json.
 *
 * TO CONFIGURE:
 * 1. Log in to your Smack Central hub
 * 2. Go to Release Packager — the derived public key is shown there
 * 3. Copy that key and replace the 64-zero placeholder below
 * 4. FTP this file to core/release-pubkey.php on every install
 *
 * A key of all zeros disables Ed25519 signature verification and falls
 * back to SHA-256 checksum-only verification. Safe for development
 * installs that don't use the self-update system.
 *
 * The matching private key lives in sc-config.php on your Smack Central
 * hub (never committed to git — keep it secret).
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


define('SNAPSMACK_RELEASE_PUBKEY', '0000000000000000000000000000000000000000000000000000000000000000');
// ===== SNAPSMACK EOF =====
