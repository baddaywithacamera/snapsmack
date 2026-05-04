<?php
return [
    'skin_name' => '52 Card Pickup',
    'topics' => [
        [
            'title' => 'The Pile Concept',
            'body'  => '52 Card Pickup presents your photo archive as a randomised pile of physical prints scattered on a surface. Each page load shows a random selection of images with random rotations, positions, and z-index stacking. Click any image to view it. Click Reshuffle (or press R) to throw a new set of prints.',
        ],
        [
            'title' => 'Frame Styles',
            'body'  => 'Four frame styles are available: Polaroid (white border, wider at bottom), Standard Print (thin even white border), Borderless (no frame, subtle dog-ear corner), and Slide Mount (black border with inner highlight). Configure which styles are in the random pool under Skin Admin > FRAME STYLES. If only one is enabled, all images use that style.',
        ],
        [
            'title' => 'Pile Settings',
            'body'  => 'Pile Size controls how many images appear (10-30). Scatter Radius sets how far images spread from centre (tight, medium, wide). Max Rotation sets the tilt angle. Max Image Width caps the display size. All configurable in Skin Admin under THE PILE.',
        ],
        [
            'title' => 'Reshuffle',
            'body'  => 'The Reshuffle button (fixed bottom-right) loads a new random set of images via AJAX without a page reload. The current pile fades out, a fresh random set is fetched, and the new pile fades in. The button label is configurable. Press R on the keyboard for the same effect (can be disabled).',
        ],
        [
            'title' => 'Single Image View',
            'body'  => 'Clicking any image in the pile opens the standard single-image view with title, description, EXIF data, and comments. A "Return to Pile" link navigates back to the landing page, which re-randomises on return — this is intentional. The point is discovery, not navigation.',
        ],
        [
            'title' => 'Archive',
            'body'  => 'The ARCHIVE link in the nav shows a standard square-thumbnail grid for visitors who want to browse systematically. This is the same archive system used by other SnapSmack skins.',
        ],
    ],
];
// EOF
