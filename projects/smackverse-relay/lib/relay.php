<?php
// SNAPSMACK_EOF_HEADER: this file MUST end with // ===== SNAPSMACK EOF =====
/**
 * SMACKVERSE Relay — subscription, moderation, fan-out, and the inbox handler.
 */

require_once __DIR__ . '/ap.php';

const RELAY_PUBLIC = 'https://www.w3.org/ns/activitystreams#Public';

function relay_domain_of(string $url): string {
    return strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
}

function relay_is_blocked(string $domain): bool {
    if ($domain === '') return true;
    $s = relay_db()->prepare("SELECT 1 FROM relay_blocklist WHERE domain = ? LIMIT 1");
    $s->execute([$domain]);
    return (bool)$s->fetchColumn();
}

/** Allowed to auto-activate: open mode, or the domain is on the allowlist. */
function relay_is_allowed(string $domain): bool {
    if (relay_setting('open_mode', 'allowlist') === 'open') return true;
    $s = relay_db()->prepare("SELECT 1 FROM relay_allowlist WHERE domain = ? LIMIT 1");
    $s->execute([$domain]);
    return (bool)$s->fetchColumn();
}

function relay_audience_is_public(array $activity, array $obj): bool {
    foreach ([$activity['to'] ?? [], $activity['cc'] ?? [], $obj['to'] ?? [], $obj['cc'] ?? []] as $set) {
        if (is_string($set)) { if ($set === RELAY_PUBLIC) return true; }
        elseif (is_array($set)) { foreach ($set as $v) { if ($v === RELAY_PUBLIC) return true; } }
    }
    return false;
}

/** Delivery inboxes of active subscribers, optionally excluding one actor. */
function relay_active_inboxes(string $except_actor = ''): array {
    $rows = relay_db()->query(
        "SELECT actor_url, inbox_url, shared_inbox_url FROM relay_subscribers WHERE state = 'active'"
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        if ($except_actor !== '' && $r['actor_url'] === $except_actor) continue;
        $ib = trim((string)($r['shared_inbox_url'] ?? '')) !== '' ? $r['shared_inbox_url'] : $r['inbox_url'];
        if ($ib) $out[$ib] = true;
    }
    return array_keys($out);
}

/** Inbound Follow(object=Public) → subscribe (allowlist gated). */
function relay_handle_follow(array $activity, array $actor_doc): int {
    $actor_id = (string)($actor_doc['id'] ?? '');
    $domain   = relay_domain_of($actor_id);
    if (relay_is_blocked($domain)) { relay_log('Follow', $actor_id, null, 'refused: blocked'); return 202; }

    $obj = is_array($activity['object'] ?? null) ? ($activity['object']['id'] ?? '') : ($activity['object'] ?? '');
    if ($obj !== RELAY_PUBLIC && $obj !== relay_actor_url()) {
        relay_log('Follow', $actor_id, (string)$obj, 'ignored: not a relay follow'); return 202;
    }

    $inbox = (string)($actor_doc['inbox'] ?? '');
    if ($inbox === '' || !relay_url_is_public($inbox)) {
        relay_log('Follow', $actor_id, null, 'refused: no public inbox'); return 202;
    }
    $shared = $actor_doc['endpoints']['sharedInbox'] ?? null;
    if ($shared !== null && !relay_url_is_public((string)$shared)) $shared = null;

    $state     = relay_is_allowed($domain) ? 'active' : 'pending';
    $follow_id = (string)($activity['id'] ?? '');
    relay_db()->prepare(
        "INSERT INTO relay_subscribers (actor_url, domain, inbox_url, shared_inbox_url, follow_id, state)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE inbox_url=VALUES(inbox_url), shared_inbox_url=VALUES(shared_inbox_url),
                                 follow_id=VALUES(follow_id),
                                 state = IF(state='blocked','blocked',VALUES(state))"
    )->execute([$actor_id, $domain, $inbox, $shared, $follow_id, $state]);

    if ($state === 'active') {
        relay_send_accept($actor_doc, $activity);
        relay_log('Follow', $actor_id, null, 'subscribed: active');
    } else {
        relay_log('Follow', $actor_id, null, 'subscribed: pending approval');
    }
    return 202;
}

