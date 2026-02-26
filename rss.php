<?php
/**
 * SNAPSMACK - RSS feed generator.
 * Dynamically generates an RSS 2.0 XML feed of the most recently published images.
 * Formatted for compatibility with standard feed readers and the blogroll network.
 * Git Version Official Alpha 0.5
 */

require_once __DIR__ . '/core/db.php';

// Retrieve global settings to populate the RSS channel metadata.
$settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
$settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$site_name = $settings['site_name'] ?? 'ISWA.CA';
$site_url  = rtrim($settings['site_url'] ?? 'https://iswa.ca/', '/') . '/';
$site_desc = $settings['site_description'] ?? '3D Anaglyph Photography';

// Force XML content type so browsers and feed readers parse the output correctly.
header("Content-Type: application/rss+xml; charset=UTF-8");

echo '<?xml version="1.0" encoding="UTF-8" ?>';
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
    <title><?php echo htmlspecialchars($site_name); ?></title>
    <link><?php echo $site_url; ?></link>
    <description><?php echo htmlspecialchars($site_desc); ?></description>
    <language>en-us</language>
    <atom:link href="<?php echo $site_url; ?>rss.php" rel="self" type="application/rss+xml" />

    <?php
    // Retrieve the latest published images to populate the feed items. Limited to 20 to keep the XML payload light.
    $stmt = $pdo->query("SELECT * FROM snap_images WHERE img_status = 'published' AND img_date <= NOW() ORDER BY img_date DESC LIMIT 20");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $item_link = $site_url . $row['img_slug'];
        $img_url   = $site_url . ltrim($row['img_file'], '/');
        $pub_date  = date(DATE_RSS, strtotime($row['img_date']));
        
        // Output individual feed items. Image and description are wrapped in CDATA to prevent XML parsing errors.
        echo "<item>\n";
        echo "    <title>" . htmlspecialchars($row['img_title']) . "</title>\n";
        echo "    <link>" . $item_link . "</link>\n";
        echo "    <guid isPermaLink=\"true\">" . $item_link . "</guid>\n";
        echo "    <pubDate>" . $pub_date . "</pubDate>\n";
        echo "    <description><![CDATA[<img src=\"{$img_url}\" alt=\"{$row['img_title']}\" /><br />" . ($row['img_description'] ?? '') . "]]></description>\n";
        echo "</item>\n";
    }
    ?>
</channel>
</rss>