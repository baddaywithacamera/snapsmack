<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/**
 * The Manage Archive thumbnail opens the post for editing.
 *
 * Sean kept clicking it expecting to edit and nothing happened. A 100px image
 * sitting beside the title is the obvious thing to reach for, and it was inert
 * while the only way in was a small EDIT button at the far right of the row.
 *
 * It matters more than tidiness: it is by far the largest target in the row,
 * and aim is not reliable for everyone. A big target that does the expected
 * thing is an accessibility feature, not a convenience.
 *
 * It must go to the SAME place as EDIT, which differs by site mode — longform
 * composer for smacktalk, the single-image editor keyed by cover id for
 * carousel, by image id otherwise. Two routes to one action is how they drift.
 */

function thumb_link_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$page = (string)file_get_contents($root . '/smack-manage.php');

// The thumbnail is wrapped in a link to the edit target.
thumb_link_check(
    str_contains($page, 'class="archive-thumb-link"'),
    'The Manage Archive thumbnail is no longer a link; clicking the obvious target does nothing again.'
);
thumb_link_check(
    (bool)preg_match(
        '/<a href="<\?php echo htmlspecialchars\(\$edit_href, ENT_QUOTES\); \?>" class="archive-thumb-link"/',
        $page
    ),
    'The thumbnail link no longer points at $edit_href. It must go where EDIT goes, not to a second route.'
);

// $edit_href must still be built before the thumbnail renders, or the link is empty.
$posThumb = strpos($page, 'class="archive-thumb-link"');
$posHref  = strpos($page, "\$edit_href = 'smack-post-long.php?edit='");
thumb_link_check($posThumb !== false && $posHref !== false, 'Could not locate the thumbnail link or the edit target.');
thumb_link_check(
    $posHref < $posThumb,
    '$edit_href is computed after the thumbnail is rendered, so the link would be empty.'
);

// All three site modes still resolve, and only once.
foreach (["smack-post-long.php?edit=", "smack-edit.php?id="] as $target) {
    thumb_link_check(str_contains($page, $target), "The edit target $target has gone from Manage Archive.");
}
thumb_link_check(
    substr_count($page, "\$edit_href = 'smack-post-long.php?edit='") === 1,
    'The edit target is computed in more than one place; the thumbnail and the button can now disagree.'
);

// Geometry lives in the master stylesheet, never inline. Sean's standing rule.
$css = (string)file_get_contents($root . '/assets/css/admin-theme-geometry-master.css');
thumb_link_check(
    str_contains($css, '.archive-thumb-link'),
    'The thumbnail link has no geometry in the master stylesheet.'
);
thumb_link_check(
    str_contains($css, '.archive-thumb-link:focus-visible'),
    'The thumbnail link has no visible keyboard focus state.'
);
thumb_link_check(
    !preg_match('/<a[^>]*class="archive-thumb-link"[^>]*style=/', $page),
    'The thumbnail link carries inline CSS. Geometry belongs in the master stylesheet.'
);

fwrite(STDOUT, "Archive thumbnail edit-link regression passed.\n");

// ===== SNAPSMACK EOF =====
