<?php
/**
 * SNAPSMACK — Preview Endpoint
 *
 * Two modes:
 *   Default (AJAX)  — returns JSON with rendered HTML for inline use.
 *   ?mode=full      — returns a standalone HTML page for preview-in-new-tab.
 * Admin-only — requires active session.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// Preview is a non-mutating, session-gated render of admin-supplied content: it
// stores nothing and returns output only to the caller's own browser. The
// shortcode toolbar posts it via a target=_blank form with no CSRF token, so
// exempt it from the global csrf_check() in auth-smack.php (same pattern used by
// other read-only POST endpoints). Session auth is still enforced by auth-smack.
require_once __DIR__ . '/core/csrf.php';
csrf_exempt();

require_once __DIR__ . '/core/auth-smack.php';
require_once __DIR__ . '/core/parser.php';
require_once __DIR__ . '/core/skin-settings.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

$content = $_POST['content'] ?? '';

$snapsmack = new SnapSmack($pdo);
$rendered  = $snapsmack->parseContent($content);

// --- FULL PAGE MODE (new tab preview through the active skin) ---
if (isset($_GET['mode']) && $_GET['mode'] === 'full') {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!defined('BASE_URL')) define('BASE_URL', rtrim($settings['site_url'] ?? '/', '/') . '/');
    require_once __DIR__ . '/core/admin-content-preview.php';
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    try {
        echo snapsmack_admin_content_preview($pdo, $settings, $_POST);
    } catch (Throwable $error) {
        error_log('Content preview failed: ' . $error->getMessage());
        http_response_code(422);
        echo '<!doctype html><meta charset="utf-8"><title>Preview unavailable</title><p>The active skin could not render this preview. Your edits have not been saved or published.</p>';
    }
    exit;
}

// --- DEFAULT: JSON MODE (legacy AJAX) ---
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'html'    => '<div class="description">' . $rendered . '</div>'
]);
// ===== SNAPSMACK EOF =====
