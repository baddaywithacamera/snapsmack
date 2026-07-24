<?php
/**
 * SNAPSMACK — Export Engine
 *
 * All export logic: Recovery Kit, WordPress WXR, Portable JSON.
 * Called by smack-backup.php to generate downloadable archives.
 *
 * See docs/DESIGN-backup-recovery-export.md for full architecture.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
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
     * - manifest.json (complete file inventory with checksums, sizes, and restore paths)
     * - database.sql (full SQL dump)
     *
     * Media files are NOT bundled — only inventoried in the manifest with SHA-256
     * checksums and byte-exact sizes so they can be verified or restored from the
     * live filesystem or a separate media backup. This keeps the kit small enough
     * to download, email, push to cloud, or FTP even on large media libraries.
     *
     * @return string Path to the generated .tar.gz file
     * @throws Exception on failure
     */
    public function exportRecoveryKit(): string {
        $timestamp = date('Y-m-d_H-i');
        // Sanitise site name for use in a filename — strip non-alphanum chars
        $siteName  = $this->settings['site_name'] ?? '';
        $siteSlug  = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($siteName));
        $siteSlug  = trim($siteSlug, '_');
        $prefix    = $siteSlug
            ? "snapsmack_{$siteSlug}_{$timestamp}"
            : "snapsmack_recovery_{$timestamp}";
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
            'site_mode'         => $this->settings['site_mode'] ?? 'photoblog',
            'site_uuid'         => (string)($this->settings['site_uuid'] ?? ''),
            'active_skin'       => $this->settings['active_skin'] ?? '50-shades-of-noah-grey',
            'active_variant'    => $this->settings['active_skin_variant'] ?? 'dark',
            'php_version'       => PHP_VERSION,
            'files'               => [],
            'directory_structure' => [],
            'database_images'    => [],
            'stats'              => [
                'total_images'   => 0,
                'total_comments' => 0,
                'total_pages'    => 0,
                'branding_files' => 0,
                'media_files'    => 0,
                'skin_files'     => 0,
                'upload_files'   => 0,
                'total_media_bytes' => 0,
            ],
        ];

        // --- 1. SQL DUMP (only file actually bundled) ---
        // generateSqlDump() get-or-creates site_uuid; capture it for the manifest
        // so the kit is bound to its origin site + mode (cross-mode restore guard).
        $sqlContent = $this->generateSqlDump();
        if ($manifest['site_uuid'] === '') {
            try {
                $manifest['site_uuid'] = (string)($this->pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='site_uuid' LIMIT 1")->fetchColumn() ?: '');
            } catch (Exception $e) { /* non-fatal */ }
        }
        $archive->addFromString("{$prefix}/database.sql", $sqlContent);
        $manifest['files']['database.sql'] = [
            'size'     => strlen($sqlContent),
            'checksum' => 'sha256:' . hash('sha256', $sqlContent),
            'bundled'  => true,
        ];

        // Gather stats from the SQL data
        try { $manifest['stats']['total_images']   = (int) $this->pdo->query("SELECT COUNT(*) FROM snap_images")->fetchColumn(); } catch (Exception $e) {}
        try { $manifest['stats']['total_comments'] = (int) $this->pdo->query("SELECT COUNT(*) FROM snap_comments")->fetchColumn(); } catch (Exception $e) {}
        try { $manifest['stats']['total_pages']    = (int) $this->pdo->query("SELECT COUNT(*) FROM snap_pages")->fetchColumn(); } catch (Exception $e) {}

        // --- 2. BRANDING ASSETS inventory (assets/img/) ---
        $brandingDir = $this->baseDir . '/assets/img';
        if (is_dir($brandingDir)) {
            $manifest = $this->inventoryDirectory(
                $brandingDir, 'assets/img',
                $manifest, 'branding_files'
            );
        }

        // --- 3. MEDIA LIBRARY inventory (media_assets/) ---
        $mediaDir = $this->baseDir . '/media_assets';
        if (is_dir($mediaDir)) {
            $manifest = $this->inventoryDirectory(
                $mediaDir, 'media_assets',
                $manifest, 'media_files'
            );
        }

        // --- 4. ACTIVE SKIN inventory ---
        $skinSlug = $this->settings['active_skin'] ?? '50-shades-of-noah-grey';
        $skinDir  = $this->baseDir . '/skins/' . $skinSlug;
        if (is_dir($skinDir)) {
            $manifest = $this->inventoryDirectory(
                $skinDir, "skins/{$skinSlug}",
                $manifest, 'skin_files'
            );
        }

        // --- 5. IMAGE UPLOADS inventory (img_uploads/) ---
        $uploadsDir = $this->baseDir . '/img_uploads';
        if (is_dir($uploadsDir)) {
            $manifest = $this->inventoryDirectory(
                $uploadsDir, 'img_uploads',
                $manifest, 'upload_files'
            );
        }

        // --- 6. DATABASE IMAGE MAP ---
        // Every img_file path from snap_images so the restore tool can cross-reference
        // FTP files against what the database actually expects, without parsing SQL.
        try {
            $rows = $this->pdo->query(
                "SELECT id, img_file, img_title, img_slug FROM snap_images ORDER BY id"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $manifest['database_images'][] = [
                    'id'    => (int) $row['id'],
                    'file'  => $row['img_file'],
                    'title' => $row['img_title'],
                    'slug'  => $row['img_slug'],
                ];
            }
        } catch (Exception $e) {
            // snap_images may not exist on a fresh schema
        }

        // --- 7. DIRECTORY STRUCTURE ---
        // Deduplicate directory paths from the file inventory so the restore tool
        // can pre-create the full tree before uploading any files.
        $dirs = [];
        foreach ($manifest['files'] as $key => $meta) {
            $restoreTo = $meta['restores_to'] ?? $key;
            $dir = dirname($restoreTo);
            if ($dir !== '.' && !isset($dirs[$dir])) {
                $dirs[$dir] = true;
            }
        }
        ksort($dirs);
        $manifest['directory_structure'] = array_keys($dirs);

        // --- 8. WRITE MANIFEST ---
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $archive->addFromString("{$prefix}/manifest.json", $manifestJson);

        // --- 9. COMPRESS ---
        $gzPath = $archive->compress(Phar::GZ)->getPath();
        unset($archive);
        @unlink($tarPath);

        return $gzPath;
    }

    /**
     * Lightweight inventory for SUYB (client-side manifest generation).
     *
     * Returns the SAME manifest structure as exportRecoveryKit(), but does NOT
     * hash any media file (checksum => null) and does NOT bundle the SQL dump or
     * build a tarball. The SUYB client fetches the SQL separately (type=full),
     * computes each file's SHA-256 as it downloads it over FTP, fills in the
     * null checksums, adds the database.sql entry, and tars the kit itself.
     *
     * This moves the expensive per-file hashing off the server: a 10k-image blog
     * no longer blocks a request for minutes hashing gigabytes it never bundles.
     * The manual exportRecoveryKit() path (non-SUYB / Mac owners) is unchanged.
     *
     * NOTE: the client fetches the SQL dump (type=full) BEFORE the inventory,
     * because generateSqlDump() get-or-creates site_uuid — so by the time this
     * runs the uuid is present for the cross-mode restore guard.
     *
     * @return array manifest with null checksums, ready for the client to complete
     */
    public function exportInventory(): array {
        $manifest = [
            'snapsmack_version' => $this->settings['installed_version'] ?? '0.8',
            'export_date'       => date('c'),
            'export_type'       => 'inventory',
            'site_name'         => $this->settings['site_name'] ?? '',
            'site_url'          => $this->settings['site_url'] ?? '',
            'site_mode'         => $this->settings['site_mode'] ?? 'photoblog',
            'site_uuid'         => (string)($this->settings['site_uuid'] ?? ''),
            'active_skin'       => $this->settings['active_skin'] ?? '50-shades-of-noah-grey',
            'active_variant'    => $this->settings['active_skin_variant'] ?? 'dark',
            'php_version'       => PHP_VERSION,
            'files'               => [],
            'directory_structure' => [],
            'database_images'    => [],
            'stats'              => [
                'total_images'   => 0,
                'total_comments' => 0,
                'total_pages'    => 0,
                'branding_files' => 0,
                'media_files'    => 0,
                'skin_files'     => 0,
                'upload_files'   => 0,
                'total_media_bytes' => 0,
            ],
        ];

        // site_uuid: read authoritatively (the SQL export get-or-creates it).
        if ($manifest['site_uuid'] === '') {
            try {
                $manifest['site_uuid'] = (string)($this->pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='site_uuid' LIMIT 1")->fetchColumn() ?: '');
            } catch (Exception $e) { /* non-fatal */ }
        }

        // Stats — cheap COUNT queries only.
        try { $manifest['stats']['total_images']   = (int) $this->pdo->query("SELECT COUNT(*) FROM snap_images")->fetchColumn(); } catch (Exception $e) {}
        try { $manifest['stats']['total_comments'] = (int) $this->pdo->query("SELECT COUNT(*) FROM snap_comments")->fetchColumn(); } catch (Exception $e) {}
        try { $manifest['stats']['total_pages']    = (int) $this->pdo->query("SELECT COUNT(*) FROM snap_pages")->fetchColumn(); } catch (Exception $e) {}

        // File inventory — paths + sizes ONLY, no hashing (hashFiles = false).
        $brandingDir = $this->baseDir . '/assets/img';
        if (is_dir($brandingDir)) {
            $manifest = $this->inventoryDirectory($brandingDir, 'assets/img', $manifest, 'branding_files', false);
        }
        $mediaDir = $this->baseDir . '/media_assets';
        if (is_dir($mediaDir)) {
            $manifest = $this->inventoryDirectory($mediaDir, 'media_assets', $manifest, 'media_files', false);
        }
        $skinSlug = $this->settings['active_skin'] ?? '50-shades-of-noah-grey';
        $skinDir  = $this->baseDir . '/skins/' . $skinSlug;
        if (is_dir($skinDir)) {
            $manifest = $this->inventoryDirectory($skinDir, "skins/{$skinSlug}", $manifest, 'skin_files', false);
        }
        $uploadsDir = $this->baseDir . '/img_uploads';
        if (is_dir($uploadsDir)) {
            $manifest = $this->inventoryDirectory($uploadsDir, 'img_uploads', $manifest, 'upload_files', false);
        }

        // Database image map — same as the kit; lets the client cross-reference
        // FTP files against what the DB expects without parsing SQL.
        try {
            $rows = $this->pdo->query("SELECT id, img_file, img_title, img_slug FROM snap_images ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $manifest['database_images'][] = [
                    'id'    => (int) $row['id'],
                    'file'  => $row['img_file'],
                    'title' => $row['img_title'],
                    'slug'  => $row['img_slug'],
                ];
            }
        } catch (Exception $e) { /* snap_images may not exist on a fresh schema */ }

        // Directory structure — derived from inventoried paths.
        $dirs = [];
        foreach ($manifest['files'] as $key => $meta) {
            $restoreTo = $meta['restores_to'] ?? $key;
            $dir = dirname($restoreTo);
            if ($dir !== '.' && !isset($dirs[$dir])) {
                $dirs[$dir] = true;
            }
        }
        ksort($dirs);
        $manifest['directory_structure'] = array_keys($dirs);

        return $manifest;
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
        $images     = $this->pdo->query("SELECT * FROM snap_images ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
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
        $images     = $this->pdo->query("SELECT * FROM snap_images ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
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
     * @param string $type  'full' or 'schema'
     * @return string SQL content
     */
    /**
     * Generate a SQL dump of all snap_* tables.
     *
     * @param string $type  'full' = schema + data (default)
     *                      'schema' = DDL only (CREATE TABLE statements)
     * @return string  SQL dump as a string
     */
    /**
     * Strip live secrets out of a dumped row so backups never carry credentials
     * off-box in cleartext (security audit). snap_settings rows whose key looks
     * sensitive, and known secret columns (multisite node keys), are replaced
     * with a placeholder; the owner re-provisions those on restore. bcrypt user
     * hashes are left intact (already hashed + needed for restore).
     */
    private function redactSecrets(string $table, array $row): array {
        $isSensitive = static function (string $name): bool {
            $n = strtolower($name);
            foreach (['_key','_token','_secret','password','_pass','_salt','api_key','apikey','client_secret','bearer','private_key'] as $frag) {
                if (str_contains($n, $frag)) return true;
            }
            return false;
        };
        if ($table === 'snap_settings'
            && isset($row['setting_key'], $row['setting_val'])
            && $row['setting_val'] !== null && $row['setting_val'] !== ''
            && $isSensitive((string)$row['setting_key'])) {
            $row['setting_val'] = '__REDACTED__';
        }
        if ($table === 'snap_multisite_nodes') {
            foreach (['api_key_local', 'api_key_remote'] as $c) {
                if (!empty($row[$c])) $row[$c] = '__REDACTED__';
            }
        }
        return $row;
    }

    // Core dump generator. Streams output through $emit($chunk) so a huge DB never has
    // to sit in memory at once — the old fetchAll()+string build 500'd (OOM) on big sites.
    private function emitSqlDump(string $type, callable $emit): void {
        // Origin stamp — binds the dump to the site (and MODE) it came from.
        $site_mode = $this->settings['site_mode'] ?? 'photoblog';
        $site_url  = $this->settings['site_url'] ?? ($this->settings['site_address'] ?? '');
        $site_uuid = (string)($this->settings['site_uuid'] ?? '');
        if ($site_uuid === '') {
            try {
                $site_uuid = (string)($this->pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='site_uuid' LIMIT 1")->fetchColumn() ?: '');
            } catch (PDOException $e) {
                $site_uuid = '';
            }
            if ($site_uuid === '') {
                $site_uuid = bin2hex(random_bytes(16));
                try {
                    $this->pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('site_uuid', ?)
                                         ON DUPLICATE KEY UPDATE setting_val = setting_val")->execute([$site_uuid]);
                } catch (PDOException $e) { /* non-fatal */ }
            }
        }

        $header  = "-- SnapSmack Backup Service\n";
        $header .= "-- Type: " . strtoupper($type) . "\n";
        $header .= "-- Version: " . (defined('SNAPSMACK_VERSION') ? SNAPSMACK_VERSION : 'unknown') . "\n";
        $header .= "-- Date: " . date('Y-m-d H:i:s') . "\n";
        $header .= "-- Site: " . ($this->settings['site_name'] ?? '') . "\n";
        $header .= "-- Site URL: " . $site_url . "\n";
        $header .= "-- Site UUID: " . $site_uuid . "\n";
        $header .= "-- Site mode: " . $site_mode . "\n";
        $header .= "-- ============================================================\n";
        $header .= "-- WARNING: this is a SnapSmack '" . $site_mode . "'-mode database.\n";
        $header .= "-- Restoring it onto a site running a DIFFERENT mode (photoblog /\n";
        $header .= "-- carousel / smacktalk) WILL break that site: it overwrites\n";
        $header .= "-- site_mode, the default skin, and imports wrong-shaped content.\n";
        $header .= "-- Restore only onto the SAME site, or a deliberate same-mode migration.\n";
        $header .= "-- ============================================================\n\n";
        $header .= "SET NAMES utf8mb4;\n";
        $header .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
        $emit($header);

        $tables = [];
        $res = $this->pdo->query("SHOW TABLES");
        while ($row = $res->fetch(PDO::FETCH_NUM)) {
            if (str_starts_with($row[0], 'snap_')) {
                $tables[] = $row[0];
            }
        }
        $res->closeCursor();
        sort($tables);

        // Unbuffered rows: never load a whole table into PHP/driver memory at once.
        $prevBuffered = null;
        try { $prevBuffered = $this->pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY); } catch (\Throwable $e) {}
        try { $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false); } catch (\Throwable $e) {}
        try {
            foreach ($tables as $table) {
                try {
                    $st  = $this->pdo->query("SHOW CREATE TABLE `{$table}`");
                    $ddl = $st->fetch(PDO::FETCH_ASSOC);
                    $st->closeCursor();
                } catch (PDOException $e) {
                    continue;
                }

                $emit("-- \xe2\x94\x80\xe2\x94\x80 {$table} \xe2\x94\x80\xe2\x94\x80\n"
                    . "DROP TABLE IF EXISTS `{$table}`;\n"
                    . $ddl['Create Table'] . ";\n\n");

                if ($type !== 'schema') {
                    $rs  = $this->pdo->query("SELECT * FROM `{$table}`");
                    $buf = ''; $n = 0;
                    while ($row = $rs->fetch(PDO::FETCH_ASSOC)) {
                        $row  = $this->redactSecrets($table, $row);
                        $keys = array_map(fn($k) => "`{$k}`", array_keys($row));
                        $vals = array_map(
                            fn($v) => $v === null ? "NULL" : $this->pdo->quote($v),
                            array_values($row)
                        );
                        $buf .= "INSERT INTO `{$table}` (" . implode(', ', $keys)
                              . ") VALUES (" . implode(', ', $vals) . ");\n";
                        if (++$n % 200 === 0) { $emit($buf); $buf = ''; }
                    }
                    $rs->closeCursor();
                    if ($buf !== '') $emit($buf);
                    if ($n > 0) $emit("\n");
                }
            }
        } finally {
            try { $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $prevBuffered === null ? true : $prevBuffered); } catch (\Throwable $e) {}
        }

        $emit("SET FOREIGN_KEY_CHECKS = 1;\n");
    }

    /** Buffer the dump into a string (kept for callers that need the whole thing). */
    public function generateSqlDump(string $type = 'full'): string {
        $out = '';
        $this->emitSqlDump($type, function ($chunk) use (&$out) { $out .= $chunk; });
        return $out;
    }

    /** Stream the dump straight to the client — constant memory, no 500 on big DBs. */
    public function streamSqlDump(string $type = 'full'): void {
        $this->emitSqlDump($type, function ($chunk) { echo $chunk; flush(); });
    }

    // =================================================================
    // HELPERS
    // =================================================================

    /**
     * Inventory all files in a directory WITHOUT bundling them into the archive.
     * Records path, size, SHA-256 checksum, and restore location in the manifest.
     * This keeps recovery kits lightweight — only the SQL dump is bundled.
     *
     * @param string   $sourceDir      Absolute path to source directory
     * @param string   $restorePrefix  Path prefix for restores_to (relative to site root)
     * @param array    $manifest       Current manifest array (modified in place)
     * @param string   $statKey        Key in manifest.stats to increment
     * @return array Updated manifest
     */
    private function inventoryDirectory(
        string $sourceDir,
        string $restorePrefix,
        array $manifest,
        string $statKey,
        bool $hashFiles = true
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

            $restorePath  = $restorePrefix . '/' . $relativePath;
            $fileSize     = $file->getSize();

            $manifest['files'][$restorePath] = [
                'size'        => $fileSize,
                'checksum'    => $hashFiles ? ('sha256:' . hash_file('sha256', $realPath)) : null,
                'restores_to' => $restorePath,
                'bundled'     => false,
            ];
            $manifest['stats'][$statKey]++;
            $manifest['stats']['total_media_bytes'] += $fileSize;
        }

        return $manifest;
    }
}

// ===== SNAPSMACK EOF =====
