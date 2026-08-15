<?php
/**
 * SNAPSMACK - PHOTO CHALLENGE (FEDISTRUCTURE spoke profile)
 *
 * The photofri.day / artfri.day logic, built as a THIN policy layer on top of
 * the site's single SMACKVERSE actor. It adds NO crypto, NO second actor, NO
 * duplicated federation stack — every signed request, inbox verify, delivery,
 * follow and timeline ingest is core/smackverse.php's (sv_*). This file only
 * adds the challenge policy: follow = JOIN, unfollow = LEAVE, and a board that
 * reads the posts the CMS already ingests.
 *
 * Enable per-install with snap_settings 'photochallenge_enabled' = '1'. When
 * off (every ordinary blog), every function here is inert — the inbox hook in
 * core/smackverse.php is guarded by function_exists()+pc_enabled().
 *
 * No image belonging to a participant is ever stored: the board reads
 * snap_ap_timeline, which holds only the origin permalink + hotlinked image
 * URL. Teaser-only, canonical -> origin, tease-then-eject. (FEDISTRUCTURE §10/§11.)
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (!function_exists('pc_enabled')) {

/** Is this install running the photo-challenge profile? */
function pc_enabled(array $settings): bool {
    return (string)($settings['photochallenge_enabled'] ?? '0') === '1';
}

/** The challenge hashtag, normalised (lowercase, no leading '#'). */
function pc_tag(array $settings): string {
    $t = strtolower(trim((string)($settings['photochallenge_tag'] ?? 'photofri')));
    $t = preg_replace('/[^a-z0-9_]/', '', ltrim($t, '#')) ?? '';
    return $t !== '' ? $t : 'photofri';
}

/** Display timezone retained for admin copy; qualification uses global UTC. */
function pc_tz(array $settings): DateTimeZone {
    $tz = trim((string)($settings['photochallenge_tz'] ?? ''));
    try { return $tz !== '' ? new DateTimeZone($tz) : new DateTimeZone(date_default_timezone_get()); }
    catch (Throwable $e) { return new DateTimeZone('UTC'); }
}

/**
 * The 50-hour sliding Photo-Friday window for a given moment.
 * Opens Thursday 10:00 UTC (Friday 00:00 in UTC+14) and closes Saturday
 * 12:00 UTC (Friday 24:00 in UTC-12).
 *
 * @return array{start:string,end:string,open:bool,week_key:string,label:string}
 */
function pc_window(array $settings, ?int $now_ts = null): array {
    $now = (new DateTimeImmutable('@' . ($now_ts ?? time())))->setTimezone(new DateTimeZone('UTC'));
    if (($settings['photochallenge_window_mode'] ?? 'weekly') === 'daily') {
        $start = $now->setTime(0, 0, 0);
        $end = $start->modify('+24 hours');
        return [
            'start'    => $start->format('Y-m-d H:i:s'),
            'end'      => $end->format('Y-m-d H:i:s'),
            'open'     => true,
            'week_key' => $start->format('Y-m-d'),
            'label'    => $start->format('M j, Y'),
        ];
    }
    $anchor = $now->setTime(10, 0, 0);
    $back = ((int)$anchor->format('N') - 4 + 7) % 7;       // 4 = Thursday
    $start = $anchor->modify("-{$back} days");
    if ($now < $start) $start = $start->modify('-7 days');
    $end   = $start->modify('+50 hours');
    $friday = $start->modify('+14 hours');
    return [
        'start'    => $start->format('Y-m-d H:i:s'),
        'end'      => $end->format('Y-m-d H:i:s'),
        'open'     => ($now >= $start && $now < $end),
        'week_key' => $friday->format('o-\WW'),
        'label'    => $friday->format('M j, Y'),
    ];
}

