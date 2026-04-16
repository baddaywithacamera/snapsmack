<?php
/**
 * SMACK CENTRAL - Configuration
 *
 * GITIGNORED — never committed. Fill in real values before FTP to server.
 * See sc-config.sample.php for documentation on each constant.
 */

// ── Database ──────────────────────────────────────────────────────────────────
define('SC_DB_HOST', 'localhost');
define('SC_DB_NAME', 'squir871_smackcent');
define('SC_DB_USER', 'squir871_smackcentadmin');
define('SC_DB_PASS', 'Summertime49!!#');

// ── Session ───────────────────────────────────────────────────────────────────
define('SC_SESSION_NAME', 'smack_central_session');
define('SC_BASE_URL',     'https://snapsmack.ca/smack-central/');

// ── Forum Database (isolated from Smack Central for security) ────────────────
define('SC_FORUM_DB_HOST', 'localhost');
define('SC_FORUM_DB_NAME', 'squir871_smackforum');
define('SC_FORUM_DB_USER', 'squir871_smackforumadmin');
define('SC_FORUM_DB_PASS', 'Summertime49!!#');

// ── Forum API ─────────────────────────────────────────────────────────────────
define('FORUM_API_URL',  'https://snapsmack.ca/api/forum');
define('FORUM_MOD_KEY',  'mod_b5d9b901b6f31ffb73fc076e45ec92825e1edf9f37ee4801d25d5a5fa576d02c');

// ── Release Signing ───────────────────────────────────────────────────────────
define('SMACK_RELEASE_PRIVKEY', 'fae8a0b4aab413b934d8dfdb927ee62b36f24251fb5374785d99051a7d6275b7938cb27f4230122dc22bc70decac66a09c20ad5f8db5748d0f443a57b18470d7');

// ── GitHub ────────────────────────────────────────────────────────────────────
define('SNAPSMACK_GITHUB_REPO',  'baddaywithacamera/snapsmack');
define('SNAPSMACK_GITHUB_TOKEN', '');

// ── Git & Release Paths ───────────────────────────────────────────────────────
define('SNAPSMACK_REPO_PATH', '/home/squir871/snapsmack.ca');
define('RELEASES_DIR',        '/home/squir871/snapsmack.ca/releases/');
define('RELEASES_URL',        'https://snapsmack.ca/releases/');
define('GIT_BIN',             'git');

// ── Asset Repository ─────────────────────────────────────────────────────────
define('SC_ASSETS_DIR', '/home/squir871/snapsmack.ca/sc-assets/');
define('SC_ASSETS_URL', 'https://snapsmack.ca/sc-assets/');
