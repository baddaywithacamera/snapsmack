<?php
/**
 * SNAPSMACK - Like Toggle Handler
 * Alpha v0.8
 *
 * AJAX endpoint. Toggles a like on a post for the authenticated community user.
 * Returns JSON: { liked: bool, count: int } or { error: string }.
 *
 * POST params:
 *   post_id  (int, required)
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
    ($settings['community_likes_enabled'] ?? '1') !== '1') {
    http_response_code(403);
    echo json_encode(['error' => 'likes_disabled']);
    exit;
}

// --- INPUT ---
$post_id = (int)($_POST['post_id'] ?? 0);
if ($post_id < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_post_id']);
    exit;
}

// --- VERIFY POST EXISTS ---
$exists = $pdo->prepare("SELECT id FROM snap_images WHERE id = ? LIMIT 1");
$exists->execute([$post_id]);
if (!$exists->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['error' => 'post_not_found']);
    exit;
}

// --- RATE LIMIT ---
if (!community_rate_limit('likes')) {
    http_response_code(429);
    echo json_encode(['error' => 'rate_limited']);
    exit;
}

// --- TOGGLE ---
$user_id = (int)$user['id'];

$check = $pdo->prepare("SELECT id FROM snap_likes WHERE post_id = ? AND user_id = ? LIMIT 1");
$check->execute([$post_id, $user_id]);
$existing = $check->fetchColumn();

if ($existing) {
    // Unlike
    $pdo->prepare("DELETE FROM snap_likes WHERE post_id = ? AND user_id = ?")
        ->execute([$post_id, $user_id]);
    $liked = false;
} else {
    // Like
    $pdo->prepare("INSERT IGNORE INTO snap_likes (post_id, user_id) VALUES (?, ?)")
        ->execute([$post_id, $user_id]);
    $liked = true;
}

// --- COUNT ---
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_likes WHERE post_id = ?");
$count_stmt->execute([$post_id]);
$count = (int)$count_stmt->fetchColumn();

echo json_encode(['liked' => $liked, 'count' => $count]);
