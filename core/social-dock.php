<?php
/**
 * SNAPSMACK — Social Profile Dock
 * Alpha v0.7.1
 *
 * Renders a floating dock of social profile links on public pages.
 * Included by core/footer-scripts.php. Settings from snap_settings.
 */

// Bail if dock is disabled
if (empty($settings['social_dock_enabled']) || $settings['social_dock_enabled'] !== '1') {
    return;
}

// Platform definitions: key => [label, settings_key, svg]
// SVGs use fill="currentColor" for CSS theming, 24x24 viewBox
$_dock_platforms = [
    'flickr' => [
        'label' => 'Flickr',
        'key' => 'social_dock_flickr',
        'svg' => '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="7" cy="12" r="4.5"/><circle cx="17" cy="12" r="4.5" fill="none" stroke="currentColor" stroke-width="2"/></svg>'
    ],
    'smugmug' => [
        'label' => 'SmugMug',
        'key' => 'social_dock_smugmug',
        'svg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>'
    ],
    'instagram' => [
        'label' => 'Instagram',
        'key' => 'social_dock_instagram',
        'svg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><circle cx="12" cy="12" r="5"/><circle cx="17.5" cy="6.5" r="1.5" fill="currentColor" stroke="none"/></svg>'
    ],
    'facebook' => [
        'label' => 'Facebook',
        'key' => 'social_dock_facebook',
        'svg' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/></svg>'
    ],
    'youtube' => [
        'label' => 'YouTube',
        'key' => 'social_dock_youtube',
        'svg' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M22.54 6.42a2.78 2.78 0 00-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 00-1.94 2A29 29 0 001 12a29 29 0 00.46 5.58 2.78 2.78 0 001.94 2C5.12 20 12 20 12 20s6.88 0 8.6-.46a2.78 2.78 0 001.94-2A29 29 0 0023 12a29 29 0 00-.46-5.58zM9.75 15.02V8.98L15.5 12l-5.75 3.02z"/></svg>'
    ],
    '500px' => [
        'label' => '500px',
        'key' => 'social_dock_500px',
        'svg' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M7.5 8.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zm0 2a3.5 3.5 0 110 7 3.5 3.5 0 010-7zM14 4.5h6v2h-6zM17 8a5 5 0 11-1.4 9.8l1.2-1.6A3 3 0 1017 10v-2z"/></svg>'
    ],
    'vero' => [
        'label' => 'Vero',
        'key' => 'social_dock_vero',
        'svg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 12 10 18 20 6"/></svg>'
    ],
    'threads' => [
        'label' => 'Threads',
        'key' => 'social_dock_threads',
        'svg' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12.186 2c3.73 0 5.88 1.785 6.724 3.46.574 1.14.862 2.47.862 3.96 0 .08-.002.158-.004.237a4.82 4.82 0 01-2.128 3.755c-.126.09-.26.174-.4.252.014.197.02.397.02.6 0 1.17-.195 2.24-.585 3.19-.585 1.43-1.582 2.49-2.97 3.16-1.135.545-2.466.82-3.96.82-1.703 0-3.183-.374-4.403-1.112C3.87 19.44 2.96 18.15 2.44 16.48l2.08-.72c.38 1.22 1.04 2.14 1.96 2.74.92.6 2.04.9 3.34.9 1.66 0 2.94-.44 3.8-1.3.72-.72 1.08-1.72 1.08-2.96 0-.11-.003-.218-.008-.324a4.56 4.56 0 01-2.512.716c-1.42 0-2.62-.36-3.57-1.07-.96-.72-1.44-1.74-1.44-3.04 0-1.22.46-2.2 1.38-2.94.92-.74 2.08-1.11 3.48-1.11 1.24 0 2.27.34 3.09 1.01.24.2.45.42.64.66v-1.5h2.12v5.58c0 .04 0 .08-.002.12.17-.1.332-.21.488-.33 1.1-.84 1.66-1.97 1.66-3.38 0-2.76-1.82-5.3-5.62-5.3-2.16 0-3.88.64-5.12 1.9-1.24 1.28-1.86 3.02-1.86 5.2 0 2.28.68 4.06 2.02 5.32 1.34 1.26 3.16 1.9 5.44 1.9h.12v2.1h-.12c-2.86 0-5.2-.84-6.98-2.52C3.34 17.74 2.4 15.38 2.4 12.42c0-2.72.82-4.96 2.44-6.7C6.48 3.98 8.88 3.04 11.82 3.04L12.186 2z"/></svg>'
    ],
    'bluesky' => [
        'label' => 'Bluesky',
        'key' => 'social_dock_bluesky',
        'svg' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C8.24 5.2 5.84 8.82 5.06 11.1c-.24.7-.36 1.26-.36 1.64 0 1.48 1.2 2.26 2.4 2.26.66 0 1.34-.2 1.9-.6-.82 1.74-2.5 3-4.5 3.6.82.26 1.66.4 2.5.4 3.34 0 5.58-2.48 5-5.4.58 2.92 2.66 5.4 6 5.4.84 0 1.68-.14 2.5-.4-2 -.6-3.68-1.86-4.5-3.6.56.4 1.24.6 1.9.6 1.2 0 2.4-.78 2.4-2.26 0-.38-.12-.94-.36-1.64C18.16 8.82 15.76 5.2 12 2z"/></svg>'
    ],
    'linkedin' => [
        'label' => 'LinkedIn',
        'key' => 'social_dock_linkedin',
        'svg' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>'
    ],
    'pinterest' => [
        'label' => 'Pinterest',
        'key' => 'social_dock_pinterest',
        'svg' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.372 0 12c0 5.084 3.163 9.426 7.627 11.174-.105-.949-.2-2.405.042-3.441.218-.937 1.407-5.965 1.407-5.965s-.359-.719-.359-1.782c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738a.36.36 0 01.083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.631-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12 24c6.627 0 12-5.373 12-12 0-6.628-5.373-12-12-12z"/></svg>'
    ],
    'tumblr' => [
        'label' => 'Tumblr',
        'key' => 'social_dock_tumblr',
        'svg' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M14.563 24c-5.093 0-7.031-3.756-7.031-6.411V9.747H5.116V6.648c3.63-1.313 4.512-4.596 4.71-6.469C9.84.051 9.941 0 10.077 0h3.727v6.094h5.088v3.653h-5.101v7.476c.013 1.013.207 2.412 2.271 2.412l.011-.001h.006c.631-.019 1.478-.205 1.921-.382v3.498c-.655.27-1.818.57-3.437.57v-.32z"/></svg>'
    ],
    'deviantart' => [
        'label' => 'DeviantArt',
        'key' => 'social_dock_deviantart',
        'svg' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M18 3V0h-3l-1 2-2 3H6v6h4L6 18v3h3l1-2 2-3h6v-6h-4l4-7z"/></svg>'
    ],
    'behance' => [
        'label' => 'Behance',
        'key' => 'social_dock_behance',
        'svg' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6.938 4.503c.702 0 1.34.06 1.92.188.577.13 1.07.33 1.485.61.413.28.733.643.96 1.083.225.44.34.96.34 1.563 0 .663-.15 1.21-.455 1.65-.305.44-.748.81-1.328 1.11.836.27 1.464.72 1.885 1.35.42.63.63 1.39.63 2.29 0 .66-.13 1.25-.395 1.76-.263.51-.63.94-1.095 1.28-.465.34-1.01.6-1.635.775-.625.18-1.29.265-2 .265H1V4.503h5.938zM6.68 10.46c.553 0 1.01-.148 1.373-.44.36-.295.54-.725.54-1.287 0-.32-.06-.59-.174-.807a1.396 1.396 0 00-.468-.525 1.856 1.856 0 00-.675-.28 3.41 3.41 0 00-.792-.085H3.92v3.424h2.76zm.24 5.63c.306 0 .594-.033.868-.1.275-.07.514-.18.72-.33.205-.15.368-.35.488-.6.12-.25.18-.56.18-.93 0-.74-.216-1.28-.648-1.62-.432-.34-1.003-.51-1.71-.51H3.92v4.09h3zM15.97 17.27c.504.482 1.22.722 2.15.722.66 0 1.23-.165 1.71-.494.48-.33.788-.685.93-1.065h3.08c-.49 1.54-1.244 2.66-2.265 3.35-1.02.69-2.26 1.035-3.723 1.035-.975 0-1.853-.16-2.636-.48-.78-.32-1.446-.78-1.997-1.38-.552-.6-.978-1.32-1.278-2.16-.3-.84-.45-1.77-.45-2.79 0-.98.155-1.89.465-2.72.31-.83.747-1.55 1.313-2.15.565-.6 1.24-1.07 2.025-1.41.783-.34 1.648-.51 2.593-.51.97 0 1.833.19 2.58.57.748.38 1.38.9 1.89 1.56.51.66.895 1.43 1.148 2.31.255.88.36 1.83.318 2.85h-9.18c.045 1.08.375 1.86.88 2.34zM18.01 10.71c-.402-.4-1.033-.6-1.89-.6-.562 0-1.03.1-1.407.3-.374.2-.675.44-.9.72-.224.28-.384.57-.478.87-.094.3-.153.57-.176.8h5.7c-.12-.9-.447-1.69-.85-2.09zM14.88 5.43h6.24v1.62h-6.24z"/></svg>'
    ],
    'linktree' => [
        'label' => 'Linktree',
        'key' => 'social_dock_linktree',
        'svg' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M7.953 15.066l-.038-4.086-3.291 3.19-1.624-1.624 3.291-3.19H2.135V7.178h4.156L2.935 3.832l1.624-1.624 3.355 3.355L7.953 1.5h2.09v4.063l3.356-3.355 1.624 1.624-3.356 3.346h4.156v2.178h-4.156l3.291 3.19-1.624 1.624-3.291-3.19.038 4.086h-2.09zm0 1.998h2.09V22.5h-2.09v-5.436z"/></svg>'
    ],
    'website' => [
        'label' => 'Website',
        'key' => 'social_dock_website',
        'svg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>'
    ]
];

