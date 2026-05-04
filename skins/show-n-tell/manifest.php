<?php
/**
 * SNAPSMACK - Configuration manifest for the show-n-tell skin
 * Alpha v0.7.9
 *
 * Portfolio & photoblog hybrid skin. Clean hero slider (media library source),
 * justified grid, pixel frame and Galleria frame options, optional contact form.
 */

$inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
$fonts = $inventory['fonts'] ?? [];
foreach ($inventory['local_fonts'] ?? [] as $_k => $_f) $fonts[$_k] = $_f['label'];

return [
    'name'        => 'Show N Tell',
    'version'     => '1.0',
    'author'      => 'Sean McCormick',
    'support'     => 'sean@baddaywithacamera.ca',
    'description' => 'Portfolio & photoblog hybrid. Clean hero slider fed from the media library, justified grid below. Pixel frames or Galleria frames. Optional contact form shortcode. Professional face, photoblogging soul.',
    'status'      => 'development',

    'features' => [
        'supports_wall'   => false,
        'archive_layouts' => ['square', 'cropped', 'masonry'],
        'supports_slider' => true,
        'has_landing'     => true,
        'post_modes'      => ['image'],
        'instagram_mode'  => false,
        'carousel'        => false,
        'community'       => ['likes', 'comments'],
    ],

    'require_scripts' => [
        'smack-footer',
        'smack-image-fade-load',
        'smack-lightbox',
        'smack-keyboard',
        'smack-slider',
        'smack-justified-lib',
        'smack-justified',
        'smack-community',
        'smack-overlay',
    ],

    'options' => [

        // ── HERO SLIDER ──────────────────────────────────────────────────
        'htbs_slider_enabled' => [
            'section' => 'HERO SLIDER',
            'type'    => 'select',
            'label'   => 'Enable Hero Slider',
            'default' => '1',
            'options' => ['1' => 'Yes', '0' => 'No (grid only)'],
        ],
        'htbs_slider_autoplay' => [
            'section' => 'HERO SLIDER',
            'type'    => 'select',
            'label'   => 'Autoplay',
            'default' => '1',
            'options' => ['1' => 'On', '0' => 'Off'],
        ],
        'htbs_slider_interval' => [
            'section'  => 'HERO SLIDER',
            'type'     => 'range',
            'label'    => 'Autoplay Interval (ms)',
            'default'  => '5000',
            'min'      => '2000',
            'max'      => '10000',
            'selector' => ':root',
            'property' => '--snt-slider-interval',
            'unit'     => 'ms',
        ],
        'htbs_slider_transition' => [
            'section'  => 'HERO SLIDER',
            'type'     => 'range',
            'label'    => 'Transition Speed (ms)',
            'default'  => '800',
            'min'      => '300',
            'max'      => '1500',
            'selector' => ':root',
            'property' => '--snt-slider-speed',
            'unit'     => 'ms',
        ],
        'htbs_slider_max' => [
            'section' => 'HERO SLIDER',
            'type'    => 'range',
            'label'   => 'Max Slides',
            'default' => '10',
            'min'     => '3',
            'max'     => '20',
        ],

        // ── SLIDER OVERLAY ───────────────────────────────────────────────
        'htbs_overlay_enabled' => [
            'section' => 'SLIDER OVERLAY',
            'type'    => 'select',
            'label'   => 'Show Text Overlay',
            'default' => '1',
            'options' => ['1' => 'Yes', '0' => 'No'],
        ],
        'htbs_overlay_source' => [
            'section' => 'SLIDER OVERLAY',
            'type'    => 'select',
            'label'   => 'Overlay Source',
            'default' => 'global',
            'options' => ['global' => 'Global (site name + tagline)', 'image' => 'Per-image (title + description)'],
        ],
        'htbs_overlay_name' => [
            'section' => 'SLIDER OVERLAY',
            'type'    => 'text',
            'label'   => 'Photographer Name (override)',
            'default' => '',
        ],
        'htbs_overlay_tagline' => [
            'section' => 'SLIDER OVERLAY',
            'type'    => 'text',
            'label'   => 'Tagline (override)',
            'default' => '',
        ],
        'htbs_overlay_position' => [
            'section' => 'SLIDER OVERLAY',
            'type'    => 'select',
            'label'   => 'Overlay Position',
            'default' => 'bottom-left',
            'options' => [
                'bottom-left'   => 'Bottom Left',
                'bottom-center' => 'Bottom Center',
                'bottom-right'  => 'Bottom Right',
                'center'        => 'Center',
            ],
        ],
        'htbs_overlay_style' => [
            'section' => 'SLIDER OVERLAY',
            'type'    => 'select',
            'label'   => 'Overlay Style',
            'default' => 'scrim',
            'options' => ['scrim' => 'Dark Scrim', 'shadow' => 'Text Shadow Only'],
        ],

        // ── FRAME ────────────────────────────────────────────────────────
        'htbs_frame_style' => [
            'section' => 'FRAME',
            'type'    => 'select',
            'label'   => 'Frame Style',
            'default' => 'none',
            'options' => [
                'none'     => 'No Frame',
                'pixel'    => 'Pixel Art Border',
                'galleria' => 'Gallery Frame (Galleria-style)',
            ],
        ],

        // ── GRID ─────────────────────────────────────────────────────────
        'htbs_grid_per_page' => [
            'section' => 'GRID',
            'type'    => 'range',
            'label'   => 'Images Per Page',
            'default' => '24',
            'min'     => '8',
            'max'     => '60',
        ],
        'htbs_grid_row_height' => [
            'section'  => 'GRID',
            'type'     => 'range',
            'label'    => 'Target Row Height (px)',
            'default'  => '280',
            'min'      => '160',
            'max'      => '400',
            'selector' => ':root',
            'property' => '--snt-row-height',
            'unit'     => 'px',
        ],

        // ── HEADER ───────────────────────────────────────────────────────
        'htbs_header_bg_color' => [
            'section'  => 'HEADER',
            'type'     => 'color',
            'label'    => 'Header Background',
            'default'  => '#ffffff',
            'selector' => '.snt-header',
            'property' => 'background-color',
        ],
        'htbs_nav_color' => [
            'section'  => 'HEADER',
            'type'     => 'color',
            'label'    => 'Nav Link Colour',
            'default'  => '#555555',
            'selector' => '.snt-header .nav-menu a',
            'property' => 'color',
        ],
        'htbs_nav_hover_color' => [
            'section'  => 'HEADER',
            'type'     => 'color',
            'label'    => 'Nav Link Hover',
            'default'  => '#000000',
            'selector' => '.snt-header .nav-menu a:hover',
            'property' => 'color',
        ],

        // ── FOOTER ───────────────────────────────────────────────────────
        'htbs_footer_bg_color' => [
            'section'  => 'FOOTER',
            'type'     => 'color',
            'label'    => 'Footer Background',
            'default'  => '#f8f8f8',
            'selector' => '.snt-footer, footer',
            'property' => 'background-color',
        ],
        'htbs_footer_text_color' => [
            'section'  => 'FOOTER',
            'type'     => 'color',
            'label'    => 'Footer Text Colour',
            'default'  => '#999999',
            'selector' => '.snt-footer, footer',
            'property' => 'color',
        ],

        // ── COLOURS ──────────────────────────────────────────────────────
        'htbs_bg_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Background',
            'default'  => '#ffffff',
            'selector' => 'body',
            'property' => 'background-color',
        ],
        'htbs_text_primary' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Primary Text',
            'default'  => '#222222',
            'selector' => ':root',
            'property' => '--text-primary',
        ],
        'htbs_text_secondary' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Secondary Text',
            'default'  => '#888888',
            'selector' => ':root',
            'property' => '--text-secondary',
        ],
        'htbs_accent_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Accent Colour',
            'default'  => '#2C5F7C',
            'selector' => ':root',
            'property' => '--accent-color',
        ],

        // ── TYPOGRAPHY ───────────────────────────────────────────────────
        'htbs_title_font' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Site Title Font',
            'default'  => 'DM Sans',
            'options'  => $fonts,
            'selector' => '.snt-header .site-title-text',
            'property' => 'font-family',
        ],
        'htbs_title_size' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'range',
            'label'    => 'Site Title Size (px)',
            'default'  => '22',
            'min'      => '14',
            'max'      => '48',
            'selector' => '.snt-header .site-title-text',
            'property' => 'font-size',
            'unit'     => 'px',
        ],
        'htbs_title_color' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'color',
            'label'    => 'Site Title Colour',
            'default'  => '#222222',
            'selector' => '.snt-header .site-title-text',
            'property' => 'color',
        ],
        'htbs_body_font' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Body Font',
            'default'  => 'DM Sans',
            'options'  => $fonts,
            'selector' => 'body, .description, .meta',
            'property' => 'font-family',
        ],
    ],

    'admin_styling' => "
        .control-group-flex { display: flex; align-items: center; gap: 20px; }
        .control-group-flex input { flex: 1; }
        .active-val { width: 50px; text-align: right; font-family: monospace; }
    ",

    'community_comments'  => '1',
    'community_likes'     => '1',
    'community_reactions'  => '0',
];
// EOF
