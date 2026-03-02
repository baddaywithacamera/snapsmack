<?php
/**
 * SnapSmack Core Meta
 * Version: 1.2.0 - RSS Integration
 * MASTER DIRECTIVE: Handle logic for SEO, CSS variables, and global headers.
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
?>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $display_title; ?></title>

<link rel="canonical" href="<?php echo $canonical_url; ?>">
<link rel="alternate" type="application/rss+xml" title="<?php echo $site_name; ?> RSS Feed" href="<?php echo BASE_URL; ?>rss.php" />

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
 * declarations so skins can use them. Only loads fonts that the active
 * skin's manifest actually references (via font select options or CSS).
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
 * GOOGLE FONT LOADER
 * Scans active skin settings for font-family values, cross-references
 * against the inventory's Google font list, and outputs a single
 * combined <link> tag for all fonts in use. Local fonts are already
 * handled by the @font-face block above — this only fires for Google CDN fonts.
 */
if (file_exists($inventory_path) && file_exists($skin_manifest_path)) {
    if (!isset($inv)) { $inv = include $inventory_path; }
    $google_catalog = $inv['fonts'] ?? [];

    if (!empty($google_catalog)) {
        $skin_m = include $skin_manifest_path;
        $google_needed = [];

        // Walk manifest options looking for font-family selectors
        foreach (($skin_m['options'] ?? []) as $opt_key => $opt_meta) {
            if (($opt_meta['property'] ?? '') === 'font-family') {
                $active_val = ($settings[$opt_key] ?? '') !== '' ? $settings[$opt_key] : ($opt_meta['default'] ?? '');
                if ($active_val !== '' && isset($google_catalog[$active_val])) {
                    $google_needed[$active_val] = true;
                }
            }
        }

        if (!empty($google_needed)) {
            $families = [];
            foreach (array_keys($google_needed) as $fam) {
                $families[] = str_replace(' ', '+', $fam) . ':wght@400;700';
            }
            $gf_url = 'https://fonts.googleapis.com/css2?' . implode('&', array_map(fn($f) => "family={$f}", $families)) . '&display=swap';
            echo '<link rel="stylesheet" href="' . htmlspecialchars($gf_url) . '">' . "\n";
        }
    }
}
?>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>skins/<?php echo $active_skin ?? 'new_horizon_dark'; ?>/style.css?v=<?php echo time(); ?>">

<?php if (!empty($settings['custom_css_public'])): ?>
<style id="snapsmack-dynamic-css">
<?php echo $settings['custom_css_public']; ?>
</style>
<?php endif; ?>