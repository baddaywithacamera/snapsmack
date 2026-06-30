<?php
/**
 * SNAPSMACK - Grid Lighttable (smack-lt-gram.php)
 *
 * Visual 3-column feed reorder tool for Grid (GramOfSmack) installs.
 * Shows all posts (published + draft + queued) as a live grid preview.
 * Drag tiles to reorder the feed. Publish drafts individually or in bulk.
 * Trigram groups (3-tile panoramic sets) are visually identified and
 * flagged when their tiles fall out of valid row/column alignment.
 * Trigram tiles move as a unit — dragging one moves all three together.
 * Queued posts await the other two trigram slots before going live.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before editing.
 */

require_once 'core/auth-smack.php';
require_once 'core/trigram.php';

// --- DEFENSIVE SCHEMA GUARD ---
$pdo->exec("ALTER TABLE snap_posts ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0
    COMMENT 'Manual feed order. 0 = unset (falls back to created_at DESC).'");
$pdo->exec("ALTER TABLE snap_posts ADD COLUMN IF NOT EXISTS trigram_id INT UNSIGNED DEFAULT NULL
    COMMENT 'FK to snap_trigrams.id — NULL = normal post cover'");

// ── AJAX: REORDER ──────────────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'reorder') {
    header('Content-Type: application/json');
    $new_order = array_map('intval', $_POST['ids'] ?? []);
    if (empty($new_order)) {
        echo json_encode(['ok' => false, 'error' => 'No IDs']);
        exit;
    }
    $all_ids = $pdo->query(
        "SELECT id FROM snap_posts ORDER BY sort_order ASC, created_at DESC"
    )->fetchAll(PDO::FETCH_COLUMN);

    $page_set  = array_flip($new_order);
    $stripped  = array_values(array_filter($all_ids, fn($id) => !isset($page_set[$id])));
    $insert_at = count($stripped);
    foreach ($all_ids as $pos => $id) {
        if (isset($page_set[$id])) {
            $insert_at = count(array_filter(
                array_slice($all_ids, 0, $pos),
                fn($x) => !isset($page_set[$x])
            ));
            break;
        }
    }
    array_splice($stripped, $insert_at, 0, $new_order);

    $stmt = $pdo->prepare("UPDATE snap_posts SET sort_order = ? WHERE id = ?");
    foreach ($stripped as $pos => $id) {
        $stmt->execute([$pos + 1, $id]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ── AJAX: PUBLISH SINGLE ───────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'publish') {
    header('Content-Type: application/json');
    $id = (int)($_POST['post_id'] ?? 0);
    if (!$id) { echo json_encode(['ok' => false]); exit; }

    // Fetch trigram_id for this post.
    $row = $pdo->prepare("SELECT trigram_id FROM snap_posts WHERE id = ? LIMIT 1");
    $row->execute([$id]);
    $post = $row->fetch(PDO::FETCH_ASSOC);
    $tg_id = (int)($post['trigram_id'] ?? 0);

    if ($tg_id > 0) {
        // Route through the gate — may stay as queued if group is incomplete.
        $promoted = trigram_check_and_publish($pdo, $tg_id, $id);
        if ($promoted) {
            // Return the IDs of all three newly published posts so the UI can update them.
            $tg = $pdo->prepare("SELECT post_id_1, post_id_2, post_id_3 FROM snap_trigrams WHERE id = ?");
            $tg->execute([$tg_id]);
            $tg_row   = $tg->fetch(PDO::FETCH_ASSOC);
            $all_ids  = $tg_row ? [(int)$tg_row['post_id_1'], (int)$tg_row['post_id_2'], (int)$tg_row['post_id_3']] : [$id];
            echo json_encode(['ok' => true, 'promoted' => true, 'post_ids' => $all_ids]);
        } else {
            $ready = trigram_ready_count($pdo, $tg_id);
            echo json_encode(['ok' => true, 'promoted' => false, 'queued' => true, 'ready' => $ready]);
        }
    } else {
        $pdo->prepare("UPDATE snap_posts SET status = 'published' WHERE id = ?")->execute([$id]);
        if (threeacross_enabled($pdo)) {
            // 3-across on: a lone trailing post may need to fall back to queued.
            threeacross_settle($pdo);
            echo json_encode(['ok' => true, 'promoted' => true, 'post_ids' => [$id], 'settled' => true]);
        } else {
            echo json_encode(['ok' => true, 'promoted' => true, 'post_ids' => [$id]]);
        }
    }
    exit;
}

// ── AJAX: BULK PUBLISH ─────────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'bulk_publish') {
    header('Content-Type: application/json');
    $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
    if (empty($ids)) { echo json_encode(['ok' => false, 'count' => 0]); exit; }

    // Fetch trigram_id for each requested post.
    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, trigram_id FROM snap_posts WHERE id IN ($ph)");
    $stmt->execute(array_values($ids));
    $posts_meta = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $published_ids = [];
    $queued_ids    = [];
    $processed_tg  = []; // prevent double-processing a trigram group

    foreach ($posts_meta as $p) {
        $pid   = (int)$p['id'];
        $tg_id = (int)$p['trigram_id'];

        if ($tg_id > 0) {
            if (isset($processed_tg[$tg_id])) continue;
            $processed_tg[$tg_id] = true;
            $promoted = trigram_check_and_publish($pdo, $tg_id, $pid);
            if ($promoted) {
                $tg      = $pdo->prepare("SELECT post_id_1, post_id_2, post_id_3 FROM snap_trigrams WHERE id = ?");
                $tg->execute([$tg_id]);
                $tg_row  = $tg->fetch(PDO::FETCH_ASSOC);
                if ($tg_row) {
                    $published_ids[] = (int)$tg_row['post_id_1'];
                    $published_ids[] = (int)$tg_row['post_id_2'];
                    $published_ids[] = (int)$tg_row['post_id_3'];
                }
            } else {
                $queued_ids[] = $pid;
            }
        } else {
            $pdo->prepare("UPDATE snap_posts SET status = 'published' WHERE id = ?")->execute([$pid]);
            $published_ids[] = $pid;
        }
    }

    $settled = false;
    if (threeacross_enabled($pdo)) { threeacross_settle($pdo); $settled = true; }

    echo json_encode([
        'ok'           => true,
        'count'        => count($published_ids),
        'published_ids'=> $published_ids,
        'queued_ids'   => $queued_ids,
        'settled'      => $settled,
    ]);
    exit;
}

// ── AJAX: CREATE TRIGRAM (lock 3 selected posts into an L/M/R or T/M/B set) ──
// Selection order IS slot order: ids[0]=slot1 (L/T), ids[1]=slot2 (M), ids[2]=
// slot3 (R/B). Mirrors core/threeacross-api.php's threeacross/trigram route so
// the lighttable and the Unzucker/SUMNABATCH import build identical groups.
if (isset($_POST['action']) && $_POST['action'] === 'create_trigram') {
    header('Content-Type: application/json');
    $ids = array_values(array_filter(array_map('intval', $_POST['ids'] ?? [])));
    $orientation = trim($_POST['orientation'] ?? 'h');
    if (!in_array($orientation, ['h', 'v'], true)) $orientation = 'h';

    if (count($ids) !== 3 || count(array_unique($ids)) !== 3) {
        echo json_encode(['ok' => false, 'err' => 'Select exactly three different posts to lock into a trigram.']); exit;
    }
    list($pid1, $pid2, $pid3) = $ids;

    $ph  = implode(',', array_fill(0, 3, '?'));
    $chk = $pdo->prepare("SELECT COUNT(*) FROM snap_posts WHERE id IN ($ph)");
    $chk->execute($ids);
    if ((int)$chk->fetchColumn() !== 3) {
        echo json_encode(['ok' => false, 'err' => 'One or more selected posts no longer exist.']); exit;
    }
    $dup = $pdo->prepare("SELECT id FROM snap_posts WHERE id IN ($ph) AND trigram_id IS NOT NULL LIMIT 1");
    $dup->execute($ids);
    if ($dup->fetch()) {
        echo json_encode(['ok' => false, 'err' => 'One of those posts is already in a trigram — unlock it first.']); exit;
    }

    // Defensive schema guards (mirror core/threeacross-api.php) so a DB that
    // predates the group-trigram columns still works.
    $pdo->exec("ALTER TABLE snap_trigrams
        ADD COLUMN IF NOT EXISTS trigram_type ENUM('slice','group') NOT NULL DEFAULT 'slice'
        COMMENT 'slice=GD/Imagick cut; group=pre-sliced external import' AFTER id");
    $pdo->exec("ALTER TABLE snap_trigrams MODIFY source_path VARCHAR(500) NULL");
    $pdo->exec("ALTER TABLE snap_trigrams MODIFY cut_a SMALLINT UNSIGNED NULL");
    $pdo->exec("ALTER TABLE snap_trigrams MODIFY cut_b SMALLINT UNSIGNED NULL");

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO snap_trigrams
                (trigram_type, source_path, orientation, cut_a, cut_b, post_id_1, post_id_2, post_id_3)
            VALUES ('group', NULL, ?, NULL, NULL, ?, ?, ?)
        ")->execute([$orientation, $pid1, $pid2, $pid3]);
        $trigram_id = (int)$pdo->lastInsertId();

        $upd = $pdo->prepare("UPDATE snap_posts SET trigram_id = ? WHERE id = ?");
        $upd->execute([$trigram_id, $pid1]);
        $upd->execute([$trigram_id, $pid2]);
        $upd->execute([$trigram_id, $pid3]);

        // Make the three contiguous at the next clean row boundary so the set is
        // a valid 3-across row from the moment it's locked.
        $max_so     = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM snap_posts")->fetchColumn();
        $col_offset = (1 - ($max_so % 3) + 3) % 3;
        $start      = $max_so + ($col_offset === 0 ? 3 : $col_offset);
        $so = $pdo->prepare("UPDATE snap_posts SET sort_order = ? WHERE id = ?");
        $so->execute([$start,     $pid1]);
        $so->execute([$start + 1, $pid2]);
        $so->execute([$start + 2, $pid3]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'err' => 'Trigram creation failed: ' . $e->getMessage()]); exit;
    }

    // A trigram commits this blog to the 3-across grid — turn enforcement on so
    // lone trailing singles are held until they complete a row. (Flip off by hand
    // later if you remove your trigrams.)
    threeacross_set_enabled($pdo, true);
    threeacross_settle($pdo);

    echo json_encode(['ok' => true, 'trigram_id' => $trigram_id]); exit;
}

// ── AJAX: TOGGLE 3-ACROSS ENFORCEMENT ──────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'set_threeacross') {
    header('Content-Type: application/json');
    $on = (($_POST['on'] ?? '0') === '1');
    threeacross_set_enabled($pdo, $on);
    if ($on) threeacross_settle($pdo);   // turning it on may hold a lone trailing post
    echo json_encode(['ok' => true, 'enabled' => $on]);
    exit;
}

