<?php
/**
 * SNAPSMACK.CA — Shared Page Header
 *
 * Include at the top of every page after setting:
 *   $page_title       — <title> content
 *   $page_description — meta description
 *   $page_og_url      — canonical og:url for this page
 *   $nav_active       — key matching a nav link: index|wotcha|bugger|tnb|hairy-muff|brass-tacks|buzzers|oi
 *   $page_css         — (optional) additional CSS string for page-specific styles
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once __DIR__ . '/site-version.php';

function ss_nav_link(string $href, string $label, string $key, string $active): string {
    $cls = ($active === $key) ? ' class="active"' : '';
    return '            <a href="' . $href . '"' . $cls . '>' . $label . '</a>';
}

$_nav = function(string $active): string {
    return ss_nav_link('index.php',      'GAFF!',           'index',      $active) . "\n" .
           ss_nav_link('wotcha.php',     'WOTCHA!',         'wotcha',     $active) . "\n" .
           ss_nav_link('bugger.php',     'BUGGER!',         'bugger',     $active) . "\n" .
           ss_nav_link('tnb.php',        'TWIG N BERRIES!', 'tnb',        $active) . "\n" .
           ss_nav_link('hairy-muff.php', 'HAIRY MUFF!',     'hairy-muff',  $active) . "\n" .
           ss_nav_link('brass-tacks.php','BRASS TACKS!',    'brass-tacks', $active) . "\n" .
           ss_nav_link('buzzers.php',    'BUZZERS!',        'buzzers',     $active) . "\n" .
           ss_nav_link('oi.php',         'OI THERE MATE!',  'oi',          $active);
};

$_page_css_block = isset($page_css) && $page_css !== '' ? "\n" . $page_css . "\n" : '';
?>
<!DOCTYPE html>

<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF
  marker for this file type: an HTML comment containing five
  equals, space, the literal string 'SNAPSMACK EOF', space, five
  equals.
  (Authoritative byte sequence: tools/check-eof.py EOF_MARKERS.)
  Missing or different = truncated/corrupted. Restore before saving.
-->

<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($page_title); ?></title>
<meta name="description" content="<?php echo htmlspecialchars($page_description); ?>">

<!-- Favicon -->
<link rel="icon" type="image/png" href="ss_favicon.png">
<link rel="apple-touch-icon" href="ss_favicon.png">

<!-- Open Graph -->
<meta property="og:type" content="website">
<meta property="og:url" content="<?php echo htmlspecialchars($page_og_url); ?>">
<meta property="og:title" content="<?php echo htmlspecialchars($page_title); ?>">
<meta property="og:description" content="<?php echo htmlspecialchars($page_description); ?>">
<meta property="og:image" content="https://snapsmack.ca/img/logo.png">
<meta property="og:image:width" content="1024">
<meta property="og:image:height" content="1024">
<meta property="og:image:alt" content="SnapSmack logo">

<!-- Site styles — extracted from this header to /assets/css/snapsmack-ca.css.
     filemtime cache-bust auto-busts on every change; no manual ?v bump. -->
<link rel="stylesheet" href="assets/css/snapsmack-ca.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/snapsmack-ca.css'); ?>">
<?php if ($_page_css_block !== ''): ?>
<style>
<?php echo $_page_css_block; ?>
</style>
<?php endif; ?>
</head>
<body>

<!-- MINI HEADER -->
<div id="mini-header">
    <div class="mini-inner">
        <a href="index.php" class="mini-logo"><img src="img/logo.png" alt="SnapSmack"></a>
        <nav>
<?php echo $_nav($nav_active); ?>
        </nav>
    </div>
</div>

<!-- MAIN HEADER -->
<header id="site-header">
    <div class="header-inner">
        <a href="index.php" class="logo-lockup">
            <img src="img/logo.png" alt="SnapSmack">
            <div class="logo-text">
                <div class="snap"><em>Snap</em><strong>Smack</strong></div>
                <div class="tagline">PHOTO <em>BLOGGING</em> IS BACK, BITCHEZ</div>
            </div>
        </a>
        <div class="header-right">
            <nav>
<?php echo $_nav($nav_active); ?>
            </nav>
        </div>
    </div>
</header>
<?php // ===== SNAPSMACK EOF =====
