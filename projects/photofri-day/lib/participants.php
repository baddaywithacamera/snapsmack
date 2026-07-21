<?php
// SNAPSMACK_EOF_HEADER: this file MUST end with // ===== SNAPSMACK EOF =====
/**
 * PHOTOFRI.DAY — @participate signup: the inbox handler + join / leave / follow-back.
 *
 * FOLLOW = JOIN. Following @participate opts you into the whole-fediverse Photo
 * Friday. We auto-Accept every follow (the tag is the consent; no allowlist) AND
 * follow you back. UNFOLLOW = LEAVE: we delete the participant and Undo our
 * follow-back, so leaving means leaving everywhere (Starter Kit + SnapSmack global
 * feed removal hang off this same row — v0.3 §4).
 *
 * Reuses the SMACKVERSE relay AP primitives verbatim (keys, signature verify,
 * SSRF-guarded signed delivery). This file is the photofri-specific policy layer.
 */

require_once __DIR__ . '/ap.php';

const PFD_PUBLIC = 'https://www.w3.org/ns/activitystreams#Public';

function pfd_domain_of(string $url): string {
    return strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
}

function pfd_is_blocked(string $domain): bool {
    if ($domain === '') return true;
    $s = pfd_db()->prepare("SELECT 1 FROM pfd_blocklist WHERE domain = ? LIMIT 1");
    $s->execute([$domain]);
    return (bool)$s->fetchColumn();
}

/** Build @user@instance from an actor doc (best-effort; falls back to domain). */
function pfd_handle_for(array $actor_doc): string {
    $id   = (string)($actor_doc['id'] ?? '');
    $user = (string)($actor_doc['preferredUsername'] ?? '');
    $dom  = pfd_domain_of($id);
    if ($user === '' || $dom === '') return '';
    return '@' . $user . '@' . $dom;
}

/** Inbound Follow(object=@participate) → JOIN: store, Accept, and follow back. */
function pfd_handle_follow(array $activity, array $actor_doc): int {
    $actor_id = (string)($actor_doc['id'] ?? '');
    $domain   = pfd_domain_of($actor_id);
    if (pfd_is_blocked($domain)) { pfd_log('Follow', $actor_id, null, 'refused: blocked'); return 202; }

    // The follow must target THIS actor (a personal-style Application), not Public.
    $obj = is_array($activity['object'] ?? null) ? ($activity['object']['id'] ?? '') : ($activity['object'] ?? '');
    if ($obj !== pfd_actor_url() && $obj !== PFD_PUBLIC) {
        pfd_log('Follow', $actor_id, (string)$obj, 'ignored: not a follow of @participate'); return 202;
    }

    $inbox = (string)($actor_doc['inbox'] ?? '');
    if ($inbox === '' || !pfd_url_is_public($inbox)) {
        pfd_log('Follow', $actor_id, null, 'refused: no public inbox'); return 202;
    }
    $shared = $actor_doc['endpoints']['sharedInbox'] ?? null;
    if ($shared !== null && !pfd_url_is_public((string)$shared)) $shared = null;

    $handle    = pfd_handle_for($actor_doc);
    $follow_id = (string)($activity['id'] ?? '');

    // JOIN — auto-active. A returning leaver simply rejoins; a blocked actor stays blocked.
    pfd_db()->prepare(
        "INSERT INTO pfd_participants (actor_url, domain, handle, inbox_url, shared_inbox_url, follow_id, state)
         VALUES (?, ?, ?, ?, ?, ?, 'active')
         ON DUPLICATE KEY UPDATE handle=VALUES(handle), inbox_url=VALUES(inbox_url),
                                 shared_inbox_url=VALUES(shared_inbox_url), follow_id=VALUES(follow_id),
                                 state = IF(state='blocked','blocked','active')"
    )->execute([$actor_id, $domain, $handle ?: null, $inbox, $shared, $follow_id]);

    pfd_send_accept($actor_doc, $activity);   // Accept their Follow
    pfd_send_followback($actor_doc);          // and follow them back
    pfd_log('Follow', $actor_id, null, 'joined' . ($handle ? ': ' . $handle : ''));
    return 202;
}

/** Accept the participant's Follow (so their client shows the follow as confirmed). */
function pfd_send_accept(array $actor_doc, array $follow): void {
    $inbox = (string)($actor_doc['inbox'] ?? '');
    if ($inbox === '') return;
    $accept = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id'       => pfd_actor_url() . '#accept-' . bin2hex(random_bytes(8)),
        'type'     => 'Accept',
        'actor'    => pfd_actor_url(),
        'object'   => $follow,
    ];
    pfd_queue($inbox, json_encode($accept, JSON_UNESCAPED_SLASHES));
}

/** Follow the participant back (@participate follows you). Stores our Follow id so
 *  a later leave can Undo it precisely. */
