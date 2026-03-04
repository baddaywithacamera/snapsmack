<?php
/**
 * SNAPSMACK — Export Engine
 * v0.8
 *
 * All export logic: Recovery Kit, WordPress WXR, Portable JSON.
 * Called by smack-backup.php to generate downloadable archives.
 *
 * See docs/DESIGN-backup-recovery-export.md for full architecture.
 */

class SnapSmackExport {

    private PDO $pdo;
    private string $baseDir;
    private array $settings;

    public function __construct(PDO $pdo, string $baseDir) {
        $this->pdo = $pdo;
        $this->baseDir = rtrim($baseDir, '/');
        $this->settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                              ->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    // =================================================================
    // RECOVERY KIT EXPORT
    // =================================================================

    /**
     * Builds a .tar.gz recovery kit containing:
     * - manifest.json (file inventory with checksums and restore paths)
     * - database.sql (full SQL dump)
     * - branding/ (assets/img/ contents)
     * - media/ (media_assets/ contents)
     * - skin/ (active skin directory)
     *
     * @return string Path to the generated .tar.gz file
     * @throws Exception on failure
     */
    public function exportRecoveryKit(): string {
        $timestamp = date('Y-m-d_H-i');
        $prefix = "snapsmack_recovery_{$timestamp}";
        $tarPath = sys_get_temp_dir() . "/{$prefix}.tar";

        // Clean up any stale temp files
        @unlink($tarPath);
        @unlink($tarPath . '.gz');

        $archive = new PharData($tarPath);
        $manifest = [
            'snapsmack_version' => $this->settings['installed_version'] ?? '0.8',
            'export_date'       => date('c'),
            'export_type'       => 'recovery-kit',
            'site_name'         => $this->settings['site_name'] ?? '',
            'site_url'          => $this->settings['site_url'] ?? '',
            'active_skin'       => $this->settings['active_skin'] ?? 'new-horizon-dark',
            'active_variant'    => $this->settings['active_skin_variant'] ?? 'dark',
            'php_version'       => PHP_VERSION,
            'files'             => [],
            'stats'             => [
                'total_images'   => 0,
                'total_comments' => 0,
                'total_pages'    => 0,
                'branding_files' => 0,
                'media_files'    => 0,
                'skin_files'     => 0,
            ],
        ];

        // --- 1. SQL DUMP ---
        $sqlContent = $this->generateSqlDump();
        $archive->addFromString("{$prefix}/database.sql", $sqlContent);
        $manifest['files']['database.sql'] = [
            'size'     => strlen($sqlContent),
            'checksum' => 'sha256:' . hash('sha256', $sqlContent),
        ];

        // Gather stats from the SQL data
        try { $manifest['stats']['total_images']   = (int) $this->pdo->query("SELECT COUNT(*) FROM snap_images")->fetchColumn(); } catch (Exception $e) {}
        try { $manifest['stats']['total_comments'] = (int) $this->pdo->query("SELECT COUNT(*) FROM snap_comments")->fetchColumn(); } catch (Exception $e) {}
        try { $manifest['stats']['total_pages']    = (int) $this->pdo->query("SELECT COUNT(*) FROM snap_pages")->fetchColumn(); } catch (Exception $e) {}

        // --- 2. BRANDING ASSETS (assets/img/) ---
        $brandingDir = $this->baseDir . '/assets/img';
        if (is_dir($brandingDir)) {
            $manifest = $this->addDirectoryToArchive(
                $archive, $brandingDir,
                "{$prefix}/branding", 'assets/img',
                $manifest, 'branding_files'
            );
        }

        // --- 3. MEDIA LIBRARY (media_assets/) ---
        $mediaDir = $this->baseDir . '/media_assets';
        if (is_dir($mediaDir)) {
            $manifest = $this->addDirectoryToArchive(
                $archive, $mediaDir,
                "{$prefix}/media", 'media_assets',
                $manifest, 'media_files'
            );
        }

        // --- 4. ACTIVE SKIN ---
        $skinSlug = $this->settings['active_skin'] ?? 'new-horizon-dark';
        $skinDir  = $this->baseDir . '/skins/' . $skinSlug;
        if (is_dir($skinDir)) {
            $manifest = $this->addDirectoryToArchive(
                $archive, $skinDir,
                "{$prefix}/skin/{$skinSlug}", "skins/{$skinSlug}",
                $manifest, 'skin_files'
            );
        }

        // --- 5. WRITE MANIFEST ---
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $archive->addFromString("{$prefix}/manifest.json", $manifestJson);

        // --- 6. COMPRESS ---
        $gzPath = $archive->compress(Phar::GZ)->getPath();
        unset($archive);
        @unlink($tarPath);

        return $gzPath;
    }

    // =================================================================
    // WORDPRESS WXR EXPORT
    // =================================================================

    /**
     * Generates a WordPress eXtended RSS (WXR 1.2) export file.
     *
     * @return string The WXR XML content
     */
    public function exportWordPressWXR(): string {
        $siteName = htmlspecialchars($this->settings['site_name'] ?? 'SnapSmack Site');
        $siteUrl  = rtrim($this->settings['site_url'] ?? 'https://example.com', '/');
        $now      = date('D, d M Y H:i:s +0000');

        // Fetch all data
        $images     = $this->pdo->query("SELECT * FROM snap_images ORDER BY img_date DESC")->fetchAll(PDO::FETCH_ASSOC);
        $categories = $this->pdo->query("SELECT * FROM snap_categories ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $comments   = $this->pdo->query("SELECT * FROM snap_comments ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $pages      = $this->pdo->query("SELECT * FROM snap_pages ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

        // Albums → tags
        $albums = [];
        try { $albums = $this->pdo->query("SELECT * FROM snap_albums ORDER BY id")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

        // Image-category mapping
        $imgCatMap = [];
        try {
            $rows = $this->pdo->query("SELECT image_id, cat_id FROM snap_image_cat_map")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) { $imgCatMap[$r['image_id']][] = $r['cat_id']; }
        } catch (Exception $e) {}

        // Image-album mapping
        $imgAlbumMap = [];
        try {
            $rows = $this->pdo->query("SELECT image_id, album_id FROM snap_image_album_map")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) { $imgAlbumMap[$r['image_id']][] = $r['album_id']; }
        } catch (Exception $e) {}

        // Comments grouped by image
        $commentsByImg = [];
        foreach ($comments as $c) {
            $commentsByImg[$c['img_id']][] = $c;
        }

        // Category and album lookup maps
        $catLookup = [];
        foreach ($categories as $cat) { $catLookup[$cat['id']] = $cat; }
        $albumLookup = [];
        foreach ($albums as $alb) { $albumLookup[$alb['id']] = $alb; }

        // Build XML
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0"' . "\n";
        $xml .= '  xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"' . "\n";
        $xml .= '  xmlns:content="http://purl.org/rss/1.0/modules/content/"' . "\n";
        $xml .= '  xmlns:wfw="http://wellformedweb.org/CommentAPI/"' . "\n";
        $xml .= '  xmlns:dc="http://purl.org/dc/elements/1.1/"' . "\n";
        $xml .= '  xmlns:wp="http://wordpress.org/export/1.2/">' . "\n";
        $xml .= "<channel>\n";
        $xml .= "  <title>{$siteName}</title>\n";
        $xml .= "  <link>{$siteUrl}</link>\n";
        $xml .= "  <description>" . htmlspecialchars($this->settings['site_tagline'] ?? '') . "</description>\n";
        $xml .= "  <pubDate>{$now}</pubDate>\n";
        $xml .= "  <language>en-US</language>\n";
        $xml .= "  <wp:wxr_version>1.2</wp:wxr_version>\n";
        $xml .= "  <wp:base_site_url>{$siteUrl}</wp:base_site_url>\n";
        $xml .= "  <wp:base_blog_url>{$siteUrl}</wp:base_blog_url>\n";
        $xml .= "  <generator>SnapSmack Export Engine</generator>\n\n";

        // Categories
        foreach ($categories as $cat) {
            $xml .= "  <wp:category>\n";
            $xml .= "    <wp:term_id>{$cat['id']}</wp:term_id>\n";
            $xml .= "    <wp:category_nicename>" . htmlspecialchars($cat['cat_slug']) . "</wp:category_nicename>\n";
            $xml .= "    <wp:cat_name><![CDATA[" . ($cat['cat_name'] ?? '') . "]]></wp:cat_name>\n";
            if (!empty($cat['cat_description'])) {
                $xml .= "    <wp:category_description><![CDATA[" . $cat['cat_description'] . "]]></wp:category_description>\n";
            }
            $xml .= "  </wp:category>\n";
        }

        // Albums as tags
        foreach ($albums as $alb) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $alb['album_name'] ?? 'album'));
            $xml .= "  <wp:tag>\n";
            $xml .= "    <wp:term_id>" . ($alb['id'] + 10000) . "</wp:term_id>\n";
            $xml .= "    <wp:tag_slug>" . htmlspecialchars($slug) . "</wp:tag_slug>\n";
            $xml .= "    <wp:tag_name><![CDATA[" . ($alb['album_name'] ?? '') . "]]></wp:tag_name>\n";
            $xml .= "  </wp:tag>\n";
        }
        $xml .= "\n";

