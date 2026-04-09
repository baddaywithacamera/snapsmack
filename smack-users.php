<?php
/**
 * SNAPSMACK - User account management
 * Alpha v0.7.9c
 *
 * Handles creation, editing, and deletion of administrator and editor accounts.
 * Enforces password hashing and prevents self-deletion of the active user.
 */

require_once 'core/auth.php';
require_once 'core/auth-recovery.php';

$new_recovery_code      = null; // plaintext code to display once after generation
$new_recovery_username  = null;

// --- USER CREATION ---
// Registers a new system user with hashed password and assigned role.
if (isset($_POST['add_user'])) {
    $new_user  = trim($_POST['username']);
    $new_email = trim($_POST['email']);
    $new_role  = $_POST['user_role'] ?? 'editor';
    $raw_pass  = $_POST['password'];

    if (!empty($new_user) && !empty($raw_pass)) {
        $hashed_pass = password_hash($raw_pass, PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $pdo->prepare("INSERT INTO snap_users (username, email, password_hash, user_role) VALUES (?, ?, ?, ?)");
        try {
            $stmt->execute([$new_user, $new_email, $hashed_pass, $new_role]);
            $new_uid = (int)$pdo->lastInsertId();
            $msg = "Access granted for user: {$new_user}";

            // Optionally generate a one-time recovery code for the new user.
            if (!empty($_POST['gen_recovery_code'])) {
                $new_recovery_code     = snapsmack_store_recovery_code($pdo, $new_uid);
                $new_recovery_username = $new_user;

                // Email the code if requested and an address was supplied.
                if (!empty($_POST['email_recovery_code']) && !empty($new_email)) {
                    $settings_r  = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
                    $site_name_r = $settings_r['site_name'] ?? 'SnapSmack';
                    $site_url_r  = rtrim($settings_r['site_url'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '/') . '/';
                    snapsmack_send_recovery_email($new_email, $new_user, $new_recovery_code, $site_name_r, $site_url_r);
                }
            }
        } catch (PDOException $e) {
            $err = "Error: Database conflict. Check if username or email is already in use.";
        }
    }
}

// --- RECOVERY CODE GENERATION FOR EXISTING USER ---
// Generates a fresh one-time recovery code for an existing account.
if (isset($_POST['gen_code_for_user'])) {
    $rc_uid = (int)$_POST['rc_uid'];
    if ($rc_uid > 0) {
        $rc_stmt = $pdo->prepare("SELECT id, username, email FROM snap_users WHERE id = ?");
        $rc_stmt->execute([$rc_uid]);
        $rc_user = $rc_stmt->fetch(PDO::FETCH_ASSOC);

        if ($rc_user) {
            $new_recovery_code     = snapsmack_store_recovery_code($pdo, $rc_uid);
            $new_recovery_username = $rc_user['username'];

            if (!empty($_POST['email_recovery_code']) && !empty($rc_user['email'])) {
                $settings_r  = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
                $site_name_r = $settings_r['site_name'] ?? 'SnapSmack';
                $site_url_r  = rtrim($settings_r['site_url'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '/') . '/';
                snapsmack_send_recovery_email($rc_user['email'], $rc_user['username'], $new_recovery_code, $site_name_r, $site_url_r);
                $msg = "Recovery code generated and emailed to {$rc_user['username']}.";
            } else {
                $msg = "Recovery code generated for {$rc_user['username']}.";
            }
        }
    }
}

// --- USER DELETION ---
// Removes a user account with safeguard against deleting the logged-in user.
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

// --- DATA RETRIEVAL ---
// Load all user accounts for display and management.
$users = $pdo->query("SELECT id, username, email, user_role, (recovery_code_hash IS NOT NULL) AS has_recovery_code FROM snap_users ORDER BY username ASC")->fetchAll();

$page_title = "User Manager";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>USER MANAGEMENT</h2>
    </div>

    <?php if(isset($msg)): ?>
        <div class="msg">> <?php echo $msg; ?></div>
    <?php endif; ?>

    <?php if(isset($err)): ?>
        <div class="alert alert-error">> <?php echo $err; ?></div>
    <?php endif; ?>

    <?php if ($new_recovery_code): ?>
        <div class="box" style="border-color: var(--accent, #aaa);">
            <h3>ONE-TIME RECOVERY CODE — COPY NOW</h3>
            <p style="font-size:0.82rem; color:var(--text-muted,#888); margin-bottom:14px;">
                This code is shown <strong>once only</strong> and cannot be retrieved again.
                Hand it to <strong><?php echo htmlspecialchars($new_recovery_username); ?></strong> securely.
            </p>
            <div style="font-family: monospace; font-size: 1.4rem; letter-spacing: 3px;
                        padding: 14px 20px; background: var(--input-bg,#111);
                        border: 1px solid var(--border,#333); color: var(--text-primary,#eee);
                        display: inline-block;">
                <?php echo htmlspecialchars($new_recovery_code); ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="box">
        <h3>ADD NEW SYSTEM USER</h3>
        <form method="POST">
            <div class="lens-input-wrapper">
                <label>USERNAME</label>
                <input type="text" name="username" required autocomplete="off">
            </div>

            <div class="lens-input-wrapper">
                <label>EMAIL ADDRESS</label>
                <input type="email" name="email" required autocomplete="off">
            </div>

            <div class="lens-input-wrapper">
                <label>SYSTEM ROLE</label>
                <select name="user_role">
                    <option value="editor">Editor (Content Only)</option>
                    <option value="admin">Administrator (Full Access)</option>
                </select>
            </div>

            <div class="lens-input-wrapper">
                <label>PASSWORD</label>
                <input type="password" name="password" required>
            </div>

            <div class="lens-input-wrapper" style="margin-top:10px;">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                    <input type="checkbox" name="gen_recovery_code" id="gen_rc_new" value="1">
                    Generate a one-time recovery code for this user
                </label>
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; margin-top:6px;" id="email_rc_row_new">
                    <input type="checkbox" name="email_recovery_code" value="1">
                    Also email the code to the user's address
                </label>
            </div>

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
                        <span class="dim">
                            <?php echo htmlspecialchars($u['email']); ?> | Role: <?php echo ucfirst($u['user_role']); ?>
                            <?php if ($u['has_recovery_code']): ?>
                                <span style="color:var(--accent,#aaa);">&nbsp;· recovery code set</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <div class="action-cell-flex">
                    <a href="smack-edit-user.php?id=<?php echo $u['id']; ?>" class="action-edit">EDIT</a>

                    <!-- Recovery code generation inline form -->
                    <form method="POST" style="display:inline; margin:0;">
                        <input type="hidden" name="rc_uid" value="<?php echo $u['id']; ?>">
                        <button type="submit" name="gen_code_for_user"
                                class="action-edit"
                                onclick="return confirm('Generate a new one-time recovery code for <?php echo htmlspecialchars(addslashes($u['username'])); ?>?\nAny existing code will be replaced.')">
                            RECOVERY CODE
                        </button>
                        <?php if (!empty($u['email'])): ?>
                            <label style="font-size:0.7rem; color:var(--text-muted,#666); cursor:pointer; margin-left:4px;">
                                <input type="checkbox" name="email_recovery_code" value="1" style="vertical-align:middle;">
                                email
                            </label>
                        <?php endif; ?>
                    </form>

                    <?php if($u['username'] !== $_SESSION['user_login']): ?>
                        <a href="?delete=<?php echo $u['id']; ?>"
                           class="action-delete"
                           onclick="return confirm('Confirm permanent deletion of this user?')">DELETE</a>
                    <?php else: ?>
                        <span class="dim badge-active-session">(ACTIVE SESSION)</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>