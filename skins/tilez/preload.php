<?php
/**
 * SNAPSMACK - Alfred skin SmackTalk router (preload hook)
 * v1.0.0
 *
 * Included by index.php immediately after settings load, before image routing.
 * Handles all Alfred/SmackTalk requests and exit()s so index.php's image
 * logic never runs.
 *
 * Routes:
 *   ?post=<slug>   → single longform post by slug
 *   ?id=<int>      → single longform post by ID (admin-generated links)
 *   (bare request) → paginated feed of longform posts
 *
 * Falls through (no exit) only when the active skin is not Alfred, or when the
 * request resolves to something Alfred doesn't handle (rare; index.php picks up).
 *
 * Variables available from index.php at include time:
 *   $pdo, $settings, $active_skin, $requested_slug, BASE_URL, SNAPSMACK_VERSION_SHORT
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// Only intercept when Alfred is actually the active skin
if (($settings['active_skin'] ?? '') !== 'tilez') return;

// ============================================================
//  ARCHIVE VIEW  (grid of INDIVIDUAL PHOTOGRAPHS → lightbox)
// ============================================================
//
// ALFRED's ARCHIVE nav link (?view=archive) shows the site's individual
// published photographs — rows in snap_images (the Media Gallery), NOT posts
// and NOT snap_assets. Each thumbnail opens a full-screen lightbox that
// navigates the WHOLE archive set (swipe / arrow buttons / arrow keys).
//
// Placed BEFORE the single/feed routing so ?view=archive is claimed here first.
if (($_GET['view'] ?? '') === 'archive') {

    // Respect the archive disable switch (matches core archive.php + the nav gate).
    if (($settings['archive_layout'] ?? 'square') === 'none') {
        header('Location: ' . BASE_URL, true, 302);
        exit();
    }

    // Pull every published photograph, newest first. Thumbnail = img_thumb_square
    // (relative path already stored in DB) with fallback to a derived square-crop
    // thumb path then the full file; full image = img_file. Same fallback shape
    // used by smack-gallery.php.
    try {
        $_alfred_arch_stmt = $pdo->query(
            "SELECT id, img_title, img_file, img_thumb_square, img_thumb_aspect
             FROM snap_images
             WHERE img_status = 'published'
               AND LOWER(img_title) NOT LIKE '%signature%'
               AND LOWER(img_title) NOT LIKE '%autograph%'
               AND LOWER(img_title) NOT LIKE '%sean-mccormick-black-low-res%'
             ORDER BY sort_order ASC, id DESC"
        );
        $_alfred_images = $_alfred_arch_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $_alfred_images = [];
    }

    // Resolve thumb + full URLs for each image.
    $_alfred_tiles = [];
    foreach ($_alfred_images as $_img) {
        $full_rel = ltrim($_img['img_file'] ?? '', '/');
        if ($full_rel === '') continue;

        // Thumb: prefer stored square thumb, then aspect thumb, then derived
        // thumbs/t_<file>, finally the full image itself.
        $thumb_rel = '';
        if (!empty($_img['img_thumb_square'])) {
            $thumb_rel = ltrim($_img['img_thumb_square'], '/');
        } elseif (!empty($_img['img_thumb_aspect'])) {
            $thumb_rel = ltrim($_img['img_thumb_aspect'], '/');
        } else {
            $dir  = trim(str_replace(basename($full_rel), '', $full_rel), '/');
            $base = basename($full_rel);
            $thumb_rel = ($dir !== '' ? $dir . '/' : '') . 'thumbs/t_' . $base;
        }

        $_alfred_tiles[] = [
            'full'  => BASE_URL . $full_rel,
            'thumb' => BASE_URL . $thumb_rel,
            'title' => (string)($_img['img_title'] ?? ''),
        ];
    }

    $page_title = 'ARCHIVE';

    ?><!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($settings['site_language'] ?? 'en'); ?>">
<head>
<?php include __DIR__ . '/skin-meta.php'; ?>
</head>
<body class="archive alfred-archive">

<?php include __DIR__ . '/skin-header.php'; ?>

<main class="content" role="main">

    <section class="section-inner">

        <?php if (empty($_alfred_tiles)): ?>
        <p style="color:#fff;text-align:center;padding:4rem 0;">NO PHOTOGRAPHS YET.</p>
        <?php else: ?>

        <div class="alfred-archive-grid">
        <?php foreach ($_alfred_tiles as $_t): ?>
            <a href="<?php echo htmlspecialchars($_t['full'], ENT_QUOTES); ?>"
               class="alfred-archive-tile"
               data-full="<?php echo htmlspecialchars($_t['full'], ENT_QUOTES); ?>"
               data-title="<?php echo htmlspecialchars($_t['title'], ENT_QUOTES); ?>"
               title="<?php echo htmlspecialchars($_t['title'], ENT_QUOTES); ?>">
                <img src="<?php echo htmlspecialchars($_t['thumb'], ENT_QUOTES); ?>"
                     alt="<?php echo htmlspecialchars($_t['title'], ENT_QUOTES); ?>"
                     loading="lazy">
            </a>
        <?php endforeach; ?>
        </div><!-- /.alfred-archive-grid -->

        <?php endif; ?>

    </section><!-- /.section-inner -->

</main><!-- /.content -->

<!-- Full-screen archive lightbox (navigates the WHOLE set) -->
<div id="alfred-archive-lightbox" class="alfred-lightbox" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-label="Photograph viewer">
    <button type="button" class="alfred-lb-close" aria-label="Close">&#10005;</button>
    <button type="button" class="alfred-lb-prev" aria-label="Previous photograph">&#8249;</button>
    <img class="alfred-lb-img" src="" alt="">
    <button type="button" class="alfred-lb-next" aria-label="Next photograph">&#8250;</button>
    <p class="alfred-lb-caption"></p>
</div>

<?php
// Enqueue the ALFRED archive lightbox engine (external JS — no inline scripts
// in skins). skin-footer.php loads manifest scripts + core footer scripts; this
// archive-specific engine is only needed on this view, so it's enqueued here.
?>
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-alfred-archive.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>"></script>

<?php include __DIR__ . '/skin-footer.php'; ?>

</body>
</html>
<?php
    exit();
}

// --- ROUTING ---

$_alfred_post_slug = $_GET['post'] ?? null;
$_alfred_post_id   = isset($_GET['id']) ? (int)$_GET['id'] : null;

// Treat ?s=<slug> / ?name=<slug> as a post slug if the site is SmackTalk
if (empty($_alfred_post_slug) && !empty($requested_slug)) {
    // Check if this slug belongs to a longform post before claiming the request.
    // If not, fall through so index.php can try it as an image.
    try {
        $_alfred_slug_check = $pdo->prepare(
            "SELECT id FROM snap_posts WHERE slug = ? AND post_type = 'longform' AND status = 'published' LIMIT 1"
        );
        $_alfred_slug_check->execute([$requested_slug]);
        if ($_alfred_slug_check->fetchColumn()) {
            $_alfred_post_slug = $requested_slug;
        }
    } catch (PDOException $e) { /* fall through */ }
}

