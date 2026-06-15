<?php
/**
 * SNAPSMACK - SEO Meta Tags and CSS Variables
 *
 * Generates canonical URLs, Open Graph tags, page titles, and favicon links.
 * Sets up CSS custom properties for grid gaps. Handles the critical CSS load
 * order: global resets → skin defaults → dynamic admin customizations.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */




// --- CSS VARIABLES (GRID GAP) ---
// Set --grid-gap as a CSS custom property for consistent spacing across skins
$grid_gap = $settings['browse_gap'] ?? '100';
if (is_numeric($grid_gap)) {
    $grid_gap .= 'px';
}

// --- PAGE TITLE AND SEO LOGIC ---
// Determine if we're on the homepage, then build the page title
$current_script = basename($_SERVER['SCRIPT_NAME']);
$is_home = in_array($current_script, ['index.php', 'archive.php']) && empty($_GET['slug']) && empty($requested_slug);

$site_name = htmlspecialchars($settings['site_name'] ?? 'ISWA.CA');
$tagline = !empty($settings['site_tagline']) ? " | " . htmlspecialchars($settings['site_tagline']) : "";

// Build the Title String
// SEO: an optional seo_title_template ('{page} — {site}') controls the
// per-page title format. {page} = current page title, {site} = site name.
// Falls back to the classic "Page | Site" when no template is set.
if (!empty($page_title) && !$is_home) {
    if (!empty($settings['seo_title_template'])) {
        $display_title = htmlspecialchars(strtr($settings['seo_title_template'], [
            '{page}' => $page_title,
            '{site}' => $settings['site_name'] ?? 'ISWA.CA',
        ]));
    } else {
        $display_title = htmlspecialchars($page_title) . " | " . $site_name;
    }
} else {
    $display_title = $site_name . $tagline;
}

// --- OPEN GRAPH META TAGS ---
// Priority chain for og:image (Sean's rule 2026-06-15): ONLY a deliberately
// chosen image is ever used as the social preview. No logo, no latest-image
// auto-pick. Order:
//   1. Single image post ($img from index.php)
//   2. Static page hero image ($page_data from page.php)
//   3. Explicit OG Image Override setting (a deliberately picked default)
//   else: no og:image at all.
$og_title = $display_title;
$og_description = '';
$og_image = '';
$og_type = 'website';

if (!empty($img['img_file'])) {
    // Single image view — use the post image
    $og_image = BASE_URL . ltrim($img['img_file'], '/');
    $og_type  = 'article';
    if (!empty($img['img_description'])) {
        $og_description = mb_substr(strip_tags($img['img_description']), 0, 200);
    }
} elseif (!empty($page_data['image_asset'])) {
    // Static page with a hero image
    $og_image = BASE_URL . ltrim($page_data['image_asset'], '/');
} elseif (!empty($page['image_asset'])) {
    // Legacy fallback for skin-specific meta overrides
    $og_image = BASE_URL . ltrim($page['image_asset'], '/');
}

// Explicit OG image override — a deliberately chosen site-wide default, used
// only when the page has no image of its own. A specific post/page image above
// still wins so individual shares keep their own image.
if (empty($og_image) && !empty($settings['og_image_override'])) {
    $og_image = BASE_URL . ltrim($settings['og_image_override'], '/');
}

// Deliberate: NO logo fallback and NO latest-image auto-pick. If nothing
// specific was chosen, og:image is simply omitted (Sean's rule 2026-06-15).

// Site description fallback for og:description
// Prefer site_description (a dedicated bio sentence) over the tagline.
if (empty($og_description) && !empty($settings['site_description'])) {
    $og_description = $settings['site_description'];
} elseif (empty($og_description) && !empty($settings['site_tagline'])) {
    $og_description = $settings['site_tagline'];
}

// --- META DESCRIPTION (SEO) ---
// A dedicated <meta name="description">. Page-specific content description wins;
// then the dedicated meta_description setting; then the og:description fallbacks
// already computed (site_description ?: tagline).
if (!empty($img['img_description'])) {
    $meta_description = mb_substr(strip_tags($img['img_description']), 0, 200);
} elseif (!empty($settings['meta_description'])) {
    $meta_description = $settings['meta_description'];
} else {
    $meta_description = $og_description;
}

