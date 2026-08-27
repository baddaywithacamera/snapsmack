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

/** Is the optional public challenge feed/board exposed? */
function pc_feed_enabled(array $settings): bool {
    if (array_key_exists('photochallenge_feed_enabled', $settings)) {
        return (string)$settings['photochallenge_feed_enabled'] === '1';
    }
    // Upgrade compatibility: /board existed whenever the challenge was on
    // before this separate switch shipped. Preserve that surface until the
    // operator explicitly saves ON or OFF.
    return pc_enabled($settings);
}

/**
 * Keep Menu Manager's built-in FEED item in lockstep with the challenge switch.
 * Existing menu order is preserved; first enable appends FEED for later dragging.
 */
function pc_sync_feed_menu(PDO $pdo, array &$settings, bool $enabled): void {
    $items = json_decode((string)($settings['nav_menu_json'] ?? '[]'), true);
    if (!is_array($items) || !$items) return; // legacy nav renders from the setting directly

    $contains = static function (array $list) use (&$contains): bool {
        foreach ($list as $item) {
            if (!is_array($item)) continue;
            if (($item['type'] ?? '') === 'challenge_feed' || ($item['id'] ?? '') === 'challenge_feed') return true;
            if (!empty($item['children']) && is_array($item['children']) && $contains($item['children'])) return true;
        }
        return false;
    };
    $remove = static function (array $list) use (&$remove): array {
        $out = [];
        foreach ($list as $item) {
            if (!is_array($item)) continue;
            if (($item['type'] ?? '') === 'challenge_feed' || ($item['id'] ?? '') === 'challenge_feed') continue;
            if (!empty($item['children']) && is_array($item['children'])) $item['children'] = $remove($item['children']);
            $out[] = $item;
        }
        return $out;
    };

    if ($enabled) {
        if ($contains($items)) return;
        $items[] = ['id'=>'challenge_feed','type'=>'challenge_feed','label'=>'FEED','children'=>[]];
    } else {
        $items = $remove($items);
    }
    $json = json_encode($items, JSON_UNESCAPED_SLASHES);
    $pdo->prepare("INSERT INTO snap_settings(setting_key,setting_val) VALUES('nav_menu_json',?)
        ON DUPLICATE KEY UPDATE setting_val=VALUES(setting_val)")->execute([$json]);
    $settings['nav_menu_json'] = $json;
}

/** The challenge hashtag, normalised (lowercase, no leading '#'). */
function pc_tag(array $settings): string {
    $t = strtolower(trim((string)($settings['photochallenge_tag'] ?? 'photofri')));
    $t = preg_replace('/[^a-z0-9_]/', '', ltrim($t, '#')) ?? '';
    return $t !== '' ? $t : 'photofri';
}

/**
 * Testing gate. When photochallenge_test_mode is ON, only entries whose author
 * appears on the whitelist are admitted (and therefore boosted and scored). This
 * lets a live site be exercised end to end without touching real participants and
 * without standing up a second install. OFF (the default) = everyone qualifies as
 * normal. Matching is deliberately forgiving: a whitelist line matches on the full
 * user@host handle, the bare username, the domain, or any substring of the actor URL.
 */
function pc_test_allowed(array $settings, string $actor_url, string $handle = ''): bool {
    if ((string)($settings['photochallenge_test_mode'] ?? '0') !== '1') return true;
    $norm = static fn($s) => ltrim(strtolower(trim((string)$s)), '@');
    $list = array_values(array_filter(array_map($norm, preg_split('/[\s,]+/', (string)($settings['photochallenge_test_allow'] ?? '')) ?: [])));
    if (!$list) return false;   // test mode on, nobody listed = admit nobody
    $host = strtolower((string)parse_url($actor_url, PHP_URL_HOST));
    $bare = $norm($handle);
    $full = ($bare !== '' && $host !== '') ? $bare . '@' . $host : '';
    $url  = strtolower($actor_url);
    foreach ($list as $entry) {
        if ($entry === $full || ($bare !== '' && $entry === $bare) || ($host !== '' && $entry === $host)) return true;
        if (strpos($url, $entry) !== false) return true;
    }
    return false;
}

/** Display timezone retained for admin copy; qualification uses the shared global UTC window. */
function pc_tz(array $settings): DateTimeZone {
    $tz = trim((string)($settings['photochallenge_tz'] ?? ''));
    try { return $tz !== '' ? new DateTimeZone($tz) : new DateTimeZone(date_default_timezone_get()); }
    catch (Throwable $e) { return new DateTimeZone('UTC'); }
}

/**
 * The shared 50-hour global Photo-Friday window for a given moment.
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

/**
 * Turn a plain-language prompt into its hashtag pair. One word is the norm
 * ("Belonging"); multiple words CamelCase ("Golden Hour" -> GoldenHour).
 *
 *   pc_hashtag_from_prompt('Belonging')
 *     => ['tag' => 'photofribelonging', 'display' => 'PhotoFriBelonging']
 *
 * 'tag' is the normalised form pc_tag()/qualification compares (lowercase,
 * [a-z0-9_]); 'display' is what the card and copy show. The prefix is the
 * per-install brand (PhotoFri, ArtFri…) from photochallenge_tag_prefix.
 */
function pc_hashtag_from_prompt(string $prompt, string $prefix = 'PhotoFri'): array {
    $prefix = preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?: 'PhotoFri';
    $words  = preg_split('/[^A-Za-z0-9]+/', trim($prompt), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $camel  = '';
    foreach ($words as $w) { $camel .= ucfirst(strtolower($w)); }
    $display = $prefix . $camel;
    return ['tag' => strtolower($display), 'display' => $display];
}

/** The brand prefix used to build a prompt's hashtag (PhotoFri, ArtFri…). */
function pc_tag_prefix(array $settings): string {
    $p = preg_replace('/[^A-Za-z0-9]/', '', (string)($settings['photochallenge_tag_prefix'] ?? 'PhotoFri'));
    return $p !== '' ? $p : 'PhotoFri';
}

/**
 * The 50-hour submission window for a specific Photo-Friday (any date in that
 * week is snapped to its Friday). Mirrors pc_window()'s weekly math exactly:
 * opens Thursday 10:00 UTC (Friday 00:00 minus 14h), closes Saturday 12:00 UTC.
 *
 * @return array{friday:string,start:string,end:string,week_key:string,label:string}|null
 *         All UTC. null if the date is unparseable.
 */
function pc_window_for_friday(string $any_date_in_week): ?array {
    try {
        $d = new DateTimeImmutable($any_date_in_week . ' 00:00:00', new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return null;
    }
    $dow = (int)$d->format('N');                 // 1=Mon … 7=Sun; 5 = Friday
    $fri = $d->modify(($dow <= 5 ? '+' : '-') . abs(5 - $dow) . ' days'); // snap to this week's Friday
    $start = $fri->modify('-14 hours');          // Thu 10:00 UTC (Friday 00:00 minus 14h)
    $end   = $start->modify('+50 hours');        // Sat 12:00 UTC
    return [
        'friday'   => $fri->format('Y-m-d'),
        'start'    => $start->format('Y-m-d H:i:s'),
        'end'      => $end->format('Y-m-d H:i:s'),
        'week_key' => $fri->format('o-\WW'),
        'label'    => $fri->format('M j, Y'),
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
            object_id   varchar(500) DEFAULT NULL,
            handle      varchar(190) NOT NULL DEFAULT '',
            post_url    varchar(600) NOT NULL,
            caption     varchar(500) NOT NULL DEFAULT '',
            captured_at datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            active      tinyint(1)   NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uq_week_place (week_key, place)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    try {
        $has_hof_object = (bool)$pdo->query("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pc_hall_of_fame' AND COLUMN_NAME='object_id' LIMIT 1")->fetchColumn();
        if (!$has_hof_object) $pdo->exec("ALTER TABLE pc_hall_of_fame ADD COLUMN object_id varchar(500) DEFAULT NULL AFTER actor_url");
    } catch (Throwable $e) {}
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
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS pc_admissions (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            week_key varchar(12) NOT NULL, actor_url varchar(500) NOT NULL,
            object_id varchar(500) NOT NULL, admission_number tinyint unsigned NOT NULL,
            status enum('active','withdrawn','deleted','moderated') NOT NULL DEFAULT 'active',
            horsconcours tinyint(1) NOT NULL DEFAULT 0,
            boost_state enum('pending','sent','undone','failed','test') NOT NULL DEFAULT 'pending',
            boost_activity_id varchar(600) DEFAULT NULL,
            admitted_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_checked_at datetime DEFAULT NULL, check_failures tinyint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY(id), UNIQUE KEY uq_pc_admission_object(object_id(191)),
            UNIQUE KEY uq_pc_admission_slot(week_key,actor_url(170),admission_number),
            UNIQUE KEY uq_pc_boost_activity(boost_activity_id(191)),
            KEY idx_pc_admission_board(week_key,status,admitted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS pc_rounds (
            week_key varchar(12) NOT NULL, window_start datetime NOT NULL, window_end datetime NOT NULL,
            finalized_at datetime DEFAULT NULL, PRIMARY KEY(week_key),
            KEY idx_pc_round_finalize(finalized_at,window_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS pc_window_notices (
            actor_url varchar(500) NOT NULL, week_key varchar(12) NOT NULL,
            object_id varchar(500) NOT NULL, state enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
            attempted_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, last_error varchar(500) NOT NULL DEFAULT '',
            UNIQUE KEY uq_pc_window_notice(actor_url(170),week_key),
            KEY idx_pc_window_notice_state(state,attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    try {
        $has_boost_id = (bool)$pdo->query("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pc_admissions' AND COLUMN_NAME='boost_activity_id' LIMIT 1")->fetchColumn();
        if (!$has_boost_id) $pdo->exec("ALTER TABLE pc_admissions ADD COLUMN boost_activity_id varchar(600) DEFAULT NULL AFTER boost_state, ADD UNIQUE KEY uq_pc_boost_activity(boost_activity_id(191))");
        $boost_type = (string)$pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pc_admissions' AND COLUMN_NAME='boost_state' LIMIT 1")->fetchColumn();
        if ($boost_type !== '' && strpos($boost_type, "'test'") === false) {
            $pdo->exec("ALTER TABLE pc_admissions MODIFY boost_state enum('pending','sent','undone','failed','test') NOT NULL DEFAULT 'pending'");
        }
    } catch (Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS pc_blocklist (
        kind enum('actor','domain') NOT NULL,value varchar(500) NOT NULL,reason varchar(255) DEFAULT NULL,
        blocked_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_pc_block(kind,value(190))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // One scheduled prompt per Photo-Friday: the word, its hashtag, the 50-hour
    // submission window it governs, and the card post queued to drop. The cron
    // (pc_activate_due_prompts) publishes the card and switches the live tag when
    // drop_at arrives. week_key ties a prompt to its pc_rounds / pc_admissions.
    $pdo->exec("CREATE TABLE IF NOT EXISTS pc_prompts (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        week_key     varchar(12)  NOT NULL,
        friday       date         NOT NULL,
        submit_start datetime     NOT NULL,
        submit_end   datetime     NOT NULL,
        prompt       varchar(120) NOT NULL,
        tag          varchar(120) NOT NULL,
        tag_display  varchar(120) NOT NULL DEFAULT '',
        drop_at      datetime     NOT NULL,
        post_id      bigint unsigned DEFAULT NULL,
        image_id     bigint unsigned DEFAULT NULL,
        status       enum('queued','live','done','canceled') NOT NULL DEFAULT 'queued',
        dropped_at   datetime     DEFAULT NULL,
        created_at   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(id),
        UNIQUE KEY uq_pc_prompt_week(week_key),
        KEY idx_pc_prompt_due(status, drop_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function pc_is_blocked(PDO $pdo, string $actor_url): bool {
    $domain = strtolower((string)(parse_url($actor_url,PHP_URL_HOST) ?: ''));
    $q = $pdo->prepare("SELECT 1 FROM pc_blocklist WHERE (kind='actor' AND value=?) OR (kind='domain' AND value=?) LIMIT 1");
    $q->execute([$actor_url,$domain]);
    return (bool)$q->fetchColumn();
}

function pc_block(PDO $pdo, array $settings, string $kind, string $value, string $reason = ''): void {
    if (!in_array($kind,['actor','domain'],true)) return;
    $value = $kind === 'domain' ? strtolower(trim($value)) : trim($value);
    if ($value === '') return;
    $pdo->prepare("INSERT INTO pc_blocklist(kind,value,reason) VALUES(?,?,?)
        ON DUPLICATE KEY UPDATE reason=VALUES(reason),blocked_at=NOW()")
        ->execute([$kind,$value,substr($reason,0,255)]);
    if ($kind === 'actor') pc_set_participant_state($pdo,$settings,$value,'blocked');
    else {
        $q = $pdo->prepare("SELECT actor_url FROM pc_participants WHERE LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(actor_url,'/',3),'/',-1))=?");
        $q->execute([$value]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $actor) pc_set_participant_state($pdo,$settings,(string)$actor,'blocked');
    }
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
    if (pc_is_blocked($pdo,$actor_id)) return;

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
        pc_withdraw_actor_admissions($pdo, $settings, $actor_url, 'withdrawn');
        if (function_exists('sv_unfollow_actor')) {
            $f = $pdo->prepare("SELECT id FROM snap_ap_following WHERE actor_url=? LIMIT 1");
            $f->execute([$actor_url]); $fid = (int)$f->fetchColumn();
            if ($fid > 0) sv_unfollow_actor($pdo, $settings, $fid);
        }
    } catch (Throwable $e) {}
}

function pc_set_participant_state(PDO $pdo, array $settings, string $actor_url, string $state): void {
    if (!in_array($state, ['active','blocked','left'], true) || $actor_url === '') return;
    $pdo->prepare("UPDATE pc_participants SET state=? WHERE actor_url=?")->execute([$state,$actor_url]);
    if ($state !== 'active') pc_withdraw_actor_admissions($pdo,$settings,$actor_url,$state === 'blocked' ? 'moderated' : 'withdrawn');
}

/** Set/clear a participant's #horsconcours opt-out (show on board, never ranked). */
function pc_set_horsconcours(PDO $pdo, string $actor_url, bool $on): void {
    try {
        $pdo->prepare("UPDATE pc_participants SET horsconcours = ? WHERE actor_url = ?")
            ->execute([$on ? 1 : 0, $actor_url]);
    } catch (Throwable $e) {}
}

/**
 * Privately tell an opted-in participant that a plausible entry was published
 * after the last round closed. One durable reservation per actor/upcoming round
 * prevents duplicate DMs when the same object arrives through multiple paths.
 */
function pc_notice_closed_window(PDO $pdo, array $settings, array $row, array $win): void {
    if (!function_exists('sv_send_dm')) return;
    $published = (string)($row['published'] ?? '');
    if ($published === '' || $published < (string)$win['end']) return;

    $next_start = (new DateTimeImmutable((string)$win['start'], new DateTimeZone('UTC')))->modify('+7 days');
    $next_end = $next_start->modify('+50 hours');
    $week_key = $next_start->modify('+14 hours')->format('o-\\WW');
    $actor = (string)($row['actor_url'] ?? '');
    $object_id = (string)($row['object_id'] ?? '');
    if ($actor === '' || $object_id === '') return;

    try {
        $reserve = $pdo->prepare("INSERT IGNORE INTO pc_window_notices(actor_url,week_key,object_id) VALUES(?,?,?)");
        $reserve->execute([$actor,$week_key,$object_id]);
        if ($reserve->rowCount() !== 1) return;

        $tag = '#' . pc_tag($settings);
        $body = "This Photo Friday round isn't open, so your post wasn't entered or boosted. "
              . "Please post again while the round is open: "
              . $next_start->format('Thursday, M j \\a\\t H:i') . " UTC through "
              . $next_end->format('Saturday, M j \\a\\t H:i') . " UTC. Use {$tag}.";
        [$ok,$message] = sv_send_dm($pdo,$settings,$actor,$body);
        $pdo->prepare("UPDATE pc_window_notices SET state=?,last_error=? WHERE actor_url=? AND week_key=?")
            ->execute([$ok ? 'sent' : 'failed',$ok ? '' : substr((string)$message,0,500),$actor,$week_key]);
    } catch (Throwable $e) {
        try {
            $pdo->prepare("UPDATE pc_window_notices SET state='failed',last_error=? WHERE actor_url=? AND week_key=?")
                ->execute([substr($e->getMessage(),0,500),$actor,$week_key]);
        } catch (Throwable $ignored) {}
    }
}

/** Allocate one immutable weekly slot under the participant-row lock. */
function pc_admit_object(PDO $pdo, array $settings, string $object_id): ?array {
    $win = pc_window($settings);
    $tag = pc_tag($settings);
    $q = $pdo->prepare(
        "SELECT t.*,p.state AS participant_state,p.horsconcours AS participant_hc,p.handle AS participant_handle
           FROM snap_ap_timeline t JOIN pc_participants p ON p.actor_url=t.actor_url
          WHERE t.object_id=? LIMIT 1"
    );
    $q->execute([$object_id]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row || ($row['participant_state'] ?? '') !== 'active' || (int)$row['is_boost'] !== 0
        || pc_is_blocked($pdo,(string)($row['actor_url'] ?? ''))
        || !empty($row['in_reply_to']) || (int)($row['sensitive'] ?? 0) !== 0) return null;
    if (!pc_test_allowed($settings, (string)($row['actor_url'] ?? ''), (string)($row['participant_handle'] ?? ''))) return null;
    $published = (string)($row['published'] ?? '');
    $tags = json_decode((string)($row['tags_json'] ?? '[]'), true) ?: [];
    if (!in_array($tag, $tags, true)) return null;
    $media = json_decode((string)($row['media_json'] ?? '[]'), true) ?: [];
    $videos = json_decode((string)($row['media_video_json'] ?? '[]'), true) ?: [];
    if (count($media) !== 1 || $videos || !is_string($media[0]) || trim($media[0]) === '') return null;
    if (!$win['open']) {
        pc_notice_closed_window($pdo,$settings,$row,$win);
        return null;
    }
    if ($published < $win['start'] || $published >= $win['end']) return null;
    $actor = (string)$row['actor_url'];
    $hc = ((int)($row['participant_hc'] ?? 0) === 1 || in_array('horsconcours', $tags, true)) ? 1 : 0;

    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare("SELECT state FROM pc_participants WHERE actor_url=? FOR UPDATE");
        $lock->execute([$actor]);
        if ($lock->fetchColumn() !== 'active') { $pdo->rollBack(); return null; }
        $oldq = $pdo->prepare("SELECT * FROM pc_admissions WHERE object_id=? LIMIT 1 FOR UPDATE");
        $oldq->execute([$object_id]);
        $old = $oldq->fetch(PDO::FETCH_ASSOC);
        if ($old) {
            if (($old['week_key'] ?? '') === $win['week_key'] && ($old['status'] ?? '') === 'withdrawn') {
                $pdo->prepare("UPDATE pc_admissions SET status='active',horsconcours=?,boost_state=IF(boost_state='undone','pending',boost_state) WHERE id=?")
                    ->execute([$hc, $old['id']]);
                $old['status'] = 'active'; $old['horsconcours'] = $hc;
            }
            $pdo->commit(); return $old;
        }
        $countq = $pdo->prepare("SELECT COALESCE(MAX(admission_number),0) FROM pc_admissions WHERE week_key=? AND actor_url=? FOR UPDATE");
        $countq->execute([$win['week_key'], $actor]);
        $slot = (int)$countq->fetchColumn() + 1;
        if ($slot > 5) { $pdo->commit(); return null; }
        $pdo->prepare("INSERT INTO pc_admissions
            (week_key,actor_url,object_id,admission_number,horsconcours,boost_state)
            VALUES (?,?,?,?,?,'pending')")->execute([$win['week_key'],$actor,$object_id,$slot,$hc]);
        $pdo->prepare("INSERT INTO pc_rounds(week_key,window_start,window_end) VALUES(?,?,?)
            ON DUPLICATE KEY UPDATE window_start=VALUES(window_start),window_end=VALUES(window_end)")
            ->execute([$win['week_key'],$win['start'],$win['end']]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        return ['id'=>$id,'week_key'=>$win['week_key'],'actor_url'=>$actor,'object_id'=>$object_id,
            'admission_number'=>$slot,'status'=>'active','horsconcours'=>$hc,'boost_state'=>'pending'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Withdraw current admissions without freeing their immutable slot. */
function pc_withdraw_actor_admissions(PDO $pdo, array $settings, string $actor_url, string $status = 'withdrawn'): void {
    if (!in_array($status, ['withdrawn','moderated'], true)) $status = 'withdrawn';
    $q = $pdo->prepare("SELECT object_id,boost_state,boost_activity_id FROM pc_admissions WHERE actor_url=? AND status='active'");
    $q->execute([$actor_url]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $entry) {
        if (in_array(($entry['boost_state'] ?? ''), ['sent','test'], true) && function_exists('sv_unboost_remote')) {
            try { sv_unboost_remote($pdo,$settings,(string)$entry['object_id'],(string)($entry['boost_activity_id'] ?? '')); } catch (Throwable $e) {}
        }
        $pdo->prepare("UPDATE pc_admissions SET status=?,boost_state=IF(boost_state IN('sent','test'),'undone',boost_state) WHERE object_id=?")
            ->execute([$status, $entry['object_id']]);
    }
}

function pc_withdraw_object(PDO $pdo, array $settings, string $object_id, string $status = 'withdrawn'): void {
    if (!in_array($status, ['withdrawn','deleted','moderated'], true)) $status = 'withdrawn';
    $q = $pdo->prepare("SELECT boost_state,boost_activity_id FROM pc_admissions WHERE object_id=? AND status='active' LIMIT 1");
    $q->execute([$object_id]);
    $boost = $q->fetch(PDO::FETCH_ASSOC);
    if (!$boost) return;
    if (in_array(($boost['boost_state'] ?? ''), ['sent','test'], true) && function_exists('sv_unboost_remote')) {
        try { sv_unboost_remote($pdo,$settings,$object_id,(string)($boost['boost_activity_id'] ?? '')); } catch (Throwable $e) {}
    }
    $pdo->prepare("UPDATE pc_admissions SET status=?,boost_state=IF(boost_state IN('sent','test'),'undone',boost_state) WHERE object_id=?")
        ->execute([$status, $object_id]);
    if (in_array($status, ['deleted','moderated'], true)) {
        $pdo->prepare("UPDATE pc_hall_of_fame SET active=0 WHERE object_id=?")->execute([$object_id]);
    }
}

/** Apply Update/tag/CW lifecycle without reallocating the consumed slot. */
function pc_reconcile_object(PDO $pdo, array $settings, string $object_id): void {
    if (!pc_enabled($settings) || $object_id === '') return;
    pc_ensure_tables($pdo);
    $q = $pdo->prepare("SELECT t.*,a.id admission_id,a.status admission_status,a.week_key
        FROM snap_ap_timeline t LEFT JOIN pc_admissions a ON a.object_id=t.object_id
        WHERE t.object_id=? LIMIT 1");
    $q->execute([$object_id]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;
    $tags = json_decode((string)($row['tags_json'] ?? '[]'), true) ?: [];
    $media = json_decode((string)($row['media_json'] ?? '[]'), true) ?: [];
    $videos = json_decode((string)($row['media_video_json'] ?? '[]'), true) ?: [];
    $valid = in_array(pc_tag($settings), $tags, true) && count($media) === 1 && !$videos
        && empty($row['in_reply_to']) && (int)($row['sensitive'] ?? 0) === 0 && (int)$row['is_boost'] === 0;
    if (!$row['admission_id']) {
        if ($valid) pc_maybe_boost_entry($pdo, $settings, $object_id);
        return;
    }
    if (!$valid) { pc_withdraw_object($pdo, $settings, $object_id, (int)($row['sensitive'] ?? 0) ? 'moderated' : 'withdrawn'); return; }
    $round = $pdo->prepare("SELECT finalized_at FROM pc_rounds WHERE week_key=? LIMIT 1");
    $round->execute([$row['week_key']]);
    $finalized = (string)($round->fetchColumn() ?: '');
    $hc = in_array('horsconcours', $tags, true) ? 1 : 0;
    if ($hc || $finalized === '') {
        $pdo->prepare("UPDATE pc_admissions SET horsconcours=? WHERE id=?")->execute([$hc, $row['admission_id']]);
    }
    if (($row['admission_status'] ?? '') === 'withdrawn' && $finalized === '') {
        $win = pc_window($settings);
        if ($win['open'] && $win['week_key'] === $row['week_key']) pc_maybe_boost_entry($pdo, $settings, $object_id);
    }
}

/** Garden pointers, retry boosts, and finalize ended rounds. */
function pc_cron_maintain(PDO $pdo, array &$settings, int $limit = 25): array {
    if (!pc_enabled($settings)) return [0,0,0];
    pc_ensure_tables($pdo);
    pc_activate_due_prompts($pdo, $settings);   // drop any scheduled prompt whose time has come
    $finalized = 0; $checked = 0; $withdrawn = 0;
    $rounds = $pdo->query("SELECT * FROM pc_rounds WHERE finalized_at IS NULL AND window_end<=NOW() ORDER BY window_end LIMIT 4")
        ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rounds as $round) {
        $win = ['start'=>$round['window_start'],'end'=>$round['window_end'],'open'=>false,
            'week_key'=>$round['week_key'],'label'=>$round['week_key']];
        pc_finalize_week($pdo, $settings, $win, 3);
        $pdo->prepare("UPDATE pc_rounds SET finalized_at=NOW() WHERE week_key=? AND finalized_at IS NULL")
            ->execute([$round['week_key']]);
        $finalized++;
    }
    $limit = max(1, min(100, $limit));
    $rows = $pdo->query("SELECT * FROM pc_admissions WHERE status='active'
        ORDER BY COALESCE(last_checked_at,'1970-01-01'),id LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $admission) {
        $object = function_exists('sv_fetch_ap') ? sv_fetch_ap((string)$admission['object_id'], $settings) : null;
        if (!is_array($object)) {
            $failures = (int)$admission['check_failures'] + 1;
            $pdo->prepare("UPDATE pc_admissions SET last_checked_at=NOW(),check_failures=? WHERE id=?")
                ->execute([$failures,$admission['id']]);
            if ($failures >= 3) { pc_withdraw_object($pdo,$settings,(string)$admission['object_id'],'deleted'); $withdrawn++; }
            $checked++; continue;
        }
        $attr = is_array($object['attributedTo'] ?? null) ? (string)($object['attributedTo']['id'] ?? '') : (string)($object['attributedTo'] ?? '');
        if ($attr !== $admission['actor_url']) { pc_withdraw_object($pdo,$settings,(string)$admission['object_id'],'moderated'); $withdrawn++; $checked++; continue; }
        sv_ingest_timeline($pdo,$object,$attr,'');
        $pdo->prepare("UPDATE pc_admissions SET last_checked_at=NOW(),check_failures=0 WHERE id=?")->execute([$admission['id']]);
        pc_reconcile_object($pdo,$settings,(string)$admission['object_id']);
        if (($admission['boost_state'] ?? '') === 'failed') pc_maybe_boost_entry($pdo,$settings,(string)$admission['object_id']);
        $checked++;
    }
    return [$finalized,$checked,$withdrawn];
}

/* =========================================================================
 * SCHEDULE A PROMPT
 * A thin scheduler over the same window math. Sean enters a one-word prompt,
 * picks the Photo-Friday, and uploads the card. The tool derives the hashtag
 * (#PhotoFri<Word>), files the card as a hidden draft, and — when drop_at
 * arrives — the cron publishes the card (which federates via the delivery
 * worker), switches the live qualifying hashtag to that week's tag, and moves
 * the "next prompt" pointers forward. No second poster, no bespoke federation:
 * it reuses snap_ingest_image, the post-model plug, and sv_kick_delivery.
 * ========================================================================= */

/**
 * Queue a prompt for a Photo-Friday. Ingests the card image as a DRAFT post,
 * generates the hashtag, and files it against that week's 50-hour window. The
 * card stays hidden until pc_activate_due_prompts() drops it at drop_at.
 *
 * $data: prompt (word), friday (Y-m-d), drop_at (optional datetime-local/SQL,
 *        default = window open), alt (optional card ALT).
 * $file: one $_FILES entry (the uploaded card).
 *
 * @return array{ok:bool,msg:string,id?:int}
 */
function pc_queue_prompt(PDO $pdo, array &$settings, array $data, array $file): array {
    pc_ensure_tables($pdo);
    $prompt = trim((string)($data['prompt'] ?? ''));
    if ($prompt === '') return ['ok' => false, 'msg' => 'Enter a prompt word.'];
    $win = pc_window_for_friday((string)($data['friday'] ?? ''));
    if ($win === null) return ['ok' => false, 'msg' => 'Pick a valid Photo-Friday date.'];

    $hash = pc_hashtag_from_prompt($prompt, pc_tag_prefix($settings));

    $drop_at = trim((string)($data['drop_at'] ?? ''));
    if ($drop_at === '') {
        $drop_at = $win['start'];                        // default: drops when the window opens
    } else {
        $drop_at = str_replace('T', ' ', $drop_at);      // datetime-local -> SQL
        if (strlen($drop_at) === 16) $drop_at .= ':00';
    }

    // One prompt per Friday (week_key is UNIQUE). Guard the friendly path too.
    $dupe = $pdo->prepare("SELECT id FROM pc_prompts WHERE week_key=? AND status IN ('queued','live') LIMIT 1");
    $dupe->execute([$win['week_key']]);
    if ($dupe->fetchColumn()) {
        return ['ok' => false, 'msg' => 'A prompt is already scheduled for ' . $win['label'] . '. Cancel it first to reschedule.'];
    }

    // Card body: the word, its hashtag, and the challenge link.
    $base = function_exists('sv_base') ? rtrim((string)sv_base($settings), '/') : '';
    $body = $prompt . "\n\n#" . $hash['display']
          . ($base !== '' ? "\n\nPost your photo during the 50-hour Photo-Friday window. " . $base . '/' : '');

    require_once __DIR__ . '/image-ingest.php';
    $res = snap_ingest_image($pdo, $settings, $file, [
        'title'       => $prompt,
        'status'      => 'draft',                         // hidden until the drop
        'description' => $body,
        'img_date'    => $drop_at,                        // dates the card at its drop moment
        'alt'         => (string)($data['alt'] ?? ($prompt . ' — Photo-Friday prompt card')),
    ]);
    if (empty($res['ok'])) return ['ok' => false, 'msg' => 'Card image: ' . ($res['error'] ?? 'upload failed') . '.'];
    $img_id = (int)$res['id'];

    // POST-MODEL PLUG — mirrors smack-post-solo.php so the card is post-backed
    // and federates on drop. NEVER touches img_slug (the federation identity).
    $sq = $pdo->prepare("SELECT img_slug FROM snap_images WHERE id=?");
    $sq->execute([$img_id]);
    $slug = (string)$sq->fetchColumn();
    $post_slug = $slug !== '' ? $slug : ('prompt-' . $img_id);
    $chk = $pdo->prepare('SELECT 1 FROM snap_posts WHERE slug=? LIMIT 1');
    $chk->execute([$post_slug]);
    if ($chk->fetchColumn()) $post_slug .= '-p' . $img_id;
    $pdo->prepare(
        "INSERT INTO snap_posts
            (title, slug, description, post_type, status, created_at,
             allow_comments, allow_download, download_url, panorama_rows,
             post_img_size_pct, post_border_px, post_border_color,
             post_bg_color, post_shadow, fedi_enabled)
         VALUES ('', ?, ?, 'single', 'draft', ?, 1, 0, '', 1, 100, 0, '#000000', '#ffffff', 0, 1)"
    )->execute([$post_slug, $body, $drop_at]);
    $post_id = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO snap_post_images
            (post_id, image_id, sort_position, is_cover,
             img_size_pct, img_border_px, img_border_color, img_bg_color,
             img_shadow, img_crop_mode, img_focus_x, img_focus_y, img_zoom)
         VALUES (?, ?, 0, 1, 100, 0, '#000000', '#ffffff', 0, 'fit', 50, 50, 100)"
    )->execute([$post_id, $img_id]);
    $pdo->prepare('UPDATE snap_images SET post_id=? WHERE id=?')->execute([$post_id, $img_id]);

    $ins = $pdo->prepare(
        "INSERT INTO pc_prompts
            (week_key, friday, submit_start, submit_end, prompt, tag, tag_display, drop_at, post_id, image_id, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,'queued')"
    );
    $ins->execute([$win['week_key'], $win['friday'], $win['start'], $win['end'],
                   $prompt, $hash['tag'], $hash['display'], $drop_at, $post_id, $img_id]);
    $id = (int)$pdo->lastInsertId();

    pc_refresh_prompt_pointers($pdo, $settings);
    return ['ok' => true, 'id' => $id,
            'msg' => 'Queued “' . $prompt . '” (#' . $hash['display'] . ') for ' . $win['label']
                   . '. Card drops ' . $drop_at . ' UTC.'];
}

/** Scheduled + already-dropped prompts, newest first (canceled ones hidden). */
function pc_prompts_list(PDO $pdo, int $limit = 60): array {
    pc_ensure_tables($pdo);
    $limit = max(1, min(200, $limit));
    return $pdo->query("SELECT * FROM pc_prompts WHERE status<>'canceled' ORDER BY drop_at DESC LIMIT {$limit}")
        ->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Unschedule a prompt that has not dropped yet. Non-destructive: the card stays
 * as a hidden draft in the library (never auto-deleted) so nothing is lost.
 * A prompt that already dropped cannot be canceled here — that would not un-post
 * what already federated.
 *
 * @return array{ok:bool,msg:string}
 */
function pc_cancel_prompt(PDO $pdo, array &$settings, int $id): array {
    $q = $pdo->prepare("SELECT status, friday FROM pc_prompts WHERE id=? LIMIT 1");
    $q->execute([$id]);
    $p = $q->fetch(PDO::FETCH_ASSOC);
    if (!$p) return ['ok' => false, 'msg' => 'That prompt is already gone.'];
    if (in_array($p['status'], ['live', 'done'], true)) {
        return ['ok' => false, 'msg' => 'That prompt already dropped — canceling would not un-post it.'];
    }
    $pdo->prepare("UPDATE pc_prompts SET status='canceled' WHERE id=?")->execute([$id]);
    pc_refresh_prompt_pointers($pdo, $settings);
    return ['ok' => true, 'msg' => 'Unscheduled the ' . $p['friday'] . ' prompt. Its card stays as a hidden draft in your library.'];
}

/**
 * The cron step: publish any queued prompt whose drop_at has arrived. Flips the
 * card post live (the delivery worker then federates it as a new Note), switches
 * the live qualifying hashtag to that week's tag, and advances the pointers.
 * Uses NOW() to match pc_rounds finalization (both treat the DB clock as UTC).
 *
 * @return int prompts dropped this pass
 */
function pc_activate_due_prompts(PDO $pdo, array &$settings): int {
    $due = $pdo->query("SELECT * FROM pc_prompts WHERE status='queued' AND drop_at<=NOW() ORDER BY drop_at LIMIT 5")
        ->fetchAll(PDO::FETCH_ASSOC);
    if (!$due) return 0;
    $dropped = 0;
    foreach ($due as $p) {
        $post_id = (int)$p['post_id'];
        $img_id  = (int)$p['image_id'];
        if ($post_id > 0) $pdo->prepare("UPDATE snap_posts SET status='published' WHERE id=?")->execute([$post_id]);
        if ($img_id  > 0) $pdo->prepare("UPDATE snap_images SET img_status='published' WHERE id=?")->execute([$img_id]);
        sv_set_setting($pdo, $settings, 'photochallenge_tag', (string)$p['tag']);  // this week's qualifying tag goes live
        $pdo->prepare("UPDATE pc_prompts SET status='live', dropped_at=NOW() WHERE id=?")->execute([(int)$p['id']]);
        $dropped++;
    }
    pc_refresh_prompt_pointers($pdo, $settings);
    require_once __DIR__ . '/page-cache.php';
    if (function_exists('page_cache_purge_all')) page_cache_purge_all();     // card appears immediately
    require_once __DIR__ . '/smackverse-kick.php';
    if (function_exists('sv_kick_delivery')) sv_kick_delivery();             // federate the freshly-published card
    return $dropped;
}

/**
 * Keep the "next prompt drops" / "next submissions open" pointers current so a
 * countdown can read a live value instead of a hardcoded date. Stored as ISO-8601
 * Z strings the countdown engine (data-until) understands. Display only — never
 * gates anything.
 */
function pc_refresh_prompt_pointers(PDO $pdo, array &$settings): void {
    $next = $pdo->query("SELECT drop_at, submit_start FROM pc_prompts WHERE status='queued' AND drop_at>NOW() ORDER BY drop_at LIMIT 1")
        ->fetch(PDO::FETCH_ASSOC);
    $iso = static fn(string $sql): string => $sql !== '' ? str_replace(' ', 'T', $sql) . 'Z' : '';
    sv_set_setting($pdo, $settings, 'photochallenge_next_prompt_at', $iso((string)($next['drop_at'] ?? '')));
    sv_set_setting($pdo, $settings, 'photochallenge_next_submit_at', $iso((string)($next['submit_start'] ?? '')));
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
            "SELECT t.object_id,t.actor_url,t.actor_handle,t.content,t.media_json,t.tags_json,t.url,
                    t.published,t.is_boost,a.horsconcours,p.state AS pstate
               FROM pc_admissions a
               JOIN snap_ap_timeline t ON t.object_id=a.object_id
               JOIN pc_participants p ON p.actor_url=a.actor_url AND p.state='active'
              WHERE a.week_key=:week_key AND a.status='active'
           ORDER BY a.admission_number ASC,a.admitted_at ASC LIMIT " . min(1000, max($limit, 1))
        );
        $st->execute([':week_key' => $win['week_key']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (($r['pstate'] ?? '') !== 'active') continue;
            $tags = json_decode((string)($r['tags_json'] ?? '[]'), true) ?: [];
            if (!in_array($tag, $tags, true)) continue;
            $media = json_decode((string)($r['media_json'] ?? '[]'), true) ?: [];
            if (count($media) !== 1 || !is_string($media[0]) || trim($media[0]) === '') continue;
            $rows[] = [
                'object_id'    => (string)($r['object_id'] ?? ''),   // AP object id (engagement key)
                'handle'       => (string)($r['actor_handle'] ?? ''),
                'actor_url'    => (string)($r['actor_url'] ?? ''),
                'url'          => (string)($r['url'] ?? ''),          // origin permalink (canonical)
                'thumb'        => (string)($media[0] ?? ''),          // hotlinked, origin-hosted
                'excerpt'      => mb_substr((string)($r['content'] ?? ''), 0, 240),
                'published'    => (string)($r['published'] ?? ''),
                'is_boost'     => ((int)($r['is_boost'] ?? 0)) === 1,
                'horsconcours' => ((int)($r['horsconcours'] ?? 0)) === 1,
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
 * exactly one image, original-not-boost, first five for that author). The
 * first-five allowance is intentionally not reopened by deleting/de-tagging an
 * earlier entry; durable admission enforcement is required before launch.
 */
function pc_maybe_boost_entry(PDO $pdo, array $settings, string $object_id): bool {
    if (!pc_enabled($settings)
        || ($settings['photochallenge_boost_enabled'] ?? '1') !== '1'
        || $object_id === ''
        || !function_exists('sv_boost_remote')) return false;

    pc_ensure_tables($pdo);
    try {
        $admission = pc_admit_object($pdo, $settings, $object_id);
        if (!$admission || ($admission['status'] ?? '') !== 'active'
            || in_array(($admission['boost_state'] ?? ''), ['sent','test'], true)) return false;

        // TESTING WHITELIST: sv_boost_remote delivers this boost ONLY to whitelisted
        // test accounts (never Public, never the real follower crowd). We still record
        // it, but as 'test' so the admin can tell real boosts from test ones.
        $is_test = (string)($settings['photochallenge_test_mode'] ?? '0') === '1';

        $boost_result = sv_boost_remote($pdo, $settings, $object_id);
        $ok = (bool)($boost_result[0] ?? false);
        $boost_id = (string)($boost_result[2] ?? '');
        if ($ok) {
            $pdo->prepare(
                "UPDATE pc_admissions SET boost_state=?,boost_activity_id=? WHERE object_id=?"
            )->execute([$is_test ? 'test' : 'sent', (string)$boost_id, $object_id]);
            return true;
        }
        $pdo->prepare("UPDATE pc_admissions SET boost_state='failed' WHERE object_id=?")
            ->execute([$object_id]);
        return false;
    } catch (Throwable $e) {
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
                       string $handle, string $post_url, string $caption = '', string $object_id = ''): void {
    pc_ensure_tables($pdo);
    $pdo->prepare(
        "INSERT INTO pc_hall_of_fame (week_key, place, actor_url, object_id, handle, post_url, caption)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE actor_url=VALUES(actor_url), handle=VALUES(handle),
                                 object_id=VALUES(object_id),post_url=VALUES(post_url),caption=VALUES(caption),active=1"
    )->execute([$week_key,$place,$actor_url,substr($object_id,0,500),substr($handle,0,190),
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
        $object_id = pc_entry_object_id($pdo,$object_id) ?? '';
        if ($object_id === '') return;
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
        $object_id = pc_entry_object_id($pdo,$object_id) ?? '';
        if ($object_id === '') return;
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
        return pc_entry_object_id($pdo,$object_id) !== null;
    } catch (Throwable $e) { return false; }
}

/** Normalize engagement aimed at our Announce back to the admitted origin id. */
function pc_entry_object_id(PDO $pdo, string $target_id): ?string {
    $st = $pdo->prepare("SELECT a.object_id FROM pc_admissions a
        JOIN pc_participants p ON p.actor_url=a.actor_url AND p.state='active'
        WHERE (a.object_id=? OR a.boost_activity_id=?) AND a.status='active' LIMIT 1");
    $st->execute([$target_id,$target_id]);
    $id = $st->fetchColumn();
    return $id === false ? null : (string)$id;
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
            (string)$w['url'], (string)$w['excerpt'], (string)$w['object_id']
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