// Build active links array
$_dock_links = [];
foreach ($_dock_platforms as $_platform_key => $_platform) {
    $_url = $settings[ $_platform['key'] ] ?? '';
    if (!empty($_url)) {
        $_dock_links[] = [
            'url' => $_url,
            'label' => $_platform['label'],
            'svg' => $_platform['svg']
        ];
    }
}

// If no active links, bail
if (empty($_dock_links)) {
    return;
}

// Get and validate position
$_dock_position = $settings['social_dock_position'] ?? 'bottom-right';
$_valid_positions = ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'left-top', 'left-bottom', 'right-top', 'right-bottom'];
if (!in_array($_dock_position, $_valid_positions)) {
    $_dock_position = 'bottom-right';
}

// Appearance settings → CSS custom properties
$_dock_color_light = $settings['social_dock_color_light'] ?? '#ffffff';
$_dock_color_dark  = $settings['social_dock_color_dark'] ?? '#1a1a1a';
$_dock_color_mode  = ($settings['social_dock_color_mode'] ?? 'light') === 'dark' ? 'dark' : 'light';
$_dock_shadow      = ($settings['social_dock_shadow'] ?? '1') === '1';
$_dock_opacity     = max(0, min(100, (int)($settings['social_dock_opacity'] ?? 20)));
$_dock_shape       = ($settings['social_dock_icon_shape'] ?? 'round') === 'square' ? 'square' : 'round';
$_dock_style       = ($settings['social_dock_icon_style'] ?? 'outline') === 'solid' ? 'solid' : 'outline';