// --- CANONICAL URL ---
// Prevent duplicate content issues in search engines
$protocol = snap_is_https() ? "https://" : "http://";
$canonical_url = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

// --- EXIF DISPLAY FLAG ---
// Make this available to all skin layouts so they know whether to show
// camera/lens metadata
$exif_display_enabled = (($settings['exif_display_enabled'] ?? '1') == '1');
?>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php
// --- AI TRAINING META DIRECTIVE ---
// Belt-and-suspenders alongside robots.txt. Tells AI crawlers that respect
// the noai/noimageai directives whether this content is available for training.
$ai_policy = $settings['ai_training_policy'] ?? 'no_opinion';
if ($ai_policy === 'disallow'): ?>
<meta name="robots" content="noai, noimageai">
<?php endif; ?>
<title><?php echo $display_title; ?></title>
<?php if (!empty($meta_description)): ?>
<meta name="description" content="<?php echo htmlspecialchars($meta_description); ?>">
<?php endif; ?>

<link rel="canonical" href="<?php echo $canonical_url; ?>">
<link rel="alternate" type="application/rss+xml" title="<?php echo $site_name; ?> RSS Feed" href="<?php echo BASE_URL; ?>rss.php" />

<?php
// --- FAVICON SUPPORT ---
// Support .ico, .png, and .svg favicons with appropriate MIME types
if (!empty($settings['favicon_url'])):
    $fav_path = $settings['favicon_url'];
    $fav_ext  = strtolower(pathinfo($fav_path, PATHINFO_EXTENSION));
    if ($fav_ext === 'svg'):
?>
<link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL . ltrim($fav_path, '/'); ?>">
<?php elseif ($fav_ext === 'png'): ?>
<link rel="icon" type="image/png" href="<?php echo BASE_URL . ltrim($fav_path, '/'); ?>">
<?php else: ?>
<link rel="icon" type="image/x-icon" href="<?php echo BASE_URL . ltrim($fav_path, '/'); ?>">
<?php endif; ?>
<?php endif; ?>

<meta property="og:site_name" content="<?php echo $site_name; ?>">
<meta property="og:title" content="<?php echo $og_title; ?>">
<meta property="og:type" content="<?php echo $og_type; ?>">
<meta property="og:url" content="<?php echo $canonical_url; ?>">
<?php if (!empty($og_description)): ?>
<meta property="og:description" content="<?php echo htmlspecialchars($og_description); ?>">
<?php endif; ?>
<?php if (!empty($og_image)): ?>
<meta property="og:image" content="<?php echo $og_image; ?>">
<meta property="og:image:width" content="1200">
<?php endif; ?>

<meta name="twitter:card" content="<?php echo !empty($og_image) ? 'summary_large_image' : 'summary'; ?>">
<meta name="twitter:title" content="<?php echo $og_title; ?>">
<?php if (!empty($og_description)): ?>
<meta name="twitter:description" content="<?php echo htmlspecialchars($og_description); ?>">
<?php endif; ?>
<?php if (!empty($og_image)): ?>
<meta name="twitter:image" content="<?php echo $og_image; ?>">
<?php endif; ?>

<style id="snapsmack-core-vars">
    :root {
        --grid-gap: <?php echo $grid_gap; ?>;
    }
</style>

<?php
/**
 * 0.7.81 architecture: split CSS by responsibility.
 *   - public-base.css always loads (utilities, alignment, image fade engine)
 *   - page-*.css loads only on its target page (smaller surface, less churn)
 *
 * Page detection runs against the executing script's basename; falls back
 * to no page-specific load if the page isn't recognised. Skin's style.css
 * still loads after, free to override anything below.
 *
 * public-facing.css lingers as a backwards-compat shim that @imports the
 * splits — old inclusions keep working until their references are cleaned
 * up.
 */
