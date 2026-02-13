<?php
/**
 * SnapSmack - Public Transmission Listener
 * Version: 2.6 - Double-Lock Security Build
 */

// Keep error reporting active for now while we dial this in
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Step 1: Use absolute paths to find the core files
$db_path = __DIR__ . '/core/db.php';
$spam_path = __DIR__ . '/core/spam-check.php';

if (!file_exists($db_path)) {
    die("FATAL ERROR: System cannot find DB connection at: " . $db_path);
}

require_once $db_path;

if (file_exists($spam_path)) {
    require_once $spam_path;
}

// Step 2: Handle the POST request
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
        // --- START DOUBLE-LOCK SECURITY CHECK ---
        
        // A. Fetch Global Setting
        $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        $global_on = (($settings['global_comments_enabled'] ?? '1') == '1');

        // B. Fetch Post-Specific Setting
        $lockStmt = $pdo->prepare("SELECT allow_comments, img_slug FROM snap_images WHERE id = ?");
        $lockStmt->execute([$img_id]);
        $img_data = $lockStmt->fetch(PDO::FETCH_ASSOC);
        
        $post_on = (($img_data['allow_comments'] ?? '1') == '1');
        $slug = $img_data['img_slug'] ?? '';

        // C. The Interceptor
        if (!$global_on || !$post_on) {
            header("HTTP/1.1 403 Forbidden");
            die("SIGNAL REJECTED: Comments are disabled for this frequency.");
        }
        
        // --- END DOUBLE-LOCK SECURITY CHECK ---

        // 3. Akismet Spam Check 
        if (function_exists('is_spam')) {
            if (is_spam($author, $email, $text, $pdo)) {
                // Currently set to silent flag; uncomment die to block.
                // die("SIGNAL REJECTED: Akismet flagged this as spam.");
            }
        }

        // 4. Log Transmission to Database (is_approved defaults to 0)
        $stmt = $pdo->prepare("INSERT INTO snap_comments (img_id, comment_author, comment_email, comment_text, comment_ip) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$img_id, $author, $email, $text, $ip]);

        // 6. Return home
        $target = "/index.php" . ($slug ? "/" . $slug : "");
        header("Location: " . $target . "?status=received");
        exit;

    } catch (PDOException $e) {
        die("DATABASE ERROR: " . $e->getMessage());
    }
} else {
    die("FORBIDDEN: Direct access to this frequency is not permitted.");
}