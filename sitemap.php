<?php
/**
 * SNAPSMACK - XML Sitemap (cached, indexed, capped)
 *
 * Served at /sitemap.php (aliased to /sitemap.xml via .htaccess). robots.txt
 * points crawlers here. Emits a <sitemapindex> pointing at paged sub-sitemaps
 * (?p=1, ?p=2, …), each holding up to PAGE_SIZE URLs, so an archive of any size
 * is covered without truncation.
 *
 * PERFORMANCE: the sitemap is built ONCE and cached to cache/sitemap/*.xml, then
 * served with a cheap readfile() on every crawler hit — it does NOT rebuild the
 * whole thing per request (which, on a 10k+ post archive, would be the one
 * genuinely heavy op here). The cache is refreshed lazily: when it's older than
 * TTL, the first request to win a non-blocking lock rebuilds it while everyone
 * else serves the slightly-stale copy. No cron, no publish hooks. If the cache
 * dir isn't writable it degrades gracefully to a single live (capped) sitemap.
 *
 * Config: `sitemap_image_cap` (Settings → SEO) caps how many posts/images are
 * listed; 0/blank = the whole archive. Settings-save drops the cached index so a
 * cap/URL change takes effect on the next fetch.
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

$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

$site_url    = rtrim($settings['site_url'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'example.com')), '/') . '/';
$is_carousel = ($settings['site_mode'] ?? 'photoblog') === 'carousel';
$cap         = max(0, (int)($settings['sitemap_image_cap'] ?? 0));   // 0 = unlimited

const SS_SITEMAP_PAGE_SIZE = 45000;   // safely under the 50k-URL spec ceiling
const SS_SITEMAP_TTL       = 3600;    // rebuild the cache at most hourly

$cache_dir  = __DIR__ . '/cache/sitemap/';
$page       = isset($_GET['p']) ? max(0, (int)$_GET['p']) : 0;
$index_file = $cache_dir . 'index.xml';

/** XML-wrap a list of [loc, lastmod] rows into a <urlset> string. */
function ss_sitemap_urlset(array $urls): string {
    $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $u) {
        $out .= "  <url>\n    <loc>" . htmlspecialchars($u[0], ENT_XML1) . "</loc>\n";
        if (!empty($u[1])) $out .= '    <lastmod>' . htmlspecialchars($u[1], ENT_XML1) . "</lastmod>\n";
        $out .= "  </url>\n";
    }
    return $out . "</urlset>\n";
}

/** Build a <sitemapindex> listing one <sitemap> per page. */
function ss_sitemap_index(string $site_url, int $pages): string {
    $now = date('Y-m-d');
    $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $out .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    for ($i = 1; $i <= max(1, $pages); $i++) {
        $out .= "  <sitemap>\n    <loc>" . htmlspecialchars($site_url . 'sitemap.php?p=' . $i, ENT_XML1) . "</loc>\n";
        $out .= "    <lastmod>{$now}</lastmod>\n  </sitemap>\n";
    }
    return $out . "</sitemapindex>\n";
}

