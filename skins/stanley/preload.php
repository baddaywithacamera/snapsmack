<?php
/**
 * SNAPSMACK - STANLEY skin SMACKTALK router (preload hook)
 * v1.0.0
 *
 * Included by index.php after settings load, before image routing. Handles all
 * STANLEY/SMACKTALK requests and exit()s so index.php's image logic never runs.
 *
 * Routes:
 *   ?view=archive  -> grid of individual published photographs (snap_images) -> lightbox
 *   ?post=<slug>   -> single longform post by slug
 *   ?id=<int>      -> single longform post by ID (admin-generated links)
 *   (bare request) -> paginated feed of longform posts
 *
 * Structure: skin-header.php opens #stanley-page > #stanley-wrapper >
 * #stanley-content; this file renders <main class="content"> inside it;
 * skin-footer.php closes the frame and renders the sidebar.
 *
 * Variables from index.php: $pdo, $settings, $active_skin, $requested_slug,
 * BASE_URL, SNAPSMACK_VERSION_SHORT.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

// Only intercept when STANLEY is the active skin.
if (($settings['active_skin'] ?? '') !== 'stanley') return;

// ============================================================
//  ARCHIVE VIEW  (grid of individual photographs -> lightbox)
// ============================================================
if (($_GET['view'] ?? '') === 'archive') {

    if (($settings['archive_layout'] ?? 'square') === 'none') {
        header('Location: ' . BASE_URL, true, 302);
        exit();
    }

    try {
        $_st_stmt = $pdo->query(
            "SELECT id, img_title, img_file, img_thumb_square, img_thumb_aspect
             FROM snap_images
             WHERE img_status = 'published'
             ORDER BY img_date DESC, id DESC"
        );
        $_st_images = $_st_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $_st_images = [];
    }

    $_st_tiles = [];
    foreach ($_st_images as $_img) {
        $full_rel = ltrim($_img['img_file'] ?? '', '/');
        if ($full_rel === '') continue;
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
        $_st_tiles[] = [
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
<body class="stanley archive stanley-archive">

<?php include __DIR__ . '/skin-header.php'; ?>

<main class="content" role="main">
    <?php if (empty($_st_tiles)): ?>
    <p class="stanley-empty">NO PHOTOGRAPHS YET.</p>
    <?php else: ?>
    <div class="alfred-archive-grid stanley-archive-grid">
    <?php foreach ($_st_tiles as $_t): ?>
        <a href="<?php echo htmlspecialchars($_t['full'], ENT_QUOTES); ?>"
           class="alfred-archive-tile"
           data-full="<?php echo htmlspecialchars($_t['full'], ENT_QUOTES); ?>"
           data-title="<?php echo htmlspecialchars($_t['title'], ENT_QUOTES); ?>"
           title="<?php echo htmlspecialchars($_t['title'], ENT_QUOTES); ?>">
            <img src="<?php echo htmlspecialchars($_t['thumb'], ENT_QUOTES); ?>" alt="<?php echo htmlspecialchars($_t['title'], ENT_QUOTES); ?>" loading="lazy">
        </a>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<div id="alfred-archive-lightbox" class="alfred-lightbox" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-label="Photograph viewer">
    <button type="button" class="alfred-lb-close" aria-label="Close">&#10005;</button>
    <button type="button" class="alfred-lb-prev" aria-label="Previous photograph">&#8249;</button>
    <img class="alfred-lb-img" src="" alt="">
    <button type="button" class="alfred-lb-next" aria-label="Next photograph">&#8250;</button>
    <p class="alfred-lb-caption"></p>
</div>
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-alfred-archive.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>"></script>

<?php include __DIR__ . '/skin-footer.php'; ?>

</body>
</html>
<?php
    exit();
}

// --- ROUTING ---
$_st_post_slug = $_GET['post'] ?? null;
$_st_post_id   = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (empty($_st_post_slug) && !empty($requested_slug)) {
    try {
        $chk = $pdo->prepare("SELECT id FROM snap_posts WHERE slug = ? AND post_type = 'longform' AND status = 'published' LIMIT 1");
        $chk->execute([$requested_slug]);
        if ($chk->fetchColumn()) $_st_post_slug = $requested_slug;
    } catch (PDOException $e) { /* fall through */ }
}

