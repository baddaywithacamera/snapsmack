<?php
/**
 * SNAPSMACK - Post-Model Health Check (READ-ONLY diagnostic)
 *
 * Audit 049 §5.3/§5.4 preflight. Reports the real state of the split
 * content-model on THIS install: how many photos are stored as bare image
 * rows, how many likes/reactions/comments hold an image id in a "post_id"
 * column, dishonest collection rows, orphan pivots, duplicate slugs, and
 * status-authority disagreements.
 *
 * SAFE: this file only ever runs SELECT / information_schema queries. It
 * writes NOTHING to the database and changes no data. Admin-login gated.
 * Drop it in the web root and open /modcheck.php while logged in.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once 'core/auth-smack.php';   // login gate + $pdo (redirects if not logged in)

if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . '/core/db.php';
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- defensive helpers: never fatal, skip cleanly ---------- */
function pm_table_exists(PDO $pdo, string $t): bool {
    try {
        $s = $pdo->prepare("SELECT 1 FROM information_schema.tables
                            WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
        $s->execute([$t]);
        return (bool) $s->fetchColumn();
    } catch (Throwable $e) { return false; }
}
function pm_col_exists(PDO $pdo, string $t, string $c): bool {
    try {
        $s = $pdo->prepare("SELECT 1 FROM information_schema.columns
                            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1");
        $s->execute([$t, $c]);
        return (bool) $s->fetchColumn();
    } catch (Throwable $e) { return false; }
}
/** Run a scalar SELECT; return ['n'=>int] on success or ['skip'=>reason]. */
function pm_num(PDO $pdo, string $sql, array $params = []) {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        return ['n' => (int) $s->fetchColumn()];
    } catch (Throwable $e) {
        return ['skip' => $e->getMessage()];
    }
}

/* ---------- gather rows to display ---------- */
$rows = [];   // each: [label, value-or-skip, plain-english meaning, flag]
function pm_row(&$rows, string $label, $res, string $meaning, bool $flagIfNonZero = false) {
    if (isset($res['skip'])) {
        $rows[] = [$label, null, 'skipped — ' . $res['skip'], 'skip'];
        return;
    }
    $n = $res['n'];
    $flag = ($flagIfNonZero && $n > 0) ? 'warn' : 'ok';
    $rows[] = [$label, $n, $meaning, $flag];
}

$has_images  = pm_table_exists($pdo, 'snap_images');
$has_posts   = pm_table_exists($pdo, 'snap_posts');
$has_pivot   = pm_table_exists($pdo, 'snap_post_images');

/* Site mode governs how these numbers must be READ. Photo-to-post conversion is a
   SMACKONEOUT (photoblog) concept only. */
$site_mode = 'photoblog';
try { $site_mode = (string) ($pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='site_mode' LIMIT 1")->fetchColumn() ?: 'photoblog'); }
catch (Throwable $e) {}

/* ===== SECTION 1 — the shape of your content ===== */
if ($has_images) {
    pm_row($rows, 'Total images', pm_num($pdo, "SELECT COUNT(*) FROM snap_images"),
        'Every media row on the site.');
    pm_row($rows, 'Published solo photos (bare image, no post)',
        pm_num($pdo, "SELECT COUNT(*) FROM snap_images WHERE img_status='published' AND post_id IS NULL"),
        'These are the entries stored the OLD way — a photo that is its own unit.');
    pm_row($rows, 'Images that ARE attached to a post (post_id set)',
        pm_num($pdo, "SELECT COUNT(*) FROM snap_images WHERE post_id IS NOT NULL"),
        'Already post-backed via the direct post_id column.');
    if (pm_col_exists($pdo, 'snap_images', 'img_date')) {
        pm_row($rows, 'Scheduled (published but future-dated)',
            pm_num($pdo, "SELECT COUNT(*) FROM snap_images WHERE img_status='published' AND img_date > NOW() AND post_id IS NULL"),
            'SHOTS FIRED pending — these "fire" on the image clock. Pause before any migration.', true);
    }
}
if ($has_posts) {
    pm_row($rows, 'snap_posts rows (published)',
        pm_num($pdo, "SELECT COUNT(*) FROM snap_posts WHERE status='published'"),
        'Real post rows — the target model.');
}

/* SMACKTALK reads Section 1 differently. A longform site does not use the
   SMACKONEOUT photo-to-post conversion at all — its images are editorial material
   for essays, never image-posts. Reframe the bare-image counts so nobody mistakes
   them for conversion candidates. */
if ($has_images && $site_mode === 'smacktalk') {
    if ($has_pivot) {
        pm_row($rows, 'Images in longform post buckets (expected editorial working set)',
            pm_num($pdo, "SELECT COUNT(*) FROM snap_images i
                          WHERE EXISTS (SELECT 1 FROM snap_post_images spi WHERE spi.image_id = i.id)"),
            'A bucket is private editorial state, not a list of image-posts — they are not posts and must not be converted.');
    }
    pm_row($rows, 'Library images not currently in a post bucket',
        pm_num($pdo, "SELECT COUNT(*) FROM snap_images i
                      WHERE i.post_id IS NULL
                        AND NOT EXISTS (SELECT 1 FROM snap_post_images spi WHERE spi.image_id = i.id)"),
        'On SMACKTALK these are unused library photos — they are not posts and must not be converted. '
        . 'This site does not use the SMACKONEOUT photo-to-post conversion.');
}

/* ===== SECTION 2 — dual ownership (the double-emit trap) ===== */
if ($has_images && $has_pivot) {
    pm_row($rows, 'Images WITH a pivot row but post_id still NULL',
        pm_num($pdo, "SELECT COUNT(*) FROM snap_images i
                      WHERE i.post_id IS NULL
                        AND EXISTS (SELECT 1 FROM snap_post_images pim WHERE pim.image_id = i.id)"),
        'DANGER: two ownership authorities disagree — this photo can federate TWICE.', true);
    pm_row($rows, 'Images with post_id set but NO pivot row',
        pm_num($pdo, "SELECT COUNT(*) FROM snap_images i
                      WHERE i.post_id IS NOT NULL
                        AND NOT EXISTS (SELECT 1 FROM snap_post_images pim WHERE pim.image_id = i.id)"),
        'Ownership recorded on the image but not in the pivot — inconsistent.', true);
    pm_row($rows, 'Orphan pivots (point at a missing post)',
        pm_num($pdo, "SELECT COUNT(*) FROM snap_post_images pim
                      WHERE NOT EXISTS (SELECT 1 FROM snap_posts p WHERE p.id = pim.post_id)"),
        'Pivot rows whose post no longer exists.', true);
    pm_row($rows, 'Orphan pivots (point at a missing image)',
        pm_num($pdo, "SELECT COUNT(*) FROM snap_post_images pim
                      WHERE NOT EXISTS (SELECT 1 FROM snap_images i WHERE i.id = pim.image_id)"),
        'Pivot rows whose image no longer exists.', true);
}
if ($has_images && $has_posts) {
    pm_row($rows, 'Post-backed images whose img_status disagrees with the post status',
        pm_num($pdo, "SELECT COUNT(*) FROM snap_images i
                      JOIN snap_posts p ON p.id = i.post_id
                      WHERE i.img_status <> p.status"),
        'The two "status" fields say different things for the same entry.', true);
}

/* ===== SECTION 3 — engagement ids (image masquerading as post) ===== */
foreach (['snap_likes' => 'Likes', 'snap_reactions' => 'Reactions',
          'snap_community_comments' => 'Community comments'] as $tbl => $nice) {
    if (!pm_table_exists($pdo, $tbl) || !pm_col_exists($pdo, $tbl, 'post_id')) continue;
    if ($has_images) {
        pm_row($rows, "$nice whose post_id is actually an IMAGE id",
            pm_num($pdo, "SELECT COUNT(*) FROM `$tbl` e
                          WHERE EXISTS (SELECT 1 FROM snap_images i WHERE i.id = e.post_id)
                            AND NOT EXISTS (SELECT 1 FROM snap_posts p WHERE p.id = e.post_id)"),
            "$nice attached to an image id sitting in a column named post_id — the remap target.", true);
    }
    if ($has_images && $has_posts) {
        pm_row($rows, "$nice whose post_id is AMBIGUOUS (valid image AND post id)",
            pm_num($pdo, "SELECT COUNT(*) FROM `$tbl` e
                          WHERE EXISTS (SELECT 1 FROM snap_images i WHERE i.id = e.post_id)
                            AND EXISTS (SELECT 1 FROM snap_posts p WHERE p.id = e.post_id)"),
            "Same integer is both a real image id and a real post id — a blind remap would guess wrong.", true);
    }
    pm_row($rows, "$nice that point at nothing (orphan)",
        pm_num($pdo, "SELECT COUNT(*) FROM `$tbl` e
                      WHERE NOT EXISTS (SELECT 1 FROM snap_images i WHERE i.id = e.post_id)"
                      . ($has_posts ? " AND NOT EXISTS (SELECT 1 FROM snap_posts p WHERE p.id = e.post_id)" : "")),
        "Neither an image nor a post — already dangling.", true);
}

/* ===== SECTION 4 — collections (dishonest discriminator) ===== */
if (pm_table_exists($pdo, 'snap_collection_items')
    && pm_col_exists($pdo, 'snap_collection_items', 'item_type')
    && pm_col_exists($pdo, 'snap_collection_items', 'item_id') && $has_images) {
    pm_row($rows, "Collection rows labelled 'post' that actually point at an IMAGE",
        pm_num($pdo, "SELECT COUNT(*) FROM snap_collection_items ci
                      WHERE ci.item_type='post'
                        AND EXISTS (SELECT 1 FROM snap_images i WHERE i.id = ci.item_id)"
                      . ($has_posts ? " AND NOT EXISTS (SELECT 1 FROM snap_posts p WHERE p.id = ci.item_id)" : "")),
        "The 'dishonest discriminator' — says post, stores an image. These silently drop from collection pages.", true);
    if (pm_col_exists($pdo, 'snap_collection_items', 'image_id')) {
        pm_row($rows, "Light-table rows using image_id but left at item_type='post'",
            pm_num($pdo, "SELECT COUNT(*) FROM snap_collection_items
                          WHERE image_id > 0 AND item_type='post' AND item_id = 0"),
            "Truth is in image_id; the polymorphic pair is stale/misleading.", true);
    }
}

/* ===== SECTION 5 — slug integrity ===== */
if ($has_images && pm_col_exists($pdo, 'snap_images', 'img_slug')) {
    pm_row($rows, 'Duplicate image slugs (case-insensitive)',
        pm_num($pdo, "SELECT COALESCE(SUM(c-1),0) FROM (
                        SELECT COUNT(*) c FROM snap_images
                        WHERE img_slug IS NOT NULL AND img_slug <> ''
                        GROUP BY LOWER(img_slug) HAVING COUNT(*) > 1) d"),
        'img_slug is the identity that survives migration — duplicates must be resolved first.', true);
}

/* ---------- render ---------- */
$site = '';
try { $site = (string) $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='site_title'")->fetchColumn(); }
catch (Throwable $e) {}
$ver = defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : (defined('SNAPSMACK_VERSION') ? SNAPSMACK_VERSION : '?');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Post-Model Health Check</title>
<style>
  body{font:15px/1.5 system-ui,Segoe UI,Arial,sans-serif;margin:0;background:#14161a;color:#e7e9ee}
  .wrap{max-width:960px;margin:0 auto;padding:28px 20px 60px}
  h1{font-size:22px;margin:0 0 4px}
  .sub{color:#9aa3b2;margin:0 0 22px}
  .safe{display:inline-block;background:#1f3a24;color:#9fe6ad;border:1px solid #2c5a35;
        padding:4px 10px;border-radius:6px;font-size:13px;margin-bottom:20px}
  table{width:100%;border-collapse:collapse;background:#1a1d23;border-radius:10px;overflow:hidden}
  th,td{padding:11px 13px;text-align:left;border-bottom:1px solid #262a31;vertical-align:top}
  th{background:#20242c;color:#c3cad6;font-size:13px}
  td.n{font-variant-numeric:tabular-nums;font-weight:700;font-size:17px;white-space:nowrap;width:70px}
  tr.warn td.n{color:#ffcf6b}
  tr.ok td.n{color:#8fd694}
  tr.skip td{color:#7d8493}
  .mean{color:#9aa3b2;font-size:13px}
  .lab{font-weight:600}
  footer{color:#7d8493;font-size:12px;margin-top:22px;line-height:1.7}
  code{background:#20242c;padding:1px 5px;border-radius:4px}
</style></head><body><div class="wrap">
<h1>Post-Model Health Check</h1>
<p class="sub"><?= htmlspecialchars($site ?: 'this site') ?> · SnapSmack <?= htmlspecialchars($ver) ?> · Audit 049 §5.3 preflight</p>
<span class="safe">✓ Read-only — this page changed nothing. It only counted rows.</span>
<table>
  <thead><tr><th>Check</th><th>Count</th><th>What it means</th></tr></thead>
  <tbody>
<?php foreach ($rows as [$label, $val, $mean, $flag]): ?>
    <tr class="<?= $flag ?>">
      <td class="lab"><?= htmlspecialchars($label) ?></td>
      <td class="n"><?= $val === null ? '—' : number_format($val) ?></td>
      <td class="mean"><?= htmlspecialchars($mean) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<footer>
  Amber numbers are things the post-model fix will need to clean up; they are <b>not</b> live breakage.<br>
  Rows marked “skipped” mean that table/column isn’t present on this install — expected, not an error.<br>
  This is a <b>diagnostic</b>. It performs no migration and makes no changes. Delete the file when done, or leave it — it stays admin-only.
</footer>
</div></body></html>
<?php // ===== SNAPSMACK EOF =====
