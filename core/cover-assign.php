<?php
/**
 * SNAPSMACK - Automatic cover assignment (albums + categories)
 *
 * Picks a unique cover for every album and every category, by Sean's rules:
 *   1. Cover is auto-selected.
 *   2. Most faves wins.
 *   3. Most views breaks the tie.
 *   4. No two albums share a cover image (likewise no two categories).
 *   5. On collision the more popular entity wins the image.
 *   6. The loser drops to its next-best still-available image.
 *   7. A manual user pick (cover_image_id) overrides all of the above.
 *
 * Greedy, most-popular-first — exactly rules 5/6: walk entities by popularity,
 * each grabs its best unused image, so a shared image always lands on the more
 * popular entity and the lesser one moves on.
 *
 * Data model (no schema change — these columns already exist):
 *   - cover_image_id (snap_albums / snap_categories) = the MANUAL lock (FK to
 *     snap_images). Non-null = user pick: that image is reserved and the entity
 *     is skipped by the auto pass.
 *   - featured_post_id = the active/auto cover the public grid reads. Despite the
 *     name it stores an IMAGE id (the column is a misnomer — verified against
 *     flkrfckr-api.php and smack-albums.php, which both write image ids), so we
 *     write the chosen image's id. A manual pick writes its cover_image_id.
 *   - snap_albums.view_count = album popularity. Categories have no tally, so
 *     they rank by summed member faves then views.
 *
 * Faves = likes on the image's post (snap_likes is keyed by post_id, joined via
 * snap_images.post_id). Views = snap_stats rows for the image. Albums and
 * categories use SEPARATE pools (an album and a category may share a cover; two
 * albums or two categories may not).
 *
 * Read-only except for the featured_post_id writes. Re-runnable and idempotent.
 * Returns a per-entity report: [['id'=>, 'image_id'=>, 'mode'=>'manual|auto|none'], ...].
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (!function_exists('snapsmack_recompute_covers')) {
    function snapsmack_recompute_covers(PDO $pdo): array {
        // --- Per-image stats, computed once and reused for both passes. ---
        $faves  = [];   // image_id => like count (likes are on the image's post)
        $views  = [];   // image_id => view count

        try {
            foreach ($pdo->query(
                "SELECT i.id AS iid, COUNT(l.id) AS c
                   FROM snap_images i
                   LEFT JOIN snap_likes l ON l.post_id = i.post_id
                  GROUP BY i.id") as $r) {
                $faves[(int)$r['iid']] = (int)$r['c'];
            }
        } catch (Throwable $e) { /* likes table absent — faves default to 0 */ }
        try {
            foreach ($pdo->query(
                "SELECT image_id, COUNT(*) AS c
                   FROM snap_stats
                  WHERE image_id IS NOT NULL
                  GROUP BY image_id") as $r) {
                $views[(int)$r['image_id']] = (int)$r['c'];
            }
        } catch (Throwable $e) { /* stats table absent — views default to 0 */ }

        return [
            'albums'     => ssc_assign_covers($pdo, $faves, $views,
                                'snap_albums', 'snap_image_album_map', 'album_id', 'view_count'),
            'categories' => ssc_assign_covers($pdo, $faves, $views,
                                'snap_categories', 'snap_image_cat_map', 'cat_id', null),
        ];
    }
}

if (!function_exists('ssc_assign_covers')) {
    /**
     * Assign unique covers for one entity type (album or category).
     * $viewCol: column holding a popularity tally, or null to derive it from
     *           summed member faves/views (categories have no tally).
     */
    function ssc_assign_covers(PDO $pdo, array $faves, array $views,
                               string $tbl, string $mapTbl, string $fk, ?string $viewCol): array {
        $popSel = $viewCol ? ", `$viewCol` AS pop" : "";
        $rows = $pdo->query("SELECT id, cover_image_id$popSel FROM `$tbl`")->fetchAll(PDO::FETCH_ASSOC);

        // Members per entity (image_id lists).
        $members = [];
        foreach ($pdo->query("SELECT image_id, `$fk` AS ent FROM `$mapTbl`") as $r) {
            $members[(int)$r['ent']][] = (int)$r['image_id'];
        }

        // Normalise + compute a [primary, secondary] popularity key per entity.
        foreach ($rows as &$e) {
            $e['id']  = (int)$e['id'];
            $e['cov'] = $e['cover_image_id'] !== null ? (int)$e['cover_image_id'] : null;
            if ($viewCol !== null) {
                $e['pop'] = [(int)($e['pop'] ?? 0), 0];
            } else {
                $mf = 0; $mv = 0;
                foreach ($members[$e['id']] ?? [] as $im) { $mf += $faves[$im] ?? 0; $mv += $views[$im] ?? 0; }
                $e['pop'] = [$mf, $mv];
            }
        }
        unset($e);

        $taken  = [];   // image_id => true (covers already claimed, this pool only)
        $assign = [];   // entity_id => featured_post_id
        $report = [];

        // Pass 1 — manual locks (rule 7). Reserve the image, point the display
        // column at its post so the user's pick actually renders.
        foreach ($rows as $e) {
            if ($e['cov'] !== null) {
                $taken[$e['cov']] = true;
                $assign[$e['id']] = $e['cov'];   // featured_post_id holds an image id
                $report[] = ['id' => $e['id'], 'image_id' => $e['cov'], 'mode' => 'manual'];
            }
        }

        // Pass 2 — auto, most-popular-first (rules 1-6).
        $auto = array_values(array_filter($rows, fn($e) => $e['cov'] === null));
        usort($auto, fn($a, $b) =>
            ($b['pop'][0] <=> $a['pop'][0]) ?: ($b['pop'][1] <=> $a['pop'][1]) ?: ($a['id'] <=> $b['id']));

        foreach ($auto as $e) {
            $cands = [];
            foreach ($members[$e['id']] ?? [] as $im) {
                if (isset($taken[$im])) continue; // already claimed by a more popular entity
                $cands[] = $im;
            }
            if (!$cands) { $report[] = ['id' => $e['id'], 'image_id' => null, 'mode' => 'none']; continue; }
            usort($cands, fn($x, $y) =>
                (($faves[$y] ?? 0) <=> ($faves[$x] ?? 0)) ?: (($views[$y] ?? 0) <=> ($views[$x] ?? 0)) ?: ($x <=> $y));
            $pick = $cands[0];
            $taken[$pick] = true;
            $assign[$e['id']] = $pick;   // featured_post_id holds an image id
            $report[] = ['id' => $e['id'], 'image_id' => $pick, 'mode' => 'auto'];
        }

        // Persist the auto/manual hero column. Entities with no available image
        // are left untouched (keep whatever cover they had).
        $up = $pdo->prepare("UPDATE `$tbl` SET featured_post_id = ? WHERE id = ?");
        foreach ($assign as $entId => $pid) $up->execute([$pid, $entId]);

        return $report;
    }
}
// ===== SNAPSMACK EOF =====
