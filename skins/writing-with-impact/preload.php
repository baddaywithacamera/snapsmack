<?php
/**
 * SNAPSMACK - WRITING WITH IMPACT skin SMACKTALK router (preload hook)
 * v1.0.0
 *
 * Included by index.php after settings load, before image routing. Handles all
 * WRITING WITH IMPACT / SMACKTALK requests and exit()s so index.php's image
 * logic never runs.
 *
 * Routes:
 *   ?view=archive  -> featured-image thumbnails linking to longform posts
 *   ?post=<slug>   -> single longform post by slug
 *   ?id=<int>      -> single longform post by ID
 *   (bare request) -> paginated feed of longform posts (single column)
 *
 * skin-header.php opens #wwi-page > #wwi-wrapper > #wwi-content; this file
 * renders <main class="content"> inside it; skin-footer.php closes the frame
 * and renders the STANLEY-style sidebar.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

// Only intercept when WRITING WITH IMPACT is the active skin.
if (($settings['active_skin'] ?? '') !== 'writing-with-impact') return;

// ============================================================
//  ARCHIVE VIEW  (featured-image thumbnails -> longform posts)
// ============================================================
if (($_GET['view'] ?? '') === 'archive') {

    try {
        $_wwi_stmt = $pdo->query(
            "SELECT p.id, p.title, p.slug, p.created_at,
                    i.img_file, i.img_thumb_square, i.img_thumb_aspect,
                    i.img_width, i.img_height
             FROM snap_posts p
             INNER JOIN snap_images i ON i.id = p.featured_image_id
             WHERE p.post_type = 'longform'
               AND p.status = 'published'
               AND i.img_status = 'published'
             ORDER BY p.id DESC"
        );
        $_wwi_archive_posts = $_wwi_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $_wwi_archive_posts = [];
    }

    $_wwi_archive_layout = ($settings['archive_layout'] ?? 'cropped') === 'square'
        ? 'square'
        : 'cropped';
    $_wwi_tiles = [];
    foreach ($_wwi_archive_posts as $_post) {
        if ($_wwi_archive_layout === 'square' && !empty($_post['img_thumb_square'])) {
            $_wwi_thumb_rel = ltrim($_post['img_thumb_square'], '/');
        } elseif (!empty($_post['img_thumb_aspect'])) {
            $_wwi_thumb_rel = ltrim($_post['img_thumb_aspect'], '/');
        } elseif (!empty($_post['img_thumb_square'])) {
            $_wwi_thumb_rel = ltrim($_post['img_thumb_square'], '/');
        } else {
            $_wwi_thumb_rel = ltrim($_post['img_file'] ?? '', '/');
        }

        if ($_wwi_thumb_rel === '') continue;
        $_wwi_ratio = 1.0;
        if ($_wwi_archive_layout === 'cropped') {
            $_wwi_w = (int)($_post['img_width'] ?? 0);
            $_wwi_h = (int)($_post['img_height'] ?? 0);
            if ($_wwi_w > 0 && $_wwi_h > 0) {
                $_wwi_ratio = max(2 / 3, min(3 / 2, $_wwi_w / $_wwi_h));
            }
        }
        $_wwi_tiles[] = [
            'url'   => BASE_URL . '?post=' . rawurlencode((string)$_post['slug']),
            'thumb' => BASE_URL . $_wwi_thumb_rel,
            'title' => (string)($_post['title'] ?? ''),
            'ratio' => $_wwi_ratio,
        ];
    }

    $page_title = 'ARCHIVE';
    ?><!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($settings['site_language'] ?? 'en'); ?>">
<head>
<?php include __DIR__ . '/skin-meta.php'; ?>
</head>
<body class="wwi archive wwi-archive archive-layout-<?php echo $_wwi_archive_layout; ?>">

<?php include __DIR__ . '/skin-header.php'; ?>

<main class="content" role="main">
    <?php if (empty($_wwi_tiles)): ?>
    <p class="wwi-empty">NO PHOTOGRAPHS YET.</p>
    <?php else: ?>
    <div class="wwi-archive-grid">
    <?php foreach ($_wwi_tiles as $_t): ?>
        <a href="<?php echo htmlspecialchars($_t['url'], ENT_QUOTES); ?>"
           class="wwi-archive-item wwi-image-frame"
           style="aspect-ratio:<?php echo number_format((float)$_t['ratio'], 4, '.', ''); ?>;"
           aria-label="<?php echo htmlspecialchars($_t['title'], ENT_QUOTES); ?>"
           title="<?php echo htmlspecialchars($_t['title'], ENT_QUOTES); ?>">
            <img src="<?php echo htmlspecialchars($_t['thumb'], ENT_QUOTES); ?>" alt="" loading="lazy">
        </a>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/skin-footer.php'; ?>

</body>
</html>
<?php
    exit();
}

// --- ROUTING ---
$_wwi_post_slug = $_GET['post'] ?? null;
$_wwi_post_id   = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (empty($_wwi_post_slug) && !empty($requested_slug)) {
    try {
        $chk = $pdo->prepare("SELECT id FROM snap_posts WHERE slug = ? AND post_type = 'longform' AND status = 'published' LIMIT 1");
        $chk->execute([$requested_slug]);
        if ($chk->fetchColumn()) $_wwi_post_slug = $requested_slug;
    } catch (PDOException $e) { /* fall through */ }
}