        // Post ID counter (WP needs unique IDs)
        $wpId = 1;

        // Images as posts
        foreach ($images as $img) {
            $postId = $wpId++;
            $attachId = $wpId++;
            $imgUrl = $siteUrl . '/' . ltrim($img['img_file'], '/');
            $wpStatus = ($img['img_status'] === 'published') ? 'publish' : 'draft';
            $pubDate = date('D, d M Y H:i:s +0000', strtotime($img['img_date']));

            // Build description content — include image tag for WP
            $content = '<img src="' . htmlspecialchars($imgUrl) . '" alt="' . htmlspecialchars($img['img_title']) . '" />';
            if (!empty($img['img_description'])) {
                $content .= "\n\n" . $img['img_description'];
            }

            // Attachment item (the image file)
            $xml .= "  <item>\n";
            $xml .= "    <title>" . htmlspecialchars($img['img_title']) . "</title>\n";
            $xml .= "    <link>{$siteUrl}/?attachment_id={$attachId}</link>\n";
            $xml .= "    <pubDate>{$pubDate}</pubDate>\n";
            $xml .= "    <dc:creator><![CDATA[admin]]></dc:creator>\n";
            $xml .= "    <wp:post_id>{$attachId}</wp:post_id>\n";
            $xml .= "    <wp:post_date>" . date('Y-m-d H:i:s', strtotime($img['img_date'])) . "</wp:post_date>\n";
            $xml .= "    <wp:post_type>attachment</wp:post_type>\n";
            $xml .= "    <wp:status>inherit</wp:status>\n";
            $xml .= "    <wp:post_parent>{$postId}</wp:post_parent>\n";
            $xml .= "    <wp:attachment_url>{$imgUrl}</wp:attachment_url>\n";
            $xml .= "    <wp:post_name>" . htmlspecialchars($img['img_slug']) . "-image</wp:post_name>\n";
            $xml .= "  </item>\n\n";

            // Post item (the write-up)
            $xml .= "  <item>\n";
            $xml .= "    <title>" . htmlspecialchars($img['img_title']) . "</title>\n";
            $xml .= "    <link>{$siteUrl}/" . htmlspecialchars($img['img_slug']) . "</link>\n";
            $xml .= "    <pubDate>{$pubDate}</pubDate>\n";
            $xml .= "    <dc:creator><![CDATA[admin]]></dc:creator>\n";
            $xml .= "    <content:encoded><![CDATA[{$content}]]></content:encoded>\n";
            $xml .= "    <wp:post_id>{$postId}</wp:post_id>\n";
            $xml .= "    <wp:post_date>" . date('Y-m-d H:i:s', strtotime($img['img_date'])) . "</wp:post_date>\n";
            $xml .= "    <wp:post_type>post</wp:post_type>\n";
            $xml .= "    <wp:status>{$wpStatus}</wp:status>\n";
            $xml .= "    <wp:post_name>" . htmlspecialchars($img['img_slug']) . "</wp:post_name>\n";

            // Featured image meta
            $xml .= "    <wp:postmeta>\n";
            $xml .= "      <wp:meta_key>_thumbnail_id</wp:meta_key>\n";
            $xml .= "      <wp:meta_value>{$attachId}</wp:meta_value>\n";
            $xml .= "    </wp:postmeta>\n";

            // EXIF as custom fields
            if (!empty($img['img_exif'])) {
                $exif = json_decode($img['img_exif'], true);
                if (is_array($exif)) {
                    foreach ($exif as $exifKey => $exifVal) {
                        if (!empty($exifVal) && $exifVal !== 'N/A') {
                            $xml .= "    <wp:postmeta>\n";
                            $xml .= "      <wp:meta_key>exif_" . htmlspecialchars($exifKey) . "</wp:meta_key>\n";
                            $xml .= "      <wp:meta_value><![CDATA[" . $exifVal . "]]></wp:meta_value>\n";
                            $xml .= "    </wp:postmeta>\n";
                        }
                    }
                }
            }

            // Categories
            $imgCats = $imgCatMap[$img['id']] ?? [];
            foreach ($imgCats as $catId) {
                if (isset($catLookup[$catId])) {
                    $cat = $catLookup[$catId];
                    $xml .= '    <category domain="category" nicename="' . htmlspecialchars($cat['cat_slug']) . '"><![CDATA[' . ($cat['cat_name'] ?? '') . ']]></category>' . "\n";
                }
            }

            // Albums as tags
            $imgAlbums = $imgAlbumMap[$img['id']] ?? [];
            foreach ($imgAlbums as $albId) {
                if (isset($albumLookup[$albId])) {
                    $alb = $albumLookup[$albId];
                    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $alb['album_name'] ?? 'album'));
                    $xml .= '    <category domain="post_tag" nicename="' . htmlspecialchars($slug) . '"><![CDATA[' . ($alb['album_name'] ?? '') . ']]></category>' . "\n";
                }
            }

            // Comments
            $imgComments = $commentsByImg[$img['id']] ?? [];
            foreach ($imgComments as $c) {
                $xml .= "    <wp:comment>\n";
                $xml .= "      <wp:comment_id>{$c['id']}</wp:comment_id>\n";
                $xml .= "      <wp:comment_author><![CDATA[" . ($c['comment_author'] ?? 'Anonymous') . "]]></wp:comment_author>\n";
                $xml .= "      <wp:comment_author_email>" . htmlspecialchars($c['comment_email'] ?? '') . "</wp:comment_author_email>\n";
                $xml .= "      <wp:comment_date>" . ($c['comment_date'] ?? date('Y-m-d H:i:s')) . "</wp:comment_date>\n";
                $xml .= "      <wp:comment_content><![CDATA[" . ($c['comment_text'] ?? '') . "]]></wp:comment_content>\n";
                $xml .= "      <wp:comment_approved>" . (($c['is_approved'] ?? 0) ? '1' : '0') . "</wp:comment_approved>\n";
                $xml .= "    </wp:comment>\n";
            }

            $xml .= "  </item>\n\n";
        }

        // Static pages
        foreach ($pages as $page) {
            $pageId = $wpId++;
            $xml .= "  <item>\n";
            $xml .= "    <title>" . htmlspecialchars($page['title']) . "</title>\n";
            $xml .= "    <link>{$siteUrl}/" . htmlspecialchars($page['slug']) . "</link>\n";
            $xml .= "    <dc:creator><![CDATA[admin]]></dc:creator>\n";
            $xml .= "    <content:encoded><![CDATA[" . ($page['content'] ?? '') . "]]></content:encoded>\n";
            $xml .= "    <wp:post_id>{$pageId}</wp:post_id>\n";
            $xml .= "    <wp:post_date>" . ($page['created_at'] ?? date('Y-m-d H:i:s')) . "</wp:post_date>\n";
            $xml .= "    <wp:post_type>page</wp:post_type>\n";
            $xml .= "    <wp:status>" . (($page['is_active'] ?? 1) ? 'publish' : 'draft') . "</wp:status>\n";
            $xml .= "    <wp:post_name>" . htmlspecialchars($page['slug']) . "</wp:post_name>\n";
            $xml .= "    <wp:menu_order>" . ($page['menu_order'] ?? 0) . "</wp:menu_order>\n";
            $xml .= "  </item>\n\n";
        }

        $xml .= "</channel>\n</rss>\n";
        return $xml;
    }

    // =================================================================
    // PORTABLE JSON EXPORT
    // =================================================================

    /**
     * Generates a portable JSON export with documented schema.
     * Not tied to any platform — clean, human-readable, complete.
     *
     * @return string JSON content
     */
    public function exportPortableJSON(): string {
        $siteUrl = rtrim($this->settings['site_url'] ?? '', '/');

        // Fetch all data
        $images     = $this->pdo->query("SELECT * FROM snap_images ORDER BY img_date DESC")->fetchAll(PDO::FETCH_ASSOC);
        $categories = $this->pdo->query("SELECT * FROM snap_categories ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $pages      = $this->pdo->query("SELECT * FROM snap_pages ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $comments   = $this->pdo->query("SELECT * FROM snap_comments ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

        $albums = [];
        try { $albums = $this->pdo->query("SELECT * FROM snap_albums ORDER BY id")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
        $blogroll = [];
        try { $blogroll = $this->pdo->query("SELECT * FROM snap_blogroll ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

        // Mappings
        $imgCatMap = [];
        try {
            $rows = $this->pdo->query("SELECT icm.image_id, c.cat_slug FROM snap_image_cat_map icm JOIN snap_categories c ON c.id = icm.cat_id")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) { $imgCatMap[$r['image_id']][] = $r['cat_slug']; }
        } catch (Exception $e) {}

        $imgAlbumMap = [];
        try {
            $rows = $this->pdo->query("SELECT iam.image_id, a.album_name FROM snap_image_album_map iam JOIN snap_albums a ON a.id = iam.album_id")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) { $imgAlbumMap[$r['image_id']][] = $r['album_name']; }
        } catch (Exception $e) {}

        $commentsByImg = [];
        foreach ($comments as $c) {
            $commentsByImg[$c['img_id']][] = $c;
        }

        // Build export
        $export = [
            'export_format'  => 'snapsmack-portable',
            'format_version' => '1.0',
            'exported'       => date('c'),
            'site' => [
                'name'    => $this->settings['site_name'] ?? '',
                'url'     => $siteUrl,
                'tagline' => $this->settings['site_tagline'] ?? '',
            ],
            'images' => [],
            'categories' => [],
            'albums' => [],
            'pages' => [],
            'blogroll' => [],
        ];

        foreach ($images as $img) {
            $exif = [];
            if (!empty($img['img_exif'])) {
                $decoded = json_decode($img['img_exif'], true);
                if (is_array($decoded)) $exif = $decoded;
            }

            $imgComments = [];
            foreach ($commentsByImg[$img['id']] ?? [] as $c) {
                $imgComments[] = [
                    'author'   => $c['comment_author'] ?? 'Anonymous',
                    'email'    => $c['comment_email'] ?? '',
                    'text'     => $c['comment_text'] ?? '',
                    'date'     => $c['comment_date'] ?? '',
                    'approved' => (bool)($c['is_approved'] ?? false),
                ];
            }

            $export['images'][] = [
                'id'          => (int) $img['id'],
                'title'       => $img['img_title'],
                'slug'        => $img['img_slug'],
                'description' => $img['img_description'] ?? '',
                'date'        => $img['img_date'],
                'status'      => $img['img_status'],
                'file_url'    => $siteUrl . '/' . ltrim($img['img_file'], '/'),
                'file_path'   => $img['img_file'],
                'width'       => (int)($img['img_width'] ?? 0),
                'height'      => (int)($img['img_height'] ?? 0),
                'exif'        => $exif,
                'categories'  => $imgCatMap[$img['id']] ?? [],
                'albums'      => $imgAlbumMap[$img['id']] ?? [],
                'comments'    => $imgComments,
            ];
        }

        foreach ($categories as $cat) {
            $export['categories'][] = [
                'id'          => (int) $cat['id'],
                'name'        => $cat['cat_name'],
                'slug'        => $cat['cat_slug'],
                'description' => $cat['cat_description'] ?? '',
            ];
        }

        foreach ($albums as $alb) {
            $export['albums'][] = [
                'id'          => (int) $alb['id'],
                'name'        => $alb['album_name'],
                'description' => $alb['album_description'] ?? '',
            ];
        }

        foreach ($pages as $page) {
            $export['pages'][] = [
                'id'      => (int) $page['id'],
                'slug'    => $page['slug'],
                'title'   => $page['title'],
                'content' => $page['content'] ?? '',
                'active'  => (bool)($page['is_active'] ?? true),
            ];
        }

        foreach ($blogroll as $b) {
            $export['blogroll'][] = [
                'name'        => $b['peer_name'],
                'url'         => $b['peer_url'],
                'description' => $b['peer_desc'] ?? '',
                'rss'         => $b['peer_rss'] ?? '',
            ];
        }

        return json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // =================================================================
    // SQL DUMP (reusable — extracted from smack-backup.php)
    // =================================================================

    /**
     * Generates a full SQL dump of all SnapSmack tables.
     * Reused by Recovery Kit and the existing backup page.
     *
     * @param string $type  'full', 'schema', or 'keys'
     * @return string SQL content
     */
    public function generateSqlDump(string $type = 'full'): string {
        $output = "-- SnapSmack Backup Service\n";
        $output .= "-- Type: " . strtoupper($type) . "\n";
        $output .= "-- Date: " . date('Y-m-d H:i:s') . "\n\n";

        $tables = ($type === 'keys')
            ? ['snap_users']
            : ['snap_images', 'snap_categories', 'snap_image_cat_map',
               'snap_image_album_map', 'snap_albums', 'snap_comments',
               'snap_users', 'snap_settings', 'snap_pages', 'snap_blogroll',
               'snap_assets'];

        foreach ($tables as $table) {
            try {
                $res = $this->pdo->query("SHOW CREATE TABLE {$table}")->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // Table might not exist on older schemas — skip gracefully
                continue;
            }

            $output .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $output .= $res['Create Table'] . ";\n\n";

            if ($type !== 'schema') {
                $rows = $this->pdo->query("SELECT * FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $keys = array_map(fn($k) => "`{$k}`", array_keys($row));
                    $vals = array_map(fn($v) => $v === null ? "NULL" : $this->pdo->quote($v), array_values($row));
                    $output .= "INSERT INTO `{$table}` (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $vals) . ");\n";
                }
                $output .= "\n";
            }
        }

        return $output;
    }

    // =================================================================
    // HELPERS
    // =================================================================

    /**
     * Adds all files from a directory to the tar archive, updating the manifest.
     *
     * @param PharData $archive      The tar archive
     * @param string   $sourceDir    Absolute path to source directory
     * @param string   $archivePrefix  Path prefix inside the archive
     * @param string   $restorePrefix  Path prefix for restores_to (relative to site root)
     * @param array    $manifest     Current manifest array (modified in place)
     * @param string   $statKey      Key in manifest.stats to increment
     * @return array Updated manifest
     */
    private function addDirectoryToArchive(
        PharData $archive,
        string $sourceDir,
        string $archivePrefix,
        string $restorePrefix,
        array $manifest,
        string $statKey
    ): array {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) continue;

            $realPath = $file->getRealPath();
            $relativePath = substr($realPath, strlen($sourceDir) + 1);
            $relativePath = str_replace('\\', '/', $relativePath);

            // Skip hidden files and temp files
            if (str_starts_with(basename($relativePath), '.')) continue;

            $archivePath = $archivePrefix . '/' . $relativePath;
            $restorePath = $restorePrefix . '/' . $relativePath;

            $archive->addFile($realPath, $archivePath);

            // Strip the archive prefix to get the manifest key
            $manifestKey = substr($archivePath, strpos($archivePath, '/') + 1);
            // Actually, find after the first prefix/
            $parts = explode('/', $archivePath, 2);
            $manifestKey = $parts[1] ?? $archivePath;

            $manifest['files'][$manifestKey] = [
                'size'        => $file->getSize(),
                'checksum'    => 'sha256:' . hash_file('sha256', $realPath),
                'restores_to' => $restorePath,
            ];
            $manifest['stats'][$statKey]++;
        }

        return $manifest;
    }
}
