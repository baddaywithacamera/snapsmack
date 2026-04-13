<?php
/**
 * SNAPSMACK FORUM API — Configuration Template
 *
 * Copy this file to config.php and fill in real values.
 * config.php is gitignored and must NEVER be committed.
 */

// Database (lives on snapsmack.ca — always localhost from the API's perspective)
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// Moderator key — used by Sean to delete/pin/lock anything via the API.
// Generate with: python3 -c "import secrets; print('mod_' + secrets.token_hex(32))"
// Store this somewhere safe. Do not share it.
define('FORUM_MOD_KEY', 'mod_replacethiswitharealsecretkey');