// ============================================================
//  SINGLE POST VIEW
// ============================================================

if ($_alfred_post_slug || $_alfred_post_id) {

    try {
        if ($_alfred_post_slug) {
            $stmt = $pdo->prepare(
                "SELECT p.*, i.img_file AS featured_image_path
                 FROM snap_posts p
                 LEFT JOIN snap_images i ON i.id = p.featured_image_id
                 WHERE p.slug = ? AND p.post_type = 'longform' AND p.status = 'published'
                 LIMIT 1"
            );
            $stmt->execute([$_alfred_post_slug]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT p.*, i.img_file AS featured_image_path
                 FROM snap_posts p
                 LEFT JOIN snap_images i ON i.id = p.featured_image_id
                 WHERE p.id = ? AND p.post_type = 'longform' AND p.status = 'published'
                 LIMIT 1"
            );
            $stmt->execute([$_alfred_post_id]);
        }
        $_alfred_post = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $_alfred_post = null;
    }

    if (!$_alfred_post) {
        http_response_code(404);
        // Fall through to a simple 404 page
        $page_title = '404 — Not Found';
        include __DIR__ . '/skin-meta.php';
        ?><!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>404 — Not Found</title></head>
<body style="background:#fff;padding:4rem;font-family:sans-serif;text-align:center;">
    <h1 style="font-size:2rem;text-transform:uppercase;letter-spacing:.05em;">Post not found</h1>
    <p><a href="<?php echo BASE_URL; ?>" style="color:#1e73be;">← Back to the front</a></p>
</body></html>
        <?php
        exit();
    }

    // --- Prev / Next navigation ---
    try {
        $nav_stmt = $pdo->prepare(
            "SELECT slug, title FROM snap_posts
             WHERE post_type = 'longform' AND status = 'published' AND id < ?
             ORDER BY id DESC LIMIT 1"
        );
        $nav_stmt->execute([$_alfred_post['id']]);
        $_alfred_prev = $nav_stmt->fetch(PDO::FETCH_ASSOC);

        $nav_stmt = $pdo->prepare(
            "SELECT slug, title FROM snap_posts
             WHERE post_type = 'longform' AND status = 'published' AND id > ?
             ORDER BY id ASC LIMIT 1"
        );
        $nav_stmt->execute([$_alfred_post['id']]);
        $_alfred_next = $nav_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $_alfred_prev = $_alfred_next = null;
    }

    // --- OG / page title for meta ---
    $page_title = htmlspecialchars($_alfred_post['title']);

    // Assemble the quiet editorial record shown beside the photo essay.
    $_alfred_categories = [];
    $_alfred_albums = [];
    $_alfred_signature = null;
    $_alfred_author = trim((string)($settings['site_author'] ?? ''));
    try {
        $meta_stmt = $pdo->prepare(
            "SELECT c.cat_name
             FROM snap_post_cat_map pcm
             JOIN snap_categories c ON c.id = pcm.cat_id
             WHERE pcm.post_id = ? ORDER BY c.cat_name"
        );
        $meta_stmt->execute([(int)$_alfred_post['id']]);
        $_alfred_categories = $meta_stmt->fetchAll(PDO::FETCH_COLUMN);

        $meta_stmt = $pdo->prepare(
            "SELECT a.album_name
             FROM snap_post_album_map pam
             JOIN snap_albums a ON a.id = pam.album_id
             WHERE pam.post_id = ? ORDER BY a.album_name"
        );
        $meta_stmt->execute([(int)$_alfred_post['id']]);
        $_alfred_albums = $meta_stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($_alfred_post['user_id'])) {
            $meta_stmt = $pdo->prepare("SELECT username FROM snap_users WHERE id = ? LIMIT 1");
            $meta_stmt->execute([(int)$_alfred_post['user_id']]);
            $_alfred_author = trim((string)($meta_stmt->fetchColumn() ?: $_alfred_author));
        }

        // WordPress treated the author's handwritten signature as one of the
        // post images. The importer kept the file and its post relationship,
        // but intentionally omitted the decorative image from the prose. Put
        // it back where it belongs: in the post colophon beside the essay.
        $meta_stmt = $pdo->prepare(
            "SELECT i.img_file, i.img_alt, i.img_title
             FROM snap_post_images pi
             JOIN snap_images i ON i.id = pi.image_id
             WHERE pi.post_id = ?
               AND (LOWER(i.img_title) LIKE '%signature%'
                    OR LOWER(i.img_title) LIKE '%autograph%'
                    OR LOWER(i.img_title) LIKE '%sean-mccormick-black-low-res%')
             ORDER BY pi.sort_position DESC
             LIMIT 1"
        );
        $meta_stmt->execute([(int)$_alfred_post['id']]);
        $_alfred_signature = $meta_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$_alfred_signature) {
            $meta_stmt = $pdo->query(
                "SELECT img_file, img_alt, img_title
                 FROM snap_images
                 WHERE img_status = 'published'
                   AND (LOWER(img_title) LIKE '%signature%'
                        OR LOWER(img_title) LIKE '%autograph%'
                        OR LOWER(img_title) LIKE '%sean-mccormick-black-low-res%')
                 ORDER BY id DESC
                 LIMIT 1"
            );
            $_alfred_signature = $meta_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (PDOException $e) {
        // Older installs may not have every relationship table yet. The record
        // remains useful with the metadata that is available.
    }
    if ($_alfred_author === '') {
        $_alfred_author = (string)($settings['site_name'] ?? '');
    }

    require_once dirname(__DIR__, 2) . '/core/parser.php';
    $_alfred_parser = new SnapSmack($pdo);
    $_alfred_rendered = $_alfred_parser->parseContent($_alfred_post['content'] ?? '');

    // Camera/equipment copy is authored as part of the post, but TILEZ presents
    // it as publication ephemera rather than leaving it stranded at the bottom.
    $_alfred_gear_note = '';
    if (preg_match('~<(p|div)\b[^>]*class=(?:"[^"]*\bpost-gear-note\b[^"]*"|\'[^\']*\bpost-gear-note\b[^\']*\')[^>]*>.*?</\1>~is', $_alfred_rendered, $gear_match)) {
        $_alfred_gear_note = $gear_match[0];
        $_alfred_rendered = str_replace($gear_match[0], '', $_alfred_rendered);
    }
    // WordPress imports predate the explicit .post-gear-note marker. When the
    // final paragraph clearly inventories the equipment used for the shoot,
    // treat it as the legacy colophon instead of making the author re-edit an
    // already published essay.
    if ($_alfred_gear_note === '' && preg_match(
        '~(<p\b[^>]*>(?:(?!<p\b).)*?</p>)(?:\s*</div>\s*)*$~is',
        $_alfred_rendered,
        $legacy_match,
        PREG_OFFSET_CAPTURE
    )) {
        $legacy_paragraph = $legacy_match[1][0];
        $legacy_offset = $legacy_match[1][1];
        $legacy_text = mb_strtolower(trim(html_entity_decode(strip_tags($legacy_paragraph), ENT_QUOTES | ENT_HTML5)));
        $legacy_has_lead = preg_match(
            '/\b(?:main camera|camera used|also used|equipment used|shot (?:on|with)|taken with|photos? (?:taken|made|shot) with|images? (?:taken|made|shot) with|photographed with)\b/u',
            $legacy_text
        );
        preg_match_all('/\b(?:camera|body|lens|lenses|film|film stock|drone|cellphone|phone|iphone|galaxy|pixel|canon|nikon|sony|fujifilm|fuji|olympus|pentax|leica|hasselblad|dji|kodak|ilford|helios|eos)\b/u', $legacy_text, $legacy_equipment_matches);
        $legacy_equipment_terms = array_unique($legacy_equipment_matches[0] ?? []);
        $legacy_is_colophon = (bool)$legacy_has_lead || count($legacy_equipment_terms) >= 3;
        if ($legacy_is_colophon) {
            $_alfred_gear_note = preg_replace('/^<p\b(?![^>]*\bclass=)/i', '<p class="post-gear-note"', $legacy_paragraph, 1);
            $_alfred_rendered = substr($_alfred_rendered, 0, $legacy_offset)
                . substr($_alfred_rendered, $legacy_offset + strlen($legacy_paragraph));
        }
    }

    $_alfred_photo_count = preg_match_all('/<img\b/i', $_alfred_rendered, $photo_matches);
    if (preg_match_all('/\bdata-mosaic=(?:"([^"]*)"|\'([^\']*)\')/i', $_alfred_rendered, $mosaic_matches, PREG_SET_ORDER)) {
        foreach ($mosaic_matches as $mosaic_match) {
            $mosaic_json = html_entity_decode((string)($mosaic_match[1] !== '' ? $mosaic_match[1] : $mosaic_match[2]), ENT_QUOTES | ENT_HTML5);
            $mosaic_items = json_decode($mosaic_json, true);
            if (is_array($mosaic_items)) {
                $_alfred_photo_count += count($mosaic_items);
            }
        }
    }
    $_alfred_plain_words = preg_replace('/\[[^\]]+\]/', ' ', (string)($_alfred_post['content'] ?? ''));
    $_alfred_plain_words = html_entity_decode(strip_tags((string)$_alfred_plain_words), ENT_QUOTES | ENT_HTML5);
    preg_match_all('/[\p{L}\p{N}]+(?:[’\'\-][\p{L}\p{N}]+)*/u', $_alfred_plain_words, $word_matches);
    $_alfred_word_count = count($word_matches[0]);

    ?><!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($settings['site_language'] ?? 'en'); ?>">
<head>
<?php include __DIR__ . '/skin-meta.php'; ?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/columns.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/shortcodes.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-mosaic.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
</head>
<body class="single">

<?php include __DIR__ . '/skin-header.php'; ?>

<main class="content" role="main">

    <article class="post-container h-entry">
        <?php snapsmack_indieweb_longform_properties($_alfred_post, $settings); ?>

        <div class="post-inner">
            <div class="post-content entry-content e-content" data-merge-adjacent-mosaics>
                <?php echo $_alfred_rendered; ?>
            </div>

            <aside class="post-record" aria-label="Post details">
                <div class="post-header">
                    <h1 class="post-title p-name"><?php echo htmlspecialchars($_alfred_post['title']); ?></h1>
                    <p class="post-date"><time class="dt-published" datetime="<?php echo htmlspecialchars(date(DATE_ATOM, strtotime($_alfred_post['created_at']))); ?>"><?php echo date('F j, Y', strtotime($_alfred_post['created_at'])); ?></time></p>
                </div>
                <dl class="post-facts">
                    <div><dt>Photos</dt><dd><?php echo number_format($_alfred_photo_count); ?></dd></div>
                    <div><dt>Words</dt><dd><?php echo number_format($_alfred_word_count); ?></dd></div>
                    <?php if ($_alfred_categories): ?><div><dt>Category</dt><dd><?php echo htmlspecialchars(implode(', ', $_alfred_categories)); ?></dd></div><?php endif; ?>
                    <?php if ($_alfred_albums): ?><div><dt>Album</dt><dd><?php echo htmlspecialchars(implode(', ', $_alfred_albums)); ?></dd></div><?php endif; ?>
                    <?php if ($_alfred_author !== ''): ?><div><dt>Author</dt><dd class="p-author"><?php echo htmlspecialchars($_alfred_author); ?></dd></div><?php endif; ?>
                </dl>
                <?php if ($_alfred_signature || $_alfred_gear_note !== ''): ?>
                <div class="post-record-gear">
                    <?php if ($_alfred_signature): ?>
                    <div class="post-signature">
                        <img src="<?php echo htmlspecialchars(BASE_URL . ltrim((string)$_alfred_signature['img_file'], '/'), ENT_QUOTES); ?>"
                             alt="<?php echo htmlspecialchars((string)($_alfred_signature['img_alt'] ?: $_alfred_signature['img_title']), ENT_QUOTES); ?>">
                    </div>
                    <?php endif; ?>
                    <?php if ($_alfred_gear_note !== ''): ?>
                    <h2>Camera notes</h2>
                    <?php echo $_alfred_gear_note; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </aside>

            <?php if ($_alfred_signature || $_alfred_gear_note !== ''): ?>
            <div class="post-mobile-gear">
                <?php if ($_alfred_signature): ?>
                <div class="post-signature">
                    <img src="<?php echo htmlspecialchars(BASE_URL . ltrim((string)$_alfred_signature['img_file'], '/'), ENT_QUOTES); ?>"
                         alt="<?php echo htmlspecialchars((string)($_alfred_signature['img_alt'] ?: $_alfred_signature['img_title']), ENT_QUOTES); ?>">
                </div>
                <?php endif; ?>
                <?php if ($_alfred_gear_note !== ''): ?>
                <h2>Camera notes</h2>
                <?php echo $_alfred_gear_note; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div><!-- /.post-inner -->

    </article><!-- /.post-container -->

    <!-- Prev/Next navigation -->
    <?php if ($_alfred_prev || $_alfred_next): ?>
    <nav class="post-navigation" aria-label="Post navigation">
        <?php if ($_alfred_next): ?>
        <a href="<?php echo BASE_URL . '?post=' . rawurlencode($_alfred_next['slug']); ?>"
           class="post-nav-next" title="<?php echo htmlspecialchars($_alfred_next['title']); ?>">
            <span class="fa">&#8249;</span>
            <p><?php echo htmlspecialchars($_alfred_next['title']); ?></p>
        </a>
        <?php endif; ?>
        <?php if ($_alfred_prev): ?>
        <a href="<?php echo BASE_URL . '?post=' . rawurlencode($_alfred_prev['slug']); ?>"
           class="post-nav-prev" title="<?php echo htmlspecialchars($_alfred_prev['title']); ?>">
            <p><?php echo htmlspecialchars($_alfred_prev['title']); ?></p>
            <span class="fa">&#8250;</span>
        </a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

    <?php if (!empty($_alfred_post['allow_comments'])): ?>
    <div class="comments-container">
        <?php include dirname(__DIR__, 2) . '/core/community-component.php'; ?>
    </div>
    <?php endif; ?>

</main><!-- /.content -->

<?php // MOSAIC render engine — packs [mosaic:ID] blocks (now expanded by the
      // parser into .snap-mosaic[data-mosaic]) into a justified tiled gallery.
      // Only needed on the single-post view, where post body content renders. ?>
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-mosaic.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>"></script>

<?php include __DIR__ . '/skin-footer.php'; ?>

</body>
</html>
<?php
    exit();
}