/** Gather the full URL list: homepage + content (mode-aware, cap-aware) + pages. */
function ss_sitemap_urls(PDO $pdo, string $site_url, bool $is_carousel, int $cap): array {
    $now  = date('Y-m-d H:i:s');
    $urls = [[$site_url, date('Y-m-d')]];
    $lim  = ' LIMIT ' . ($cap > 0 ? (int)$cap : 200000);   // hard ceiling even when "unlimited"
    try {
        if ($is_carousel) {
            $st = $pdo->prepare("SELECT slug, updated_at FROM snap_posts WHERE status='published' AND created_at <= ? ORDER BY updated_at DESC{$lim}");
            $st->execute([$now]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (($r['slug'] ?? '') === '') continue;
                $urls[] = [$site_url . ltrim((string)$r['slug'], '/'), substr((string)$r['updated_at'], 0, 10)];
            }
        } else {
            $st = $pdo->prepare("SELECT img_slug, img_date FROM snap_images WHERE img_status='published' AND img_date <= ? ORDER BY img_date DESC{$lim}");
            $st->execute([$now]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (($r['img_slug'] ?? '') === '') continue;
                $urls[] = [$site_url . ltrim((string)$r['img_slug'], '/'), substr((string)$r['img_date'], 0, 10)];
            }
        }
    } catch (PDOException $e) {
        error_log('SnapSmack sitemap: content query failed — ' . $e->getMessage());
    }
    try {
        $st = $pdo->query("SELECT slug, created_at FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (($r['slug'] ?? '') === '') continue;
            $urls[] = [$site_url . 'page.php?slug=' . rawurlencode((string)$r['slug']), substr((string)$r['created_at'], 0, 10)];
        }
    } catch (PDOException $e) {
        error_log('SnapSmack sitemap: pages query failed — ' . $e->getMessage());
    }
    return $urls;
}

/** Rebuild the cached index + page files atomically. Returns true on success. */
function ss_sitemap_rebuild(PDO $pdo, string $cache_dir, string $site_url, bool $is_carousel, int $cap): bool {
    if (!is_dir($cache_dir) && !@mkdir($cache_dir, 0755, true)) return false;
    if (!is_writable($cache_dir)) return false;

    $urls   = ss_sitemap_urls($pdo, $site_url, $is_carousel, $cap);
    $chunks = array_chunk($urls, SS_SITEMAP_PAGE_SIZE);
    if (!$chunks) $chunks = [[]];

    foreach ($chunks as $i => $chunk) {
        $tmp = $cache_dir . 'page-' . ($i + 1) . '.xml.tmp';
        if (@file_put_contents($tmp, ss_sitemap_urlset($chunk), LOCK_EX) === false) return false;
        @rename($tmp, $cache_dir . 'page-' . ($i + 1) . '.xml');
    }
    // Drop stale higher-numbered pages (archive shrank or cap lowered).
    for ($j = count($chunks) + 1; is_file($cache_dir . 'page-' . $j . '.xml'); $j++) {
        @unlink($cache_dir . 'page-' . $j . '.xml');
    }

    $tmp = $cache_dir . 'index.xml.tmp';
    if (@file_put_contents($tmp, ss_sitemap_index($site_url, count($chunks)), LOCK_EX) === false) return false;
    @rename($tmp, $cache_dir . 'index.xml');
    return true;
}

// --- Refresh if stale; a non-blocking lock means only ONE request rebuilds ---
$stale = !is_file($index_file) || (time() - (int)@filemtime($index_file)) > SS_SITEMAP_TTL;
if ($stale) {
    @mkdir($cache_dir, 0755, true);
    $lock = @fopen($cache_dir . '.lock', 'c');
    if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
        ss_sitemap_rebuild($pdo, $cache_dir, $site_url, $is_carousel, $cap);
        flock($lock, LOCK_UN);
        fclose($lock);
    } elseif ($lock) {
        fclose($lock);   // someone else is rebuilding — serve what we have
    }
}

// --- Serve ---
header('Content-Type: application/xml; charset=UTF-8');
$want = $page > 0 ? $cache_dir . 'page-' . $page . '.xml' : $index_file;

if (is_file($want)) {
    readfile($want);
} else {
    // Cache unavailable/unwritable (or page out of range) → answer live so the
    // sitemap never 500s. Capped, and paginated the same way as the cache.
    $urls   = ss_sitemap_urls($pdo, $site_url, $is_carousel, $cap);
    $chunks = array_chunk($urls, SS_SITEMAP_PAGE_SIZE) ?: [[]];
    if ($page > 0) {
        echo ss_sitemap_urlset($chunks[$page - 1] ?? []);
    } else {
        echo ss_sitemap_index($site_url, count($chunks));
    }
}
// ===== SNAPSMACK EOF =====