// ── AJAX: RANDOMIZE FEED ───────────────────────────────────────────────────
// Shuffle the PUBLISHED feed at the ROW level: trigrams stay glued (a whole row,
// in slot order); singles regroup into fresh rows of three; only the *position*
// of each set changes. Non-published posts keep their relative order, parked
// after the published block so the visible grid stays in complete rows.
if (isset($_POST['action']) && $_POST['action'] === 'randomize') {
    header('Content-Type: application/json');

    $pub = $pdo->query("
        SELECT p.id, p.trigram_id,
               CASE WHEN tg.post_id_1 = p.id THEN 1
                    WHEN tg.post_id_2 = p.id THEN 2
                    WHEN tg.post_id_3 = p.id THEN 3
                    ELSE 0 END AS slot
        FROM snap_posts p
        LEFT JOIN snap_trigrams tg ON tg.id = p.trigram_id
        WHERE p.status = 'published'
        ORDER BY CASE WHEN p.sort_order > 0 THEN 0 ELSE 1 END ASC,
                 p.sort_order ASC, p.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $units = []; $singles = []; $seen = [];
    foreach ($pub as $r) {
        $tg = (int)$r['trigram_id'];
        if ($tg > 0) {
            if (isset($seen[$tg])) continue;
            $seen[$tg] = true;
            $mem = array_values(array_filter($pub, fn($x) => (int)$x['trigram_id'] === $tg));
            usort($mem, fn($a, $b) => (int)$a['slot'] <=> (int)$b['slot']);
            $units[] = array_map(fn($m) => (int)$m['id'], $mem);
        } else {
            $singles[] = (int)$r['id'];
        }
    }
    // Singles into rows of three; any 1–2 leftover (enforcement off) ride as a
    // final partial unit so trigrams never misalign.
    foreach (array_chunk($singles, 3) as $chunk) $units[] = $chunk;
    shuffle($units);

    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare("UPDATE snap_posts SET sort_order = ? WHERE id = ?");
        $pos = 1;
        foreach ($units as $u) foreach ($u as $id) $upd->execute([$pos++, $id]);

        // Park non-published posts after the published block, order preserved.
        $rest = $pdo->query("
            SELECT id FROM snap_posts WHERE status <> 'published'
            ORDER BY CASE WHEN sort_order > 0 THEN 0 ELSE 1 END ASC,
                     sort_order ASC, created_at DESC
        ")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rest as $id) $upd->execute([$pos++, $id]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'err' => 'Randomize failed: ' . $e->getMessage()]); exit;
    }
    echo json_encode(['ok' => true]); exit;
}

// ── FETCH ALL POSTS ────────────────────────────────────────────────────────
$posts = $pdo->query("
    SELECT
        p.id           AS post_id,
        p.title,
        p.status,
        p.sort_order,
        p.trigram_id,
        p.created_at,
        i.img_thumb_square,
        i.img_file,
        (SELECT COUNT(*) FROM snap_post_images spi WHERE spi.post_id = p.id) AS image_count,
        CASE
            WHEN tg.post_id_1 = p.id THEN 1
            WHEN tg.post_id_2 = p.id THEN 2
            WHEN tg.post_id_3 = p.id THEN 3
            ELSE NULL
        END AS trigram_slot,
        tg.orientation AS trigram_orientation
    FROM snap_posts p
    LEFT JOIN snap_post_images pi ON pi.post_id = p.id AND pi.is_cover = 1
    LEFT JOIN snap_images i       ON i.id = pi.image_id
    LEFT JOIN snap_trigrams tg    ON tg.id = p.trigram_id
    ORDER BY p.sort_order ASC, p.created_at DESC
")->fetchAll();

$total       = count($posts);
$draft_count = count(array_filter($posts, fn($p) => $p['status'] === 'draft'));
$queued_count= count(array_filter($posts, fn($p) => $p['status'] === 'queued'));
$three_on    = threeacross_enabled($pdo);

$page_title = 'Grid Lighttable';
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row header-row--ruled">
        <h2>GRID LIGHTTABLE
            <span class="ltg-count-badge"><?php echo $total; ?> post<?php echo $total !== 1 ? 's' : ''; ?></span>
            <?php if ($draft_count > 0): ?>
            <span class="ltg-count-badge ltg-count-badge--draft"><?php echo $draft_count; ?> draft<?php echo $draft_count !== 1 ? 's' : ''; ?></span>
            <?php endif; ?>
            <?php if ($queued_count > 0): ?>
            <span class="ltg-count-badge ltg-count-badge--queued"><?php echo $queued_count; ?> queued</span>
            <?php endif; ?>
        </h2>
        <div class="ltg-toolbar">
            <span class="ltg-save-status" id="ltgSaveStatus"></span>
            <span class="ltg-trigram-lock" id="ltgTrigramLock" style="display:none;align-items:center;gap:6px;">
                <select id="ltgTrigramOrient" class="btn btn--sm" title="Trigram orientation" style="padding:3px 6px;">
                    <option value="h">Horizontal · L / M / R</option>
                    <option value="v">Vertical · T / M / B</option>
                </select>
                <button class="btn btn--sm" id="ltgLockTrigramBtn" onclick="ltgLockTrigram()" title="Lock the 3 selected posts into a trigram, in the order you ticked them">🔒 LOCK TRIGRAM</button>
            </span>
            <button class="btn btn--sm" id="ltgBulkPublishBtn" style="display:none;" onclick="ltgBulkPublish()">PUBLISH SELECTED</button>
            <button class="btn btn--sm" id="ltgRandomizeBtn" onclick="ltgRandomize()" title="Shuffle the feed — trigrams stay glued as a set, only their position moves">🎲 RANDOMIZE</button>
            <label class="ltg-col-label" title="3-across: hold a lone trailing post as queued until two more complete its row. Creating a trigram turns this on automatically.">
                <input type="checkbox" id="ltgThreeAcross" <?php echo $three_on ? 'checked' : ''; ?>> 3-across
            </label>
            <label class="ltg-col-label" title="Tile size">
                🔍 <input type="range" id="ltgZoomSlider" min="240" max="900" value="900" step="30">
            </label>
            <a href="smack-post-gram.php" class="btn btn--sm">+ NEW POST</a>
        </div>
    </div>

    <?php if (empty($posts)): ?>
    <div class="box box--no-header" style="text-align:center;padding:60px 20px;color:var(--text-secondary);">
        <p>No posts yet. This lighttable is for Grid (GramOfSmack) installs.</p>
        <a href="smack-post-gram.php" class="btn">CREATE FIRST POST</a>
    </div>
    <?php else: ?>

    <div class="box box--no-header" style="padding:10px 16px;font-size:0.78rem;color:var(--text-secondary);line-height:1.5;">
        Drag tiles to reorder your feed. <strong style="color:var(--text-primary)">Trigram</strong> tiles are outlined —
        dragging any one tile moves all three together.
        A warning appears if the three tiles fall out of a valid row or column.
        <strong style="color:var(--accent,#c8a96e)">Queued</strong> posts wait for all 3 trigram slots before going live.
    </div>

    <div class="ltg-grid" id="ltgGrid">
    <?php foreach ($posts as $post):
        $thumb      = $post['img_thumb_square'] ?: $post['img_file'];
        $is_draft   = ($post['status'] === 'draft');
        $is_queued  = ($post['status'] === 'queued');
        $tg_id      = (int)$post['trigram_id'];
        $tg_slot    = (int)$post['trigram_slot'];
        $tg_orient  = $post['trigram_orientation'] ?? 'h';
        $img_count  = (int)$post['image_count'];

        // Trigram cover: show the panorama slice in the lighttable tile too, so the
        // arrangement preview matches what the live grid will render.
        if ($tg_id > 0 && $tg_slot > 0) {
            $tg_label = ($tg_orient === 'v')
                ? (['T','M','B'][$tg_slot - 1] ?? '')
                : (['L','M','R'][$tg_slot - 1] ?? '');
            if ($tg_label !== '') {
                $tg_rel = 'trigrams/trigram-' . $tg_id . '-' . $tg_label . '.jpg';
                if (is_file(__DIR__ . '/' . $tg_rel)) $thumb = $tg_rel;
            }
        }

        $slot_labels_h = [1 => 'L', 2 => 'M', 3 => 'R'];
        $slot_labels_v = [1 => 'T', 2 => 'M', 3 => 'B'];
        $slot_label = $tg_slot ? ($tg_orient === 'v' ? $slot_labels_v[$tg_slot] : $slot_labels_h[$tg_slot]) : '';

        $tile_classes = 'ltg-tile';
        if ($is_draft)   $tile_classes .= ' ltg-tile--draft';
        if ($is_queued)  $tile_classes .= ' ltg-tile--queued';
        if ($tg_id > 0)  $tile_classes .= ' ltg-tile--trigram';
    ?>
    <div class="<?php echo $tile_classes; ?>"
         data-post-id="<?php echo $post['post_id']; ?>"
         data-trigram-id="<?php echo $tg_id; ?>"
         data-trigram-slot="<?php echo $tg_slot; ?>"
         data-status="<?php echo htmlspecialchars($post['status']); ?>">

        <?php if ($thumb): ?>
        <img src="<?php echo htmlspecialchars($thumb); ?>"
             alt="<?php echo htmlspecialchars($post['title']); ?>"
             draggable="false"
             loading="lazy">
        <?php else: ?>
        <div class="ltg-tile-no-img">
            <span><?php echo htmlspecialchars($post['title'] ?: '(no title)'); ?></span>
        </div>
        <?php endif; ?>

        <?php if ($is_draft): ?>
        <div class="ltg-badge ltg-badge--draft">DRAFT</div>
        <?php endif; ?>

        <?php if ($is_queued): ?>
        <div class="ltg-badge ltg-badge--queued">QUEUED</div>
        <?php endif; ?>

        <?php if ($img_count > 1): ?>
        <div class="ltg-badge ltg-badge--carousel">⧉<?php echo $img_count; ?></div>
        <?php endif; ?>

        <?php if ($tg_slot > 0): ?>
        <div class="ltg-badge ltg-badge--trigram" title="Locked trigram — <?php echo $slot_label; ?> slot">🔒 <?php echo $slot_label; ?></div>
        <?php endif; ?>

        <label class="ltg-select-corner" title="Select for locking / publishing">
            <input type="checkbox" class="ltg-select-cb" data-post-id="<?php echo $post['post_id']; ?>">
        </label>

        <div class="ltg-tile-overlay">
            <span class="ltg-tile-title"><?php echo htmlspecialchars($post['title'] ?: '(no title)'); ?></span>
            <div class="ltg-tile-actions">
                <?php if ($is_draft || $is_queued): ?>
                <button class="ltg-btn-publish"
                        data-post-id="<?php echo $post['post_id']; ?>"
                        data-trigram-id="<?php echo $tg_id; ?>"
                        <?php if ($is_queued): ?>title="Waiting for more trigram posts"<?php endif; ?>>
                    <?php echo $is_queued ? 'QUEUED' : 'PUBLISH'; ?>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="ltg-warn-badge" style="display:none;">⚠ TRIGRAM</div>
    </div>
    <?php endforeach; ?>
    </div>

    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.3/Sortable.min.js"></script>
<script>
(function () {
    const grid     = document.getElementById('ltgGrid');
    const status   = document.getElementById('ltgSaveStatus');
    const bulkBtn  = document.getElementById('ltgBulkPublishBtn');
    if (!grid) return;

    // ── Zoom slider (shrinks grid width, keeps 3 cols) ─────────────────────
    const zoomSlider = document.getElementById('ltgZoomSlider');
    function applyZoom(w) {
        grid.style.setProperty('--ltg-grid-width', w + 'px');
    }
    const savedZoom = parseInt(localStorage.getItem('ltg_zoom')) || 900;
    zoomSlider.value = savedZoom;
    applyZoom(savedZoom);
    zoomSlider.addEventListener('input', function () {
        const w = parseInt(this.value);
        applyZoom(w);
        localStorage.setItem('ltg_zoom', w);
    });

    // ── Sortable (core only — no MultiDrag plugin) ─────────────────────────
    // Drag one tile; onEnd snaps its trigram siblings into position beside it.
    Sortable.create(grid, {
        animation:       150,
        ghostClass:      'ltg-tile--ghost',
        chosenClass:     'ltg-tile--chosen',
        dragClass:       'ltg-tile--drag',
        filter:          '.ltg-select-cb, .ltg-btn-publish',
        preventOnFilter: false,

        onEnd: function (evt) {
            const tile = evt.item;
            const tgId = tile.dataset.trigramId;

            if (tgId && tgId !== '0') {
                // Pull the other two trigram tiles out of the DOM, then
                // reinsert all three in slot order around the dropped tile.
                const bySlot = [...grid.querySelectorAll(`.ltg-tile[data-trigram-id="${tgId}"]`)]
                    .sort((a, b) => parseInt(a.dataset.trigramSlot) - parseInt(b.dataset.trigramSlot));

                if (bySlot.length === 3) {
                    const [s1, s2, s3] = bySlot;
                    // Remove siblings (not the dragged tile — Sortable already placed it)
                    bySlot.forEach(t => { if (t !== tile) t.remove(); });

                    // Reinsert in slot order relative to the dropped tile
                    if (tile === s1) {
                        tile.after(s2);  s2.after(s3);
                    } else if (tile === s2) {
                        tile.before(s1); tile.after(s3);
                    } else {
                        tile.before(s2); s2.before(s1);
                    }
                }
            }

            saveOrder();
            checkTrigramAlignment();
        }
    });

    // ── Save order ─────────────────────────────────────────────────────────
    function saveOrder() {
        const ids = [...grid.querySelectorAll('.ltg-tile')].map(t => t.dataset.postId);
        setStatus('saving');
        const fd = new FormData();
        fd.append('action', 'reorder');
        ids.forEach(id => fd.append('ids[]', id));
        fetch('smack-lt-gram.php', {
            method:  'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body:    fd
        })
        .then(r => r.json())
        .then(d => setStatus(d.ok ? 'saved' : 'error'))
        .catch(() => setStatus('error'));
    }

    function setStatus(state) {
        const map = { saving: 'Saving…', saved: 'Saved ✓', error: 'Error — reload and retry' };
        status.textContent   = map[state] || '';
        status.dataset.state = state;
        if (state === 'saved') setTimeout(() => { if (status.dataset.state === 'saved') status.textContent = ''; }, 2500);
    }

    // ── Trigram alignment check ────────────────────────────────────────────
    function checkTrigramAlignment() {
        const tiles = [...grid.querySelectorAll('.ltg-tile')];

        // Clear warnings
        tiles.forEach(t => {
            t.classList.remove('ltg-tile--warn');
            const wb = t.querySelector('.ltg-warn-badge');
            if (wb) wb.style.display = 'none';
        });

        // Group by trigram_id
        const groups = {};
        tiles.forEach((tile, idx) => {
            const tgId = tile.dataset.trigramId;
            if (!tgId || tgId === '0') return;
            if (!groups[tgId]) groups[tgId] = [];
            groups[tgId].push({ tile, idx });
        });

        for (const tgId in groups) {
            const group = groups[tgId];
            if (group.length !== 3) continue;

            const idxs = group.map(g => g.idx).sort((a, b) => a - b);
            const [a, b, c] = idxs;

            // Valid horizontal row: consecutive, starts at column 0 (a % 3 === 0)
            const isHRow = (b === a + 1 && c === a + 2 && a % 3 === 0);
            // Valid vertical column: same column, consecutive rows
            const isVCol = (b === a + 3 && c === a + 6 && a % 3 === b % 3);

            if (!isHRow && !isVCol) {
                group.forEach(g => {
                    g.tile.classList.add('ltg-tile--warn');
                    const wb = g.tile.querySelector('.ltg-warn-badge');
                    if (wb) wb.style.display = 'block';
                });
            }
        }
    }

    // ── Publish single ─────────────────────────────────────────────────────
    grid.addEventListener('click', function (e) {
        const btn = e.target.closest('.ltg-btn-publish');
        if (!btn) return;

        const postId = btn.dataset.postId;
        const tgId   = btn.dataset.trigramId;
        const tile   = btn.closest('.ltg-tile');

        // Queued tiles: try to promote — the gate decides.
        btn.textContent = '…';
        btn.disabled = true;
        const fd = new FormData();
        fd.append('action',  'publish');
        fd.append('post_id', postId);
        fetch('smack-lt-gram.php', {
            method:  'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body:    fd
        })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) { btn.textContent = 'ERR'; return; }

            // 3-across may have re-queued a lone trailing post elsewhere — reload
            // so the whole grid reflects the authoritative server state.
            if (d.settled) { location.reload(); return; }

            if (d.promoted) {
                // One or more posts promoted — update all affected tiles.
                const promotedIds = new Set(d.post_ids || [parseInt(postId)]);
                grid.querySelectorAll('.ltg-tile').forEach(t => {
                    const tid = parseInt(t.dataset.postId);
                    if (!promotedIds.has(tid)) return;
                    t.classList.remove('ltg-tile--draft', 'ltg-tile--queued');
                    t.dataset.status = 'published';
                    t.querySelector('.ltg-badge--draft')?.remove();
                    t.querySelector('.ltg-badge--queued')?.remove();
                    t.querySelector('.ltg-btn-publish')?.remove();
                });
                updateBulkBtn();
            } else if (d.queued) {
                // Still waiting — update badge to show progress.
                const ready = d.ready || 1;
                const badge = tile.querySelector('.ltg-badge--queued');
                if (badge) badge.textContent = `QUEUED ${ready}/3`;
                btn.textContent = 'QUEUED';
                btn.disabled    = false;
                tile.dataset.status = 'queued';
            }
        })
        .catch(() => { btn.textContent = 'ERR'; btn.disabled = false; });
    });

    // ── Bulk publish ───────────────────────────────────────────────────────
    let trigramTickOrder = [];   // post-ids in the order their boxes were ticked

    grid.addEventListener('change', function (e) {
        if (!e.target.classList.contains('ltg-select-cb')) return;
        const id = e.target.dataset.postId;
        if (e.target.checked) {
            if (!trigramTickOrder.includes(id)) trigramTickOrder.push(id);
        } else {
            trigramTickOrder = trigramTickOrder.filter(x => x !== id);
        }
        updateBulkBtn();
        renderSelectionBadges();
        persistSelection();
    });

    function updateBulkBtn() {
        const checked = grid.querySelectorAll('.ltg-select-cb:checked');
        bulkBtn.style.display = checked.length > 0 ? '' : 'none';
        bulkBtn.textContent   = `PUBLISH SELECTED (${checked.length})`;

        // The lock control appears only when exactly 3 posts are selected.
        const lock = document.getElementById('ltgTrigramLock');
        if (lock) lock.style.display = (checked.length === 3) ? 'inline-flex' : 'none';
    }

    window.ltgBulkPublish = function () {
        const checked = [...grid.querySelectorAll('.ltg-select-cb:checked')];
        if (!checked.length) return;
        const ids = checked.map(cb => cb.dataset.postId);
        bulkBtn.disabled    = true;
        bulkBtn.textContent = 'Publishing…';
        const fd = new FormData();
        fd.append('action', 'bulk_publish');
        ids.forEach(id => fd.append('ids[]', id));
        fetch('smack-lt-gram.php', {
            method:  'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body:    fd
        })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) { bulkBtn.textContent = 'ERROR'; bulkBtn.disabled = false; return; }

            if (d.settled) { location.reload(); return; }   // 3-across re-settled rows

            const publishedSet = new Set(d.published_ids || []);
            const queuedSet    = new Set(d.queued_ids    || []);

            grid.querySelectorAll('.ltg-tile').forEach(tile => {
                const tid = parseInt(tile.dataset.postId);
                if (publishedSet.has(tid)) {
                    tile.classList.remove('ltg-tile--draft', 'ltg-tile--queued');
                    tile.dataset.status = 'published';
                    tile.querySelector('.ltg-badge--draft')?.remove();
                    tile.querySelector('.ltg-badge--queued')?.remove();
                    tile.querySelector('.ltg-btn-publish')?.remove();
                    tile.querySelector('.ltg-select-cb').checked = false;
                } else if (queuedSet.has(tid)) {
                    tile.classList.add('ltg-tile--queued');
                    tile.dataset.status = 'queued';
                    tile.querySelector('.ltg-select-cb').checked = false;
                }
            });

            // Posts that went live/queued leave the tick order + lose their badge.
            trigramTickOrder = trigramTickOrder.filter(id =>
                !publishedSet.has(parseInt(id)) && !queuedSet.has(parseInt(id)));
            renderSelectionBadges();
            persistSelection();
            bulkBtn.style.display = 'none';
            bulkBtn.disabled      = false;
            updateBulkBtn();
        })
        .catch(() => { bulkBtn.textContent = 'ERROR'; bulkBtn.disabled = false; });
    };

    // ── Lock 3 selected posts into a trigram ───────────────────────────────
    // Slot order = the order the boxes were ticked (first tick = L / T).
    window.ltgLockTrigram = function () {
        const checkedIds = [...grid.querySelectorAll('.ltg-select-cb:checked')].map(cb => cb.dataset.postId);
        if (checkedIds.length !== 3) return;
        let ordered = trigramTickOrder.filter(id => checkedIds.includes(id));
        if (ordered.length !== 3) ordered = checkedIds;   // fallback: reading order

        const orient = document.getElementById('ltgTrigramOrient').value;
        const btn    = document.getElementById('ltgLockTrigramBtn');
        btn.disabled = true; btn.textContent = 'Locking…';

        const fd = new FormData();
        fd.append('action', 'create_trigram');
        fd.append('orientation', orient);
        ordered.forEach(id => fd.append('ids[]', id));

        fetch('smack-lt-gram.php', {
            method:  'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body:    fd
        })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) {
                alert(d.err || 'Could not lock trigram.');
                btn.disabled = false; btn.textContent = '🔒 LOCK TRIGRAM';
                return;
            }
            // Reload so the set renders with its outline, L/M/R badges, and lock-drag.
            clearPersistedSelection();
            location.reload();
        })
        .catch(() => {
            alert('Network error while locking the trigram.');
            btn.disabled = false; btn.textContent = '🔒 LOCK TRIGRAM';
        });
    };

    // ── Selection persistence + live L/M/R order badges ─────────────────────
    // The slot a ticked post takes when locked = the order you tick it (1st =
    // L/T, 2nd = M, 3rd = R/B). Show that letter live on each ticked tile, and
    // remember the whole selection across reloads so a refresh never wipes it.
    const LTG_SEL_KEY = 'ltg_selection';
    const orientSel   = document.getElementById('ltgTrigramOrient');

    function slotLabel(index) {
        const vertical = (orientSel && orientSel.value === 'v');
        const h  = ['L', 'M', 'R'];
        const v  = ['T', 'M', 'B'];
        return (index >= 0 && index < 3) ? (vertical ? v[index] : h[index]) : String(index + 1);
    }

    function renderSelectionBadges() {
        grid.querySelectorAll('.ltg-tile').forEach(tile => {
            const cb    = tile.querySelector('.ltg-select-cb');
            let   badge = tile.querySelector('.ltg-sel-order');
            const pos   = (cb && cb.checked) ? trigramTickOrder.indexOf(cb.dataset.postId) : -1;
            if (pos === -1) { if (badge) badge.remove(); return; }
            if (!badge) {
                badge = document.createElement('div');
                badge.className = 'ltg-sel-order';
                tile.appendChild(badge);
            }
            badge.textContent = slotLabel(pos);
        });
    }

    function persistSelection() {
        try {
            localStorage.setItem(LTG_SEL_KEY, JSON.stringify({
                order:  trigramTickOrder,
                orient: orientSel ? orientSel.value : 'h'
            }));
        } catch (e) {}
    }

    function clearPersistedSelection() {
        try { localStorage.removeItem(LTG_SEL_KEY); } catch (e) {}
    }

    function restoreSelection() {
        let saved = null;
        try { saved = JSON.parse(localStorage.getItem(LTG_SEL_KEY) || 'null'); } catch (e) {}
        if (!saved || !Array.isArray(saved.order)) return;
        if (orientSel && (saved.orient === 'h' || saved.orient === 'v')) orientSel.value = saved.orient;
        const present = new Set([...grid.querySelectorAll('.ltg-select-cb')].map(cb => cb.dataset.postId));
        trigramTickOrder = saved.order.filter(id => present.has(id));
        trigramTickOrder.forEach(id => {
            const cb = grid.querySelector('.ltg-select-cb[data-post-id="' + id + '"]');
            if (cb) cb.checked = true;
        });
        updateBulkBtn();
        renderSelectionBadges();
    }

    if (orientSel) orientSel.addEventListener('change', function () {
        renderSelectionBadges();
        persistSelection();
    });

    restoreSelection();

    // ── Randomize the feed (trigrams stay glued; only positions move) ───────
    window.ltgRandomize = function () {
        if (!confirm('Shuffle the whole feed? Trigrams stay glued as a set — only their position changes.')) return;
        const btn = document.getElementById('ltgRandomizeBtn');
        if (btn) { btn.disabled = true; btn.textContent = 'Shuffling…'; }
        const fd = new FormData(); fd.append('action', 'randomize');
        fetch('smack-lt-gram.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
            .then(r => r.json())
            .then(d => {
                if (!d.ok) { alert(d.err || 'Randomize failed.'); if (btn) { btn.disabled = false; btn.textContent = '🎲 RANDOMIZE'; } return; }
                location.reload();
            })
            .catch(() => { alert('Network error while randomizing.'); if (btn) { btn.disabled = false; btn.textContent = '🎲 RANDOMIZE'; } });
    };

    // ── 3-across enforcement toggle ─────────────────────────────────────────
    const threeCb = document.getElementById('ltgThreeAcross');
    if (threeCb) threeCb.addEventListener('change', function () {
        const fd = new FormData();
        fd.append('action', 'set_threeacross');
        fd.append('on', this.checked ? '1' : '0');
        fetch('smack-lt-gram.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
            .then(r => r.json())
            .then(() => location.reload())
            .catch(() => location.reload());
    });

    // Run alignment check on load
    checkTrigramAlignment();
})();
</script>

