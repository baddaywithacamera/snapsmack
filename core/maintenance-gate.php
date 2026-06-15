<?php
/**
 * SNAPSMACK - Maintenance Gate
 *
 * Include immediately after $settings is populated on any public-facing page.
 * If maintenance_mode is '1' AND the visitor is not logged in, renders the
 * maintenance page and exits. Logged-in users pass through unaffected.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

// Trigger on explicit maintenance mode OR a SMACKBACK breach. The breach path
// (spec #2 lockdown, public side) holds the public site down so a tampered
// install can't throw bad/exploit code at visitors. Admins still pass through.
$_mg_maint  = ($settings['maintenance_mode'] ?? '0') === '1';
$_mg_breach = ($settings['smackback_enabled'] ?? '0') === '1'
           && ($settings['smackback_status'] ?? 'clean') === 'breach';
if (!$_mg_maint && !$_mg_breach) {
    return; // Nothing to do — neither maintenance nor breach
}

// Resume an existing session to check login state — never create a new one
// for visitors who will just see the holding page. If no cookie exists, there
// is no logged-in session to check, so skip session_start entirely.
if (session_status() === PHP_SESSION_NONE && !empty($_COOKIE[session_name()])) {
    session_start();
}

// Logged-in users see the site normally
if (!empty($_SESSION['user_login'])) {
    return;
}

// Breach path: send 503 (temporary, keeps crawlers from indexing the holding
// page) and, when this is a pure breach (not also explicit maintenance), use a
// neutral message — never reveal a breach to the public.
if ($_mg_breach) {
    http_response_code(503);
    header('Retry-After: 3600');
    if (!$_mg_maint) {
        $settings['maintenance_title'] = 'Temporarily Unavailable';
        $settings['maintenance_body']  = 'This site is briefly offline for maintenance. Please check back soon.';
    }
}

// ── Build page values ──────────────────────────────────────────────────────
$maint_title = htmlspecialchars($settings['maintenance_title'] ?? 'Under Maintenance');
$maint_body  = htmlspecialchars($settings['maintenance_body']  ?? 'We\'re working on a few things. Check back soon.');
$site        = htmlspecialchars($settings['site_name'] ?? 'SNAPSMACK');

// Pull two brand colours from settings where available; fall back to neutral
// dark-mode defaults that look good on any skin.
$bg    = '#0d0d0d';
$card  = '#161616';
$ink   = '#e8e2d4';
$dim   = '#6a6258';
$rule  = '#2a2520';

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $maint_title; ?> — <?php echo $site; ?></title>
<meta name="robots" content="noindex,nofollow">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body {
    height: 100%;
    background: <?php echo $bg; ?>;
    color: <?php echo $ink; ?>;
    font-family: Georgia, 'Times New Roman', serif;
    display: flex;
    align-items: center;
    justify-content: center;
}
.maint-wrap {
    text-align: center;
    padding: 2rem;
    max-width: 520px;
    width: 100%;
}
.maint-icon {
    width: 64px;
    height: 64px;
    margin: 0 auto 2rem;
    opacity: 0.55;
    animation: maint-rock 3s ease-in-out infinite;
    transform-origin: 50% 80%;
}
@keyframes maint-rock {
    0%, 100% { transform: rotate(-8deg); }
    50%       { transform: rotate(8deg); }
}
.maint-site {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.25em;
    text-transform: uppercase;
    color: <?php echo $dim; ?>;
    margin-bottom: 1.5rem;
}
.maint-rule {
    width: 40px;
    height: 1px;
    background: <?php echo $rule; ?>;
    margin: 1.5rem auto;
}
.maint-title {
    font-size: 1.6rem;
    font-weight: normal;
    letter-spacing: 0.04em;
    color: <?php echo $ink; ?>;
    line-height: 1.3;
    margin-bottom: 1rem;
}
.maint-body {
    font-size: 1rem;
    line-height: 1.75;
    color: <?php echo $dim; ?>;
}
</style>
</head>
<body>
<div class="maint-wrap">
    <!-- Wrench SVG — inline, no external deps -->
    <svg class="maint-icon" viewBox="0 0 24 24" fill="none"
         stroke="<?php echo $ink; ?>" stroke-width="1.5"
         stroke-linecap="round" stroke-linejoin="round"
         xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
    </svg>
    <div class="maint-site"><?php echo $site; ?></div>
    <div class="maint-rule"></div>
    <h1 class="maint-title"><?php echo $maint_title; ?></h1>
    <p class="maint-body"><?php echo nl2br($maint_body); ?></p>
    <div class="maint-rule"></div>
</div>
</body>
</html>
<?php
exit();
// ===== SNAPSMACK EOF =====