// Active color based on mode
$_dock_color = ($_dock_color_mode === 'dark') ? $_dock_color_dark : $_dock_color_light;

// Convert hex colour to RGB for rgba() usage in CSS
$_dock_rgb = '255,255,255';
if (preg_match('/^#?([0-9a-f]{6})$/i', $_dock_color, $_m)) {
    $_dock_rgb = hexdec(substr($_m[1], 0, 2)) . ',' . hexdec(substr($_m[1], 2, 2)) . ',' . hexdec(substr($_m[1], 4, 2));
}

$_dock_classes = 'social-dock dock-' . $_dock_position;
if ($_dock_shape === 'square') $_dock_classes .= ' dock-square';
if ($_dock_style === 'solid')  $_dock_classes .= ' dock-solid';
if ($_dock_shadow)             $_dock_classes .= ' dock-shadow';
?>
<div class="<?php echo htmlspecialchars($_dock_classes); ?>"
     data-dock-position="<?php echo htmlspecialchars($_dock_position); ?>"
     style="--dock-color: <?php echo htmlspecialchars($_dock_color); ?>; --dock-rgb: <?php echo $_dock_rgb; ?>; --dock-bg-opacity: <?php echo $_dock_opacity / 100; ?>;">
    <?php foreach ($_dock_links as $_link): ?>
        <a href="<?php echo htmlspecialchars($_link['url']); ?>" target="_blank" rel="noopener" title="<?php echo htmlspecialchars($_link['label']); ?>" class="dock-link">
            <?php echo $_link['svg']; ?>
        </a>
    <?php endforeach; ?>
</div>