<style>
/* ── Grid container ────────────────────────────────────────────────────── */
.ltg-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 3px;
    max-width: var(--ltg-grid-width, 900px);
    margin: 16px auto;
    padding: 0 16px;
}

/* ── Tile base ─────────────────────────────────────────────────────────── */
.ltg-tile {
    position:    relative;
    aspect-ratio: 1;
    overflow:    hidden;
    background:  var(--bg-secondary, #1a1a1a);
    cursor:      grab;
    user-select: none;
}
.ltg-tile:active { cursor: grabbing; }
.ltg-tile img {
    width:       100%;
    height:      100%;
    object-fit:  cover;
    display:     block;
    pointer-events: none;
}

/* ── No-image placeholder ──────────────────────────────────────────────── */
.ltg-tile-no-img {
    width:       100%;
    height:      100%;
    display:     flex;
    align-items: center;
    justify-content: center;
    padding:     8px;
    text-align:  center;
    font-size:   0.72rem;
    color:       var(--text-secondary, #666);
    line-height: 1.3;
}

/* ── States ────────────────────────────────────────────────────────────── */
.ltg-tile--draft          { opacity: 0.65; }
.ltg-tile--queued         { opacity: 0.80; outline: 2px dashed var(--accent, #c8a96e); outline-offset: -2px; }
.ltg-tile--trigram        { outline: 3px solid var(--accent, #c8a96e); outline-offset: -3px; }
/* Ticked tiles get a clear ring so you can see your selection before locking. */
.ltg-tile:has(.ltg-select-cb:checked) { outline: 3px solid #46c46a; outline-offset: -3px; }
.ltg-tile--queued.ltg-tile--trigram { outline: 2px dashed var(--accent, #c8a96e); outline-offset: -2px; }
.ltg-tile--warn           { outline: 2px solid #e05a5a !important; outline-offset: -2px; }
.ltg-tile--ghost          { opacity: 0.35; }
.ltg-tile--chosen         { outline: 2px solid var(--accent, #c8a96e); }

/* ── Badges ────────────────────────────────────────────────────────────── */
.ltg-badge {
    position:    absolute;
    font-size:   0.65rem;
    font-weight: 700;
    letter-spacing: 0.5px;
    padding:     2px 5px;
    line-height: 1;
    pointer-events: none;
}
.ltg-badge--draft    { top: 5px; left: 5px;  background: #e08030; color: #000; }
.ltg-badge--queued   { top: 5px; left: 5px;  background: var(--accent, #c8a96e); color: #000; }
.ltg-badge--carousel { top: 38px; right: 6px; background: rgba(0,0,0,.65); color: #fff; }

/* Live selection-order disc: which slot (L/M/R or T/M/B) a ticked tile will
   take when locked. Centred + bold so the order is unmistakable before you
   hit LOCK TRIGRAM. */
.ltg-sel-order {
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    min-width: 40px; height: 40px;
    padding: 0 8px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 999px;
    background: var(--accent, #0095f6);
    color: #fff;
    font-size: 20px; font-weight: 800; line-height: 1;
    box-shadow: 0 2px 10px rgba(0,0,0,.55);
    pointer-events: none;
    z-index: 6;
}
/* Locked-trigram slot label (🔒 L / M / R) — big and unmissable so the group
   reads as locked and you can see which tile is left / middle / right. */
.ltg-badge--trigram  {
    bottom: 6px; right: 6px;
    font-size: 0.95rem; font-weight: 800; padding: 4px 10px;
    background: var(--accent, #c8a96e); color: #000;
    border-radius: 3px; box-shadow: 0 1px 5px rgba(0,0,0,.5);
}

/* ── Warning badge ─────────────────────────────────────────────────────── */
.ltg-warn-badge {
    position:    absolute;
    bottom:      5px;
    left:        5px;
    background:  #e05a5a;
    color:       #fff;
    font-size:   0.6rem;
    font-weight: 700;
    padding:     2px 5px;
    letter-spacing: 0.5px;
    pointer-events: none;
}

/* ── Hover overlay ─────────────────────────────────────────────────────── */
.ltg-tile-overlay {
    position:   absolute;
    inset:      0;
    background: rgba(0,0,0,.55);
    display:    flex;
    flex-direction: column;
    justify-content: flex-end;
    padding:    8px;
    opacity:    0;
    transition: opacity .15s;
    /* The overlay is a visual-only hover layer. WITHOUT this it covered the
       whole tile at pointer-events:auto even while invisible (opacity:0), so it
       swallowed every click and drag-grab on the tile ("boxes hard to click").
       Let pointer events pass through to the tile; only the action controls
       below opt back in. */
    pointer-events: none;
}
.ltg-tile:hover .ltg-tile-overlay { opacity: 1; }
.ltg-tile-title {
    font-size:   0.7rem;
    color:       #fff;
    line-height: 1.3;
    margin-bottom: 6px;
    overflow:    hidden;
    display:     -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}
.ltg-tile-actions {
    display:     flex;
    align-items: center;
    gap:         6px;
    /* Re-enable clicks on the controls the overlay's pointer-events:none
       disabled (publish button + select checkbox). */
    pointer-events: auto;
}

/* ── Tile buttons ──────────────────────────────────────────────────────── */
.ltg-btn-publish {
    background:  var(--accent, #c8a96e);
    color:       #000;
    border:      none;
    font-size:   0.65rem;
    font-weight: 700;
    padding:     3px 8px;
    cursor:      pointer;
    letter-spacing: 0.5px;
    flex-shrink: 0;
}
.ltg-btn-publish:hover { filter: brightness(1.15); }
.ltg-btn-publish:disabled { opacity: 0.6; cursor: default; }
.ltg-select-label {
    display:     flex;
    align-items: center;
    cursor:      pointer;
    margin-left: auto;
}
/* Always-visible select target in the tile corner — a big circular hit area so
   it's easy to click without hunting for a tiny box hidden behind hover. */
.ltg-select-corner {
    position: absolute; top: 6px; right: 6px; z-index: 3;
    width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: rgba(0,0,0,.5); cursor: pointer;
}
.ltg-select-corner:hover { background: rgba(0,0,0,.72); }
.ltg-select-cb { width: 18px; height: 18px; cursor: pointer; accent-color: var(--accent, #c8a96e); }

/* ── Column slider ─────────────────────────────────────────────────────── */
.ltg-col-label {
    display:     flex;
    align-items: center;
    gap:         5px;
    font-size:   0.75rem;
    color:       var(--text-secondary);
    cursor:      default;
    white-space: nowrap;
}
.ltg-col-label input[type=range] {
    width:  70px;
    cursor: pointer;
    accent-color: var(--accent, #c8a96e);
}

/* ── Toolbar + count badges ────────────────────────────────────────────── */
.ltg-toolbar {
    display:     flex;
    align-items: center;
    gap:         10px;
}
.ltg-count-badge {
    display:     inline-block;
    font-size:   0.72rem;
    font-weight: 400;
    color:       var(--text-secondary, #888);
    margin-left: 10px;
    letter-spacing: 0.5px;
}
.ltg-count-badge--draft  { color: #e08030; }
.ltg-count-badge--queued { color: var(--accent, #c8a96e); }
.ltg-save-status {
    font-size:  0.75rem;
    color:      var(--text-secondary, #888);
    min-width:  80px;
    text-align: right;
}
.ltg-save-status[data-state="saved"]  { color: #5aaa5a; }
.ltg-save-status[data-state="error"]  { color: #e05a5a; }
.ltg-save-status[data-state="saving"] { color: var(--text-secondary, #888); }
</style>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
