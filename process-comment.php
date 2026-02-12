<?php
/**
 * SnapSmack - Public Transmission Listener
 * Version: 2.5 - Debugged & Test-Safe
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
        // 3. Akismet Spam Check 
        // We run it, but we won't KILL the script if it flags your gibberish.
        if (function_exists('is_spam')) {
            if (is_spam($author, $email, $text, $pdo)) {
                // To re-enable strict blocking later, uncomment the die() line below.
                // die("SIGNAL REJECTED: Akismet flagged this as spam.");
                
                // For now, we just let it through to your 'Incoming' queue.
            }
        }

        // 4. Log Transmission to Database (is_approved defaults to 0)
        $stmt = $pdo->prepare("INSERT INTO snap_comments (img_id, comment_author, comment_email, comment_text, comment_ip) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$img_id, $author, $email, $text, $ip]);

        // 5. Get Slug for the Redirect
        $slugStmt = $pdo->prepare("SELECT img_slug FROM snap_images WHERE id = ?");
        $slugStmt->execute([$img_id]);
        $slug = $slugStmt->fetchColumn();

        // 6. Return home
        // We use the slug in the URL structure your frontend expects
        $target = "/index.php" . ($slug ? "/" . $slug : "");
        header("Location: " . $target . "?status=received");
        exit;

    } catch (PDOException $e) {
        die("DATABASE ERROR: " . $e->getMessage());
    }
} else {
    die("FORBIDDEN: Direct access to this frequency is not permitted.");
}