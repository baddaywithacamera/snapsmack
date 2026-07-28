<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$page_title = 'Build a Self-Hosted Photography Website - SnapSmack';
$page_description = 'Build a self-hosted photography website with SnapSmack: free photo publishing software with albums, collections, backups, exports, and optional federation.';
$page_og_url = 'https://snapsmack.ca/self-hosted-photography.php';
$landing_eyebrow = 'Your server. Your domain. Your work.';
$landing_h1 = 'Build a Self-Hosted Photography Website';
$landing_lede = 'SnapSmack is a free self-hosted photography platform for people who want a real website, a chronological archive, and no middleman between the photographer and the photographs.';
$landing_sections = [
    [
        'heading' => 'What self-hosted actually means',
        'body' => [
            'You install SnapSmack on Linux hosting with PHP and MySQL. Your images, writing, metadata, settings, and audience relationships live in infrastructure you control.',
            'It is not a hosted account wearing a custom-domain hat. If SnapSmack disappeared tomorrow, the site and data you already run would still be yours.',
        ],
    ],
    [
        'heading' => 'A photography archive, not a generic page builder',
        'body' => [
            'Post a single photograph, build a grid or carousel, or publish a long-form photo essay. Organize work with albums, categories, collections, archive views, tags, and static pages.',
            'Views and reactions can live with the archive without turning the site into a performance dashboard. Skins change presentation without moving the underlying work.',
        ],
        'list' => [
            'SMACKONEOUT for one-image chronological publishing',
            'GRAMOFSMACK for grids, carousels, and multi-image posts',
            'SMACKTALK for writing and photography at equal billing',
            'Full backups, structured exports, and restoration tools',
        ],
    ],
    [
        'heading' => 'Supported without pretending',
        'body' => [
            'SnapSmack is supported on Linux. It may run on macOS because parts of the companion tooling use Python and browser interfaces, but macOS is not tested or supported. Apparently it runs. Good for you. It is still unsupported.',
            'Start with the <a href="brass-tacks.php">installation and resource FAQ</a>, then read why <a href="export-your-photos.php">complete exports matter</a>.',
        ],
    ],
];
require_once __DIR__ . '/includes/seo-landing.php';
// ===== SNAPSMACK EOF =====
