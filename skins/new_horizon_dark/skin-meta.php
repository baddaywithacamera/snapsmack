<?php
/**
 * New Horizon Dark — Skin Meta
 * Version: 5.1
 */

// 1. INCLUDE CORE META (handles SEO, public-facing.css, dynamic CSS)
include(dirname(__DIR__, 2) . '/core/meta.php');

// 2. SKIN BASE STYLESHEET
$skin_css_url = BASE_URL . 'skins/' . ($settings['active_skin'] ?? 'new_horizon_dark') . '/style.css';
?>
<link rel="stylesheet" href="<?php echo $skin_css_url; ?>?v=<?php echo time(); ?>">
