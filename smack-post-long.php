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
require_once 'core/snap-tags.php';

if (!isset($settings)) {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
}
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
        } elseif (preg_match('/^\[img:\s*\d+(?:\s*\|[^\]]*)*\]$/', $trimmed)) {
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
         ORDER BY p.created_at DESC
         LIMIT 80"
    );
    $posts->execute([$q]);
    echo json_encode($posts->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// --- DELETE ---
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM snap_post_cat_map WHERE post_id = ?")->execute([$del_id]);
    $pdo->prepare("DELETE FROM snap_post_album_map WHERE post_id = ?")->execute([$del_id]);
    $pdo->prepare("DELETE FROM snap_tags WHERE image_id = ?")->execute([$del_id]);
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
    $featured_asset   = !empty($_POST['featured_asset_id']) ? (int)$_POST['featured_asset_id'] : null;
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
                featured_asset_id=?" .
                ($custom_date ? ", created_at=?" : "") . "
            WHERE id=? AND post_type='longform'
        ");
        $params = [$title, $slug, $content_html, $status, $allow_comments, $featured_asset];
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
                (title, slug, content, post_type, status, allow_comments, featured_asset_id" .
                ($custom_date ? ", created_at" : "") . ")
            VALUES (?, ?, ?, 'longform', ?, ?, ?" .
                ($custom_date ? ", ?" : "") . ")
        ");
        $params = [$title, $slug, $content_html, $status, $allow_comments, $featured_asset];
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
        $et = $pdo->prepare("SELECT tag FROM snap_tags WHERE image_id = ?");
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
     ORDER BY p.created_at DESC
     LIMIT 200"
)->fetchAll();

// Fetch featured asset for edit mode
$featured_asset_data = null;
if ($edit_post && !empty($edit_post['featured_asset_id'])) {
    $fas = $pdo->prepare("SELECT id, asset_name, asset_path FROM snap_assets WHERE id = ?");
    $fas->execute([$edit_post['featured_asset_id']]);
    $featured_asset_data = $fas->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Media library for featured image picker
$all_assets = $pdo->query("SELECT id, asset_name, asset_path FROM snap_assets ORDER BY created_at DESC LIMIT 300")->fetchAll();

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
                    <button type="button" class="sc-btn sc-btn-preview" data-action="preview" title="Preview in New Tab">PREVIEW</button>
                </div>
            </div>
            <textarea id="long-content" name="content" rows="28"
                      style="width:100%;box-sizing:border-box;font-family:monospace;font-size:13px;"
                      placeholder="Write something worth saying. Blank lines become paragraph breaks. Embed image shortcodes and MOSAIC panels inline."><?php echo htmlspecialchars($edit_content ?? ($edit_post['content'] ?? '')); ?></textarea>
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

                    <!-- HERO IMAGE (from media library) -->
                    <div class="lens-input-wrapper mt-10">
                        <label>HERO IMAGE <span class="field-tip" data-tip="Primary image for this post, selected from your media library.">ⓘ</span></label>
                        <input type="hidden" name="featured_asset_id" id="long-hero-asset-id"
                               value="<?php echo $featured_asset_data ? (int)$featured_asset_data['id'] : ''; ?>">
                        <div id="long-hero-preview" style="margin-top:6px;">
                            <?php if ($featured_asset_data): ?>
                                <?php $hero_url = BASE_URL . ltrim($featured_asset_data['asset_path'], '/'); ?>
                                <img src="<?php echo htmlspecialchars($hero_url); ?>"
                                     style="width:100%;max-width:200px;height:auto;border-radius:3px;border:1px solid var(--border);"
                                     alt="">
                                <span class="dim" style="display:block;font-size:11px;margin-top:4px;"><?php echo htmlspecialchars($featured_asset_data['asset_name']); ?></span>
                            <?php else: ?>
                                <div style="width:100%;max-width:200px;height:80px;background:var(--card-bg);border:1px dashed var(--border);border-radius:3px;display:flex;align-items:center;justify-content:center;">
                                    <span class="dim" style="font-size:10px;text-align:center;padding:4px;">NO HERO</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;gap:8px;margin-top:8px;">
                            <button type="button" onclick="openHeroPicker()" class="btn-secondary" style="font-size:11px;padding:5px 12px;">
                                <?php echo $featured_asset_data ? 'CHANGE' : 'SELECT HERO'; ?>
                            </button>
                            <?php if ($featured_asset_data): ?>
                                <button type="button" onclick="clearHero()" class="btn-secondary" style="font-size:11px;padding:5px 12px;color:var(--dim);">REMOVE</button>
                            <?php endif; ?>
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
                            <a href="?delete=<?php echo (int)$edit_post['id']; ?>"
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
                    <a href="<?php echo htmlspecialchars(BASE_URL . 'post.php?id=' . $lp['id']); ?>" class="action-view" target="_blank" rel="noopener">VIEW</a>
                    <a href="?edit=<?php echo (int)$lp['id']; ?>" class="action-edit">EDIT</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

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
            Don't see the one you want? <a href="smack-mosaics.php" target="_blank" style="color:var(--link);">Build a new mosaic →</a>
        </p>
    </div>
</div>

<!-- HERO IMAGE PICKER MODAL (media library) -->
<div id="hero-picker-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:9001;overflow-y:auto;">
    <div style="background:var(--bg);margin:40px auto;max-width:860px;border-radius:4px;border:1px solid var(--border);padding:20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <span style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;">SELECT HERO IMAGE</span>
            <button type="button" onclick="closeHeroPicker()" style="background:none;border:none;color:var(--dim);font-size:20px;cursor:pointer;line-height:1;">×</button>
        </div>
        <input type="text" id="hero-search" placeholder="Filter by name…" oninput="filterHeroGrid(this.value)"
               style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:3px;background:var(--input-bg);color:var(--text);font-size:13px;margin-bottom:12px;box-sizing:border-box;">
        <div id="hero-asset-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px;max-height:480px;overflow-y:auto;"></div>
    </div>
</div>

<script src="assets/js/smack-asset-picker.js"></script>
<script src="assets/js/shortcode-toolbar.js"></script>

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
            list.innerHTML = '<p class="dim" style="font-size:12px;padding:10px;">No mosaics yet. <a href="smack-mosaics.php" target="_blank" style="color:var(--link);">Build one →</a></p>';
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

// --- HERO ASSET PICKER ---
var _heroAssets = <?php echo json_encode(array_values($all_assets), JSON_HEX_TAG); ?>;
var _heroBase   = <?php echo json_encode(BASE_URL); ?>;

function openHeroPicker() {
    document.getElementById('hero-picker-modal').style.display = 'block';
    document.getElementById('hero-search').value = '';
    renderHeroGrid(_heroAssets);
}
function closeHeroPicker() {
    document.getElementById('hero-picker-modal').style.display = 'none';
}
function filterHeroGrid(q) {
    q = q.toLowerCase();
    var filtered = q ? _heroAssets.filter(function (a) {
        return a.asset_name.toLowerCase().indexOf(q) !== -1;
    }) : _heroAssets;
    renderHeroGrid(filtered);
}
function renderHeroGrid(assets) {
    var grid = document.getElementById('hero-asset-grid');
    if (!assets.length) {
        grid.innerHTML = '<p class="dim" style="font-size:12px;padding:10px;">No assets found.</p>';
        return;
    }
    var html = '';
    assets.forEach(function (a) {
        var src = _heroBase + a.asset_path.replace(/^\//, '');
        html += '<div onclick="selectHeroAsset(' + a.id + ',\'' + src + '\',\'' + a.asset_name.replace(/'/g, "\\'") + '\')"'
              + ' style="cursor:pointer;border:2px solid transparent;border-radius:3px;overflow:hidden;aspect-ratio:1;background:#111;">'
              + '<img src="' + src + '" style="width:100%;height:100%;object-fit:cover;" loading="lazy">'
              + '</div>';
    });
    grid.innerHTML = html;
}
function selectHeroAsset(id, src, name) {
    document.getElementById('long-hero-asset-id').value = id;
    document.getElementById('long-hero-preview').innerHTML =
        '<img src="' + src + '" style="width:100%;max-width:200px;height:auto;border-radius:3px;border:1px solid var(--border);" alt="">'
        + '<span class="dim" style="display:block;font-size:11px;margin-top:4px;">' + name + '</span>'
        + '<div style="display:flex;gap:8px;margin-top:8px;">'
        + '<button type="button" onclick="openHeroPicker()" class="btn-secondary" style="font-size:11px;padding:5px 12px;">CHANGE</button>'
        + '<button type="button" onclick="clearHero()" class="btn-secondary" style="font-size:11px;padding:5px 12px;color:var(--dim);">REMOVE</button>'
        + '</div>';
    closeHeroPicker();
}
function clearHero() {
    document.getElementById('long-hero-asset-id').value = '';
    document.getElementById('long-hero-preview').innerHTML =
        '<div style="width:100%;max-width:200px;height:80px;background:var(--card-bg);border:1px dashed var(--border);border-radius:3px;display:flex;align-items:center;justify-content:center;">'
        + '<span class="dim" style="font-size:10px;text-align:center;padding:4px;">NO HERO</span></div>'
        + '<div style="display:flex;gap:8px;margin-top:8px;">'
        + '<button type="button" onclick="openHeroPicker()" class="btn-secondary" style="font-size:11px;padding:5px 12px;">SELECT HERO</button>'
        + '</div>';
}
document.getElementById('hero-picker-modal').addEventListener('click', function (e) {
    if (e.target === this) closeHeroPicker();
});
</script>
<?php // ===== SNAPSMACK EOF =====
