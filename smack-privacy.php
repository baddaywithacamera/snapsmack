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

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth-smack.php';

// ─── FORM SUBMISSION ───────────────────────────────────────────────

if (isset($_POST['save_privacy'])) {
    $enabled = isset($_POST['privacy_policy_enabled']) ? '1' : '0';
    $title   = trim($_POST['privacy_policy_title'] ?? 'Privacy Policy');
    $content = trim($_POST['privacy_policy_content'] ?? '');
    $retention = max(1, min(365, (int)($_POST['stats_retention_days'] ?? 365)));

    $saves = [
        'privacy_policy_enabled' => $enabled,
        'privacy_policy_title'   => $title,
        'privacy_policy_content' => $content,
        'stats_retention_days'   => (string)$retention,
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

$stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings WHERE setting_key IN ('privacy_policy_enabled','privacy_policy_title','privacy_policy_content','stats_retention_days','site_name','site_email')");
$priv = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$pp_enabled = $priv['privacy_policy_enabled'] ?? '0';
$pp_title   = $priv['privacy_policy_title']   ?? 'Privacy Policy';
$pp_content = $priv['privacy_policy_content'] ?? '';
$stats_retention_days = max(1, min(365, (int)($priv['stats_retention_days'] ?? 365)));

$starter_site = htmlspecialchars($priv['site_name'] ?? 'This site', ENT_QUOTES);
$starter_email = htmlspecialchars($priv['site_email'] ?? 'the address shown on the contact page', ENT_QUOTES);
$pipeda_starter = <<<HTML
<h2>Our privacy approach</h2>
<p>{$starter_site} follows the fair-information principles in Canada’s federal Personal Information Protection and Electronic Documents Act (PIPEDA), whether or not the Act legally applies to a particular activity.</p>

<h2>What this site records</h2>
<p>The site records limited first-party usage information so its operator can troubleshoot problems and understand which content is viewed. A visit record can include the date and time, the page or photograph viewed, the referring website’s domain, broad browser and operating-system categories, country when supplied by the local web server, time spent on eligible feed pages, and a one-day visitor code derived from the network address.</p>
<p>The raw network address, full referring URL, full browser user-agent string, and search phrases are not stored. The one-day visitor code changes daily and is not used to follow someone across days. There are no advertising trackers, no cross-site tracking, and no third-party analytics.</p>

<h2>Your device preferences</h2>
<p>If you choose “Allow preferences,” the site may use browser storage to remember display and navigation choices on this device. If you choose “Continue without,” optional browser storage is cleared and disabled. A necessary privacy-choice cookie remembers that selection for up to one year.</p>

<h2>How long information is kept</h2>
<p>Visit-level usage records are automatically deleted after the retention period selected by the site operator, which cannot exceed 365 days. Daily summary counts may be kept longer because they do not contain raw network addresses or persistent visitor identifiers.</p>

<h2>Sharing and safeguards</h2>
<p>Usage statistics stay in this site’s own database and are not sold or provided to advertising or third-party analytics services. Reasonable technical and administrative safeguards are used to protect the information.</p>

<h2>Your questions and rights</h2>
<p>You may ask what personal information the site operator holds about you, request a correction, or raise a privacy concern. Contact: {$starter_email}.</p>

<p><em>Site operator: review this starter before publishing it. Add accurate details for comments, accounts, federation, email services, artificial-intelligence features, or other optional services you enable.</em></p>
HTML;

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
                <p>Start with plain, accurate language, then add any optional services this site actually uses.</p>
                <button type="button" id="use-pipeda-starter" class="secondary-btn">USE PIPEDA STARTER</button>
                <textarea id="privacy-policy-content" name="privacy_policy_content" class="css-override-textarea" spellcheck="false" rows="24"><?php echo htmlspecialchars($pp_content); ?></textarea>
            </div>
        </div>

        <div class="box">
            <div class="lens-input-wrapper">
                <label>Detailed Usage Retention</label>
                <input type="number" name="stats_retention_days" value="<?php echo $stats_retention_days; ?>" min="1" max="365" class="text-input">
                <p>Days before visit-level records are automatically deleted. The maximum is 365 days; daily summary counts may remain.</p>
            </div>
        </div>

        <div class="form-action-row">
            <button type="submit" name="save_privacy" class="master-update-btn">
                SAVE PRIVACY POLICY
            </button>
        </div>

    </form>

</div>

<script>
(function () {
    var button = document.getElementById('use-pipeda-starter');
    var field = document.getElementById('privacy-policy-content');
    if (!button || !field) return;
    button.addEventListener('click', function () {
        if (field.value.trim() && !window.confirm('Replace the current policy text with the PIPEDA starter?')) return;
        field.value = <?php echo json_encode($pipeda_starter, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        field.focus();
    });
}());
</script>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
