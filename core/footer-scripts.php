<?php
/**
 * SNAPSMACK - Global JavaScript Engine Loader
 *
 * Loads core JavaScript engines used on all public pages: the HUD (toast
 * notifications), communications engine (keyboard shortcuts), and Thomas the
 * Bear easter egg. Include this once per controller.
 *
 * NOTE: The drawer engine is loaded by skin-footer.php (for info/comment
 * drawer on photo pages). The wall engine is loaded directly by gallery-wall.php
 * (page-specific). This file only outputs the shared global engines.
 *
 * LOAD ORDER: Consent engine FIRST — other engines check snapConsent.ok()
 * before writing to localStorage/sessionStorage.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


?>

<?php // --- CONSENT ENGINE (must load before any storage-writing scripts) --- ?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-consent.css">
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-consent.js?v=<?php echo time(); ?>"></script>
<?php include __DIR__ . '/consent-banner.php'; ?>

<div id="hud" class="hud-msg"></div>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/public-download-overlay.css">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-comms.css">
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-comms.js?v=<?php echo time(); ?>"></script>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-thomas.css">
<?php
// Expose Thomas UID to JS for the Easter egg discover ping.
// Random 32-char hex generated once and stored in snap_settings — not derived
// from site URL and cannot be reverse-engineered to identify this install.
$_ss_uid = '';
try {
    if ($pdo instanceof PDO) {
        $_ss_uid = $pdo->query(
            "SELECT setting_val FROM snap_settings WHERE setting_key = 'thomas_uid' LIMIT 1"
        )->fetchColumn() ?: '';
        if ($_ss_uid === '' || strlen($_ss_uid) !== 32) {
            $_ss_uid = bin2hex(random_bytes(16));
            $pdo->prepare(
                "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('thomas_uid', ?)
                 ON DUPLICATE KEY UPDATE setting_val = setting_val"
            )->execute([$_ss_uid]);
        }
    }
} catch (Throwable $_ss_e) {}
?>
<script>window.ssUid='<?php echo htmlspecialchars($_ss_uid, ENT_QUOTES); ?>';</script>
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-thomas.js?v=<?php echo time(); ?>"></script>

<?php include __DIR__ . '/social-dock.php'; ?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-social-dock.css">
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-social-dock.js?v=<?php echo time(); ?>"></script>

<?php include __DIR__ . '/sticky-header.php'; ?>
<?php
// Only load the sticky-header engine when the admin actually enabled it. Before
// this, the CSS/JS loaded unconditionally, so the JS stickied the first <header>
// on any page that included this file (e.g. slickr archive pages) even after the
// option was turned off. Match sticky-header.php's own gate ('1' = on) so the
// admin "Sticky header" toggle controls the behaviour everywhere, every skin.
if (($settings['sticky_header_enabled'] ?? '') === '1'):
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-sticky-header.css">
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-sticky-header.js?v=<?php echo time(); ?>"></script>
<?php endif; ?>

<?php // --- SCROLL TIME tracker: emits only on GRAM landing / SMACKONEOUT archive --- ?>
<?php if (function_exists('snapsmack_scrolltime_tag')) snapsmack_scrolltime_tag($settings); ?>
<?php // ===== SNAPSMACK EOF =====
