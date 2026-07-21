<?php
/**
 * SNAPSMACK - Trigram helpers
 *
 * Shared logic for the queued-publish gate that ensures all three posts in a
 * trigram group go live atomically.  Used by smack-lt-gram.php and
 * core/threeacross-api.php.  Intended for smack-post-gram.php (manual
 * publish path) — not yet wired.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/**
 * Check whether all three posts in a trigram group are ready to publish, and
 * if so promote them atomically.
 *
 * Called after a post's status has been set to 'queued' or 'published' and the
 * post belongs to a trigram group.
 *
 * Behaviour:
 *   - Counts how many of the three trigram posts are status='queued' or
 *     status='published'.
 *   - If count < 3: sets the target post to 'queued' and returns false.
 *   - If count == 3: flips all three to 'published' atomically, assigns
 *     consecutive sort_order values starting at the next row-boundary slot
 *     (next position ≡ 0 mod 3 after the current MAX(sort_order)), and
 *     returns true.
 *
 * @param PDO $pdo          Live database connection.
 * @param int $trigram_id   The snap_trigrams.id for the group.
 * @param int $post_id      The post being published (will be set to 'queued' if not ready).
 *
 * @return bool  True = all three promoted to 'published'.  False = still waiting.
 */
function trigram_check_and_publish(PDO $pdo, int $trigram_id, int $post_id): bool
{
    // Fetch the trigram row to get all three post IDs.
    $tg = $pdo->prepare("SELECT post_id_1, post_id_2, post_id_3 FROM snap_trigrams WHERE id = ? LIMIT 1");
    $tg->execute([$trigram_id]);
    $row = $tg->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        // Trigram row missing — just publish the post normally.
        $pdo->prepare("UPDATE snap_posts SET status = 'published' WHERE id = ?")->execute([$post_id]);
        return true;
    }

    $ids = [(int)$row['post_id_1'], (int)$row['post_id_2'], (int)$row['post_id_3']];

    // Count how many slots are already queued or published (i.e. user-ready).
    $ph   = implode(',', array_fill(0, 3, '?'));
    $stmt = $pdo->prepare("SELECT id, status FROM snap_posts WHERE id IN ($ph)");
    $stmt->execute($ids);
    $statuses = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // id => status

    // Mark the triggering post as queued now so it counts.
    $ready = 0;
    foreach ($ids as $pid) {
        $s = ($pid === $post_id) ? 'queued' : ($statuses[$pid] ?? '');
        if ($s === 'queued' || $s === 'published') {
            $ready++;
        }
    }

    if ($ready < 3) {
        // Not all three ready — park this post as queued.
        $pdo->prepare("UPDATE snap_posts SET status = 'queued' WHERE id = ?")->execute([$post_id]);
        return false;
    }

    // All three ready — promote atomically.
    $pdo->beginTransaction();
    try {
        // Find the next row-boundary sort_order slot.
        // sort_order is 1-indexed; row starts are 1, 4, 7, 10... (≡ 1 mod 3).
        // Find smallest n > max_so where (n-1) % 3 === 0 (column 0 of a new row).
        $max_so     = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM snap_posts WHERE status = 'published'")->fetchColumn();
        $col_offset = (1 - ($max_so % 3) + 3) % 3;
        $start      = $max_so + ($col_offset === 0 ? 3 : $col_offset);

        $upd = $pdo->prepare("UPDATE snap_posts SET status = 'published', sort_order = ? WHERE id = ?");
        foreach ($ids as $i => $pid) {
            $upd->execute([$start + $i, $pid]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        // Re-throw so callers can surface the error.
        throw $e;
    }

    return true;
}

/**
 * How many of the three trigram slots are queued or published?
 * Returns an int 0–3.  Used to build UI tooltips ("Waiting for X more").
 */
function trigram_ready_count(PDO $pdo, int $trigram_id): int
{
    $tg = $pdo->prepare("SELECT post_id_1, post_id_2, post_id_3 FROM snap_trigrams WHERE id = ? LIMIT 1");
    $tg->execute([$trigram_id]);
    $row = $tg->fetch(PDO::FETCH_ASSOC);
    if (!$row) return 0;

    $ids  = [(int)$row['post_id_1'], (int)$row['post_id_2'], (int)$row['post_id_3']];
    $ph   = implode(',', array_fill(0, 3, '?'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_posts WHERE id IN ($ph) AND status IN ('queued','published')");
    $stmt->execute($ids);
    return (int)$stmt->fetchColumn();
}

/* ───────────────────────────────────────────────────────────────────────────
   3-ACROSS ENFORCEMENT (blog-level)

   When ON, the published feed is kept to COMPLETE rows of three. A trigram
   already occupies a whole row (gated by trigram_check_and_publish above). The
   piece this adds: trailing SINGLE (non-trigram) posts that can't fill a row are
   parked as 'queued' and auto-release the moment enough posts exist to complete
   the row — same hands-off behaviour as the trigram gate.

   The flag lives in snap_settings (setting_key = 'gram_three_across_enforce').
   Creating a trigram flips it ON; it can be flipped back OFF by hand.
   ─────────────────────────────────────────────────────────────────────────── */

if (!defined('THREEACROSS_FLAG')) define('THREEACROSS_FLAG', 'gram_three_across_enforce');

function threeacross_enabled(PDO $pdo): bool
{
    try {
        $s = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = ? LIMIT 1");
        $s->execute([THREEACROSS_FLAG]);
        return ((string)$s->fetchColumn()) === '1';
    } catch (Throwable $e) {
        return false;
    }
}

function threeacross_set_enabled(PDO $pdo, bool $on): void
{
    $pdo->prepare(
        "INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
    )->execute([THREEACROSS_FLAG, $on ? '1' : '0']);
}

/**
 * Keep published SINGLE (non-trigram) posts to a multiple of three. The trailing
 * (bottom-of-feed) 1–2 user-ready singles beyond the last complete row are parked
 * as 'queued'; the rest are 'published'. No-op when enforcement is off. Drafts are
 * never touched — only posts the user already pushed (published/queued) are
 * redistributed, so this can never surface a draft.
 *
 * Ordering matches the landing feed exactly, so "trailing" = the real bottom of
 * the grid (the lone post on an incomplete row).
 *
 * @return array ['published'=>int[], 'queued'=>int[]] — the singles it settled.
 */
function threeacross_settle(PDO $pdo): array
{
    if (!threeacross_enabled($pdo)) return ['published' => [], 'queued' => []];

    $rows = $pdo->query(
        "SELECT id FROM snap_posts
          WHERE trigram_id IS NULL AND status IN ('published','queued')
          ORDER BY CASE WHEN sort_order > 0 THEN 1 ELSE 0 END ASC,
                   sort_order ASC, id DESC"
    )->fetchAll(PDO::FETCH_COLUMN);

    $n    = count($rows);
    $keep = $n - ($n % 3);

    $pub = $pdo->prepare("UPDATE snap_posts SET status='published' WHERE id = ? AND status <> 'published'");
    $que = $pdo->prepare("UPDATE snap_posts SET status='queued'    WHERE id = ? AND status <> 'queued'");

    $published = []; $queued = [];
    foreach ($rows as $i => $id) {
        if ($i < $keep) { $pub->execute([$id]); $published[] = (int)$id; }
        else            { $que->execute([$id]); $queued[]    = (int)$id; }
    }
    return ['published' => $published, 'queued' => $queued];
}

/**
 * Re-lay the PUBLISHED feed as whole rows. A trigram stays glued (one row, in
 * slot order); singles regroup into fresh rows of three. Only the position of
 * each row-unit changes — never a trigram's internals — so the grid stays
 * aligned. Non-published posts are parked after the published block, order
 * preserved.
 *
 * @param string $mode 'shuffle' = randomize; 'chrono' = newest-first by date.
 * @return int Number of published posts re-laid.
 *
 * Destructive to feed order — callers MUST gate behind step-up auth.
 */
function feed_relayout(PDO $pdo, string $mode): int
{
    // Posted order = insertion order = id. img_date/created_at never sort.
    $pub = $pdo->query("
        SELECT p.id, p.trigram_id,
               CASE WHEN tg.post_id_1 = p.id THEN 1
                    WHEN tg.post_id_2 = p.id THEN 2
                    WHEN tg.post_id_3 = p.id THEN 3
                    ELSE 0 END AS slot
        FROM snap_posts p
        LEFT JOIN snap_trigrams tg ON tg.id = p.trigram_id
        WHERE p.status = 'published'
        ORDER BY p.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Build row-units: ['ord'=>newest member id, 'ids'=>[post ids in order]].
    $units = []; $singles = []; $seen = [];
    foreach ($pub as $r) {
        $tg = (int)$r['trigram_id'];
        if ($tg > 0) {
            if (isset($seen[$tg])) continue;
            $seen[$tg] = true;
            $mem = array_values(array_filter($pub, fn($x) => (int)$x['trigram_id'] === $tg));
            usort($mem, fn($a, $b) => (int)$a['slot'] <=> (int)$b['slot']);
            $units[] = [
                'ord' => max(array_map(fn($m) => (int)$m['id'], $mem)),
                'ids' => array_map(fn($m) => (int)$m['id'], $mem),
            ];
        } else {
            $singles[] = $r;
        }
    }
    // Singles are already newest-first; chunk into rows of three.
    foreach (array_chunk($singles, 3) as $chunk) {
        $units[] = [
            'ord' => (int)$chunk[0]['id'],
            'ids' => array_map(fn($m) => (int)$m['id'], $chunk),
        ];
    }

    if ($mode === 'shuffle') {
        shuffle($units);
    } else { // 'chrono' — newest (highest posted id) row first
        usort($units, fn($a, $b) => $b['ord'] <=> $a['ord']);
    }

    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare("UPDATE snap_posts SET sort_order = ? WHERE id = ?");
        $pos = 1;
        foreach ($units as $u) {
            foreach ($u['ids'] as $id) $upd->execute([$pos++, $id]);
        }
        // Park non-published posts after the published block, order preserved.
        $rest = $pdo->query("
            SELECT id FROM snap_posts WHERE status <> 'published'
            ORDER BY CASE WHEN sort_order > 0 THEN 1 ELSE 0 END ASC,
                     sort_order ASC, id DESC
        ")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rest as $id) $upd->execute([$pos++, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $pos - 1;
}

/**
 * Re-order a feed post array so every HORIZONTAL trigram (L/M/R) starts at
 * column 0 of the 3-wide grid — WITHOUT padding blank tiles. When a trigram
 * would land mid-row, the next single (non-trigram) posts are pulled up to
 * finish the current row, then the trigram's three members are emitted
 * together. Result: no empty gaps, panoramas stay whole on one row, and the
 * order only bends a hair (a single or two slide up around each trigram).
 *
 * Idempotent — already-aligned input comes back unchanged. Skins keep their
 * phantom-padding as a safety net: after this pass it only fires at the very
 * tail of the feed where no singles remain to pull (a whole panorama beats a
 * broken one).
 *
 * Expects each row to carry 'trigram_id', 'trigram_slot' (1/2/3, 0 = single),
 * and 'trigram_orientation' ('h' default, 'v' = vertical/no realign).
 *
 * @param array $posts Feed rows in display order.
 * @return array Re-ordered rows.
 */
function trigram_align_backfill(array $posts): array
{
    $posts = array_values($posts);
    $n     = count($posts);
    $used  = array_fill(0, $n, false);
    $out   = [];
    $col   = 0;

    for ($i = 0; $i < $n; $i++) {
        if ($used[$i]) continue;
        $p      = $posts[$i];
        $slot   = (int)($p['trigram_slot'] ?? 0);
        $orient = $p['trigram_orientation'] ?? 'h';

        // Only horizontal trigram L-tiles (slot 1) need a clean row start.
        if ($slot === 1 && $orient !== 'v') {
            // Backfill the current row with upcoming SINGLES so the trigram
            // begins at column 0.
            if ($col !== 0) {
                $need = 3 - $col;
                for ($k = $i + 1; $k < $n && $need > 0; $k++) {
                    if ($used[$k]) continue;
                    if ((int)($posts[$k]['trigram_slot'] ?? 0) > 0) continue; // singles only
                    $out[] = $posts[$k];
                    $used[$k] = true;
                    $col = ($col + 1) % 3;
                    $need--;
                }
                // If $col is still not 0, no singles remain to pull — the skin's
                // phantom padding handles this tail case. Emit the trigram now.
            }
            // Emit L, then its M (slot 2) and R (slot 3) — pulled together even
            // if a stray single sits between them in the raw order.
            $tgid = (int)($p['trigram_id'] ?? 0);
            $out[] = $p; $used[$i] = true; $col = ($col + 1) % 3;
            foreach ([2, 3] as $wantSlot) {
                for ($k = $i + 1; $k < $n; $k++) {
                    if ($used[$k]) continue;
                    if ((int)($posts[$k]['trigram_id'] ?? 0) === $tgid
                        && (int)($posts[$k]['trigram_slot'] ?? 0) === $wantSlot) {
                        $out[] = $posts[$k]; $used[$k] = true; $col = ($col + 1) % 3;
                        break;
                    }
                }
            }
            continue;
        }

        // Normal single (or vertical-trigram member) — emit in place.
        $out[] = $p; $used[$i] = true; $col = ($col + 1) % 3;
    }

    return $out;
}

// ===== SNAPSMACK EOF =====
