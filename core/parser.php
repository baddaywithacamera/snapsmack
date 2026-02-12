<?php
/**
 * SnapSmack Core Parser
 * Version: PRO-PENCIL 4.9.1 - Dual-Table Asset Routing
 * Logic: Hybrid routing for snap_images (photography) and snap_assets (media).
 */

class SnapSmack {
    private $pdo;
    private $config = [];

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadConfig();
    }

    private function loadConfig() {
        try {
            $stmt = $this->pdo->query("SELECT setting_key, setting_val FROM snap_settings");
            $this->config = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $e) { 
            $this->config = []; 
        }
    }

    public function parseContent($content) {
        if (empty($content)) return "";

        // Shortcode Engine: Handles [img:ID|size|align]
        return preg_replace_callback('/\[img:\s*(\d+)(?:\s*\|\s*(small|wall|full))?(?:\s*\|\s*(left|center|right))?\s*\]/i', function($matches) {
            $id = $matches[1];
            $size = $matches[2] ?? 'full';
            $align = $matches[3] ?? 'center';

            // 1. ATTEMPT MEDIA ASSETS FIRST (smack-media.php uploads)
            $stmt = $this->pdo->prepare("SELECT asset_path as path, asset_name as name FROM snap_assets WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $asset = $stmt->fetch();

            // 2. FALLBACK TO SNAP_IMAGES (Main Photography Posts)
            if (!$asset) {
                $stmt = $this->pdo->prepare("SELECT img_file as path, img_title as name FROM snap_images WHERE id = ? LIMIT 1");
                $stmt->execute([$id]);
                $asset = $stmt->fetch();
            }

            if (!$asset) return "";

            $base = defined('BASE_URL') ? BASE_URL : (rtrim($this->config['site_url'] ?? '/', '/') . '/');
            $raw_path = ltrim($asset['path'], '/');

            // --- TRIPLE PATH LOGIC ---
            // Only apply thumb/wall logic if the file is in an 'uploads' directory 
            // and actually has a thumb. Otherwise, return the raw path.
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