<?php
/**
 * SNAPSMACK - PARADE Help Topics
 * Alpha v0.7.9
 *
 * Returns help content for the PARADE skin.
 * Consumed by core/sidebar.php to render the F1 help modal.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


return [
    'skin_name' => 'PARADE',
    'topics' => [
        [
            'title' => 'Three-Column Grid Layout',
            'body'  => 'PARADE presents your photographs in a clean, square-tile 3-column layout — the same format that made Instagram the de-facto portfolio platform for photographers. Every post occupies one tile on the landing page, regardless of how many images it contains. Tap or click any tile to view the full post.',
        ],
        [
            'title' => 'The Fireworks Background',
            'body'  => 'PARADE is AURORA\'s daylight twin. Behind your photographs, slow-motion fireworks drift across a high-key white field, painted in the identity flag you choose. Rockets launch in real time while the particles themselves run on a slowed clock, so the sky can ease to a near-freeze and back. In Skin Admin under PARADE you set the Flag Palette, the Background (Pure white, Soft white, Warm white, or a faint Palette wash), Busyness (launches per second), Motion (the slow-motion amount), Burst Size, and Softness (how far the flag colours are eased toward pastel so they read against white). Flag hues always stay true.',
        ],
        [
            'title' => 'Flag Palettes — A Real Show of Support',
            'body'  => 'PARADE is built to fly a flag, not toggle a token. Pick the palette that represents you or your community: Rainbow (six-stripe Pride), Progress Pride, Trans, Bisexual, Non-Binary, or Two-Spirit. Each individual burst samples across the whole palette, so a single firework paints the entire flag rather than one flat colour. Adding a flag is a one-line entry in parade-config.php — no code change.',
        ],
        [
            'title' => 'Fine-Tuning the Fireworks',
            'body'  => 'Under FIREWORKS DETAIL you can dial in Burst Spread (how wide each burst opens), Rocket Speed (how fast they rise before bursting), and Streamer Width (the thickness of the particle trails). The defaults are a good starting point — adjust on your own monitor until the rhythm feels right.',
        ],
        [
            'title' => 'Motion & Accessibility',
            'body'  => 'The fireworks respect your operating system\'s "reduce motion" setting: when it is on, the background settles into a few static, coloured bursts — coherent and still, with no animation. The animation also pauses automatically whenever the browser tab is hidden, so it never burns CPU in the background. The canvas renders at half resolution with a light blur so it stays soft behind the photography.',
        ],
        [
            'title' => 'Text Colours for the Bright Field',
            'body'  => 'Because PARADE runs on a bright background, text defaults to dark for legibility. Under TEXT in Skin Admin you can set the Primary text colour, the Muted text colour, and the Accent colour used for links and the active nav item.',
        ],
        [
            'title' => 'Carousel Posts (Multi-Image)',
            'body'  => 'A post can hold a single image or a full carousel of images. When uploading, drag images into any order you like — the first image becomes the cover tile shown on the grid. Multi-image tiles show a small indicator so visitors know there\'s more to see. On the post page, a swipe- and arrow-friendly carousel lets them page through each image.',
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
            'body'  => 'An optional profile header can be enabled in Skin Admin under PROFILE HEADER. When on, it shows your site name, avatar (a custom image or your initials), total post count, and site description as a bio — displayed above the grid on the landing page. Toggle it off for a pure-grid look.',
        ],
        [
            'title' => 'Categories and Albums',
            'body'  => 'Archive pages (reached by clicking a category or album link) show the same 3-column grid scoped to that collection. Multi-image posts are deduplicated — each post appears as a single tile even if several of its images share the same category.',
        ],
        [
            'title' => 'Likes, Reactions & Comments',
            'body'  => 'SnapSmack\'s community system gives visitors a way to interact with your photographs. A floating dock appears on every page with a like button and a reaction picker — visitors can react without creating an account. Comments require a free community account, created directly on your site (no third-party login). Manage everything in Admin > Community Settings.',
        ],
        [
            'title' => 'Got Zuck Fucked?',
            'body'  => 'If Instagram pulled the rug out from under your photography and you want your grid back, the Import Tool can restore your feed from an Instagram data export. Go to Admin > Import > Instagram to get started. Your photos, your grid, your rules — under whatever flag you fly.',
        ],
    ],
];
// ===== SNAPSMACK EOF =====
