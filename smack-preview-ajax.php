<?php
/**
 * SNAPSMACK — AJAX Preview Endpoint
 * Alpha v0.7
 *
 * Returns rendered HTML for the editor's live preview panel.
 * Accepts POST content, runs it through the parser, returns JSON.
 * Admin-only — requires active session.
 */

require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/parser.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

$content = $_POST['content'] ?? '';

$snapsmack = new SnapSmack($pdo);
$rendered  = $snapsmack->parseContent($content);

echo json_encode([
    'success' => true,
    'html'    => '<div class="description">' . $rendered . '</div>'
]);
