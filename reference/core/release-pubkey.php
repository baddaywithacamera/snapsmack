<?php
/**
 * SNAPSMACK - Release Verification Public Key
 *
 * Ed25519 public key used to verify the signature on release packages
 * downloaded by the self-update system.
 *
 * This file is gitignored — it lives only on each server and is never
 * committed or shipped in release packages. Use release-pubkey-sample.php
 * as the template if you need to set up a new install.
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
