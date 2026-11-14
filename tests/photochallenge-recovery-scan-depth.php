<?php
/**
 * SS-1337. Recovery must read deep enough into a participant's outbox to find
 * their challenge entry.
 *
 * pc_recover_tagged_entries() passed a hardcoded 12 to pc_rescan_participants(),
 * so a participant who had posted more than twelve originals since entering had
 * that entry fall off the bottom of the scan. Curb Appeal stopped at 12/20.
 *
 * This test DRIVES the real reader, it does not read the source for a number.
 * A stub outbox serves 30 originals across three pages with the tagged entry
 * sitting 20 posts down. At the old depth of 12 the entry is not returned; at
 * the depth recovery now uses it is. Then the call sites are checked, because a
 * correct constant nobody passes would fix nothing.
 */

// Defined BEFORE the include, so photochallenge.php's function_exists() checks find it.
$GLOBALS['stub_outbox'] = [];
function sv_fetch_ap(string $url, array $settings = []) {
    return $GLOBALS['stub_outbox'][$url] ?? null;
}

require_once dirname(__DIR__) . '/core/photochallenge.php';

$fails = [];

// --- build a 30-post outbox, 10 notes per page, the entry at index 19 --------
$pages = [];
for ($p = 0; $p < 3; $p++) {
    $items = [];
    for ($i = 0; $i < 10; $i++) {
        $n = $p * 10 + $i;
        $note = ['type'=>'Note', 'id'=>'https://pixelfed.test/p/' . $n,
                 'url'=>'https://pixelfed.test/p/' . $n, 'published'=>'2026-11-13T12:00:00Z',
                 'tag'=>[]];
        if ($n === 19) $note['tag'][] = ['type'=>'Hashtag', 'name'=>'#photofriday'];
        $items[] = ['type'=>'Create', 'object'=>$note];
    }
    $pages['https://pixelfed.test/outbox?page=' . $p] = [
        'type'=>'OrderedCollectionPage', 'orderedItems'=>$items,
        'next'=> $p < 2 ? 'https://pixelfed.test/outbox?page=' . ($p + 1) : '',
    ];
}
$GLOBALS['stub_outbox'] = $pages + [
    'https://pixelfed.test/outbox' => ['type'=>'OrderedCollection',
        'first'=>'https://pixelfed.test/outbox?page=0'],
];

$tagged = static function (array $notes): bool {
    foreach ($notes as $note) if (pc_note_has_tag($note, 'photofriday')) return true;
    return false;
};

// --- the old depth could not see it; this is what the fault looked like ------
$shallow = pc_outbox_notes('https://pixelfed.test/outbox', [], 12);
if (count($shallow) !== 12) $fails[] = 'expected the reader to stop at 12, got ' . count($shallow);
if ($tagged($shallow)) $fails[] = 'the fixture is wrong: the entry must be out of reach at depth 12';

// --- the depth recovery now uses does see it --------------------------------
$deep = pc_outbox_notes('https://pixelfed.test/outbox', [], PC_RECOVERY_POSTS_PER_ACTOR);
if (!$tagged($deep)) {
    $fails[] = 'recovery depth ' . PC_RECOVERY_POSTS_PER_ACTOR
             . ' did not reach the tagged entry 20 posts down (' . count($deep) . ' read)';
}

// --- and the call sites actually pass it ------------------------------------
$src = file_get_contents(dirname(__DIR__) . '/core/photochallenge.php');
if (strpos($src, 'pc_rescan_participants($pdo,$settings,PC_RECOVERY_POSTS_PER_ACTOR,true,') === false) {
    $fails[] = 'pc_recover_tagged_entries does not pass PC_RECOVERY_POSTS_PER_ACTOR';
}
if (preg_match('/pc_rescan_participants\(\$pdo,\s*\$settings,\s*\d+\s*,/', $src)) {
    $fails[] = 'a recovery scan still passes a hardcoded per-actor depth';
}

foreach ($fails as $f) echo "FAIL: $f\n";
echo (count($fails) ? 'FAIL' : 'PASS')
   . ": recovery reads deep enough into a participant outbox to find the entry\n";
exit(count($fails) ? 1 : 0);
// ===== SNAPSMACK EOF =====
