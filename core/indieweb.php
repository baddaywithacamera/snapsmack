<?php
/**
 * SnapSmack passive IndieWeb semantics.
 *
 * This module intentionally provides only machine-readable identity and post
 * markup: rel=me, h-card, and h-entry properties. It creates no endpoints,
 * performs no remote fetches, and does not implement Webmention, IndieAuth, or
 * Micropub. ActivityPub remains SnapSmack's sole social transport.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

/** Return an owner-supplied URL only when it is an ordinary HTTP(S) URL. */
function snapsmack_indieweb_url(string $url): string {
    $url = trim($url);
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) return '';
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? $url : '';
}

/**
 * Identity URLs already configured for the public Social Dock.
 *
 * A disabled dock is an owner decision not to publish those profiles, so it
 * also suppresses rel=me discovery. Nothing private becomes public merely by
 * upgrading SnapSmack.
 */
function snapsmack_indieweb_identity_urls(array $settings): array {
    if (($settings['social_dock_enabled'] ?? '0') !== '1') return [];

    $keys = [
        'social_dock_flickr', 'social_dock_smugmug', 'social_dock_instagram',
        'social_dock_facebook', 'social_dock_youtube', 'social_dock_500px',
        'social_dock_vero', 'social_dock_threads', 'social_dock_mastodon',
        'social_dock_bluesky', 'social_dock_linkedin', 'social_dock_pinterest',
        'social_dock_tumblr', 'social_dock_deviantart', 'social_dock_behance',
        'social_dock_linktree', 'social_dock_website',
    ];

    $urls = [];
    foreach ($keys as $key) {
        $url = snapsmack_indieweb_url((string)($settings[$key] ?? ''));
        if ($url !== '') $urls[$url] = $url;
    }
    return array_values($urls);
}

/** Emit document-head identity discovery links. */
function snapsmack_indieweb_head_links(array $settings): void {
    foreach (snapsmack_indieweb_identity_urls($settings) as $url) {
        echo '<link rel="me" href="'
           . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
           . '">' . "\n";
    }
}

/**
 * Emit compact h-entry properties for a solo photograph.
 *
 * The skin remains responsible for visible presentation. These typed elements
 * label the canonical values once in core so every skin exposes the same entry.
 */
function snapsmack_indieweb_photo_properties(array $img, array $settings): void {
    if (empty($img['id'])) return;

    $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') . '/' : '/';
    $slug = ltrim((string)($img['img_slug'] ?? ''), '/');
    $url  = $slug !== '' ? $base . $slug : $base;
    $name = html_entity_decode((string)($img['img_title'] ?? ''), ENT_QUOTES | ENT_HTML5);
    $desc = trim(strip_tags((string)($img['img_description'] ?? '')));
    $date = trim((string)($img['img_date'] ?? ''));
    $file = ltrim(str_replace('\\', '/', (string)($img['img_file'] ?? '')), '/');
    $site = html_entity_decode((string)($settings['site_name'] ?? 'SnapSmack'), ENT_QUOTES | ENT_HTML5);

    echo '<span class="snapsmack-indieweb-properties" hidden aria-hidden="true">';
    echo '<a class="u-url" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></a>';
    echo '<data class="p-name" value="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"></data>';
    if ($desc !== '') {
        echo '<data class="p-summary e-content" value="' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '"></data>';
    }
    if ($date !== '' && strtotime($date) !== false) {
        echo '<time class="dt-published" datetime="' . htmlspecialchars(date(DATE_ATOM, strtotime($date)), ENT_QUOTES, 'UTF-8') . '"></time>';
    }
    if ($file !== '') {
        echo '<a class="u-photo" href="' . htmlspecialchars($base . $file, ENT_QUOTES, 'UTF-8') . '"></a>';
    }
    echo '<span class="p-author h-card">';
    echo '<a class="p-name u-url" href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '">'
       . htmlspecialchars($site, ENT_QUOTES, 'UTF-8') . '</a>';
    echo '</span></span>';
}

/** Emit the shared canonical URL and author properties for a longform entry. */
function snapsmack_indieweb_longform_properties(array $post, array $settings): void {
    $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') . '/' : '/';
    $slug = trim((string)($post['slug'] ?? ''));
    $url  = $slug !== '' ? $base . '?post=' . rawurlencode($slug) : $base;
    $site = html_entity_decode((string)($settings['site_name'] ?? 'SnapSmack'), ENT_QUOTES | ENT_HTML5);

    echo '<span class="snapsmack-indieweb-properties" hidden aria-hidden="true">';
    echo '<a class="u-url" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></a>';
    echo '<span class="p-author h-card"><a class="p-name u-url" href="'
       . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '">'
       . htmlspecialchars($site, ENT_QUOTES, 'UTF-8') . '</a></span>';
    echo '</span>';
}

// ===== SNAPSMACK EOF =====
