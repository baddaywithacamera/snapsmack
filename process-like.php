<?php
/**
 * SNAPSMACK - Like Toggle Handler
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

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


header('Content-Type: application/json');

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/community-session.php';
require_once __DIR__ . '/core/secret-store.php'; // SECAUDIT 047
require_once __DIR__ . '/core/csrf.php';         // SS-1385

// --- METHOD ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

// --- TOKEN (SS-1385) ---
// This endpoint read NO token, so a form POST from any other site could toggle
// a like for whoever was logged in.
//
// THE FIRST REPAIR WAS FAILED ON REVIEW AND DESERVED TO BE. It added the check
// below and nothing else. csrf_meta_tag() is emitted on ADMIN pages only, so a
// public page carried no token, the client sent none, and the guard waved every
// request through -- while REFUSING a signed-in owner's genuine likes on his own
// site, because for him a session existed and the token still did not. It
// allowed the attack and broke the real case.
//
// What makes the check mean something now: core/public-runtime.php starts the
// shared session where the heart is enabled, snap_tile_like_html() puts the
// token on the button, and ss-engine-tile-like.js reads it from there. The
// session is RESUMED here through the same store, so the token compared is the
// one that page issued.
//
// WHAT THIS DOES AND DOES NOT PROTECT, because the honest scope matters. The
// session cookie is SameSite Lax (core/session-store.php), so it is NOT sent on
// a cross-site POST. A request from another site therefore arrives with no
// session and no identity: the like cannot be attributed to the visitor whose
// browser sent it, which is the harm this ticket describes. The token check is
// the second layer, for any browser or configuration where that cookie does
// arrive.
//
// WHAT IS STILL OPEN, stated rather than left to be discovered. A visitor
// whose browser sends no session cookie at all -- cookies off, or a strict
// privacy mode -- has no session and no token, and is ALLOWED, because
// refusing them is refusing a real reader. That means a cross-site POST can
// still toggle an ANONYMOUS like, the IP-keyed guest row, for such a visitor.
// It cannot touch a signed-in member's like, which is the harm SS-1385
// describes. Closing the remainder means refusing every cookie-less visitor,
// which is a decision about who may like a photograph and therefore not mine
// to take: it is with the PM.
require_once __DIR__ . '/core/tile-like-control.php';
snap_tile_like_session();
if (!csrf_ok_public()) {
    http_response_code(403);
    echo json_encode(['error' => 'csrf', 'message' => 'Reload the page and try again.']);
    exit;
}

// --- SETTINGS CHECK ---
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                ->fetchAll(PDO::FETCH_KEY_PAIR);

// DEFAULT-ON, so `!== '0'` and never `=== '1'`. Both settings default ON, and
// this endpoint was the only place in the chain reading them strictly, so a
// row holding an EMPTY STRING -- which an admin save can leave -- refused
// every like on a site that never asked for them to be off.
//
// That is not theoretical. core/community-component.php:61 draws the dock's
// like button with `!== '0'`, so on such a site the button has been on screen
// and returning 403 on click, with nothing to say why. SS-1323 surfaced it by
// putting a second control on the same endpoint and Muse spotted the two
// gates disagreeing. The repo-wide default-on-switch gate enforces this rule
// and it is right: the strict read was the bug, not the control.
if (($settings['community_enabled'] ?? '1') === '0' ||
    ($settings['community_likes_enabled'] ?? '1') === '0') {
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
// Signed-in community user by user_id; everyone else by a salted hash of
// their IP -- no PII stored, no cookie needed. These six lines used to live
// here AND in core/community-dock.php, where the read side asks the same
// question; SS-1323 needed a third caller and two copies of an identity rule
// are a bug waiting to be invisible. core/like-identity.php is the one rule.
require_once __DIR__ . '/core/like-identity.php';
[$user_id, $guest_hash] = snap_like_identity($pdo ?? null);

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
// ===== SNAPSMACK EOF =====
