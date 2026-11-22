<?php
declare(strict_types=1);
/**
 * SNAPSMACK — what the fediverse cache and the inbound timeline are allowed
 * to keep.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1481 finding 14, measured at caebe17b and approved by Sean 2026-11-20.
 *
 * WHAT WAS WRONG. Two stores grew without a ceiling.
 *
 *   The media cache had a 7-day TTL and NO total cap, and the only thing that
 *   enforced the TTL was a 1-in-100 dice roll on each store. So the eviction
 *   either ran far more often than it needed to or, on a quiet site, not for
 *   days -- and nothing anywhere said "this cache may use N megabytes".
 *
 *   The inbound timeline had no retention at all. Every post the hub relayed,
 *   every post a followed account made, kept for ever on a photographer's
 *   shared host.
 *
 * WHAT THIS IS NOT. It is not video. Remote video is linked, never cached:
 * core/fediverse.php stores the URL and type in media_video_json, the player
 * streams it from the origin with preload="none", and the proxy's allowlist
 * refuses anything that is not one of five image types.
 *
 * Sean's ruling as REVISED on 2026-11-21, which supersedes his first one of
 * 2026-11-20 and is the rule in force: SNAPSMACK never caches, proxies,
 * re-encodes, stores or uploads video, and no post originating here carries
 * video. BOOSTING a remote post that contains video IS allowed, video-only or
 * mixed, because a boost is an Announce referencing the remote object and this
 * server never touches the bytes.
 *
 * The superseded sentence said "never ... boosts or shares video" and survived
 * the behaviour change in this file and in smack-media-proxy.php — Codex's
 * FAIL 3. A comment that contradicts the code is worse than no comment,
 * because the next reader trusts it.
 *
 * THE NUMBERS ARE SEAN'S, not mine. 512 MB total, owner-settable. 4 MB per
 * file -- a CACHE limit, not a fetch limit: a larger image is still fetched
 * and still served, it just does not earn a place on disk. The 7-day TTL
 * stays. 90 days or 2,000 rows per feed, whichever is MORE GENEROUS, and
 * never a row something has notified the owner about.
 */

/** The one place the cache lives. */
function snap_media_cache_dir(): string {
    return dirname(__DIR__) . '/cache/fedi-media';
}

/**
 * The owner's cache ceiling in bytes.
 *
 * Settable, because a photographer on a 2 GB shared host and one on a VPS do
 * not want the same number. Clamped at both ends: below 16 MB the cache
 * thrashes and costs more than it saves, and 32 GB is past any shared host
 * this product runs on. A missing or unreadable setting gives the default
 * rather than an unbounded cache.
 */
function snap_media_cache_limit_bytes(?array $settings = null): int {
    $mb = (int)($settings['fedi_media_cache_mb'] ?? 512);
    if ($mb <= 0) { $mb = 512; }
    return max(16, min(32768, $mb)) * 1048576;
}

/**
 * The largest single file worth caching, in bytes.
 *
 * DISTINCT FROM THE FETCH CEILING, which is 15 MB and enforced mid-stream in
 * smack-media-proxy.php. A 9 MB photograph is still fetched and still served
 * to the reader; it simply does not take 2% of the cache for one image. Said
 * plainly here because "4 MB limit" and "15 MB limit" in the same subsystem is
 * exactly the kind of pair that gets collapsed into one by a later reader.
 */
function snap_media_cache_max_file_bytes(): int {
    return 4194304;
}

/** Every cached pair in the directory, with its size and age. */
function snap_media_cache_entries(string $dir): array {
    $out = [];
    foreach (glob($dir . '/*.bin') ?: [] as $bin) {
        if (!is_file($bin)) { continue; }
        $mtime = @filemtime($bin);
        $size  = (int)@filesize($bin);
        $meta  = substr($bin, 0, -4) . '.json';
        if (is_file($meta)) { $size += (int)@filesize($meta); }
        $out[] = ['bin' => $bin, 'meta' => $meta,
                  'mtime' => $mtime === false ? 0 : $mtime, 'size' => $size];
    }
    return $out;
}