// ============================================================
//  SINGLE POST VIEW
// ============================================================
if ($_wwi_post_slug || $_wwi_post_id) {

    try {
        if ($_wwi_post_slug) {
            $stmt = $pdo->prepare(
                "SELECT p.*, i.img_file AS featured_image_path
                 FROM snap_posts p LEFT JOIN snap_images i ON i.id = p.featured_image_id
                 WHERE p.slug = ? AND p.post_type = 'longform' AND p.status = 'published' LIMIT 1"
            );
            $stmt->execute([$_wwi_post_slug]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT p.*, i.img_file AS featured_image_path
                 FROM snap_posts p LEFT JOIN snap_images i ON i.id = p.featured_image_id
                 WHERE p.id = ? AND p.post_type = 'longform' AND p.status = 'published' LIMIT 1"
            );
            $stmt->execute([$_wwi_post_id]);
        }
        $_wwi_post = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $_wwi_post = null;
    }

    if (!$_wwi_post) {
        http_response_code(404);
        $page_title = '404 — Not Found';
        ?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>404 — Not Found</title></head>
<body style="background:#e9e6dc;padding:4rem;font-family:'Courier New',monospace;text-align:center;">
    <h1>POST NOT FOUND</h1>
    <p><a href="<?php echo BASE_URL; ?>" style="color:#2b2b2b;">&larr; Back to the front</a></p>
</body></html>
        <?php
        exit();
    }

    // Prev / Next
    try {
        $ns = $pdo->prepare("SELECT slug, title FROM snap_posts WHERE post_type='longform' AND status='published' AND id < ? ORDER BY id DESC LIMIT 1");
        $ns->execute([$_wwi_post['id']]);
        $_wwi_prev = $ns->fetch(PDO::FETCH_ASSOC);
        $ns = $pdo->prepare("SELECT slug, title FROM snap_posts WHERE post_type='longform' AND status='published' AND id > ? ORDER BY id ASC LIMIT 1");
        $ns->execute([$_wwi_post['id']]);
        $_wwi_next = $ns->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $_wwi_prev = $_wwi_next = null;
    }

    $page_title = htmlspecialchars($_wwi_post['title']);
    ?><!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($settings['site_language'] ?? 'en'); ?>">
<head>
<?php include __DIR__ . '/skin-meta.php'; ?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/columns.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/shortcodes.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
</head>
<body class="wwi single">

<?php include __DIR__ . '/skin-header.php'; ?>

<main class="content" role="main">
    <article class="post-container h-entry">
        <?php snapsmack_indieweb_longform_properties($_wwi_post, $settings); ?>

        <?php if (!empty($_wwi_post['featured_image_path'])):
            // Cover framed to WRITING WITH IMPACT's shape (4:3) with the post's pan/zoom (must match manifest cover_aspect).
            $_cpx = isset($_wwi_post['cover_pos_x']) ? (int)$_wwi_post['cover_pos_x'] : 50;
            $_cpy = isset($_wwi_post['cover_pos_y']) ? (int)$_wwi_post['cover_pos_y'] : 50;
            $_cz  = isset($_wwi_post['cover_zoom'])  ? (int)$_wwi_post['cover_zoom']  : 100;
        ?>
        <figure class="featured-media wwi-image-frame" style="aspect-ratio:4/3;overflow:hidden;">
            <img class="u-photo" src="<?php echo BASE_URL . ltrim($_wwi_post['featured_image_path'], '/'); ?>" alt="<?php echo htmlspecialchars($_wwi_post['title']); ?>"
                 style="width:100%;height:100%;object-fit:cover;object-position:<?php echo $_cpx; ?>% <?php echo $_cpy; ?>%;transform-origin:<?php echo $_cpx; ?>% <?php echo $_cpy; ?>%;transform:scale(<?php echo number_format($_cz / 100, 3); ?>);display:block;">
        </figure>
        <?php endif; ?>

        <div class="post-header">
            <p class="post-date"><time class="dt-published" datetime="<?php echo htmlspecialchars(date(DATE_ATOM, strtotime($_wwi_post['created_at']))); ?>"><?php echo strtoupper(date('D M j Y', strtotime($_wwi_post['created_at']))); ?></time></p>
            <h1 class="post-title p-name"><?php echo htmlspecialchars($_wwi_post['title']); ?></h1>
        </div>

        <div class="post-inner">
            <div class="post-content entry-content e-content">
                <?php
                require_once dirname(__DIR__, 2) . '/core/parser.php';
                $_wwi_parser = new SnapSmack($pdo);
                echo $_wwi_parser->parseContent($_wwi_post['content'] ?? '');
                ?>
            </div>
        </div>

    </article>

    <?php if ($_wwi_prev || $_wwi_next): ?>
    <nav class="post-navigation" aria-label="Post navigation">
        <?php if ($_wwi_next): ?>
        <a href="<?php echo BASE_URL . '?post=' . rawurlencode($_wwi_next['slug']); ?>" class="post-nav-next" title="<?php echo htmlspecialchars($_wwi_next['title']); ?>">&#8249; <?php echo htmlspecialchars($_wwi_next['title']); ?></a>
        <?php endif; ?>
        <?php if ($_wwi_prev): ?>
        <a href="<?php echo BASE_URL . '?post=' . rawurlencode($_wwi_prev['slug']); ?>" class="post-nav-prev" title="<?php echo htmlspecialchars($_wwi_prev['title']); ?>"><?php echo htmlspecialchars($_wwi_prev['title']); ?> &#8250;</a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

    <?php if (!empty($_wwi_post['allow_comments'])): ?>
    <div class="comments-container">
        <?php include dirname(__DIR__, 2) . '/core/community-component.php'; ?>
    </div>
    <?php endif; ?>
</main>

<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-mosaic.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>"></script>

<?php include __DIR__ . '/skin-footer.php'; ?>

</body>
</html>
<?php
    exit();
}