// ============================================================
//  FEED VIEW  (paginated archive of longform posts)
// ============================================================

// Only intercept bare / root requests — if $requested_slug is non-empty and
// didn't match a longform post above, fall through to image routing.
if (!empty($requested_slug)) return;

$_alfred_per_page = max(1, (int)($settings['posts_per_page'] ?? 12));
$_alfred_page     = max(1, (int)($_GET['page'] ?? 1));
$_alfred_offset   = ($_alfred_page - 1) * $_alfred_per_page;
$_alfred_show_titles = ($settings['show_post_titles'] ?? '0') === '1';

try {
    $count_stmt = $pdo->query(
        "SELECT COUNT(*) FROM snap_posts WHERE post_type = 'longform' AND status = 'published'"
    );
    $_alfred_total = (int)$count_stmt->fetchColumn();

    $feed_stmt = $pdo->prepare(
        "SELECT p.id, p.title, p.slug, p.created_at,
                COALESCE(i.img_thumb_aspect, i.img_file) AS featured_image_path,
                i.img_width AS featured_width,
                i.img_height AS featured_height
         FROM snap_posts p
         LEFT JOIN snap_images i ON i.id = p.featured_image_id
         WHERE p.post_type = 'longform' AND p.status = 'published'
         ORDER BY p.created_at DESC, p.id DESC
         LIMIT ? OFFSET ?"
    );
    $feed_stmt->execute([$_alfred_per_page, $_alfred_offset]);
    $_alfred_posts = $feed_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_alfred_total = 0;
    $_alfred_posts = [];
}

