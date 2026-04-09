<?php
/**
 * SNAPSMACK - Multisite API Handler
 * Alpha v0.7.8
 *
 * Handles inbound API requests from hub and satellite sites.
 * Supports handshake, heartbeat, comment management, post retrieval, and stats.
 * Authentication via API key in Authorization header or registration token.
 */

// --- ENVIRONMENT BOOTSTRAP ---
if (!defined('BASE_URL')) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    define('BASE_URL', $protocol . "://" . $host . "/");
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/constants.php';

// --- RESPONSE HELPERS ---
function api_response($status, $data = null) {
    header('Content-Type: application/json');
    $response = ['status' => $status];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function api_error($message) {
    header('HTTP/1.1 400 Bad Request');
    api_response('error', ['message' => $message]);
}

function api_unauthorized() {
    header('HTTP/1.1 401 Unauthorized');
    api_response('error', ['message' => 'Unauthorized']);
}

function api_not_found() {
    header('HTTP/1.1 404 Not Found');
    api_response('error', ['message' => 'Not found']);
}

// --- ROUTE EXTRACTION ---
// Parse the route from either a query parameter or PATH_INFO
$route = '';
if (isset($_GET['route'])) {
    $route = trim($_GET['route'], '/');
} elseif (!empty($_SERVER['PATH_INFO'])) {
    $route = trim($_SERVER['PATH_INFO'], '/');
}

// Remove 'api/' prefix if present
if (strpos($route, 'api/') === 0) {
    $route = substr($route, 4);
}

// Extract API version and endpoint
$parts = explode('/', $route);
$endpoint = $parts[0] ?? '';
$action = $parts[1] ?? '';

// --- HTTPS REQUIREMENT CHECK ---
// Multisite communication must use HTTPS
$is_https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';

// --- AUTHENTICATION ---
// Extract API key from Authorization header
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$api_key = '';
$reg_token = '';

if (preg_match('/^Bearer\s+(\S+)$/i', $auth_header, $matches)) {
    $api_key = $matches[1];
} elseif (isset($_POST['token'])) {
    $reg_token = $_POST['token'];
}

// Get current site settings
try {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    api_error("Database unavailable");
}

$current_role = $settings['multisite_role'] ?? '';
$current_version = $settings['snapsmack_version'] ?? 'Alpha v0.7.8';

// --- ENDPOINT: POST /api/multisite/handshake ---
// Complete registration: exchange registration token for API keys
if ($endpoint === 'multisite' && $action === 'handshake' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$is_https) {
        api_error("HTTPS required for handshake");
    }

    $remote_url = $_POST['site_url'] ?? '';
    $remote_name = $_POST['site_name'] ?? '';
    $token = $_POST['token'] ?? '';

    if (empty($remote_url) || empty($token)) {
        api_error("Missing site_url or token");
    }

    // Validate registration token
    $reg_token_valid = $settings['multisite_reg_token'] ?? '';
    $reg_token_expires = isset($settings['multisite_reg_token_expires']) ? (int)$settings['multisite_reg_token_expires'] : 0;

    if ($token !== $reg_token_valid || time() > $reg_token_expires) {
        api_unauthorized();
    }

    // Generate API keys (64-char hex)
    $api_key_local = bin2hex(random_bytes(32));   // Hub uses this to call us
    $api_key_remote = bin2hex(random_bytes(32));  // We use this to call the hub

    // Determine node role based on current site's role
    $node_role = $current_role === 'hub' ? 'satellite' : 'hub';

    // Insert or update the node
    $stmt = $pdo->prepare("
        INSERT INTO snap_multisite_nodes
        (role, site_url, site_name, api_key_local, api_key_remote, status, connected_at)
        VALUES (?, ?, ?, ?, ?, 'active', NOW())
        ON DUPLICATE KEY UPDATE
            api_key_local = VALUES(api_key_local),
            api_key_remote = VALUES(api_key_remote),
            status = 'active',
            site_name = VALUES(site_name)
    ");

    try {
        $stmt->execute([$node_role, $remote_url, $remote_name, $api_key_local, $api_key_remote]);

        // Clear the registration token
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?")
            ->execute(['multisite_reg_token', '', '']);
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?")
            ->execute(['multisite_reg_token_expires', '0', '0']);

        api_response('success', [
            'api_key' => $api_key_remote,
            'site_url' => BASE_URL,
            'site_name' => $settings['site_name'] ?? 'SnapSmack',
            'version' => $current_version
        ]);
    } catch (PDOException $e) {
        api_error("Registration failed: " . $e->getMessage());
    }
}

// --- AUTHENTICATION CHECK FOR PROTECTED ENDPOINTS ---
// All remaining endpoints require valid API key
if (empty($api_key)) {
    api_unauthorized();
}

// Validate API key against stored nodes
$stmt = $pdo->prepare("
    SELECT id, site_url, site_name, role FROM snap_multisite_nodes
    WHERE api_key_local = ? AND status = 'active'
    LIMIT 1
");
$stmt->execute([$api_key]);
$node = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$node) {
    api_unauthorized();
}

$node_id = $node['id'];
$node_url = $node['site_url'];
$node_name = $node['site_name'];
$node_role = $node['role'];

// Update last_seen_at for rate limiting
$pdo->prepare("UPDATE snap_multisite_nodes SET last_seen_at = NOW() WHERE id = ?")->execute([$node_id]);

// --- ENDPOINT: GET /api/multisite/heartbeat ---
// Returns version, post count, pending comments, backup status
if ($endpoint === 'multisite' && $action === 'heartbeat' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // Count posts
    $post_count = (int)$pdo->query("SELECT COUNT(*) FROM snap_posts WHERE is_active = 1")->fetchColumn();

    // Count images
    $image_count = (int)$pdo->query("SELECT COUNT(*) FROM snap_images WHERE is_active = 1")->fetchColumn();

    // Count pending comments
    $pending_comments = (int)$pdo->query("SELECT COUNT(*) FROM snap_comments WHERE is_approved = 0")->fetchColumn();

    // Get last backup info
    $backup_stmt = $pdo->query("
        SELECT last_backup_at, last_backup_size, last_backup_dest, last_backup_status
        FROM snap_multisite_nodes WHERE id = ?
    ");
    $backup_stmt->execute([$node_id]);
    $backup_info = $backup_stmt->fetch(PDO::FETCH_ASSOC);

    // Get disk usage
    $upload_dir = __DIR__ . '/../uploads/';
    $disk_usage = 0;
    if (is_dir($upload_dir)) {
        $disk_usage = array_sum(array_map('filesize', glob($upload_dir . '*', GLOB_RECURSE)));
    }

    api_response('success', [
        'version' => $current_version,
        'post_count' => $post_count,
        'image_count' => $image_count,
        'pending_comments' => $pending_comments,
        'last_backup_at' => $backup_info['last_backup_at'],
        'last_backup_size' => $backup_info['last_backup_size'],
        'last_backup_dest' => $backup_info['last_backup_dest'],
        'last_backup_status' => $backup_info['last_backup_status'] ?? 'unknown',
        'disk_usage_bytes' => $disk_usage,
        'timestamp' => date('c')
    ]);
}

// --- ENDPOINT: GET /api/multisite/comments/pending ---
// Returns unapproved comments for moderation
if ($endpoint === 'multisite' && $action === 'pending' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query("
        SELECT
            c.id, c.post_id, c.commenter_name, c.commenter_email,
            c.comment_text, c.created_at, c.spam_score,
            p.title as post_title
        FROM snap_comments c
        LEFT JOIN snap_posts p ON c.post_id = p.id
        WHERE c.is_approved = 0
        ORDER BY c.created_at DESC
        LIMIT 50
    ");
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    api_response('success', ['comments' => $comments]);
}

// --- ENDPOINT: POST /api/multisite/comments/action ---
// Approve, reject, or spam a comment
if ($endpoint === 'multisite' && $action === 'action' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $comment_id = (int)($_POST['comment_id'] ?? 0);
    $action_type = $_POST['action'] ?? ''; // 'approve', 'reject', 'spam'

    if (!$comment_id || !in_array($action_type, ['approve', 'reject', 'spam'])) {
        api_error("Invalid comment_id or action");
    }

    // Get comment
    $stmt = $pdo->prepare("SELECT id FROM snap_comments WHERE id = ?");
    $stmt->execute([$comment_id]);
    $comment = $stmt->fetch();

    if (!$comment) {
        api_not_found();
    }

    if ($action_type === 'approve') {
        $pdo->prepare("UPDATE snap_comments SET is_approved = 1 WHERE id = ?")->execute([$comment_id]);
    } elseif ($action_type === 'reject') {
        $pdo->prepare("UPDATE snap_comments SET is_approved = 0, is_spam = 0 WHERE id = ?")->execute([$comment_id]);
    } elseif ($action_type === 'spam') {
        $pdo->prepare("UPDATE snap_comments SET is_spam = 1, is_approved = 0 WHERE id = ?")->execute([$comment_id]);
    }

    api_response('success', ['comment_id' => $comment_id, 'action' => $action_type]);
}

// --- ENDPOINT: GET /api/multisite/posts/recent ---
// Recent posts with thumbnails
if ($endpoint === 'multisite' && $action === 'recent' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit = min((int)($_GET['limit'] ?? 10), 50);

    $stmt = $pdo->query("
        SELECT
            p.id, p.title, p.slug, p.posted_at, p.post_text,
            (SELECT img_path FROM snap_images WHERE post_id = p.id LIMIT 1) as thumbnail
        FROM snap_posts p
        WHERE p.is_active = 1
        ORDER BY p.posted_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    api_response('success', ['posts' => $posts]);
}

// --- ENDPOINT: GET /api/multisite/stats/daily ---
// Daily stats summary
if ($endpoint === 'multisite' && $action === 'daily' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $days = min((int)($_GET['days'] ?? 30), 365);

    $stmt = $pdo->prepare("
        SELECT
            DATE(posted_at) as day,
            COUNT(*) as post_count
        FROM snap_posts
        WHERE posted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY DATE(posted_at)
        ORDER BY day DESC
    ");
    $stmt->execute([$days]);
    $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    api_response('success', ['stats' => $stats, 'period_days' => $days]);
}

// --- ENDPOINT: GET /api/multisite/updates/status ---
// Current version and update availability
if ($endpoint === 'multisite' && $action === 'status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // In a real implementation, this would check against a release server
    $update_available = false;
    $latest_version = $current_version;

    api_response('success', [
        'current_version' => $current_version,
        'latest_version' => $latest_version,
        'update_available' => $update_available
    ]);
}

// --- ENDPOINT: GET /api/multisite/backup/status ---
// Current backup state
if ($endpoint === 'multisite' && $action === 'status' && $parts[2] === 'backup' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("
        SELECT last_backup_at, last_backup_size, last_backup_dest, last_backup_status
        FROM snap_multisite_nodes WHERE id = ?
    ");
    $stmt->execute([$node_id]);
    $backup = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$backup) {
        api_error("Backup data not found");
    }

    api_response('success', $backup);
}

// --- ENDPOINT: POST /api/multisite/disconnect ---
// Revoke hub access
if ($endpoint === 'multisite' && $action === 'disconnect' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->prepare("UPDATE snap_multisite_nodes SET status = 'disconnected' WHERE id = ?")->execute([$node_id]);
    api_response('success', ['message' => 'Disconnected']);
}

// --- FALLBACK: Route not found ---
api_not_found();
?>
