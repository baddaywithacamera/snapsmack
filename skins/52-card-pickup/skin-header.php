<?php
/**
 * SNAPSMACK - Skin header for 52 Card Pickup
 * v1.0
 */

// Nav pages from DB
$nav_pages = $pdo->query("
    SELECT slug, title FROM snap_pages
    WHERE is_active = 1
    ORDER BY menu_order ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<header class="pickup-header">
    <a href="<?php echo BASE_URL; ?>" class="site-title-text"><?php echo htmlspecialchars($site_name); ?></a>

    <ul class="nav-menu">
        <li><a href="<?php echo BASE_URL; ?>archive">ARCHIVE</a></li>
        <?php foreach ($nav_pages as $np): ?>
            <li><a href="<?php echo BASE_URL . htmlspecialchars($np['slug']); ?>"><?php echo htmlspecialchars(strtoupper($np['title'])); ?></a></li>
        <?php endforeach; ?>
    </ul>
</header>
<?php // EOF
