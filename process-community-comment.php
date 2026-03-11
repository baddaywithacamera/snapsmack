<?php
/**
 * SNAPSMACK - Community Comment Handler
 * Alpha v0.7.1
 *
 * AJAX endpoint. Submits a new comment or deletes an existing comment.
 * Requires an authenticated community user session.
 *
 * POST (submit):
 *   post_id      (int,    required)
 *   comment_text (string, required)
 *
 * POST (delete):
 *   action       'delete'
 *   comment_id   (int, required) — only the comment author can delete their own
 *
 * Returns JSON on submit:
 *   { comment_id, username, display_name, avatar_url, comment_text, created_at }
 *
 * Returns JSON on delete:
 *   { deleted: true, comment_id: int }
 */

header('Content-Type: application/json');

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/community-session.php';

// --- AUTH ---
$user = community_current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'not_authenticated']);
    exit;
}

// --- METHOD ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

// --- SETTINGS CHECK ---
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                ->fetchAll(PDO::FETCH_KEY_PAIR);

if (($settings['community_enabled'] ?? '1') !== '1' ||
    ($settings['community_comments_enabled'] ?? '1') !== '1') {
    http_response_code(403);
    echo json_encode(['error' => 'comments_disabled']);
    exit;
}

$action_type = trim($_POST['action'] ?? 'submit');

// ============================================================================
// DELETE
// ============================================================================
if ($action_type === 'delete') {
    $comment_id = (int)($_POST['comment_id'] ?? 0);
    if ($comment_id < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_comment_id']);
        exit;
    }

    // Verify ownership — only the author can delete their own comment
    $owner = $pdo->prepare("SELECT user_id FROM snap_community_comments WHERE id = ? LIMIT 1");
    $owner->execute([$comment_id]);
    $owner_id = (int)$owner->fetchColumn();

    if (!$owner_id) {
        http_response_code(404);
        echo json_encode(['error' => 'comment_not_found']);
        exit;
    }

    if ($owner_id !== (int)$user['id']) {
        http_response_code(403);
        echo json_encode(['error' => 'not_your_comment']);
        exit;
    }

    // Soft-delete: set status = 'deleted' rather than removing the row
    // This preserves comment IDs and prevents thread gaps in the UI.
    $pdo->prepare("UPDATE snap_community_comments SET status = 'deleted' WHERE id = ?")
        ->execute([$comment_id]);

    echo json_encode(['deleted' => true, 'comment_id' => $comment_id]);
    exit;
}

// ============================================================================
// SUBMIT
// ============================================================================
$post_id      = (int)($_POST['post_id'] ?? 0);
$comment_text = trim($_POST['comment_text'] ?? '');

if ($post_id < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_post_id']);
    exit;
}

if (empty($comment_text)) {
    http_response_code(400);
    echo json_encode(['error' => 'empty_comment']);
    exit;
}

if (mb_strlen($comment_text) > 2000) {
    http_response_code(400);
    echo json_encode(['error' => 'comment_too_long', 'max' => 2000]);
    exit;
}

// --- EMAIL VERIFIED CHECK ---
if (!$user['email_verified']) {
    http_response_code(403);
    echo json_encode(['error' => 'email_not_verified']);
    exit;
}

// --- VERIFY POST EXISTS AND ALLOWS COMMENTS ---
$post = $pdo->prepare("SELECT id, allow_comments FROM snap_images WHERE id = ? LIMIT 1");
$post->execute([$post_id]);
$post_row = $post->fetch();

if (!$post_row) {
    http_response_code(404);
    echo json_encode(['error' => 'post_not_found']);
    exit;
}

if ((int)$post_row['allow_comments'] !== 1) {
    http_response_code(403);
    echo json_encode(['error' => 'comments_off_for_post']);
    exit;
}

// --- RATE LIMIT ---
if (!community_rate_limit('comments')) {
    http_response_code(429);
    echo json_encode(['error' => 'rate_limited', 'message' => 'Too many comments. Slow down.']);
    exit;
}

// --- INSERT ---
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$pdo->prepare("
    INSERT INTO snap_community_comments (post_id, user_id, comment_text, ip)
    VALUES (?, ?, ?, ?)
")->execute([$post_id, (int)$user['id'], $comment_text, $ip]);

$comment_id = (int)$pdo->lastInsertId();
$created_at = date('Y-m-d H:i:s');

echo json_encode([
    'comment_id'   => $comment_id,
    'username'     => $user['username'],
    'display_name' => $user['display_name'] ?: $user['username'],
    'avatar_url'   => $user['avatar_url'],
    'comment_text' => $comment_text,
    'created_at'   => $created_at,
    'date_label'   => date('Y-m-d', strtotime($created_at)),
]);
