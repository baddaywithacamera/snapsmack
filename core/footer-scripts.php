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
// Expose install UID to JS so Thomas can include it in the discover ping.
// Same hash as _updater_ping_home() — SHA-256 of normalised site URL, first 32 chars.
$_ss_uid = '';
try {
    if ($pdo instanceof PDO) {
        $_ss_site_url = $pdo->query(
            "SELECT setting_val FROM snap_settings WHERE setting_key = 'site_url' LIMIT 1"
        )->fetchColumn() ?: '';
        if ($_ss_site_url !== '') {
            $_ss_uid = substr(hash('sha256', strtolower(rtrim($_ss_site_url, '/'))), 0, 32);
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
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-sticky-header.css">
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-sticky-header.js?v=<?php echo time(); ?>"></script>
<?php // ===== SNAPSMACK EOF =====