// ============================================================
//  FEED VIEW  (paginated list of longform posts)
// ============================================================
if (!empty($requested_slug)) return;

$_wwi_per_page = max(1, (int)($settings['posts_per_page'] ?? 8));
$_wwi_page     = max(1, (int)($_GET['page'] ?? 1));
$_wwi_offset   = ($_wwi_page - 1) * $_wwi_per_page;

try {
    $_wwi_total = (int)$pdo->query("SELECT COUNT(*) FROM snap_posts WHERE post_type='longform' AND status='published'")->fetchColumn();
    $fs = $pdo->prepare(
        "SELECT p.id, p.title, p.slug, p.created_at, p.content,
                COALESCE(i.img_thumb_square, i.img_file) AS featured_image_path
         FROM snap_posts p LEFT JOIN snap_images i ON i.id = p.featured_image_id
         WHERE p.post_type = 'longform' AND p.status = 'published'
         ORDER BY p.id DESC LIMIT ? OFFSET ?"
    );
    $fs->execute([$_wwi_per_page, $_wwi_offset]);
    $_wwi_posts = $fs->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_wwi_total = 0;
    $_wwi_posts = [];
}
$_wwi_total_pages = (int)ceil($_wwi_total / $_wwi_per_page);

$_wwi_excerpt = function (string $html): string {
    $t = strip_tags(preg_replace('/\[[^\]]*\]/', '', $html));
    $t = trim(preg_replace('/\s+/', ' ', $t));
    return mb_strlen($t) > 300 ? mb_substr($t, 0, 300) . '…' : $t;
};
?><!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($settings['site_language'] ?? 'en'); ?>">
<head>
<?php include __DIR__ . '/skin-meta.php'; ?>
</head>
<body class="wwi blog">

<?php include __DIR__ . '/skin-header.php'; ?>

<main class="content" role="main">
    <?php if (empty($_wwi_posts)): ?>
    <p class="wwi-empty">NO POSTS YET.</p>
    <?php else: ?>
    <div class="wwi-posts">
    <?php foreach ($_wwi_posts as $_p): ?>
        <article class="wwi-post-summary">
            <p class="summary-date"><?php echo strtoupper(date('D M j Y', strtotime($_p['created_at']))); ?></p>
            <h2 class="summary-title"><a href="<?php echo BASE_URL . '?post=' . rawurlencode($_p['slug']); ?>"><?php echo htmlspecialchars($_p['title']); ?></a></h2>
            <?php if (!empty($_p['featured_image_path'])): ?>
            <a class="summary-thumb wwi-image-frame" href="<?php echo BASE_URL . '?post=' . rawurlencode($_p['slug']); ?>">
                <img src="<?php echo htmlspecialchars(BASE_URL . ltrim($_p['featured_image_path'], '/'), ENT_QUOTES); ?>" alt="<?php echo htmlspecialchars($_p['title']); ?>" loading="lazy">
            </a>
            <?php endif; ?>
            <p class="summary-excerpt"><?php echo htmlspecialchars($_wwi_excerpt($_p['content'] ?? '')); ?></p>
            <p class="summary-more"><a href="<?php echo BASE_URL . '?post=' . rawurlencode($_p['slug']); ?>">CONTINUE READING &raquo;</a></p>
        </article>
    <?php endforeach; ?>
    </div>

    <?php if ($_wwi_total_pages > 1): ?>
    <nav class="wwi-pagination" aria-label="Page navigation">
        <?php if ($_wwi_page > 1): ?><a href="<?php echo BASE_URL . '?page=' . ($_wwi_page - 1); ?>">&laquo; NEWER</a><?php endif; ?>
        <span class="sep"><?php echo $_wwi_page; ?> / <?php echo $_wwi_total_pages; ?></span>
        <?php if ($_wwi_page < $_wwi_total_pages): ?><a href="<?php echo BASE_URL . '?page=' . ($_wwi_page + 1); ?>">OLDER &raquo;</a><?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/skin-footer.php'; ?>

</body>
</html>
<?php
exit();
// ===== SNAPSMACK EOF =====
