<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * THE ARCHIVE PANE FOLLOWS THE VISITOR, NOT THE STORED DEFAULT.
 *
 * The owner opened photowalk.ing/archive?layout=thumbs and got a wall, with
 * the T button lit as though he had thumbnails. The resolution was never the
 * problem: core/archive-controls.php reads the URL, the cookie and the site
 * default and publishes the answer as response.archive_layout_active, and the
 * control row is drawn from the same answer. NO PAGE COMPONENT READ IT. All
 * four that render an archive re-derived the layout from site.archive_layout,
 * the stored default, which knows nothing about this visitor.
 *
 * The two sides had also drifted apart in vocabulary: archive-controls folds
 * square / cropped / croppedwithcalendar into 'thumbs', while the components
 * still tested the old four-way list.
 *
 * ONYX was worse than inconsistent. It compared the stored setting against
 * 'thumbs' -- a value that setting never holds -- so the test was false for
 * every real value and #browse-grid carried data-pane-hidden permanently.
 *
 * These checks pin the contract for all four components at once, because the
 * fault was in the shape they share, not in any one skin.
 */

require_once __DIR__ . '/../core/skin-view-contract.php';
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
require_once __DIR__ . '/../core/archive-pane.php';

$failures = [];

$view = static function (?string $active, string $stored): array {
    $response = ['status' => 200, 'kind' => 'archive', 'items' => [], 'navigation' => []];
    if ($active !== null) $response['archive_layout_active'] = $active;
    return [
        'site' => ['archive_layout' => $stored],
        'response' => $response,
    ];
};

// 1. The resolved value wins over the stored default, in both directions.
//    This is the owner's case: ?layout=thumbs on a site whose default is the wall.
if (snap_archive_pane($view('thumbs', 'masonry')) !== 'thumbs') {
    $failures[] = 'a resolved "thumbs" did not beat a stored "masonry" — the owner\'s exact complaint';
}
if (snap_archive_pane($view('masonry', 'square')) !== 'masonry') {
    $failures[] = 'a resolved "masonry" did not beat a stored "square"';
}

// 2. It FAILS OPEN. These components render every page kind, and only an
//    archive route carries the controls; a landing page must keep its grid.
if (snap_archive_pane($view(null, 'masonry')) !== 'masonry') {
    $failures[] = 'with no resolution, the stored "masonry" was not honoured';
}
if (snap_archive_pane($view(null, 'cropped')) !== 'thumbs') {
    $failures[] = 'with no resolution, a legacy thumbnail value did not fall back to thumbs';
}
if (snap_archive_pane($view(null, '')) !== 'thumbs') {
    $failures[] = 'with nothing at all, the pane was not the thumbnail grid';
}

// 3. Garbage in the resolved slot does not win. Only the two-way vocabulary.
if (snap_archive_pane($view('square', 'masonry')) !== 'masonry') {
    $failures[] = 'an old four-way value in archive_layout_active was treated as resolved';
}

// 4. Every component that renders an archive asks, and none re-derives.
$components = [
    'core/50-shades-page-component.php',
    'core/chaplin-page-component.php',
    'core/onyx-page-component.php',
    'core/impact-printer-page-component.php',
];
foreach ($components as $relative) {
    $source = (string)@file_get_contents(dirname(__DIR__) . '/' . $relative);
    if ($source === '') { $failures[] = "{$relative} could not be read"; continue; }
    if (strpos($source, 'snap_archive_pane($view)') === false) {
        $failures[] = "{$relative} does not ask snap_archive_pane() for the pane";
    }
    if (strpos($source, "require_once __DIR__ . '/archive-pane.php'") === false) {
        $failures[] = "{$relative} uses the helper without requiring it";
    }
    // The specific shape that caused this: deciding the pane from the stored
    // setting. The thumb style may still read archive_layout (CHAPLIN and
    // IMPACT PRINTER legitimately do); choosing the PANE from it may not.
    if (preg_match('/\$archivePane\s*=\s*\$archiveLayout/', $source)) {
        $failures[] = "{$relative} still derives the pane from the stored layout";
    }
}

// 5. ONYX specifically: no comparison of the stored setting against 'thumbs',
//    which is not a value it can hold.
$onyx = (string)@file_get_contents(dirname(__DIR__) . '/core/onyx-page-component.php');
if (strpos($onyx, "\$view['site']['archive_layout'] ?? '') !== 'thumbs'") !== false
    || strpos($onyx, "\$view['site']['archive_layout'] ?? '') === 'thumbs'") !== false) {
    $failures[] = 'onyx still compares the stored archive_layout against "thumbs", which it never holds';
}

if ($failures) {
    throw new RuntimeException("Archive pane contract broken:\n  - " . implode("\n  - ", $failures));
}

echo "Archive pane follows the resolved layout: passed (" . count($components) . " components).\n";
// ===== SNAPSMACK EOF =====
