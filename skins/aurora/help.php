<?php
/**
 * SNAPSMACK - AURORA Help Topics
 * Alpha v0.7.9
 *
 * Returns help content for the AURORA skin.
 * Consumed by core/sidebar.php to render the F1 help modal.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


return [
    'skin_name' => 'AURORA',
    'topics' => [
        [
            'title' => 'Three-Column Grid Layout',
            'body'  => 'AURORA presents your photographs in a clean, square-tile 3-column layout — the same format that made Instagram the de-facto portfolio platform for photographers. Every post occupies one tile on the landing page, regardless of how many images it contains. Tap or click any tile to view the full post.',
        ],
        [
            'title' => 'The Aurora Background',
            'body'  => 'Behind your photographs, slow northern-lights curtains drift across the page — drawn on a canvas, advancing through the active palette over a cycle you choose (15–240 seconds; the default is deliberately geological). It is meant to be felt, not watched. In Skin Admin under AURORA you set the palette (Aurora, Borealis Ice, or Solar Storm), the sky base colour (deep black, navy, or indigo), the cycle time, and the background opacity. The colour shift is palette-driven — the real palette colours always show true, so nothing washes out or shifts off-hue.',
        ],
        [
            'title' => 'The Border Wave',
            'body'  => 'Each tile carries an animated colour border drawn from the AURORA palette. Pick the style in Skin Admin under BORDER WAVE: Circle each tile, Circle + sweep across, Wave across grid, or Scatter pulse. You also control the direction (including the two diagonals), the wave rhythm (a slow–fast–slow breath, or constant slow), the border width (1–10 px), the border opacity, and the tile corners. The border shares the AURORA palette, so changing the palette restyles both the background and the borders at once.',
        ],
        [
            'title' => 'Motion & Accessibility',
            'body'  => 'Both layers respect your operating system\'s "reduce motion" setting. When reduced motion is on, the aurora background and the tile borders each render a single static frame — the skin stays visually coherent with zero movement. The animation also pauses automatically whenever the browser tab is hidden, so it never burns CPU in the background.',
        ],
        [
            'title' => 'Carousel Posts (Multi-Image)',
            'body'  => 'A post can hold a single image or a full carousel of images. When uploading, drag images into any order you like — the first image becomes the cover tile shown on the grid. Multi-image tiles show a small ⧉ indicator (or a count badge, depending on your Skin Admin setting) so visitors know there\'s more to see. On the post page, a swipe- and arrow-friendly carousel lets them page through each image.',
        ],
        [
            'title' => 'Uploading and Posting',
            'body'  => 'Go to Admin > New Post to upload images. You can drag multiple files onto the upload strip at once — up to 20 per post. Reorder them by dragging. Click the COVER badge on any image to promote it as the grid tile. The post title, description, categories, and albums all work the same as in other SnapSmack skins.',
        ],
        [
            'title' => 'Editing a Post',
            'body'  => 'Open any post, then click the edit icon in the admin bar (or go to Admin > Manage and find the post). The carousel editor lets you reorder images, swap the cover, remove individual images, update EXIF metadata per-image, and add more photos to an existing post — all without re-uploading.',
        ],
        [
            'title' => 'EXIF Data Panel',
            'body'  => 'When viewing a carousel post, an EXIF panel beneath the image updates automatically as you swipe through slides — showing the camera body, lens, aperture, shutter speed, ISO, and focal length for each individual photograph. EXIF is extracted at upload time and can be hand-edited per image on the edit page.',
        ],
        [
            'title' => 'Profile Header',
            'body'  => 'An optional profile header can be enabled in Skin Admin under LAYOUT. When on, it shows your site name, avatar initials (or a custom avatar image), total post count, and site description as a bio — displayed above the grid on the landing page. Toggle it off for a pure-grid look.',
        ],
        [
            'title' => 'Categories and Albums',
            'body'  => 'Archive pages (reached by clicking a category or album link) show the same 3-column grid scoped to that collection. Multi-image posts are deduplicated — each post appears as a single tile even if several of its images share the same category.',
        ],
        [
            'title' => 'Tile Appearance',
            'body'  => 'Tile gap (0–5 px), corner radius (0–8 px), and hover overlay style (title, image count, or none) are all configurable in Skin Admin under GRID. The overall content max-width can be set to 735 px, 935 px, or 1080 px to suit your preferred density.',
        ],
        [
            'title' => 'Image Frame Customisation',
            'body'  => 'AURORA lets you composite your photographs into custom frames — the kind of careful presentation photographers used to do in Photoshop actions or Instasize before the platforms stripped it out. In Skin Admin under IMAGE FRAME, set the customisation mode: Per Grid applies one style to every tile; Per Carousel lets each post define its own look; Per Image gives every individual photo its own treatment. Style controls per image: size within the square (75–100% in 5% increments), border width (0–20 px), border colour, background colour, and drop shadow intensity (none, soft, medium, heavy). When a frame is applied, the tile switches from full-bleed cover crop to a flex-centred contained image over a coloured background.',
        ],
        [
            'title' => 'Likes, Reactions & Comments',
            'body'  => 'SnapSmack\'s community system gives visitors a way to interact with your photographs. A floating dock appears on every page with a like button and a reaction picker — visitors can react without creating an account. Comments require a free community account, created directly on your site (no third-party login). Manage everything in Admin > Community Settings: toggle likes, reactions, and comments independently, set the active reaction set (up to 6 emoji), configure rate limits, and set up comment notification emails.',
        ],
        [
            'title' => 'Got Zuck Fucked?',
            'body'  => 'If Instagram pulled the rug out from under your photography and you want your grid back, the Import Tool can restore your feed from an Instagram data export. Go to Admin > Import > Instagram to get started. Your photos, your grid, your rules.',
        ],
    ],
];
// ===== SNAPSMACK EOF =====
