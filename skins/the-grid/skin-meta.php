<?php
/**
 * SNAPSMACK - The Grid Skin Meta
 * Alpha v0.7.4
 *
 * Page title tag. Included by core/meta.php.
 */
?>
<title><?php
    if (!empty($page_title)) {
        echo htmlspecialchars($page_title) . ' &mdash; ';
    }
    echo htmlspecialchars($settings['site_name'] ?? 'SnapSmack');
?></title>
