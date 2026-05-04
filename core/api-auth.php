<?php
/**
 * SNAPSMACK - API / Session Dual Authentication
 *
 * Drop-in replacement for require_once 'core/auth.php' on endpoints that
 * must serve both browser sessions (admin UI) and tool API key access (SYBU).
 *
 * Priority order:
 *   1. X-Snap-Key header present → validate against tool_api_key setting.
 *      Valid: define SNAP_API_AUTH and return (caller proceeds immediately).
 *      Invalid: 401 JSON error, exit.
 *   2. No key header → fall through to normal session auth (core/auth.php),
 *      which redirects browsers to the login page if not authenticated.
 *
 * The tool_api_key setting is managed in Admin → Settings → API Access.
 * An empty stored key means API key auth is disabled — key header is ignored
 * and falls through to session auth.
 */

require_once __DIR__ . '/db.php';

$_x_snap_key = trim($_SERVER['HTTP_X_SNAP_KEY'] ?? '');

if ($_x_snap_key !== '') {
    // Key was provided — look up the stored key
    $_stored_key = '';
    try {
        $_stmt = $pdo->prepare(
            "SELECT setting_val FROM snap_settings WHERE setting_key = 'tool_api_key' LIMIT 1"
        );
        $_stmt->execute();
        $_stored_key = (string)($_stmt->fetchColumn() ?: '');
        unset($_stmt);
    } catch (PDOException $e) {
        // DB error — fail closed
    }

    if ($_stored_key === '' || !hash_equals($_stored_key, $_x_snap_key)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid or missing API key.']);
        exit;
    }

    // Valid key — mark as API-authenticated and return immediately.
    // The caller proceeds without a session.
    define('SNAP_API_AUTH', true);
    unset($_x_snap_key, $_stored_key);
    return;
}

unset($_x_snap_key);

// No key header — fall through to standard session auth.
require_once __DIR__ . '/auth.php';
// EOF