$_snap_page = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
$_snap_page_css = [
    'archive'     => 'page-archive.css',
    'collection'  => 'page-collection.css',
    'collections' => 'page-collection.css',
    'blogroll'    => 'page-blogroll.css',
    'page'        => 'page-static.css',
    'index'       => 'page-static.css',
];
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/public-base.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
<?php if (isset($_snap_page_css[$_snap_page])): ?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/<?php echo $_snap_page_css[$_snap_page]; ?>?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
<?php endif; ?>
<?php
// shortcodes.css loads on pages that run parseContent() — page.php and blog.php.
// Safe on all install types; carousel installs simply won't use the shortcodes.
if (in_array($_snap_page, ['page', 'blog', 'index'])): ?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/shortcodes.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-mosaic.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-mosaic.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>" defer></script>

<?php
/**
 * LOCAL FONT LOADER
 * Reads local_fonts from manifest-inventory.php and outputs @font-face
 * declarations so skins can use custom fonts.
 */
$inventory_path = __DIR__ . '/manifest-inventory.php';
$skin_manifest_path = dirname(__DIR__) . '/skins/' . ($active_skin ?? '50-shades-of-noah-grey') . '/manifest.php';

if (file_exists($inventory_path)) {
    $inv = include $inventory_path;
    $local_fonts = $inv['local_fonts'] ?? [];

    if (!empty($local_fonts)) {
        echo '<style id="snapsmack-local-fonts">' . "\n";
        foreach ($local_fonts as $family => $font) {
            $file_url = BASE_URL . ltrim($font['file'], '/');
            $format   = $font['format'] ?? 'truetype';
            $weight   = $font['weight'] ?? 'normal';
            $style    = $font['style'] ?? 'normal';
            echo "@font-face {\n";
            echo "  font-family: '{$family}';\n";
            echo "  src: url('{$file_url}') format('{$format}');\n";
            echo "  font-weight: {$weight};\n";
            echo "  font-style: {$style};\n";
            echo "  font-display: swap;\n";
            echo "}\n";
        }
        echo '</style>' . "\n";
    }
}
?>

<?php
/**
 * CSS LOAD ORDER (CRITICAL FOR CUSTOMIZATIONS)
 *   1. public-facing.css    — global resets and shared layout
 *   2. @font-face           — local font declarations
 *   3. Engine CSS           — JS engine stylesheets (must be in <head>, not body)
 *   4. style.css            — skin baseline styles (default appearance)
 *   5. dynamic compiled CSS — skin admin overrides (MUST WIN)
 *
 * Engine CSS is loaded here (head) so it's available before first paint.
 * Engine JS stays in skin-footer.php (end of body) for performance.
 * CSS in the body causes browsers to re-render mid-paint — always put it here.
 */

// Load engine CSS for all scripts required by the active skin.
// Mirrors the script loop in skin-footer.php but outputs only CSS links.
if (!isset($skin_manifest_path)) {
    $skin_manifest_path = dirname(__DIR__) . '/skins/' . ($active_skin ?? '50-shades-of-noah-grey') . '/manifest.php';
}
if (file_exists($skin_manifest_path)) {
    try {
        $_skin_mf = include $skin_manifest_path;
        if (!is_array($_skin_mf)) $_skin_mf = [];
    } catch (\Throwable $e) {
        $_skin_mf = [];
        error_log("SnapSmack: failed to load manifest {$skin_manifest_path} — " . $e->getMessage());
    }
    $_inventory = include __DIR__ . '/manifest-inventory.php';
    foreach ($_skin_mf['require_scripts'] ?? [] as $_handle) {
        $_entry = $_inventory['scripts'][$_handle] ?? [];
        // CSS only here — emit each engine stylesheet in <head> so it's
        // available before first paint. The matching <script> tag is emitted
        // by the active skin's skin-footer.php at end of body. Emitting it
        // here too would double-load every engine (and silently double its
        // DOM, which is exactly how the duplicate-calendar bug got created).
        if (!empty($_entry['css'])) {
            echo '<link rel="stylesheet" href="' . BASE_URL
                . $_entry['css']
                . '?v=' . SNAPSMACK_VERSION_SHORT . '">' . "\n";
        }
    }
    $_skin_css_version = SNAPSMACK_VERSION_SHORT . (!empty($_skin_mf['version']) ? '-' . $_skin_mf['version'] : '');
    unset($_skin_mf, $_inventory, $_handle, $_entry);
} else {
    $_skin_css_version = SNAPSMACK_VERSION_SHORT;
}
?>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>skins/<?php echo $active_skin ?? '50-shades-of-noah-grey'; ?>/style.css?v=<?php echo $_skin_css_version; ?>">

