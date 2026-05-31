<?php
/**
 * SNAPSMACK - Multisite API Handler
 *
 * Handles inbound API requests from hub installations. Endpoints are routed
 * by api.php. All write endpoints require a valid Bearer API key. The
 * /handshake endpoint uses a one-time registration token instead.
 *
 * Route structure: multisite/{resource}/{sub-action}
 *   multisite/handshake
 *   multisite/heartbeat
 *   multisite/comments/pending
 *   multisite/comments/action
 *   multisite/posts/recent
 *   multisite/posts/create
 *   multisite/stats/daily
 *   multisite/updates/status
 *   multisite/backup/status
 *   multisite/backup/log
 *   multisite/auth/sso-token
 *   multisite/blogroll/list
 *   multisite/blogroll/sync
 *   multisite/disconnect
 *   multisite/ban-sync      — bidirectional ban hash exchange (Shield Tier 1)
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// --- ENVIRONMENT BOOTSTRAP ---
if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    define('BASE_URL', $protocol . '://' . $_SERVER['HTTP_HOST'] . '/');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/mesh-helpers.php';

// --- RESPONSE HELPERS ---
function ms_respond($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ms_ok(array $data = []): void  { ms_respond(array_merge(['ok' => true], $data)); }
function ms_err(string $msg, int $code = 400): void { ms_respond(['ok' => false, 'error' => $msg], $code); }

// --- ROUTE PARSING ---
// Route arrives as the portion after 'api/' e.g. 'multisite/comments/pending'
$parts      = explode('/', trim($GLOBALS['route'] ?? ($_GET['route'] ?? ''), '/'));
$resource   = $parts[1] ?? '';   // heartbeat, comments, posts, etc.
$sub_action = $parts[2] ?? '';   // pending, action, recent, etc.
$method     = $_SERVER['REQUEST_METHOD'];

// --- SETTINGS ---
try {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    ms_err('Database unavailable', 503);
}

// --- AUTHORIZATION HEADER HELPER ---
// nginx/PHP-FPM often strips HTTP_AUTHORIZATION from $_SERVER.
// Fall back to getallheaders() so Bearer auth works regardless of server config.
function ms_get_auth_header(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        $hdrs = getallheaders();
        foreach ($hdrs as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
        }
    }
    return $h;
}

// --- HTTPS CHECK (handshake only) ---
// Handles direct HTTPS, reverse proxy (X-Forwarded-Proto), and Cloudflare Tunnel (CF-Visitor).
$is_https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
         || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
         || (json_decode($_SERVER['HTTP_CF_VISITOR'] ?? '{}', true)['scheme'] ?? '') === 'https';

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: POST multisite/handshake
// One-time registration. Validates the reg token, issues API keys, stores node.
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'handshake' && $method === 'POST') {
    if (!$is_https) {
        ms_err('HTTPS required for handshake');
    }

    $remote_url  = trim($_POST['site_url']  ?? '');
    $remote_name = trim($_POST['site_name'] ?? 'Unknown Hub');
    $token       = trim($_POST['token']     ?? '');

    if (!$remote_url || !$token) {
        ms_err('site_url and token are required');
    }

    $stored_token   = $settings['multisite_reg_token']         ?? '';
    $token_expires  = (int)($settings['multisite_reg_token_expires'] ?? 0);

    if (!$stored_token || !hash_equals($stored_token, $token) || time() > $token_expires) {
        ms_err('Invalid or expired registration token', 401);
    }

    // Generate a persistent key pair: hub calls us with api_key_local,
    // we call the hub with api_key_remote.
    $api_key_local  = bin2hex(random_bytes(32));  // hub → spoke auth
    $api_key_remote = bin2hex(random_bytes(32));  // spoke → hub auth (returned to hub)

    try {
        $pdo->prepare("
            INSERT INTO snap_multisite_nodes
                (role, site_url, site_name, api_key_local, api_key_remote, status, connected_at)
            VALUES ('hub', ?, ?, ?, ?, 'active', NOW())
            ON DUPLICATE KEY UPDATE
                role           = VALUES(role),
                api_key_local  = VALUES(api_key_local),
                api_key_remote = VALUES(api_key_remote),
                site_name      = VALUES(site_name),
                status         = 'active',
                connected_at   = NOW()
        ")->execute([$remote_url, $remote_name, $api_key_local, $api_key_remote]);

        // Burn the one-time token
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, '') ON DUPLICATE KEY UPDATE setting_val = ''")->execute(['multisite_reg_token']);
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, '0') ON DUPLICATE KEY UPDATE setting_val = '0'")->execute(['multisite_reg_token_expires']);

        ms_ok([
            'api_key'          => $api_key_local,   // hub presents this when calling us (hub→spoke)
            'api_key_outbound' => $api_key_remote,  // we present this when calling hub (spoke→hub)
            'site_url'         => BASE_URL,
            'site_name'        => $settings['site_name'] ?? 'SnapSmack',
            'version'          => SNAPSMACK_VERSION,
        ]);
    } catch (\PDOException $e) {
        ms_err('Registration failed: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: GET multisite/ping
// Spoke-initiated connectivity check. Uses api_key_remote auth (the key the
// spoke generated during handshake, stored on the hub as api_key_remote).
// Normal Bearer auth uses api_key_local which is hub→spoke only.
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'ping' && $method === 'GET') {
    $ping_key = '';
    if (preg_match('/^Bearer\s+(\S+)$/i', ms_get_auth_header(), $pm)) {
        $ping_key = $pm[1];
    }
    if (!$ping_key) ms_err('Authorization header required', 401);

    $spoke_stmt = $pdo->prepare("
        SELECT id FROM snap_multisite_nodes
        WHERE api_key_remote = ? AND role = 'spoke'
        LIMIT 1
    ");
    $spoke_stmt->execute([$ping_key]);
    $spoke_row = $spoke_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$spoke_row) {
        ms_err('Invalid key or spoke not registered', 401);
    }

    // Mark spoke as active — ping is the recovery path for offline spokes.
    $pdo->prepare("
        UPDATE snap_multisite_nodes
        SET status = 'active', last_seen_at = NOW()
        WHERE id = ?
    ")->execute([$spoke_row['id']]);

    // Mesh: if THIS install is a hub, include the canonical roster of peers
    // so the spoke can learn about its siblings. Excludes the caller from
    // the roster so they don't see themselves.
    $mesh_roster = [];
    if (($settings['multisite_role'] ?? '') === 'hub') {
        // Look up the calling spoke's URL so we exclude them.
        $caller_url_stmt = $pdo->prepare(
            "SELECT site_url FROM snap_multisite_nodes
             WHERE api_key_remote = ? AND role = 'spoke' LIMIT 1"
        );
        $caller_url_stmt->execute([$ping_key]);
        $caller_url = (string)($caller_url_stmt->fetchColumn() ?: '');
        $mesh_roster = ms_build_roster($pdo, $caller_url);
    }

    ms_ok([
        'version' => SNAPSMACK_VERSION,
        'mesh'    => [
            'role'   => $settings['multisite_role'] ?? '',
            'peers'  => $mesh_roster,
            'hub_url'=> ($settings['multisite_role'] ?? '') === 'hub' ? BASE_URL : '',
        ],
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: GET multisite/peers/list
// Hub-only. Returns the canonical roster for any authenticated peer that
// asks. Spokes use this on demand (e.g. from a "Sync Network Roster" button)
// in addition to the implicit roster delivery on ping.
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'peers' && $sub_action === 'list' && $method === 'GET') {
    if (($settings['multisite_role'] ?? '') !== 'hub') {
        ms_err('Only the hub can serve the peer roster', 403);
    }

    $peers_key = '';
    if (preg_match('/^Bearer\s+(\S+)$/i', ms_get_auth_header(), $pm2)) {
        $peers_key = $pm2[1];
    }
    if (!$peers_key) ms_err('Authorization header required', 401);

    $caller_stmt = $pdo->prepare(
        "SELECT site_url FROM snap_multisite_nodes
         WHERE api_key_remote = ? AND status = 'active' LIMIT 1"
    );
    $caller_stmt->execute([$peers_key]);
    $caller_url = (string)($caller_stmt->fetchColumn() ?: '');
    if ($caller_url === '') ms_err('Invalid key', 401);

    ms_ok([
        'hub_url' => BASE_URL,
        'peers'   => ms_build_roster($pdo, $caller_url),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// BEARER TOKEN AUTH — all endpoints below require this
// ─────────────────────────────────────────────────────────────────────────────
$api_key = '';
if (preg_match('/^Bearer\s+(\S+)$/i', ms_get_auth_header(), $m)) {
    $api_key = $m[1];
}
if (!$api_key) {
    ms_err('Authorization header required', 401);
}

$node_stmt = $pdo->prepare("
    SELECT id, site_url, site_name, role
    FROM snap_multisite_nodes
    WHERE api_key_local = ? AND status = 'active'
    LIMIT 1
");
$node_stmt->execute([$api_key]);
$node = $node_stmt->fetch(PDO::FETCH_ASSOC);

if (!$node) {
    ms_err('Invalid or revoked API key', 401);
}

$node_id = $node['id'];

// Touch last_seen
$pdo->prepare("UPDATE snap_multisite_nodes SET last_seen_at = NOW() WHERE id = ?")->execute([$node_id]);

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: GET multisite/heartbeat
// Returns site vitals: version, counts, backup state.
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'heartbeat' && $method === 'GET') {
    // Transmissions (snap_images) are the primary content type in SnapSmack.
    // snap_posts wraps them but the canonical count is on snap_images.
    $post_count  = (int)$pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status = 'published'")->fetchColumn();
    $image_count = $post_count; // same source — kept for API compat
    $pending     = (int)$pdo->query("SELECT COUNT(*) FROM snap_comments WHERE is_approved = 0")->fetchColumn();

    // Disk usage — uploads folder
    $upload_dir  = dirname(__DIR__) . '/uploads/';
    $disk_bytes  = 0;
    if (is_dir($upload_dir)) {
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($upload_dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $f) { $disk_bytes += $f->getSize(); }
    }

    ms_ok([
        'version'            => SNAPSMACK_VERSION_SHORT,
        'update_track'       => $settings['update_track'] ?? 'stable',
        'post_count'         => $post_count,
        'image_count'        => $image_count,
        'pending_comments'   => $pending,
        'last_backup_at'     => $settings['last_backup_at']     ?? null,
        'last_backup_size'   => $settings['last_backup_size']   ?? null,
        'last_backup_dest'   => $settings['last_backup_dest']   ?? null,
        'last_backup_status' => $settings['last_backup_status'] ?? 'unknown',
        'disk_usage_bytes'   => $disk_bytes,
        'site_tagline'       => $settings['site_tagline'] ?? '',
        'maintenance_mode'   => ($settings['maintenance_mode'] ?? '0') === '1' ? 1 : 0,
        'smackback_status'   => $settings['smackback_status']   ?? 'unknown',
        'smackback_breach_at'=> ($settings['smackback_breach_at'] ?? '') ?: null,
        'timestamp'          => date('c'),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: GET multisite/comments/pending
// Returns unapproved comments with the image title they're attached to.
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'comments' && $sub_action === 'pending' && $method === 'GET') {
    $rows = $pdo->query("
        SELECT
            c.id,
            c.img_id,
            c.comment_author,
            c.comment_email,
            c.comment_text,
            c.comment_date,
            c.comment_ip,
            i.img_title,
            i.img_slug
        FROM snap_comments c
        LEFT JOIN snap_images i ON i.id = c.img_id
        WHERE c.is_approved = 0
        ORDER BY c.comment_date DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);

    ms_ok(['comments' => $rows, 'count' => count($rows)]);
}

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: POST multisite/comments/action
// Approve or delete a comment by ID.
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'comments' && $sub_action === 'action' && $method === 'POST') {
    if ($node['role'] !== 'hub') ms_err('Only a hub may moderate comments on this spoke', 403);
    $comment_id  = (int)($_POST['comment_id'] ?? 0);
    $action_type = $_POST['action'] ?? '';

    if (!$comment_id || !in_array($action_type, ['approve', 'delete'], true)) {
        ms_err('comment_id and action (approve|delete) required');
    }

    $exists = $pdo->prepare("SELECT id FROM snap_comments WHERE id = ?");
    $exists->execute([$comment_id]);
    if (!$exists->fetchColumn()) {
        ms_err('Comment not found', 404);
    }

    if ($action_type === 'approve') {
        $pdo->prepare("UPDATE snap_comments SET is_approved = 1 WHERE id = ?")->execute([$comment_id]);
    } else {
        $pdo->prepare("DELETE FROM snap_comments WHERE id = ?")->execute([$comment_id]);
    }

    ms_ok(['comment_id' => $comment_id, 'action' => $action_type]);
}

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: GET multisite/posts/recent
// Recent published posts with their primary image thumbnail.
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'posts' && $sub_action === 'recent' && $method === 'GET') {
    $limit = min((int)($_GET['limit'] ?? 20), 100);

    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.title,
            p.slug,
            p.description,
            p.post_type,
            p.created_at,
            i.img_file,
            i.img_thumb_aspect,
            i.img_thumb_square,
            i.img_width,
            i.img_height
        FROM snap_posts p
        LEFT JOIN snap_images i ON i.post_id = p.id
        WHERE p.status = 'published'
        ORDER BY p.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepend BASE_URL to image paths
    foreach ($posts as &$post) {
        if ($post['img_thumb_aspect']) {
            $post['thumb_url'] = BASE_URL . ltrim($post['img_thumb_aspect'], '/');
        } elseif ($post['img_file']) {
            $post['thumb_url'] = BASE_URL . ltrim($post['img_file'], '/');
        } else {
            $post['thumb_url'] = null;
        }
        $post['post_url'] = BASE_URL . ltrim($post['slug'], '/');
        unset($post['img_file'], $post['img_thumb_aspect'], $post['img_thumb_square']);
    }
    unset($post);

    ms_ok(['posts' => $posts, 'count' => count($posts)]);
}

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: GET multisite/stats/daily
// Pre-aggregated daily stats from snap_stats_daily.
// Optional &enriched=1 adds: top_images[], bot_total, top_day, period_totals.
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'stats' && $sub_action === 'daily' && $method === 'GET') {
    $days     = (int)($_GET['days'] ?? 30);
    $all_time = ($days === 0);
    $enriched = !empty($_GET['enriched']);

    if ($all_time) {
        $stmt = $pdo->query("
            SELECT stat_date, total_views, unique_visitors, bot_views, top_referrer
            FROM snap_stats_daily
            ORDER BY stat_date DESC
        ");
    } else {
        $days = max(1, min($days, 3650));   // clamp 1–3650 days (~10 years)
        $stmt = $pdo->prepare("
            SELECT stat_date, total_views, unique_visitors, bot_views, top_referrer
            FROM snap_stats_daily
            WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            ORDER BY stat_date DESC
        ");
        $stmt->execute([$days]);
    }
    $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $payload = ['stats' => $stats, 'period_days' => $days];

    // ── ENRICHED MODE ────────────────────────────────────────────────────────
    if ($enriched) {
        // Period totals + top_day from snap_stats_daily
        $bot_total   = 0;
        $top_day     = ['date' => null, 'views' => 0];
        $period_views  = 0;
        $period_unique = 0;
        foreach ($stats as $row) {
            $period_views  += (int)$row['total_views'];
            $period_unique += (int)$row['unique_visitors'];
            $bot_total     += (int)($row['bot_views'] ?? 0);
            if ((int)$row['total_views'] > $top_day['views']) {
                $top_day = ['date' => $row['stat_date'], 'views' => (int)$row['total_views']];
            }
        }

        // Top images from raw snap_stats — human traffic only, joined to snap_images
        $top_images = [];
        try {
            if ($all_time) {
                $img_stmt = $pdo->query("
                    SELECT s.image_id,
                           i.img_title, i.img_slug, i.img_file,
                           i.img_thumb_aspect, i.img_thumb_square,
                           COUNT(*) AS view_count
                    FROM snap_stats s
                    JOIN snap_images i ON i.id = s.image_id
                    WHERE s.is_bot = 0 AND s.image_id IS NOT NULL
                    GROUP BY s.image_id
                    ORDER BY view_count DESC
                    LIMIT 30
                ");
            } else {
                $img_stmt = $pdo->prepare("
                    SELECT s.image_id,
                           i.img_title, i.img_slug, i.img_file,
                           i.img_thumb_aspect, i.img_thumb_square,
                           COUNT(*) AS view_count
                    FROM snap_stats s
                    JOIN snap_images i ON i.id = s.image_id
                    WHERE s.is_bot = 0
                      AND s.image_id IS NOT NULL
                      AND s.hit_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    GROUP BY s.image_id
                    ORDER BY view_count DESC
                    LIMIT 30
                ");
                $img_stmt->execute([$days]);
            }
            $img_rows = $img_stmt->fetchAll(PDO::FETCH_ASSOC);
            $base = rtrim(BASE_URL, '/');
            foreach ($img_rows as $img) {
                // Prefer aspect-ratio thumb, fallback to square, fallback to constructed path
                if (!empty($img['img_thumb_aspect'])) {
                    $thumb = $base . '/' . ltrim($img['img_thumb_aspect'], '/');
                } elseif (!empty($img['img_thumb_square'])) {
                    $thumb = $base . '/' . ltrim($img['img_thumb_square'], '/');
                } else {
                    $file = ltrim($img['img_file'], '/');
                    $thumb = $base . '/' . dirname($file) . '/thumbs/t_' . basename($file);
                }
                $top_images[] = [
                    'id'        => (int)$img['image_id'],
                    'title'     => $img['img_title'],
                    'slug'      => $img['img_slug'],
                    'thumb_url' => $thumb,
                    'page_url'  => $base . '/' . ltrim($img['img_slug'], '/'),
                    'views'     => (int)$img['view_count'],
                ];
            }
        } catch (\Exception $e) {
            // snap_stats may not have data yet; return empty array
        }

        $payload['top_images']    = $top_images;
        $payload['bot_total']     = $bot_total;
        $payload['top_day']       = $top_day;
        $payload['period_totals'] = [
            'views'  => $period_views,
            'unique' => $period_unique,
            'bot'    => $bot_total,
        ];
        $payload['site_url'] = rtrim(BASE_URL, '/');

        // ── BROWSERS ─────────────────────────────────────────────────────────
        try {
            if ($all_time) {
                $b_stmt = $pdo->query("SELECT browser, COUNT(*) AS hits FROM snap_stats WHERE is_bot = 0 AND browser IS NOT NULL AND browser != '' GROUP BY browser ORDER BY hits DESC LIMIT 15");
            } else {
                $b_stmt = $pdo->prepare("SELECT browser, COUNT(*) AS hits FROM snap_stats WHERE is_bot = 0 AND browser IS NOT NULL AND browser != '' AND hit_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY browser ORDER BY hits DESC LIMIT 15");
                $b_stmt->execute([$days]);
            }
            $payload['browsers'] = $b_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) { $payload['browsers'] = []; }

        // ── OS ───────────────────────────────────────────────────────────────
        try {
            if ($all_time) {
                $os_stmt = $pdo->query("SELECT os, COUNT(*) AS hits FROM snap_stats WHERE is_bot = 0 AND os IS NOT NULL AND os != '' GROUP BY os ORDER BY hits DESC LIMIT 15");
            } else {
                $os_stmt = $pdo->prepare("SELECT os, COUNT(*) AS hits FROM snap_stats WHERE is_bot = 0 AND os IS NOT NULL AND os != '' AND hit_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY os ORDER BY hits DESC LIMIT 15");
                $os_stmt->execute([$days]);
            }
            $payload['os'] = $os_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) { $payload['os'] = []; }

        // ── CATEGORIES ───────────────────────────────────────────────────────
        try {
            if ($all_time) {
                $cat_stmt = $pdo->query("SELECT c.cat_name, COUNT(*) AS views FROM snap_stats s JOIN snap_image_cat_map m ON s.image_id = m.image_id JOIN snap_categories c ON m.cat_id = c.id WHERE s.is_bot = 0 AND s.image_id IS NOT NULL GROUP BY c.id, c.cat_name ORDER BY views DESC LIMIT 15");
            } else {
                $cat_stmt = $pdo->prepare("SELECT c.cat_name, COUNT(*) AS views FROM snap_stats s JOIN snap_image_cat_map m ON s.image_id = m.image_id JOIN snap_categories c ON m.cat_id = c.id WHERE s.is_bot = 0 AND s.image_id IS NOT NULL AND s.hit_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY c.id, c.cat_name ORDER BY views DESC LIMIT 15");
                $cat_stmt->execute([$days]);
            }
            $payload['categories'] = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) { $payload['categories'] = []; }

        // ── SEARCH TERMS ─────────────────────────────────────────────────────
        try {
            if ($all_time) {
                $st_stmt = $pdo->query("SELECT search_term, COUNT(*) AS uses FROM snap_stats WHERE is_bot = 0 AND search_term IS NOT NULL AND search_term != '' GROUP BY search_term ORDER BY uses DESC LIMIT 20");
            } else {
                $st_stmt = $pdo->prepare("SELECT search_term, COUNT(*) AS uses FROM snap_stats WHERE is_bot = 0 AND search_term IS NOT NULL AND search_term != '' AND hit_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY search_term ORDER BY uses DESC LIMIT 20");
                $st_stmt->execute([$days]);
            }
            $payload['search_terms'] = $st_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) { $payload['search_terms'] = []; }

        // ── PEAK HOURS (dow 1=Sun..7=Sat, hour 0-23) ─────────────────────────
        try {
            if ($all_time) {
                $ph_stmt = $pdo->query("SELECT DAYOFWEEK(hit_at) AS dow, HOUR(hit_at) AS hour, COUNT(*) AS hits FROM snap_stats WHERE is_bot = 0 GROUP BY DAYOFWEEK(hit_at), HOUR(hit_at)");
            } else {
                $ph_stmt = $pdo->prepare("SELECT DAYOFWEEK(hit_at) AS dow, HOUR(hit_at) AS hour, COUNT(*) AS hits FROM snap_stats WHERE is_bot = 0 AND hit_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY DAYOFWEEK(hit_at), HOUR(hit_at)");
                $ph_stmt->execute([$days]);
            }
            $payload['peak_hours'] = $ph_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) { $payload['peak_hours'] = []; }

        // ── COUNTRIES ────────────────────────────────────────────────────────
        try {
            if ($all_time) {
                $co_stmt = $pdo->query("SELECT country, COUNT(*) AS hits FROM snap_stats WHERE is_bot = 0 AND country IS NOT NULL AND country != '' GROUP BY country ORDER BY hits DESC LIMIT 20");
            } else {
                $co_stmt = $pdo->prepare("SELECT country, COUNT(*) AS hits FROM snap_stats WHERE is_bot = 0 AND country IS NOT NULL AND country != '' AND hit_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY country ORDER BY hits DESC LIMIT 20");
                $co_stmt->execute([$days]);
            }
            $payload['countries'] = $co_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) { $payload['countries'] = []; }
    }

    ms_ok($payload);
}

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: GET multisite/updates/status
// Current version. Hub uses this to flag stale spokes.
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'updates' && $sub_action === 'status' && $method === 'GET') {
    ms_ok([
        'version'          => SNAPSMACK_VERSION,
        'version_short'    => SNAPSMACK_VERSION_SHORT,
        'version_codename' => SNAPSMACK_VERSION_CODENAME,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: GET multisite/backup/status
// Current backup health from snap_settings.
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'backup' && $sub_action === 'status' && $method === 'GET') {
    ms_ok([
        'last_backup_at'     => $settings['last_backup_at']     ?? null,
        'last_backup_size'   => $settings['last_backup_size']   ?? null,
        'last_backup_dest'   => $settings['last_backup_dest']   ?? null,
        'last_backup_status' => $settings['last_backup_status'] ?? 'unknown',
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: GET multisite/backup/log
// Last 10 backup events from snap_backup_log (if table exists).
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'backup' && $sub_action === 'log' && $method === 'GET') {
    try {
        $log = $pdo->query("
            SELECT created_at, status, size_bytes, destination, notes
            FROM snap_backup_log
            ORDER BY created_at DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
        $log = [];   // table doesn't exist yet
    }
    ms_ok(['log' => $log]);
}

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: GET multisite/backup/config
// Returns this site's cloud provider, folder target, and site metadata so that
// SUYB can auto-populate profile fields without manual entry. Never exposes
// secrets (client_secret, refresh_token) — only the provider type and folder.
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'backup' && $sub_action === 'config' && $method === 'GET') {
    $cloud_provider = 'none';
    if (!empty($settings['google_client_id']) && !empty($settings['google_refresh_token'])) {
        $cloud_provider = 'google_drive';
    } elseif (!empty($settings['onedrive_client_id']) && !empty($settings['onedrive_refresh_token'])) {
        $cloud_provider = 'onedrive';
    }

    ms_ok([
        'site_url'       => BASE_URL,
        'site_name'      => $settings['site_name'] ?? 'SnapSmack',
        'cloud_provider' => $cloud_provider,
        'cloud_folder_id'=> $settings['google_drive_folder_id'] ?? $settings['onedrive_folder_id'] ?? '',
        'version'        => SNAPSMACK_VERSION,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: GET multisite/backup/export
// Serves a SQL dump (schema or full) so SUYB on the hub can pull database
// exports from spokes without needing direct DB credentials.
//   ?dump=schema  — DDL only
//   ?dump=full    — schema + data (default)
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'backup' && $sub_action === 'export' && $method === 'GET') {
    require_once __DIR__ . '/export-engine.php';

    $dump_type = $_GET['dump'] ?? 'full';
    if (!in_array($dump_type, ['schema', 'full'], true)) {
        ms_err('dump must be "schema" or "full"');
    }

    $exporter = new SnapSmackExport($pdo, dirname(__DIR__));
    $sql = $exporter->generateSqlDump($dump_type);

    $siteName = $settings['site_name'] ?? 'snapsmack';
    $siteSlug = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($siteName));
    $siteSlug = trim($siteSlug, '_') ?: 'snapsmack';
    $timestamp = date('Y-m-d_H-i');

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . "{$siteSlug}_{$dump_type}_{$timestamp}.sql" . '"');
    header('Content-Length: ' . strlen($sql));
    echo $sql;
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: POST multisite/posts/create
// Cross-post: create a new image record from a hub-originated post.
// The hub sends metadata + a publicly accessible URL for the image file.
// This spoke fetches the image, saves it locally, and creates the record.
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'posts' && $sub_action === 'create' && $method === 'POST') {
    $title      = trim($_POST['title']      ?? '');
    $img_url    = trim($_POST['img_url']    ?? '');
    $img_ext    = strtolower(trim($_POST['img_ext'] ?? 'jpg'));
    $img_status = in_array($_POST['img_status'] ?? '', ['published', 'draft']) ? $_POST['img_status'] : 'draft';
    $description = trim($_POST['description'] ?? '');
    $img_date   = trim($_POST['img_date']   ?? date('Y-m-d'));
    $film_val   = trim($_POST['film']       ?? '');

    if (!$title) ms_err('title is required');
    if (!$img_url || !filter_var($img_url, FILTER_VALIDATE_URL)) ms_err('valid img_url is required');

    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
    if (!in_array($img_ext, $allowed_exts)) ms_err('unsupported image format');

    // Fetch the image from the hub
    $fetch_ch = curl_init();
    curl_setopt_array($fetch_ch, [
        CURLOPT_URL            => $img_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);
    $img_binary = curl_exec($fetch_ch);
    $fetch_code = curl_getinfo($fetch_ch, CURLINFO_HTTP_CODE);
    curl_close($fetch_ch);

    if (!$img_binary || $fetch_code !== 200) {
        ms_err('Could not fetch image from hub: HTTP ' . $fetch_code);
    }

    // Validate the fetched bytes are actually an image before saving
    $img_info_pre = @getimagesizefromstring($img_binary);
    if (!$img_info_pre) {
        ms_err('Fetched content is not a valid image');
    }

    // Save the image
    $rel_dir  = 'img_uploads/' . date('Y') . '/' . date('m');
    $full_dir = __DIR__ . '/../' . $rel_dir;
    if (!is_dir($full_dir)) {
        @mkdir($full_dir, 0755, true);
    }

    $slug     = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)) . '-' . time();
    $filename = $slug . '.' . $img_ext;
    $db_path  = $rel_dir . '/' . $filename;
    $filepath = $full_dir . '/' . $filename;

    if (file_put_contents($filepath, $img_binary) === false) {
        ms_err('Could not save image to disk');
    }

    // Get image dimensions
    $img_w = $img_h = 0;
    $size = @getimagesize($filepath);
    if ($size) { $img_w = $size[0]; $img_h = $size[1]; }

    // Build EXIF from JPEG if possible
    $img_exif = null;
    if (in_array($img_ext, ['jpg', 'jpeg'])) {
        $exif_raw = @exif_read_data($filepath);
        if ($exif_raw) {
            $img_exif = json_encode([
                'camera'   => $exif_raw['Model']                       ?? '',
                'iso'      => $exif_raw['ISOSpeedRatings']             ?? '',
                'aperture' => $exif_raw['COMPUTED']['ApertureFNumber'] ?? '',
                'shutter'  => $exif_raw['ExposureTime']                ?? '',
                'focal'    => $exif_raw['FocalLength']                 ?? '',
            ]);
        }
    }

    // Create the image record
    try {
        $stmt = $pdo->prepare("
            INSERT INTO snap_images (
                img_title, img_slug, img_file, img_description,
                img_film, img_exif, img_status, img_date,
                img_width, img_height, allow_comments, allow_download
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0)
        ");
        $stmt->execute([
            $title, $slug, $db_path, $description,
            $film_val, $img_exif, $img_status, $img_date,
            $img_w, $img_h,
        ]);
        $new_id = $pdo->lastInsertId();
    } catch (\PDOException $e) {
        @unlink($filepath);  // Clean up saved image on DB failure
        ms_err('Database error: ' . $e->getMessage());
    }

    ms_ok([
        'img_id'     => (int)$new_id,
        'slug'       => $slug,
        'img_status' => $img_status,
        'post_url'   => BASE_URL . $slug,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: POST multisite/auth/sso-token
// Generates a short-lived one-time SSO token so the hub can launch a
// browser session on this spoke without knowing its admin password.
// Token is single-use and expires in 5 minutes.
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'auth' && $sub_action === 'sso-token' && $method === 'POST') {
    $token   = bin2hex(random_bytes(32));   // 64-char hex
    $expires = time() + 300;               // 5 minutes

    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?")
        ->execute(['multisite_sso_token', $token, $token]);
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?")
        ->execute(['multisite_sso_token_expires', $expires, $expires]);

    ms_ok(['sso_token' => $token, 'expires_at' => $expires]);
}

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: GET multisite/blogroll/list
// Returns all active blogroll entries with their category names.
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'blogroll' && $sub_action === 'list' && $method === 'GET') {
    try {
        $rows = $pdo->query("
            SELECT b.id, b.peer_name, b.peer_url, b.peer_rss, b.peer_desc,
                   c.cat_name AS category
            FROM snap_blogroll b
            LEFT JOIN snap_blogroll_cats c ON b.cat_id = c.id
            ORDER BY c.cat_name ASC, b.peer_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
        $rows = [];
    }
    ms_ok(['entries' => $rows, 'count' => count($rows)]);
}

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: POST multisite/blogroll/sync
// Hub pushes its blogroll to this spoke. Entries are tagged with
// the hub's site_url as their source and managed as a group: any prior
// hub-synced entries are removed before the new set is inserted.
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'blogroll' && $sub_action === 'sync' && $method === 'POST') {
    $hub_url_raw = trim($_POST['hub_url']   ?? '');
    // Normalize to hostname-only form so storage and comparison always agree.
    // Migration 052 stamps source_hub_url the same way (substring after 'Hub: ').
    $hub_url     = preg_replace('~^https?://~i', '', rtrim($hub_url_raw, '/'));
    $entries_raw = trim($_POST['entries']   ?? '');

    if (!$hub_url_raw || !$hub_url) ms_err('hub_url required');

    $entries = json_decode($entries_raw, true);
    if (!is_array($entries)) ms_err('entries must be a JSON array');

    // Remove every entry previously synced from this hub. We track origin via
    // snap_blogroll.source_hub_url (added in migration 052) so re-syncs only
    // affect hub-pushed rows — the spoke's own locally-added peers stay put.
    // Graceful fallback: if migration 052 hasn't run, wipe all and re-import.
    $has_source_col = false;
    try {
        $pdo->prepare("DELETE FROM snap_blogroll WHERE source_hub_url = ?")
            ->execute([$hub_url]);
        $has_source_col = true;
    } catch (PDOException $e) {
        $pdo->exec("DELETE FROM snap_blogroll");
    }

    // Lookup existing categories on the spoke once, keyed by lowercase name,
    // so we can match the hub's category structure case-insensitively.
    $cats_existing = [];
    foreach ($pdo->query("SELECT id, cat_name FROM snap_blogroll_cats")->fetchAll(PDO::FETCH_ASSOC) as $_c) {
        $cats_existing[strtolower($_c['cat_name'])] = (int)$_c['id'];
    }
    $cat_create = $pdo->prepare("INSERT INTO snap_blogroll_cats (cat_name) VALUES (?)");

    // Resolve a category name to a cat_id, creating a new category on the
    // spoke when needed. Empty/missing category -> 0 (uncategorized).
    $resolve_cat = function (string $cat_name) use ($pdo, &$cats_existing, $cat_create): int {
        $cat_name = trim($cat_name);
        if ($cat_name === '') return 0;
        $key = strtolower($cat_name);
        if (isset($cats_existing[$key])) return $cats_existing[$key];
        $cat_create->execute([$cat_name]);
        $new_id = (int)$pdo->lastInsertId();
        $cats_existing[$key] = $new_id;
        return $new_id;
    };

    // Insert fresh entries — each carries its own category from the hub.
    $inserted = 0;
    if ($has_source_col) {
        $insert = $pdo->prepare("
            INSERT INTO snap_blogroll (peer_name, peer_url, peer_rss, peer_desc, cat_id, source_hub_url)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
    } else {
        $insert = $pdo->prepare("
            INSERT INTO snap_blogroll (peer_name, peer_url, peer_rss, peer_desc, cat_id)
            VALUES (?, ?, ?, ?, ?)
        ");
    }
    foreach ($entries as $e) {
        $url = trim($e['peer_url'] ?? '');
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) continue;
        $entry_cat_id = $resolve_cat((string)($e['category'] ?? ''));
        $row = [
            substr(trim($e['peer_name'] ?? ''), 0, 255) ?: parse_url($url, PHP_URL_HOST),
            $url,
            substr(trim($e['peer_rss'] ?? ''), 0, 500),
            substr(trim($e['peer_desc'] ?? ''), 0, 500),
            $entry_cat_id,
        ];
        if ($has_source_col) $row[] = $hub_url;
        $insert->execute($row);
        $inserted++;
    }

    ms_ok(['inserted' => $inserted, 'hub_url' => $hub_url]);
}

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: POST multisite/disconnect
// Hub is revoking its own access. Wipe the node record.
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'disconnect' && $method === 'POST') {
    $pdo->prepare("DELETE FROM snap_multisite_nodes WHERE id = ?")->execute([$node_id]);
    ms_ok(['message' => 'Disconnected']);
}

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: POST multisite/ban-sync
// Called BY the hub ON this spoke. Part of SnapSmack Shield Tier 1.
//
// Request body (JSON):
//   consolidated_bans — array of { ban_type, ban_value, reason } from the hub's
//                        shared registry; spoke merges these into snap_ban_list.
//
// Response:
//   new_bans — array of bans created on this spoke since ban_hub_last_sync_at.
//   merged   — count of bans merged from hub into local snap_ban_list.
//
// Idempotent: INSERT IGNORE + banned_at cursor mean repeated calls are safe.
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'ban-sync' && $method === 'POST') {

    // Only the hub may initiate a ban sync
    if ($node['role'] !== 'hub') ms_err('Only a hub can initiate ban-sync', 403);

    // Respect the opt-in toggle; return empty (not error) if disabled so the
    // hub doesn't mark the spoke as broken
    if (empty($settings['hub_spoke_ban_sync'])) {
        ms_ok(['new_bans' => [], 'merged' => 0, 'status' => 'disabled']);
    }

    $body         = json_decode(file_get_contents('php://input'), true) ?: [];
    $consolidated = $body['consolidated_bans'] ?? [];
    $valid_types  = ['fingerprint', 'ip', 'email_hash'];

    // ── Merge hub's consolidated bans into local snap_ban_list ───────────────
    // Hub-sourced bans get a 'hub-sync:' reason prefix so the spoke never
    // echoes them back as its own "new" bans on the next sync cycle.

    $merged      = 0;
    $bans_to_store = [];
    $insert_stmt = $pdo->prepare("
        INSERT IGNORE INTO `snap_ban_list` (ban_type, ban_value, reason)
        VALUES (?, ?, ?)
    ");

    foreach ($consolidated as $ban) {
        $type        = $ban['ban_type']  ?? '';
        $value       = $ban['ban_value'] ?? '';
        $raw_reason  = $ban['reason']    ?? '';
        if (!in_array($type, $valid_types, true)) continue;
        // Value must be a SHA-256 hex string — 64 lowercase hex chars
        if (!preg_match('/^[0-9a-f]{64}$/i', $value)) continue;
        $reason = 'hub-sync:' . substr(preg_replace('/[^a-zA-Z0-9_\- ]/', '', $raw_reason), 0, 64);
        $bans_to_store[] = ['type' => $type, 'value' => $value, 'reason' => $reason];
    }

    foreach ($bans_to_store as $b) {
        $insert_stmt->execute([$b['type'], $b['value'], $b['reason']]);
        $merged++;
    }

    ms_ok(['imported' => $merged]);
}

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: POST multisite/updates/trigger
// Hub instructs this spoke to download and apply the latest release.
// Hub role required. Returns {ok, version, files_updated, migrations, errors[]}.
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'updates' && $sub_action === 'trigger' && $method === 'POST') {
    if ($node['role'] !== 'hub') ms_err('Only a hub may trigger spoke updates', 403);

    require_once __DIR__ . '/updater.php';

    @set_time_limit(120);

    $installed_version  = $settings['installed_version']  ?? SNAPSMACK_VERSION_SHORT;
    $installed_checksum = $settings['installed_checksum'] ?? '';

    // 1. Fetch release info from Smack Central
    $release_info = updater_fetch_release_info();
    if (!$release_info || isset($release_info['error'])) {
        ms_err('Could not fetch release info: ' . ($release_info['error'] ?? 'unknown'), 500);
    }

    // 2. Check whether an update is actually needed
    $status = updater_check_status($installed_version, $release_info, $installed_checksum);
    if ($status === 'up_to_date') {
        ms_ok(['status' => 'already_current', 'version' => $installed_version]);
    }

    // 3. Download
    $dl_error = '';
    $zip_path = updater_download($release_info['download_url'] ?? '', $dl_error);
    if (!$zip_path) ms_err('Download failed: ' . $dl_error, 500);

    // 4. Verify checksum + signature
    $verify_error = '';
    if (!updater_verify_package($zip_path, $release_info['checksum_sha256'] ?? '', $release_info['signature'] ?? '', $verify_error)) {
        @unlink($zip_path);
        ms_err('Verification failed: ' . $verify_error, 500);
    }

    // 5. Acquire maintenance lock
    updater_acquire_lock($pdo);

    // 6. Extract
    $extract = updater_extract($zip_path);
    @unlink($zip_path);

    if (!$extract['success']) {
        updater_release_lock();
        ms_err('Extraction failed: ' . implode('; ', $extract['errors']), 500);
    }

    // 7. Run migrations
    $migration_files = updater_find_migrations($pdo);
    $mig_result = [];
    if (!empty($migration_files)) {
        $mig_result = updater_run_migrations($pdo, $migration_files);
    }

    // 8. Set version + store checksum
    updater_set_version(
        $pdo,
        $release_info['version']         ?? $installed_version,
        $release_info['version_full']    ?? '',
        $release_info['codename']        ?? '',
        $release_info['checksum_sha256'] ?? ''
    );

    // 9. Release lock
    updater_release_lock();
    updater_cleanup();

    // 10. Phone home — now running with newly-extracted code, so ping fires even on
    //     the first update that introduces _updater_ping_home (bootstraps the counter).
    try {
        $new_version = preg_replace('/^[^0-9]+/', '', $release_info['version'] ?? $installed_version);
        $new_track   = updater_get_track($pdo);
        _updater_ping_home($pdo, $new_version, $new_track);
    } catch (Throwable $e) {}

    $mig_count = count(array_filter($mig_result, fn($r) => ($r['status'] ?? '') === 'ok'));

    ms_ok([
        'status'        => 'updated',
        'from_version'  => $installed_version,
        'to_version'    => preg_replace('/^[^0-9]+/', '', $release_info['version'] ?? ''),
        'files_updated' => $extract['files_updated'],
        'files_skipped' => $extract['files_skipped'],
        'migrations'    => $mig_count,
        'errors'        => $extract['errors'],
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: POST multisite/maintenance/set
// Hub instructs this spoke to toggle its maintenance mode on or off.
// Hub role required. Body: {"mode": 1} or {"mode": 0}.
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'maintenance' && $sub_action === 'set' && $method === 'POST') {
    if ($node['role'] !== 'hub') ms_err('Only a hub may set maintenance mode', 403);
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!isset($body['mode'])) ms_err('mode parameter required', 400);
    $mode = $body['mode'] ? '1' : '0';
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('maintenance_mode', ?)
                   ON DUPLICATE KEY UPDATE setting_val = ?")
        ->execute([$mode, $mode]);
    ms_ok(['maintenance_mode' => (int)$mode]);
}

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: POST multisite/smackback/report
// A spoke in paranoid mode calls this when it detects a breach.
// Hub records the breach on the spoke's node record and runs correlation:
// if ≥ 2 spokes are simultaneously in breach, a coordinated-attack alert fires.
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'smackback' && $sub_action === 'report' && $method === 'POST') {
    if ($node['role'] !== 'spoke') ms_err('Only spokes may report a breach', 403);

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) ms_err('Invalid JSON body', 400);

    $tampered    = array_values(array_filter((array)($body['tampered']  ?? []), 'is_string'));
    $missing     = array_values(array_filter((array)($body['missing']   ?? []), 'is_string'));
    $truncated   = array_values(array_filter((array)($body['truncated'] ?? []), 'is_string'));
    $corrupted   = array_values(array_filter((array)($body['corrupted'] ?? []), 'is_string'));
    $detected_at = date('Y-m-d H:i:s', strtotime($body['detected_at'] ?? 'now'));

    $all_files = array_merge(
        array_map(fn($p) => ['path' => $p, 'status' => 'tampered'],  $tampered),
        array_map(fn($p) => ['path' => $p, 'status' => 'missing'],   $missing),
        array_map(fn($p) => ['path' => $p, 'status' => 'truncated'], $truncated),
        array_map(fn($p) => ['path' => $p, 'status' => 'corrupted'], $corrupted)
    );

    // Store breach on this spoke's node record
    try {
        $pdo->prepare("
            UPDATE snap_multisite_nodes
            SET smackback_status      = 'breach',
                smackback_breach_at   = ?,
                smackback_breach_files = ?
            WHERE id = ?
        ")->execute([$detected_at, json_encode($all_files), $node['id']]);
    } catch (PDOException $e) {
        // Columns may not exist yet on older hubs — degrade gracefully
        error_log('SMACKBACK hub report: schema not ready — ' . $e->getMessage());
        ms_ok(['received' => true, 'correlated' => false, 'note' => 'hub schema pending migration']);
    }

    // Correlation: count spokes currently in breach
    $breach_count = (int)$pdo->query(
        "SELECT COUNT(*) FROM snap_multisite_nodes WHERE role = 'spoke' AND smackback_status = 'breach'"
    )->fetchColumn();

    // Fire coordinated-attack alert if ≥ 2 spokes are simultaneously in breach
    if ($breach_count >= 2) {
        $coord_settings = $pdo->query(
            "SELECT setting_key, setting_val FROM snap_settings
             WHERE setting_key IN ('admin_email', 'site_name', 'site_url')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $to        = $coord_settings['admin_email'] ?? '';
        $site_name = $coord_settings['site_name']  ?? 'SnapSmack Hub';
        $site_url  = $coord_settings['site_url']   ?? '';

        if ($to) {
            $subject = "[{$site_name}] SMACKBACK — COORDINATED BREACH DETECTED ({$breach_count} spokes)";
            $body_txt = "SMACKBACK has detected a simultaneous breach on {$breach_count} spoke(s) in your network.\n\n"
                      . "This may indicate a coordinated attack or a shared infrastructure compromise.\n\n"
                      . "Log into your hub now to review the SMACKBACK panel on each spoke:\n"
                      . rtrim($site_url, '/') . "/smack-multisite.php\n\n"
                      . "--- Automated alert from SMACKBACK at " . date('Y-m-d H:i:s') . " ---\n";
            @mail($to, $subject, $body_txt,
                "From: SMACKBACK <noreply@" . (parse_url($site_url, PHP_URL_HOST) ?: 'localhost') . ">\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "X-Mailer: SnapSmack SMACKBACK"
            );
        }
    }

    ms_ok(['received' => true, 'breach_count' => $breach_count]);
}

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: POST multisite/skins/reinstall
// Hub instructs this spoke to download and (re)install a skin from the registry.
// Hub role required. Body: {"skin_slug":"chaplin","download_url":"https://...","signature":"..."}
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'skins' && $sub_action === 'reinstall' && $method === 'POST') {
    if ($node['role'] !== 'hub') ms_err('Only a hub may install skins on this spoke', 403);

    $body         = json_decode(file_get_contents('php://input'), true) ?? [];
    $slug         = trim($body['skin_slug']    ?? '');
    $download_url = trim($body['download_url'] ?? '');
    $signature    = trim($body['signature']    ?? '');

    if (!$slug || !$download_url)                         ms_err('skin_slug and download_url required');
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug))        ms_err('Invalid skin slug');
    if (!filter_var($download_url, FILTER_VALIDATE_URL))  ms_err('Invalid download_url');

    require_once __DIR__ . '/skin-registry.php';

    $public_key = $settings['update_public_key'] ?? '';
    $result     = skin_registry_install($slug, $download_url, $signature, $public_key);

    if ($result['success']) ms_ok(['skin' => $slug, 'message' => $result['message']]);
    ms_err($result['message']);
}

// ─────────────────────────────────────────────────────────────────────────────
// ENDPOINT: POST multisite/settings/push
// Hub pushes a set of site settings to this spoke. Only keys on the explicit
// allowlist below are accepted — the hub cannot write arbitrary settings.
// ─────────────────────────────────────────────────────────────────────────────
if ($resource === 'settings' && $sub_action === 'push' && $method === 'POST') {
    if ($node['role'] !== 'hub') ms_err('Only a hub can push settings', 403);

    // Explicit allowlist — keep this narrow.
    $allowed_keys = [
        'timezone', 'date_format',
        'akismet_key',
        'ai_training_policy',
        'smackback_enabled', 'smackback_mode',
        'global_comments_enabled',
        'site_email',
        'download_link_required', 'download_default_mode',
    ];

    $pairs_raw = trim($_POST['settings'] ?? '');
    $pairs     = json_decode($pairs_raw, true);
    if (!is_array($pairs) || empty($pairs)) ms_err('settings must be a non-empty JSON object');

    $applied = [];
    $skipped = [];
    $upsert  = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)");

    foreach ($pairs as $key => $val) {
        $key = (string)$key;
        if (!in_array($key, $allowed_keys, true)) {
            $skipped[] = $key;
            continue;
        }
        $upsert->execute([$key, (string)$val]);
        $applied[] = $key;
    }

    ms_ok(['applied' => $applied, 'skipped' => $skipped]);
}

// Fell through -- unknown endpoint
ms_err('Unknown multisite endpoint', 404);
// ===== SNAPSMACK EOF =====
