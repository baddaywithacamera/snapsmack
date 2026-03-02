<?php
/**
 * SnapSmack - Download Overlay Helper
 * Version: 3.0 - Fixed Position, No Wrapper
 * -------------------------------------------------------------------------
 * Download button uses position:fixed so it sits at a fixed spot in the
 * viewport. NO wrapper div needed. Image stays as direct flex child of
 * the skin's photo container. Zero layout interference.
 *
 * Expected variables in scope:
 *   $img      — The image record array from snap_images
 *   $settings — The site settings array from snap_settings
 *
 * Usage in skin layout.php:
 *   <?php include dirname(__DIR__, 2) . '/core/download-overlay.php'; ?>
 *   <img src="..." alt="..." class="fsog-image post-image">
 *   <?php echo $download_button; ?>
 * -------------------------------------------------------------------------
 */

$download_button = '';

$global_downloads = (($settings['global_downloads_enabled'] ?? '0') === '1');
$post_downloads   = (($img['allow_download'] ?? 0) == 1);

if ($global_downloads && $post_downloads) {

    $external_url = trim($img['download_url'] ?? '');

    if (!empty($external_url)) {
        // Auto-convert Google Drive view links to direct download
        if (preg_match('#drive\.google\.com/file/d/([^/]+)#', $external_url, $m)) {
            $external_url = 'https://drive.google.com/uc?export=download&id=' . $m[1];
        }
        $download_url = $external_url;
        $target = ' target="_blank" rel="noopener"';
    } else {
        $salt = $settings['download_salt'] ?? 'snapsmack-default-salt-change-me';
        $token = hash_hmac('sha256', (string)$img['id'], $salt);
        $download_url = BASE_URL . 'download.php?id=' . $img['id'] . '&t=' . $token;
        $target = '';
    }

    $download_button = '<a href="' . htmlspecialchars($download_url) . '" class="snap-download-btn" title="Download full resolution"' . $target . '>'
        . '<span class="snap-download-icon"><span></span></span>'
        . '</a>';
}
