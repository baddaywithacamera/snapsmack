<?php
/**
 * SnapSmack Core Meta
 * Version: 1.3.1 - CSS Load Order Fix
 * MASTER DIRECTIVE: Handle logic for SEO, CSS variables, and global headers.
 *
 * v1.3.0: Added favicon link output from settings.
 *         Added $exif_display_enabled variable for skin layouts.
 * v1.3.1: FIXED — Skin style.css now loads BEFORE dynamic compiled CSS.
 *         Previously style.css loaded last, overriding all skin admin
 *         customizations (fonts, sizes, transforms, etc.).
 */

// 1. PREPARE CSS VARIABLES (The Bridge)
$grid_gap = $settings['browse_gap'] ?? '100';
if (is_numeric($grid_gap)) {
    $grid_gap .= 'px';
}

// 2. PREPARE TITLES & SEO LOGIC
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

// 3. PREPARE SOCIAL META (Open Graph)
$og_title = $display_title;
$og_image = "";
if (!empty($page['image_asset'])) {
    $og_image = BASE_URL . ltrim($page['image_asset'], '/');
} elseif (!empty($settings['header_logo_url'])) {
    $og_image = BASE_URL . ltrim($settings['header_logo_url'], '/');
}

// 4. CANONICAL URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$canonical_url = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

// 5. EXIF DISPLAY FLAG — available to all skin layouts
$exif_display_enabled = (($settings['exif_display_enabled'] ?? '1') == '1');
?>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $display_title; ?></title>

<link rel="canonical" href="<?php echo $canonical_url; ?>">
<link rel="alternate" type="application/rss+xml" title="<?php echo $site_name; ?> RSS Feed" href="<?php echo BASE_URL; ?>rss.php" />

<?php
// 6. FAVICON — supports .ico, .png, .svg
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
<meta property="og:type" content="website">
<meta property="og:url" content="<?php echo $canonical_url; ?>">
<?php if (!empty($og_image)): ?>
<meta property="og:image" content="<?php echo $og_image; ?>">
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
 * declarations so skins can use them.
 */
$inventory_path = __DIR__ . '/manifest-inventory.php';
$skin_manifest_path = dirname(__DIR__) . '/skins/' . ($active_skin ?? 'new_horizon_dark') . '/manifest.php';

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
 * CSS LOAD ORDER (CRITICAL):
 *   1. public-facing.css    — global resets & shared layout
 *   2. @font-face           — local font declarations
 *   3. style.css            — skin baseline styles (defaults)
 *   4. dynamic compiled CSS — skin admin overrides (MUST WIN)
 *
 * The dynamic CSS must load LAST so that skin option panel
 * customizations (fonts, sizes, transforms, colors) override
 * the skin's hardcoded style.css defaults.
 */
?>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>skins/<?php echo $active_skin ?? 'new_horizon_dark'; ?>/style.css?v=<?php echo time(); ?>">

<?php if (!empty($settings['custom_css_public'])): ?>
<style id="snapsmack-dynamic-css">
<?php echo $settings['custom_css_public']; ?>
</style>
<?php endif; ?>
