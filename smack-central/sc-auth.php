<?php
/**
 * SMACK CENTRAL - Session guard
 *
 * Require this at the top of every SMACK CENTRAL page.
 * Loads config, starts the session, and redirects to login if not authenticated.
 */

require_once __DIR__ . '/sc-config.php';
require_once __DIR__ . '/sc-db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SC_SESSION_NAME);
    session_start();
}

if (empty($_SESSION['sc_admin_id'])) {
    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
    header('Location: sc-login.php' . ($redirect ? '?next=' . $redirect : ''));
    exit;
}
