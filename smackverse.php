<?php
/**
 * SNAPSMACK — SMACKVERSE public router (ActivityPub, v0.2 FOLLOW + DELIVER)
 *
 * Public federation endpoints. Canonical routes are PATH-STYLE (0.7.350+),
 * rewritten here by .htaccess as ?appath= — AP object ids must carry no
 * query string because Pixelfed HTML-encodes '&' when dereferencing:
 *   /ap/actor      — the blog's actor document (application/activity+json)
 *   /ap/outbox     — OrderedCollection shell (first+last); ?page=N = 20 Notes,
 *                    newest-first, chained next/prev so the whole catalogue is
 *                    crawlable by a remote puller
 *   /ap/followers  — OrderedCollection (totalItems only; list stays private)
 *   /ap/following  — OrderedCollection (totalItems only; accounts the blog
 *                    actor follows — outbound Follow, 0.7.356)
 *   /ap/inbox      — POST inbox (see below)
 *   /ap/note/i/N   — Note for a standalone image
 *   /ap/note/p/N   — Note for a grouped post (ONE multi-attachment Note,
 *                    the Pixelfed carousel shape)
 *   /ap/note/c/N   — Note for a federated local comment
 * Legacy query routes (?ap=actor, ?ap=note&post=N…) still resolve for
 * anything already federated. Routes (?ap=):
 *   webfinger — resolves acct:<handle>@<domain> (rewrite /.well-known/webfinger here)
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

// Path-style routes (/ap/actor, /ap/note/p/N…) arrive via the .htaccess
// rewrite as ?appath=. AP object ids must be query-string-free — Pixelfed
// HTML-encodes '&' when it fetches object URLs (?a=1&b=2 becomes &amp; and
// 404s) — so paths are canonical as of 0.7.350. Legacy ?ap= URLs still work
// below for anything already federated.
if (isset($_GET['appath'])) {
    $seg = explode('/', trim((string)$_GET['appath'], '/'));
    $_GET['ap'] = $seg[0] ?? '';
    if (($seg[0] ?? '') === 'note') {
        $key = ['p' => 'post', 'i' => 'id', 'c' => 'comment', 'r' => 'reply'][$seg[1] ?? ''] ?? null;
        if ($key === null || !isset($seg[2])) sv_404();
        $_GET[$key] = $seg[2];
    }
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

    case 'nodeinfo':
        // NodeInfo discovery (/.well-known/nodeinfo) — points crawlers/peers at
        // the 2.0 document. Standard two-step fediverse software discovery.
        if ($method !== 'GET') sv_404();
        sv_respond([
            'links' => [[
                'rel'  => 'http://nodeinfo.diaspora.software/ns/schema/2.0',
                'href' => sv_base($settings) . 'nodeinfo/2.0',
            ]],
        ], 200, 'application/json');
        break;

    case 'nodeinfo2':
        // The NodeInfo 2.0 document itself (/nodeinfo/2.0).
        if ($method !== 'GET') sv_404();
        sv_respond(sv_nodeinfo_doc($pdo, $settings), 200, 'application/json');
        break;

    case 'actor':
        if ($method !== 'GET') sv_404();
        // Content negotiation: a human browser hitting the actor id (e.g. by
        // clicking the handle link on Mastodon/Pixelfed) is bounced to the
        // Pixelfed-faithful profile page; a fediverse server still gets the
        // actor JSON. Same rule the note case uses below.
        $ac_acc = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (stripos($ac_acc, 'activity+json') === false
            && stripos($ac_acc, 'ld+json') === false
            && stripos($ac_acc, 'text/html') !== false) {
            header('Location: ' . sv_profile_url($settings), true, 302);
            exit;
        }
        sv_respond(sv_actor_doc($pdo, $settings));
        break;

    case 'outbox':
        if ($method !== 'GET') sv_404();
        $ob_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 0;
        sv_respond(sv_outbox_doc($pdo, $settings, $ob_page));
        break;

    case 'followers':
        if ($method !== 'GET') sv_404();
        sv_respond(sv_followers_doc($pdo, $settings));
        break;

    case 'following':
        if ($method !== 'GET') sv_404();
        sv_respond(sv_following_doc($settings, $pdo));
        break;

    case 'featured':
        if ($method !== 'GET') sv_404();
        sv_respond(sv_featured_doc($pdo, $settings));
        break;

    case 'featured-tags':
        if ($method !== 'GET') sv_404();
        sv_respond(sv_featured_tags_doc($pdo, $settings));
        break;

    case 'remote-follow':
        // Public profile Follow button: a visitor from another instance gives
        // their handle; we bounce them to THEIR server to confirm following us.
        if ($method !== 'GET') sv_404();
        $vh  = (string)($_GET['handle'] ?? '');
        $dst = sv_remote_follow_url($vh, $settings);
        if ($dst === null) {
            header('Location: ' . sv_profile_url($settings) . '?follow=badhandle');
            exit;
        }
        header('Location: ' . $dst, true, 302);
        exit;

    case 'remote-interact':
        // Like / Reply / Boost from a visitor's OWN instance: bounce them to
        // their server's authorize_interaction for one of OUR objects — the
        // standard Mastodon/Pixelfed remote-interaction flow.
        if ($method !== 'GET') sv_404();
        $ri_h   = ltrim(trim((string)($_GET['handle'] ?? '')), '@');
        $ri_uri = (string)($_GET['uri'] ?? '');
        // Only ever bounce interactions for OUR OWN objects (no open redirect).
        if (!preg_match('/^[^@\s\/]+@([^@\s\/]+\.[^@\s\/]+)$/', $ri_h, $rm)
            || strpos($ri_uri, sv_base($settings)) !== 0) {
            header('Location: ' . sv_profile_url($settings));
            exit;
        }
        header('Location: https://' . $rm[1] . '/authorize_interaction?uri=' . rawurlencode($ri_uri), true, 302);
        exit;

    case 'note':
        if ($method !== 'GET') sv_404();
        // Content negotiation: a browser gets the Pixelfed-faithful HTML post
        // view; a fediverse server gets the Note JSON below. Only post/image
        // notes have a human view (reply/comment stay JSON-only).
        $pp_acc  = $_SERVER['HTTP_ACCEPT'] ?? '';
        $pp_html = stripos($pp_acc, 'activity+json') === false
                && stripos($pp_acc, 'ld+json') === false
                && stripos($pp_acc, 'text/html') !== false;
        if ($pp_html && (isset($_GET['post']) || isset($_GET['id']))) {
            $GLOBALS['pp_post_kind'] = isset($_GET['post']) ? 'post' : 'image';
            $GLOBALS['pp_post_id']   = (int)($_GET['post'] ?? ($_GET['id'] ?? 0));
            require __DIR__ . '/core/public-post.php';
            exit;
        }
        if (isset($_GET['reply'])) {
            // Dereferenceable Note for a durable outbound reply to a remote post.
            $note = sv_outbound_reply_doc($pdo, (string)$_GET['reply'], $settings);
        } elseif (isset($_GET['comment'])) {
            // Dereferenceable Note for a federated local comment (approved only).
            $cst = $pdo->prepare("SELECT * FROM snap_comments WHERE id = ? AND is_approved = 1 AND ap_source <> 'fediverse' LIMIT 1");
            $cst->execute([(int)$_GET['comment']]);
            $crow = $cst->fetch(PDO::FETCH_ASSOC);
            $note = $crow ? sv_note_for_comment($pdo, $crow, $settings) : null;
        } elseif (isset($_GET['post'])) {
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
        // Diagnostic refs (best-effort, from the still-unverified body).
        $log_verb  = is_string($activity['type'] ?? null) ? (string)$activity['type'] : '?';
        $log_actor = is_array($activity['actor']  ?? null) ? (string)($activity['actor']['id']  ?? '') : (string)($activity['actor']  ?? '');
        $log_obj   = is_array($activity['object'] ?? null) ? (string)($activity['object']['id'] ?? '') : (string)($activity['object'] ?? '');
        // Signature first — unverified requests change NOTHING.
        $actor_doc = sv_verify_signature($raw);
        if ($actor_doc === null) {
            // A Delete we can't verify is almost always routine fediverse garbage
            // collection: a remote instance purging a (usually spam) account
            // broadcasts a Delete, but the actor — and its signing key — is
            // ALREADY gone, so verification can NEVER succeed (the key fetch
            // 404s). Mastodon itself just drops these. We do the same: still take
            // NO action (unverified = changes nothing), ACK with 202, and DON'T
            // write a scary REJECTED line — otherwise the activity log is buried
            // under thousands of them. Verification is untouched for everything
            // else (Follow/Like/reply still fail-closed and logged).
            if ($log_verb === 'Delete') {
                http_response_code(202); exit;
            }
            // Everything else: log the rejection so a Like/reply that fails
            // signature verification is visible instead of vanishing silently —
            // prime suspect when a federated like never lands. Best-effort; no-ops
            // if table absent.
            if (function_exists('sv_inbox_log')) sv_inbox_log($pdo, $log_verb, $log_actor, $log_obj, 'REJECTED: signature verify failed');
            http_response_code(401); exit;
        }
        try {
            sv_ensure_tables($pdo);
            $code = sv_handle_inbox($pdo, $settings, $activity, $actor_doc);
        } catch (Exception $e) {
            $code = 500;
        }
        http_response_code($code);
        // INSTANT response: acknowledge to the sender first, then deliver any
        // Accept this request just queued — so a follow completes in seconds,
        // not on the next 10-minute cron tick. fastcgi_finish_request flushes
        // the 202 to the caller, then we drain UNPACED and briefly.
        //
        // NEVER pace (sleep) here: this runs in a web/FPM worker, and a paced
        // drain holds that worker for minutes — inbound federation traffic then
        // starves the pool and the whole site 524s. The Accept goes out fast;
        // any backfill queued alongside it rides the CLI delivery cron, which
        // paces it in order with no HTTP timeout to trip.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try { sv_process_deliveries($pdo, $settings, 20); } catch (\Throwable $e) { /* cron will retry */ }
        exit;

    default:
        sv_404();
}
// ===== SNAPSMACK EOF =====
