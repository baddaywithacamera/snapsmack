<?php
/**
 * SNAPSMACK - Masthead Cover Picker
 *
 * Pick an existing photo as the Slickr profile masthead cover
 * (snap_settings.slickr_cover_image_id). Filter by album and sort by newest /
 * most viewed / most liked / title. Falls back to the newest landscape photo
 * when nothing is chosen ("automatic"). Positioning/crop is a planned follow-up;
 * the cover currently renders as a centered cover-crop.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once 'core/auth-smack.php';

// --- PAGE-LEVEL MASTHEAD GATE ---
// The Masthead Cover picker only applies to skins that render a full-bleed cover
// (today: Slickr). core/sidebar.php gates the nav LINK; this blocks a direct URL
// hit too, mirroring the same manifest-flag / slug-fallback logic.
// See [[project_masthead_cover]].
$_active_skin = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)(
    $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='active_skin' LIMIT 1")->fetchColumn() ?: ''
));
$_mh_manifest = [];
if ($_active_skin !== '' && file_exists("skins/{$_active_skin}/manifest.php")) {
    try { $_mh_manifest = include "skins/{$_active_skin}/manifest.php"; }
    catch (\Throwable $_e) {
        $_mh_manifest = [];
        error_log("SnapSmack masthead: failed to load manifest for {$_active_skin} — " . $_e->getMessage());
    }
}
$_skin_has_masthead = (is_array($_mh_manifest) && !empty($_mh_manifest['features']['masthead_cover']))
                   || in_array($_active_skin, ['slickr'], true);
if (!$_skin_has_masthead) {
    header('Location: smack-skin.php?msg=' . urlencode('The Masthead Cover applies to the Slickr skin only.'));
    exit;
}

// --- SET / RESET COVER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'set_cover') {
        $cid = (int)($_POST['image_id'] ?? 0);
        if ($cid > 0) {
            $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('slickr_cover_image_id', ?) ON DUPLICATE KEY UPDATE setting_val = ?")
                ->execute([$cid, $cid]);
        }
        header('Location: smack-masthead.php?msg=' . urlencode('Masthead cover updated.'));
        exit;
    }
    if (($_POST['action'] ?? '') === 'reset_cover') {
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('slickr_cover_image_id', '0') ON DUPLICATE KEY UPDATE setting_val = '0'")
            ->execute();
        header('Location: smack-masthead.php?msg=' . urlencode('Reset to automatic (newest landscape).'));
        exit;
    }
    if (($_POST['action'] ?? '') === 'save_cover_pos') {
        $px = max(0,   min(100, (int)($_POST['pos_x'] ?? 50)));
        $py = max(0,   min(100, (int)($_POST['pos_y'] ?? 50)));
        $pz = max(100, min(300, (int)($_POST['zoom']  ?? 100)));
        foreach (['slickr_cover_pos_x' => $px, 'slickr_cover_pos_y' => $py, 'slickr_cover_zoom' => $pz] as $k => $v) {
            $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?")
                ->execute([$k, $v, $v]);
        }
        header('Location: smack-masthead.php?msg=' . urlencode('Cover position saved.'));
        exit;
    }
}

$current_cover_id = (int)($pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='slickr_cover_image_id' LIMIT 1")->fetchColumn() ?: 0);

// Current cover framing (0 is a valid pan position, so test for missing keys
// explicitly rather than treating 0 as "unset").
$_cf = $pdo->query("SELECT setting_key, setting_val FROM snap_settings WHERE setting_key IN ('slickr_cover_pos_x','slickr_cover_pos_y','slickr_cover_zoom')")
           ->fetchAll(PDO::FETCH_KEY_PAIR);
$cover_pos_x = isset($_cf['slickr_cover_pos_x']) ? (int)$_cf['slickr_cover_pos_x'] : 50;
$cover_pos_y = isset($_cf['slickr_cover_pos_y']) ? (int)$_cf['slickr_cover_pos_y'] : 50;
$cover_zoom  = isset($_cf['slickr_cover_zoom'])  ? (int)$_cf['slickr_cover_zoom']  : 100;

// Full-resolution URL of the chosen cover, for the WYSIWYG position editor.
$cover_full_url = '';
if ($current_cover_id > 0) {
    $cfq = $pdo->prepare("SELECT img_file FROM snap_images WHERE id = ? LIMIT 1");
    $cfq->execute([$current_cover_id]);
    $cff = $cfq->fetchColumn();
    if ($cff) $cover_full_url = BASE_URL . ltrim($cff, '/');
}

// --- FILTERS ---
$f_album = (int)($_GET['album'] ?? 0);
$f_sort  = in_array($_GET['sort'] ?? '', ['recent', 'viewed', 'liked', 'title'], true) ? $_GET['sort'] : 'recent';
$order   = [
    'recent' => 'i.img_date DESC',
    'viewed' => 'i.img_view_seed DESC, i.img_date DESC',
    'liked'  => 'i.img_like_seed DESC, i.img_date DESC',
    'title'  => 'i.img_title ASC',
][$f_sort];

$albums = $pdo->query("SELECT id, album_name FROM snap_albums ORDER BY album_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Capped at 200; the album filter narrows a 9k+ library to something browsable.
$join = '';
$params = [];
if ($f_album > 0) {
    $join = "JOIN snap_image_album_map m ON m.image_id = i.id AND m.album_id = ?";
    $params[] = $f_album;
}
$stmt = $pdo->prepare(
    "SELECT i.id, i.img_title, i.img_thumb_square, i.img_file
     FROM snap_images i $join
     WHERE i.img_status = 'published'
     ORDER BY $order
     LIMIT 200"
);
$stmt->execute($params);
$photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

function _mh_thumb($row) {
    if (!empty($row['img_thumb_square'])) return BASE_URL . ltrim($row['img_thumb_square'], '/');
    $f = ltrim((string)($row['img_file'] ?? ''), '/');
    if ($f === '') return BASE_URL . 'assets/images/default-album-cover.jpg';
    $fn = basename($f);
    $d  = substr($f, 0, strlen($f) - strlen($fn));
    return BASE_URL . $d . 'thumbs/t_' . $fn;
}

include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">

    <div class="header-row header-row--ruled">
        <h2>MASTHEAD COVER</h2>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">&gt; <?php echo htmlspecialchars($_GET['msg']); ?></div>
    <?php endif; ?>

    <p class="dim" style="margin:0 0 18px;">Pick a photo for your profile masthead, then drag to position and zoom it. Leave it on automatic to use your newest landscape shot.</p>

    <div style="display:flex; align-items:center; gap:16px; margin-bottom:22px;">
        <span style="font-size:0.85rem;"><strong>Current:</strong>
            <?php echo $current_cover_id > 0 ? 'chosen photo' : 'Automatic (newest landscape)'; ?></span>
        <?php if ($current_cover_id > 0):
            $cf = $pdo->prepare("SELECT img_thumb_aspect, img_file FROM snap_images WHERE id=? LIMIT 1");
            $cf->execute([$current_cover_id]);
            $cr = $cf->fetch(PDO::FETCH_ASSOC);
            if ($cr): ?>
                <img src="<?php echo BASE_URL . ltrim($cr['img_thumb_aspect'] ?: $cr['img_file'], '/'); ?>" style="height:48px; border-radius:4px;">
            <?php endif; ?>
            <form method="post" style="margin:0;">
                <input type="hidden" name="action" value="reset_cover">
                <button type="submit" class="btn-smack btn-smack--sm">Reset to automatic</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($cover_full_url !== ''): ?>
    <style>#mh-stage.mh-grabbing{cursor:grabbing;}</style>
    <div class="box" style="margin:0 0 22px; padding:18px;">
        <p style="margin:0 0 12px; font-size:0.85rem;"><strong>Position &amp; zoom</strong> &mdash; drag the cover to move it, slide to zoom in. This preview renders exactly like the live banner.</p>
        <div id="mh-stage" style="position:relative; width:100%; height:240px; overflow:hidden; background:#222; border-radius:6px; cursor:grab; touch-action:none; user-select:none;">
            <img id="mh-cover-img" src="<?php echo htmlspecialchars($cover_full_url, ENT_QUOTES); ?>" alt=""
                 style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover; object-position:<?php echo $cover_pos_x; ?>% <?php echo $cover_pos_y; ?>%; transform-origin:<?php echo $cover_pos_x; ?>% <?php echo $cover_pos_y; ?>%; transform:scale(<?php echo number_format($cover_zoom / 100, 3); ?>); pointer-events:none;">
            <div aria-hidden="true" style="position:absolute; inset:0; pointer-events:none; background:linear-gradient(to bottom, rgba(0,0,0,0) 18%, rgba(0,0,0,.45) 62%, rgba(0,0,0,.82) 100%);"></div>
        </div>
        <form method="post" style="margin:14px 0 0; display:flex; align-items:center; gap:18px; flex-wrap:wrap;">
            <input type="hidden" name="action" value="save_cover_pos">
            <input type="hidden" name="pos_x" id="mh-pos-x" value="<?php echo $cover_pos_x; ?>">
            <input type="hidden" name="pos_y" id="mh-pos-y" value="<?php echo $cover_pos_y; ?>">
            <input type="hidden" name="zoom"  id="mh-zoom-val" value="<?php echo $cover_zoom; ?>">
            <label style="display:flex; align-items:center; gap:10px; font-size:0.8rem; letter-spacing:.04em;">ZOOM
                <input type="range" id="mh-zoom" min="100" max="300" step="1" value="<?php echo $cover_zoom; ?>" style="width:200px;">
            </label>
            <button type="button" id="mh-recenter" class="btn-smack btn-smack--sm">Re-centre</button>
            <button type="submit" class="btn-smack btn-smack--sm">Save position</button>
        </form>
    </div>
    <?php endif; ?>

    <form method="get" style="display:flex; gap:14px; align-items:end; margin-bottom:18px; flex-wrap:wrap;">
        <div>
            <label style="display:block; font-size:0.72rem; opacity:.7; letter-spacing:.05em;">ALBUM</label>
            <select name="album" onchange="this.form.submit()">
                <option value="0">All photos</option>
                <?php foreach ($albums as $a): ?>
                    <option value="<?php echo (int)$a['id']; ?>" <?php echo $f_album === (int)$a['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($a['album_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="display:block; font-size:0.72rem; opacity:.7; letter-spacing:.05em;">SORT</label>
            <select name="sort" onchange="this.form.submit()">
                <option value="recent" <?php echo $f_sort === 'recent' ? 'selected' : ''; ?>>Newest</option>
                <option value="viewed" <?php echo $f_sort === 'viewed' ? 'selected' : ''; ?>>Most viewed</option>
                <option value="liked"  <?php echo $f_sort === 'liked'  ? 'selected' : ''; ?>>Most liked (faves)</option>
                <option value="title"  <?php echo $f_sort === 'title'  ? 'selected' : ''; ?>>A &rarr; Z</option>
            </select>
        </div>
        <noscript><button type="submit" class="btn-smack btn-smack--sm">Apply</button></noscript>
    </form>

    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(140px,1fr)); gap:10px;">
        <?php if (empty($photos)): ?>
            <p class="dim">No photos found for this filter.</p>
        <?php else: foreach ($photos as $p):
            $is_cur = ((int)$p['id'] === $current_cover_id);
        ?>
            <form method="post" style="margin:0;">
                <input type="hidden" name="action" value="set_cover">
                <input type="hidden" name="image_id" value="<?php echo (int)$p['id']; ?>">
                <button type="submit" title="<?php echo htmlspecialchars($p['img_title'] ?? ''); ?>"
                        style="display:block; width:100%; padding:0; border:3px solid <?php echo $is_cur ? 'var(--accent,#b6ff1a)' : 'transparent'; ?>; border-radius:6px; background:#222; cursor:pointer; overflow:hidden; aspect-ratio:1/1;">
                    <img src="<?php echo _mh_thumb($p); ?>" alt="<?php echo htmlspecialchars($p['img_title'] ?? ''); ?>" loading="lazy" style="width:100%; height:100%; object-fit:cover; display:block;">
                </button>
            </form>
        <?php endforeach; endif; ?>
    </div>

</div>
<?php if ($cover_full_url !== ''): ?>
<script src="assets/js/ss-engine-masthead-crop.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>"></script>
<?php endif; ?>
<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
