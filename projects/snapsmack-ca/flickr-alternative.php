<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$page_title = 'Self-Hosted Flickr Alternative for Photographers - SnapSmack';
$page_description = 'Move your photography archive to your own domain with SnapSmack, a free self-hosted Flickr alternative with albums, metadata, and chronological publishing.';
$page_og_url = 'https://snapsmack.ca/flickr-alternative.php';
$landing_eyebrow = 'Bring the archive home';
$landing_h1 = 'A Flickr Alternative on Your Own Domain';
$landing_lede = 'Your Flickr archive should feel like a body of work, not a hostage negotiation. SnapSmack gives photographs, albums, metadata, and writing a home you control.';
$landing_sections = [
    [
        'heading' => 'Keep the archive, lose the landlord',
        'body' => [
            'SnapSmack is <strong>self-hosted photo publishing software</strong>. The files sit on your server and the public URLs use your domain. Albums, categories, collections, captions, tags, EXIF details, and dates remain part of the archive.',
            'SLICKR offers a familiar photostream and album presentation without pretending Flickr itself is still the only shape a large photography archive can take.',
        ],
    ],
    [
        'heading' => 'Migration built for real collections',
        'body' => [
            'FLKR FCKR is the companion migration path for Flickr exports. It is designed to move images and their useful context into SnapSmack at a rate your server can handle.',
            'No migration is magic and no two exports are identical. The point is a documented path home, not a promise that every historical oddity from every Flickr era will transform perfectly.',
        ],
        'list' => [
            'Original images and available titles, descriptions, tags, and dates',
            'Rate-controlled posting for shared hosting',
            'Albums and archive views on your own website',
            'Backups and structured exports after the move',
        ],
    ],
    [
        'heading' => 'Retro photo blogging, modern technology',
        'body' => [
            'The old web got one thing profoundly right: a site could belong to a person. SnapSmack keeps that and replaces the brittle old machinery with signed updates, modern PHP, database backups, responsive skins, and optional federation.',
            'See the broader <a href="self-hosted-photography.php">self-hosted photography platform</a> or read the <a href="brass-tacks.php">honest technical FAQ</a>.',
        ],
    ],
];
require_once __DIR__ . '/includes/seo-landing.php';
// ===== SNAPSMACK EOF =====
