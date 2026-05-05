<?php
/**
 * SNAPSMACK - Release Verification Public Key
 *
 * Ed25519 public key used to verify the signature on release packages
 * downloaded by the self-update system.
 *
 * The matching private key lives in sc-config.php on your Smack Central hub
 * (never committed to git — keep it secret).
 *
 * TO CONFIGURE:
 * 1. Log in to your Smack Central hub
 * 2. Go to Release Packager — the derived public key is shown there
 * 3. Replace the 64-zero placeholder below with your actual public key hex
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


define('SNAPSMACK_RELEASE_PUBKEY', 'b9955f78c835f964b980a104d231be8253fce59db89cb12367c9ccbbafcea297');
// ===== SNAPSMACK EOF =====
