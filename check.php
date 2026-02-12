<?php
// 1. Force error reporting ON
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h3>SnapSmack Diagnostic</h3>";

// 2. Check for auth.php
echo "Checking for auth.php... ";
if (file_exists('core/auth.php')) {
    echo "<span style='color:green;'>FOUND</span><br>";
    require_once 'core/auth.php';
    echo "Auth loaded successfully.<br>";
} else {
    die("<span style='color:red;'>MISSING</span> - Check your core folder path.");
}

// 3. Check for Database variable
echo "Checking Database connection... ";
if (isset($pdo)) {
    echo "<span style='color:green;'>CONNECTED</span><br>";
} else {
    echo "<span style='color:red;'>FAILED</span> - \$pdo variable not found. Check core/db.php.<br>";
}

echo "<br><b>If no error appeared above, the logic is sound. Try visiting <a href='smack-post.php'>smack-post.php</a> now.</b>";
?>