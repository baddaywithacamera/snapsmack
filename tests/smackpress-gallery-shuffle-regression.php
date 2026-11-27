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

// ── 4. The receiving rule, now on the MOSAIC ───────────────────────────────
// PM ruling (i), 11 November: the flag lives on snap_mosaics, not snap_posts.
// Three reasons, and the first is the one that forced it: there is no way back
// from a mosaic to its post, so a post-level flag could not be read at render
// time without new plumbing through six callers. A post may also hold several
// mosaics, each a separate gallery, and one WordPress gallery maps to one
// mosaic. These assertions moved with the column.
$api = (string) @file_get_contents($root . '/core/smackpress-api.php');
gsh('core/smackpress-api.php is readable', $api !== '');

// THESE TWO WERE STRING SEARCHES AND ARE NOW EXECUTED. (SS-1612.) They looked
// for the text of the shuffle line inside core/smackpress-api.php, which could
// only ever prove that a line was written, never that it decides anything. The
// rules moved to core/mosaic-contract.php so that a test could require them,
// and that is the whole reason the move was worth making -- so the first thing
// to do with it is stop grepping and call the validator.
//
// The PDO is a real one and is never touched on this path: the validator only
// consults the schema when focus or emphasis are present, and neither is here.
require_once $root . '/core/mosaic-contract.php';
$gsh_pdo = new PDO('sqlite::memory:');
// The refusal is injected, so a refusal here would be RECORDED rather than
// exiting the run. Nothing below should refuse; if one does, the count is
// checked at the end so it cannot pass quietly.
$gsh_refusals = [];
$gsh_refuse = function (int $code, string $message) use (&$gsh_refusals): void {
    $gsh_refusals[] = $code . ' ' . $message;
};

$gsh_on  = smackpress_mosaic_validate(['asset_ids' => [1, 2], 'gallery_shuffle' => true], $gsh_pdo, $gsh_refuse);
$gsh_off = smackpress_mosaic_validate(['asset_ids' => [1, 2], 'gallery_shuffle' => false], $gsh_pdo, $gsh_refuse);
$gsh_mute = smackpress_mosaic_validate(['asset_ids' => [1, 2]], $gsh_pdo, $gsh_refuse);
gsh('none of the three valid definitions was refused', $gsh_refusals === []);

gsh('the mosaics endpoint receives gallery_shuffle ON as 1',  $gsh_on['gallery_shuffle'] === 1);
gsh('and OFF as 0, not as a missing value',                   $gsh_off['gallery_shuffle'] === 0);
gsh(
    'THE AUTHORED ORDER IS STORED EXACTLY AS SENT even with shuffle on',
    $gsh_on['asset_ids'] === [1, 2] && $gsh_mute['asset_ids'] === [1, 2]
);
// RE-EXPRESSED FROM A LITERAL TO THE INTENT. (SS-1612.) This used to require
// the exact string
//     INSERT INTO snap_mosaics (title, asset_ids, gap, layout, gallery_shuffle)
// which was one of THREE hardcoded INSERT statements, one per combination of
// weights and mirrors. Carrying focus_positions and emphasis as well would have
// made twelve, so the route now builds its column list once and appends the
// optional columns under the same conditions as before.
//
// The literal is therefore gone and the behaviour is not: gallery_shuffle sits
// in the BASE column list, unconditionally, which is a tighter claim than the
// old one. The old assertion would pass if the flag appeared in any one of the
// three statements; this one fails unless it is in the list every insert uses.
//
// Proved by mutation before this line was written: removing gallery_shuffle
// from that base list fails this check.
gsh(
    'it is stored on the mosaic row, unconditionally',
    str_contains($api, "\$columns = ['title', 'asset_ids', 'gap', 'layout', 'gallery_shuffle'];")
);
gsh(
    'a client that says nothing gets OFF rather than a missing value',
    $gsh_mute['gallery_shuffle'] === 0
);
// STILL A SOURCE CHECK, AND IT HAS TO BE. This one guards against a SHAPE
// returning rather than a value being wrong: the flag used to be read off the
// post, and the fault would be someone reaching for it with array_key_exists
// again. There is no value to execute for a line that should not exist. Both
// files are searched, because the rules moved and the fault could reappear in
// either.
$contract = (string) @file_get_contents($root . '/core/mosaic-contract.php');
gsh('core/mosaic-contract.php is readable', $contract !== '');
gsh(
    'the post no longer carries a shuffle flag at all, in either file',
    !str_contains($api, "array_key_exists('gallery_shuffle'")
        && !str_contains($contract, "array_key_exists('gallery_shuffle'")
);

// ── The column exists on the mosaic, and defaults to OFF ───────────────────
$canonical = (string) @file_get_contents($root . '/database/schema/snapsmack_canonical.sql');
gsh(
    'snap_mosaics carries gallery_shuffle, defaulting to 0',
    (bool) preg_match('/`gallery_shuffle`\s+TINYINT\(1\)\s+NOT NULL DEFAULT 0/i', $canonical)
);
// The snap_posts block is cut out FIRST and then searched. A single pattern
// spanning "snap_posts ... gallery_shuffle ... ENGINE=" runs straight past the
// end of snap_posts and finds the column on snap_mosaics instead, so it fails
// on a schema that is correct. It did.
$posts_block = '';
if (preg_match('/CREATE TABLE IF NOT EXISTS `snap_posts`(.*?)ENGINE=/is', $canonical, $pm)) {
    $posts_block = $pm[1];
}
gsh('the snap_posts block could be read',            $posts_block !== '');
gsh('and snap_posts no longer carries the flag',     !str_contains($posts_block, 'gallery_shuffle'));

if ($fails) {
    fwrite(STDERR, "\n{$fails} FAILURE(S): the shuffle receiving contract is not held. Six owner-random "
        . "galleries cannot be imported truthfully, or an authored order can be lost.\n");
    exit(1);
}
echo "SS-1185 shuffle receiving contract: PASS\n";
exit(0);

// ===== SNAPSMACK EOF =====
