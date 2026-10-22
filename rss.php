<?php
/**
 * SNAPSMACK - RSS 2.0 feed generator
 *
 * Dynamically generates an RSS feed of the most recently published images.
 * Formatted for compatibility with standard feed readers and the blogroll network.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once __DIR__ . '/core/db.php';

// --- SETTINGS LOADING ---
// Retrieves channel metadata from database
$settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
$settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$site_name = $settings['site_name'] ?? 'ISWA.CA';
$site_url  = rtrim($settings['site_url'] ?? 'https://example.com/', '/') . '/';
$site_desc = $settings['site_description'] ?? '3D Anaglyph Photography';

// Build one RSS row per published item.  Longform imports upload every body,
// cover, and signature image into snap_images; treating that media table as the
// feed made those supporting assets look like standalone posts.  The post model
// is authoritative when it exists, while genuinely unowned legacy photographs
// remain valid feed items.
$rss_stream_sql = "
    SELECT p.slug AS item_slug,
           COALESCE(NULLIF(p.title,''), i.img_title) AS item_title,
           i.img_file AS item_file,
           COALESCE(NULLIF(p.description,''), i.img_description, '') AS item_description,
           p.created_at AS item_date
      FROM snap_posts p
      JOIN snap_images i ON i.id=p.featured_image_id
     WHERE p.post_type='longform' AND p.status='published' AND p.created_at<=?
       AND i.img_status='published' AND i.img_date<=?
    UNION ALL
    SELECT p.slug AS item_slug,
           COALESCE(NULLIF(p.title,''), i.img_title) AS item_title,
           i.img_file AS item_file,
           COALESCE(NULLIF(p.description,''), i.img_description, '') AS item_description,
           p.created_at AS item_date
      FROM snap_posts p
      JOIN snap_post_images pi ON pi.post_id=p.id AND pi.is_cover=1
      JOIN snap_images i ON i.id=pi.image_id
     WHERE p.post_type<>'longform' AND p.status='published' AND p.created_at<=?
       AND i.img_status='published' AND i.img_date<=?
    UNION ALL
    SELECT i.img_slug AS item_slug, i.img_title AS item_title,
           i.img_file AS item_file, COALESCE(i.img_description,'') AS item_description,
           i.img_date AS item_date
      FROM snap_images i
     WHERE i.img_status='published' AND i.img_date<=? AND i.post_id IS NULL
       AND NOT EXISTS (SELECT 1 FROM snap_posts p
                        WHERE p.featured_image_id=i.id OR p.signature_image_id=i.id)
       AND NOT EXISTS (SELECT 1 FROM snap_bucket_items b WHERE b.image_id=i.id)
       AND NOT EXISTS (SELECT 1 FROM snap_images duplicate_media
                        WHERE duplicate_media.id<>i.id
                          AND duplicate_media.img_status='published'
                          AND duplicate_media.img_checksum IS NOT NULL
                          AND duplicate_media.img_checksum<>''
                          AND duplicate_media.img_checksum=i.img_checksum)
";

$post_count = 0;
$rss_rows = [];
try {
    $now_local = date('Y-m-d H:i:s');
    $params = array_fill(0, 5, $now_local);
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM ({$rss_stream_sql}) rss_items");
    $count_stmt->execute($params);
    $post_count = (int)$count_stmt->fetchColumn();
    $feed_stmt = $pdo->prepare("SELECT * FROM ({$rss_stream_sql}) rss_items ORDER BY item_date DESC LIMIT 20");
    $feed_stmt->execute($params);
    $rss_rows = $feed_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Upgrade compatibility for an install that has not received the post/bucket
    // schema yet.  The old image-only stream is safe there because those tables
    // cannot contain longform supporting media.
    $now_local = date('Y-m-d H:i:s');
    $feed_stmt = $pdo->prepare("SELECT img_slug AS item_slug,img_title AS item_title,
        img_file AS item_file,COALESCE(img_description,'') AS item_description,img_date AS item_date
        FROM snap_images WHERE img_status='published' AND img_date<=?
        ORDER BY sort_order ASC,id DESC LIMIT 20");
    $feed_stmt->execute([$now_local]);
    $rss_rows = $feed_stmt->fetchAll(PDO::FETCH_ASSOC);
    $post_count = (int)$pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status='published'")->fetchColumn();
}

// --- FEED SETUP ---
// Force XML content type for proper parsing in browsers and feed readers
header("Content-Type: application/rss+xml; charset=UTF-8");

echo '<?xml version="1.0" encoding="UTF-8" ?>';
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:snapsmack="https://snapsmack.ca/ns/rss">
<channel>
    <title><?php echo htmlspecialchars($site_name); ?></title>
    <link><?php echo $site_url; ?></link>
    <description><?php echo htmlspecialchars($site_desc); ?></description>
    <language>en-us</language>
    <atom:link href="<?php echo $site_url; ?>rss.php" rel="self" type="application/rss+xml" />
    <snapsmack:postCount><?php echo $post_count; ?></snapsmack:postCount>

    <?php
    // --- FEED ITEMS ---
    foreach ($rss_rows as $row) {
        $item_link = $site_url . ltrim($row['item_slug'], '/');
        $img_url   = $site_url . ltrim($row['item_file'], '/');
        $pub_date  = date(DATE_RSS, strtotime($row['item_date']));

        // Output individual items. Image HTML is wrapped in CDATA to prevent XML parsing errors.
        echo "<item>\n";
        echo "    <title>" . htmlspecialchars($row['item_title']) . "</title>\n";
        echo "    <link>" . $item_link . "</link>\n";
        echo "    <guid isPermaLink=\"true\">" . $item_link . "</guid>\n";
        echo "    <pubDate>" . $pub_date . "</pubDate>\n";
        echo "    <description><![CDATA[<img src=\"{$img_url}\" alt=\"" . htmlspecialchars($row['item_title'], ENT_QUOTES, 'UTF-8') . "\" /><br />" . ($row['item_description'] ?? '') . "]]></description>\n";
        echo "</item>\n";
    }
    ?>
</channel>
</rss>
<?php // ===== SNAPSMACK EOF =====
