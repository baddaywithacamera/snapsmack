<?php
/**
 * SNAPSMACK.CA — Shared Page Header
 *
 * Include at the top of every page after setting:
 *   $page_title       — <title> content
 *   $page_description — meta description
 *   $page_og_url      — canonical og:url for this page
 *   $nav_active       — key matching a nav link: index|wotcha|bugger|tnb|oi|hairy-muff
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
           ss_nav_link('hairy-muff.php', 'HAIRY MUFF!',     'hairy-muff', $active) . "\n" .
           ss_nav_link('oi.php',         'OI MATE!',        'oi',         $active);
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

<style>
/* \u2500\u2500\u2500 RESET & BASE \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500 */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --red:       #D40000;
    --black:     #111111;
    --dark-grey: #333333;
    --mid-grey:  #666666;
    --light-grey:#f4f4f4;
    --border:    #ddd;
    --white:     #ffffff;
    --max:       1100px;
}

html { scroll-behavior: smooth; }

body {
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 17px;
    line-height: 1.7;
    zoom: 1.25;
    color: var(--dark-grey);
    background: var(--white);
}

a { color: var(--dark-grey); text-decoration: none; transition: color 0.15s; }
a:hover { color: var(--black); text-decoration: underline; }

img { display: block; max-width: 100%; height: auto; }

/* \u2500\u2500\u2500 LAYOUT \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500 */
.wrap { max-width: var(--max); margin: 0 auto; padding: 0 32px; }
section { padding: 72px 0; }
section + section { border-top: 1px solid var(--border); }

/* \u2500\u2500\u2500 TYPOGRAPHY \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500 */
h1, h2, h3 {
    font-family: Arial Black, Arial, sans-serif;
    line-height: 1.1;
    text-transform: uppercase;
    letter-spacing: -0.01em;
}
h1 {
    font-size: clamp(2.4rem, 5vw, 4rem);
    color: var(--black);
    margin-bottom: 24px;
    font-weight: 900;
    letter-spacing: -0.02em;
    line-height: 1.05;
}
h1 span { color: var(--red); }
h2 { font-size: clamp(1.6rem, 3vw, 2.2rem); color: var(--red); margin-bottom: 24px; }
h3 { font-size: 1.05rem; color: var(--black); margin-bottom: 8px; letter-spacing: 0.03em; }
p { margin-bottom: 1.4em; }
p:last-child { margin-bottom: 0; }
.lede { font-size: 1.15rem; color: var(--mid-grey); max-width: 680px; }

/* \u2500\u2500\u2500 MAIN HEADER \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500 */
#site-header { background: var(--white); border-bottom: 3px solid var(--red); padding: 28px 0 24px; }

/* \u2500\u2500\u2500 MINI HEADER \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500 */
#mini-header {
    position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
    background: rgba(255,255,255,0.55);
    backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
    border-bottom: 2px solid rgba(212,0,0,0.5);
    padding: 8px 0;
    transform: translateY(-100%); opacity: 0;
    transition: transform 0.3s ease, opacity 0.3s ease;
}
#mini-header.visible { transform: translateY(0); opacity: 1; }
.mini-inner {
    display: flex; align-items: center; justify-content: space-between;
    max-width: var(--max); margin: 0 auto; padding: 0 32px;
}
.mini-inner a.mini-logo img { display: block; width: 38px; height: auto; }
#mini-header nav { column-gap: 24px; row-gap: 6px; }
#mini-header nav a { font-size: 0.75rem; }

.header-inner {
    display: flex; align-items: center; justify-content: space-between;
    gap: 40px; max-width: var(--max); margin: 0 auto; padding: 0 32px;
}
.logo-lockup { display: flex; align-items: center; gap: 20px; text-decoration: none; }
.logo-lockup:hover { text-decoration: none; }
.logo-lockup img { width: 90px; height: auto; flex-shrink: 0; }
.logo-text .snap {
    font-family: Arial Black, Arial, sans-serif;
    font-size: clamp(2rem, 5vw, 3.4rem); font-weight: 900;
    text-transform: uppercase; letter-spacing: -0.03em; line-height: 1;
}
.logo-text .snap em { color: var(--red); font-style: normal; }
.logo-text .snap strong { color: var(--black); font-weight: 900; }
.logo-text .tagline {
    font-family: Arial Black, Arial, sans-serif;
    font-size: clamp(0.65rem, 1.5vw, 0.85rem); font-weight: 900;
    text-transform: uppercase; letter-spacing: 0.04em;
    color: var(--dark-grey); margin-top: 4px;
}
.logo-text .tagline em { color: var(--red); font-style: normal; }