// ============================================================
//  SINGLE POST VIEW
// ============================================================
if ($_st_post_slug || $_st_post_id) {

    try {
        if ($_st_post_slug) {
            $stmt = $pdo->prepare(
                "SELECT p.*, i.img_file AS featured_image_path
                 FROM snap_posts p LEFT JOIN snap_images i ON i.id = p.featured_image_id
                 WHERE p.slug = ? AND p.post_type = 'longform' AND p.status = 'published' LIMIT 1"
            );
            $stmt->execute([$_st_post_slug]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT p.*, i.img_file AS featured_image_path
                 FROM snap_posts p LEFT JOIN snap_images i ON i.id = p.featured_image_id
                 WHERE p.id = ? AND p.post_type = 'longform' AND p.status = 'published' LIMIT 1"
            );
            $stmt->execute([$_st_post_id]);
        }
        $_st_post = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $_st_post = null;
    }

    if (!$_st_post) {
        http_response_code(404);
        $page_title = '404 — Not Found';
        ?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>404 — Not Found</title></head>
<body style="background:#d5d6d7;padding:4rem;font-family:Georgia,serif;text-align:center;">
    <h1>Post not found</h1>
    <p><a href="<?php echo BASE_URL; ?>" style="color:#2e6da4;">&larr; Back to the front</a></p>
</body></html>
        <?php
        exit();
    }

    // Prev / Next
    try {
        $ns = $pdo->prepare("SELECT slug, title FROM snap_posts WHERE post_type='longform' AND status='published' AND created_at < ? ORDER BY created_at DESC LIMIT 1");
        $ns->execute([$_st_post['created_at']]);
        $_st_prev = $ns->fetch(PDO::FETCH_ASSOC);
        $ns = $pdo->prepare("SELECT slug, title FROM snap_posts WHERE post_type='longform' AND status='published' AND created_at > ? ORDER BY created_at ASC LIMIT 1");
        $ns->execute([$_st_post['created_at']]);
        $_st_next = $ns->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $_st_prev = $_st_next = null;
    }

    $page_title = htmlspecialchars($_st_post['title']);
    ?><!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($settings['site_language'] ?? 'en'); ?>">
<head>
<?php include __DIR__ . '/skin-meta.php'; ?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/columns.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/shortcodes.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
</head>
<body class="stanley single">

<?php include __DIR__ . '/skin-header.php'; ?>

<main class="content" role="main">
    <article class="post-container">

        <?php if (!empty($_st_post['featured_image_path'])): ?>
        <figure class="featured-media">
            <img src="<?php echo BASE_URL . ltrim($_st_post['featured_image_path'], '/'); ?>" alt="<?php echo htmlspecialchars($_st_post['title']); ?>">
        </figure>
        <?php endif; ?>

        <div class="post-header">
            <h1 class="post-title"><?php echo htmlspecialchars($_st_post['title']); ?></h1>
            <p class="post-date"><?php echo date('F j, Y', strtotime($_st_post['created_at'])); ?></p>
        </div>

        <div class="post-inner">
            <div class="post-content entry-content">
                <?php
                require_once dirname(__DIR__, 2) . '/core/parser.php';
                $_st_parser = new SnapSmack($pdo);
                echo $_st_parser->parseContent($_st_post['content'] ?? '');
                ?>
            </div>
        </div>

    </article>

    <?php if ($_st_prev || $_st_next): ?>
    <nav class="post-navigation" aria-label="Post navigation">
        <?php if ($_st_next): ?>
        <a href="<?php echo BASE_URL . '?post=' . rawurlencode($_st_next['slug']); ?>" class="post-nav-next" title="<?php echo htmlspecialchars($_st_next['title']); ?>">&#8249; <?php echo htmlspecialchars($_st_next['title']); ?></a>
        <?php endif; ?>
        <?php if ($_st_prev): ?>
        <a href="<?php echo BASE_URL . '?post=' . rawurlencode($_st_prev['slug']); ?>" class="post-nav-prev" title="<?php echo htmlspecialchars($_st_prev['title']); ?>"><?php echo htmlspecialchars($_st_prev['title']); ?> &#8250;</a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

    <?php if (!empty($_st_post['allow_comments'])): ?>
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
//  FEED VIEW  (paginated archive of longform posts)
// ============================================================
if (!empty($requested_slug)) return;

$_st_per_page = max(1, (int)($settings['posts_per_page'] ?? 10));
$_st_page     = max(1, (int)($_GET['page'] ?? 1));
$_st_offset   = ($_st_page - 1) * $_st_per_page;

try {
    $_st_total = (int)$pdo->query("SELECT COUNT(*) FROM snap_posts WHERE post_type='longform' AND status='published'")->fetchColumn();
    $fs = $pdo->prepare(
        "SELECT p.id, p.title, p.slug, p.created_at, p.content,
                COALESCE(i.img_thumb_square, i.img_file) AS featured_image_path
         FROM snap_posts p LEFT JOIN snap_images i ON i.id = p.featured_image_id
         WHERE p.post_type = 'longform' AND p.status = 'published'
         ORDER BY p.created_at DESC LIMIT ? OFFSET ?"
    );
    $fs->execute([$_st_per_page, $_st_offset]);
    $_st_posts = $fs->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_st_total = 0;
    $_st_posts = [];
}
$_st_total_pages = (int)ceil($_st_total / $_st_per_page);

// Build a short plain-text excerpt from post content for the Kubrick-style list.
$_st_excerpt = function (string $html): string {
    $t = strip_tags(preg_replace('/\[[^\]]*\]/', '', $html));
    $t = trim(preg_replace('/\s+/', ' ', $t));
    return mb_strlen($t) > 260 ? mb_substr($t, 0, 260) . '…' : $t;
};
?><!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($settings['site_language'] ?? 'en'); ?>">
<head>
<?php include __DIR__ . '/skin-meta.php'; ?>
</head>
<body class="stanley blog">

<?php include __DIR__ . '/skin-header.php'; ?>

<main class="content" role="main">
    <?php if (empty($_st_posts)): ?>
    <p class="stanley-empty">No posts yet.</p>
    <?php else: ?>
    <div class="stanley-posts">
    <?php foreach ($_st_posts as $_p): ?>
        <article class="stanley-post-summary">
            <?php if (!empty($_p['featured_image_path'])): ?>
            <a class="summary-thumb" href="<?php echo BASE_URL . '?post=' . rawurlencode($_p['slug']); ?>">
                <img src="<?php echo htmlspecialchars(BASE_URL . ltrim($_p['featured_image_path'], '/'), ENT_QUOTES); ?>" alt="<?php echo htmlspecialchars($_p['title']); ?>" loading="lazy">
            </a>
            <?php endif; ?>
            <h2 class="summary-title"><a href="<?php echo BASE_URL . '?post=' . rawurlencode($_p['slug']); ?>"><?php echo htmlspecialchars($_p['title']); ?></a></h2>
            <p class="summary-date"><?php echo date('F j, Y', strtotime($_p['created_at'])); ?></p>
            <p class="summary-excerpt"><?php echo htmlspecialchars($_st_excerpt($_p['content'] ?? '')); ?></p>
            <p class="summary-more"><a href="<?php echo BASE_URL . '?post=' . rawurlencode($_p['slug']); ?>">Read the rest &raquo;</a></p>
        </article>
    <?php endforeach; ?>
    </div>

    <?php if ($_st_total_pages > 1): ?>
    <nav class="stanley-pagination" aria-label="Page navigation">
        <?php if ($_st_page > 1): ?><a href="<?php echo BASE_URL . '?page=' . ($_st_page - 1); ?>">&laquo; Newer</a><?php endif; ?>
        <span class="sep"><?php echo $_st_page; ?> / <?php echo $_st_total_pages; ?></span>
        <?php if ($_st_page < $_st_total_pages): ?><a href="<?php echo BASE_URL . '?page=' . ($_st_page + 1); ?>">Older &raquo;</a><?php endif; ?>
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
