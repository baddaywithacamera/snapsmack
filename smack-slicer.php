<?php
/**
 * SNAPSMACK - Panorama Slicer (Triptych + Trigram-cover)
 *
 * GramOfSmack-mode tool. Drop one wide (or tall) source image, set two cut
 * points (default even thirds), and slice it into THREE square images. Two
 * modes, chosen at the top of the page:
 *
 *   • TRIPTYCH   — three NEW draft single-image posts. Each square slice is
 *                  both the post image and its grid cover. Arrange them in the
 *                  Grid Lighttable, then publish so the row reads as one panorama.
 *
 *   • COVER SLICES (Trigram) — the slices become grid COVERS for three EXISTING
 *                  posts you pick. The posts keep their own carousels; only the
 *                  grid tile shows the slice. Writes a snap_trigrams row and sets
 *                  snap_posts.trigram_id on the three posts.
 *
 * Both flows are the spec from _spec/the-grid.md (Triptych §, Trigrams §).
 * Slicing engine: core/trigram-slicer.php (trigram_slice_image). Triptych
 * slices land in img_uploads/YYYY/MM/; trigram covers land in trigrams/.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once 'core/auth-smack.php';
require_once 'core/trigram-slicer.php';
require_once 'core/palette-extract.php';

set_time_limit(600);
ini_set('memory_limit', '512M');

$settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
$settings      = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$jpeg_q  = (int)($settings['jpeg_quality'] ?? 88);
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$msg = '';

/**
 * Generate t_ / a_ 400² thumbnails for a square 1080 slice. Returns
 * [square_rel, aspect_rel] (both the same square for a 1:1 source) or [null,null].
 */
$make_thumbs = function (string $abs, string $thumb_dir, string $rel_dir, string $base) {
    $src = @imagecreatefromjpeg($abs);
    if (!$src) return [null, null];
    $thumb = imagecreatetruecolor(400, 400);
    imagecopyresampled($thumb, $src, 0, 0, 0, 0, 400, 400, 1080, 1080);
    $t = 't_' . $base . '.jpg';
    $a = 'a_' . $base . '.jpg';
    imagejpeg($thumb, $thumb_dir . '/' . $t, 82);
    imagejpeg($thumb, $thumb_dir . '/' . $a, 82);
    imagedestroy($thumb);
    imagedestroy($src);
    return [$rel_dir . '/thumbs/' . $t, $rel_dir . '/thumbs/' . $a];
};

