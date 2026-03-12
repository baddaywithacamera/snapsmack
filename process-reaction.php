<?php
/**
 * SNAPSMACK - Reaction Toggle Handler
 * Alpha v0.7.3
 *
 * AJAX endpoint. Sets or clears a reaction on a post for the authenticated
 * community user. One reaction per user per post — setting a new reaction
 * replaces the old one.
 *
 * Returns JSON: { reaction: string|null, counts: { code: count, ... } }
 *
 * POST params:
 *   post_id       (int,    required)
 *   reaction_code (string, required) — pass the user's current code to toggle off
 */

header('Content-Type: application/json');

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/community-session.php';

// --- MASTER REACTION REGISTRY ---
// Universe of all valid codes. Must stay in sync with the registry in
// core/community-dock.php and core/community-component.php.
const VALID_REACTIONS = [
    'fire', 'chef-kiss', 'wow', 'moody', 'sharp', 'golden-hour',
    'cinematic', 'peaceful', 'haunting', 'story', 'colours',
    'light', 'texture', 'timing', 'composition', 'thumbs-down',
];

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
    ($settings['community_reactions_enabled'] ?? '0') !== '1') {
    http_response_code(403);
    echo json_encode(['error' => 'reactions_disabled']);
    exit;
}

// --- ACTIVE REACTION SET ---
// Blog owner configures which reactions are enabled (up to 6) via admin.
// community_active_reactions is a JSON array of slugs stored in snap_settings.
// thumbs-down is gated separately by community_allow_dislike.
$raw_active = $settings['community_active_reactions'] ?? '[]';
$active_reactions = json_decode($raw_active, true) ?: [];
if (($settings['community_allow_dislike'] ?? '0') === '1') {
    if (!in_array('thumbs-down', $active_reactions, true)) {
        $active_reactions[] = 'thumbs-down';
    }
}
// Always intersect with master registry to prevent junk codes from settings
$active_reactions = array_values(array_intersect($active_reactions, VALID_REACTIONS));

// --- INPUT ---
$post_id       = (int)($_POST['post_id'] ?? 0);
$reaction_code = trim($_POST['reaction_code'] ?? '');

if ($post_id < 1 ||
    !in_array($reaction_code, VALID_REACTIONS, true) ||
    !in_array($reaction_code, $active_reactions, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_input']);
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

$current = $pdo->prepare("SELECT reaction_code FROM snap_reactions WHERE post_id = ? AND user_id = ? LIMIT 1");
$current->execute([$post_id, $user_id]);
$existing_code = $current->fetchColumn();

if ($existing_code === $reaction_code) {
    // Same reaction — remove it (toggle off)
    $pdo->prepare("DELETE FROM snap_reactions WHERE post_id = ? AND user_id = ?")
        ->execute([$post_id, $user_id]);
    $new_reaction = null;
} else {
    // New or different reaction — upsert
    $pdo->prepare("
        INSERT INTO snap_reactions (post_id, user_id, reaction_code)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE reaction_code = VALUES(reaction_code), created_at = NOW()
    ")->execute([$post_id, $user_id, $reaction_code]);
    $new_reaction = $reaction_code;
}

// --- UPDATED COUNTS ---
$count_stmt = $pdo->prepare("SELECT reaction_code, COUNT(*) as cnt FROM snap_reactions WHERE post_id = ? GROUP BY reaction_code");
$count_stmt->execute([$post_id]);
$counts = [];
foreach ($count_stmt->fetchAll() as $row) {
    $counts[$row['reaction_code']] = (int)$row['cnt'];
}

echo json_encode(['reaction' => $new_reaction, 'counts' => $counts]);
