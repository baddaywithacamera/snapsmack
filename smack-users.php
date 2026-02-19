<?php
/**
 * SnapSmack - User Manager
 * Version: 6.0 - V6 Core Standardized
 * MASTER DIRECTIVE: Full file return. Standardized layout.
 */
require_once 'core/auth.php';

// --- 1. ACTION: ADD NEW USER ---
if (isset($_POST['add_user'])) {
    $new_user = trim($_POST['username']);
    $new_email = trim($_POST['email']);
    $new_role = $_POST['user_role'] ?? 'editor';
    $raw_pass = $_POST['password'];

    if (!empty($new_user) && !empty($raw_pass)) {
        $hashed_pass = password_hash($raw_pass, PASSWORD_BCRYPT, ['cost' => 12]);
        
        $stmt = $pdo->prepare("INSERT INTO snap_users (username, email, password_hash, user_role) VALUES (?, ?, ?, ?)");
        try {
            $stmt->execute([$new_user, $new_email, $hashed_pass, $new_role]);
            $msg = "Access granted for user: {$new_user}";
        } catch (PDOException $e) {
            $err = "Error: Database conflict. Check if username or email is already in use.";
        }
    }
}

// --- 2. ACTION: DELETE USER ---
if (isset($_GET['delete'])) {
    $uid = (int)$_GET['delete'];
    if (isset($_SESSION['user_login'])) {
        $stmt = $pdo->prepare("SELECT username FROM snap_users WHERE id = ?");
        $stmt->execute([$uid]);
        $target = $stmt->fetchColumn();
        
        if ($target !== $_SESSION['user_login']) {
            $pdo->prepare("DELETE FROM snap_users WHERE id = ?")->execute([$uid]);
            header("Location: smack-users.php");
            exit;
        } else {
            $err = "Error: You cannot delete the account currently logged in.";
        }
    }
}

// --- 3. DATA FETCH ---
$users = $pdo->query("SELECT id, username, email, user_role FROM snap_users ORDER BY username ASC")->fetchAll();

$page_title = "User Manager";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>USER MANAGEMENT</h2>
    </div>

    <?php if(isset($msg)): ?><div class="msg">> <?php echo $msg; ?></div><?php endif; ?>
    <?php if(isset($err)): ?><div class="alert alert-error">> <?php echo $err; ?></div><?php endif; ?>

    <div class="box">
        <h3>ADD NEW SYSTEM USER</h3>
        <form method="POST">
            <label>USERNAME</label>
            <input type="text" name="username" required autocomplete="off">

            <label>EMAIL ADDRESS</label>
            <input type="email" name="email" required autocomplete="off">

            <label>SYSTEM ROLE</label>
            <select name="user_role">
                <option value="editor">Editor (Content Only)</option>
                <option value="admin">Administrator (Full Access)</option>
            </select>
            
            <label>PASSWORD</label>
            <input type="password" name="password" required>

            <button type="submit" name="add_user" class="master-update-btn">CREATE USER ACCESS</button>
        </form>
    </div>

    <div class="box">
        <h3>EXISTING ACCESS ACCOUNTS</h3>
        <?php foreach ($users as $u): ?>
            <div class="recent-item">
                <div class="item-details">
                    <div class="item-text">
                        <strong><?php echo strtoupper($u['username']); ?></strong>
                        <span class="dim"><?php echo $u['email']; ?> | Role: <?php echo ucfirst($u['user_role']); ?></span>
                    </div>
                </div>
                
                <div class="action-cell-flex">
                    <a href="smack-edit-user.php?id=<?php echo $u['id']; ?>" class="action-edit">EDIT</a>

                    <?php if($u['username'] !== $_SESSION['user_login']): ?>
                        <a href="?delete=<?php echo $u['id']; ?>" 
                           class="action-delete" 
                           onclick="return confirm('Confirm permanent deletion of this user?')">DELETE</a>
                    <?php else: ?>
                        <span class="dim" style="font-size: 0.6rem; letter-spacing: 0.5px;">(ACTIVE SESSION)</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>