function relay_send_accept(array $actor_doc, array $follow): void {
    $inbox = (string)($actor_doc['inbox'] ?? '');
    if ($inbox === '') return;
    $accept = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id'       => relay_actor_url() . '#accept-' . bin2hex(random_bytes(8)),
        'type'     => 'Accept',
        'actor'    => relay_actor_url(),
        'object'   => $follow,
    ];
    relay_queue($inbox, json_encode($accept, JSON_UNESCAPED_SLASHES));
}

function relay_handle_undo(array $activity, array $actor_doc): int {
    $actor_id = (string)($actor_doc['id'] ?? '');
    $obj   = $activity['object'] ?? [];
    $otype = is_array($obj) ? ($obj['type'] ?? '') : '';
    if ($otype === 'Follow') {
        relay_db()->prepare("DELETE FROM relay_subscribers WHERE actor_url = ?")->execute([$actor_id]);
        relay_log('Undo', $actor_id, null, 'unsubscribed');
    }
    return 202;
}

/** Public Create/Announce from an active subscriber → fan out to all others. */
function relay_handle_fanout(array $activity, array $actor_doc, string $type): int {
    $actor_id = (string)($actor_doc['id'] ?? '');
    $domain   = relay_domain_of($actor_id);
    if (relay_is_blocked($domain)) { relay_log($type, $actor_id, null, 'dropped: blocked'); return 202; }

    $s = relay_db()->prepare("SELECT 1 FROM relay_subscribers WHERE actor_url = ? AND state = 'active' LIMIT 1");
    $s->execute([$actor_id]);
    if (!$s->fetchColumn()) { relay_log($type, $actor_id, null, 'dropped: not an active subscriber'); return 202; }

    $obj = is_array($activity['object'] ?? null) ? $activity['object'] : [];
    if (!relay_audience_is_public($activity, is_array($obj) ? $obj : [])) {
        relay_log($type, $actor_id, null, 'dropped: not public — never fan private/DM'); return 202;
    }

    $obj_id = is_array($obj) ? (string)($obj['id'] ?? '') : (string)($activity['object'] ?? '');
    if ($obj_id === '') { relay_log($type, $actor_id, null, 'dropped: no object id'); return 202; }

    // Fan out as an Announce of the object id (no image storage — the receiving
    // install dereferences/render from the origin; photos load from origin).
    $announce = [
        '@context'  => 'https://www.w3.org/ns/activitystreams',
        'id'        => relay_actor_url() . '#relay-' . bin2hex(random_bytes(8)),
        'type'      => 'Announce',
        'actor'     => relay_actor_url(),
        'published' => gmdate('Y-m-d\TH:i:s\Z'),
        'to'        => [RELAY_PUBLIC],
        'cc'        => [relay_base() . 'followers'],
        'object'    => $obj_id,
    ];
    $json = json_encode($announce, JSON_UNESCAPED_SLASHES);
    $n = 0;
    foreach (relay_active_inboxes($actor_id) as $ib) { relay_queue($ib, $json); $n++; }
    relay_log($type, $actor_id, $obj_id, 'fanned to ' . $n);
    return 202;
}

/** The inbox endpoint: verify signature, dispatch, ACK, drain a little. */
function relay_inbox(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '' || strlen($raw) > 524288) { http_response_code(400); exit; }
    $activity = json_decode($raw, true);
    if (!is_array($activity)) { http_response_code(400); exit; }

    $type      = (string)($activity['type'] ?? '');
    $log_actor = is_array($activity['actor'] ?? null)
        ? (string)($activity['actor']['id'] ?? '') : (string)($activity['actor'] ?? '');

    $actor_doc = relay_verify_signature($raw);
    if ($actor_doc === null) {
        if ($type === 'Delete') { http_response_code(202); exit; } // dead-actor tombstones — drop quietly
        relay_log($type ?: '?', $log_actor, null, 'REJECTED: signature verify failed');
        http_response_code(401); exit;
    }
    if ($log_actor !== '' && ($actor_doc['id'] ?? '') !== $log_actor) { http_response_code(401); exit; }

    switch ($type) {
        case 'Follow':   $code = relay_handle_follow($activity, $actor_doc); break;
        case 'Undo':     $code = relay_handle_undo($activity, $actor_doc); break;
        case 'Create':
        case 'Announce': $code = relay_handle_fanout($activity, $actor_doc, $type); break;
        default:         $code = 202;
    }
    http_response_code($code);
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
    try { relay_drain(20); } catch (Throwable $e) { /* cron will catch up */ }
    exit;
}
// ===== SNAPSMACK EOF =====