function pfd_send_followback(array $actor_doc): void {
    $actor_id = (string)($actor_doc['id'] ?? '');
    $inbox    = (string)($actor_doc['inbox'] ?? '');
    if ($actor_id === '' || $inbox === '') return;
    $fid = pfd_actor_url() . '#follow-' . bin2hex(random_bytes(8));
    pfd_db()->prepare("UPDATE pfd_participants SET followback_id = ? WHERE actor_url = ?")
        ->execute([$fid, $actor_id]);
    $follow = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id'       => $fid,
        'type'     => 'Follow',
        'actor'    => pfd_actor_url(),
        'object'   => $actor_id,
    ];
    pfd_queue($inbox, json_encode($follow, JSON_UNESCAPED_SLASHES));
}

/** Their server Accepted our follow-back → mark it (best-effort bookkeeping). */
function pfd_handle_accept(array $activity, array $actor_doc): int {
    $actor_id = (string)($actor_doc['id'] ?? '');
    pfd_db()->prepare("UPDATE pfd_participants SET followback_ok = 1 WHERE actor_url = ?")
        ->execute([$actor_id]);
    pfd_log('Accept', $actor_id, null, 'follow-back accepted');
    return 202;
}

/** Undo(Follow) → LEAVE: remove the participant and Undo our follow-back too. */
function pfd_handle_undo(array $activity, array $actor_doc): int {
    $actor_id = (string)($actor_doc['id'] ?? '');
    $obj   = $activity['object'] ?? [];
    $otype = is_array($obj) ? ($obj['type'] ?? '') : '';
    if ($otype !== 'Follow') { return 202; }

    // Look up what we owe them (our follow-back id + inbox) before deleting.
    $s = pfd_db()->prepare("SELECT inbox_url, followback_id FROM pfd_participants WHERE actor_url = ? LIMIT 1");
    $s->execute([$actor_id]);
    $row = $s->fetch();

    // LEAVE everywhere: the participant row IS the Starter Kit + global-feed membership.
    pfd_db()->prepare("DELETE FROM pfd_participants WHERE actor_url = ?")->execute([$actor_id]);

    // Stop following them back (mirror the leave) if we had.
    if ($row && !empty($row['followback_id']) && !empty($row['inbox_url'])) {
        pfd_send_unfollow((string)$row['inbox_url'], $actor_id, (string)$row['followback_id']);
    }
    pfd_log('Undo', $actor_id, null, 'left');
    return 202;
}

/** Undo our own follow-back (used on leave). */
function pfd_send_unfollow(string $inbox, string $actor_id, string $followback_id): void {
    $undo = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id'       => pfd_actor_url() . '#undo-' . bin2hex(random_bytes(8)),
        'type'     => 'Undo',
        'actor'    => pfd_actor_url(),
        'object'   => [
            'id'     => $followback_id,
            'type'   => 'Follow',
            'actor'  => pfd_actor_url(),
            'object' => $actor_id,
        ],
    ];
    pfd_queue($inbox, json_encode($undo, JSON_UNESCAPED_SLASHES));
}

/** The @participate inbox: verify signature, dispatch, ACK, drain a little. */
function pfd_inbox(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '' || strlen($raw) > 524288) { http_response_code(400); exit; }
    $activity = json_decode($raw, true);
    if (!is_array($activity)) { http_response_code(400); exit; }

    $type      = (string)($activity['type'] ?? '');
    $log_actor = is_array($activity['actor'] ?? null)
        ? (string)($activity['actor']['id'] ?? '') : (string)($activity['actor'] ?? '');

    $actor_doc = pfd_verify_signature($raw);
    if ($actor_doc === null) {
        if ($type === 'Delete') { http_response_code(202); exit; } // dead-actor tombstones — drop quietly
        pfd_log($type ?: '?', $log_actor, null, 'REJECTED: signature verify failed');
        http_response_code(401); exit;
    }
    if ($log_actor !== '' && ($actor_doc['id'] ?? '') !== $log_actor) { http_response_code(401); exit; }

    switch ($type) {
        case 'Follow': $code = pfd_handle_follow($activity, $actor_doc); break;
        case 'Undo':   $code = pfd_handle_undo($activity, $actor_doc); break;
        case 'Accept': $code = pfd_handle_accept($activity, $actor_doc); break;
        // TODO (next step): Create/Announce carrying #photofri → board ingest
        // (teaser-only, hotlink origin preview, canonical→origin, rolling 5/author/week).
        case 'Create':
        case 'Announce': pfd_log($type, (string)($actor_doc['id'] ?? ''), null, 'noted: board ingest TODO'); $code = 202; break;
        default:       $code = 202;
    }
    http_response_code($code);
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
    try { pfd_drain(20); } catch (Throwable $e) { /* cron will catch up */ }
    exit;
}
// ===== SNAPSMACK EOF =====
