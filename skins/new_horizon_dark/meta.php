<!DOCTYPE html>
<html lang="en">
<head>
    <?php 
    /**
     * SnapSmack Skin - Meta Loader
     * Version: 2.2 - Dynamic Inventory Integration
     * -------------------------------------------------------------------------
     * - ADDED: Dynamic Google Font Loader.
     * - FIXED: Pulls specific fonts from core/manifest-inventory.php based on 
     * active settings to optimize performance.
     * - RETAINED: Core Meta inclusion and architectural integrity.
     * -------------------------------------------------------------------------
     */
    include(dirname(__DIR__, 2) . '/core/meta.php'); 

    // 1. PULL INVENTORY TO MAP FRIENDLY NAMES TO SLUGS
    $font_inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');

    // 2. AGGREGATE ACTIVE CHOICES FROM DB SETTINGS
    $active_choices = array_unique([
        $settings['header_font_family'] ?? 'Playfair Display',
        $settings['static_heading_font'] ?? 'Inter',
        $settings['static_body_font'] ?? 'Inter',
        $settings['wall_font_ref'] ?? 'Playfair Display'
    ]);

    // 3. BUILD THE DYNAMIC GOOGLE FONT URL
    $font_query = [];
    foreach ($active_choices as $font) {
        // Ensure the font actually exists in our inventory before trying to load it
        if (isset($font_inventory[$font])) {
            $font_query[] = 'family=' . str_replace(' ', '+', $font) . ':wght@300;400;700;900';
        }
    }
    
    // Default fallback if query is somehow empty
    $google_url = !empty($font_query) 
        ? "https://fonts.googleapis.com/css2?" . implode('&', $font_query) . "&display=swap"
        : "https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap";
    ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="<?php echo $google_url; ?>" rel="stylesheet">

    <link rel="stylesheet" href="<?php echo BASE_URL; ?>skins/<?php echo $active_skin; ?>/style.css?v=<?php echo time(); ?>">
    <script src="<?php echo BASE_URL; ?>skins/<?php echo $active_skin; ?>/script.js" defer></script>

    <?php if (!empty($settings['custom_css_public'])): ?>
        <style id="snapsmack-custom-overrides">
            <?php echo $settings['custom_css_public']; ?>
        </style>
    <?php endif; ?>
</head>