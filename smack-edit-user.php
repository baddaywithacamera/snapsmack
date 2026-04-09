<?php
/**
 * SNAPSMACK - User account editor
 * Alpha v0.7.9
 *
 * Allows modification of user email, role, and password.
 * Usernames are immutable to preserve database integrity.
 */

require_once 'core/auth.php';
require_once 'core/auth-recovery.php';

// --- REQUEST VALIDATION ---
// Requires a user ID parameter to load the correct record.
$uid = $_GET['id'] ?? null;
if (!$uid) {
    header("Location: smack-users.php");
    exit;
}

// Load the user record for editing.
$stmt = $pdo->prepare("SELECT * FROM snap_users WHERE id = ?");
$stmt->execute([$uid]);
$user = $stmt->fetch();

if (!$user) {
    die("User not found.");
}

$edit_recovery_code = null; // plaintext code shown once after generation

// --- RECOVERY CODE GENERATION ---
if (isset($_POST['gen_recovery_code'])) {
    $edit_recovery_code = snapsmack_store_recovery_code($pdo, (int)$uid);

    if (!empty($_POST['email_recovery_code']) && !empty($user['email'])) {
        $settings_r  = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        $site_name_r = $settings_r['site_name'] ?? 'SnapSmack';
        $site_url_r  = rtrim($settings_r['site_url'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '/') . '/';
        snapsmack_send_recovery_email($user['email'], $user['username'], $edit_recovery_code, $site_name_r, $site_url_r);
        $msg = "Recovery code generated and emailed to {$user['username']}.";
    } else {
        $msg = "Recovery code generated for {$user['username']}.";
    }

    // Refresh user data (recovery_code_hash is now set).
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
}

// --- FORM SUBMISSION HANDLER ---
// Updates email, role, and optionally resets password.
if (isset($_POST['update_user'])) {
    $email = trim($_POST['email']);
    $role  = $_POST['user_role'];
    $pass  = trim($_POST['password']);

    // Conditionally update password only if a new one was provided.
    $sql = "UPDATE snap_users SET email = ?, user_role = ? WHERE id = ?";
    $params = [$email, $role, $uid];

    if (!empty($pass)) {
        // Hash password before storage.
        $sql = "UPDATE snap_users SET email = ?, user_role = ?, password_hash = ? WHERE id = ?";
        $params = [$email, $role, password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), $uid];
    }

    $pdo->prepare($sql)->execute($params);
    $msg = "User updated successfully.";

    // Refresh user data to display updates.
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
        <div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <?php if ($edit_recovery_code): ?>
        <div class="box" style="border-color: var(--accent, #aaa);">
            <h3>ONE-TIME RECOVERY CODE — COPY NOW</h3>
            <p style="font-size:0.82rem; color:var(--text-muted,#888); margin-bottom:14px;">
                This code is shown <strong>once only</strong> and cannot be retrieved again.
                Hand it to <strong><?php echo htmlspecialchars($user['username']); ?></strong> securely.
            </p>
            <div style="font-family: monospace; font-size: 1.4rem; letter-spacing: 3px;
                        padding: 14px 20px; background: var(--input-bg,#111);
                        border: 1px solid var(--border,#333); color: var(--text-primary,#eee);
                        display: inline-block;">
                <?php echo htmlspecialchars($edit_recovery_code); ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="box">
        <form method="POST">
            <label>USERNAME (IMMUTABLE)</label>
            <div class="read-only-display"><?php echo htmlspecialchars($user['username']); ?></div>

            <label>EMAIL ADDRESS</label>
            <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>

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

    <div class="box">
        <h3>RECOVERY CODE</h3>
        <p style="font-size:0.82rem; color:var(--text-muted,#888); margin-bottom:16px;">
            Generate a one-time recovery code for this user. They can use it at the login screen
            if they are locked out. The code is consumed on first use and they must set a new
            password immediately after.
            <?php if (!empty($user['recovery_code_hash'])): ?>
                <br><span style="color:var(--accent,#aaa);">A recovery code is currently set for this account.</span>
            <?php endif; ?>
        </p>
        <form method="POST" style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
            <?php if (!empty($user['email'])): ?>
                <label style="font-size:0.78rem; color:var(--text-muted,#888); cursor:pointer; display:flex; align-items:center; gap:6px;">
                    <input type="checkbox" name="email_recovery_code" value="1">
                    Email code to <?php echo htmlspecialchars($user['email']); ?>
                </label>
            <?php endif; ?>
            <button type="submit" name="gen_recovery_code" class="master-update-btn"
                    onclick="return confirm('Generate a new one-time recovery code?\nAny existing code will be replaced.')">
                GENERATE RECOVERY CODE
            </button>
        </form>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>