// =============================================================================
// POST HANDLER
// =============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['source']['tmp_name'])) {

    $mode        = (($_POST['mode'] ?? 'triptych') === 'trigram') ? 'trigram' : 'triptych';
    $orientation = (($_POST['orientation'] ?? 'h') === 'v') ? 'v' : 'h';
    $frac_a      = (float)($_POST['cut_a_frac'] ?? 0);
    $frac_b      = (float)($_POST['cut_b_frac'] ?? 0);

    if ($_FILES['source']['error'] !== UPLOAD_ERR_OK) {
        $msg = 'UPLOAD_FAILURE: error code ' . $_FILES['source']['error'];
    } else {
        $tmp_name = $_FILES['source']['tmp_name'];
        $mime     = mime_content_type($tmp_name);

        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
            $msg = 'UNSUPPORTED_TYPE: ' . $mime;
        } else {
            // Persist the source into the month's upload tree (under sources/) so
            // the engine has a stable path and we keep provenance for re-slicing.
            $rel_dir   = 'img_uploads/' . date('Y') . '/' . date('m');
            $full_dir  = __DIR__ . '/' . $rel_dir;
            $src_dir   = $full_dir . '/sources';
            $thumb_dir = $full_dir . '/thumbs';
            foreach ([$full_dir, $src_dir, $thumb_dir] as $d) {
                if (!is_dir($d)) mkdir($d, 0755, true);
            }

            $ext      = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
            $prefix   = date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 4);
            $src_rel  = $rel_dir . '/sources/' . $prefix . '-src.' . $ext;
            $src_path = __DIR__ . '/' . $src_rel;

            if (!move_uploaded_file($tmp_name, $src_path)) {
                $msg = 'UPLOAD_FAILURE: could not store source image.';
            } else {
                [$sw, $sh] = getimagesize($src_path);
                $axis_len  = ($orientation === 'v') ? (int)$sh : (int)$sw;

                // Fractions (0..1) from the drag UI → pixel cut points. Blank/zero
                // falls back to even thirds (stored explicitly so re-slices match).
                $cut_a = ($frac_a > 0 && $frac_a < 1) ? (int)round($frac_a * $axis_len) : (int)round($axis_len / 3);
                $cut_b = ($frac_b > 0 && $frac_b < 1) ? (int)round($frac_b * $axis_len) : (int)round($axis_len * 2 / 3);

                // ===================================================================
                // MODE: TRIGRAM-COVER — slices become covers for 3 existing posts
                // ===================================================================
                if ($mode === 'trigram') {

                    $pl = (int)($_POST['post_l'] ?? 0);
                    $pm = (int)($_POST['post_m'] ?? 0);
                    $pr = (int)($_POST['post_r'] ?? 0);
                    $picked = [$pl, $pm, $pr];

                    if (in_array(0, $picked, true) || count(array_unique($picked)) !== 3) {
                        $msg = 'PICK_FAILURE: choose three distinct posts for the three slots.';
                    } else {
                        // Confirm all three posts exist.
                        $chk = $pdo->prepare("SELECT COUNT(*) FROM snap_posts WHERE id IN (?, ?, ?)");
                        $chk->execute($picked);
                        if ((int)$chk->fetchColumn() !== 3) {
                            $msg = 'PICK_FAILURE: one or more selected posts no longer exist.';
                        } else {
                            $trig_dir = __DIR__ . '/trigrams';
                            if (!is_dir($trig_dir)) mkdir($trig_dir, 0755, true);

                            try {
                                $pdo->beginTransaction();

                                // Insert the trigram row first so the slice files can
                                // carry its id (trigram-{id}-{L/M/R}.jpg).
                                $tg_ins = $pdo->prepare("
                                    INSERT INTO snap_trigrams
                                        (trigram_type, source_path, orientation, cut_a, cut_b,
                                         post_id_1, post_id_2, post_id_3)
                                    VALUES ('slice', ?, ?, ?, ?, ?, ?, ?)
                                ");
                                $tg_ins->execute([$src_rel, $orientation, $cut_a, $cut_b, $pl, $pm, $pr]);
                                $tg_id = (int)$pdo->lastInsertId();

                                $result = trigram_slice_image(
                                    $src_path, $orientation, $cut_a, $cut_b,
                                    1080, $trig_dir, 'trigram-' . $tg_id, max(1, min(100, $jpeg_q))
                                );
                                if (empty($result['ok'])) {
                                    throw new RuntimeException('slice failed: ' . ($result['error'] ?? 'unknown'));
                                }

                                // Point the three posts at this trigram.
                                $upd = $pdo->prepare("UPDATE snap_posts SET trigram_id = ? WHERE id = ?");
                                $upd->execute([$tg_id, $pl]);
                                $upd->execute([$tg_id, $pm]);
                                $upd->execute([$tg_id, $pr]);

                                $pdo->commit();

                                require_once __DIR__ . '/core/page-cache.php';
                                page_cache_purge_all();

                                if ($is_ajax) { echo 'success'; exit; }
                                header('Location: smack-lt-gram.php?msg=TRIGRAM_CREATED');
                                exit;
                            } catch (Throwable $e) {
                                if ($pdo->inTransaction()) $pdo->rollBack();
                                $msg = 'TRIGRAM_FAILURE: ' . $e->getMessage();
                            }
                        }
                    }

                // ===================================================================
                // MODE: TRIPTYCH — three new draft single-image posts
                // ===================================================================
                } else {

                    $result = trigram_slice_image(
                        $src_path, $orientation, $cut_a, $cut_b,
                        1080, $full_dir, $prefix, max(1, min(100, $jpeg_q))
                    );

                    if (empty($result['ok'])) {
                        $msg = 'SLICE_FAILURE: ' . ($result['error'] ?? 'unknown error');
                    } else {
                        $now   = date('Y-m-d H:i:s');
                        $slots = $result['slots'];   // [1=>label, 2=>label, 3=>label]
                        $files = $result['files'];   // [label => filename]

                        $img_stmt = $pdo->prepare("
                            INSERT INTO snap_images
                                (img_title, img_slug, img_description, img_date, img_file,
                                 img_status, img_orientation, allow_comments, allow_download,
                                 download_url, img_thumb_square, img_thumb_aspect,
                                 img_checksum, img_display_options, img_width, img_height)
                            VALUES ('', ?, '', ?, ?, 'draft', 2, 1, 0, '', ?, ?, ?, ?, 1080, 1080)
                        ");
                        $post_stmt = $pdo->prepare("
                            INSERT INTO snap_posts
                                (title, slug, description, post_type, status, created_at,
                                 allow_comments, allow_download, download_url, panorama_rows,
                                 post_img_size_pct, post_border_px, post_border_color,
                                 post_bg_color, post_shadow)
                            VALUES ('', ?, '', 'single', 'draft', ?, 1, 0, '', 1,
                                    100, 0, '#000000', '#ffffff', 0)
                        ");
                        $pi_stmt = $pdo->prepare("
                            INSERT INTO snap_post_images
                                (post_id, image_id, sort_position, is_cover, img_size_pct,
                                 img_border_px, img_border_color, img_bg_color, img_shadow,
                                 img_crop_mode)
                            VALUES (?, ?, 0, 1, 100, 0, '#000000', '#ffffff', 0, 'fill')
                        ");
                        $upd_post = $pdo->prepare("UPDATE snap_images SET post_id = ? WHERE id = ?");

                        try {
                            $pdo->beginTransaction();
                            for ($n = 1; $n <= 3; $n++) {
                                $label   = $slots[$n];
                                $fname   = $files[$label];
                                $abs     = $full_dir . '/' . $fname;
                                $db_path = $rel_dir  . '/' . $fname;

                                [$tsq, $tasp] = $make_thumbs($abs, $thumb_dir, $rel_dir, $prefix . '-' . $label);

                                $checksum = hash_file('sha256', $abs);
                                $palette  = snapsmack_extract_palette($abs, 5);
                                $pal_json = !empty($palette) ? json_encode(['palette' => $palette]) : null;

                                $slug = $prefix . '-' . strtolower($label);

                                $img_stmt->execute([$slug, $now, $db_path, $tsq, $tasp, $checksum, $pal_json]);
                                $image_id = (int)$pdo->lastInsertId();

                                $post_stmt->execute([$slug . '-post', $now]);
                                $post_id = (int)$pdo->lastInsertId();

                                $pi_stmt->execute([$post_id, $image_id]);
                                $upd_post->execute([$post_id, $image_id]);
                            }
                            $pdo->commit();
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            $msg = 'DB_FAILURE: ' . $e->getMessage();
                        }

                        if ($msg === '') {
                            if ($is_ajax) { echo 'success'; exit; }
                            header('Location: smack-lt-gram.php?msg=TRIPTYCH_CREATED');
                            exit;
                        }
                    }
                }
            }
        }
    }

    if ($is_ajax && $msg !== '') { echo $msg; exit; }
}

// ── Existing posts for the trigram-cover picker ──────────────────────────────
$pick_posts = $pdo->query("
    SELECT p.id, p.title, p.slug, p.status, i.img_thumb_square, i.img_file
    FROM snap_posts p
    LEFT JOIN snap_post_images pi ON pi.post_id = p.id AND pi.is_cover = 1
    LEFT JOIN snap_images i       ON i.id = pi.image_id
    WHERE p.post_type IN ('single', 'carousel')
    ORDER BY p.created_at DESC
    LIMIT 300
")->fetchAll();

// =============================================================================
// PAGE RENDER
// =============================================================================

$page_title = "Panorama Slicer";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row header-row--ruled">
        <h2>PANORAMA SLICER</h2>
        <div class="header-actions">
            <span class="dim" style="font-size:12px; letter-spacing:1px;">TRIGRAM / TRIPTYCH</span>
        </div>
    </div>

    <?php if (!empty($msg)): ?>
        <div class="notice notice-error"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <style>
    .sl-modes { display:flex; gap:10px; margin-bottom:18px; flex-wrap:wrap; }
    .sl-mode { flex:1 1 240px; padding:14px 16px; cursor:pointer; border-radius:6px;
        border:1px solid var(--border-color,#333); background:rgba(255,255,255,0.03); }
    .sl-mode.active { border-color:var(--accent,#b6ff1a);
        box-shadow:inset 0 0 0 1px var(--accent,#b6ff1a); }
    .sl-mode b { display:block; letter-spacing:.5px; margin-bottom:4px; }
    .sl-mode span { font-size:12px; opacity:.7; line-height:1.35; }

    .sl-drop { display:flex; flex-direction:column; align-items:center;
        justify-content:center; gap:6px; padding:40px 20px; cursor:pointer;
        text-align:center; border:2px dashed var(--border-color,#3a3a3a);
        border-radius:8px; background:rgba(255,255,255,0.02);
        transition:border-color .15s, background .15s; }
    .sl-drop:hover, .sl-drop.is-over { border-color:var(--accent,#b6ff1a);
        background:rgba(182,255,26,0.05); }
    .sl-drop-icon { font-size:34px; line-height:1; opacity:.55; }

    .sl-orient { display:flex; gap:10px; margin:18px 0; }
    .sl-orient button { padding:7px 14px; cursor:pointer; font-size:12px;
        letter-spacing:.5px; border-radius:5px;
        border:1px solid var(--border-color,#333);
        background:rgba(255,255,255,0.03); color:inherit; }
    .sl-orient button.active { background:var(--accent,#b6ff1a); color:#111;
        border-color:var(--accent,#b6ff1a); font-weight:700; }

    .sl-stage { position:relative; display:inline-block; max-width:100%;
        user-select:none; touch-action:none; line-height:0; }
    .sl-stage img { display:block; max-width:100%; height:auto; border-radius:4px; }
    .sl-div { position:absolute; z-index:5; background:var(--accent,#b6ff1a);
        box-shadow:0 0 0 1px rgba(0,0,0,.55); }
    .sl-div--h { top:0; bottom:0; width:4px; margin-left:-2px; cursor:ew-resize; }
    .sl-div--v { left:0; right:0; height:4px; margin-top:-2px; cursor:ns-resize; }
    .sl-div::after { content:''; position:absolute; background:var(--accent,#b6ff1a);
        border:1px solid rgba(0,0,0,.5); border-radius:3px; }
    .sl-div--h::after { width:14px; height:34px; left:-7px; top:50%; margin-top:-17px; }
    .sl-div--v::after { height:14px; width:34px; top:-7px; left:50%; margin-left:-17px; }

    .sl-previews { display:flex; gap:14px; margin-top:22px; flex-wrap:wrap; }
    .sl-prev { text-align:center; }
    .sl-prev canvas { width:160px; height:160px; display:block; background:#111;
        border:1px solid var(--border-color,#333); border-radius:4px; }
    .sl-prev-label { margin-top:6px; font-size:11px; letter-spacing:1.5px; opacity:.7; }
    .sl-hint { margin-top:14px; font-size:12px; opacity:.65; }

    /* Trigram post-picker */
    .sl-slots { display:flex; gap:14px; margin:6px 0 18px; flex-wrap:wrap; }
    .sl-slot { width:120px; }
    .sl-slot-box { position:relative; width:120px; height:120px; border-radius:6px;
        border:2px dashed var(--border-color,#3a3a3a); background:#111;
        display:flex; align-items:center; justify-content:center; cursor:pointer;
        overflow:hidden; }
    .sl-slot-box.filled { border-style:solid; border-color:var(--accent,#b6ff1a); }
    .sl-slot-box img { width:100%; height:100%; object-fit:cover; }
    .sl-slot-box .sl-slot-empty { font-size:11px; opacity:.5; }
    .sl-slot-label { display:block; text-align:center; margin-top:6px;
        font-size:12px; letter-spacing:2px; font-weight:700; }
    .sl-picker { max-height:300px; overflow-y:auto; margin-top:8px; padding:8px;
        border:1px solid var(--border-color,#333); border-radius:6px;
        display:grid; grid-template-columns:repeat(auto-fill,minmax(92px,1fr)); gap:8px; }
    .sl-pick { padding:0; border:1px solid var(--border-color,#333); border-radius:4px;
        background:#111; cursor:pointer; overflow:hidden; color:inherit; }
    .sl-pick img { width:100%; height:80px; object-fit:cover; display:block; }
    .sl-pick.used { opacity:.3; pointer-events:none; }
    .sl-pick-t { display:block; font-size:10px; padding:3px 4px; opacity:.7;
        white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    </style>

    <form id="sl-form" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="mode"        id="sl-mode"     value="triptych">
        <input type="hidden" name="orientation" id="sl-orient-val" value="h">
        <input type="hidden" name="cut_a_frac"  id="sl-cut-a"    value="0.3333">
        <input type="hidden" name="cut_b_frac"  id="sl-cut-b"    value="0.6667">
        <input type="hidden" name="post_l"      id="sl-post-l"   value="">
        <input type="hidden" name="post_m"      id="sl-post-m"   value="">
        <input type="hidden" name="post_r"      id="sl-post-r"   value="">

        <div class="sl-modes">
            <div class="sl-mode active" id="sl-mode-triptych" data-mode="triptych">
                <b>TRIPTYCH — three new posts</b>
                <span>Slice a wide image into three brand-new draft posts. Each slice is
                      its own post and grid cover. Arrange + publish in the Lighttable.</span>
            </div>
            <div class="sl-mode" id="sl-mode-trigram" data-mode="trigram">
                <b>COVER SLICES — assign to existing posts</b>
                <span>Slice a wide image into covers for three posts you already have.
                      The posts keep their carousels; only the grid tiles show the panorama.</span>
            </div>
        </div>

        <div class="box">
            <div id="sl-drop" class="sl-drop">
                <input type="file" id="sl-file" name="source"
                       accept="image/jpeg,image/png,image/webp" style="display:none;">
                <div class="sl-drop-icon">⊕</div>
                <p style="margin:0; font-weight:600; letter-spacing:.5px;">
                    DROP A WIDE IMAGE HERE or click to browse</p>
                <p class="dim" style="margin:0; font-size:12px;">JPG · PNG · WebP</p>
            </div>

            <div id="sl-editor" style="display:none;">
                <div class="sl-orient">
                    <button type="button" id="sl-h" class="active">Horizontal — L / M / R</button>
                    <button type="button" id="sl-v">Vertical — T / M / B</button>
                </div>

                <div id="sl-stage" class="sl-stage">
                    <img id="sl-img" src="" alt="">
                    <div id="sl-divA" class="sl-div sl-div--h"></div>
                    <div id="sl-divB" class="sl-div sl-div--h"></div>
                </div>

                <div class="sl-previews">
                    <div class="sl-prev"><canvas id="sl-c0" width="320" height="320"></canvas>
                        <div class="sl-prev-label" id="sl-l0">L</div></div>
                    <div class="sl-prev"><canvas id="sl-c1" width="320" height="320"></canvas>
                        <div class="sl-prev-label" id="sl-l1">M</div></div>
                    <div class="sl-prev"><canvas id="sl-c2" width="320" height="320"></canvas>
                        <div class="sl-prev-label" id="sl-l2">R</div></div>
                </div>

                <p class="sl-hint">Each preview is the exact square the slicer will cut
                    (centre-cropped, cover fit). Drag the bars to move the cuts.</p>
            </div>
        </div>

        <!-- Trigram-cover: pick three existing posts ------------------------- -->
        <div class="box mt-30" id="sl-trigram-pick" style="display:none;">
            <h3 style="margin:0 0 6px;">ASSIGN SLICES TO POSTS</h3>
            <p class="skin-desc-text" style="margin:0 0 10px;">
                Click a post below to fill the next slot. Click a filled slot to clear it.
            </p>
            <div class="sl-slots">
                <div class="sl-slot">
                    <div class="sl-slot-box" data-slot="0"><span class="sl-slot-empty">empty</span></div>
                    <span class="sl-slot-label" id="sl-slot-label-0">L</span>
                </div>
                <div class="sl-slot">
                    <div class="sl-slot-box" data-slot="1"><span class="sl-slot-empty">empty</span></div>
                    <span class="sl-slot-label" id="sl-slot-label-1">M</span>
                </div>
                <div class="sl-slot">
                    <div class="sl-slot-box" data-slot="2"><span class="sl-slot-empty">empty</span></div>
                    <span class="sl-slot-label" id="sl-slot-label-2">R</span>
                </div>
            </div>
            <div class="sl-picker">
                <?php foreach ($pick_posts as $pp):
                    $pthumb = $pp['img_thumb_square'] ?: $pp['img_file'] ?: '';
                    $ptitle = $pp['title'] !== '' ? $pp['title'] : ('#' . $pp['id'] . ' ' . $pp['slug']);
                ?>
                <button type="button" class="sl-pick" data-id="<?php echo (int)$pp['id']; ?>"
                        data-thumb="<?php echo htmlspecialchars($pthumb); ?>">
                    <?php if ($pthumb): ?>
                        <img src="<?php echo htmlspecialchars($pthumb); ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <span style="display:block;height:80px;"></span>
                    <?php endif; ?>
                    <span class="sl-pick-t"><?php echo htmlspecialchars($ptitle); ?></span>
                </button>
                <?php endforeach; ?>
                <?php if (empty($pick_posts)): ?>
                    <p class="dim" style="grid-column:1/-1;">No posts yet to assign covers to.</p>
                <?php endif; ?>
            </div>
        </div>

        <div id="sl-progress-wrap" class="progress-container" style="display:none;">
            <div id="sl-progress-bar" class="progress-bar"></div>
        </div>

        <div class="form-action-row">
            <button type="submit" id="sl-submit" class="master-update-btn" disabled>
                SLICE INTO THREE POSTS
            </button>
        </div>
    </form>
</div>

<script src="assets/js/ss-engine-slicer.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>"></script>
<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
