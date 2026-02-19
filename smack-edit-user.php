<?php
/**
 * SnapSmack - Edit User
 * Version: 1.2 - Triptych Authorized (Geometry + Read-Only Lock)
 */
require_once 'core/auth.php';

$uid = $_GET['id'] ?? null;
if (!$uid) { header("Location: smack-users.php"); exit; }

// 1. Fetch current user data
$stmt = $pdo->prepare("SELECT * FROM snap_users WHERE id = ?");
$stmt->execute([$uid]);
$user = $stmt->fetch();

if (!$user) { die("User not found."); }

// 2. Handle Update (Surgical: Username is excluded)
if (isset($_POST['update_user'])) {
    $email = trim($_POST['email']);
    $role  = $_POST['user_role'];
    $pass  = trim($_POST['password']);

    $sql = "UPDATE snap_users SET email = ?, user_role = ? WHERE id = ?";
    $params = [$email, $role, $uid];
    
    if (!empty($pass)) {
        $sql = "UPDATE snap_users SET email = ?, user_role = ?, password_hash = ? WHERE id = ?";
        $params = [$email, $role, password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), $uid];
    }

    $pdo->prepare($sql)->execute($params);
    $msg = "User updated successfully.";
    
    // Refresh
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
}

$page_title = "Edit User";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>EDIT USER: <?php echo strtoupper($user['username']); ?></h2>
    </div>

    <?php if(isset($msg)): ?>
        <div class="alert alert-success"><?php echo $msg; ?></div>
    <?php endif; ?>

    <div class="box">
        <form method="POST">
            <label>USERNAME (IMMUTABLE)</label>
            <div class="read-only-display"><?php echo $user['username']; ?></div>

            <label>EMAIL ADDRESS</label>
            <input type="email" name="email" value="<?php echo $user['email']; ?>" required>

            <label>SYSTEM ROLE</label>
            <select name="user_role">
                <option value="admin" <?php echo ($user['user_role'] == 'admin') ? 'selected' : ''; ?>>Administrator</option>
                <option value="editor" <?php echo ($user['user_role'] == 'editor') ? 'selected' : ''; ?>>Editor</option>
            </select>

            <label>RESET PASSWORD (LEAVE BLANK TO RETAIN CURRENT)</label>
            <input type="password" name="password" placeholder="••••••••">

            <button type="submit" name="update_user" class="master-update-btn">SAVE CHANGES</button>
        </form>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>