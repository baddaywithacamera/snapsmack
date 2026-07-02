<?php
/**
 * SNAPSMACK — SMACKVERSE public router (ActivityPub, v0.2 FOLLOW + DELIVER)
 *
 * Public federation endpoints. Routes (?ap=):
 *   webfinger — resolves acct:<handle>@<domain> (rewrite /.well-known/webfinger here)
 *   actor     — the blog's actor document (application/activity+json)
 *   outbox    — OrderedCollection shell; &page=1 = 20 newest Notes
 *   followers — OrderedCollection (totalItems only; member list stays private)
 *   note      — dereferenceable Note JSON: &id=N (standalone image) or
 *               &post=N (grouped post → ONE multi-attachment Note, the
 *               Pixelfed carousel shape)
 *   inbox     — POST; HTTP-signature verified (draft-cavage + Digest + Date).
 *               Follow → follower stored + Accept queued. Undo/Delete →
 *               follower deactivated. Everything else acknowledged (202).
 *               Unverifiable requests get 401 and change NOTHING.
 *
 * Every route 404s unless snap_settings smackverse_enabled = 1.
 * Spec: _spec/smackverse-activitypub-spec-v0_1.md
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

// --- ENVIRONMENT BOOTSTRAP (mirrors core/gyss-api.php) ---
if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    define('BASE_URL', $protocol . '://' . $_SERVER['HTTP_HOST'] . '/');
}
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/constants.php';
require_once __DIR__ . '/core/smackverse.php';

function sv_respond(array $data, int $status = 200, string $ctype = 'application/activity+json'): void {
    http_response_code($status);
    header('Content-Type: ' . $ctype . '; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function sv_404(): void {
    sv_respond(['error' => 'not found'], 404, 'application/json');
}

try {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    sv_respond(['error' => 'unavailable'], 503, 'application/json');
}

// Hard gate: pretend none of this exists until the flag is on.
if (!sv_enabled($settings)) {
    sv_404();
}

$ap     = $_GET['ap'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($ap) {

    case 'webfinger':
        if ($method !== 'GET') sv_404();
        $jrd = sv_webfinger($_GET['resource'] ?? '', $settings);
        if ($jrd === null) sv_404();
        sv_respond($jrd, 200, 'application/jrd+json');
        break;

    case 'actor':
        if ($method !== 'GET') sv_404();
        sv_respond(sv_actor_doc($pdo, $settings));
        break;

    case 'outbox':
        if ($method !== 'GET') sv_404();
        sv_respond(sv_outbox_doc($pdo, $settings, isset($_GET['page'])));
        break;

    case 'followers':
        if ($method !== 'GET') sv_404();
        sv_respond(sv_followers_doc($pdo, $settings));
        break;

    case 'note':
        if ($method !== 'GET') sv_404();
        if (isset($_GET['post'])) {
            $post = sv_post_row($pdo, (int)$_GET['post']);
            $note = $post ? sv_note_for_post($pdo, $post, $settings) : null;
        } else {
            $img  = sv_image_row($pdo, (int)($_GET['id'] ?? 0));
            $note = $img ? sv_note_for_image($pdo, $img, $settings) : null;
        }
        if ($note === null) sv_404();
        sv_respond($note);
        break;

    case 'inbox':
        if ($method !== 'POST') sv_404();
        // Rate limit BEFORE any work — every inbox POST otherwise costs a
        // signature check including a remote key fetch. Login-pattern limiter.
        if (!sv_inbox_rate_ok($pdo, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')) {
            http_response_code(429); exit;
        }
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '' || strlen($raw) > 524288) {
            http_response_code(400); exit;
        }
        $activity = json_decode($raw, true);
        if (!is_array($activity)) {
            http_response_code(400); exit;
        }
        // Signature first — unverified requests change NOTHING.
        $actor_doc = sv_verify_signature($raw);
        if ($actor_doc === null) {
            http_response_code(401); exit;
        }
        try {
            sv_ensure_tables($pdo);
            $code = sv_handle_inbox($pdo, $settings, $activity, $actor_doc);
        } catch (Exception $e) {
            $code = 500;
        }
        http_response_code($code);
        exit;

    default:
        sv_404();
}
// ===== SNAPSMACK EOF =====
