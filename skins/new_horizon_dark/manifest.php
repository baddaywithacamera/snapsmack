<?php
/**
 * SnapSmack Skin Manifest: New Horizon Dark
 * Version: 3.5 - Global Inventory Integration
 * -------------------------------------------------------------------------
 * - ADDED: Dynamic Font Pull from core/manifest-inventory.php.
 * - RESTORED: All structural and descriptive comments from Version 3.4.
 * - FIXED: Header Typography selector targets '.logo-area a' for underline control.
 * - FIXED: Header Font Size targets '.site-title-text' for precise scaling.
 * - RETAINED: Master Height Locks (Optical Lift, Header, Infobox).
 * - RETAINED: Static Page & Wall Engine Typography settings.
 * - DIRECTIVE: FULL FILE OUTPUT. NO TRUNCATION. NO LOGIC STRIPPING.
 * -------------------------------------------------------------------------
 */

// PULL GLOBAL FONT INVENTORY FROM CORE
// This ensures every skin on the system uses the same high-end tactical library.
$fonts = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');

return [
    'name'          => 'New Horizon Dark',
    'version'       => '3.5',
    'author'        => 'Sean McCormick',
    'support'       => 'sean@baddaywithacamera.ca',
    'description'   => 'A reimagining of the classic Horizon theme for the original Pixelpost by Jay-C.',
    
    /* -------------------------------------------------------------------------
       ENGINE FEATURES
       Defines what core SnapSmack modules this skin supports.
       ------------------------------------------------------------------------- */
    'features' => [
        'supports_wall' => true, 
    ],

    'options' => [
        
        /* ---------------------------------------------------------------------
           SECTION 1: SKIN SPECIFIC (Framing, Branding & Height Locks)
           These controls affect the unique visual character of the Horizon skin.
           --------------------------------------------------------------------- */
        
        // Main Image Framing logic using unique CSS payloads
        'image_frame_style' => [
            'section'  => 'SKIN SPECIFIC',
            'type'     => 'select',
            'label'    => 'Main Image Frame Style',
            'default'  => 'revival_double',
            'selector' => 'img.post-image, .thumb-link, .inline-asset, .static-transmission .description .align-left',
            'property' => 'custom-framing',
            'options'  => [
                'revival_double' => [
                    'label' => 'Revival Double Line (Grey/Black/Grey)',
                    'css'   => '{ border: 5px solid #666666 !important; box-shadow: 0 0 0 15px #000000, 0 0 0 16px #666666 !important; }'
                ],
                'classic_white'  => [
                    'label' => 'Classic Horizon (Thick White)',
                    'css'   => '{ border: 20px solid #ffffff !important; box-shadow: 0 0 0 1px #333333 !important; }'
                ],
                'gallery_mat'    => [
                    'label' => 'Gallery Multi-Mat (White / Med-Grey / White)',
                    'css'   => '{ border: 3px solid #ffffff !important; box-shadow: 0 0 0 15px #666666, 0 0 0 16px #ffffff !important; }'
                ],
                'minimal_bevel'  => [
                    'label' => 'Minimal Bevel (Thin White / Black / White)',
                    'css'   => '{ border: 1px solid #ffffff !important; box-shadow: 0 0 0 8px #000000, 0 0 0 9px #ffffff !important; }'
                ],
                'obsidian'       => [
                    'label' => 'Obsidian (Dark Grey / Black / Dark Grey)',
                    'css'   => '{ border: 1px solid #333333 !important; box-shadow: 0 0 0 20px #111111, 0 0 0 21px #333333 !important; }'
                ]
            ]
        ],

        // Header Branding Font - Targets the Link tag to kill underlines
        // DYNAMICALLY POPULATED FROM CORE INVENTORY
        'header_font_family' => [
            'section'  => 'SKIN SPECIFIC',
            'type'     => 'select',
            'label'    => 'Header Typography (Text Logo)',
            'default'  => 'Playfair Display',
            'options'  => $fonts, 
            'selector' => '.logo-area a', 
            'property' => 'font-family'
        ],

        // Header Branding Size - Targets the Text wrapper for scaling
        'header_font_size' => [
            'section'  => 'SKIN SPECIFIC',
            'type'     => 'range',
            'label'    => 'Header Font Size (px)',
            'default'  => '50',
            'min'      => '40',
            'max'      => '100',
            'selector' => '.site-title-text',
            'property' => 'font-size'
        ],

        // Vertical spacing control for the main photo box
        'optical_lift' => [
            'section'  => 'SKIN SPECIFIC',
            'type'     => 'range',
            'label'    => 'Optical Vertical Lift (px)',
            'default'  => '50',
            'min'      => '0',
            'max'      => '150',
            'selector' => '#photobox',
            'property' => 'padding-bottom'
        ],

        // Global Height Lock for the Header container
        'header_height' => [
            'section'  => 'SKIN SPECIFIC',
            'type'     => 'range',
            'label'    => 'Header Height Lock (px)',
            'default'  => '80',
            'min'      => '40',
            'max'      => '150',
            'selector' => '#header',
            'property' => 'height'
        ],

        // Global Height Lock for the Footer/Infobox container
        'infobox_height' => [
            'section'  => 'SKIN SPECIFIC',
            'type'     => 'range',
            'label'    => 'Infobox Height Lock (px)',
            'default'  => '50',
            'min'      => '30',
            'max'      => '100',
            'selector' => '#infobox',
            'property' => 'height'
        ],

        /* ---------------------------------------------------------------------
           SECTION 2: STATIC PAGE STYLING (Typography & Spacing)
           Controls the look of About, Contact, and Dynamic Page content.
           --------------------------------------------------------------------- */

        // Dynamic Pull from Core Inventory
        'static_heading_font' => [
            'section'  => 'STATIC PAGE STYLING',
            'type'     => 'select',
            'label'    => 'Heading Typography',
            'default'  => 'Helvetica Neue',
            'options'  => $fonts,
            'selector' => '.static-page-title, .photo-title-footer',
            'property' => 'font-family'
        ],

        // Dynamic Pull from Core Inventory
        'static_body_font' => [
            'section'  => 'STATIC PAGE STYLING',
            'type'     => 'select',
            'label'    => 'Body Typography',
            'default'  => 'Georgia',
            'options'  => $fonts,
            'selector' => '.static-content, .description',
            'property' => 'font-family'
        ],

        'static_section_spacing' => [
            'section'  => 'STATIC PAGE STYLING',
            'type'     => 'range',
            'label'    => 'Vertical Section Spacing (px)',
            'default'  => '40',
            'min'      => '10',
            'max'      => '120',
            'selector' => '.static-content',
            'property' => 'margin-top'
        ],

        /* ---------------------------------------------------------------------
           SECTION 3: WALL SPECIFIC (3D Engine Physics & Visuals)
           Controls the movement and lighting behaviors of the 3D Gallery Wall.
           --------------------------------------------------------------------- */

        'wall_friction' => [
            'section'  => 'WALL SPECIFIC',
            'type'     => 'number',
            'label'    => 'Wall Friction (0.1 - 0.99)',
            'default'  => '0.96',
            'selector' => ':root',
            'property' => '--wall-friction'
        ],

        'wall_dragweight' => [
            'section'  => 'WALL SPECIFIC',
            'type'     => 'number',
            'label'    => 'Drag Weight',
            'default'  => '2.5',
            'selector' => ':root',
            'property' => '--wall-drag-weight'
        ],

        'wall_shadow_intensity' => [
            'section'  => 'WALL SPECIFIC',
            'type'     => 'select',
            'label'    => 'Shadow Intensity',
            'default'  => 'heavy',
            'options'  => [
                'none'  => 'No Shadow',
                'light' => 'Light / Subtle',
                'heavy' => 'Heavy / Deep Glow'
            ],
            'selector' => '.wall-item img',
            'property' => 'filter'
        ],

        // Dynamic Pull from Core Inventory
        'wall_font_ref' => [
            'section'  => 'WALL SPECIFIC',
            'type'     => 'select',
            'label'    => 'Title Typography',
            'default'  => 'Playfair Display',
            'options'  => $fonts,
            'selector' => '.wall-title',
            'property' => 'font-family'
        ],

        'wall_theme' => [
            'section'  => 'WALL SPECIFIC',
            'type'     => 'color',
            'label'    => 'Wall Background Color',
            'default'  => '#000000',
            'selector' => 'body.is-wall',
            'property' => 'background-color'
        ],

        'wall_text_color' => [
            'section'  => 'WALL SPECIFIC',
            'type'     => 'color',
            'label'    => 'Floating Title Color',
            'default'  => '#808080',
            'selector' => '.wall-title',
            'property' => 'color'
        ],

        'wall_shadow_color' => [
            'section'  => 'WALL SPECIFIC',
            'type'     => 'color',
            'label'    => 'Shadow/Glow Color',
            'default'  => '#000000',
            'selector' => '.wall-item img',
            'property' => '--glow-color'
        ]
    ],

    /* -------------------------------------------------------------------------
       ADMIN INTERFACE STYLING
       Injects custom layout rules specifically for this skin's control tab.
       ------------------------------------------------------------------------- */
    'admin_styling' => "
        .metadata-selector-row { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-top: -15px; 
            margin-bottom: 50px; 
        }
        .skin-switcher-form { display: flex; align-items: center; gap: 10px; }
        .skin-switcher-form label { margin: 0 !important; }
        .control-group-flex { display: flex; align-items: center; gap: 20px; }
        .control-group-flex input { flex: 1; }
        .active-val { width: 50px; text-align: right; font-family: monospace; }
        .hex-display { font-family: monospace; }
    "
];