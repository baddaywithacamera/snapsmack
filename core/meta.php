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

<?php if (!empty($settings['custom_css_public'])): ?>
<style id="snapsmack-dynamic-css">
<?php echo $settings['custom_css_public']; ?>
</style>
<?php endif; ?>
