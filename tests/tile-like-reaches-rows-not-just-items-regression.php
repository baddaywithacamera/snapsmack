<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * THE HEART MUST REACH EVERY LIST A SKIN ITERATES, NOT JUST items.
 *
 * SS-1323's blocking fault, found by Muse in final review. The runtime
 * annotated $response['items'] and nothing else. The controller builds GLIDE's
 * $rows as VALUE COPIES of those items -- $rows[$index % 9][] = $item in
 * core/public-controller.php -- BEFORE the runtime runs, so a row's copy of a
 * photograph never saw the heart. GLIDE's layout iterates rows whenever the
 * wall has photographs in it, so the control would have appeared only on the
 * empty-rows fallback: hearts exactly when there is nothing to like.
 *
 * WHY IT SURVIVED A PASSING BROWSER GATE: that test builds its own fixture
 * markup with the hearts already in it, so it exercises the engine and the
 * layout but never the controller -> runtime -> layout seam where the fault
 * was. A green rendered test and a green source test can both be right and
 * still miss the one join between them.
 *
 * THIS TEST EXECUTES THE WRITE-BACK. It is not a source check. The annotation
 * was moved out of snapsmack_public_runtime() into snap_tile_like_annotate()
 * for exactly this reason: inside the runtime it could not be run without a
 * database, a repository and a whole request, so a test could only read it and
 * agree with it.
 */

require_once __DIR__ . '/../core/tile-like-control.php';

$failures = [];
$heart = ['41' => '<button data-tile-like="41"></button>'];
$html = [41 => '<button data-tile-like="41"></button>',
         42 => '<button data-tile-like="42"></button>'];

// --- 1. a GLIDE response: bare rows of items, the shape that was broken ----
$glide = [
    'items' => [['id' => 41], ['id' => 42]],
    // Value copies, exactly as the controller leaves them, and deliberately
    // WITHOUT tile_like: that is what the fault looked like.
    'rows' => [0 => [['id' => 41]], 1 => [['id' => 42]], 2 => []],
];
$out = snap_tile_like_annotate($glide, $html);

if (($out['items'][0]['tile_like'] ?? '') === '') {
    $failures[] = 'items lost their heart';
}
if (($out['rows'][0][0]['tile_like'] ?? '') === '') {
    $failures[] = 'a GLIDE row got no heart -- the wall shows the control only '
        . 'when it is empty, which is the SS-1323 blocking fault';
}
if (($out['rows'][1][0]['tile_like'] ?? '') === '') {
    $failures[] = 'the second row got no heart';
}
if (($out['rows'][0][0]['tile_like'] ?? '') !== ($out['items'][0]['tile_like'] ?? 'x')) {
    $failures[] = 'the row copy and the item copy of one photograph disagree';
}
if (($out['rows'][2] ?? null) !== []) {
    $failures[] = 'an empty row was not left alone';
}

// --- 2. the other rows shape in this codebase ------------------------------
// core/public-controller.php:331 builds ['items' => $row, 'full' => true].
$wrapped = ['rows' => [['items' => [['id' => 41]], 'full' => true]]];
$out = snap_tile_like_annotate($wrapped, $html);
if (($out['rows'][0]['items'][0]['tile_like'] ?? '') === '') {
    $failures[] = 'a wrapped row (["items" => ..., "full" => ...]) got no heart';
}
if (($out['rows'][0]['full'] ?? null) !== true) {
    $failures[] = 'annotating a wrapped row dropped its other keys';
}

// --- 3. a photograph with no prepared heart prints nothing -----------------
$unknown = ['items' => [['id' => 99]], 'rows' => [[['id' => 99]]]];
$out = snap_tile_like_annotate($unknown, $html);
if (($out['items'][0]['tile_like'] ?? 'x') !== '') {
    $failures[] = 'an id with no prepared heart did not get an empty string';
}
if (($out['rows'][0][0]['tile_like'] ?? 'x') !== '') {
    $failures[] = 'an id with no prepared heart got something in a row';
}

// --- 4. nothing to annotate is not an error --------------------------------
$empty = snap_tile_like_annotate([], $html);
if ($empty !== []) {
    $failures[] = 'an empty response was changed';
}
$noRows = snap_tile_like_annotate(['items' => [['id' => 41]]], $html);
if (($noRows['items'][0]['tile_like'] ?? '') === '') {
    $failures[] = 'a response with no rows at all lost its item hearts';
}

// --- 5. the enumeration itself: rows are in the list of lists --------------
// snap_tile_like_lists() is what decides which lists get ids collected from
// them. If it forgets rows, the ids are never queried and every heart in a
// row would be '' even with the write-back above correct.
$lists = snap_tile_like_lists($glide);
$seen = [];
foreach ($lists as $list) {
    foreach ($list as $item) {
        if (is_array($item)) $seen[] = (int)($item['id'] ?? 0);
    }
}
foreach ([41, 42] as $id) {
    if (count(array_keys($seen, $id, true)) < 2) {
        $failures[] = "photograph {$id} was not enumerated in both items and "
            . 'rows, so its liked state is never asked for in one of them';
    }
}
if (snap_tile_like_lists(['rows' => [['items' => [['id' => 41]]]]]) === []) {
    $failures[] = 'a wrapped row was not enumerated at all';
}

if ($failures) {
    throw new RuntimeException("Tile heart does not reach every list:\n  - "
        . implode("\n  - ", $failures));
}

echo "Tile heart reaches items and rows, both row shapes: passed.\n";
// ===== SNAPSMACK EOF =====
