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
}

$current_cover_id = (int)($pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='slickr_cover_image_id' LIMIT 1")->fetchColumn() ?: 0);

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

    <p class="dim" style="margin:0 0 18px;">Pick a photo for your profile masthead. Leave it on automatic to use your newest landscape shot. (Positioning/crop is coming next — for now it centre-crops to fill.)</p>

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
<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
