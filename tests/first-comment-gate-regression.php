<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The comment form must not be hidden because there are no comments yet.
 *
 * Ten solo pages wrapped their comments region in
 *
 *     <?php if (!empty($view['response']['comments'])): ?>
 *
 * which is sound for a thread and fatal for a form. No comments means no
 * region, which means no form, which means nobody can leave the first one.
 * The gate guarantees its own condition stays false forever.
 *
 * Found on 19 October 2026, the day 0.7.910D put the comment system back:
 * four of nine live sites showed the form and five did not, and three of
 * those five were this -- acolourlesslife (CHAPLIN), foreverphotograph
 * (SLICKR), theschoolofhardnocks (GAME ON).
 *
 * Source assertions: these are template branches, and rendering all ten with
 * their real responses needs a database. What is pinned is that no skin ever
 * again makes the comments region conditional on comments alone.
 */

$root = dirname(__DIR__);
$fail = [];

// Every file that renders the comments component inside a conditional must
// let a community block open that conditional too.
$files = array_merge(
    glob($root . '/core/*-page-component.php') ?: [],
    glob($root . '/skins/*/layout.php') ?: [],
    glob($root . '/skins/*/landing.php') ?: []
);
$checked = 0;
foreach ($files as $file) {
    $src = (string)file_get_contents($file);
    $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $file);
    $offset = 0;
    while (($at = strpos($src, "!empty(\$view['response']['comments'])", $offset)) !== false) {
        $offset = $at + 10;
        // Only the occurrences that guard a comments render are gates.
        if (!str_contains(substr($src, $at, 400), "snap_render_component('comments'")) continue;
        $checked++;
        // The guard has to admit a community block as well.
        if (!str_contains(substr($src, max(0, $at - 60), 220), "\$view['response']['community']")) {
            $fail[] = $rel . ' hides the comment form until somebody has already commented';
        }
    }
}
if ($checked === 0) {
    $fail[] = 'no comments gate found at all -- this check has stopped checking anything';
}

// And the fragment the GRAMOFSMACK skins render in their modal, which gates
// in PHP rather than in a template.
$helpers = (string)file_get_contents($root . '/core/skin-render-helpers.php');
if (!str_contains($helpers, 'if (snap_community_block() !== null || $comments !== [])')) {
    $fail[] = 'the grid photo fragment renders comments only when some already exist';
}

if ($fail) {
    fwrite(STDERR, "FAIL: the first comment can never be left\n  - " . implode("\n  - ", $fail) . "\n");
    exit(1);
}
echo "PASS: {$checked} comments gates, every one of them open to the first comment.\n";
// ===== SNAPSMACK EOF =====
