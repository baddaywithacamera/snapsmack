<?php
/**
 * SNAPSMACK — SMACKCAST relay policy (0.7.545D)
 *
 * Thin policy layer over the shared FEDIVERSE signature, fetch and delivery
 * primitives. It is inert unless this is the FEDISTRUCTURE 4.0 SMACKCAST hub.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

function sc_relay_is_hub(array $settings): bool {
    return ($settings['site_mode'] ?? '') === 'fedistructure'
        && ($settings['distribution'] ?? '') === 'fedistructure'
        && ($settings['node_role'] ?? '') === 'hub'
        && ($settings['distribution_profile'] ?? '') === 'smackcast'
        && ($settings['smackcast_relay_enabled'] ?? '0') === '1';
}

function sc_relay_is_public(array $activity, array $object): bool {
    $public = 'https://www.w3.org/ns/activitystreams#Public';
    foreach ([$activity['to'] ?? [], $activity['cc'] ?? [], $object['to'] ?? [], $object['cc'] ?? []] as $aud) {
        foreach (is_array($aud) ? $aud : [$aud] as $value) {
            if ($value === $public || $value === 'as:Public' || $value === 'Public') return true;
        }
    }
    return false;
}

/** Public-timeline eligible. Public in cc is the de-facto unlisted shape. */
function sc_relay_is_discoverable(array $activity, array $object): bool {
    $public = 'https://www.w3.org/ns/activitystreams#Public';
    foreach ([$activity['to'] ?? [], $object['to'] ?? []] as $aud) {
        foreach (is_array($aud) ? $aud : [$aud] as $value) {
            if ($value === $public || $value === 'as:Public' || $value === 'Public') return true;
        }
    }
    return false;
}

