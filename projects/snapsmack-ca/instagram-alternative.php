<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$page_title = 'Self-Hosted Instagram Alternative - SnapSmack';
$page_description = 'Build a chronological photography feed on your own domain with SnapSmack, a free self-hosted Instagram alternative for independent photographers.';
$page_og_url = 'https://snapsmack.ca/instagram-alternative.php';
$landing_eyebrow = 'Independent photo sharing';
$landing_h1 = 'An Instagram Alternative You Own';
$landing_lede = 'Instagram can be where people find you. It does not have to be where your photography lives. SnapSmack gives your work a chronological home on your own domain.';
$landing_sections = [
    [
        'heading' => 'Your archive is not rented space',
        'body' => [
            'A social account is permission to decorate somebody else\'s database. A SnapSmack site is a <strong>personal photography website</strong>: your hosting, your database, your files, your domain.',
            'There is no algorithm deciding which post deserves daylight. Publish one image or a thirty-image carousel and it appears in the order you chose.',
        ],
    ],
    [
        'heading' => 'Familiar photography, independent machinery',
        'body' => [
            'GRAMOFSMACK provides the square grid, multiple-image posts, carousels, reactions, comments, albums, and collections people understand. It is not a claim of Instagram feature parity. It is the useful photographic part, rebuilt as <strong>independent photo sharing</strong>.',
            'The joy of the old web. Without the old software.',
        ],
        'list' => [
            'Chronological photo feed with no suggested-post detours',
            'Original captions, tags, dates, and files under your control',
            'Export and backup tools instead of platform lock-in',
            'Optional Fediverse reach without surrendering the canonical copy',
        ],
    ],
    [
        'heading' => 'Social can point home',
        'body' => [
            'SnapSmack can federate your public work across the Fediverse. People on compatible services can discover, follow, like, boost, and reply while the canonical photograph stays on your site.',
            'Read how <a href="fediverse-photography.php">Fediverse photography works</a>, or see how to <a href="export-your-photos.php">take your complete archive with you</a>.',
        ],
    ],
];
require_once __DIR__ . '/includes/seo-landing.php';
// ===== SNAPSMACK EOF =====
