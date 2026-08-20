<?php
/**
 * SNAPSMACK - Install-mode / content guard
 *
 * Mode-agnostic helpers that detect the dominant content shape already present
 * in a database and decide whether setting a given site_mode would conflict
 * with it. The point: stop someone from pointing a GRAMOFSMACK install (or any
 * other mode) at a database that already holds a DIFFERENT mode's content.
 *
 * Why this matters: the canonical schema is the union of all three modes' tables,
 * and schema-sync NEVER drops, so a mode mismatch does NOT destroy data — but it
 * silently MIS-RENDERS it (SmackTalk essays pushed through The Grid's image
 * templates) and lets the wrong tools start writing alongside it. This guard
 * turns "silently mis-rendered and slowly polluted" into "stopped at the door."
 *
 * Used by install.php (recovery mode) today; intended to back the mode-migration
 * tool when that gets built. Read-only — never writes.
 *
 * Content-shape inference is a last-resort fallback only. Since 0.7.539 both
 * SMACKONEOUT and GRAMOFSMACK can legitimately store post-backed single images,
 * those rows do not reveal which authoring mode created them. On an established
 * install the saved site_mode is therefore the authority; guessing from shared
 * tables would manufacture confidence the data does not contain.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (!function_exists('snap_mode_label')) {
    /** Human-facing label for an internal site_mode value. */
    function snap_mode_label(string $mode): string {
        switch ($mode) {
            case 'photoblog': return 'SMACKONEOUT (photoblog)';
            case 'carousel':  return 'GRAMOFSMACK (carousel)';
            case 'smacktalk': return 'SmackTalk (essays)';
            default:          return $mode;
        }
    }
}

if (!function_exists('snap_detect_content')) {
    /**
     * Count the existing content by shape and report the dominant mode.
     * Every probe is wrapped — a missing table counts as 0, so this never throws
     * and never invents a conflict on a half-built or fresh database.
     *
     * @return array{counts:array<string,int>, dominant:?string, total:int}
     */
    function snap_detect_content(PDO $pdo): array {
        $count = function (string $sql) use ($pdo): int {
            try { return (int)$pdo->query($sql)->fetchColumn(); }
            catch (Throwable $e) { return 0; }
        };

        $longform_posts = $count("SELECT COUNT(*) FROM snap_posts WHERE post_type = 'longform'");
        $bucket_images  = $count("SELECT COUNT(DISTINCT b.image_id)
                                  FROM snap_bucket_items b
                                  JOIN snap_posts p ON p.id = b.post_id
                                  WHERE p.post_type = 'longform'");
        $bare_images    = $count("SELECT COUNT(*) FROM snap_images WHERE post_id IS NULL");
        $multi_posts    = $count("SELECT COUNT(*) FROM (
                                    SELECT post_id FROM snap_post_images
                                    GROUP BY post_id HAVING COUNT(*) > 1
                                  ) multi");

        $counts = [
            // Bucket photos are evidence of longform editorial work, not bare
            // photoblog entries. Weight them here and subtract them below.
            'smacktalk' => $longform_posts + $bucket_images,
            // A one-image pivot is now shared by photoblog and gram; only a
            // multi-image post is unambiguous carousel evidence.
            'carousel'  => $multi_posts,
            'photoblog' => max(0, $bare_images - $bucket_images),
        ];

        $dominant = null;
        $max = 0;
        foreach ($counts as $mode => $n) {
            if ($n > $max) { $max = $n; $dominant = $mode; }
        }

        return ['counts' => $counts, 'dominant' => $dominant, 'total' => array_sum($counts)];
    }
}

if (!function_exists('snap_mode_conflict')) {
    /**
     * Decide whether setting $target_mode conflicts with the content already in
     * the database. Returns null when it's safe (empty DB, matching content, or
     * existing content below $threshold — a handful of stray rows shouldn't block
     * a deliberate setup). Otherwise returns the conflict detail + a ready-to-show
     * message.
     *
     * $threshold mirrors the offline-posting consent gate (>5 items = an
     * "established" site) so the two safety rails agree on what "populated" means.
     *
     * @return array{existing_mode:string, existing_count:int, target_mode:string, message:string}|null
     */
    function snap_mode_conflict(PDO $pdo, string $target_mode, int $threshold = 5): ?array {
        // Prefer the install's explicit mode whenever it has real content. The
        // unified post model deliberately makes single-image photoblog and gram
        // posts structurally identical, so content-shape inference cannot safely
        // distinguish them.
        try {
            $saved_mode = (string)$pdo->query(
                "SELECT setting_val FROM snap_settings WHERE setting_key = 'site_mode' LIMIT 1"
            )->fetchColumn();
            $content_count = (int)$pdo->query(
                "SELECT (SELECT COUNT(*) FROM snap_posts) + (SELECT COUNT(*) FROM snap_images)"
            )->fetchColumn();
            if (in_array($saved_mode, ['photoblog', 'carousel', 'smacktalk'], true)
                && $content_count > $threshold) {
                if ($saved_mode === $target_mode) return null;
                return [
                    'existing_mode'  => $saved_mode,
                    'existing_count' => $content_count,
                    'target_mode'    => $target_mode,
                    'message'        => sprintf(
                        'This established database is configured as %s, but you are setting it to %s mode. '
                      . 'The unified post tables cannot safely infer a different origin for single-image posts. '
                      . 'Aborting — migrate the content deliberately before changing modes.',
                        snap_mode_label($saved_mode),
                        snap_mode_label($target_mode)
                    ),
                ];
            }
        } catch (Throwable $e) {
            // Missing/partial recovery schema: fall back to conservative shape probes below.
        }

        $info = snap_detect_content($pdo);
        $dominant = $info['dominant'];
        if ($dominant === null) return null;                 // empty DB
        $count = $info['counts'][$dominant];
        if ($count <= $threshold) return null;               // negligible content
        if ($dominant === $target_mode) return null;         // mode matches content

        $message = sprintf(
            'This database already holds %d %s item%s, but you are setting it to %s mode. '
          . 'Installing the wrong mode over existing content will not delete it, but it will '
          . 'mis-render it and let the wrong tools write alongside it. Aborting — switch the '
          . 'install mode to match, or migrate the content deliberately.',
            $count,
            snap_mode_label($dominant),
            $count === 1 ? '' : 's',
            snap_mode_label($target_mode)
        );

        return [
            'existing_mode'  => $dominant,
            'existing_count' => $count,
            'target_mode'    => $target_mode,
            'message'        => $message,
        ];
    }
}
// ===== SNAPSMACK EOF =====
