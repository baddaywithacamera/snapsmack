<?php
/**
 * SNAPSMACK - Comment submission handler.
 * Processes incoming POST requests for new comments, verifies global and 
 * post-level permissions, applies spam filtering, and logs to the database.
 * Git Version Official Alpha 0.5
 */

// Enable error reporting during the alpha phase for debugging.
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Establish absolute paths to ensure reliable inclusion of core dependencies regardless of the calling context.
$db_path = __DIR__ . '/core/db.php';
$spam_path = __DIR__ . '/core/spam-check.php';

if (!file_exists($db_path)) {
    die("FATAL ERROR: System cannot find DB connection at: " . $db_path);
}

require_once $db_path;

if (file_exists($spam_path)) {
    require_once $spam_path;
}

// Ensure this script is only accessed via form submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $img_id = (int)($_POST['img_id'] ?? 0);
    $author = trim($_POST['author'] ?? 'Anonymous');
    $email  = trim($_POST['email'] ?? '');
    $text   = trim($_POST['comment_text'] ?? '');
    $ip     = $_SERVER['REMOTE_ADDR'];

    if ($img_id === 0 || empty($text)) {
        die("VALIDATION ERROR: Image ID or Message is missing.");
    }

    try {
        // Check the global configuration to verify if comments are enabled sitewide.
        $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        $global_on = (($settings['global_comments_enabled'] ?? '1') == '1');

        // Check the specific image record to verify if comments are enabled for this individual post.
        $lockStmt = $pdo->prepare("SELECT allow_comments, img_slug FROM snap_images WHERE id = ?");
        $lockStmt->execute([$img_id]);
        $img_data = $lockStmt->fetch(PDO::FETCH_ASSOC);
        
        $post_on = (($img_data['allow_comments'] ?? '1') == '1');
        $slug = $img_data['img_slug'] ?? '';

        // Reject the submission if either global or post-level comments are disabled.
        if (!$global_on || !$post_on) {
            header("HTTP/1.1 403 Forbidden");
            die("SIGNAL REJECTED: Comments are disabled for this frequency.");
        }
        
        // Evaluate the submission against the Akismet API if the spam filter module is loaded.
        if (function_exists('is_spam')) {
            if (is_spam($author, $email, $text, $pdo)) {
                // Currently configured to allow flagged spam to be stored for manual review rather than hard-blocking.
                // die("SIGNAL REJECTED: Akismet flagged this as spam.");
            }
        }

        // Insert the comment into the database. The 'is_approved' column defaults to 0, requiring administrative moderation.
        $stmt = $pdo->prepare("INSERT INTO snap_comments (img_id, comment_author, comment_email, comment_text, comment_ip) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$img_id, $author, $email, $text, $ip]);

        // Redirect the user back to the originating post with a success parameter.
        $target = "/index.php" . ($slug ? "/" . $slug : "");
        header("Location: " . $target . "?status=received");
        exit;

    } catch (PDOException $e) {
        die("DATABASE ERROR: " . $e->getMessage());
    }
} else {
    die("FORBIDDEN: Direct access to this frequency is not permitted.");
}