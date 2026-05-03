<?php
/**
 * SNAPSMACK - Calendar Engine AJAX Endpoint
 *
 * Returns JSON for the archive calendar sidebar engine.
 * Called by ss-engine-calendar.js when the panel opens or the user
 * navigates to a different month.
 *
 * GET parameters:
 *   offset  (int)   — month offset from current month (0 = now, -1 = last month, etc.)
 *   months  (int)   — number of months to return (1-12, default 1)
 *   count   (int)   — number of recent posts to return (5-20, default 10)
 *
 * Response shape:
 *   {
 *     base_url:     "https://example.com/archive.php",
 *     months: [
 *       {
 *         year:  2026,
 *         month: 4,
 *         name:  "April",
 *         days:  { "2026-04-03": 2, "2026-04-10": 1, ... }
 *       },
 *       ...
 *     ],
 *     recent_posts: [
 *       { title: "...", url: "...", date: "2026-04-10" },
 *       ...
 *     ]
 *   }
 */

require_once 'core/db.php';

// Only respond to XHR
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

// --- PARAMETERS ---
$offset      = max(-120, min(0, (int)($_GET['offset'] ?? 0)));
$months_req  = max(1, min(12, (int)($_GET['months'] ?? 1)));
$post_count  = max(5, min(20, (int)($_GET['count']  ?? 10)));

// --- BASE URL ---
try {
    $settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $settings = [];
}

if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('BASE_URL', $protocol . '://' . $host . '/');
}

$archive_url = BASE_URL . 'archive.php';

// --- BUILD MONTH DATA ---
$month_names = [
    1 => 'January', 2 => 'February', 3 => 'March',     4 => 'April',
    5 => 'May',     6 => 'June',     7 => 'July',       8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

$now_local  = date('Y-m-d H:i:s');
$months_out = [];

for ($m = 0; $m < $months_req; $m++) {
    // Work backwards from offset: offset=-2, m=0 → 2 months back; m=1 → 3 months back
    $total_offset = $offset - $m;
    $ts    = strtotime("first day of this month " . abs($total_offset) . " months " . ($total_offset <= 0 ? "ago" : "ahead"), strtotime('today'));
    if ($total_offset > 0) {
        // strtotime doesn't handle "ahead" natively; use mktime
        $ts = mktime(0, 0, 0, date('n') + $total_offset, 1, date('Y'));
    }

    $year  = (int) date('Y', $ts);
    $month = (int) date('n', $ts);

    // First and last day of this month
    $month_start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
    $month_end   = sprintf('%04d-%02d-%02d 23:59:59', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));

    // Count published images per day in this month
    try {
        $stmt = $pdo->prepare("
            SELECT DATE(img_date) as post_date, COUNT(*) as cnt
            FROM snap_images
            WHERE img_status = 'published'
              AND img_date >= ?
              AND img_date <= ?
              AND img_date <= ?
            GROUP BY DATE(img_date)
        ");
        $stmt->execute([$month_start, $month_end, $now_local]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $rows = [];
    }

    $days = [];
    foreach ($rows as $row) {
        $days[$row['post_date']] = (int) $row['cnt'];
    }

    $months_out[] = [
        'year'  => $year,
        'month' => $month,
        'name'  => $month_names[$month],
        'days'  => $days,
    ];
}

// --- RECENT POSTS ---
try {
    $stmt = $pdo->prepare("
        SELECT img_title, img_slug, DATE(img_date) as post_date
        FROM snap_images
        WHERE img_status = 'published'
          AND img_date <= ?
        ORDER BY img_date DESC, id DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $now_local, PDO::PARAM_STR);
    $stmt->bindValue(2, $post_count, PDO::PARAM_INT);
    $stmt->execute();
    $recent_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_rows = [];
}

$recent_posts = [];
foreach ($recent_rows as $row) {
    // Build the URL to the single post page
    $recent_posts[] = [
        'title' => $row['img_title'],
        'url'   => BASE_URL . $row['img_slug'],
        'date'  => $row['post_date'],
    ];
}

// --- RESPONSE ---
echo json_encode([
    'base_url'     => $archive_url,
    'months'       => $months_out,
    'recent_posts' => $recent_posts,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