/* \u2500\u2500\u2500 NAVIGATION \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500 */
nav {
    display: grid; grid-template-columns: repeat(3, auto);
    column-gap: 32px; row-gap: 10px;
    margin-left: auto; text-align: right;
    align-items: start; justify-items: end;
}
nav a {
    font-family: Arial Black, Arial, sans-serif;
    font-size: 0.8rem; font-weight: 900; text-transform: uppercase;
    letter-spacing: 0.04em; color: var(--dark-grey);
    text-decoration: none; position: relative; padding-bottom: 4px;
    transition: color 0.15s;
}
nav a::after {
    content: ''; position: absolute; bottom: 0; left: 0;
    width: 0; height: 2px; background: var(--red); transition: width 0.3s ease;
}
nav a:hover { color: var(--black); text-decoration: none; }
nav a:hover::after { width: 100%; }
nav a.active { color: var(--black); }
nav a.active::after { width: 100%; }

/* \u2500\u2500\u2500 SUBPAGE SHARED \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500 */
.page-header { padding: 80px 0 64px; border-bottom: 1px solid var(--border); }
.page-header h1 { margin-bottom: 12px; }
.page-body { max-width: 820px; padding: 64px 0 96px; }
.page-body ul { margin: 0 0 1.4em 1.5em; }
.page-body ul li { margin-bottom: 0.6em; }
.callout {
    background: var(--light-grey); border-left: 4px solid var(--red);
    padding: 20px 24px; margin: 32px 0; font-size: 0.97rem;
}
.callout p { margin-bottom: 0.8em; }
.callout p:last-child { margin-bottom: 0; }
.updated {
    font-family: Arial, sans-serif; font-size: 0.8rem; color: var(--mid-grey);
    letter-spacing: 0.04em; margin-top: 56px; padding-top: 24px;
    border-top: 1px solid var(--border);
}

/* \u2500\u2500\u2500 FOOTER \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500 */
#site-footer { background: var(--black); border-top: 3px solid var(--red); padding: 32px 0; }
.footer-copy {
    font-family: 'Courier New', monospace; font-size: 0.68rem; color: #aaa;
    letter-spacing: 0.04em; line-height: 1; margin: 0; text-align: center;
}
.footer-copy a { color: var(--white); text-decoration: none; }
.footer-copy a:hover { text-decoration: underline; }

/* ─── HEADER VERSION BADGES ──────────────────────────────────────────────── */
.header-right {
    display: flex; align-items: flex-start; gap: 24px;
    margin-left: auto;
}
.header-build-badges {
    display: flex; flex-direction: column; gap: 5px; align-items: flex-end;
    flex-shrink: 0;
}
.header-badge {
    display: flex; align-items: center; gap: 0;
    font-family: 'Courier New', monospace;
    font-size: 0.62rem; font-weight: 700;
    letter-spacing: 0.04em; border-radius: 3px; overflow: hidden;
    white-space: nowrap;
}
.header-badge-track {
    padding: 3px 7px;
    text-transform: uppercase;
    color: #fff;
}
.header-badge-ver {
    padding: 3px 7px;
    background: var(--black);
    color: #fff;
    text-transform: uppercase;
}
.header-badge--boring .header-badge-track  { background: #555; }
.header-badge--bitchin .header-badge-track { background: var(--red); }

@media (max-width: 700px) {
    nav { column-gap: 16px; }
    nav a { font-size: 0.7rem; }
    .header-build-badges { display: none; }
}
<?php echo $_page_css_block; ?>
</style>
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
            <div class="header-build-badges">
                <div class="header-badge header-badge--boring">
                    <span class="header-badge-track">Boring</span>
                    <span class="header-badge-ver">v<?php echo SS_PROMO_VERSION; ?></span>
                </div>
                <div class="header-badge header-badge--bitchin">
                    <span class="header-badge-track">Bitchin'</span>
                    <span class="header-badge-ver">v<?php echo SS_PROMO_DEV_VERSION; ?></span>
                </div>
            </div>
        </div>
    </div>
</header>
<?php // ===== SNAPSMACK EOF =====
