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
        echo json_encode(['ok' => true, 'promoted' => true, 'post_ids' => [$id]]);
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

    echo json_encode([
        'ok'           => true,
        'count'        => count($published_ids),
        'published_ids'=> $published_ids,
        'queued_ids'   => $queued_ids,
    ]);
    exit;
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
            <button class="btn btn--sm" id="ltgBulkPublishBtn" style="display:none;" onclick="ltgBulkPublish()">PUBLISH SELECTED</button>
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
        <div class="ltg-badge ltg-badge--trigram"><?php echo $slot_label; ?></div>
        <?php endif; ?>

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
                <label class="ltg-select-label" title="Select">
                    <input type="checkbox" class="ltg-select-cb" data-post-id="<?php echo $post['post_id']; ?>">
                </label>
            </div>
        </div>

        <div class="ltg-warn-badge" style="display:none;">⚠ TRIGRAM</div>
    </div>
    <?php endforeach; ?>
    </div>

    <?php endif; ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.3/Sortable.min.js"></script>
<script>
(function () {
    const grid     = document.getElementById('ltgGrid');
    const status   = document.getElementById('ltgSaveStatus');
    const bulkBtn  = document.getElementById('ltgBulkPublishBtn');
    if (!grid) return;

    // ── Group-move helper ──────────────────────────────────────────────────
    // Returns all tile elements belonging to the same trigram group as tile.
    function getTrigramGroup(tile) {
        const tgId = tile.dataset.trigramId;
        if (!tgId || tgId === '0') return [tile];
        return [...grid.querySelectorAll(`.ltg-tile[data-trigram-id="${tgId}"]`)];
    }

    // ── Sortable init ──────────────────────────────────────────────────────
    Sortable.create(grid, {
        animation:     150,
        ghostClass:    'ltg-tile--ghost',
        chosenClass:   'ltg-tile--chosen',
        dragClass:     'ltg-tile--drag',

        // When drag starts on a trigram tile, also visually mark its siblings.
        onStart: function (evt) {
            getTrigramGroup(evt.item).forEach(t => {
                if (t !== evt.item) t.classList.add('ltg-tile--group-moving');
            });
        },

        onEnd: function (evt) {
            // Move the other two trigram tiles to follow the dragged one.
            const moved = evt.item;
            const group = getTrigramGroup(moved);
            if (group.length === 3) {
                // Sort group by slot so we always place L, M, R in order.
                group.sort((a, b) => (parseInt(a.dataset.trigramSlot) || 0) - (parseInt(b.dataset.trigramSlot) || 0));
                const movedSlot  = parseInt(moved.dataset.trigramSlot) || 1;
                const movedIndex = [...grid.children].indexOf(moved);

                // Place the other two relative to the dragged tile's new position.
                group.forEach((tile, i) => {
                    tile.classList.remove('ltg-tile--group-moving');
                    if (tile === moved) return;
                    const slotOffset = parseInt(tile.dataset.trigramSlot) - movedSlot;
                    const targetIdx  = movedIndex + slotOffset;
                    const tiles      = [...grid.children];
                    if (targetIdx >= 0 && targetIdx < tiles.length) {
                        if (slotOffset > 0) {
                            tiles[targetIdx].after(tile);
                        } else {
                            tiles[Math.max(0, targetIdx)].before(tile);
                        }
                    } else if (targetIdx < 0) {
                        grid.prepend(tile);
                    } else {
                        grid.append(tile);
                    }
                });
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
    grid.addEventListener('change', function (e) {
        if (e.target.classList.contains('ltg-select-cb')) updateBulkBtn();
    });

    function updateBulkBtn() {
        const checked = grid.querySelectorAll('.ltg-select-cb:checked');
        bulkBtn.style.display = checked.length > 0 ? '' : 'none';
        bulkBtn.textContent   = `PUBLISH SELECTED (${checked.length})`;
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

            bulkBtn.style.display = 'none';
            bulkBtn.disabled      = false;
            updateBulkBtn();
        })
        .catch(() => { bulkBtn.textContent = 'ERROR'; bulkBtn.disabled = false; });
    };

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
    max-width: 900px;
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
.ltg-tile--trigram        { outline: 2px solid var(--accent, #c8a96e); outline-offset: -2px; }
.ltg-tile--queued.ltg-tile--trigram { outline: 2px dashed var(--accent, #c8a96e); outline-offset: -2px; }
.ltg-tile--warn           { outline: 2px solid #e05a5a !important; outline-offset: -2px; }
.ltg-tile--ghost          { opacity: 0.35; }
.ltg-tile--chosen         { outline: 2px solid var(--accent, #c8a96e); }
.ltg-tile--group-moving   { outline: 2px dashed #fff; outline-offset: -2px; opacity: 0.7; }

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
.ltg-badge--carousel { top: 5px; right: 5px; background: rgba(0,0,0,.65); color: #fff; }
.ltg-badge--trigram  { bottom: 5px; right: 5px; background: var(--accent, #c8a96e); color: #000; }

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
.ltg-select-cb { width: 14px; height: 14px; cursor: pointer; accent-color: var(--accent, #c8a96e); }

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
