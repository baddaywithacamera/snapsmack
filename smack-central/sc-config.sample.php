<?php
/**
 * SMACK CENTRAL - Configuration Sample
 *
 * This file is committed to the repo as the canonical template.
 * sc-config.php (gitignored) is the live working copy — kept in sync
 * with this file by the development process. FTP sc-config.php to the
 * server and fill in the real values for each placeholder.
 */

// ── Database ──────────────────────────────────────────────────────────────────
// Same database as forum-server (squir871_smackforum on snapsmack.ca).
define('SC_DB_HOST', 'localhost');
define('SC_DB_NAME', 'your_database_name');
define('SC_DB_USER', 'your_database_user');
define('SC_DB_PASS', 'your_database_password');

// ── Session ───────────────────────────────────────────────────────────────────
define('SC_SESSION_NAME', 'smack_central_session');
define('SC_BASE_URL',     'https://snapsmack.ca/smack-central/');

// ── Forum API ─────────────────────────────────────────────────────────────────
// Mod key from forum-server/api/forum/config.php.
define('FORUM_API_URL',  'https://snapsmack.ca/api/forum');
define('FORUM_MOD_KEY',  'mod_your_mod_key_here');

// ── Release Signing ───────────────────────────────────────────────────────────
// Ed25519 SECRET key hex for signing release packages.
// The corresponding PUBLIC key is in core/release-pubkey.php in every install.
//
// To generate a keypair (run once, offline, store secret key securely):
//   $kp  = sodium_crypto_sign_keypair();
//   $sec = sodium_bin2hex(sodium_crypto_sign_secretkey($kp));
//   $pub = sodium_bin2hex(sodium_crypto_sign_publickey($kp));
//   echo "Secret: $sec\nPublic: $pub\n";
//   // Paste $pub into core/release-pubkey.php on every install.
//   // Paste $sec below. Never commit it.
define('SMACK_RELEASE_PRIVKEY', 'your_ed25519_secret_key_hex_here_128_hex_chars');

// ── Git & Release Paths ───────────────────────────────────────────────────────
// Absolute path to a local clone of the SnapSmack repo on this server.
// First-time setup: git clone https://github.com/you/snapsmack.git /path/to/repo
define('SNAPSMACK_REPO_PATH', '/home/youruser/snapsmack-repo');

// Absolute path to the releases output directory (must be web-accessible).
define('RELEASES_DIR', '/home/youruser/public_html/releases/');

// Public URL of the releases directory (trailing slash).
define('RELEASES_URL', 'https://snapsmack.ca/releases/');

// Path to the git binary. 'git' works if git is in PATH; use full path if not.
define('GIT_BIN', 'git');

// ── Asset Repository ─────────────────────────────────────────────────────────
// SC_ASSETS_DIR: absolute path to the directory that hosts font and JS files.
//   Structure: {SC_ASSETS_DIR}/fonts/{FamilyName}/{file.ttf}
//              {SC_ASSETS_DIR}/js/{ss-engine-name.js}
//              {SC_ASSETS_DIR}/css/{ss-engine-name.css}
// Must be web-accessible. Create it alongside your releases/ directory.
define('SC_ASSETS_DIR', '/home/youruser/public_html/sc-assets/');

// Public URL of the sc-assets directory (trailing slash).
define('SC_ASSETS_URL', 'https://snapsmack.ca/sc-assets/');
