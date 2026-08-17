<?php
/**
 * SNAPSMACK - SmackTalk longform post editor
 *
 * Writing-forward post type. Full-body content editor with the complete
 * shortcode toolbar plus MOSAIC panel insertion. Hero image from the
 * media library. Categories, albums, and tags all supported.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth-smack.php';
require_once 'core/app-mode.php';
require_once 'core/snap-tags.php';
require_once 'core/bucket.php';

if (!isset($settings)) {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
}
snapsmack_require_app_mode($settings, 'smacktalk');
$GLOBALS['SNAPSMACK_APP_COMPOSER'] = true;
if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim($settings['site_url'] ?? '/', '/') . '/');
}

// --- AUTO-RUN MIGRATION 041 if needed ---
try {
    $pdo->query("SELECT content, featured_asset_id FROM snap_posts LIMIT 0");
} catch (PDOException $e) {
    $mig = __DIR__ . '/migrations/041_longform_post_type.php';
    if (file_exists($mig)) { require_once $mig; migration_041_up($pdo); }
}

// Also ensure 'longform' ENUM value exists (idempotent ENUM check)
try {
    $row = $pdo->query("SHOW COLUMNS FROM `snap_posts` WHERE Field = 'post_type'")->fetch(PDO::FETCH_ASSOC);
    if ($row && strpos($row['Type'], 'longform') === false) {
        $mig = __DIR__ . '/migrations/041_longform_post_type.php';
        if (file_exists($mig)) { require_once $mig; migration_041_up($pdo); }
    }
} catch (PDOException $e) { /* silently skip */ }

// Defensive: ensure the Gallery cover column exists (canonical schema is the
// source of truth; this only catches an install caught mid-update). Pure
// structural add — no migration file. featured_image_id = FK to snap_images
// (the Gallery), the cover/featured image source, superseding the old
// Library-only featured_asset_id.
try {
    $pdo->exec("ALTER TABLE snap_posts ADD COLUMN IF NOT EXISTS featured_image_id INT UNSIGNED DEFAULT NULL");
} catch (PDOException $e) { /* older engine without IF NOT EXISTS — canonical sync handles it */ }

// Defensive: per-post SMACKTALK cover pan/zoom (non-destructive; applied via
// object-position + scale at render). Cover frame shape is per-skin (manifest
// cover_aspect). Canonical schema is the source of truth; this catches mid-update.
try {
    $pdo->exec("ALTER TABLE snap_posts ADD COLUMN IF NOT EXISTS cover_pos_x TINYINT UNSIGNED NOT NULL DEFAULT 50");
    $pdo->exec("ALTER TABLE snap_posts ADD COLUMN IF NOT EXISTS cover_pos_y TINYINT UNSIGNED NOT NULL DEFAULT 50");
    $pdo->exec("ALTER TABLE snap_posts ADD COLUMN IF NOT EXISTS cover_zoom SMALLINT UNSIGNED NOT NULL DEFAULT 100");
} catch (PDOException $e) { /* older engine — canonical sync handles it */ }

// --- PLAIN TEXT ↔ HTML HELPERS (same as smack-pages.php) ---
function smack_autop_long(string $text): string {
    if (trim($text) === '') return '';
    if (preg_match('/^\s*<p/i', $text)) return $text;
    $text = preg_replace('/(\[img:[^\]]+\])\s*\n+/', '$1', $text);
    $text = preg_replace('/(\[mosaic:\d+\])\s*\n+/', '$1', $text);
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $protected = [];
    $text = preg_replace_callback(
        '/<(ul|ol|table|blockquote|pre|div|figure|section|aside)[\s>].*?<\/\1>/si',
        function ($m) use (&$protected) {
            $key = '<!--BLOCK:' . count($protected) . '-->';
            $protected[$key] = $m[0];
            return "\n\n" . $key . "\n\n";
        },
        $text
    );
    $chunks = preg_split('/\n\n+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($chunks as &$chunk) {
        $trimmed = trim($chunk);
        if (str_starts_with($trimmed, '<!--BLOCK:')) {
            $chunk = $trimmed;
        } elseif (preg_match('/^\[img:\s*g?\s*\d+(?:\s*\|[^\]]*)*\]$/', $trimmed)) {
            $chunk = $trimmed;
        } elseif (preg_match('/^\[mosaic:\d+\]$/', $trimmed)) {
            $chunk = $trimmed;
        } elseif (preg_match('/^\[spacer:\s*\d+\]$/', $trimmed)) {
            $chunk = $trimmed;
        } else {
            $chunk = '<p>' . nl2br($trimmed) . '</p>';
        }
    }
    $result = implode("\n", $chunks);
    foreach ($protected as $key => $block) {
        $result = str_replace($key, $block, $result);
    }
    return $result;
}

function smack_reverse_autop_long(string $text): string {
    $text = str_replace('<p>', '', $text);
    $text = str_replace('</p>', "\n", $text);
    $text = preg_replace('/<br\s*\/?>/i', '', $text);
    return trim($text);
}

// --- SLUG GENERATION ---
function long_slugify(string $title): string {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $title), '-'));
    return $slug ?: 'post';
}

