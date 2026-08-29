<?php
/**
 * SNAPSMACK.CA — Shared Page Header
 *
 * Include at the top of every page after setting:
 *   $page_title       — <title> content
 *   $page_description — meta description
 *   $page_og_url      — canonical og:url for this page
 *   $nav_active       — key matching a nav link: index|wotcha|bugger|tnb|hairy-muff|brass-tacks|reckoning|buzzers|oi
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
    $goods_open = str_starts_with($active, 'goods') ? ' active' : '';
    $rest_open  = in_array($active, ['bugger', 'tnb', 'hairy-muff', 'reckoning', 'buzzers', 'oi'], true) ? ' active' : '';
    return ss_nav_link('index.php',      'GAFF!',        'index',       $active) . "\n" .
           '            <details class="nav-group' . $goods_open . '"><summary>THE GOODS!</summary><div class="nav-flyout">' .
           ss_nav_link('features.php',   'THE GOODS!',   'goods',       $active) .
           ss_nav_link('skins.php',      'GLAD RAGS!',   'goods-skins', $active) .
           ss_nav_link('tools.php',      'BOX O\' TRICKS!', 'goods-tools', $active) .
           '</div></details>' . "\n" .
           ss_nav_link('wotcha.php',     'WOTCHA!',      'wotcha',      $active) . "\n" .
           ss_nav_link('brass-tacks.php','BRASS TACKS!', 'brass-tacks', $active) . "\n" .
           '            <details class="nav-group' . $rest_open . '"><summary>MORE BOLLOCKS!</summary><div class="nav-flyout">' .
           ss_nav_link('bugger.php',     'BUGGER!',         'bugger',    $active) .
           ss_nav_link('tnb.php',        'TWIG N BERRIES!', 'tnb',       $active) .
           ss_nav_link('hairy-muff.php', 'HAIRY MUFF!',     'hairy-muff',$active) .
           ss_nav_link('buzzers.php',    'BUZZERS!',        'buzzers',   $active) .
           ss_nav_link('the-reckoning.php', 'THE RECKONING!', 'reckoning', $active) .
           ss_nav_link('oi.php',         'OI THERE MATE!',  'oi',        $active) .
           '</div></details>';
};

$_page_css_block = isset($page_css) && $page_css !== '' ? "\n" . $page_css . "\n" : '';
$_canonical_url = $page_canonical ?? $page_og_url;
$_social_title = $page_social_title ?? $page_title;
$_social_description = $page_social_description ?? $page_description;
$_social_image = $page_social_image ?? 'https://snapsmack.ca/img/logo.png';
$_schema = [[
    '@context' => 'https://schema.org',
    '@type' => 'WebPage',
    'name' => $page_title,
    'description' => $page_description,
    'url' => $_canonical_url,
    'isPartOf' => ['@id' => 'https://snapsmack.ca/#website'],
]];
if ($_canonical_url === 'https://snapsmack.ca/') {
    array_unshift($_schema,
        [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => 'https://snapsmack.ca/#website',
            'name' => 'SnapSmack',
            'url' => 'https://snapsmack.ca/',
            'description' => 'Free self-hosted photo publishing software.',
        ],
        [
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => 'SnapSmack',
            'applicationCategory' => 'MultimediaApplication',
            'operatingSystem' => 'Linux',
            'description' => 'Free self-hosted photo publishing software for independent photographers.',
            'url' => 'https://snapsmack.ca/',
            'license' => 'https://github.com/baddaywithacamera/snapsmack/blob/master/licenses/SNAPSMACK-LICENSE.txt',
            'offers' => [
                '@type' => 'Offer',
                'price' => '0',
                'priceCurrency' => 'USD',
            ],
        ]
    );
}
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
<meta name="robots" content="index, follow">
<link rel="canonical" href="<?php echo htmlspecialchars($_canonical_url); ?>">

<!-- Favicon -->
<link rel="icon" type="image/png" href="ss_favicon.png">
<link rel="apple-touch-icon" href="ss_favicon.png">

<!-- Open Graph -->
<meta property="og:type" content="website">
<meta property="og:site_name" content="SnapSmack">
<meta property="og:url" content="<?php echo htmlspecialchars($_canonical_url); ?>">
<meta property="og:title" content="<?php echo htmlspecialchars($_social_title); ?>">
<meta property="og:description" content="<?php echo htmlspecialchars($_social_description); ?>">
<meta property="og:image" content="<?php echo htmlspecialchars($_social_image); ?>">
<meta property="og:image:width" content="1024">
<meta property="og:image:height" content="1024">
<meta property="og:image:alt" content="SnapSmack logo">

<!-- Twitter / X -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?php echo htmlspecialchars($_social_title); ?>">
<meta name="twitter:description" content="<?php echo htmlspecialchars($_social_description); ?>">
<meta name="twitter:image" content="<?php echo htmlspecialchars($_social_image); ?>">

<!-- Search-engine structured data; all claims are also visible on the site. -->
<script type="application/ld+json"><?php
echo json_encode($_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?></script>

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
        <a href="index.php" class="mini-logo"><img src="img/logo.png" alt="SnapSmack" width="1024" height="1024"></a>
        <input type="checkbox" id="ss-nav-mini" class="nav-toggle" aria-hidden="true">
        <label for="ss-nav-mini" class="nav-burger" role="button" aria-label="Toggle menu"></label>
        <nav>
<?php echo $_nav($nav_active); ?>
        </nav>
    </div>
</div>

<!-- MAIN HEADER -->
<header id="site-header">
    <div class="header-inner">
        <a href="index.php" class="logo-lockup">
            <img src="img/logo.png" alt="SnapSmack" width="1024" height="1024">
            <div class="logo-text">
                <div class="snap"><em>Snap</em><strong>Smack</strong></div>
                <div class="tagline">PHOTO <em>BLOGGING</em> IS BACK, BITCHEZ</div>
            </div>
        </a>
        <div class="header-right">
            <input type="checkbox" id="ss-nav-main" class="nav-toggle" aria-hidden="true">
            <label for="ss-nav-main" class="nav-burger" role="button" aria-label="Toggle menu"></label>
            <nav>
<?php echo $_nav($nav_active); ?>
            </nav>
        </div>
    </div>
</header>

<aside class="mobile-shame" aria-label="A note for mobile visitors">
    <strong>WELCOME TO THE ALL-YOU-CAN-EAT BAG OF DICKS.</strong>
    <span>The site works on phones. Dignity remains a desktop feature.</span>
</aside>
<?php // ===== SNAPSMACK EOF =====
