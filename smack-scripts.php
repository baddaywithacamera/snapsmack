<?php
/**
 * SNAPSMACK - Third-Party Script Manager
 * Alpha v0.7.8
 *
 * Manages external scripts and embed codes that live in the database,
 * not in the codebase. Two zones:
 *
 *   HEAD SCRIPTS — injected before </head> on every public page.
 *                  Use for analytics, tracking pixels, universal loaders.
 *
 *   EMBED CODES  — stored here, placed on any page via [embed:key] shortcode.
 *                  Use for newsletter forms, chat widgets, third-party components.
 */

require_once 'core/auth.php';

// ─── FORM SUBMISSION ───────────────────────────────────────────────

if (isset($_POST['save_scripts'])) {

    $head_scripts = trim($_POST['head_scripts'] ?? '');
    $embed_codes  = trim($_POST['embed_codes'] ?? '');

    // Head scripts — single blob, injected on every public page.
    $check = $pdo->prepare("SELECT COUNT(*) FROM snap_settings WHERE setting_key = 'custom_head_scripts'");
    $check->execute();
    if ($check->fetchColumn() > 0) {
        $stmt = $pdo->prepare("UPDATE snap_settings SET setting_val = ? WHERE setting_key = 'custom_head_scripts'");
    } else {
        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_val, setting_key) VALUES (?, 'custom_head_scripts')");
    }
    $stmt->execute([$head_scripts]);

    // Embed codes — key=value blocks, one per shortcode.
    $check2 = $pdo->prepare("SELECT COUNT(*) FROM snap_settings WHERE setting_key = 'custom_embed_codes'");
    $check2->execute();
    if ($check2->fetchColumn() > 0) {
        $stmt2 = $pdo->prepare("UPDATE snap_settings SET setting_val = ? WHERE setting_key = 'custom_embed_codes'");
    } else {
        $stmt2 = $pdo->prepare("INSERT INTO snap_settings (setting_val, setting_key) VALUES (?, 'custom_embed_codes')");
    }
    $stmt2->execute([$embed_codes]);

    header("Location: smack-scripts.php?msg=INJECTED");
    exit;
}

// ─── LOAD EXISTING DATA ───────────────────────────────────────────

$stmt = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = 'custom_head_scripts'");
$stmt->execute();
$head_scripts = $stmt->fetchColumn() ?: '';

$stmt2 = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = 'custom_embed_codes'");
$stmt2->execute();
$embed_codes = $stmt2->fetchColumn() ?: '';

$page_title = "Third-Party Scripts";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">

    <h2>SMACK YOUR SCRIPTS UP!</h2>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert">> SCRIPTS INJECTED</div>
    <?php endif; ?>

    <form method="POST">

        <!-- HEAD SCRIPTS -->
        <div class="box">
            <div class="lens-input-wrapper">
                <label>HEAD SCRIPTS</label>
                <p class="dim">Injected before &lt;/head&gt; on every public page. Analytics, tracking pixels, universal loaders (e.g. MailerLite, Google Analytics).</p>
                <textarea name="head_scripts" class="css-override-textarea" spellcheck="false" placeholder="<!-- Paste your tracking scripts here -->"><?php echo htmlspecialchars($head_scripts); ?></textarea>
            </div>
        </div>

        <!-- EMBED CODES -->
        <div class="box">
            <div class="lens-input-wrapper">
                <label>EMBED CODES</label>
                <p class="dim">
                    Define reusable embed snippets. Each block starts with a key line —
                    <code style="color:#a0ff90;">[key:mailerlite]</code> — followed by the HTML.
                    Place them on any page with the <code style="color:#a0ff90;">[embed:mailerlite]</code> shortcode.
                </p>
                <textarea name="embed_codes" class="css-override-textarea" spellcheck="false" placeholder="[key:mailerlite]
<div class=&quot;ml-embedded&quot; data-form=&quot;Ixs8uR&quot;></div>

[key:youtube-subscribe]
<div class=&quot;g-ytsubscribe&quot; data-channelid=&quot;UC...&quot;></div>"><?php echo htmlspecialchars($embed_codes); ?></textarea>
            </div>
        </div>

        <div class="form-action-row">
            <button type="submit" name="save_scripts" class="master-update-btn">
                SAVE SCRIPTS
            </button>
        </div>

    </form>

</div>

<?php include 'core/admin-footer.php'; ?>