$_alfred_total_pages = (int)ceil($_alfred_total / $_alfred_per_page);

?><!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($settings['site_language'] ?? 'en'); ?>">
<head>
<?php include __DIR__ . '/skin-meta.php'; ?>
</head>
<body class="blog<?php echo $_alfred_show_titles ? ' show-preview-titles' : ''; ?>">

<?php include __DIR__ . '/skin-header.php'; ?>

<main class="content" role="main">

    <section class="section-inner">

        <?php if (empty($_alfred_posts)): ?>
        <p style="color:#fff;text-align:center;padding:4rem 0;">No posts yet.</p>
        <?php else: ?>

        <div class="posts ss-masonry tilez-posts">
        <?php foreach ($_alfred_posts as $_p):
            $has_thumb = !empty($_p['featured_image_path']);
            $tile_class = 'post' . ($has_thumb ? ' has-post-thumbnail' : '');
            $tile_w = max(1, (int)($_p['featured_width'] ?? 3));
            $tile_h = max(1, (int)($_p['featured_height'] ?? 2));
        ?>
            <a href="<?php echo BASE_URL . '?post=' . rawurlencode($_p['slug']); ?>"
               class="<?php echo $tile_class; ?> ss-masonry-item"
               data-w="<?php echo $tile_w; ?>" data-h="<?php echo $tile_h; ?>"
               aria-label="<?php echo htmlspecialchars($_p['title'], ENT_QUOTES); ?>">
                <?php if ($has_thumb): ?>
                <img src="<?php echo htmlspecialchars(BASE_URL . ltrim($_p['featured_image_path'], '/'), ENT_QUOTES); ?>"
                     data-w="<?php echo $tile_w; ?>" data-h="<?php echo $tile_h; ?>"
                     alt="<?php echo htmlspecialchars($_p['title'], ENT_QUOTES); ?>" loading="lazy">
                <?php endif; ?>
                <div class="post-overlay">
                    <div class="archive-post-header">
                        <p class="archive-post-date"><?php echo date('M j, Y', strtotime($_p['created_at'])); ?></p>
                        <h2 class="archive-post-title"><?php echo htmlspecialchars($_p['title']); ?></h2>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
        </div><!-- /.posts -->

        <?php endif; ?>

        <!-- Pagination -->
        <?php if ($_alfred_total_pages > 1): ?>
        <nav class="archive-nav" aria-label="Page navigation">
            <?php if ($_alfred_page > 1): ?>
            <a href="<?php echo BASE_URL . '?page=' . ($_alfred_page - 1); ?>">
                <span>&#8592;</span>
            </a>
            <?php else: ?>
            <span class="sep">&#8592;</span>
            <?php endif; ?>

            <span class="sep"><?php echo $_alfred_page; ?> / <?php echo $_alfred_total_pages; ?></span>

            <?php if ($_alfred_page < $_alfred_total_pages): ?>
            <a href="<?php echo BASE_URL . '?page=' . ($_alfred_page + 1); ?>">
                <span>&#8594;</span>
            </a>
            <?php else: ?>
            <span class="sep">&#8594;</span>
            <?php endif; ?>
        </nav>
        <?php endif; ?>

    </section><!-- /.section-inner -->

</main><!-- /.content -->

<?php include __DIR__ . '/skin-footer.php'; ?>

</body>
</html>
<?php
exit();
// ===== SNAPSMACK EOF =====
