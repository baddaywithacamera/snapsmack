<?php
/**
 * SNAPSMACK - Manifest for the Alfred skin
 * v1.0.0
 *
 * A SmackTalk skin for photographers who write. Named for Alfred Hitchcock,
 * honouring Anders Norén's Hitchcock WordPress theme whose visual design
 * this skin faithfully recreates for the SnapSmack platform.
 *
 * Exact visual match: dark navigation bar, full-width header image,
 * Montserrat navigation, Droid Serif body text, archive grid of post tiles.
 *
 * @author Sean McCormick
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

return [
    'name'            => 'ALFRED',
    'version'         => '1.0.0',
    'author'          => 'Sean McCormick',
    'author_email'    => 'sean@baddaywithacamera.ca',
    'description'     => 'A SMACKTALK skin for photographers who write. Faithful recreation of Anders Norén\'s Hitchcock WordPress theme — dark navigation, Montserrat headings, Droid Serif body text, full-width header image. Named for Alfred Hitchcock.',
    'status'          => 'stable',      // Flipped for the SMACKTALK/Noah Grey rollout.
                                        // NOTE: 'stable' only makes ALFRED eligible for
                                        // the gallery/registry — it does NOT publish it.
                                        // The Skin Packager must be re-run so ALFRED
                                        // enters registry.json (signed) before any
                                        // install can fetch it.

    // Alfred is SmackTalk only. It provides preload.php which handles
    // longform post routing before index.php reaches its image logic.
    'modes'           => ['smacktalk'],

    // Declares the skin pre-load hook. index.php will include preload.php
    // before its image routing if this file exists. Alfred uses it to
    // intercept the request, render the SmackTalk feed or single post,
    // and exit — so index.php's image logic never runs.
    'skin_preload'    => 'preload.php',

    'variants' => [
        'default' => 'Alfred (Dark Nav / White Content)',
    ],
    'default_variant' => 'default',

    // SMACKTALK post-cover frame shape (CSS aspect-ratio). Pan/zoom crops within
    // it; the admin cover cropper frames to this exact ratio.
    'cover_aspect'    => '1/1',

    'features' => [
        'supports_wall'   => false,
        'has_landing'     => false,
        'post_modes'      => ['longform'],
        'instagram_mode'  => false,
        'carousel'        => false,
        'community'       => ['comments'],
    ],

    'require_scripts' => [
        'smack-alfred-nav',
        'smack-footer',
        'smack-community',
    ],

    'community_comments'  => '1',
    'community_likes'     => '0',
    'community_reactions' => '0',

    'options' => [

        /* ============================================================
           SECTION 1: HEADER
           ============================================================ */

        'header_image' => [
            'section'  => 'HEADER',
            'type'     => 'asset',
            'label'    => 'Header Background Image',
            'default'  => '',
            'help'     => 'Full-width image displayed behind the site logo. Recommended: 1440×900px minimum. Leave blank for the default dark grey.',
        ],

        'header_logo' => [
            'section'  => 'HEADER',
            'type'     => 'asset',
            'label'    => 'Custom Logo',
            'default'  => '',
            'help'     => 'Upload a logo image to replace the site title text. Transparent PNG recommended.',
        ],

        'retina_logo' => [
            'section'  => 'HEADER',
            'type'     => 'select',
            'label'    => 'Retina Logo',
            'default'  => '0',
            'options'  => ['0' => 'No', '1' => 'Yes (display at half size)'],
            'help'     => 'If your logo is uploaded at 2x resolution, enable this to display it at half size for sharp rendering on retina screens.',
        ],

        'show_tagline' => [
            'section'  => 'HEADER',
            'type'     => 'select',
            'label'    => 'Show Tagline',
            'default'  => '1',
            'options'  => ['1' => 'Show', '0' => 'Hide'],
            'help'     => 'Hide the site tagline under the logo/title (useful when your logo already includes it). The tagline still appears in RSS and social/SEO meta.',
        ],

        /* ============================================================
           SECTION 2: COLOURS
           ============================================================ */

        'accent_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Accent Colour',
            'default'  => '#1e73be',
            'selector' => ':root',
            'property' => '--alfred-accent',
            'help'     => 'Used for links, buttons, hover states, and interactive elements.',
        ],

        /* ============================================================
           SECTION 3: TYPOGRAPHY
           ============================================================ */

        'show_post_titles' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Always Show Post Titles in Archive',
            'default'  => '0',
            'options'  => ['0' => 'On hover only', '1' => 'Always visible'],
        ],

        /* ============================================================
           SECTION 4: LAYOUT
           ============================================================ */

        'post_content_width' => [
            'section'  => 'LAYOUT',
            'type'     => 'range',
            'label'    => 'Post Content Width (px)',
            'default'  => '800',
            'min'      => '480',
            'max'      => '1100',
            'selector' => '.post-inner',
            'property' => 'width',
            'unit'     => 'px',
        ],

        'posts_per_page' => [
            'section'  => 'LAYOUT',
            'type'     => 'range',
            'label'    => 'Posts Per Page (archive)',
            'default'  => '12',
            'min'      => '4',
            'max'      => '40',
        ],

    ],
];
// ===== SNAPSMACK EOF =====