/**
 * Bring the cache back under its ceiling. Deterministic, every time.
 *
 * THE LOTTERY IS GONE. It was `if (random_int(1, 100) === 1)`, so on a quiet
 * site the 7-day TTL might not be enforced for days, and on a busy one the
 * sweep ran far more often than anything needed it to. Worse, a dice roll
 * cannot be tested: asserting on it is asserting on luck.
 *
 * Two passes, in this order:
 *   1. Anything past the TTL goes, whatever the total size is. Stale is stale.
 *   2. If the remainder is still over the ceiling, the OLDEST goes first until
 *      it fits.
 *
 * OLDEST BY WRITE TIME, not by last read. A cache hit deliberately does NOT
 * touch the file: the proxy decides freshness from the same mtime
 * (`filemtime($cache_body) >= time() - 86400`), so touching on a hit would
 * mean a popular image never refreshes. The cost is that eviction is
 * first-in-first-out rather than least-recently-used, which for a feed of
 * photographs -- where old posts stop being read -- is close enough and is
 * predictable, which the thing it replaced was not.
 *
 * @return array{expired:int,evicted:int,bytes:int} bytes = the total left
 */
function snap_media_cache_sweep(string $dir, int $limitBytes, int $ttl = 604800,
                                ?int $now = null): array {
    $now = $now ?? time();
    $expired = 0; $evicted = 0;
    $entries = [];
    foreach (snap_media_cache_entries($dir) as $e) {
        if ($e['mtime'] < $now - $ttl) {
            @unlink($e['bin']);
            if (is_file($e['meta'])) { @unlink($e['meta']); }
            $expired++;
            continue;
        }
        $entries[] = $e;
    }

    $total = 0;
    foreach ($entries as $e) { $total += $e['size']; }
    if ($total <= $limitBytes) {
        return ['expired' => $expired, 'evicted' => 0, 'bytes' => $total];
    }

    // Oldest first. A stable tiebreak on the path, so two files written in the
    // same second are evicted in an order that does not depend on the
    // filesystem's listing.
    usort($entries, static function (array $a, array $b): int {
        return $a['mtime'] <=> $b['mtime'] ?: strcmp($a['bin'], $b['bin']);
    });
    foreach ($entries as $e) {
        if ($total <= $limitBytes) { break; }
        @unlink($e['bin']);
        if (is_file($e['meta'])) { @unlink($e['meta']); }
        $total -= $e['size'];
        $evicted++;
    }
    return ['expired' => $expired, 'evicted' => $evicted, 'bytes' => $total];
}

/**
 * Cache one fetched image, if it is worth caching, and keep the cache bounded.
 *
 * Returns whether the body was written. FALSE IS NOT AN ERROR: an oversized
 * image, or an unwritable cache directory, both mean "served but not kept",
 * and the caller serves the body either way.
 */
function snap_media_cache_store(string $dir, string $key, string $body, string $ctype,
                                int $limitBytes, int $ttl = 604800): bool {
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) { return false; }
    if (!is_writable($dir)) { return false; }
    if (strlen($body) > snap_media_cache_max_file_bytes()) {
        // Served, not kept. Sweep anyway: this call is still a good moment to
        // notice the cache is over its ceiling.
        snap_media_cache_sweep($dir, $limitBytes, $ttl);
        return false;
    }
    $bin = $dir . '/' . $key . '.bin';
    $ok  = @file_put_contents($bin, $body, LOCK_EX) !== false;
    if ($ok) {
        @file_put_contents($dir . '/' . $key . '.json',
                           json_encode(['type' => $ctype]), LOCK_EX);
    }
    // After the write, so the file just stored counts towards the ceiling and
    // a single store can never push the cache over it.
    snap_media_cache_sweep($dir, $limitBytes, $ttl);
    return $ok;
}

/**
 * How many timeline rows each feed keeps, and for how long.
 *
 * WHICHEVER IS MORE GENEROUS, which is Sean's word and the whole point: a
 * quiet feed keeps 90 days even if that is 40 posts, and a firehose keeps
 * 2,000 even if that is four days. Neither number alone is right for both.
 */
