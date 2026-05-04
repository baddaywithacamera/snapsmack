<?php
/**
 * SNAPSMACK - Privacy Policy Manager
 *
 * Manages the site's public-facing privacy policy. When enabled, a link
 * appears in the public footer and the policy renders at privacy-policy.php.
 *
 * Blog owners who participate in SMACKATTACK or GOBSMACKED should
 * disclose this to their visitors here.
 */

require_once 'core/auth.php';

// ─── FORM SUBMISSION ───────────────────────────────────────────────

if (isset($_POST['save_privacy'])) {
    $enabled = isset($_POST['privacy_policy_enabled']) ? '1' : '0';
    $title   = trim($_POST['privacy_policy_title'] ?? 'Privacy Policy');
    $content = trim($_POST['privacy_policy_content'] ?? '');

    $saves = [
        'privacy_policy_enabled' => $enabled,
        'privacy_policy_title'   => $title,
        'privacy_policy_content' => $content,
    ];

    foreach ($saves as $key => $val) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM snap_settings WHERE setting_key = ?");
        $chk->execute([$key]);
        if ($chk->fetchColumn() > 0) {
            $pdo->prepare("UPDATE snap_settings SET setting_val = ? WHERE setting_key = ?")->execute([$val, $key]);
        } else {
            $pdo->prepare("INSERT INTO snap_settings (setting_val, setting_key) VALUES (?, ?)")->execute([$val, $key]);
        }
    }

    header("Location: smack-privacy.php?msg=saved");
    exit;
}

// ─── LOAD EXISTING DATA ───────────────────────────────────────────

$stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings WHERE setting_key IN ('privacy_policy_enabled','privacy_policy_title','privacy_policy_content')");
$priv = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$pp_enabled = $priv['privacy_policy_enabled'] ?? '0';
$pp_title   = $priv['privacy_policy_title']   ?? 'Privacy Policy';
$pp_content = $priv['privacy_policy_content'] ?? '';

$page_title = "Privacy Policy";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">

    <h2>PRIVACY POLICY</h2>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert">> PRIVACY POLICY SAVED</div>
    <?php endif; ?>

    <form method="POST">

        <div class="box">
            <div class="lens-input-wrapper">
                <label>Enable Public Privacy Policy Page <span class="field-tip" data-tip="When enabled, a Privacy Policy link appears in the public site footer and the policy is accessible at /privacy-policy.php.">ⓘ</span></label>
                <label class="toggle-switch">
                    <input type="checkbox" name="privacy_policy_enabled" value="1" <?php echo ($pp_enabled === '1') ? 'checked' : ''; ?>>
                    <span class="toggle-label">Show privacy policy link in footer</span>
                </label>
            </div>
        </div>

        <div class="box">
            <div class="lens-input-wrapper">
                <label>Page Title</label>
                <input type="text" name="privacy_policy_title" value="<?php echo htmlspecialchars($pp_title); ?>" class="text-input" placeholder="Privacy Policy">
            </div>
        </div>

        <div class="box">
            <div class="lens-input-wrapper">
                <label>Privacy Policy Content <span class="field-tip" data-tip="HTML accepted. If you participate in SMACKATTACK or GOBSMACKED, disclose it here — your visitors have a right to know.">ⓘ</span></label>
                <textarea name="privacy_policy_content" class="css-override-textarea" spellcheck="false" rows="24"><?php echo htmlspecialchars($pp_content); ?></textarea>
            </div>
        </div>

        <div class="form-action-row">
            <button type="submit" name="save_privacy" class="master-update-btn">
                SAVE PRIVACY POLICY
            </button>
        </div>

    </form>

</div>

<?php include 'core/admin-footer.php'; ?>
// EOF
