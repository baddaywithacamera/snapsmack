<?php
return [
    'skin_name' => 'Galleria',
    'topics' => [
        [
            'title' => 'Floating Gallery Concept',
            'body'  => 'Galleria presents your photographs as framed artwork mounted on a floating gallery. The landing page features a horizontal slider, and the archive shows a clean grid of framed thumbnails. Wall textures (plaster, linen, concrete) can be changed in the Skin Admin.',
        ],
        [
            'title' => 'Picture Frames',
            'body'  => 'Each image is displayed in a photorealistic CSS picture frame with configurable frame colour, frame width, mat colour, mat width, bevel style, and wood grain. Site-wide defaults are set in Skin Admin under PICTURE FRAMES. Per-image overrides can be set on the Edit Transmission page in the Frame & Display section.',
        ],
        [
            'title' => 'Colour Palette',
            'body'  => 'When you upload an image, SnapSmack automatically extracts 5 dominant colours from the photograph. These appear as clickable swatches on the Edit Transmission page, making it easy to choose a frame or mat colour that complements the image. Run php tools/backfill-palettes.php to generate palettes for existing images.',
        ],
        [
            'title' => 'Slider Settings',
            'body'  => 'The landing page slider can show 1, 2, or 3 framed images at a time. Transition speed, auto-advance, and looping are all configurable in Skin Admin under SLIDER. Touch/swipe and keyboard arrows are supported.',
        ],
        [
            'title' => 'Archive Grid',
            'body'  => 'The archive displays thumbnails in miniature frames matching the hero image framing. Column count (2-6), max width, and side padding are all configurable. Frames can be toggled to plain borders if preferred.',
        ],
        [
            'title' => 'Aspect Ratios',
            'body'  => 'Galleria supports all image aspect ratios — landscape, portrait, and square. A "Force Square Crop" toggle is available in Skin Admin under SINGLE IMAGE if you prefer uniform square presentation.',
        ],
        [
            'title' => 'Likes, Reactions & Comments',
            'body'  => 'SnapSmack ships a built-in community system: likes, emoji reactions, and comments — all self-hosted on your own server, no third-party tracking. A floating dock appears on every page; visitors tap it to like or react to a photograph without needing an account. Comments require a free community account created directly on your site. Manage everything in Admin > Community Settings: toggle features on or off, pick your reaction set (up to 6 emoji), set rate limits, and configure comment notification emails.',
        ],
    ],
];
