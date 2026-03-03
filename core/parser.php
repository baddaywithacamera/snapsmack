<?php
/**
 * SNAPSMACK - Content Parser and Asset Router
 * Alpha v0.6
 *
 * Parses shortcodes in content and converts them to image markup. Supports
 * both snap_assets (media library uploads) and snap_images (photography posts)
 * with fallback logic. Handles thumbnail/wall variants automatically.
 */

class SnapSmack {
    private $pdo;
    private $config = [];

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadConfig();
    }

    // --- CONFIGURATION LOADER ---
    // Load site settings from the database into a config array.
    // Fails silently if the table is unavailable.
    private function loadConfig() {
        try {
            $stmt = $this->pdo->query("SELECT setting_key, setting_val FROM snap_settings");
            $this->config = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $e) {
            $this->config = [];
        }
    }

    // --- CONTENT PARSER ---
    // Parse all [img:ID|size|align] shortcodes in the provided content.
    // Returns HTML with <img> tags, or an empty string if the asset is not found.
    public function parseContent($content) {
        if (empty($content)) return "";

        // Shortcode pattern: [img:ID] or [img:ID|size] or [img:ID|size|align]
        // Size: small (thumbnail), wall (gallery wall), full (default)
        // Align: left, center (default), right
        return preg_replace_callback('/\[img:\s*(\d+)(?:\s*\|\s*(small|wall|full))?(?:\s*\|\s*(left|center|right))?\s*\]/i', function($matches) {
            $id = $matches[1];
            $size = $matches[2] ?? 'full';
            $align = $matches[3] ?? 'center';

            // --- ASSET LOOKUP (PRIORITY 1) ---
            // Try media assets first (smack-media.php uploads)
            $stmt = $this->pdo->prepare("SELECT asset_path as path, asset_name as name FROM snap_assets WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $asset = $stmt->fetch();

            // --- FALLBACK TO SNAP_IMAGES (PRIORITY 2) ---
            // If not in snap_assets, look in snap_images (main photography posts)
            if (!$asset) {
                $stmt = $this->pdo->prepare("SELECT img_file as path, img_title as name FROM snap_images WHERE id = ? LIMIT 1");
                $stmt->execute([$id]);
                $asset = $stmt->fetch();
            }

            if (!$asset) return "";

            // Determine base URL from environment or config
            $base = defined('BASE_URL') ? BASE_URL : (rtrim($this->config['site_url'] ?? '/', '/') . '/');
            $raw_path = ltrim($asset['path'], '/');

            // --- PATH RESOLUTION ---
            // For uploads in the thumbs directory, apply size modifiers.
            // Otherwise, return the raw path as-is.
            $filename = basename($raw_path);
            $folder = str_replace($filename, '', $raw_path);

            if ($size === 'small' && strpos($raw_path, 'uploads/') !== false) {
                $final_path = $folder . 'thumbs/t_' . $filename;
            } elseif ($size === 'wall' && strpos($raw_path, 'uploads/') !== false) {
                $final_path = $folder . 'thumbs/wall_' . $filename;
            } else {
                $final_path = $raw_path;
            }

            $full_src = $base . $final_path;
            $classes = "snapsmack-asset asset-$size align-$align";

            return sprintf(
                '<img src="%s" class="%s" alt="%s" loading="lazy">',
                $full_src,
                $classes,
                htmlspecialchars($asset['name'])
            );
        }, $content);
    }
}
