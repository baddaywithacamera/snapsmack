<?php
/**
 * SnapSmack Core Admin Header
 * Version: 1.5 - Secure Role Recovery
 */

// 1. Ensure settings are bootstrapped
if (!isset($settings)) {
    $settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * 2. ROLE RECOVERY LOGIC
 * If the session is missing the role, we re-verify against the database.
 * We use 'sean' as the master fallback if the session login is lost.
 */
if (!isset($_SESSION['user_role']) || empty($_SESSION['user_role'])) {
    $login_name = $_SESSION['user_login'] ?? 'sean'; // Fallback to your master username
    
    try {
        $user_stmt = $pdo->prepare("SELECT user_role FROM snap_users WHERE username = ?");
        $user_stmt->execute([$login_name]);
        $role = $user_stmt->fetchColumn();
        
        // Final fallback: if the DB has no role column yet, assume admin for 'sean'
        if (!$role && $login_name === 'sean') {
            $_SESSION['user_role'] = 'admin';
        } else {
            $_SESSION['user_role'] = $role ?: 'editor';
        }
    } catch (PDOException $e) {
        // If the query fails (column missing), 'sean' stays Admin so you can fix it.
        $_SESSION['user_role'] = ($login_name === 'sean') ? 'admin' : 'editor';
    }
}

$user_role = $_SESSION['user_role'];

// 3. Security Gate
$admin_only = ['smack-config.php', 'smack-skin.php', 'smack-users.php', 'smack-backup.php', 'smack-maintenance.php', 'smack-css.php'];
$current_file = basename($_SERVER['PHP_SELF']);

if ($user_role !== 'admin' && in_array($current_file, $admin_only)) {
    header("Location: smack-admin.php?err=unauthorized");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> | SnapSmack Admin</title>
    <link rel="stylesheet" href="assets/css/admin-theme.css">
    <link rel="stylesheet" href="assets/css/hotkey-engine.css">
</head>
<body class="admin-body">