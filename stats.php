<?php
/**
 * SNAPSMACK - Public Stats Endpoint
 *
 * Returns a small JSON payload with site statistics for display on
 * the snapsmack.ca skin gallery hover cards. No authentication required.
 * No personal data is exposed — only aggregate counts.
 *
 * Response fields:
 *   site_name       string  — from snap_settings
 *   posts           int     — published images
 *   views_30d       int     — total page views, last 30 days (snap_stats_daily)
 *   unique_30d      int     — unique visitors, last 30 days
 *   active_since    string  — date of first published post (Y-m-d)
 *   version         string  — installed SnapSmack version
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

// CORS — allow snapsmack.ca to fetch this cross-domain
header('Access-Control-Allow-Origin: https://snapsmack.ca');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600'); // 1-hour browser cache

// Bootstrap: constants + DB only, no session, no auth
define('SNAPSMACK_STATS_REQUEST', true);
try {
    require_once __DIR__ . '/core/db.php';
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error' => 'unavailable']);
    exit;
}
require_once __DIR__ . '/core/constants.php';

try {
    // Settings
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);

    // Post count
    $posts = (int)$pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status = 'published'")->fetchColumn();

    // Stats — last 30 days
    $stats = $pdo->query("
        SELECT COALESCE(SUM(total_views), 0)     AS views_30d,
               COALESCE(SUM(unique_visitors), 0) AS unique_30d
        FROM snap_stats_daily
        WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ")->fetch();

    // Stats — all time (live)
    $stats_all = $pdo->query("
        SELECT COALESCE(SUM(total_views), 0)     AS views_all,
               COALESCE(SUM(unique_visitors), 0) AS unique_all
        FROM snap_stats_daily
    ")->fetch();

    // Imported view history (e.g. Flickr count_views, stored per image as img_view_seed).
    // Adds to ALL-TIME views only — never the 30-day window, and never uniques (imports
    // carry real view tallies but no unique-visitor data; see FLKR FCKR history spec).
    $seed_views = (int)$pdo->query("
        SELECT COALESCE(SUM(img_view_seed), 0) FROM snap_images WHERE img_status = 'published'
    ")->fetchColumn();

    // Active since — an explicit 'active_since' setting wins (e.g. a Flickr membership that
    // predates the imported data, or a placeholder date sitting on imported rows); else
    // fall back to the earliest published post date.
    $since = (string)($settings['active_since'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $since)) {
        $since = (string)$pdo->query("
            SELECT DATE(MIN(img_date)) FROM snap_images WHERE img_status = 'published'
        ")->fetchColumn();
    }

    echo json_encode([
        'site_name'    => $settings['site_name']    ?? 'SnapSmack Site',
        'posts'        => $posts,
        'views_30d'    => (int)($stats['views_30d']       ?? 0),
        'unique_30d'   => (int)($stats['unique_30d']      ?? 0),
        'views_all'    => (int)($stats_all['views_all']   ?? 0) + $seed_views,
        'unique_all'   => (int)($stats_all['unique_all']  ?? 0),
        'active_since' => $since ?: null,
        'version'      => SNAPSMACK_VERSION_SHORT,
    ], JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'query_failed']);
}
// ===== SNAPSMACK EOF =====