<?php
/**
 * SKIN VARIANT STYLESHEET HOOK
 * If a skin sets $skin_variant_url before including meta.php, the variant
 * loads here — after style.css but BEFORE the dynamic compiled CSS.
 * This ensures admin customizations in dynamic CSS always override variants.
 */
if (!empty($skin_variant_url)): ?>
<link rel="stylesheet" href="<?php echo $skin_variant_url; ?>?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
<?php endif; ?>

<?php if (!empty($settings['custom_css_public'])): ?>
<style id="snapsmack-dynamic-css">
<?php echo $settings['custom_css_public']; ?>
</style>
<?php endif; ?>

<?php if (!empty($settings['static_content_width']) || isset($settings['static_content_gutter'])): ?>
<style id="snapsmack-layout-vars">
:root {
<?php if (!empty($settings['static_content_width'])): ?>
    --static-content-width: <?php echo (int)$settings['static_content_width']; ?>px;
<?php endif; ?>
<?php if (isset($settings['static_content_gutter'])): ?>
    --static-content-gutter: <?php echo (int)$settings['static_content_gutter']; ?>px;
<?php endif; ?>
}
</style>
<?php endif; ?>

<?php
/**
 * SMACK_CONFIG — JS engine configuration object.
 * Emitted before body scripts so engines can read settings at init time.
 * Add keys here as new engine options are introduced.
 */
$_smack_js_config = [];

// Lightbox backdrop opacity (set via skin manifest 'lightbox_bg_opacity' option)
if (!empty($settings['lightbox_bg_opacity'])) {
    $_smack_js_config['lightbox'] = [
        'opacity' => (string) round(intval($settings['lightbox_bg_opacity']) / 100, 2)
    ];
}

// Calendar sidebar settings — always output so the JS engine respects Archive
// Appearance settings regardless of whether the skin lists smack-calendar in
// require_scripts. The JS only reads this if it's loaded; no harm if it's not.
$_smack_js_config['calendar'] = [
    'side'      => $settings['calendar_side']       ?? 'right',
    'months'    => (int)($settings['calendar_months']     ?? 1),
    'postCount' => (int)($settings['calendar_post_count'] ?? 10),
    'endpoint'  => BASE_URL . 'api-calendar.php',
];

if (!empty($_smack_js_config)):
?>
<script>window.SMACK_CONFIG = <?php echo json_encode($_smack_js_config, JSON_UNESCAPED_UNICODE); ?>;</script>
<?php endif; ?>

<?php if (!empty($settings['nav_menu_json']) && $settings['nav_menu_json'] !== '[]'): ?>
<style id="snapsmack-nav-vars">
:root {
    --nav-dropdown-bg: <?php echo htmlspecialchars($settings['nav_dropdown_bg'] ?? '#000000'); ?>;
    --nav-dropdown-text: <?php echo htmlspecialchars($settings['nav_dropdown_text'] ?? '#ffffff'); ?>;
}
</style>
<?php endif; ?>

<?php
/**
 * THIRD-PARTY HEAD SCRIPTS
 * Injected from Smack Your Scripts Up! admin page.
 * Loads after all CSS so tracking/analytics scripts don't block rendering.
 */
// Head scripts stored in a file so SMACKBACK can watch for tampering.
// Falls back to DB for installs that have not yet re-saved via smack-scripts.php.
$_custom_head_file = dirname(__DIR__) . '/data/custom-head.html';
if (file_exists($_custom_head_file) && ($__head = file_get_contents($_custom_head_file)) !== false && trim($__head) !== ''):
    echo $__head;
elseif (!empty($settings['custom_head_scripts'])):
    echo $settings['custom_head_scripts'];
endif;
unset($_custom_head_file, $__head);
// ===== SNAPSMACK EOF =====
