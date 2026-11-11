<?php
/**
 * SS-1185 — the shuffle receiving contract.
 *
 * Six of the eleven galleries being migrated from mccormickphotography.com are
 * owner-random: WordPress showed their photographs in a different order on each
 * view. Current dev has nowhere to put that fact, so those six cannot be
 * imported truthfully — the migration either loses the setting or lies about it.
 * Sean's Selections is NOT one of the six, and its 73 photographs have an
 * authored order that must not move.
 *
 * THE CONTRACT, in the order it matters:
 *
 *   1. OFF IS THE DEFAULT. A post that never mentions shuffle renders in its
 *      authored order, every time, deterministically.
 *   2. SHUFFLE IS PRESENTATION, NOT STORAGE. The stored order is never
 *      rewritten. Turning shuffle off must give the authored order back
 *      exactly, which is only true if nothing ever scrambled what was saved.
 *   3. A SHUFFLE IS A PERMUTATION. Same photographs, same count, no duplicates,
 *      none lost. This is the one that would quietly ruin a gallery, so it is
 *      checked over many runs rather than once.
 *   4. AN OLDER CLIENT CANNOT CLEAR IT. A client that does not know the field
 *      sends no key, and its edit must leave the stored value alone — the same
 *      array_key_exists rule portfolio_post, colophon and signature_image_id
 *      already follow. Sending an explicit value CAN change it, on purpose.
 *
 * Written before the implementation, as ordered. Everything here is pure: the
 * ordering helper takes ids and returns ids, so "does not lose or duplicate
 * IDs" is provable without a database, a site or a browser.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root  = dirname(__DIR__);
$fails = 0;

function gsh(string $label, bool $ok): void {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fails++;
}

// ── The helper must exist and be loadable on its own ───────────────────────
// Deliberately NOT core/bucket.php's DB functions: the ordering decision is
// pulled out as a pure function precisely so it can be proven here.
$helper = $root . '/core/gallery-order.php';
gsh('core/gallery-order.php exists', is_file($helper));
if (!is_file($helper)) {
    fwrite(STDERR, "\nThe shuffle receiving contract has no implementation yet.\n");
    exit(1);
}
require_once $helper;
gsh('snap_gallery_present_order() is defined', function_exists('snap_gallery_present_order'));
if (!function_exists('snap_gallery_present_order')) {
    fwrite(STDERR, "\nsnap_gallery_present_order() is missing.\n");
    exit(1);
}

$authored = [11, 7, 93, 4, 58, 2, 61, 19, 73, 40];

// ── 1. OFF is the default, and it is deterministic ─────────────────────────
gsh(
    'shuffle OFF returns the authored order unchanged',
    snap_gallery_present_order($authored, false) === $authored
);

$same = true;
for ($i = 0; $i < 25; $i++) {
    if (snap_gallery_present_order($authored, false) !== $authored) { $same = false; break; }
}
gsh('shuffle OFF is deterministic across repeated renders', $same);

gsh(
    'an absent flag behaves as OFF',
    snap_gallery_present_order($authored) === $authored
);

// ── 2. The caller's array is never mutated ─────────────────────────────────
// If shuffling rewrote what was passed in, the stored order would drift every
// time a page was rendered, and nobody would notice until it was unrecoverable.
$before = $authored;
snap_gallery_present_order($authored, true);
gsh('shuffling does not mutate the array it was given', $authored === $before);

// ── 3. A shuffle is a permutation, over many runs ──────────────────────────
$lost = $dupe = $wrongCount = 0;
$sortedAuthored = $authored;
sort($sortedAuthored);
for ($i = 0; $i < 200; $i++) {
    $out = snap_gallery_present_order($authored, true);
    if (count($out) !== count($authored)) { $wrongCount++; continue; }
    if (count(array_unique($out)) !== count($out)) { $dupe++; }
    $s = $out; sort($s);
    if ($s !== $sortedAuthored) { $lost++; }
}
gsh('shuffle ON never changes the photograph count (200 runs)', $wrongCount === 0);
gsh('shuffle ON never duplicates a photograph (200 runs)',      $dupe === 0);
gsh('shuffle ON never loses a photograph (200 runs)',           $lost === 0);

// It must actually shuffle. A no-op would pass every check above.
$moved = false;
for ($i = 0; $i < 50; $i++) {
    if (snap_gallery_present_order($authored, true) !== $authored) { $moved = true; break; }
}
gsh('shuffle ON actually reorders (not a silent no-op)', $moved);

// ── Degenerate inputs must not throw or invent ─────────────────────────────
gsh('an empty gallery stays empty', snap_gallery_present_order([], true) === []);
gsh('a one-photograph gallery is returned as-is', snap_gallery_present_order([42], true) === [42]);

// ── 4. The receiving rule: an older client cannot clear the flag ───────────
// Checked against core/smackpress-api.php, where the field arrives.
$api = (string) @file_get_contents($root . '/core/smackpress-api.php');
gsh('core/smackpress-api.php is readable', $api !== '');

gsh(
    'the import keys the shuffle flag on array_key_exists, not on truthiness',
    (bool) preg_match("/array_key_exists\(\s*'gallery_shuffle'\s*,\s*\\\$body\s*\)/", $api)
);
gsh(
    'the flag is only written when the caller actually sent it (UPDATE path)',
    (bool) preg_match('/\$has_gallery_shuffle\s*\)\s*\{[^}]*gallery_shuffle=\?/s', $api)
);
gsh(
    'the flag is only written when the caller actually sent it (INSERT path)',
    (bool) preg_match('/\$has_gallery_shuffle\s*\)\s*\{[^}]*,gallery_shuffle/s', $api)
);

// ── The column exists, and defaults to OFF ─────────────────────────────────
$canonical = (string) @file_get_contents($root . '/database/schema/snapsmack_canonical.sql');
gsh(
    'snap_posts carries gallery_shuffle, defaulting to 0',
    (bool) preg_match('/`gallery_shuffle`\s+tinyint\(1\)\s+NOT NULL DEFAULT 0/i', $canonical)
);

if ($fails) {
    fwrite(STDERR, "\n{$fails} FAILURE(S): the shuffle receiving contract is not held. Six owner-random "
        . "galleries cannot be imported truthfully, or an authored order can be lost.\n");
    exit(1);
}
echo "SS-1185 shuffle receiving contract: PASS\n";
exit(0);

// ===== SNAPSMACK EOF =====