/** Create the two challenge-owned tables (idempotent, safe on every boot). */
function pc_ensure_tables(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS pc_participants (
            actor_url    varchar(500) NOT NULL,
            handle       varchar(190) NOT NULL DEFAULT '',
            joined_at    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            horsconcours tinyint(1)   NOT NULL DEFAULT 0,
            state        varchar(16)  NOT NULL DEFAULT 'active',
            PRIMARY KEY (actor_url(191)),
            KEY idx_state (state)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS pc_hall_of_fame (
            id          int unsigned NOT NULL AUTO_INCREMENT,
            week_key    varchar(12)  NOT NULL,
            place       tinyint      NOT NULL DEFAULT 1,
            actor_url   varchar(500) NOT NULL,
            handle      varchar(190) NOT NULL DEFAULT '',
            post_url    varchar(600) NOT NULL,
            caption     varchar(500) NOT NULL DEFAULT '',
            captured_at datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            active      tinyint(1)   NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uq_week_place (week_key, place)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // Durable per-entry engagement tally. The CMS inbox log (snap_ap_inbox_log)
    // is a 500-row diagnostic RING — it cannot be trusted to survive a busy
    // Friday, so ranking gets its own append-only, dedup-per-actor table. One
    // row per (entry object, actor, kind); the score is COUNT()s over it. Fed by
    // pc_record_like / pc_record_boost from the guarded Like/Announce inbox hooks
    // (see §4 of the build handoff — those hooks are the runtime-test surface).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS pc_engagement (
            object_id  varchar(500) NOT NULL,
            actor_url  varchar(500) NOT NULL,
            kind       varchar(8)   NOT NULL DEFAULT 'like',
            created_at datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_engage (object_id(160), actor_url(160), kind),
            KEY idx_engage_obj (object_id(191), kind)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS pc_outbound_boosts (
            object_id  varchar(500) NOT NULL,
            actor_url  varchar(500) NOT NULL,
            state      varchar(16)  NOT NULL DEFAULT 'pending',
            boosted_at datetime     NULL,
            last_error varchar(500) NOT NULL DEFAULT '',
            PRIMARY KEY (object_id(191)),
            KEY idx_pc_boost_state (state)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * FOLLOW = JOIN. Called from sv_handle_inbox after the CMS has recorded the
 * follower + sent the Accept. We record the participant and follow BACK, so the
 * participant's public #tag posts are delivered to our inbox and land on the
 * board via sv_ingest_timeline. Inert unless the profile is enabled.
 */
function pc_on_follow(PDO $pdo, array $settings, array $actor_doc): void {
    if (!pc_enabled($settings)) return;
    $actor_id = (string)($actor_doc['id'] ?? '');
    if ($actor_id === '') return;
    pc_ensure_tables($pdo);

    $handle = ($actor_doc['preferredUsername'] ?? '')
            . '@' . (parse_url($actor_id, PHP_URL_HOST) ?: '');
    $pdo->prepare(
        "INSERT INTO pc_participants (actor_url, handle, state)
         VALUES (?, ?, 'active')
         ON DUPLICATE KEY UPDATE handle = VALUES(handle),
                                 state  = IF(state='blocked','blocked','active')"
    )->execute([$actor_id, substr($handle, 0, 190)]);

    // Follow them back through the shared federation stack — no new crypto.
    if (function_exists('sv_follow_actor') && !sv_is_following($pdo, $actor_id)) {
        try { sv_follow_actor($pdo, $settings, $actor_id); } catch (Throwable $e) {}
    }
    if (function_exists('sv_notify')) {
        try { sv_notify($pdo, 'pc_join', $actor_id, $handle); } catch (Throwable $e) {}
    }
}

/** UNFOLLOW = LEAVE. Called from sv_handle_inbox on Undo(Follow). */
function pc_on_leave(PDO $pdo, array $settings, string $actor_url): void {
    if (!pc_enabled($settings) || $actor_url === '') return;
    try {
        $pdo->prepare("UPDATE pc_participants SET state='left' WHERE actor_url = ?")
            ->execute([$actor_url]);
    } catch (Throwable $e) {}
}

/** Set/clear a participant's #horsconcours opt-out (show on board, never ranked). */
function pc_set_horsconcours(PDO $pdo, string $actor_url, bool $on): void {
    try {
        $pdo->prepare("UPDATE pc_participants SET horsconcours = ? WHERE actor_url = ?")
            ->execute([$on ? 1 : 0, $actor_url]);
    } catch (Throwable $e) {}
}

/**
 * The current board: photo posts carrying the challenge tag, published inside
 * the active window. Reads the CMS's own snap_ap_timeline — no image is stored
 * here, only the origin permalink + hotlinked thumb. Newest first (ranking by
 * likes/boosts is the next layer — see NOTE below).
 *
 * @return array<int,array{handle:string,actor_url:string,url:string,thumb:string,
 *                          excerpt:string,published:string,is_boost:bool,horsconcours:bool}>
 */
function pc_board(PDO $pdo, array $settings, ?array $window = null, int $limit = 200): array {
    if (!pc_enabled($settings)) return [];
    $win = $window ?? pc_window($settings);
    $tag = pc_tag($settings);
    $rows = [];
    try {
        $st = $pdo->prepare(
            "SELECT t.object_id, t.actor_url, t.actor_handle, t.content, t.media_json, t.tags_json, t.url,
                    t.published, t.is_boost,
                    p.horsconcours, p.state AS pstate
               FROM snap_ap_timeline t
               JOIN pc_participants p ON p.actor_url = t.actor_url
                                     AND p.state = 'active'
              WHERE t.published >= :start AND t.published < :end
                AND t.is_boost = 0
           ORDER BY t.published ASC
              LIMIT " . min(1000, max($limit * 5, $limit))
        );
        $st->execute([
            ':start' => $win['start'],
            ':end'   => $win['end'],
        ]);
        $per_actor = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (($r['pstate'] ?? '') !== 'active') continue;
            $tags = json_decode((string)($r['tags_json'] ?? '[]'), true) ?: [];
            if (!in_array($tag, $tags, true)) continue;
            $media = json_decode((string)($r['media_json'] ?? '[]'), true) ?: [];
            if (count($media) !== 1 || !is_string($media[0]) || trim($media[0]) === '') continue;
            $actor = (string)($r['actor_url'] ?? '');
            $per_actor[$actor] = ($per_actor[$actor] ?? 0) + 1;
            if ($per_actor[$actor] > 5) continue;
            $rows[] = [
                'object_id'    => (string)($r['object_id'] ?? ''),   // AP object id (engagement key)
                'handle'       => (string)($r['actor_handle'] ?? ''),
                'actor_url'    => (string)($r['actor_url'] ?? ''),
                'url'          => (string)($r['url'] ?? ''),          // origin permalink (canonical)
                'thumb'        => (string)($media[0] ?? ''),          // hotlinked, origin-hosted
                'excerpt'      => mb_substr((string)($r['content'] ?? ''), 0, 240),
                'published'    => (string)($r['published'] ?? ''),
                'is_boost'     => ((int)($r['is_boost'] ?? 0)) === 1,
                'horsconcours' => ((int)($r['horsconcours'] ?? 0)) === 1
                                  || in_array('horsconcours', $tags, true),
            ];
        }
    } catch (Throwable $e) { /* fresh install: table may lag */ }
    usort($rows, static fn($a, $b) => strcmp((string)$b['published'], (string)$a['published']));
    return array_slice($rows, 0, $limit);
}

/**
 * Boost a newly ingested qualifying original exactly once.
 *
 * Inbound Announce activities never reach this function: only an original Note
 * ingested from an actor we follow is eligible. pc_board() supplies the complete
 * qualification policy (active participant, real hashtag, current window,
 * exactly one image, original-not-boost, first five for that author).
 */
function pc_maybe_boost_entry(PDO $pdo, array $settings, string $object_id): bool {
    if (!pc_enabled($settings)
        || ($settings['photochallenge_boost_enabled'] ?? '1') !== '1'
        || $object_id === ''
        || !function_exists('sv_boost_remote')) return false;

    $qualified = false;
    foreach (pc_board($pdo, $settings, null, 1000) as $entry) {
        if (($entry['object_id'] ?? '') === $object_id) {
            $qualified = true;
            break;
        }
    }
    if (!$qualified) return false;

    pc_ensure_tables($pdo);
    $actor_url = '';
    try {
        $actor = $pdo->prepare("SELECT actor_url FROM snap_ap_timeline WHERE object_id = ? LIMIT 1");
        $actor->execute([$object_id]);
        $actor_url = (string)($actor->fetchColumn() ?: '');
        $claim = $pdo->prepare(
            "INSERT IGNORE INTO pc_outbound_boosts (object_id, actor_url, state)
             VALUES (?, ?, 'pending')"
        );
        $claim->execute([$object_id, $actor_url]);
        if ($claim->rowCount() !== 1) return false;

        [$ok, $message] = sv_boost_remote($pdo, $settings, $object_id);
        if ($ok) {
            $pdo->prepare(
                "UPDATE pc_outbound_boosts
                    SET state='sent', boosted_at=NOW(), last_error=''
                  WHERE object_id=?"
            )->execute([$object_id]);
            return true;
        }
        // A transient remote failure must be retryable on a later delivery or
        // explicit retry job; do not permanently consume the unique claim.
        $pdo->prepare("DELETE FROM pc_outbound_boosts WHERE object_id=?")->execute([$object_id]);
        return false;
    } catch (Throwable $e) {
        try {
            $pdo->prepare("DELETE FROM pc_outbound_boosts WHERE object_id=? AND state='pending'")
                ->execute([$object_id]);
        } catch (Throwable $ignored) {}
        return false;
    }
}

/** Render the board as a self-contained teaser-card fragment (canonical -> origin). */
function pc_board_html(PDO $pdo, array $settings): string {
    $win  = pc_window($settings);
    $rows = pc_board($pdo, $settings, $win);
    $esc  = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $state = $win['open'] ? 'OPEN' : 'CLOSED';
    $out  = '<section class="pc-board" data-window="' . $esc($win['week_key']) . '">';
    $out .= '<header class="pc-board-head"><h2>PHOTO FRIDAY &mdash; ' . $esc($win['label'])
          . '</h2><span class="pc-state pc-' . strtolower($state) . '">' . $state . '</span></header>';
    if (!$rows) {
        $out .= '<p class="pc-empty">No entries yet. Post a photo with #' . $esc(pc_tag($settings))
              . ' and follow to join.</p></section>';
        return $out;
    }
    $out .= '<ul class="pc-grid">';
    foreach ($rows as $r) {
        $badge = $r['horsconcours'] ? '<span class="pc-hc" title="hors concours - shown, not ranked">hc</span>' : '';
        $thumb = $r['thumb'] !== ''
            ? '<img loading="lazy" src="' . $esc($r['thumb']) . '" alt="">'
            : '';
        /* SECAUDIT 047: scheme-guard federation URL — non-http(s) can never be a live href */
        $__u = (string)($r['url'] ?? ''); $__safe = preg_match('#^https?://#i', $__u) ? $__u : '';
        $out .= '<li class="pc-card">'
              . '<a href="' . ($__safe !== '' ? $esc($__safe) : '#') . '" rel="canonical noopener" target="_blank">'
              . $thumb
              . '<span class="pc-by">' . $esc($r['handle']) . $badge . '</span>'
              . '</a></li>';
    }
    $out .= '</ul></section>';
    return $out;
}

/** Hall of Fame: the retained text-list of LINKS to winning posts (no images). */
function pc_hof_list(PDO $pdo, int $limit = 100): array {
    try {
        $st = $pdo->prepare(
            "SELECT id, week_key, place, handle, post_url, caption, active
               FROM pc_hall_of_fame
           ORDER BY week_key DESC, place ASC LIMIT {$limit}"
        );
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/** Record a Hall-of-Fame winner (idempotent per week+place). */
function pc_hof_record(PDO $pdo, string $week_key, int $place, string $actor_url,
                       string $handle, string $post_url, string $caption = ''): void {
    pc_ensure_tables($pdo);
    $pdo->prepare(
        "INSERT INTO pc_hall_of_fame (week_key, place, actor_url, handle, post_url, caption)
         VALUES (?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE actor_url=VALUES(actor_url), handle=VALUES(handle),
                                 post_url=VALUES(post_url), caption=VALUES(caption), active=1"
    )->execute([$week_key, $place, $actor_url, substr($handle, 0, 190),
                substr($post_url, 0, 600), substr($caption, 0, 500)]);
}

/* ─────────────────────────────────────────────────────────────────────────
 * RANKING LAYER (build queue §4)
 *
 * The board is chronological until engagement data exists; then it ranks by a
 * composite score. The score's inputs come from pc_engagement, a durable table
 * the inbox writes to via pc_record_like / pc_record_boost. Everything here
 * DEGRADES SAFELY: no engagement rows → every score is 0 → order collapses back
 * to newest-first, exactly like the un-ranked board. So this is inert-correct on
 * a fresh install and only starts sorting once real likes/boosts land.
 * ───────────────────────────────────────────────────────────────────────── */

/** Record a like on an entry (idempotent per actor). Inert unless enabled. */
function pc_record_like(PDO $pdo, array $settings, string $object_id, string $actor_url): void {
    if (!pc_enabled($settings) || $object_id === '' || $actor_url === '') return;
    try {
        $pdo->prepare(
            "INSERT IGNORE INTO pc_engagement (object_id, actor_url, kind)
             VALUES (?, ?, 'like')"
        )->execute([$object_id, $actor_url]);
    } catch (Throwable $e) { /* table may lag on a fresh install */ }
}

/** Record a boost of an entry (idempotent per actor). Inert unless enabled. */
function pc_record_boost(PDO $pdo, array $settings, string $object_id, string $actor_url): void {
    if (!pc_enabled($settings) || $object_id === '' || $actor_url === '') return;
    try {
        $pdo->prepare(
            "INSERT IGNORE INTO pc_engagement (object_id, actor_url, kind)
             VALUES (?, ?, 'boost')"
        )->execute([$object_id, $actor_url]);
    } catch (Throwable $e) { /* table may lag on a fresh install */ }
}

/** Retract a previously observed Like/Announce engagement. */
function pc_remove_engagement(PDO $pdo, array $settings, string $object_id, string $actor_url, string $kind): void {
    if (!pc_enabled($settings) || $object_id === '' || $actor_url === ''
        || !in_array($kind, ['like', 'boost'], true)) return;
    try {
        $pdo->prepare("DELETE FROM pc_engagement WHERE object_id = ? AND actor_url = ? AND kind = ?")
            ->execute([$object_id, $actor_url, $kind]);
    } catch (Throwable $e) {}
}

/** Only tally engagement for an active participant entry held in the timeline. */
function pc_has_entry(PDO $pdo, array $settings, string $object_id): bool {
    if (!pc_enabled($settings) || $object_id === '') return false;
    try {
        $st = $pdo->prepare(
            "SELECT 1 FROM snap_ap_timeline t
               JOIN pc_participants p ON p.actor_url = t.actor_url AND p.state = 'active'
              WHERE t.object_id = ? LIMIT 1"
        );
        $st->execute([$object_id]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/** The weight a boost carries relative to a like when scoring (setting, default 1). */
function pc_boost_weight(array $settings): int {
    $w = (int)($settings['photochallenge_boost_weight'] ?? 1);
    return $w < 0 ? 0 : $w;
}

/**
 * Like/boost tallies for a set of entry object_ids, in ONE query.
 * @return array<string,array{likes:int,boosts:int,score:int}>
 */
function pc_score_map(PDO $pdo, array $settings, array $object_ids): array {
    $ids = array_values(array_unique(array_filter(array_map('strval', $object_ids), 'strlen')));
    if (!$ids) return [];
    $weight = pc_boost_weight($settings);
    $map = [];
    try {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare(
            "SELECT object_id,
                    SUM(kind = 'like')  AS likes,
                    SUM(kind = 'boost') AS boosts
               FROM pc_engagement
              WHERE object_id IN ($ph)
           GROUP BY object_id"
        );
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $likes  = (int)($r['likes'] ?? 0);
            $boosts = (int)($r['boosts'] ?? 0);
            $map[(string)$r['object_id']] = [
                'likes'  => $likes,
                'boosts' => $boosts,
                'score'  => $likes + $boosts * $weight,
            ];
        }
    } catch (Throwable $e) { /* fresh install: no engagement yet */ }
    return $map;
}

/**
 * The board, ranked. Same rows as pc_board() plus {likes,boosts,score,rank}.
 * Non-hors-concours entries are ranked by score (desc), earliest-posted breaking
 * ties; hors-concours entries are shown but carry rank 0 and sort to the end.
 * With no engagement data every score is 0, so this is just the chronological
 * board with rank labels — safe on day one.
 *
 * @return array<int,array<string,mixed>>
 */
function pc_board_ranked(PDO $pdo, array $settings, ?array $window = null, int $limit = 200): array {
    $rows = pc_board($pdo, $settings, $window, $limit);
    if (!$rows) return [];
    // Engagement keys on the AP object_id — that is the object ref a remote
    // Like/Announce carries and what pc_record_like / pc_record_boost store.
    $scores = pc_score_map($pdo, $settings, array_column($rows, 'object_id'));
    foreach ($rows as &$r) {
        $s = $scores[$r['object_id']] ?? ['likes' => 0, 'boosts' => 0, 'score' => 0];
        $r['likes']  = $s['likes'];
        $r['boosts'] = $s['boosts'];
        $r['score']  = $s['score'];
        $r['rank']   = 0;
    }
    unset($r);

    // Split, sort competitors, keep hors-concours in chronological tail.
    $comp = array_values(array_filter($rows, static fn($r) => !$r['horsconcours'] && !$r['is_boost']));
    $tail = array_values(array_filter($rows, static fn($r) =>  $r['horsconcours'] ||  $r['is_boost']));
    usort($comp, static function ($a, $b) {
        if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
        return strcmp((string)$a['published'], (string)$b['published']); // earliest first on a tie
    });
    $i = 0;
    foreach ($comp as &$r) { $r['rank'] = ++$i; }
    unset($r);
    return array_merge($comp, $tail);
}

/**
 * The winners for a given window: top $n competitors by score. Excludes boosts,
 * hors-concours, blocked actors and entries with no image. Returns pc_board rows
 * (ranked) — the caller records them to the Hall of Fame. Returns [] when the
 * round produced no rankable entry (nothing crowned rather than a false winner).
 *
 * @return array<int,array<string,mixed>>
 */
function pc_pick_winners(PDO $pdo, array $settings, ?array $window = null, int $n = 3): array {
    $ranked = pc_board_ranked($pdo, $settings, $window, 500);
    $winners = [];
    foreach ($ranked as $r) {
        if ($r['rank'] < 1) continue;               // hors-concours / boost tail
        if (($r['thumb'] ?? '') === '') continue;   // a winner must be a photo
        $winners[] = $r;
        if (count($winners) >= $n) break;
    }
    return $winners;
}

/**
 * Crown a window: pick the top $n and write them to the Hall of Fame. Idempotent
 * per (week_key, place) — re-running re-crowns the same round cleanly. Returns
 * the number of places recorded.
 */
function pc_finalize_week(PDO $pdo, array $settings, ?array $window = null, int $n = 3): int {
    if (!pc_enabled($settings)) return 0;
    $win = $window ?? pc_window($settings);
    $winners = pc_pick_winners($pdo, $settings, $win, $n);
    $place = 0;
    foreach ($winners as $w) {
        $place++;
        pc_hof_record(
            $pdo, (string)$win['week_key'], $place,
            (string)$w['actor_url'], (string)$w['handle'],
            (string)$w['url'], (string)$w['excerpt']
        );
    }
    return $place;
}

/** Deactivate / reactivate a Hall-of-Fame row (dead-link handling, §6). */
function pc_hof_set_active(PDO $pdo, int $id, bool $active): void {
    try {
        $pdo->prepare("UPDATE pc_hall_of_fame SET active = ? WHERE id = ?")
            ->execute([$active ? 1 : 0, $id]);
    } catch (Throwable $e) {}
}

/** Participant roster for the admin surface (counts + rows). */
function pc_participants(PDO $pdo, int $limit = 500): array {
    try {
        $st = $pdo->prepare(
            "SELECT actor_url, handle, joined_at, horsconcours, state
               FROM pc_participants
           ORDER BY joined_at DESC LIMIT {$limit}"
        );
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/** Quick roster counts by state, for the admin dashboard. */
function pc_participant_counts(PDO $pdo): array {
    $out = ['active' => 0, 'left' => 0, 'blocked' => 0, 'total' => 0];
    try {
        $st = $pdo->query("SELECT state, COUNT(*) c FROM pc_participants GROUP BY state");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['state']] = (int)$r['c'];
            $out['total'] += (int)$r['c'];
        }
    } catch (Throwable $e) {}
    return $out;
}

} // function_exists guard
// ===== SNAPSMACK EOF =====
