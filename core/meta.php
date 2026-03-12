<?php
/**
 * SNAPSMACK - SEO Meta Tags and CSS Variables
 * Alpha v0.7.2
 *
 * Generates canonical URLs, Open Graph tags, page titles, and favicon links.
 * Sets up CSS custom properties for grid gaps. Handles the critical CSS load
 * order: global resets → skin defaults → dynamic admin customizations.
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
if (!empty($page_title) && !$is_home) {
    $display_title = htmlspecialchars($page_title) . " | " . $site_name;
} else {
    $display_title = $site_name . $tagline;
}

// --- OPEN GRAPH META TAGS ---
// Priority chain for og:image:
//   1. Single image post ($img from index.php)
//   2. Static page hero image ($page_data from page.php)
//   3. Latest published image (homepage, archive, blogroll)
//   4. Site logo fallback
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

// If still empty, fetch the latest published image as the site preview
if (empty($og_image) && isset($pdo)) {
    try {
        $og_latest = $pdo->query("SELECT img_file FROM snap_images WHERE img_status = 'published' ORDER BY img_date DESC LIMIT 1")->fetchColumn();
        if ($og_latest) {
            $og_image = BASE_URL . ltrim($og_latest, '/');
        }
    } catch (Exception $e) {
        // Silently fall through to logo fallback
    }
}

// Final fallback: site logo
if (empty($og_image) && !empty($settings['header_logo_url'])) {
    $og_image = BASE_URL . ltrim($settings['header_logo_url'], '/');
}

// Site description fallback for og:description
// Prefer site_description (a dedicated bio sentence) over the tagline.
if (empty($og_description) && !empty($settings['site_description'])) {
    $og_description = $settings['site_description'];
} elseif (empty($og_description) && !empty($settings['site_tagline'])) {
    $og_description = $settings['site_tagline'];
}

// --- CANONICAL URL ---
// Prevent duplicate content issues in search engines
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$canonical_url = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

// --- EXIF DISPLAY FLAG ---
// Make this available to all skin layouts so they know whether to show
// camera/lens metadata
$exif_display_enabled = (($settings['exif_display_enabled'] ?? '1') == '1');
?>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $display_title; ?></title>

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

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/public-facing.css">

<?php
/**
 * LOCAL FONT LOADER
 * Reads local_fonts from manifest-inventory.php and outputs @font-face
 * declarations so skins can use custom fonts.
 */
$inventory_path = __DIR__ . '/manifest-inventory.php';
$skin_manifest_path = dirname(__DIR__) . '/skins/' . ($active_skin ?? 'new-horizon-dark') . '/manifest.php';

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
 *   3. style.css            — skin baseline styles (default appearance)
 *   4. dynamic compiled CSS — skin admin overrides (MUST WIN)
 *
 * The dynamic CSS must load LAST so user customizations from the skin option
 * panel (fonts, sizes, colors, transforms) override the skin's hardcoded
 * style.css defaults. This is why the order is critical.
 */
?>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>skins/<?php echo $active_skin ?? 'new-horizon-dark'; ?>/style.css?v=<?php echo time(); ?>">

<?php if (!empty($settings['custom_css_public'])): ?>
<style id="snapsmack-dynamic-css">
<?php echo $settings['custom_css_public']; ?>
</style>
<?php endif; ?>
