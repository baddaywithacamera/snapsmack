<?php
/**
 * SNAPSMACK - SMACK THAT APP UP entry point
 *
 * Authenticates the owner, reads the installation's authoritative site_mode,
 * and opens the matching existing composer. There is deliberately no mode
 * selector and no client-supplied mode parameter.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once __DIR__ . '/core/auth-smack.php';
require_once __DIR__ . '/core/app-mode.php';

$settings = $pdo->query('SELECT setting_key, setting_val FROM snap_settings')
    ->fetchAll(PDO::FETCH_KEY_PAIR);

unset($_SESSION['snapsmack_login_return']);

header('Cache-Control: no-store, private');
header('Location: ' . BASE_URL . snapsmack_app_composer($settings), true, 303);
exit;

// ===== SNAPSMACK EOF =====
