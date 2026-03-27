<?php
/**
 * SNAPSMACK - Like Toggle Handler
 * Alpha v0.7.6
 *
 * AJAX endpoint. Toggles a like on a post. Supports both authenticated
 * community users (tracked by user_id) and anonymous visitors (tracked
 * by a hashed IP — no cookie, no session, no PII stored).
 *
 * Returns JSON: { liked: bool, count: int } or { error: string }.
 *
 * POST params:
 *   post_id  (int, required)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/community-session.php';

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

// --- IDENTITY RESOLUTION ---
// Authenticated community user → track by user_id (original behaviour)
// Anonymous visitor → track by hashed IP (no PII stored, no cookie needed)
$user = community_current_user();
$user_id    = $user ? (int)$user['id'] : null;
$guest_hash = null;

if (!$user) {
    // Generate a deterministic hash from IP + salt. The salt prevents
    // rainbow-table reversal of the IP.
    $like_salt  = $settings['download_salt'] ?? 'snapsmack-default-salt-change-me';
    $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $guest_hash = hash('sha256', $ip . $like_salt);
}

// --- RATE LIMIT ---
if (function_exists('community_rate_limit') && !community_rate_limit('likes')) {
    http_response_code(429);
    echo json_encode(['error' => 'rate_limited']);
    exit;
}

// --- CHECK FOR EXISTING LIKE ---
if ($user_id) {
    $check = $pdo->prepare("SELECT id FROM snap_likes WHERE post_id = ? AND user_id = ? LIMIT 1");
    $check->execute([$post_id, $user_id]);
} else {
    // Check if guest_hash column exists (pre-migration sites fall back to auth-only)
    try {
        $check = $pdo->prepare("SELECT id FROM snap_likes WHERE post_id = ? AND guest_hash = ? LIMIT 1");
        $check->execute([$post_id, $guest_hash]);
    } catch (PDOException $e) {
        // guest_hash column doesn't exist yet — can't process anonymous likes
        http_response_code(401);
        echo json_encode(['error' => 'not_authenticated']);
        exit;
    }
}
$existing = $check->fetchColumn();

// --- TOGGLE ---
if ($existing) {
    // Unlike
    $pdo->prepare("DELETE FROM snap_likes WHERE id = ?")
        ->execute([$existing]);
    $liked = false;
} else {
    // Like
    if ($user_id) {
        $pdo->prepare("INSERT IGNORE INTO snap_likes (post_id, user_id) VALUES (?, ?)")
            ->execute([$post_id, $user_id]);
    } else {
        $pdo->prepare("INSERT IGNORE INTO snap_likes (post_id, guest_hash) VALUES (?, ?)")
            ->execute([$post_id, $guest_hash]);
    }
    $liked = true;
}

// --- COUNT ---
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_likes WHERE post_id = ?");
$count_stmt->execute([$post_id]);
$count = (int)$count_stmt->fetchColumn();

echo json_encode(['liked' => $liked, 'count' => $count]);
