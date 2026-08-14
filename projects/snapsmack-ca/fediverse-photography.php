<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$page_title = 'Fediverse Photography From Your Own Website - SnapSmack';
$page_description = 'Publish Fediverse photography from your own domain with SnapSmack. Keep the canonical archive on your website while enabling optional social discovery and interaction.';
$page_og_url = 'https://snapsmack.ca/fediverse-photography.php';
$landing_eyebrow = 'Your site is home';
$landing_h1 = 'A Retro Photo Blog for the Modern Fediverse';
$landing_lede = 'SnapSmack combines an independent photography website with optional Fediverse discovery. Your site remains home; federation is the road between homes.';
$landing_sections = [
    [
        'heading' => 'Publish on your site first',
        'body' => [
            'The canonical photograph, caption, date, and archive URL live on your domain. SnapSmack can syndicate public work across the Fediverse and point interaction back toward that source.',
            'This is not a claim that SnapSmack replaces a person\'s Fediverse home. It is a way for a photography website to participate without moving its centre of gravity somewhere else.',
        ],
    ],
    [
        'heading' => 'Dip a toe or dive in',
        'body' => [
            'Federation is opt-in. A site can remain an ordinary independent photoblog, publish outward, or use the built-in client to follow and interact more deeply.',
            'Followers on compatible services can discover public posts, follow, like, boost, and reply. The exact experience depends on the receiving service because the Fediverse is a neighbourhood, not a franchise.',
        ],
        'list' => [
            'Canonical work remains on the photographer\'s domain',
            'ActivityPub support is integrated into the CMS',
            'Federation can be disabled without deleting the local archive',
            'No centralized SnapSmack social account is required',
        ],
    ],
    [
        'heading' => 'Independent photo sharing with an exit',
        'body' => [
            'Federation is valuable because no single service owns the road. Portability is valuable because even open roads sometimes close. SnapSmack treats both as complements to ownership.',
            'Read about the <a href="export-your-photos.php">complete export path</a> or the broader <a href="instagram-alternative.php">independent Instagram alternative</a>.',
        ],
    ],
];
require_once __DIR__ . '/includes/seo-landing.php';
// ===== SNAPSMACK EOF =====
