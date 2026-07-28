<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$page_title = 'Export Your Photography Archive - SnapSmack';
$page_description = 'Take your complete SnapSmack photography archive with you: original images, metadata, albums, categories, collections, captions, relationships, and structured JSON.';
$page_og_url = 'https://snapsmack.ca/export-your-photos.php';
$landing_eyebrow = 'No hostage situations';
$landing_h1 = 'Take Your Entire Photography Archive With You';
$landing_lede = 'Ownership is meaningless if leaving means starting over. SnapSmack backups and exports are designed to give you the files, structure, and context needed to move your work elsewhere.';
$landing_sections = [
    [
        'heading' => 'Your exit is part of the product',
        'body' => [
            'A complete export can include original images and available metadata, albums, categories, collections, captions, view totals, reaction data, relationships, and structured JSON.',
            'That is not a promise of one-click compatibility with every destination. It is the practical raw material another system needs to build a real importer.',
        ],
    ],
    [
        'heading' => 'Structured enough to transform',
        'body' => [
            'The export includes machine-readable structure plus instructions for using AI-assisted transformation when the destination expects a different import shape. You remain responsible for checking the transformed result before importing it anywhere.',
            'No external AI service receives your archive automatically. You choose the tool, the destination, and whether to use that path at all.',
        ],
        'list' => [
            'Original media and web copies',
            'Post text, captions, dates, tags, and organization',
            'Albums, categories, collections, and relationships where available',
            'Structured JSON for documented transformation',
            'Backup and recovery tooling for rebuilding SnapSmack itself',
        ],
    ],
    [
        'heading' => 'Backups are not the same as lock-in',
        'body' => [
            'A recovery backup helps rebuild this system. A portable export helps leave it. SnapSmack treats both as necessary because a platform that only lets you restore back into itself has not really given you your data.',
            'Learn how the <a href="self-hosted-photography.php">self-hosted photography platform</a> stores your work, or read the project\'s <a href="hairy-muff.php">licensing and anti-lock-in philosophy</a>.',
        ],
    ],
];
require_once __DIR__ . '/includes/seo-landing.php';
// ===== SNAPSMACK EOF =====
