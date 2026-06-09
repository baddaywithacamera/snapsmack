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
if (($settings['active_skin'] ?? '') !== 'alfred') return;

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
                "SELECT p.*, a.asset_path AS featured_image_path
                 FROM snap_posts p
                 LEFT JOIN snap_assets a ON a.id = p.featured_asset_id
                 WHERE p.slug = ? AND p.post_type = 'longform' AND p.status = 'published'
                 LIMIT 1"
            );
            $stmt->execute([$_alfred_post_slug]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT p.*, a.asset_path AS featured_image_path
                 FROM snap_posts p
                 LEFT JOIN snap_assets a ON a.id = p.featured_asset_id
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
             WHERE post_type = 'longform' AND status = 'published' AND created_at < ?
             ORDER BY created_at DESC LIMIT 1"
        );
        $nav_stmt->execute([$_alfred_post['created_at']]);
        $_alfred_prev = $nav_stmt->fetch(PDO::FETCH_ASSOC);

        $nav_stmt = $pdo->prepare(
            "SELECT slug, title FROM snap_posts
             WHERE post_type = 'longform' AND status = 'published' AND created_at > ?
             ORDER BY created_at ASC LIMIT 1"
        );
        $nav_stmt->execute([$_alfred_post['created_at']]);
        $_alfred_next = $nav_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $_alfred_prev = $_alfred_next = null;
    }

    // --- OG / page title for meta ---
    $page_title = htmlspecialchars($_alfred_post['title']);

    ?><!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($settings['site_language'] ?? 'en'); ?>">
<head>
<?php include __DIR__ . '/skin-meta.php'; ?>
</head>
<body class="single">

<?php include __DIR__ . '/skin-header.php'; ?>

<main class="content" role="main">

    <article class="post-container">

        <?php if (!empty($_alfred_post['featured_image_path'])): ?>
        <figure class="featured-media">
            <img src="<?php echo BASE_URL . ltrim($_alfred_post['featured_image_path'], '/'); ?>"
                 alt="<?php echo htmlspecialchars($_alfred_post['title']); ?>">
        </figure>
        <?php endif; ?>

        <div class="post-header">
            <p class="post-date"><?php echo date('F j, Y', strtotime($_alfred_post['created_at'])); ?></p>
            <h1 class="post-title"><?php echo htmlspecialchars($_alfred_post['title']); ?></h1>
        </div>

        <div class="post-inner">
            <div class="post-content entry-content">
                <?php echo $_alfred_post['content'] ?? ''; ?>
            </div>

            <div class="post-meta">
                <p><?php echo date('F j, Y', strtotime($_alfred_post['created_at'])); ?></p>
            </div>
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
        "SELECT p.id, p.title, p.slug, p.created_at, a.asset_path AS featured_image_path
         FROM snap_posts p
         LEFT JOIN snap_assets a ON a.id = p.featured_asset_id
         WHERE p.post_type = 'longform' AND p.status = 'published'
         ORDER BY p.created_at DESC
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

        <div class="posts">
        <?php foreach ($_alfred_posts as $_p):
            $has_thumb = !empty($_p['featured_image_path']);
            $tile_style = $has_thumb
                ? ' style="background-image: url(\'' . htmlspecialchars(BASE_URL . ltrim($_p['featured_image_path'], '/'), ENT_QUOTES) . '\')"'
                : '';
            $tile_class = 'post' . ($has_thumb ? ' has-post-thumbnail' : '');
        ?>
            <a href="<?php echo BASE_URL . '?post=' . rawurlencode($_p['slug']); ?>"
               class="<?php echo $tile_class; ?>"<?php echo $tile_style; ?>>
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