function snap_timeline_retention_days(): int { return 90; }
function snap_timeline_retention_rows(): int { return 2000; }

/**
 * Does the boost record exist on this site?
 *
 * Asked only after a delete has already failed, to tell "this site never
 * federated and has no such table" from "the delete genuinely failed". The
 * answer decides whether the prune carries on or leaves that batch alone, so
 * it must not itself throw: when even this cannot be determined, the safe
 * reading is that the table IS there and the batch should be spared.
 *
 * Written against both engines on purpose. MySQL answers SHOW TABLES LIKE;
 * sqlite, which the regressions use, answers sqlite_master. Trying the first
 * and falling back is one query on a path that only runs after a failure.
 */
function snap_timeline_boosts_table_exists(PDO $pdo): bool {
    foreach (["SHOW TABLES LIKE 'snap_ap_timeline_boosts'",
              "SELECT name FROM sqlite_master WHERE type='table' "
              . "AND name='snap_ap_timeline_boosts'"] as $sql) {
        try {
            $q = $pdo->query($sql);
            if ($q === false) { continue; }
            return $q->fetch() !== false;
        } catch (Throwable $e) {
            continue;
        }
    }
    return true;   // cannot tell → assume present, spare the batch
}

/**
 * Prune the inbound timeline, keeping everything any feed still wants.
 *
 * A row survives if ANY of these is true:
 *   - it is newer than the day window;
 *   - it is inside some feed's newest N, by membership, or by its own `source`
 *     when it has no membership row;
 *   - the owner was NOTIFIED about it. Sean's instruction, and it is the rule
 *     that makes this safe to run unattended: a boost or a reply the owner can
 *     still see in their notifications must not lead to a post that no longer
 *     exists.
 *
 * $limit bounds one run so this can be called from a request without a long
 * DELETE. What it does not reach this time, it reaches next time.
 *
 * @return array{deleted:int,kept:int,candidates:int}
 */
