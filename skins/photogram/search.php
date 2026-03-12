<?php
/**
 * SNAPSMACK - Photogram Search
 * Alpha v0.7.2
 *
 * Full-text search across img_title and img_description.
 * Loaded by skin-footer.php when ?pg=search is requested and
 * pg_show_search is enabled. Falls back to empty state when
 * no query is provided.
 */

$q           = trim($_GET['q'] ?? '');
$q_safe      = htmlspecialchars($q);
$results     = [];
$result_count = 0;

$pg_active_tab = 'search';

if ($q !== '') {
    // Full-text LIKE search across title + description.
    // Phase 2: switch to FULLTEXT index when install base warrants it.
    $search_term = '%' . $q . '%';
    $search_stmt = $pdo->prepare("
        SELECT id, img_title, img_slug, img_file, img_thumb_square
        FROM snap_images
        WHERE img_status = 'published'
          AND img_date   <= ?
          AND (img_title LIKE ? OR img_description LIKE ?)
        ORDER BY img_date DESC
        LIMIT 60
    ");
    $search_stmt->execute([date('Y-m-d H:i:s'), $search_term, $search_term]);
    $results      = $search_stmt->fetchAll(PDO::FETCH_ASSOC);
    $result_count = count($results);
}

$site_title = $settings['site_title'] ?? $site_name ?? 'Photogram';
?>

<?php include('skin-meta.php'); ?>
<?php include('skin-header.php'); ?>

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
                       placeholder="Search photos…"
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
            <p>Search titles and descriptions</p>
        </div>
    <?php endif; ?>

</div><!-- /.pg-content -->
</div><!-- /#pg-app -->

<?php include('skin-footer.php'); ?>
