<?php
/**
 * SNAPSMACK - Admin Dashboard Header
 *
 * Outputs the HTML <head> and opening <body> tags for all admin pages.
 * Resolves the active theme from the user's session preference, loads the
 * appropriate theme CSS, and detects cron/PHP-CLI availability for scheduled
 * tasks.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 86400);
    ini_set('session.cookie_lifetime', 86400);
    session_start();
}

// --- LOAD SETTINGS ---
// Fetch site settings from the database if not already in scope
if (!isset($settings)) {
    $settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// --- CRON CAPABILITY DETECTION ---
// Check if the server supports cron and PHP CLI for scheduled task runner
$cron_supported  = false;
$php_cli_path    = '';
if (function_exists('exec')) {
    exec('crontab -l 2>&1', $ct_out, $ct_code);
    $cron_supported = ($ct_code === 0);
    $php_cli_path   = trim(exec('which php 2>&1'));
    if (strpos($php_cli_path, '/') !== 0) $php_cli_path = '';
}

// --- THEME RESOLUTION ---
// Use the user's session preference, fall back to the global setting,
// then default to midnight-lime
$active_theme = $_SESSION['user_preferred_skin'] ?? $settings['active_theme'] ?? 'midnight-lime';

// --- ALERT THEME OVERRIDE ---
// Hidden pulsing themes auto-applied on an active alert, replacing the user's
// theme entirely so the whole admin UI signals the state. A SmackBack breach
// outranks the Layer-2 network YELLOW. $settings is loaded above; $_nalert_status
// is set by core/auth-smack.php before this header is included. Reading
// smackback_status directly means breach pulses even in lockout mode, where the
// admin is forced to smack-back.php and no _smackback_alert_breach flag is set.
if (($settings['smackback_enabled'] ?? '0') === '1'
    && ($settings['smackback_status'] ?? 'clean') === 'breach') {
    $active_theme = 'alert-breach-red';
} elseif (!empty($GLOBALS['_nalert_status'])) {
    $active_theme = ($GLOBALS['_nalert_status'] === 'yellow_fast')
        ? 'alert-yellow-fast'
        : 'alert-yellow-slow';
}

$theme_base = "assets/adminthemes/{$active_theme}/";
$manifest_path = $theme_base . "{$active_theme}-manifest.php";

$colour_css_file = "admin-theme-colours-{$active_theme}.css";

// --- MANIFEST-DRIVEN CSS FILE ---
// Allow theme manifests to override the default CSS filename
if (file_exists($manifest_path)) {
    $m_data = include $manifest_path;
    if (isset($m_data['css_file'])) {
        $colour_css_file = $m_data['css_file'];
    }
}

$active_skin_path = $theme_base . $colour_css_file;

// Buffer the entire admin page so admin-footer.php can auto-inject CSRF
// tokens into every <form method="POST"> before flushing to the browser.
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/csrf.php'; csrf_meta_tag(); ?>
    <title><?php echo $page_title ?? 'Admin'; ?> | SnapSmack</title>

    <link rel="stylesheet" href="assets/css/admin-theme-geometry-master.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
    <link rel="stylesheet" href="<?php echo $active_skin_path; ?>?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
</head>
<body class="admin-body">
<button class="sidebar-toggle" onclick="document.querySelector('.sidebar').classList.toggle('open'); document.querySelector('.sidebar-overlay').classList.toggle('open');">&#9776;</button>
<div class="sidebar-overlay" onclick="document.querySelector('.sidebar').classList.remove('open'); this.classList.remove('open');"></div>
<?php if (!empty($GLOBALS['_smackback_alert_breach'])): ?>
<style>#smackback-banner{margin-left:240px;}@media(max-width:1024px){#smackback-banner{margin-left:0;}}</style>
<div id="smackback-banner" style="background:#3a1000;border-bottom:3px solid #cc2200;padding:10px 24px;font-size:0.88rem;color:#ffccc0;display:flex;align-items:center;gap:16px;position:sticky;top:0;z-index:999;">
    <strong style="color:#ff6600;letter-spacing:1px;font-size:0.82rem;">&#9888; SMACKBACK</strong>
    File tampering or corruption detected. Admin functions are unrestricted (Alert mode).
    <a href="smack-back.php" style="color:#ff9900;text-decoration:none;border:1px solid #ff6600;padding:3px 10px;font-size:0.8rem;white-space:nowrap;">View Breach Detail &rarr;</a>
    <button id="smackback-silence-btn" onclick="document.body.classList.add('alarm-silenced');localStorage.setItem('smackback_alarm_silenced','1');this.style.display='none';" style="margin-left:auto;background:transparent;border:1px solid #884400;color:#ff9900;font-size:0.75rem;padding:3px 10px;cursor:pointer;white-space:nowrap;font-family:inherit;">&#128263; Silence Alarm</button>
</div>
<script>if(localStorage.getItem('smackback_alarm_silenced')==='1'){document.body.classList.add('alarm-silenced');var b=document.getElementById('smackback-silence-btn');if(b)b.style.display='none';}</script>
<?php endif; ?>
<?php if (!empty($GLOBALS['_nalert_status'])): ?>
<?php
    // Network alert banner (Layer 2 — SC global YELLOW). Separate from SMACKBACK RED above.
    require_once __DIR__ . '/network-alert.php';
    nalert_render_banner($GLOBALS['_nalert_status'], $GLOBALS['_nalert_message'] ?? '');
?>
<?php endif; ?>
<?php // ===== SNAPSMACK EOF =====
