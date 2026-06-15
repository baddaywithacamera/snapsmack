<?php
/**
 * SNAPSMACK - XML Sitemap generator
 *
 * Serves a search-engine sitemap at /sitemap.php (aliased to /sitemap.xml via
 * .htaccess). robots.txt (auto-generated in smack-settings.php) already points
 * crawlers here. Lists the homepage, published posts/images, and active static
 * pages with <lastmod>. Each query block is guarded so a missing table or
 * column on an older install degrades gracefully instead of fatalling.
 *
 * Mode-aware: SMACKONEOUT (photoblog) lists snap_images; GRAMOFSMACK / carousel
 * installs list snap_posts. Static pages are listed for both.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once __DIR__ . '/core/db.php';

// --- SETTINGS ---
$settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
$settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$site_url       = rtrim($settings['site_url'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'example.com')), '/') . '/';
$is_carousel    = ($settings['site_mode'] ?? 'photoblog') === 'carousel';
$now_local      = date('Y-m-d H:i:s');

/**
 * Collect URLs as [loc, lastmod(Y-m-d) | null]. Built first, emitted after, so
 * a query failure can't leave a half-written XML document.
 */
$urls = [];
$urls[] = [$site_url, date('Y-m-d')]; // homepage

// --- POSTS / IMAGES (mode-aware) ---
try {
    if ($is_carousel) {
        $stmt = $pdo->prepare(
            "SELECT slug, updated_at FROM snap_posts
             WHERE status = 'published' AND created_at <= ?
             ORDER BY updated_at DESC LIMIT 5000"
        );
        $stmt->execute([$now_local]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['slug'] === '' || $r['slug'] === null) continue;
            $urls[] = [$site_url . ltrim($r['slug'], '/'), substr((string)$r['updated_at'], 0, 10)];
        }
    } else {
        $stmt = $pdo->prepare(
            "SELECT img_slug, img_date FROM snap_images
             WHERE img_status = 'published' AND img_date <= ?
             ORDER BY img_date DESC LIMIT 5000"
        );
        $stmt->execute([$now_local]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['img_slug'] === '' || $r['img_slug'] === null) continue;
            $urls[] = [$site_url . ltrim($r['img_slug'], '/'), substr((string)$r['img_date'], 0, 10)];
        }
    }
} catch (PDOException $e) {
    error_log('SnapSmack sitemap: content query failed — ' . $e->getMessage());
}

// --- STATIC PAGES ---
try {
    $stmt = $pdo->query(
        "SELECT slug, created_at FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['slug'] === '' || $r['slug'] === null) continue;
        $urls[] = [$site_url . 'page.php?slug=' . rawurlencode($r['slug']), substr((string)$r['created_at'], 0, 10)];
    }
} catch (PDOException $e) {
    error_log('SnapSmack sitemap: pages query failed — ' . $e->getMessage());
}

// --- OUTPUT ---
header('Content-Type: application/xml; charset=UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($u[0], ENT_XML1) . "</loc>\n";
    if (!empty($u[1])) {
        echo '    <lastmod>' . htmlspecialchars($u[1], ENT_XML1) . "</lastmod>\n";
    }
    echo "  </url>\n";
}
echo '</urlset>' . "\n";
// ===== SNAPSMACK EOF =====
