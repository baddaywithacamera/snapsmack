<?php
return [
    'skin_name' => 'Show N Tell',
    'topics' => [
        [
            'title' => 'Portfolio Concept',
            'body'  => 'Show N Tell is a portfolio and photoblog hybrid. It gives photographers a professional web presence without adding studio management features. The landing page has two modes: a hero slider for a dramatic first impression, or a clean justified grid for getting straight to the work. Both modes are available from the same skin — toggle the slider on or off in Skin Admin under HERO SLIDER.',
        ],
        [
            'title' => 'Hero Slider Setup',
            'body'  => 'The hero slider is fed from the media library — not from posts. This means slider images do not appear in your archive, do not count as posts, and can include composites or specially processed images that would look out of place in your blog. To set up the slider: go to Skin Admin, find the HERO SLIDER section, and use the slider asset picker to select images from your media library. Drag to reorder. The slider pulls from this curated list in the order you set. Configure autoplay, transition speed, interval, and maximum number of slides.',
        ],
        [
            'title' => 'Text Overlays',
            'body'  => 'The slider supports text overlays showing the photographer name and tagline. Two modes: Global (same text on all slides, pulled from site settings or overridden in Skin Admin) or Per-Image (uses each asset name and description). Overlay position is configurable: bottom-left, bottom-center, bottom-right, or center. Overlay style is either a dark scrim (translucent dark background behind the text) or text shadow only. Overlays can be disabled entirely.',
        ],
        [
            'title' => 'Justified Grid',
            'body'  => 'Below the slider (or as the entire landing page if the slider is off), Show N Tell renders a justified grid of recent published images. The grid uses the same Flickr-style justified layout engine as Galleria — images maintain their original aspect ratios and flow into rows of configurable height. Set the target row height and images per page in Skin Admin under GRID.',
        ],
        [
            'title' => 'Frame Styles',
            'body'  => 'Three frame options for the single image view: No Frame (edge-to-edge), Pixel Art Border (decorative pixel-art style with inset/outset shading), or Gallery Frame (Galleria-style mat and wood frame). Frame style is set globally — one style for the whole site. Choose in Skin Admin under FRAME.',
        ],
        [
            'title' => 'Contact Form',
            'body'  => 'Show N Tell includes an optional contact form via the [snapsmack_contact] shortcode. Place it on any static page. Fields: name, email, message. Sends email to your admin address via wp_mail. No form submissions are stored in the database — email only. A honeypot field provides basic spam protection. The photographer email address is displayed below the form. The form is a convenience, not a wall between you and clients.',
        ],
        [
            'title' => 'What Show N Tell Is Not',
            'body'  => 'Show N Tell is not a studio management system. It has no password-protected client galleries, no booking system, no calendar, no proofing workflow, no watermarking, no e-commerce, no print ordering, no download tracking. It is a photoblog with a professional face. Photographers who need studio management should use Pixieset, HoneyBook, or similar dedicated tools.',
        ],
    ],
];
// EOF
