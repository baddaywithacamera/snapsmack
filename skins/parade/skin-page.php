<?php
/**
 * SNAPSMACK - PARADE Static Page Template
 *
 * Used by page.php when active skin is parade.
 * Renders static pages (About, etc.) inside the Grid shell:
 * full <html><head>, Grid CSS, sticky nav, content, footer.
 *
 * Variables from page.php:
 *   $pdo, $settings, $active_skin, $page_data, $page_title, $snapsmack, $slug
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// ── Nav pages ──────────────────────────────────────────────────────────────
try {
    $nav_pages_stmt = $pdo->query("SELECT title, slug FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC");
    $nav_pages = $nav_pages_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $nav_pages = [];
}

// ── Avatar for header ──────────────────────────────────────────────────────
$_sp_avatar_path   = $settings['skin_avatar'] ?? '';
$_sp_avatar_exists = $_sp_avatar_path && file_exists(dirname(__DIR__, 2) . '/' . $_sp_avatar_path);
$_sp_site_name     = $settings['site_name'] ?? 'SnapSmack';
$_sp_initial       = strtoupper(substr($_sp_site_name, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include dirname(__DIR__, 2) . '/core/meta.php'; ?>
</head>
<body class="pa-static-page">

<div class="pa-content-wrap">

<!-- ── Shared profile + sticky nav (identical across all Grid pages) ──────── -->
<?php include __DIR__ . '/skin-profile.php'; ?>

<!-- ── Page content ─────────────────────────────────────────────────────── -->
<main class="pa-static-content">

    <h1 class="pa-static-title"><?php echo htmlspecialchars($page_data['title']); ?></h1>

    <?php if (!empty($page_data['image_asset'])):
        $hero_size  = in_array($page_data['image_size']  ?? '', ['medium','small']) ? $page_data['image_size']  : 'full';
        $hero_align = in_array($page_data['image_align'] ?? '', ['left','right'])   ? $page_data['image_align'] : 'center';
        $hero_shadow = !empty($page_data['image_shadow']) ? ' page-hero--shadow' : '';
    ?>
    <div class="page-hero page-hero--<?php echo $hero_size; ?> page-hero--<?php echo $hero_align; ?><?php echo $hero_shadow; ?>">
        <img src="<?php echo BASE_URL . ltrim($page_data['image_asset'], '/'); ?>"
             alt="<?php echo htmlspecialchars($page_data['title']); ?>">
    </div>
    <?php endif; ?>

    <div class="pa-static-body description">
        <?php
        if (!empty($page_data['content'])) {
            echo $snapsmack->parseContent($page_data['content']);
        }
        ?>
    </div>

</main>

</div><!-- /.pa-content-wrap -->

<?php include __DIR__ . '/skin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====