function snap_timeline_retention(PDO $pdo, ?int $days = null, ?int $perFeed = null,
                                 int $limit = 5000, ?int $now = null): array {
    $days    = $days    ?? snap_timeline_retention_days();
    $perFeed = $perFeed ?? snap_timeline_retention_rows();
    $now     = $now     ?? time();
    $cutoff  = gmdate('Y-m-d H:i:s', $now - ($days * 86400));
    $keep    = [];

    try {
        // Newest N per feed, by membership.
        $byFeed = $pdo->prepare(
            "SELECT m.timeline_id FROM snap_ap_timeline_membership m
               JOIN snap_ap_timeline t ON t.id = m.timeline_id
              WHERE m.feed = ?
              ORDER BY COALESCE(t.published, t.fetched_at) DESC, t.id DESC
              LIMIT " . (int)$perFeed);
        // Newest N per feed for rows that have no membership row at all, which
        // is how everything ingested before memberships existed still counts.
        $bySource = $pdo->prepare(
            "SELECT t.id FROM snap_ap_timeline t
              WHERE t.source = ?
                AND NOT EXISTS (SELECT 1 FROM snap_ap_timeline_membership m
                                 WHERE m.timeline_id = t.id)
              ORDER BY COALESCE(t.published, t.fetched_at) DESC, t.id DESC
              LIMIT " . (int)$perFeed);
        foreach (['home', 'local', 'global'] as $feed) {
            $byFeed->execute([$feed]);
            foreach ($byFeed->fetchAll(PDO::FETCH_COLUMN) as $id) { $keep[(int)$id] = true; }
            $bySource->execute([$feed]);
            foreach ($bySource->fetchAll(PDO::FETCH_COLUMN) as $id) { $keep[(int)$id] = true; }
        }

        // Everything inside the day window, whatever feed it is in.
        $recent = $pdo->prepare(
            "SELECT id FROM snap_ap_timeline
              WHERE COALESCE(published, fetched_at) >= ?");
        $recent->execute([$cutoff]);
        foreach ($recent->fetchAll(PDO::FETCH_COLUMN) as $id) { $keep[(int)$id] = true; }

        // Anything the owner was notified about. Last, and separately, because
        // this is the rule that must hold even if the two above are wrong.
        $notified = $pdo->query(
            "SELECT t.id FROM snap_ap_timeline t
               JOIN snap_ap_notifications n ON n.object_id = t.object_id");
        foreach ($notified->fetchAll(PDO::FETCH_COLUMN) as $id) { $keep[(int)$id] = true; }

        $all = $pdo->query("SELECT id FROM snap_ap_timeline
                             ORDER BY COALESCE(published, fetched_at) ASC, id ASC")
                   ->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        error_log('SnapSmack timeline retention: could not read, nothing pruned — '
            . $e->getMessage());
        return ['deleted' => 0, 'kept' => 0, 'candidates' => 0];
    }

    $doomed = [];
    foreach ($all as $id) {
        if (!isset($keep[(int)$id])) { $doomed[] = (int)$id; }
    }
    $candidates = count($doomed);
    $doomed = array_slice($doomed, 0, max(0, $limit));
    if (!$doomed) {
        return ['deleted' => 0, 'kept' => count($keep), 'candidates' => 0];
    }

    $deleted = 0;
    try {
        foreach (array_chunk($doomed, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            // THE BOOST RECORD GOES TOO, and it has to go first, while the
            // timeline rows it is keyed against still exist: it is keyed on
            // object_id, not on the timeline's id, so once the row is deleted
            // there is nothing left to look the object up by.
            //
            // Codex's FAIL 2. snap_ap_timeline_boosts arrived with the
            // finding-2 repair on the other branch, and the merge brought the
            // two together without this. Orphan boost rows would then grow
            // outside every ceiling here, and a post re-ingested later would
            // read as still having live boosters that withdrew months ago.
            // Adding a table and not teaching retention about it is the same
            // omission as adding one and not declaring it in the schema.
            //
            // ABSENT IS FINE; FAILED IS NOT. Codex's second review: catching
            // everything here and carrying on meant a permissions error, a
            // lock or a deadlock left behind exactly the orphan records this
            // repair exists to prevent, while the log line scrolled past. A
            // site that has never federated genuinely has no such table and
            // must not be stopped by it. Those are different situations and
            // the code now tells them apart.
            //
            // On a real delete failure this CHUNK IS SKIPPED: the posts stay,
            // their boost records stay with them, and the next run tries
            // again. Keeping a post too long is recoverable; an orphan record
            // that outlives its post is the fault being fixed.
            try {
                $pdo->prepare("DELETE FROM snap_ap_timeline_boosts
                                WHERE object_id IN (SELECT object_id FROM snap_ap_timeline
                                                     WHERE id IN ({$marks}))")->execute($chunk);
            } catch (Throwable $e) {
                if (snap_timeline_boosts_table_exists($pdo)) {
                    error_log('SnapSmack timeline retention: boost records could NOT be '
                        . 'pruned and the table is present, so this batch of '
                        . count($chunk) . ' post(s) is left alone rather than orphaning '
                        . 'them — ' . $e->getMessage());
                    continue;
                }
                error_log('SnapSmack timeline retention: no snap_ap_timeline_boosts table '
                    . 'on this site, so there are no boost records to prune.');
            }
            // Memberships next, and by hand. The canonical schema cascades,
            // but a site whose table was made before the constraint existed
            // would otherwise keep orphan membership rows for ever.
            $pdo->prepare("DELETE FROM snap_ap_timeline_membership
                            WHERE timeline_id IN ({$marks})")->execute($chunk);
            $st = $pdo->prepare("DELETE FROM snap_ap_timeline WHERE id IN ({$marks})");
            $st->execute($chunk);
            $deleted += $st->rowCount();
        }
    } catch (Throwable $e) {
        error_log('SnapSmack timeline retention: prune failed after '
            . $deleted . ' row(s) — ' . $e->getMessage());
    }
    return ['deleted' => $deleted, 'kept' => count($keep), 'candidates' => $candidates];
}
// ===== SNAPSMACK EOF =====
