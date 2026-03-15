<?php
/**
 * SNAPSMACK - Comment submission handler
 * Alpha v0.7.4
 *
 * Processes incoming comment submissions, verifies global and per-image permissions,
 * applies optional spam filtering, and stores comments for moderation.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- DEPENDENCY LOADING ---
// Establish absolute paths to ensure reliable inclusion regardless of calling context
$db_path = __DIR__ . '/core/db.php';
$spam_path = __DIR__ . '/core/spam-check.php';

if (!file_exists($db_path)) {
    die("FATAL ERROR: System cannot find DB connection at: " . $db_path);
}

require_once $db_path;

if (file_exists($spam_path)) {
    require_once $spam_path;
}

// --- REQUEST HANDLER ---
// Only process POST submissions. Block direct access.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- INPUT PARSING ---
    $img_id = (int)($_POST['img_id'] ?? 0);
    $author = trim($_POST['author'] ?? 'Anonymous');
    $email  = trim($_POST['email'] ?? '');
    $text   = trim($_POST['comment_text'] ?? '');
    $ip     = $_SERVER['REMOTE_ADDR'];

    // Validation: Image ID and comment text are required
    if ($img_id === 0 || empty($text)) {
        die("VALIDATION ERROR: Image ID or Message is missing.");
    }

    try {
        // --- PERMISSION CHECKS ---
        // Load global setting to check if comments are enabled sitewide
        $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        $global_on = (($settings['global_comments_enabled'] ?? '1') == '1');

        // Check the specific image record to verify per-post comment permission
        $lockStmt = $pdo->prepare("SELECT allow_comments, img_slug FROM snap_images WHERE id = ?");
        $lockStmt->execute([$img_id]);
        $img_data = $lockStmt->fetch(PDO::FETCH_ASSOC);

        $post_on = (($img_data['allow_comments'] ?? '1') == '1');
        $slug = $img_data['img_slug'] ?? '';

        // Reject submission if either global or post-level comments are disabled
        if (!$global_on || !$post_on) {
            header("HTTP/1.1 403 Forbidden");
            die("SIGNAL REJECTED: Comments are disabled for this frequency.");
        }

        // --- SPAM FILTERING ---
        // Optional Akismet check. If flagged, currently allows storage for manual review.
        if (function_exists('is_spam')) {
            if (is_spam($author, $email, $text, $pdo)) {
                // Spam flagged but not hard-blocked. Stored for moderation.
            }
        }

        // --- DATABASE INSERTION ---
        // Store comment with is_approved = 0 for moderation queue
        $stmt = $pdo->prepare("INSERT INTO snap_comments (img_id, comment_author, comment_email, comment_text, comment_ip) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$img_id, $author, $email, $text, $ip]);

        // --- REDIRECT ---
        // Send user back to the post with success status
        $target = "/index.php" . ($slug ? "/" . $slug : "");
        header("Location: " . $target . "?status=received");
        exit;

    } catch (PDOException $e) {
        die("DATABASE ERROR: " . $e->getMessage());
    }
} else {
    die("FORBIDDEN: Direct access to this frequency is not permitted.");
}