function sc_relay_actor_blocked(PDO $pdo, string $actor_url): bool {
    $domain = strtolower(rtrim((string)(parse_url($actor_url, PHP_URL_HOST) ?: ''), '.'));
    try {
        $q = $pdo->prepare("SELECT 1 FROM snap_ap_blocks
            WHERE (kind='actor' AND value=?) OR (kind='domain' AND value=?) LIMIT 1");
        $q->execute([$actor_url, $domain]);
        if ($q->fetchColumn()) return true;
    } catch (Throwable $e) { /* schema may be syncing */ }
    if (function_exists('pc_is_blocked')) {
        try { if (pc_is_blocked($pdo, $actor_url)) return true; } catch (Throwable $e) {}
    }
    return false;
}

function sc_relay_actor_owns_object(string $actor_url, string $object_id): bool {
    $actor_host = strtolower(rtrim((string)(parse_url($actor_url, PHP_URL_HOST) ?: ''), '.'));
    $object_host = strtolower(rtrim((string)(parse_url($object_id, PHP_URL_HOST) ?: ''), '.'));
    return $actor_host !== '' && $object_host !== '' && hash_equals($actor_host, $object_host);
}

function sc_relay_follow_is_join(array $activity): bool {
    $object = is_array($activity['object'] ?? null)
        ? (string)($activity['object']['id'] ?? '') : (string)($activity['object'] ?? '');
    return ($activity['type'] ?? '') === 'Follow'
        && $object === 'https://www.w3.org/ns/activitystreams#Public';
}

/** Upsert a JOIN and return its state. Admission defaults closed. */
function sc_relay_join(PDO $pdo, array $settings, array $activity, array $actor): string {
    $actor_url = (string)($actor['id'] ?? '');
    $domain = strtolower((string)(parse_url($actor_url, PHP_URL_HOST) ?: ''));
    $inbox = (string)($actor['inbox'] ?? '');
    $shared = (string)($actor['endpoints']['sharedInbox'] ?? '');
    if ($actor_url === '' || $domain === '' || $inbox === '' || !sv_url_is_public($inbox)) return 'refused';
    if ($shared !== '' && !sv_url_is_public($shared)) $shared = '';

    $blocked = $pdo->prepare("SELECT 1 FROM snap_relay_blocklist WHERE domain = ? LIMIT 1");
    $blocked->execute([$domain]);
    if ($blocked->fetchColumn()) return 'blocked';
    $allowed = $pdo->prepare("SELECT 1 FROM snap_relay_allowlist WHERE domain = ? LIMIT 1");
    $allowed->execute([$domain]);
    $state = (($settings['smackcast_admission_mode'] ?? 'allowlist') === 'open' || $allowed->fetchColumn())
        ? 'active' : 'pending';
    $follow_id = (string)($activity['id'] ?? '');
    $pdo->prepare(
        "INSERT INTO snap_relay_subscribers
            (actor_url, domain, inbox_url, shared_inbox_url, follow_id, state, last_seen_at)
         VALUES (?, ?, ?, NULLIF(?, ''), ?, ?, NOW())
         ON DUPLICATE KEY UPDATE domain=VALUES(domain), inbox_url=VALUES(inbox_url),
           shared_inbox_url=VALUES(shared_inbox_url), follow_id=VALUES(follow_id),
           state=IF(state='blocked','blocked',VALUES(state)), last_seen_at=NOW()"
    )->execute([$actor_url, $domain, $inbox, $shared, $follow_id, $state]);
    return $state;
}

function sc_relay_leave(PDO $pdo, string $actor_url): void {
    $pdo->prepare("UPDATE snap_relay_subscribers SET state='left', last_seen_at=NOW() WHERE actor_url=?")
        ->execute([$actor_url]);
}

/** Queue exactly one Announce per destination/object; snap_relay_intake dedups intake. */
function sc_relay_fanout(PDO $pdo, array $settings, array $activity, string $actor_url): int {
    $member = $pdo->prepare("SELECT 1 FROM snap_relay_subscribers WHERE actor_url=? AND state='active' LIMIT 1");
    $member->execute([$actor_url]);
    if (!$member->fetchColumn()) return 0;
    $object = $activity['object'] ?? [];
    if (!is_array($object) || !in_array(($object['type'] ?? ''), ['Note','Image'], true)) return 0;
    if (($object['inReplyTo'] ?? null) || !sc_relay_is_discoverable($activity, $object)) return 0;
    $object_id = (string)($object['id'] ?? '');
    $activity_id = (string)($activity['id'] ?? $object_id);
    $attributed = is_array($object['attributedTo'] ?? null)
        ? (string)($object['attributedTo']['id'] ?? '') : (string)($object['attributedTo'] ?? '');
    if ($object_id === '' || $attributed !== $actor_url
        || !sc_relay_actor_owns_object($actor_url, $object_id)) return 0;
    try {
        $pdo->prepare("INSERT INTO snap_relay_intake (activity_id, object_id, origin_actor_url) VALUES (?,?,?)")
            ->execute([$activity_id, $object_id, $actor_url]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '23000') throw $e;
    }
    $announce = json_encode([
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => sv_actor_url($settings) . '#announce-' . hash('sha256', $object_id),
        'type' => 'Announce', 'actor' => sv_actor_url($settings),
        'object' => $object_id, 'to' => ['https://www.w3.org/ns/activitystreams#Public'],
    ], JSON_UNESCAPED_SLASHES);
    return sc_relay_queue_to_members($pdo, $actor_url, $announce,
        'relay-create:' . hash('sha256', $object_id));
}

/** Refresh a relayed object after a verified origin Update. */
function sc_relay_refresh(PDO $pdo, array $settings, array $activity, string $actor_url): int {
    $member = $pdo->prepare("SELECT 1 FROM snap_relay_subscribers WHERE actor_url=? AND state='active' LIMIT 1");
    $member->execute([$actor_url]);
    if (!$member->fetchColumn()) return 0;
    $object = $activity['object'] ?? [];
    if (!is_array($object) || !in_array(($object['type'] ?? ''), ['Note','Image'], true)) return 0;
    $object_id = (string)($object['id'] ?? '');
    $attributed = is_array($object['attributedTo'] ?? null)
        ? (string)($object['attributedTo']['id'] ?? '') : (string)($object['attributedTo'] ?? '');
    if ($object_id === '' || $attributed !== $actor_url
        || !sc_relay_actor_owns_object($actor_url, $object_id)
        || !sc_relay_is_discoverable($activity, $object)) return 0;
    $announce = json_encode([
        '@context'=>'https://www.w3.org/ns/activitystreams',
        'id'=>sv_actor_url($settings).'#announce-update-'.hash('sha256',(string)($activity['id'] ?? $object_id)),
        'type'=>'Announce','actor'=>sv_actor_url($settings),'object'=>$object_id,
        'to'=>['https://www.w3.org/ns/activitystreams#Public'],
    ], JSON_UNESCAPED_SLASHES);
    return sc_relay_queue_to_members($pdo, $actor_url, $announce,
        'relay-update:' . hash('sha256', (string)($activity['id'] ?? $object_id)));
}

function sc_relay_queue_to_members(PDO $pdo, string $origin_actor, string $json, string $prefix): int {
    $rows = $pdo->query("SELECT actor_url,inbox_url,shared_inbox_url FROM snap_relay_subscribers WHERE state='active'")
        ->fetchAll(PDO::FETCH_ASSOC);
    $queued = 0;
    foreach ($rows as $row) {
        if ((string)$row['actor_url'] === $origin_actor) continue;
        // Personal inbox delivery avoids losing a recipient when several actors
        // happen to advertise the same sharedInbox.
        $inbox = (string)$row['inbox_url'];
        if ($inbox === '' || !sv_url_is_public($inbox)) continue;
        if (sv_queue_delivery($pdo, $inbox, $json,
            substr($prefix . ':' . hash('sha256', $inbox), 0, 191)) > 0) $queued++;
    }
    return $queued;
}

/** Retract the relay's prior Announce after an origin Delete/moderation event. */
function sc_relay_retract(PDO $pdo, array $settings, string $actor_url, string $object_id, string $activity_id): int {
    if ($actor_url === '' || $object_id === '') return 0;
    $known = $pdo->prepare("SELECT 1 FROM snap_relay_intake WHERE object_id=? AND origin_actor_url=? LIMIT 1");
    $known->execute([$object_id, $actor_url]);
    if (!$known->fetchColumn()) return 0;
    $announce_id = sv_actor_url($settings) . '#announce-' . hash('sha256', $object_id);
    $undo = json_encode([
        '@context'=>'https://www.w3.org/ns/activitystreams',
        'id'=>sv_actor_url($settings).'#undo-announce-'.hash('sha256',($activity_id ?: '') . "\n" . $object_id),
        'type'=>'Undo','actor'=>sv_actor_url($settings),
        'object'=>['id'=>$announce_id,'type'=>'Announce','actor'=>sv_actor_url($settings),'object'=>$object_id],
        'to'=>['https://www.w3.org/ns/activitystreams#Public'],
    ], JSON_UNESCAPED_SLASHES);
    return sc_relay_queue_to_members($pdo, $actor_url, $undo,
        'relay-retract:' . hash('sha256', ($activity_id ?: '') . "\n" . $object_id));
}

function sc_relay_remove_membership(PDO $pdo, string $object_id, string $feed = 'local'): void {
    $q = $pdo->prepare("SELECT id FROM snap_ap_timeline WHERE object_id=? LIMIT 1");
    $q->execute([$object_id]);
    $id = (int)$q->fetchColumn();
    if ($id <= 0) return;
    $pdo->prepare("DELETE FROM snap_ap_timeline_membership WHERE timeline_id=? AND feed=?")->execute([$id,$feed]);
    $left = $pdo->prepare("SELECT 1 FROM snap_ap_timeline_membership WHERE timeline_id=? LIMIT 1");
    $left->execute([$id]);
    if (!$left->fetchColumn()) $pdo->prepare("DELETE FROM snap_ap_timeline WHERE id=?")->execute([$id]);
}

function sc_relay_add_membership(PDO $pdo, string $object_id, string $feed, string $via): void {
    // This policy file ships to ordinary blogs too; relay membership storage
    // does not.  Cache the schema check for this request and stay inert when
    // the hub-only table is absent.
    static $has_membership_table = null;
    if ($has_membership_table === null) {
        try {
            $has_membership_table = (bool)$pdo->query(
                "SELECT 1 FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='snap_ap_timeline_membership' LIMIT 1"
            )->fetchColumn();
        } catch (Throwable $e) {
            $has_membership_table = false;
        }
    }
    if (!$has_membership_table) return;
    $s = $pdo->prepare("SELECT id FROM snap_ap_timeline WHERE object_id=? LIMIT 1");
    $s->execute([$object_id]);
    $timeline_id = (int)$s->fetchColumn();
    if ($timeline_id > 0) {
        $pdo->prepare("INSERT IGNORE INTO snap_ap_timeline_membership
            (timeline_id,feed,discovered_via_actor) VALUES (?,?,?)")
            ->execute([$timeline_id, $feed, $via]);
    }
}

function sc_relay_queue_ingest(PDO $pdo, string $relay, string $object_id, string $error): void {
    $pdo->prepare("INSERT INTO snap_relay_ingest_jobs (relay_actor_url,object_id,last_error)
        VALUES (?,?,?) ON DUPLICATE KEY UPDATE status='queued',last_error=VALUES(last_error)")
        ->execute([$relay, $object_id, substr($error, 0, 500)]);
}

/** Try one relay Announce. A transient fetch creates durable receiver work. */
function sc_relay_receive_announce(PDO $pdo, array $settings, string $relay, string $object_id): bool {
    if ($relay !== sv_relay_actor_url($settings) || !sv_is_following($pdo, $relay)) return false;
    $object = sv_fetch_ap($object_id, $settings);
    if (!is_array($object)) {
        sc_relay_queue_ingest($pdo, $relay, $object_id, 'origin fetch failed');
        return true;
    }
    if ((string)($object['id'] ?? '') !== $object_id) return true;
    $actor = is_array($object['attributedTo'] ?? null)
        ? (string)($object['attributedTo']['id'] ?? '') : (string)($object['attributedTo'] ?? '');
    if ($actor === '' || !sc_relay_actor_owns_object($actor, $object_id)
        || sc_relay_actor_blocked($pdo, $actor)
        || !sc_relay_is_discoverable([], $object)) return true;
    $actor_doc = sv_fetch_ap($actor, $settings);
    if (!is_array($actor_doc) || (string)($actor_doc['id'] ?? '') !== $actor) return true;
    sv_ingest_timeline($pdo, $object, $actor, '', false, null, 'local', $relay);
    $pdo->prepare("DELETE FROM snap_relay_ingest_jobs WHERE relay_actor_url=? AND object_id=?")
        ->execute([$relay, $object_id]);
    return true;
}

function sc_relay_process_ingest_jobs(PDO $pdo, array $settings, int $limit = 20): array {
    // This file ships everywhere, but relay tables exist only on an explicitly
    // configured SMACKCAST hub. Ordinary blogs must never touch hub-only schema.
    if (!sc_relay_is_hub($settings)) return [0, 0];
    $q = $pdo->prepare("SELECT * FROM snap_relay_ingest_jobs WHERE status='queued' AND next_try_at<=NOW()
        ORDER BY next_try_at,id LIMIT " . max(1, min(100, $limit)));
    $q->execute(); $done = 0; $retry = 0;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $job) {
        $before = $pdo->prepare("SELECT 1 FROM snap_ap_timeline WHERE object_id=? LIMIT 1");
        $before->execute([$job['object_id']]);
        sc_relay_receive_announce($pdo, $settings, $job['relay_actor_url'], $job['object_id']);
        $after = $pdo->prepare("SELECT 1 FROM snap_ap_timeline WHERE object_id=? LIMIT 1");
        $after->execute([$job['object_id']]);
        if ($after->fetchColumn()) { $done++; continue; }
        $attempts = (int)$job['attempts'] + 1;
        $shelve = $attempts >= 8 || strtotime((string)$job['created_at']) < time() - 604800;
        $delay = min(86400, 300 * (2 ** min(8, $attempts - 1)));
        $pdo->prepare("UPDATE snap_relay_ingest_jobs SET attempts=?,status=?,next_try_at=DATE_ADD(NOW(),INTERVAL ? SECOND) WHERE id=?")
            ->execute([$attempts, $shelve ? 'shelved' : 'queued', $delay, $job['id']]);
        $retry++;
    }
    return [$done, $retry];
}

/**
 * Bounded hub-side self-heal for a publication notify missed during hub outage.
 * Old history is never replayed: only public Creates inside the seven-day horizon.
 */
function sc_relay_recover_member_outboxes(PDO $pdo, array $settings, int $members = 5, int $items = 20): array {
    if (!sc_relay_is_hub($settings)
        || ($settings['smackcast_outbox_recovery_enabled'] ?? '0') !== '1') return [0, 0];
    $members = max(1, min(20, $members));
    $items = max(1, min(50, $items));
    $q = $pdo->query("SELECT actor_url FROM snap_relay_subscribers WHERE state='active'
        ORDER BY COALESCE(last_outbox_check_at,'1970-01-01') ASC LIMIT {$members}");
    $checked = 0; $recovered = 0; $cutoff = time() - 604800;
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $actor_url) {
        $actor = sv_fetch_ap((string)$actor_url, $settings);
        $outbox_url = is_array($actor) ? (string)($actor['outbox'] ?? '') : '';
        $collection = $outbox_url !== '' ? sv_fetch_ap($outbox_url, $settings) : null;
        if (is_array($collection) && isset($collection['first']) && is_string($collection['first'])) {
            $first = sv_fetch_ap($collection['first'], $settings);
            if (is_array($first)) $collection = $first;
        }
        $activities = is_array($collection)
            ? ($collection['orderedItems'] ?? $collection['items'] ?? []) : [];
        if (!is_array($activities)) $activities = [];
        foreach (array_slice($activities, 0, $items) as $activity) {
            if (!is_array($activity) || ($activity['type'] ?? '') !== 'Create') continue;
            $object = $activity['object'] ?? [];
            if (!is_array($object)) continue;
            $published = strtotime((string)($object['published'] ?? $activity['published'] ?? ''));
            if ($published !== false && $published < $cutoff) continue;
            $recovered += sc_relay_fanout($pdo, $settings, $activity, (string)$actor_url) > 0 ? 1 : 0;
        }
        $pdo->prepare("UPDATE snap_relay_subscribers SET last_outbox_check_at=NOW() WHERE actor_url=?")
            ->execute([$actor_url]);
        $checked++;
    }
    return [$checked, $recovered];
}

// ===== SNAPSMACK EOF =====