// --- AJAX: the BUCKET — this post's working set of Gallery photos ---
// Filling the bucket is deliberately its own save, not part of the post form:
// it has to work on a draft you are still writing, without forcing a full post
// save (and a page reload) every time you add a photo.
if (!empty($_GET['ajax']) && $_GET['ajax'] === 'bucket') {
    header('Content-Type: application/json');
    snap_bucket_ensure($pdo);

    $post_id = (int)($_GET['post_id'] ?? 0);
    $q       = trim((string)($_GET['q'] ?? ''));

    $where  = ["img_status = 'published'"];
    $params = [];
    if ($q !== '') {
        $where[]  = '(img_title LIKE ? OR img_file LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    $where_sql = 'WHERE ' . implode(' AND ', $where);

    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_images $where_sql");
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    // Same honesty rule as the mosaic picker: a capped list says so.
    $stmt = $pdo->prepare(
        "SELECT id,
                img_title AS name,
                COALESCE(NULLIF(img_thumb_aspect, ''), img_file) AS path
         FROM snap_images
         $where_sql
         ORDER BY id DESC
         LIMIT 500"
    );
    $stmt->execute($params);
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // The bucket's OWN photos always come back, whatever the search or the cap.
    // A bucket photo older than the newest 500 would otherwise leave a hole in
    // the strip — still saved, but looking as though it had been dropped.
    // Returned separately so the "showing X of Y" line stays true to the search.
    $bucket = snap_bucket_ids($pdo, $post_id);
    $have    = array_map(function ($im) { return (int)$im['id']; }, $images);
    $missing = array_values(array_diff($bucket, $have));
    $extra   = [];
    if ($missing) {
        $ph   = implode(',', array_fill(0, count($missing), '?'));
        $xstm = $pdo->prepare(
            "SELECT id,
                    img_title AS name,
                    COALESCE(NULLIF(img_thumb_aspect, ''), img_file) AS path
             FROM snap_images WHERE id IN ($ph)"
        );
        $xstm->execute($missing);
        $extra = $xstm->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'images' => $images,
        'extra'  => $extra,
        'bucket' => $bucket,
        'total'  => $total,
        'shown'  => count($images),
        'capped' => $total > count($images),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_bucket') {
    // CSRF is already enforced: core/auth-smack.php runs csrf_check() on every
    // POST, and assets/js/ss-engine-admin-csrf.js puts the token on every admin
    // XHR. Nothing to add here — and nothing to skip either.
    header('Content-Type: application/json');
    $post_id = (int)($_POST['post_id'] ?? 0);
    if ($post_id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Save this post as a draft first, then fill its bucket.']);
        exit;
    }
    $ids = json_decode($_POST['image_ids'] ?? '[]', true);
    if (!is_array($ids)) $ids = [];
    try {
        $n = snap_bucket_save($pdo, $post_id, $ids);
        echo json_encode(['ok' => true, 'count' => $n]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => 'Could not save the bucket. Nothing was changed.']);
    }
    exit;
}

// --- AJAX: list mosaics for insert picker ---
if (!empty($_GET['ajax']) && $_GET['ajax'] === 'mosaics') {
    header('Content-Type: application/json');
    $mosaics = $pdo->query(
        "SELECT id, title, updated_at FROM snap_mosaics ORDER BY updated_at DESC LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($mosaics);
    exit;
}

// --- AJAX: post picker for featured image ---
if (!empty($_GET['ajax']) && $_GET['ajax'] === 'posts') {
    header('Content-Type: application/json');
    $q     = '%' . trim($_GET['q'] ?? '') . '%';
    $posts = $pdo->prepare(
        "SELECT p.id, p.title, p.created_at,
                i.img_thumb_square, i.img_thumb_aspect, i.img_file
         FROM snap_posts p
         LEFT JOIN snap_images i ON i.post_id = p.id
         WHERE p.status = 'published' AND p.title LIKE ?
         GROUP BY p.id
         ORDER BY p.id DESC
         LIMIT 80"
    );
    $posts->execute([$q]);
    echo json_encode($posts->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// --- DELETE ---
if (isset($_GET['delete'])) {
    csrf_verify(); // SECAUDIT 047 — GET deletion must carry the CSRF token
    $del_id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM snap_post_cat_map WHERE post_id = ?")->execute([$del_id]);
    $pdo->prepare("DELETE FROM snap_post_album_map WHERE post_id = ?")->execute([$del_id]);
    $pdo->prepare("DELETE FROM snap_image_tags WHERE image_id = ?")->execute([$del_id]);
    $pdo->prepare("DELETE FROM snap_posts WHERE id = ? AND post_type = 'longform'")->execute([$del_id]);
    require_once __DIR__ . '/core/page-cache.php';
    page_cache_purge_all();
    header("Location: smack-post-long.php?msg=TRANSMISSION+PURGED");
    exit;
}

// --- FORM SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_long'])) {
    $post_id          = !empty($_POST['post_id']) ? (int)$_POST['post_id'] : null;
    $title            = trim($_POST['title'] ?? '');
    $slug             = trim($_POST['slug'] ?? '');
    $raw_content      = $_POST['content'] ?? '';
    $status           = in_array($_POST['status'] ?? '', ['published','draft']) ? $_POST['status'] : 'published';
    $allow_comments   = (int)($_POST['allow_comments'] ?? 1);
    $featured_image   = !empty($_POST['featured_image_id']) ? (int)$_POST['featured_image_id'] : null;
    $cover_pos_x      = max(0,   min(100, (int)($_POST['cover_pos_x'] ?? 50)));
    $cover_pos_y      = max(0,   min(100, (int)($_POST['cover_pos_y'] ?? 50)));
    $cover_zoom       = max(100, min(300, (int)($_POST['cover_zoom']  ?? 100)));
    $manual_tags      = trim($_POST['tags'] ?? '');
    $selected_cats    = $_POST['cat_ids'] ?? [];
    $selected_albums  = $_POST['album_ids'] ?? [];

    // Custom timestamp
    $raw_date    = $_POST['post_date'] ?? '';
    $custom_date = !empty($raw_date) ? str_replace('T', ' ', $raw_date) : null;

    if ($title === '') {
        $form_error = "Title is required.";
        goto render_form;
    }

    // Auto-slug if empty; sanitize user-supplied slug if provided.
    if ($slug === '') {
        $slug = long_slugify($title);
    } else {
        $slug = long_slugify($slug); // normalise user input through same rules
    }

    // Ensure unique slug on insert
    if (!$post_id) {
        $base_slug = $slug;
        $n = 0;
        while (true) {
            $check = $pdo->prepare("SELECT id FROM snap_posts WHERE slug = ?");
            $check->execute([$slug]);
            if (!$check->fetch()) break;
            $n++;
            $slug = $base_slug . '-' . $n;
        }
    }

    $content_html = smack_autop_long($raw_content);

    if ($post_id) {
        // UPDATE
        $upd = $pdo->prepare("
            UPDATE snap_posts
            SET title=?, slug=?, content=?, status=?, allow_comments=?,
                featured_image_id=?, cover_pos_x=?, cover_pos_y=?, cover_zoom=?" .
                ($custom_date ? ", created_at=?" : "") . "
            WHERE id=? AND post_type='longform'
        ");
        $params = [$title, $slug, $content_html, $status, $allow_comments, $featured_image, $cover_pos_x, $cover_pos_y, $cover_zoom];
        if ($custom_date) $params[] = $custom_date;
        $params[] = $post_id;
        $upd->execute($params);

        // Re-sync categories
        $pdo->prepare("DELETE FROM snap_post_cat_map WHERE post_id = ?")->execute([$post_id]);
        $pdo->prepare("DELETE FROM snap_post_album_map WHERE post_id = ?")->execute([$post_id]);
        foreach ($selected_cats as $cid) {
            $pdo->prepare("INSERT IGNORE INTO snap_post_cat_map (post_id, cat_id) VALUES (?, ?)")->execute([$post_id, (int)$cid]);
        }
        foreach ($selected_albums as $aid) {
            $pdo->prepare("INSERT IGNORE INTO snap_post_album_map (post_id, album_id) VALUES (?, ?)")->execute([$post_id, (int)$aid]);
        }
        snap_sync_tags($pdo, $post_id, $title . ' ' . $manual_tags);
        require_once __DIR__ . '/core/page-cache.php';
        page_cache_purge_all();
        header("Location: smack-post-long.php?msg=TRANSMISSION+UPDATED&edit=" . $post_id);
        exit;
    } else {
        // INSERT
        $ins = $pdo->prepare("
            INSERT INTO snap_posts
                (title, slug, content, post_type, status, allow_comments, featured_image_id, cover_pos_x, cover_pos_y, cover_zoom" .
                ($custom_date ? ", created_at" : "") . ")
            VALUES (?, ?, ?, 'longform', ?, ?, ?, ?, ?, ?" .
                ($custom_date ? ", ?" : "") . ")
        ");
        $params = [$title, $slug, $content_html, $status, $allow_comments, $featured_image, $cover_pos_x, $cover_pos_y, $cover_zoom];
        if ($custom_date) $params[] = $custom_date;
        $ins->execute($params);
        $new_id = (int)$pdo->lastInsertId();

        foreach ($selected_cats as $cid) {
            $pdo->prepare("INSERT IGNORE INTO snap_post_cat_map (post_id, cat_id) VALUES (?, ?)")->execute([$new_id, (int)$cid]);
        }
        foreach ($selected_albums as $aid) {
            $pdo->prepare("INSERT IGNORE INTO snap_post_album_map (post_id, album_id) VALUES (?, ?)")->execute([$new_id, (int)$aid]);
        }
        snap_sync_tags($pdo, $new_id, $title . ' ' . $manual_tags);
        require_once __DIR__ . '/core/page-cache.php';
        page_cache_purge_all();
        header("Location: smack-post-long.php?msg=TRANSMISSION+LIVE&edit=" . $new_id);
        exit;
    }
}

render_form:
// --- EDIT MODE ---
$edit_post    = null;
$edit_cats    = [];
$edit_albums  = [];
$edit_content = '';
if (isset($_GET['edit'])) {
    $ep_id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM snap_posts WHERE id = ? AND post_type = 'longform'");
    $stmt->execute([$ep_id]);
    $edit_post = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($edit_post) {
        $edit_content = smack_reverse_autop_long($edit_post['content'] ?? '');
        // Load existing cat/album selections
        $ec = $pdo->prepare("SELECT cat_id FROM snap_post_cat_map WHERE post_id = ?");
        $ec->execute([$ep_id]);
        $edit_cats = array_column($ec->fetchAll(PDO::FETCH_ASSOC), 'cat_id');
        $ea = $pdo->prepare("SELECT album_id FROM snap_post_album_map WHERE post_id = ?");
        $ea->execute([$ep_id]);
        $edit_albums = array_column($ea->fetchAll(PDO::FETCH_ASSOC), 'album_id');
        // Load existing tags
        $et = $pdo->prepare("SELECT t.tag FROM snap_image_tags it JOIN snap_tags t ON t.id = it.tag_id WHERE it.image_id = ?");
        $et->execute([$ep_id]);
        $edit_tags_arr = array_column($et->fetchAll(PDO::FETCH_ASSOC), 'tag');
        $edit_tags_str = implode(' ', array_map(fn($t) => '#' . $t, $edit_tags_arr));
    }
}

// --- DATA ---
$all_cats   = $pdo->query("SELECT * FROM snap_categories ORDER BY cat_name ASC")->fetchAll();
$all_albums = $pdo->query("SELECT * FROM snap_albums ORDER BY album_name ASC")->fetchAll();
$all_posts  = $pdo->query(
    "SELECT p.id, p.title, p.status, p.created_at, p.slug
     FROM snap_posts p
     WHERE p.post_type = 'longform'
     ORDER BY p.id DESC
     LIMIT 200"
)->fetchAll();

// Fetch the cover image (from the Gallery / snap_images) for edit mode.
// Covers are POST content, so they live in the Gallery — NOT the reusable-asset
// Library. featured_image_id → snap_images.
$featured_image_data = null;
if ($edit_post && !empty($edit_post['featured_image_id'])) {
    $fis = $pdo->prepare("SELECT id, img_title AS name, img_file, img_thumb_square FROM snap_images WHERE id = ?");
    $fis->execute([$edit_post['featured_image_id']]);
    $featured_image_data = $fis->fetch(PDO::FETCH_ASSOC) ?: null;
}

$page_title = "SmackTalk — Longform Post";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row header-row--ruled">
        <h2>SMACKTALK — LONGFORM TRANSMISSION</h2>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">&gt; <?php echo htmlspecialchars($_GET['msg']); ?></div>
    <?php endif; ?>

    <?php if (!empty($form_error)): ?>
        <div class="alert" style="background:rgba(204,68,68,0.15);border:1px solid rgba(204,68,68,0.4);color:#cc4444;padding:12px 16px;border-radius:4px;margin-bottom:16px;">
            <?php echo htmlspecialchars($form_error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="long-post-form">
        <input type="hidden" name="save_long" value="1">
        <input type="hidden" name="post_id" value="<?php echo $edit_post ? (int)$edit_post['id'] : ''; ?>">

        <!-- TITLE + SLUG row -->
        <div class="box" style="margin-bottom:0;border-bottom:none;border-radius:4px 4px 0 0;">
            <div class="post-layout-grid">
                <div class="flex-2">
                    <div class="lens-input-wrapper">
                        <label>TRANSMISSION TITLE</label>
                        <input type="text" name="title" id="long-title"
                               value="<?php echo htmlspecialchars($edit_post['title'] ?? ''); ?>"
                               placeholder="The thing you're writing about..." required autofocus>
                    </div>
                </div>
                <div class="flex-1">
                    <div class="lens-input-wrapper">
                        <label>SLUG <span class="field-tip" data-tip="The URL-friendly identifier for this post. Auto-generated from the title if left blank.">ⓘ</span></label>
                        <input type="text" name="slug" id="long-slug"
                               value="<?php echo htmlspecialchars($edit_post['slug'] ?? ''); ?>"
                               placeholder="auto-generated">
                    </div>
                </div>
            </div>
        </div>

        <!-- MAIN EDITOR AREA -->
        <div class="box" style="border-radius:0;border-top:none;border-bottom:none;">
            <div class="sc-toolbar" data-target="long-content">
                <div class="sc-row">
                    <button type="button" class="sc-btn" data-action="bold" title="Bold (Ctrl+B)">B</button>
                    <button type="button" class="sc-btn" data-action="italic" title="Italic (Ctrl+I)">I</button>
                    <button type="button" class="sc-btn" data-action="underline" title="Underline (Ctrl+U)">U</button>
                    <button type="button" class="sc-btn" data-action="link" title="Insert Link (Ctrl+K)">LINK</button>
                    <span class="sc-sep"></span>
                    <button type="button" class="sc-btn" data-action="h2" title="Heading 2">H2</button>
                    <button type="button" class="sc-btn" data-action="h3" title="Heading 3">H3</button>
                    <button type="button" class="sc-btn" data-action="blockquote" title="Blockquote">BQ</button>
                    <button type="button" class="sc-btn" data-action="hr" title="Horizontal Rule">HR</button>
                    <span class="sc-sep"></span>
                    <button type="button" class="sc-btn" data-action="ul" title="Bullet List">UL</button>
                    <button type="button" class="sc-btn" data-action="ol" title="Numbered List">OL</button>
                    <span class="sc-sep"></span>
                    <select class="sc-shortcode-select" title="Insert data shortcode">
                        <option value="">— INSERT SHORTCODE —</option>
                        <option value="[post_count]">Post Count</option>
                        <option value="[site_name]">Site Name</option>
                        <option value="[site_url]">Site URL</option>
                        <option value="[current_year]">Current Year</option>
                        <option value='[years_since year="" month="" day=""]'>Years Since&hellip;</option>
                        <option value="[newest_post]">Newest Post Date</option>
                        <option value="[oldest_post]">Oldest Post Date</option>
                        <option value="[archive_link]">Archive Link</option>
                        <option value="[gallery_link]">Gallery Link</option>
                        <option value="[random_image]">Random Image</option>
                        <option value="[latest_image]">Latest Image</option>
                        <option value="[embed:]">Embed&hellip;</option>
                    </select>
                </div>
                <div class="sc-row">
                    <button type="button" class="sc-btn" data-action="img" title="Insert Image Shortcode">IMG</button>
                    <button type="button" class="sc-btn" data-action="col2" title="2-Column Layout">COL 2</button>
                    <button type="button" class="sc-btn" data-action="col3" title="3-Column Layout">COL 3</button>
                    <button type="button" class="sc-btn" data-action="dropcap" title="Dropcap">DROP</button>
                    <button type="button" class="sc-btn" data-action="spacer" title="Vertical Spacer (1-100px)">SPACER</button>
                    <span class="sc-sep"></span>
                    <button type="button" class="sc-btn" id="mosaic-insert-btn" title="Insert MOSAIC panel">MOSAIC</button>
                    <button type="button" class="sc-btn" id="gallery-insert-btn" title="Insert an image already in the Media Gallery">GALLERY</button>
                    <button type="button" class="sc-btn sc-btn-preview" data-action="preview" title="Preview in New Tab">PREVIEW</button>
                </div>
            </div>
            <textarea id="long-content" name="content" rows="28"
                      style="width:100%;box-sizing:border-box;font-family:monospace;font-size:13px;"
                      placeholder="Write something worth saying. Blank lines become paragraph breaks. Embed image shortcodes and MOSAIC panels inline."><?php echo htmlspecialchars($edit_content ?? ($edit_post['content'] ?? '')); ?></textarea>
        </div>

        <?php /* ── BUCKET ──────────────────────────────────────────────────────
                 This post's working set of Gallery photos. Private and editorial:
                 nothing here publishes on its own, and nothing here is placed in
                 the essay until you put it there. Its whole job is to give the
                 MOSAIC picker something to narrow by, so choosing photos for an
                 arrangement is a dozen tiles instead of the entire Gallery.

                 Saved on its own button, NOT with the post form, so it works
                 while a draft is still being written without a reload. */ ?>
        <?php /* Scoped to #bucket-panel so it matches the form's compact button
                 idiom (sc-btn / AI-fill buttons) without touching any global
                 style. Accent is the theme's --lens-accent, so it tracks the
                 skin rather than a hardcoded green. */ ?>
        <style>
        #bucket-panel .bkt-actions { display:flex; align-items:center; gap:12px; margin-top:12px; flex-wrap:wrap; }
        #bucket-panel .bkt-btn {
            height:38px; padding:0 18px; font-size:.72rem; font-weight:700;
            letter-spacing:.06em; text-transform:uppercase; border-radius:4px;
            border:1px solid var(--border,#333); background:transparent;
            color:var(--text-primary,#ddd); cursor:pointer;
            transition:border-color .15s, color .15s, background-color .15s;
        }
        #bucket-panel .bkt-btn:hover { border-color:var(--lens-accent,#39FF14); color:var(--lens-accent,#39FF14); }
        #bucket-panel .bkt-save { margin-left:auto; }
        #bucket-panel .bkt-save[disabled] { opacity:.4; cursor:default; }
        #bucket-panel .bkt-save.is-dirty {
            background:var(--lens-accent,#39FF14); border-color:var(--lens-accent,#39FF14);
            color:var(--lens-bg,#141414); opacity:1; cursor:pointer;
        }
        #bucket-panel .bkt-link {
            display:inline-block; margin-top:12px; font-size:.72rem; letter-spacing:.04em;
            text-transform:uppercase; color:var(--lens-accent,#39FF14);
            text-decoration:none; border-bottom:1px solid transparent;
        }
        #bucket-panel .bkt-link:hover { border-bottom-color:currentColor; }
        #bucket-panel .bkt-hint { font-size:11px; color:var(--dim,#888); margin-left:10px; }
        </style>
        <div class="box" id="bucket-panel" style="border-radius:0;border-top:none;">
            <div class="header-row" style="margin-bottom:10px;">
                <h3 style="margin:0;font-size:13px;letter-spacing:.8px;">
                    BUCKET
                    <span class="field-tip" data-tip="The photos this post is built from. Pick them once here, then the MOSAIC picker opens showing only these instead of your whole gallery. Nothing here is published or placed in the essay on its own.">ⓘ</span>
                </h3>
                <span id="bucket-status" class="dim" style="font-size:11px;"></span>
            </div>

            <?php $bucket_post_id_form = $edit_post ? (int)$edit_post['id'] : 0; ?>
            <?php if (!$bucket_post_id_form): ?>
                <?php /* A bucket belongs to a post, and a post that has never been
                         saved has no id to belong to. Say so plainly rather than
                         showing a picker that silently discards what you choose. */ ?>
                <p class="dim" style="font-size:12px;margin:0;">
                    Save this post once — as a <strong>Draft</strong> is fine — and the bucket appears here.
                    Then pick the photos you are writing from, and the mosaic builder will show only those.
                </p>
            <?php else: ?>
                <div id="bucket-selected"
                     style="display:flex;flex-wrap:wrap;gap:8px;min-height:76px;padding:12px;border:1px solid var(--border);border-radius:3px;background:var(--input-bg);"></div>

                <?php /* Two quiet controls in the form's own compact idiom, NOT two
                         full-width slabs. ADD sits left; SAVE sits right, apart from
                         it (Parkinson's — no adjacent mis-hit targets), and stays
                         calm until there is something to save, when it lights accent
                         and enables. So it is a clear target exactly when it matters
                         and silent the rest of the time. */ ?>
                <div class="bkt-actions">
                    <button type="button" id="bucket-add-btn" class="bkt-btn">+ Add photos</button>
                    <button type="button" id="bucket-save-btn" class="bkt-btn bkt-save" disabled>Save bucket</button>
                </div>

                <div>
                    <a id="bucket-mosaic-link" href="smack-mosaics.php?new=1&amp;post=<?php echo $bucket_post_id_form; ?>"
                       target="_blank" class="bkt-link">Build a mosaic from this bucket →</a>
                    <span class="bkt-hint">save first, or the builder won't see your latest picks</span>
                </div>

                <!-- BUCKET PICKER (hidden until asked for) -->
                <div id="bucket-picker" style="display:none;margin-top:12px;border:1px solid var(--border);border-radius:3px;padding:12px;background:var(--card-bg);">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:10px;">
                        <span style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--dim);">MEDIA GALLERY</span>
                        <input type="text" id="bucket-search" placeholder="Search by name" style="flex:1;max-width:280px;">
                        <button type="button" id="bucket-picker-close" style="background:none;border:none;color:var(--dim);cursor:pointer;font-size:18px;line-height:1;">×</button>
                    </div>
                    <div id="bucket-count" style="font-size:11px;color:var(--dim);margin-bottom:2px;"></div>
                    <div style="font-size:11px;color:var(--dim);margin-bottom:8px;">Click a photo to pick it. <strong>Shift-click</strong> to grab everything between it and your last pick.</div>
                    <?php /* user-select:none — shift-click otherwise highlights the run of
                             tiles as text instead of selecting photos. */ ?>
                    <div id="bucket-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:6px;max-height:340px;overflow-y:auto;user-select:none;"></div>
                </div>
            <?php endif; ?>
        </div>

        <!-- META SIDEBAR ROW -->
        <div class="box" style="border-radius:0 0 4px 4px;border-top:none;">
            <div class="post-layout-grid">

                <!-- LEFT META: cats, albums, tags -->
                <div class="flex-2">
                    <div class="post-layout-grid">
                        <div class="flex-1">
                            <div class="lens-input-wrapper">
                                <label>REGISTRY (CATEGORIES)</label>
                                <div class="custom-multiselect">
                                    <div class="select-box" onclick="toggleDropdown('long-cat-items')">
                                        <span id="long-cat-label">
                                            <?php
                                            if (!empty($edit_cats)) {
                                                $sel_names = array_filter(array_map(fn($c) => in_array($c['id'], $edit_cats) ? htmlspecialchars($c['cat_name']) : null, $all_cats));
                                                echo implode(', ', $sel_names);
                                            } else {
                                                echo 'Select Categories...';
                                            }
                                            ?>
                                        </span>
                                        <span class="arrow">▼</span>
                                    </div>
                                    <div class="dropdown-content" id="long-cat-items">
                                        <div class="dropdown-search-wrapper">
                                            <input type="text" placeholder="Filter..." onkeyup="filterRegistry(this, 'long-cat-list-box')">
                                        </div>
                                        <div class="dropdown-list" id="long-cat-list-box">
                                            <?php foreach ($all_cats as $c): ?>
                                                <label class="multi-cat-item">
                                                    <input type="checkbox" name="cat_ids[]"
                                                           value="<?php echo $c['id']; ?>"
                                                           <?php echo in_array($c['id'], $edit_cats) ? 'checked' : ''; ?>
                                                           onchange="updateLabelLong('long-cat-label', 'long-cat-items', 'Select Categories...')">
                                                    <span class="cat-name-text"><?php echo htmlspecialchars($c['cat_name']); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="flex-1">
                            <div class="lens-input-wrapper">
                                <label>MISSIONS (ALBUMS)</label>
                                <div class="custom-multiselect">
                                    <div class="select-box" onclick="toggleDropdown('long-album-items')">
                                        <span id="long-album-label">
                                            <?php
                                            if (!empty($edit_albums)) {
                                                $sel_names = array_filter(array_map(fn($a) => in_array($a['id'], $edit_albums) ? htmlspecialchars($a['album_name']) : null, $all_albums));
                                                echo implode(', ', $sel_names);
                                            } else {
                                                echo 'Select Albums...';
                                            }
                                            ?>
                                        </span>
                                        <span class="arrow">▼</span>
                                    </div>
                                    <div class="dropdown-content" id="long-album-items">
                                        <div class="dropdown-search-wrapper">
                                            <input type="text" placeholder="Filter..." onkeyup="filterRegistry(this, 'long-album-list-box')">
                                        </div>
                                        <div class="dropdown-list" id="long-album-list-box">
                                            <?php foreach ($all_albums as $a): ?>
                                                <label class="multi-cat-item">
                                                    <input type="checkbox" name="album_ids[]"
                                                           value="<?php echo $a['id']; ?>"
                                                           <?php echo in_array($a['id'], $edit_albums) ? 'checked' : ''; ?>
                                                           onchange="updateLabelLong('long-album-label', 'long-album-items', 'Select Albums...')">
                                                    <span class="cat-name-text"><?php echo htmlspecialchars($a['album_name']); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="lens-input-wrapper mt-10">
                        <label>TAGS <span class="field-tip" data-tip="Space-separated hashtags (e.g. street architecture film).">ⓘ</span></label>
                        <input type="text" name="tags" id="long-tags"
                               value="<?php echo htmlspecialchars($edit_tags_str ?? ''); ?>"
                               placeholder="#narrative #essay #portraits">
                    </div>
                </div>

                <!-- RIGHT META: status, date, comments, hero, save -->
                <div class="post-col-right">
                    <div class="lens-input-wrapper">
                        <label>PUBLICATION STATUS</label>
                        <select name="status" class="full-width-select">
                            <option value="published" <?php echo (!$edit_post || ($edit_post['status'] ?? '') === 'published') ? 'selected' : ''; ?>>Published</option>
                            <option value="draft"     <?php echo ($edit_post && ($edit_post['status'] ?? '') === 'draft') ? 'selected' : ''; ?>>Draft</option>
                        </select>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>TIMESTAMP</label>
                        <input type="datetime-local" name="post_date" class="full-width-select edit-timestamp"
                               onclick="this.showPicker()"
                               value="<?php echo $edit_post ? date('Y-m-d\TH:i', strtotime($edit_post['created_at'])) : date('Y-m-d\TH:i'); ?>">
                    </div>

                    <div class="lens-input-wrapper">
                        <label>PUBLIC SIGNALS (COMMENTS)?</label>
                        <select name="allow_comments" class="full-width-select">
                            <option value="1" <?php echo (!$edit_post || ($edit_post['allow_comments'] ?? 1)) ? 'selected' : ''; ?>>ENABLED</option>
                            <option value="0" <?php echo ($edit_post && !($edit_post['allow_comments'] ?? 1)) ? 'selected' : ''; ?>>DISABLED</option>
                        </select>
                    </div>

                    <!-- COVER IMAGE (from the Media Gallery — POST content, NOT the Library) -->
                    <div class="lens-input-wrapper mt-10">
                        <label>COVER IMAGE <span class="field-tip" data-tip="The post's cover / featured image — shown as the banner on the post and as its thumbnail in the post listing. Chosen from your Media Gallery (post images), like a GRAMOFSMACK cover.">ⓘ</span></label>
                        <input type="hidden" name="featured_image_id" id="long-cover-image-id"
                               value="<?php echo $featured_image_data ? (int)$featured_image_data['id'] : ''; ?>">
                        <div id="long-cover-preview" style="margin-top:6px;">
                            <?php if ($featured_image_data): ?>
                                <?php $cover_url = BASE_URL . ltrim(($featured_image_data['img_thumb_square'] ?: $featured_image_data['img_file']), '/'); ?>
                                <img src="<?php echo htmlspecialchars($cover_url); ?>"
                                     style="width:100%;max-width:200px;height:auto;border-radius:3px;border:1px solid var(--border);"
                                     alt="">
                                <span class="dim" style="display:block;font-size:11px;margin-top:4px;"><?php echo htmlspecialchars($featured_image_data['name'] ?? ''); ?></span>
                            <?php else: ?>
                                <div style="width:100%;max-width:200px;height:80px;background:var(--card-bg);border:1px dashed var(--border);border-radius:3px;display:flex;align-items:center;justify-content:center;">
                                    <span class="dim" style="font-size:10px;text-align:center;padding:4px;">NO COVER</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;gap:8px;margin-top:8px;">
                            <button type="button" id="long-cover-btn" class="btn-secondary" style="font-size:11px;padding:5px 12px;">
                                <?php echo $featured_image_data ? 'CHANGE' : 'SELECT COVER'; ?>
                            </button>
                            <button type="button" id="long-cover-remove" class="btn-secondary" style="font-size:11px;padding:5px 12px;color:var(--dim);<?php echo $featured_image_data ? '' : 'display:none;'; ?>">REMOVE</button>
                        </div>
                        <?php
                        // Cover framing (pan/zoom). Non-destructive: object-position + scale,
                        // rendered identically by the SMACKTALK skins. The stage is framed to
                        // the ACTIVE skin's cover shape (manifest cover_aspect).
                        $_ck_skin   = preg_replace('/[^a-z0-9_-]/', '', (string)($pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='active_skin'")->fetchColumn() ?: 'alfred'));
                        $_ck_mf     = __DIR__ . '/skins/' . $_ck_skin . '/manifest.json';
                        $cover_aspect = '1/1';
                        if (is_file($_ck_mf)) { $_m = snapsmack_load_manifest($_ck_mf); if (!empty($_m['cover_aspect'])) $cover_aspect = (string)$_m['cover_aspect']; }
                        $cv_px = isset($edit_post['cover_pos_x']) ? (int)$edit_post['cover_pos_x'] : 50;
                        $cv_py = isset($edit_post['cover_pos_y']) ? (int)$edit_post['cover_pos_y'] : 50;
                        $cv_z  = isset($edit_post['cover_zoom'])  ? (int)$edit_post['cover_zoom']  : 100;
                        $cover_full = $featured_image_data ? BASE_URL . ltrim($featured_image_data['img_file'], '/') : '';
                        ?>
                        <div id="long-cover-crop-wrap" style="margin-top:10px;<?php echo $featured_image_data ? '' : 'display:none;'; ?>">
                            <label style="font-size:11px;">COVER FRAMING <span class="dim" style="font-weight:normal;">(drag to position, slide to zoom)</span></label>
                            <div id="lc-stage" data-aspect="<?php echo htmlspecialchars($cover_aspect, ENT_QUOTES); ?>"
                                 style="position:relative;width:100%;max-width:260px;aspect-ratio:<?php echo htmlspecialchars($cover_aspect, ENT_QUOTES); ?>;overflow:hidden;background:#111;border-radius:4px;border:1px solid var(--border);cursor:grab;touch-action:none;user-select:none;margin-top:4px;">
                                <img id="lc-cover-img" src="<?php echo htmlspecialchars($cover_full, ENT_QUOTES); ?>" alt=""
                                     style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:<?php echo $cv_px; ?>% <?php echo $cv_py; ?>%;transform-origin:<?php echo $cv_px; ?>% <?php echo $cv_py; ?>%;transform:scale(<?php echo number_format($cv_z / 100, 3); ?>);pointer-events:none;">
                            </div>
                            <input type="hidden" name="cover_pos_x" id="lc-pos-x" value="<?php echo $cv_px; ?>">
                            <input type="hidden" name="cover_pos_y" id="lc-pos-y" value="<?php echo $cv_py; ?>">
                            <input type="hidden" name="cover_zoom"  id="lc-zoom-val" value="<?php echo $cv_z; ?>">
                            <div style="display:flex;align-items:center;gap:10px;margin-top:6px;">
                                <label style="font-size:11px;display:flex;align-items:center;gap:6px;">ZOOM
                                    <input type="range" id="lc-zoom" min="100" max="300" step="1" value="<?php echo $cv_z; ?>" style="width:120px;">
                                </label>
                                <button type="button" id="lc-recenter" class="btn-secondary" style="font-size:11px;padding:4px 10px;">RE-CENTRE</button>
                            </div>
                        </div>
                    </div>

                    <div class="lens-input-wrapper mt-20">
                        <button type="submit" class="master-update-btn">
                            <?php echo $edit_post ? "UPDATE TRANSMISSION" : "TRANSMIT"; ?>
                        </button>
                    </div>

                    <?php if ($edit_post): ?>
                        <div class="lens-input-wrapper mt-10">
                            <a href="smack-post-long.php" class="btn-reset btn-cancel-block">NEW TRANSMISSION</a>
                        </div>
                        <div class="lens-input-wrapper mt-10">
                            <a href="?delete=<?php echo (int)$edit_post['id']; ?>&t=<?php echo urlencode(csrf_token()); ?>"
                               class="btn-reset btn-cancel-block"
                               style="color:var(--danger, #cc4444);border-color:var(--danger, #cc4444);"
                               onclick="return confirm('PURGE THIS TRANSMISSION? This cannot be undone.')">PURGE</a>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>

    </form>

    <!-- EXISTING LONGFORM POSTS LIST -->
    <?php if (!empty($all_posts)): ?>
    <div class="box" style="margin-top:20px;">
        <h3>LONGFORM TRANSMISSIONS</h3>
        <?php foreach ($all_posts as $lp): ?>
            <div class="recent-item">
                <div class="item-details">
                    <div class="item-text">
                        <strong><?php echo htmlspecialchars($lp['title']); ?></strong>
                        <?php if ($lp['status'] === 'draft'): ?>
                            <code class="slug-display" style="color:#c0392b;">DRAFT</code>
                        <?php endif; ?>
                        <code class="slug-display"><?php echo htmlspecialchars($lp['slug']); ?></code>
                        <span class="dim" style="font-size:0.8em;"><?php echo date('M j, Y', strtotime($lp['created_at'])); ?></span>
                    </div>
                </div>
                <div class="item-actions">
                    <a href="<?php echo htmlspecialchars(BASE_URL . '?id=' . $lp['id']); ?>" class="action-view" target="_blank" rel="noopener">VIEW</a>
                    <a href="?edit=<?php echo (int)$lp['id']; ?>" class="action-edit">EDIT</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<link rel="stylesheet" href="assets/css/ss-engine-ai-enrichment.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
<script src="assets/js/ss-engine-ai-enrichment.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>"></script>
<?php include 'core/admin-footer.php'; ?>

<!-- MOSAIC INSERT MODAL -->
<div id="mosaic-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:9000;overflow-y:auto;">
    <div style="background:var(--bg);margin:40px auto;max-width:600px;border-radius:4px;border:1px solid var(--border);padding:20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <span style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;">INSERT MOSAIC PANEL</span>
            <button type="button" onclick="closeMosaicModal()" style="background:none;border:none;color:var(--dim);font-size:20px;cursor:pointer;line-height:1;">×</button>
        </div>
        <div id="mosaic-modal-list" style="max-height:400px;overflow-y:auto;">
            <p class="dim" style="font-size:12px;padding:10px;">Loading mosaics…</p>
        </div>
        <p style="font-size:11px;color:var(--dim);margin-top:12px;">
            <?php /* Carries ?post= so the builder opens narrowed to THIS post's
                     bucket. Without it the builder has no idea which essay you
                     came from and can only offer the whole Gallery. */ ?>
            Don't see the one you want? <a href="smack-mosaics.php?new=1<?php echo $edit_post ? '&amp;post=' . (int)$edit_post['id'] : ''; ?>" target="_blank" style="color:var(--link);">Build a new mosaic →</a>
        </p>
    </div>
</div>

<!-- GALLERY IMAGE PICKER MODAL (snap_images / POST images) -->
<div id="gallery-pick-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:9002;overflow-y:auto;">
    <div style="background:var(--bg);margin:40px auto;max-width:860px;border-radius:4px;border:1px solid var(--border);padding:20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <span style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;">INSERT IMAGE FROM GALLERY</span>
            <button type="button" id="gallery-pick-close" style="background:none;border:none;color:var(--dim);font-size:20px;cursor:pointer;line-height:1;">×</button>
        </div>
        <input type="text" id="gallery-pick-search" placeholder="Search titles, descriptions, tags…"
               style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:3px;background:var(--input-bg);color:var(--text);font-size:13px;margin-bottom:12px;box-sizing:border-box;">
        <div id="gallery-pick-grid"
             data-base="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES); ?>"
             style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px;max-height:480px;overflow-y:auto;"></div>
        <p id="gallery-pick-empty" class="dim" style="font-size:12px;padding:10px;display:none;">
            No images in the Gallery yet. <a href="smack-gallery.php" target="_blank" style="color:var(--link);">Upload some →</a>
        </p>
    </div>
</div>

<script src="assets/js/smack-asset-picker.js"></script>
<script src="assets/js/shortcode-toolbar.js"></script>
<script src="assets/js/smack-longform-gallery-picker.js"></script>
<script src="assets/js/ss-engine-longform-cover-crop.js"></script>

<script>
// --- Slug auto-generation ---
var _slugManuallyEdited = <?php echo ($edit_post && !empty($edit_post['slug'])) ? 'true' : 'false'; ?>;
var _slugField = document.getElementById('long-slug');
var _titleField = document.getElementById('long-title');

function slugify(s) {
    return s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
}
_titleField.addEventListener('input', function () {
    if (!_slugManuallyEdited) {
        _slugField.value = slugify(this.value);
    }
});
_slugField.addEventListener('input', function () {
    _slugManuallyEdited = (this.value.trim() !== '');
});
_slugField.addEventListener('blur', function () {
    if (this.value.trim() === '') _slugManuallyEdited = false;
});

// --- Multi-select label updater for long post form ---
function updateLabelLong(labelId, dropId, defaultText) {
    var drop  = document.getElementById(dropId);
    var label = document.getElementById(labelId);
    var checked = drop.querySelectorAll('input[type=checkbox]:checked');
    if (checked.length === 0) {
        label.textContent = defaultText;
    } else {
        var names = [];
        checked.forEach(function (cb) {
            var span = cb.parentNode.querySelector('.cat-name-text');
            if (span) names.push(span.textContent.trim());
        });
        label.textContent = names.join(', ');
    }
}

// --- MOSAIC INSERT ---
function openMosaicModal() {
    var modal = document.getElementById('mosaic-modal');
    var list  = document.getElementById('mosaic-modal-list');
    modal.style.display = 'block';
    list.innerHTML = '<p class="dim" style="font-size:12px;padding:10px;">Loading…</p>';
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'smack-post-long.php?ajax=mosaics', true);
    xhr.onload = function () {
        if (xhr.status !== 200) {
            list.innerHTML = '<p class="dim" style="font-size:12px;padding:10px;">Failed to load mosaics.</p>';
            return;
        }
        var mosaics = JSON.parse(xhr.responseText);
        if (!mosaics.length) {
            list.innerHTML = '<p class="dim" style="font-size:12px;padding:10px;">No mosaics yet. <a href="smack-mosaics.php?new=1<?php echo $edit_post ? '&amp;post=' . (int)$edit_post['id'] : ''; ?>" target="_blank" style="color:var(--link);">Build one →</a></p>';
            return;
        }
        var html = '';
        mosaics.forEach(function (m) {
            html += '<div onclick="insertMosaic(' + m.id + ')" style="'
                  + 'cursor:pointer;padding:10px 14px;border:1px solid var(--border);border-radius:3px;'
                  + 'margin-bottom:6px;display:flex;justify-content:space-between;align-items:center;'
                  + 'transition:background .15s;"'
                  + ' onmouseover="this.style.background=\'var(--hover-bg)\'"'
                  + ' onmouseout="this.style.background=\'\'">'
                  + '<span style="font-size:13px;">' + m.title + '</span>'
                  + '<code style="font-size:11px;color:var(--dim);">[mosaic:' + m.id + ']</code>'
                  + '</div>';
        });
        list.innerHTML = html;
    };
    xhr.send();
}
function closeMosaicModal() {
    document.getElementById('mosaic-modal').style.display = 'none';
}
function insertMosaic(id) {
    var sc = '[mosaic:' + id + ']';
    var ta = document.getElementById('long-content');
    var start = ta.selectionStart, end = ta.selectionEnd;
    var before = ta.value.substring(0, start);
    var after  = ta.value.substring(end);
    // Insert on its own line with surrounding blank lines
    var insert = '\n\n' + sc + '\n\n';
    ta.value = before + insert + after;
    ta.selectionStart = ta.selectionEnd = start + insert.length;
    ta.focus();
    closeMosaicModal();
}
document.getElementById('mosaic-insert-btn').addEventListener('click', openMosaicModal);
document.getElementById('mosaic-modal').addEventListener('click', function (e) {
    if (e.target === this) closeMosaicModal();
});

// --- BUCKET: this post's working set of Gallery photos ---
// Whole block is inert on an unsaved post — there is no post id for a bucket to
// belong to, so the panel renders an explanation instead of these controls.
(function () {
    var panel = document.getElementById('bucket-panel');
    var strip = document.getElementById('bucket-selected');
    if (!panel || !strip) return;

    var POST_ID    = <?php echo $edit_post ? (int)$edit_post['id'] : 0; ?>;
    var BASE       = <?php echo json_encode(BASE_URL); ?>;
    var bucketIds  = [];      // ordered image ids in the bucket
    var known      = {};      // id → {id, name, path} for everything seen this session
    var gridOrder  = [];      // ids currently listed in the picker
    var lastPickIndex = null; // anchor for shift-click range selection
    var dirty      = false;   // unsaved changes?
    var searchTimer = null;

    var statusEl = document.getElementById('bucket-status');
    var picker   = document.getElementById('bucket-picker');
    var grid     = document.getElementById('bucket-grid');
    var countEl  = document.getElementById('bucket-count');
    var searchEl = document.getElementById('bucket-search');

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    var saveBtn = document.getElementById('bucket-save-btn');

    function setStatus(msg) {
        statusEl.textContent = msg;
    }

    // Clean: quiet count, save disabled (nothing to save). Dirty: save lights
    // accent and enables, so the signal to save lives on the button you press.
    function setClean(count) {
        dirty = false;
        setStatus(count ? count + ' photo' + (count === 1 ? '' : 's') + ' saved' : 'Empty');
        if (saveBtn) { saveBtn.classList.remove('is-dirty'); saveBtn.disabled = true; }
    }

    function markDirty() {
        dirty = true;
        setStatus(bucketIds.length + ' photo' + (bucketIds.length === 1 ? '' : 's') + ' — unsaved');
        if (saveBtn) { saveBtn.classList.add('is-dirty'); saveBtn.disabled = false; }
    }

    function load(q) {
        var url = 'smack-post-long.php?ajax=bucket&post_id=' + POST_ID
                + (q ? '&q=' + encodeURIComponent(q) : '');
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.onload = function () {
            if (xhr.status !== 200) { setStatus('Could not load the gallery.', true); return; }
            var resp;
            try { resp = JSON.parse(xhr.responseText); }
            catch (e) { setStatus('Could not load the gallery.', true); return; }

            gridOrder = [];
            lastPickIndex = null;   // grid order just changed; the old anchor is meaningless
            (resp.images || []).forEach(function (im) {
                known[im.id] = im;
                gridOrder.push(parseInt(im.id, 10));
            });
            // Known so the strip can draw them, but NOT in gridOrder — the grid
            // shows what the search asked for, the strip shows the bucket.
            (resp.extra || []).forEach(function (im) { known[im.id] = im; });
            // Only adopt the SAVED bucket on the first load. Re-reading it after
            // a search would throw away picks not yet saved.
            if (!dirty) {
                bucketIds = (resp.bucket || []).map(function (n) { return parseInt(n, 10); });
                setClean(bucketIds.length);
            }
            // A capped list must never look like the whole list.
            countEl.textContent = resp.capped
                ? 'Showing the ' + resp.shown + ' newest of ' + resp.total + ' photos — search by name to reach the rest.'
                : 'Showing all ' + resp.total + ' photo' + (resp.total === 1 ? '' : 's') + '.';
            renderGrid();
            renderStrip();
        };
        xhr.send();
    }

    function renderStrip() {
        if (!bucketIds.length) {
            strip.innerHTML = '<p class="dim" style="padding:10px;margin:0;font-size:12px;">'
                            + 'Nothing in the bucket yet. Add the photos this post is built from.</p>';
            return;
        }
        var html = '';
        bucketIds.forEach(function (id) {
            var im = known[id];
            if (!im) return;
            // object-fit:contain — the shape of a photo is the thing that matters
            // when these are headed for an arrangement.
            html += '<div style="position:relative;width:72px;height:72px;border:1px solid var(--border);'
                  + 'border-radius:3px;overflow:hidden;flex-shrink:0;background:#111;" title="' + esc(im.name || '') + '">'
                  + '<img src="' + BASE + im.path + '" style="width:100%;height:100%;object-fit:contain;" loading="lazy" alt="">'
                  + '<button type="button" data-remove="' + id + '" title="Remove from bucket" aria-label="Remove from bucket"'
                  + ' style="position:absolute;top:2px;right:2px;background:rgba(0,0,0,.7);border:none;color:#ff5555;'
                  + 'cursor:pointer;width:20px;height:20px;border-radius:50%;font-size:13px;line-height:1;padding:0;'
                  + 'display:flex;align-items:center;justify-content:center;">×</button>'
                  + '</div>';
        });
        strip.innerHTML = html;
    }

    function renderGrid() {
        var html = '';
        gridOrder.forEach(function (id) {
            var im = known[id];
            if (!im) return;
            var inBucket = bucketIds.indexOf(id) !== -1;
            html += '<div data-pick="' + id + '" title="' + esc(im.name || '') + '"'
                  + ' style="cursor:pointer;position:relative;aspect-ratio:1;border:2px solid '
                  + (inBucket ? 'var(--accent)' : 'transparent') + ';border-radius:3px;overflow:hidden;background:#111;">'
                  + '<img src="' + BASE + im.path + '" style="width:100%;height:100%;object-fit:contain;" loading="lazy" alt="">';
            if (inBucket) {
                html += '<div style="position:absolute;top:3px;right:3px;background:var(--accent);color:#111;'
                      + 'border-radius:50%;width:18px;height:18px;display:flex;align-items:center;'
                      + 'justify-content:center;font-size:11px;font-weight:700;">&#10003;</div>';
            }
            html += '</div>';
        });
        grid.innerHTML = html || '<p style="color:var(--dim);font-size:12px;grid-column:1/-1;">No photos match.</p>';
    }

    // Delegated clicks: the grid and strip are re-rendered constantly, so
    // per-tile handlers would be rebound on every keystroke of a search.
    // lastPickIndex is the anchor for shift-click range selection; reset on
    // every load() because the grid order changes with search/scope.
    grid.addEventListener('click', function (e) {
        var tile = e.target.closest('[data-pick]');
        if (!tile) return;
        var id  = parseInt(tile.getAttribute('data-pick'), 10);
        var idx = gridOrder.indexOf(id);

        if (e.shiftKey && lastPickIndex !== null && idx !== -1) {
            // Shift-click grabs the whole run between the last photo you clicked
            // and this one — and ADDS them (never toggles off), so dragging a
            // range can only ever build the selection, not silently gut it.
            var lo = Math.min(lastPickIndex, idx), hi = Math.max(lastPickIndex, idx);
            for (var k = lo; k <= hi; k++) {
                var rid = gridOrder[k];
                if (bucketIds.indexOf(rid) === -1) bucketIds.push(rid);
            }
        } else {
            var i = bucketIds.indexOf(id);
            if (i === -1) bucketIds.push(id); else bucketIds.splice(i, 1);
        }
        lastPickIndex = idx;
        markDirty();
        renderGrid();
        renderStrip();
    });

    strip.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-remove]');
        if (!btn) return;
        var id = parseInt(btn.getAttribute('data-remove'), 10);
        var i  = bucketIds.indexOf(id);
        if (i !== -1) {
            bucketIds.splice(i, 1);
            markDirty();
            renderGrid();
            renderStrip();
        }
    });

    document.getElementById('bucket-add-btn').addEventListener('click', function () {
        var open = picker.style.display !== 'none';
        picker.style.display = open ? 'none' : 'block';
        if (!open && !gridOrder.length) load('');
    });
    document.getElementById('bucket-picker-close').addEventListener('click', function () {
        picker.style.display = 'none';
    });

    searchEl.addEventListener('input', function () {
        clearTimeout(searchTimer);
        var q = searchEl.value.trim();
        searchTimer = setTimeout(function () { load(q); }, 250);
    });

    document.getElementById('bucket-save-btn').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        setStatus('Saving…');
        var body = 'action=save_bucket&post_id=' + POST_ID
                 + '&image_ids=' + encodeURIComponent(JSON.stringify(bucketIds));
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'smack-post-long.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function () {
            btn.disabled = false;
            var resp;
            try { resp = JSON.parse(xhr.responseText); }
            catch (e) {
                // A 403 from the CSRF guard arrives as plain text, not JSON.
                setStatus('Could not save — reload the page and try again.', true);
                return;
            }
            if (resp.ok) {
                setClean(resp.count);           // disables save, drops the accent
            } else {
                setStatus(resp.error || 'Could not save the bucket.');
            }
        };
        xhr.onerror = function () {
            btn.disabled = false;
            setStatus('Could not save — check your connection and try again.', true);
        };
        xhr.send(body);
    });

    // Leaving with unsaved picks loses them, and the mosaic builder would then
    // show a bucket that does not match what is on screen. BUT submitting the
    // post itself (UPDATE TRANSMISSION / TRANSMIT) is a save, not a "leave" —
    // it must never trigger the warning. Same for the mosaic link, which opens
    // in a new tab. So the guard only fires on a genuine navigation away.
    var postForm = document.getElementById('long-post-form');
    if (postForm) postForm.addEventListener('submit', function () { dirty = false; });

    window.addEventListener('beforeunload', function (e) {
        if (!dirty) return;
        e.preventDefault();
        e.returnValue = '';
    });

    load('');
}());

</script>
<?php // ===== SNAPSMACK EOF =====
