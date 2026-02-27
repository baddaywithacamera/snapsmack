<?php
/**
 * SnapSmack Core Meta
 * Version: 1.3.0 - Local Font Inventory Integration
 * MASTER DIRECTIVE: Handle logic for SEO, CSS variables, and global headers.
 * ADDED: @font-face output loop from manifest-inventory local_fonts block.
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
// OUTPUT @font-face FOR ALL LOCAL FONTS IN INVENTORY
// Any font added to manifest-inventory.php local_fonts is automatically
// declared here for every public page. No per-skin or per-template work needed.
$_inventory    = include(dirname(__DIR__) . '/core/manifest-inventory.php');
$_local_fonts  = $_inventory['local_fonts'] ?? [];
if (!empty($_local_fonts)):
?>
<style id="snapsmack-local-fonts">
<?php foreach ($_local_fonts as $family => $meta): ?>
@font-face {
    font-family: '<?php echo htmlspecialchars($family); ?>';
    src: url('<?php echo BASE_URL . htmlspecialchars($meta['file']); ?>') format('<?php echo htmlspecialchars($meta['format']); ?>');
    font-weight: <?php echo htmlspecialchars($meta['weight'] ?? 'normal'); ?>;
    font-style: <?php echo htmlspecialchars($meta['style'] ?? 'normal'); ?>;
    font-display: swap;
}
<?php endforeach; ?>
</style>
<?php endif; ?>