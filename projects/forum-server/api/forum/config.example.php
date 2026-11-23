<?php
/**
 * SNAPSMACK FORUM API — Configuration Template
 *
 * Copy this file to config.php and fill in real values.
 * config.php is gitignored and must NEVER be committed.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// Database (lives on snapsmack.ca — always localhost from the API's perspective)
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// Owner key — used by Smack Central and as a break-glass forum credential.
// Generate with: python3 -c "import secrets; print('mod_' + secrets.token_hex(32))"
// Store this somewhere safe. Do not share it with moderators: issue each
// person a revocable moderation-only key from Smack Central instead.
define('FORUM_MOD_KEY', 'mod_replace_this_with_the_owner_secret');
// ===== SNAPSMACK EOF =====
