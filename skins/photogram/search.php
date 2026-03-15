<?php
/**
 * SNAPSMACK - Photogram Search
 * Alpha v0.7.3a
 *
 * Full-text search across img_title, img_description, and hashtags.
 * Loaded by landing.php when ?pg=search is requested and
 * search_enabled is on. Falls back to empty state when no query
 * is provided. Queries starting with # redirect to the hashtag
 * archive page for that tag.
 */

$q           = trim($_GET['q'] ?? '');
$q_safe      = htmlspecialchars($q);
$results     = [];
$result_count = 0;
$matched_tags = [];

$pg_active_tab = 'search';

// If the query looks like a hashtag, redirect to the tag archive
if ($q !== '' && $q[0] === '#') {
    $tag_candidate = substr($q, 1);
    if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,49}$/', $tag_candidate)) {
        header('Location: ' . BASE_URL . '?tag=' . rawurlencode(strtolower($tag_candidate)));
        exit;
    }
}

if ($q !== '') {
    $now = date('Y-m-d H:i:s');

    // ── Find matching tags ──────────────────────────────────────────────
    $tag_term = '%' . strtolower($q) . '%';
    $tag_stmt = $pdo->prepare("
        SELECT id, tag, slug, use_count
        FROM snap_tags
        WHERE slug LIKE ?
          AND use_count > 0
        ORDER BY use_count DESC
        LIMIT 10
    ");
    $tag_stmt->execute([$tag_term]);
    $matched_tags = $tag_stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Image search: title + description + tagged images ───────────────
    $search_term = '%' . $q . '%';
    $search_stmt = $pdo->prepare("
        SELECT DISTINCT i.id, i.img_title, i.img_slug, i.img_file, i.img_thumb_square
        FROM snap_images i
        LEFT JOIN snap_image_tags it ON it.image_id = i.id
        LEFT JOIN snap_tags t ON t.id = it.tag_id
        WHERE i.img_status = 'published'
          AND i.img_date   <= ?
          AND (
              i.img_title LIKE ?
              OR i.img_description LIKE ?
              OR t.slug LIKE ?
          )
        ORDER BY i.img_date DESC
        LIMIT 60
    ");
    $search_stmt->execute([$now, $search_term, $search_term, $tag_term]);
    $results      = $search_stmt->fetchAll(PDO::FETCH_ASSOC);
    $result_count = count($results);
}

$site_title = $settings['site_title'] ?? $site_name ?? 'Photogram';
?>

<?php include __DIR__ . '/skin-meta.php'; ?>
<?php include __DIR__ . '/skin-header.php'; ?>

<div id="pg-app">
<div class="pg-content">

    <!-- ── Search Header ───────────────────────────────────────────────────── -->
    <header class="pg-profile-header">
        <span class="pg-profile-header-title">Search</span>
    </header>

    <!-- ── Search Bar ──────────────────────────────────────────────────────── -->
    <div class="pg-search-wrap">
        <form method="GET" action="<?php echo BASE_URL; ?>" class="pg-search-form" role="search">
            <input type="hidden" name="pg" value="search">
            <div class="pg-search-input-wrap">
                <svg class="pg-search-icon" width="16" height="16" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <input type="search"
                       name="q"
                       value="<?php echo $q_safe; ?>"
                       placeholder="Search photos or #tags…"
                       class="pg-search-input"
                       autocomplete="off"
                       autofocus>
                <?php if ($q !== ''): ?>
                    <a href="<?php echo BASE_URL; ?>?pg=search" class="pg-search-clear" aria-label="Clear search">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2.5"
                             stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($q !== ''): ?>

        <?php if (!empty($matched_tags)): ?>
        <!-- ── Matching Tags ─────────────────────────────────────────────── -->
        <div class="pg-search-tags">
            <?php foreach ($matched_tags as $mt): ?>
                <a href="<?php echo BASE_URL . '?tag=' . rawurlencode($mt['slug']); ?>" class="pg-search-tag-chip">
                    <span class="pg-search-tag-hash">#</span><?php echo htmlspecialchars($mt['slug']); ?>
                    <span class="pg-search-tag-count"><?php echo number_format((int)$mt['use_count']); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ── Result Count ────────────────────────────────────────────────── -->
        <div class="pg-search-meta">
            <?php if ($result_count > 0): ?>
                <span><?php echo number_format($result_count); ?> result<?php echo $result_count !== 1 ? 's' : ''; ?> for <strong><?php echo $q_safe; ?></strong></span>
            <?php else: ?>
                <span>No results for <strong><?php echo $q_safe; ?></strong></span>
            <?php endif; ?>
        </div>

        <!-- ── Results Grid ─────────────────────────────────────────────────── -->
        <?php if (!empty($results)): ?>
        <main class="pg-grid" aria-label="Search results">
            <?php foreach ($results as $r):
                $link = BASE_URL . htmlspecialchars($r['img_slug']);
                if (!empty($r['img_thumb_square'])) {
                    $thumb = BASE_URL . ltrim($r['img_thumb_square'], '/');
                } elseif (!empty($r['img_file'])) {
                    $fp    = pathinfo(ltrim($r['img_file'], '/'));
                    $thumb = BASE_URL . $fp['dirname'] . '/thumbs/t_' . $fp['basename'];
                } else {
                    $thumb = '';
                }
            ?>
            <a href="<?php echo $link; ?>"
               class="pg-grid-cell"
               title="<?php echo htmlspecialchars($r['img_title']); ?>"
               aria-label="<?php echo htmlspecialchars($r['img_title']); ?>">
                <?php if ($thumb): ?>
                    <img src="<?php echo $thumb; ?>"
                         alt="<?php echo htmlspecialchars($r['img_title']); ?>"
                         loading="lazy">
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </main>
        <?php endif; ?>

    <?php else: ?>
        <!-- ── Empty State ──────────────────────────────────────────────────── -->
        <div class="pg-search-empty">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.5"
                 stroke-linecap="round" stroke-linejoin="round"
                 style="opacity:.3;">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <p>Search titles, descriptions, and #hashtags</p>
        </div>
    <?php endif; ?>

</div><!-- /.pg-content -->
</div><!-- /#pg-app -->

<?php include __DIR__ . '/skin-footer.php'; ?>
