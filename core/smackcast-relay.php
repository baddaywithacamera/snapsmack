<?php
/**
 * SNAPSMACK — SMACKCAST relay policy (0.7.545D)
 *
 * Thin policy layer over the shared FEDIVERSE signature, fetch and delivery
 * primitives. Fan-out policy is hub-only; a normal blog that explicitly
 * follows the configured relay also runs the receiver and recovery paths.
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

/** Receiver-side schema self-heal for installs that predate the durable ingest queue. */
function sc_relay_ensure_ingest_jobs(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS snap_relay_ingest_jobs (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        relay_actor_url varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
        object_id varchar(600) COLLATE utf8mb4_unicode_ci NOT NULL,
        attempts int unsigned NOT NULL DEFAULT 0,
        status enum('queued','shelved') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
        next_try_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_error varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        feed varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'local',
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_relay_ingest (relay_actor_url(150),object_id(191)),
        KEY idx_relay_ingest_due (status,next_try_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // 681D: a retried Announce must land in the same tab as a first-try one.
    static $has_feed_col = null;
    if ($has_feed_col === null) {
        try {
            $has_feed_col = (bool)$pdo->query("SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='snap_relay_ingest_jobs' AND COLUMN_NAME='feed' LIMIT 1")->fetchColumn();
            if (!$has_feed_col) {
                $pdo->exec("ALTER TABLE snap_relay_ingest_jobs ADD COLUMN feed varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'local'");
                $has_feed_col = true;
            }
        } catch (Throwable $e) { $has_feed_col = false; }
    }
}

// ── 719D "STAGE NAME": @handle@photoblogs.fyi aliases ───────────────────────
// A member blog may be addressed as @<handle>@<relay domain>. The roster row
// carries the alias; the hub answers WebFinger for it (sv_webfinger) with the
// spoke's own actor as `self`, and the spoke answers the same question about
// itself so remote servers see both ends agree. The handle is claimed at join
// time from the actor's preferredUsername — same name as the blog's own
// handle, first come first served, unique on the hub. It is NEVER written to
// alsoKnownAs (that is the Move signal and arms the anti-hijack path).

/** Schema self-heal: the alias column on installs that predate 719D. */
function sc_relay_ensure_alias_column(PDO $pdo): void {
    static $done = null;
    if ($done !== null) return;
    try {
        $has = (bool)$pdo->query("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='snap_relay_subscribers' AND COLUMN_NAME='alias_handle' LIMIT 1")->fetchColumn();
        if (!$has) {
            $pdo->exec("ALTER TABLE snap_relay_subscribers ADD COLUMN alias_handle varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL");
            $pdo->exec("ALTER TABLE snap_relay_subscribers ADD UNIQUE KEY uq_relay_sub_alias (alias_handle)");
        }
        $done = true;
    } catch (Throwable $e) { $done = false; }
}

/** The one handle shape the alias accepts: 1-60 of [a-z0-9_], same as the blog handle. */
function sc_relay_alias_normalize(string $handle): string {
    $h = strtolower(trim($handle));
    return preg_match('/^[a-z0-9_]{1,60}$/', $h) ? $h : '';
}

/** Names the hub keeps for itself — never handed out as a member alias. */
function sc_relay_alias_reserved(string $handle, array $settings): bool {
    $own = strtolower(function_exists('sv_handle') ? sv_handle($settings) : '');
    return $handle === 'curator' || ($own !== '' && $handle === $own);
}

/**
 * Claim <handle> as the alias of <actor_url> if it is free. Free means: no other
 * row holds it, or the row holding it LEFT the relay more than 90 days ago (held
 * that long so a departed blog's name cannot be picked up the next morning and
 * worn by someone else; released after so a dead blog does not squat it forever).
 * Returns the alias now on the row (existing one wins; '' when none could be set).
 */
function sc_relay_claim_alias(PDO $pdo, array $settings, string $actor_url, string $wanted): string {
    sc_relay_ensure_alias_column($pdo);
    try {
        $cur = $pdo->prepare("SELECT alias_handle FROM snap_relay_subscribers WHERE actor_url=? LIMIT 1");
        $cur->execute([$actor_url]);
        $have = (string)($cur->fetchColumn() ?: '');
        if ($have !== '') return $have;
        $h = sc_relay_alias_normalize($wanted);
        if ($h === '' || sc_relay_alias_reserved($h, $settings)) return '';
        $holder = $pdo->prepare("SELECT id, actor_url, state, last_seen_at FROM snap_relay_subscribers WHERE alias_handle=? LIMIT 1");
        $holder->execute([$h]);
        $row = $holder->fetch(PDO::FETCH_ASSOC);
        if ($row && (string)$row['actor_url'] !== $actor_url) {
            $left_long_ago = ($row['state'] ?? '') === 'left'
                && !empty($row['last_seen_at'])
                && strtotime((string)$row['last_seen_at']) < time() - 90 * 86400;
            if (!$left_long_ago) return '';
            $pdo->prepare("UPDATE snap_relay_subscribers SET alias_handle=NULL WHERE id=?")->execute([(int)$row['id']]);
        }
        $pdo->prepare("UPDATE snap_relay_subscribers SET alias_handle=? WHERE actor_url=? AND alias_handle IS NULL")
            ->execute([$h, $actor_url]);
        return $h;
    } catch (Throwable $e) { return ''; }
}

/**
 * Members who joined before 719D have no alias yet and will not re-join. Each
 * cron tick on the hub names a few of them from their live actor doc — bounded
 * (default 3 fetches), active rows only, oldest first. Returns [checked, named].
 */
function sc_relay_backfill_aliases(PDO $pdo, array $settings, int $limit = 3): array {
    if (!sc_relay_is_hub($settings)) return [0, 0];
    sc_relay_ensure_alias_column($pdo);
    $limit = max(1, min(20, $limit));
    try {
        $rows = $pdo->query("SELECT actor_url FROM snap_relay_subscribers
            WHERE state='active' AND alias_handle IS NULL ORDER BY subscribed_at ASC LIMIT {$limit}")
            ->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) { return [0, 0]; }
    $checked = 0; $named = 0;
    foreach ($rows as $actor_url) {
        $checked++;
        $actor = sv_fetch_ap((string)$actor_url, $settings);
        $want = is_array($actor) ? (string)($actor['preferredUsername'] ?? '') : '';
        if ($want === '') continue;
        if (sc_relay_claim_alias($pdo, $settings, (string)$actor_url, $want) !== '') $named++;
    }
    return [$checked, $named];
}

/** The ACTIVE member behind an alias, or null. Left/blocked/pending rows never answer. */
function sc_relay_alias_lookup(PDO $pdo, string $handle): ?array {
    $h = sc_relay_alias_normalize($handle);
    if ($h === '') return null;
    sc_relay_ensure_alias_column($pdo);
    try {
        $q = $pdo->prepare("SELECT actor_url, domain, alias_handle FROM snap_relay_subscribers
            WHERE alias_handle=? AND state='active' LIMIT 1");
        $q->execute([$h]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) { return null; }
}

/** Alias rows the hub holds, for the FLEET review and the directory. */
function sc_relay_alias_rows(PDO $pdo): array {
    sc_relay_ensure_alias_column($pdo);
    try {
        return $pdo->query("SELECT actor_url, domain, state, alias_handle FROM snap_relay_subscribers
            WHERE alias_handle IS NOT NULL ORDER BY alias_handle")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

function sc_relay_is_receiver(PDO $pdo, array $settings): bool {
    $relay = sv_relay_actor_url($settings);
    return $relay !== '' && sv_is_following($pdo, $relay);
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

/**
 * Is this actor blocked?
 *
 * $conclusive, when passed, says whether every source could actually be READ.
 * This function answers false for an unreadable snap_ap_blocks, which is right
 * for RECEIVING -- a syncing schema must not stop a post arriving -- and wrong
 * for relaying outward, where "I could not tell" has to mean "do not
 * broadcast". Codex's review finding 1: sc_relay_source_may_relay() called
 * this and so inherited the swallow, and only a snap_relay_blocklist failure
 * closed the gate. With snap_ap_blocks absent and snap_relay_blocklist
 * readable, the hub relayed.
 *
 * One implementation, two readings of a failure. The caller decides which it
 * needs rather than a second copy of the policy existing somewhere.
 */
function sc_relay_actor_blocked(PDO $pdo, string $actor_url, ?bool &$conclusive = null): bool {
    $conclusive = true;
    $domain = strtolower(rtrim((string)(parse_url($actor_url, PHP_URL_HOST) ?: ''), '.'));
    try {
        $q = $pdo->prepare("SELECT 1 FROM snap_ap_blocks
            WHERE (kind='actor' AND value=?) OR (kind='domain' AND value=?) LIMIT 1");
        $q->execute([$actor_url, $domain]);
        if ($q->fetchColumn()) return true;
    } catch (Throwable $e) {
        // schema may be syncing — receiving carries on, relaying must not
        $conclusive = false;
    }
    if (function_exists('pc_is_blocked')) {
        try { if (pc_is_blocked($pdo, $actor_url)) return true; }
        catch (Throwable $e) { $conclusive = false; }
    }
    return false;
}

/**
 * May the hub still relay for this source, right now?
 *
 * SS-1481 finding 5. sc_relay_join() consults snap_relay_blocklist once, at
 * the moment a member joins (:229), and sc_relay_actor_blocked() is consulted
 * on RECEIVE (:444). Neither is consulted on the way OUT: sc_relay_fanout()
 * and sc_relay_refresh() asked only whether the actor was a relay source. So a
 * domain blocklisted AFTER it joined, or an actor added to snap_ap_blocks, kept
 * being broadcast to every member until its subscriber row happened to change
 * state. A block that a site has recorded should take effect on the next post,
 * not at the next join.
 *
 * This adds no new policy. It reuses sc_relay_actor_blocked() -- which already
 * covers snap_ap_blocks by actor and by domain, plus pc_is_blocked() -- and
 * adds the one source it does not cover, the relay's own domain blocklist, read
 * exactly as sc_relay_join() reads it.
 *
 * ON FAILURE THIS SAYS "BLOCKED", for EVERY source it consults. If the hub
 * cannot tell whether an actor or a domain is blocked, broadcasting to every
 * member is the move it cannot take back. Refusing is recoverable; the posts
 * relay on the next tick once the tables answer.
 *
 * That used to hold for only one of the two. Codex's review finding 1:
 * sc_relay_actor_blocked() swallows an unreadable snap_ap_blocks and answers
 * false -- correct for receiving, where a syncing schema must not stop a post
 * arriving -- so with that table absent and snap_relay_blocklist readable, this
 * said MAY RELAY. It now asks that helper whether its answer was conclusive.
 */
function sc_relay_source_may_relay(PDO $pdo, string $actor_url): bool {
    if ($actor_url === '') return false;
    $conclusive = true;
    if (sc_relay_actor_blocked($pdo, $actor_url, $conclusive)) return false;
    if (!$conclusive) {
        error_log('SnapSmack relay: block list unreadable, refusing to relay '
            . $actor_url);
        return false;
    }

    $domain = strtolower(rtrim((string)(parse_url($actor_url, PHP_URL_HOST) ?: ''), '.'));
    if ($domain === '') return false;
    try {
        $q = $pdo->prepare("SELECT 1 FROM snap_relay_blocklist WHERE domain = ? LIMIT 1");
        $q->execute([$domain]);
        if ($q->fetchColumn()) return false;
    } catch (Throwable $e) {
        error_log('SnapSmack relay: blocklist unreadable, refusing to relay '
            . $domain . ' — ' . $e->getMessage());
        return false;
    }
    return true;
}

/**
 * Retract everything this hub has already relayed for one actor.
 *
 * The companion to the check above: stopping future fan-out does nothing about
 * the posts already sitting in every member's reader. Reuses sc_relay_retract()
 * per object rather than building a second Undo, and PAGES the intake rows
 * instead of taking a fixed slice, so a prolific actor is fully retracted.
 *
 * Called when a block is recorded. NOTE, because it is worth saying plainly:
 * at the time of writing nothing in the repo INSERTs into snap_relay_blocklist
 * or snap_ap_blocks -- both tables are read-only in code and populated by hand.
 * So this function has no automatic trigger yet; it is the piece an admin
 * control will call, and it is callable now from a one-off script. That gap is
 * reported rather than papered over with an invented admin page.
 *
 * @return int objects retracted
 */
function sc_relay_retract_actor(PDO $pdo, array $settings, string $actor_url,
                                string $activity_id = '', int $page = 200): int {
    if ($actor_url === '') return 0;
    $retracted = 0;
    $lastId = 0;
    $page = max(1, min(1000, $page));
    while (true) {
        try {
            $q = $pdo->prepare("SELECT id, object_id FROM snap_relay_intake
                                 WHERE origin_actor_url = ? AND id > ?
                                 ORDER BY id ASC LIMIT {$page}");
            $q->execute([$actor_url, $lastId]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('SnapSmack relay: retract paging failed for ' . $actor_url
                . ' — ' . $e->getMessage());
            break;
        }
        if (!$rows) break;
        foreach ($rows as $row) {
            $lastId = (int)$row['id'];
            $object_id = (string)$row['object_id'];
            if ($object_id === '') continue;
            if (sc_relay_retract($pdo, $settings, $actor_url, $object_id,
                                 $activity_id !== '' ? $activity_id : $object_id) > 0) {
                $retracted++;
            }
        }
        if (count($rows) < $page) break;
    }
    return $retracted;
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
    // 719D: the blog's own handle becomes its @handle@<relay domain> alias, if free.
    sc_relay_claim_alias($pdo, $settings, $actor_url, (string)($actor['preferredUsername'] ?? ''));
    return $state;
}

function sc_relay_leave(PDO $pdo, string $actor_url): void {
    $pdo->prepare("UPDATE snap_relay_subscribers SET state='left', last_seen_at=NOW() WHERE actor_url=?")
        ->execute([$actor_url]);
}

/**
 * Is this actor an allowed relay source? Normal members join the relay. The
 * curator's consent-directory follows are a separate, explicitly managed
 * source; manual hub follows do not become network-wide relay sources.
 */
function sc_relay_actor_is_source(PDO $pdo, string $actor_url): bool {
    $member = $pdo->prepare("SELECT 1 FROM snap_relay_subscribers WHERE actor_url=? AND state='active' LIMIT 1");
    $member->execute([$actor_url]);
    if ($member->fetchColumn()) return true;
    try {
        $q = $pdo->prepare("SELECT 1 FROM snap_curator_directory c
            JOIN snap_ap_following f ON f.id=c.follow_row_id
            WHERE c.actor_url=? AND c.state IN ('following','followed') AND f.state='accepted' LIMIT 1");
        $q->execute([$actor_url]);
        return (bool)$q->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/**
 * Which reader tab a relayed post belongs in on every member blog.
 * A relay MEMBER's post is network-local. A post from a curator-followed
 * outside photographer (fediverse.info consent directory) is GLOBAL.
 * Before 681D every relayed post was filed as 'local' and nothing anywhere
 * ever wrote 'global', so the GLOBAL tab stayed empty on every install.
 */
function sc_relay_origin_feed(PDO $pdo, string $actor_url): string {
    $member = $pdo->prepare("SELECT 1 FROM snap_relay_subscribers WHERE actor_url=? AND state='active' LIMIT 1");
    $member->execute([$actor_url]);
    return $member->fetchColumn() ? 'local' : 'global';
}

/** Read the feed tag the hub put on a relay Announce. Untagged (pre-681D hub) = local. */
function sc_relay_announce_feed(array $activity): string {
    return (($activity['feed'] ?? '') === 'global') ? 'global' : 'local';
}

/** Queue exactly one Announce per destination/object; snap_relay_intake dedups intake. */
function sc_relay_fanout(PDO $pdo, array $settings, array $activity, string $actor_url): int {
    if (!sc_relay_actor_is_source($pdo, $actor_url)) return 0;
    // SS-1481 finding 5: a block recorded after this source joined must stop
    // the next post, not wait for a state change on its subscriber row.
    if (!sc_relay_source_may_relay($pdo, $actor_url)) return 0;
    $object = $activity['object'] ?? [];
    if (!is_array($object) || !in_array(($object['type'] ?? ''), ['Note','Image'], true)) return 0;
    if (($object['inReplyTo'] ?? null) || !sc_relay_is_discoverable($activity, $object)) return 0;
    $object_id = (string)($object['id'] ?? '');
    $activity_id = (string)($activity['id'] ?? $object_id);
    $attributed = is_array($object['attributedTo'] ?? null)
        ? (string)($object['attributedTo']['id'] ?? '') : (string)($object['attributedTo'] ?? '');
    if ($object_id === '' || $attributed !== $actor_url
        || !sc_relay_actor_owns_object($actor_url, $object_id)) return 0;
    $announce = json_encode([
        '@context' => ['https://www.w3.org/ns/activitystreams',
            ['photoblogs' => 'https://photoblogs.fyi/ns#', 'feed' => 'photoblogs:feed']],
        'id' => sv_actor_url($settings) . '#announce-' . hash('sha256', $object_id),
        'type' => 'Announce', 'actor' => sv_actor_url($settings),
        'object' => $object_id, 'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        'feed' => sc_relay_origin_feed($pdo, $actor_url),
    ], JSON_UNESCAPED_SLASHES);

    // QUEUE FIRST, THEN RECORD THE INTAKE. Codex's review finding 4: the intake
    // row went in before this call, and the recovery loop skips any object with
    // an intake row (SS-1481 finding 10). So if the request died or queueing
    // threw after the insert, recovery would PERMANENTLY skip the very post it
    // exists to recover -- the fix for one fault building a worse one.
    //
    // This order cannot lose a post. A throw here leaves no intake row, so the
    // next recovery tick takes the post in and relays it. The cost is that the
    // same object could be queued twice in a race, and sv_queue_delivery()'s
    // dedupe_key -- 'relay-create:' + hash(object) per inbox -- collapses that
    // to one row, which is what made the intake UNIQUE key redundant as a
    // dedupe in the first place.
    $queued = sc_relay_queue_to_members($pdo, $actor_url, $announce,
        'relay-create:' . hash('sha256', $object_id));
    try {
        $pdo->prepare("INSERT INTO snap_relay_intake (activity_id, object_id, origin_actor_url) VALUES (?,?,?)")
            ->execute([$activity_id, $object_id, $actor_url]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '23000') throw $e;
    }
    return $queued;
}

/** Refresh a relayed object after a verified origin Update. */
function sc_relay_refresh(PDO $pdo, array $settings, array $activity, string $actor_url): int {
    if (!sc_relay_actor_is_source($pdo, $actor_url)) return 0;
    // Same gate as fan-out: an Update is another broadcast to every member.
    if (!sc_relay_source_may_relay($pdo, $actor_url)) return 0;
    $object = $activity['object'] ?? [];
    if (!is_array($object) || !in_array(($object['type'] ?? ''), ['Note','Image'], true)) return 0;
    $object_id = (string)($object['id'] ?? '');
    $attributed = is_array($object['attributedTo'] ?? null)
        ? (string)($object['attributedTo']['id'] ?? '') : (string)($object['attributedTo'] ?? '');
    if ($object_id === '' || $attributed !== $actor_url
        || !sc_relay_actor_owns_object($actor_url, $object_id)
        || !sc_relay_is_discoverable($activity, $object)) return 0;
    // SS-1481 finding 3. This Announce used to carry no feed key, while
    // sc_relay_fanout's does. An untagged Announce is read as LOCAL by
    // sc_relay_announce_feed() -- deliberately, for pre-681D hubs -- so one
    // edit to a GLOBAL curator-sourced post added a local membership to it and
    // the post appeared in a tab it was never meant to be in. The tag has to
    // be the same on both paths, derived the same way, or an Update silently
    // reclassifies the post it is refreshing.
    //
    // The @context gains the photoblogs feed mapping for the same reason
    // fan-out has it: an extension term nobody declares is an extension term
    // a strict consumer may drop.
    $announce = json_encode([
        '@context'=>['https://www.w3.org/ns/activitystreams',
            ['photoblogs' => 'https://photoblogs.fyi/ns#', 'feed' => 'photoblogs:feed']],
        'id'=>sv_actor_url($settings).'#announce-update-'.hash('sha256',(string)($activity['id'] ?? $object_id)),
        'type'=>'Announce','actor'=>sv_actor_url($settings),'object'=>$object_id,
        'to'=>['https://www.w3.org/ns/activitystreams#Public'],
        'feed'=>sc_relay_origin_feed($pdo, $actor_url),
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
    if (!$has_membership_table) {
        // SS-1481 finding 4. This used to be a bare `return`, and the silence
        // was the problem rather than the inertness.
        //
        // Membership is what makes the LOCAL and GLOBAL tabs deterministic:
        // snap_ap_timeline_membership's primary key is (timeline_id, feed), so
        // a direct follow and a relay arrival of the same post each add their
        // own row. Without the table the reader falls back to the single
        // `source` column, and sv_ingest_timeline() never updates source on a
        // duplicate -- so FIRST ARRIVAL WINS and a post can simply miss a tab.
        //
        // The table is created by schema-sync, not by runtime self-heal, so a
        // hub whose sync has not run looks like it is working while quietly
        // filing posts in the wrong tab. Nothing said so.
        //
        // IT ONLY COMPLAINS WHEN MEMBERSHIP WAS EXPECTED. This function runs on
        // every timeline ingest on every site, and an ordinary blog has no
        // relay tables by design -- logging there would be noise on every post
        // forever, which is its own way of saying nothing. A 'home' feed needs
        // no membership row. A 'local' or 'global' feed is a relay arrival, and
        // that is when the absence matters.
        //
        // Once per request, because the point is to be noticed, not to bury the
        // log under one line per post.
        static $warned = false;
        if (!$warned && ($feed === 'local' || $feed === 'global')) {
            $warned = true;
            error_log('SMACKCAST: snap_ap_timeline_membership is MISSING while '
                . 'ingesting a ' . $feed . ' relay post. LOCAL/GLOBAL tabs will '
                . 'fall back to the single source column, so first arrival wins '
                . 'and posts can miss a tab. Run schema-sync on this install.');
        }
        return;
    }
    $s = $pdo->prepare("SELECT id FROM snap_ap_timeline WHERE object_id=? LIMIT 1");
    $s->execute([$object_id]);
    $timeline_id = (int)$s->fetchColumn();
    if ($timeline_id > 0) {
        $pdo->prepare("INSERT IGNORE INTO snap_ap_timeline_membership
            (timeline_id,feed,discovered_via_actor) VALUES (?,?,?)")
            ->execute([$timeline_id, $feed, $via]);
    }
}

function sc_relay_queue_ingest(PDO $pdo, string $relay, string $object_id, string $error, string $feed = 'local'): void {
    sc_relay_ensure_ingest_jobs($pdo);
    $feed = $feed === 'global' ? 'global' : 'local';
    try {
        $pdo->prepare("INSERT INTO snap_relay_ingest_jobs (relay_actor_url,object_id,last_error,feed)
            VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE status='queued',last_error=VALUES(last_error),feed=VALUES(feed)")
            ->execute([$relay, $object_id, substr($error, 0, 500), $feed]);
    } catch (Throwable $e) {
        // feed column could not be added (locked-down DB user): keep the old shape.
        $pdo->prepare("INSERT INTO snap_relay_ingest_jobs (relay_actor_url,object_id,last_error)
            VALUES (?,?,?) ON DUPLICATE KEY UPDATE status='queued',last_error=VALUES(last_error)")
            ->execute([$relay, $object_id, substr($error, 0, 500)]);
    }
}

/** Try one relay Announce. A transient fetch creates durable receiver work. */
function sc_relay_receive_announce(PDO $pdo, array $settings, string $relay, string $object_id, string $feed = 'local'): bool {
    if ($relay !== sv_relay_actor_url($settings) || !sv_is_following($pdo, $relay)) return false;
    $feed = $feed === 'global' ? 'global' : 'local';
    // Successful receipt also clears any earlier fetch-recovery row.  The
    // table therefore belongs to relay receivers, not only to the hub.
    sc_relay_ensure_ingest_jobs($pdo);
    $object = sv_fetch_ap($object_id, $settings);
    if (!is_array($object)) {
        sc_relay_queue_ingest($pdo, $relay, $object_id, 'origin fetch failed', $feed);
        return true;
    }
    if ((string)($object['id'] ?? '') !== $object_id) return true;
    $actor = is_array($object['attributedTo'] ?? null)
        ? (string)($object['attributedTo']['id'] ?? '') : (string)($object['attributedTo'] ?? '');
    if ($actor === '' || !sc_relay_actor_owns_object($actor, $object_id)
        || sc_relay_actor_blocked($pdo, $actor)
        || !sc_relay_is_discoverable([], $object)) return true;
    // SS-1481 finding 9. This fetch establishes one thing: the attributed actor
    // resolves and its id is what it claims. The document is then discarded --
    // it is not passed to the ingest below. So on a relay with traffic it was
    // one network round trip per Announce to re-answer a question we had
    // already answered minutes ago and written into snap_ap_actors.
    //
    // A recent cache row answers it. The cache does NOT hold publicKey, so it
    // could never stand in for a fetch used in verification; the liveness
    // question is all it is asked. The cost of the shortcut is that for up to
    // the TTL a just-deleted actor's post can still arrive, which is why the
    // window is 15 minutes rather than the row's lifetime.
    if (!sv_actor_seen_recently($pdo, $actor, 900)) {
        $actor_doc = sv_fetch_ap($actor, $settings);
        if (!is_array($actor_doc) || (string)($actor_doc['id'] ?? '') !== $actor) return true;
        if (function_exists('sv_cache_actor')) sv_cache_actor($pdo, $actor_doc);
    }
    sv_ingest_timeline($pdo, $object, $actor, '', false, null, $feed, $relay);
    $pdo->prepare("DELETE FROM snap_relay_ingest_jobs WHERE relay_actor_url=? AND object_id=?")
        ->execute([$relay, $object_id]);
    return true;
}

function sc_relay_process_ingest_jobs(PDO $pdo, array $settings, int $limit = 20): array {
    // The hub may receive relay work, and ordinary blogs need this worker when
    // they explicitly follow the relay. Unrelated installs remain inert.
    if (!sc_relay_is_hub($settings) && !sc_relay_is_receiver($pdo, $settings)) return [0, 0];
    sc_relay_ensure_ingest_jobs($pdo);
    $q = $pdo->prepare("SELECT * FROM snap_relay_ingest_jobs WHERE status='queued' AND next_try_at<=NOW()
        ORDER BY next_try_at,id LIMIT " . max(1, min(100, $limit)));
    $q->execute(); $done = 0; $retry = 0;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $job) {
        $before = $pdo->prepare("SELECT 1 FROM snap_ap_timeline WHERE object_id=? LIMIT 1");
        $before->execute([$job['object_id']]);
        sc_relay_receive_announce($pdo, $settings, $job['relay_actor_url'], $job['object_id'],
            (string)($job['feed'] ?? 'local'));
        $after = $pdo->prepare("SELECT 1 FROM snap_ap_timeline WHERE object_id=? LIMIT 1");
        $after->execute([$job['object_id']]);
        if ($after->fetchColumn()) { $done++; continue; }
        $attempts = (int)$job['attempts'] + 1;
        $shelve = $attempts >= 8 || strtotime((string)$job['created_at']) < time() - 604800;
        $delay = min(86400, 300 * (2 ** min(8, $attempts - 1)));

        // SS-1481 finding 11. If the origin answered the fetch that just failed
        // with a Retry-After, wait that long instead of our own backoff. The
        // outbound delivery queue has honoured this for a long time; inbound
        // ingest did not, and retrying on our own schedule against a server
        // asking us to slow down is how a polite instance starts rate-limiting
        // us harder.
        //
        // The accessor holds the header from the fetch inside the call above,
        // which is synchronous, so it belongs to THIS job and no other.
        //
        // WE TAKE THE LONGER OF THE TWO, deliberately. Honouring a Retry-After
        // SHORTER than our own backoff would let a remote pull us back into a
        // tight retry loop after we had already decided to back off -- so the
        // header can only ever slow us down, never speed us up.
        if (function_exists('sv_last_retry_after') && function_exists('sv_retry_after_seconds')) {
            $asked = sv_retry_after_seconds(sv_last_retry_after());
            if ($asked !== null) $delay = max($delay, $asked);
        }
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

    // SS-1481 finding 10. This loop re-reads the first $items of each member's
    // outbox every tick, and called sc_relay_fanout() on every Create it found
    // -- including ones relayed days ago.
    //
    // snap_relay_intake's UNIQUE key swallows the duplicate intake, so it LOOKS
    // harmless. It is not. The Announce still reaches sv_queue_delivery(),
    // whose ON DUPLICATE KEY UPDATE sets attempts = 0, status = 'queued' and
    // pulls next_try_at back to NOW. And because a SUCCESSFUL delivery row is
    // deleted, there is no row left to collide with -- so a fresh one is
    // inserted and the Announce is DELIVERED AGAIN. Every tick. For every
    // already-relayed post still inside the window.
    //
    // At the defaults that is up to 5 members x 20 items of repeat deliveries
    // every ten minutes, and it also resets the backoff of anything genuinely
    // still retrying.
    //
    // The cheapest correct fix is the one Muse proposed: skip what we have
    // already taken in. snap_relay_intake is exactly that record -- fanout
    // writes it -- so no new column and no cursor to keep in step.
    $seen = $pdo->prepare("SELECT 1 FROM snap_relay_intake
                            WHERE object_id = ? AND origin_actor_url = ? LIMIT 1");

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

            // Already taken in from this actor? Then it has already been
            // relayed, and re-announcing it would re-deliver it.
            $object_id = (string)($object['id'] ?? '');
            if ($object_id !== '') {
                try {
                    $seen->execute([$object_id, (string)$actor_url]);
                    if ($seen->fetchColumn()) continue;
                } catch (Throwable $e) {
                    // Cannot tell: fall through and let fanout's own intake
                    // insert decide. Recovery exists to catch MISSED posts, so
                    // the failure that matters is skipping one, not sending a
                    // duplicate the member's server will drop by id.
                    error_log('SnapSmack relay: intake lookup failed during recovery — '
                        . $e->getMessage());
                }
            }

            $recovered += sc_relay_fanout($pdo, $settings, $activity, (string)$actor_url) > 0 ? 1 : 0;
        }
        $pdo->prepare("UPDATE snap_relay_subscribers SET last_outbox_check_at=NOW() WHERE actor_url=?")
            ->execute([$actor_url]);
        $checked++;
    }
    return [$checked, $recovered];
}

/**
 * Seed GLOBAL from newly accepted curator follows. ActivityPub Follow does not
 * replay history, so without this bounded seven-day pass a healthy curator can
 * still leave every spoke's Global tab blank until somebody posts again.
 */
function sc_relay_recover_curator_outboxes(PDO $pdo, array $settings, int $actors = 2, int $items = 10): array {
    if (!sc_relay_is_hub($settings)
        || ($settings['smackcast_outbox_recovery_enabled'] ?? '0') !== '1') return [0, 0];
    if (function_exists('sc_curator_ensure_tables')) sc_curator_ensure_tables($pdo);
    $actors = max(1, min(10, $actors));
    $items = max(1, min(30, $items));
    try {
        $q = $pdo->query("SELECT c.id,c.actor_url FROM snap_curator_directory c
            JOIN snap_ap_following f ON f.id=c.follow_row_id
            WHERE c.state IN ('following','followed') AND f.state='accepted'
              AND c.actor_url IS NOT NULL AND c.actor_url<>''
            ORDER BY COALESCE(c.last_outbox_check_at,'1970-01-01') ASC,c.id ASC LIMIT {$actors}");
    } catch (Throwable $e) { return [0, 0]; }
    $checked = 0; $recovered = 0; $cutoff = time() - 604800;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $actor_url = (string)$row['actor_url'];
        // Pixelfed commonly exposes an AP outbox shell with totalItems but no
        // pages. Use the same universal gallery reader as the public client:
        // Mastodon REST, Pixelfed's public REST route, then a paginated AP
        // outbox. The previous AP-only path is why 18 accepted accounts yielded
        // one lonely post in GLOBAL.
        $actor = function_exists('sv_crawl_actor') ? sv_crawl_actor($actor_url) : null;
        $posts = is_array($actor) && function_exists('sv_fetch_gallery')
            ? sv_fetch_gallery($actor, $items) : [];
        foreach ($posts as $post) {
            if (!is_array($post)) continue;
            $object_id = trim((string)($post['id'] ?? ''));
            if ($object_id === '') continue;
            $published = strtotime((string)($post['published'] ?? ''));
            if ($published !== false && $published < $cutoff) continue;
            $attachments = [];
            foreach ((array)($post['images'] ?? []) as $image_url) {
                if (is_string($image_url) && $image_url !== '') {
                    $attachments[] = ['type'=>'Image','url'=>$image_url];
                }
            }
            if (!$attachments) continue;
            $object = [
                'id'=>$object_id, 'type'=>'Note', 'attributedTo'=>$actor_url,
                'url'=>(string)($post['url'] ?? $object_id),
                'published'=>(string)($post['published'] ?? ''),
                'content'=>nl2br(htmlspecialchars((string)($post['text'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
                'attachment'=>$attachments,
                'to'=>['https://www.w3.org/ns/activitystreams#Public'],
            ];
            $activity = [
                'id'=>$object_id . '#curator-recovery', 'type'=>'Create',
                'actor'=>$actor_url, 'object'=>$object,
                'to'=>['https://www.w3.org/ns/activitystreams#Public'],
            ];
            $recovered += sc_relay_fanout($pdo, $settings, $activity, $actor_url) > 0 ? 1 : 0;
        }
        $pdo->prepare("UPDATE snap_curator_directory SET last_outbox_check_at=NOW() WHERE id=?")
            ->execute([(int)$row['id']]);
        $checked++;
    }
    return [$checked, $recovered];
}

// ===== SNAPSMACK EOF =====
