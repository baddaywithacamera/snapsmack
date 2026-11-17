<?php
declare(strict_types=1);

/**
 * A SMACKTALK PAGE THAT RENDERS MUST ALSO BE COUNTED.
 *
 * SS-1129 made snapsmack_scrolltime_tag() eligible for a SMACKTALK post:
 * ($mode === 'smacktalk' && $type === 'page'). That gate is right, and on its
 * own it did nothing, because the gate is never reached unless a hit was
 * logged first -- the tag reads $GLOBALS['snapsmack_hit'] and returns at once
 * when there is none.
 *
 * AND THE SMACKTALK HIT GOES THROUGH A LOOKUP TABLE THAT DID NOT KNOW EVERY
 * KIND THE CONTROLLER PRODUCES. index.php keys $_hit_types by response kind
 * and calls snapsmack_log_hit() only when isset() succeeds. The controller
 * names one written post 'single' and the landing diary 'feed'; neither was a
 * key. Those pages rendered and nothing was recorded: no view, no dwell.
 *
 * SS-1129's own commit message said "a long written post is page_type 'page'".
 * On this path it was not. That is why "integrate the post-dwell fix and
 * article calls can emit" did not hold: the prerequisite was necessary and not
 * sufficient. 'single' => 'page' is now mapped, which makes SS-1129's stated
 * intent true rather than adding a new one.
 *
 * WHAT IS MEASURED. The kinds come from the controller's own source and the
 * table from index.php's own source, because the table is an inline literal
 * inside the request path and cannot be called. That is a source read and is
 * labelled as one. THE GATE IS CALLED FOR REAL with each resulting page_type,
 * in its own process, because a test asserting on a decision must call it.
 */

$root = dirname(__DIR__);
if (!defined('BASE_URL')) define('BASE_URL', '/');
if (!defined('SNAPSMACK_VERSION_SHORT')) define('SNAPSMACK_VERSION_SHORT', '0.7.977D');
require_once $root . '/core/stats-logger.php';

// CHILD MODE SITS HERE, BEFORE ANY OUTPUT, AND THAT MATTERS. I first put it
// below the parent's checks. The child runs the file from the top, so its
// stdout carried every parent line before the answer, each comparison against
// 'TAG' failed, and the gate looked broken for page_type 'landing' -- which it
// has always allowed. The fault was in my harness and I caught it only because
// a result I knew to be wrong stared back at me.
//
// Each page_type is asked in its OWN process: snapsmack_scrolltime_tag() has a
// one-shot guard per request, so a second question in one process is a lie.
if (($argv[1] ?? '') === 'child') {
    $GLOBALS['settings'] = ['site_mode' => 'smacktalk', 'site_name' => 'Fixture'];
    $GLOBALS['snapsmack_hit'] = ['id' => 4242, 'page_type' => (string)$argv[2]];
    ob_start();
    snapsmack_scrolltime_tag($GLOBALS['settings']);
    echo str_contains((string)ob_get_clean(), 'id="ss-scrolltime"') ? 'TAG' : 'NOTAG';
    exit;
}

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}
function emits(string $page_type): bool {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' child '
         . escapeshellarg($page_type);
    return trim((string)shell_exec($cmd)) === 'TAG';
}

// --- the kinds the SMACKTALK controller actually returns --------------------
$ctl = (string)file_get_contents($root . '/core/smacktalk-public-controller.php');
preg_match_all("/'kind'\\s*=>\\s*'([a-z_]+)'/", $ctl, $m);
$kinds = array_values(array_unique($m[1]));
sort($kinds);
echo 'KINDS THE SMACKTALK CONTROLLER RETURNS: ' . implode(', ', $kinds) . "\n";
cx($kinds !== [], 'the controller source yielded its kinds at all');
cx(in_array('single', $kinds, true), "'single' is one of them, so a written post is a real kind");
cx(in_array('feed', $kinds, true), "'feed' is one of them, so the landing diary is a real kind");

// --- the table index.php uses to decide whether to log ---------------------
// NO FIXED WINDOW. I first read a 1200-character slice after the marker
// comment; then I added an explaining comment above the literal, the literal
// fell outside the slice, the map parsed as EMPTY and every check failed for a
// reason that had nothing to do with the code. Search forward for the literal
// itself instead, so a comment can never move it out of view.
$idx = (string)file_get_contents($root . '/index.php');
$at = strpos($idx, 'The SMACKTALK controller exits here too');
cx($at !== false, 'the SMACKTALK hit block was located in index.php');
$open = $at === false ? false : strpos($idx, '$_hit_types = [', (int)$at);
$close = $open === false ? false : strpos($idx, '];', (int)$open);
$lit = ($open === false || $close === false) ? '' : substr($idx, (int)$open, (int)$close - (int)$open);
preg_match_all("/'([a-z_]+)'\\s*=>\\s*'([a-z-]+)'/", $lit, $mm, PREG_SET_ORDER);
$map = [];
foreach ($mm as $pair) $map[$pair[1]] = $pair[2];
echo 'KINDS THAT TABLE KNOWS: ' . implode(', ', array_keys($map)) . "\n";
cx(count($map) >= 8, 'the hit table was parsed and is not a near-empty misread (' . count($map) . ' keys)');

// --- the disagreement, named ----------------------------------------------
echo "\nEVERY RENDERABLE KIND IS COUNTED\n";
// 'not_found' is excluded because a 404 is not a page view, and the hit block
// already refuses it on status.
//
// 'feed' WAS OPEN AND IS NOW RULED ON. I raised it as SS-1204 rather than
// mapping it myself, because 'feed' is the SMACKTALK landing page and every
// reader of dwell_ms filters page_type IN ('landing','archive') -- so mapping
// it adds rows to a bucket the SCROLL TIME tile already reports, and changes
// what that number means from that day on. That was not mine to decide inside
// a fix for something else.
//
// The coordinator ruled YES on 5 Nov 2026: Sean's rule already says a SMACKTALK
// landing page gets a beacon, and a front page logging no hits at all is a bug.
// So 'feed' is asserted below like any other renderable kind, and the reported
// consequence is asserted too rather than left as a footnote.
//
// 'taxonomy' IS HELD, by the same ruling: nobody has asked for a beacon there.
// Held is not the same as fine, so it stays reported and visible.
$OPEN = ['taxonomy'];
$shouldCount = array_values(array_diff($kinds, ['not_found'], $OPEN));
foreach ($shouldCount as $k) {
    cx(isset($map[$k]),
       "kind '{$k}' has a page_type in the hit table"
       . (isset($map[$k]) ? " ({$map[$k]})" : ' -- NOTHING IS LOGGED, so no view and no dwell'));
}
$uncounted = [];
foreach ($OPEN as $k) {
    if (!isset($map[$k])) {
        $uncounted[] = $k;
        echo "  --    kind '{$k}' is NOT counted: it renders and logs nothing.\n";
        echo "        OPEN DECISION for Sean, not a pass: mapping it moves a reported figure.\n";
    } else {
        echo "  --    kind '{$k}' is now counted as '{$map[$k]}'\n";
    }
}

// --- the gate itself, CALLED, for each page_type that does get logged -----
echo "\nTHE GATE, CALLED FOR REAL IN SMACKTALK MODE\n";
foreach (array_values(array_unique(array_values($map))) as $pt) {
    echo "  --    page_type '{$pt}': " . (emits($pt) ? 'TAG' : 'no tag') . "\n";
}
cx(emits('page'), "page_type 'page' emits, which is what SS-1129 added");
cx(!emits('image'), "page_type 'image' does NOT emit, so a photo page is still untouched");

echo "\nTHE SMACKTALK FRONT PAGE IS COUNTED, AND IT IS COUNTED IN PUBLIC\n";
// The point of the ruling was not just that a row gets written. It was that
// the front page starts appearing in the figure Sean reads. So assert BOTH
// halves: the kind is mapped, and the page_type it is mapped to is one the
// readers actually report. A mapping to a collection-only bucket would have
// satisfied "is it counted" and quietly delivered nothing.
cx(isset($map['feed']), "the SMACKTALK front page ('feed') is counted at all");
cx(($map['feed'] ?? '') === 'landing',
   "and it is counted as 'landing', which is a REPORTED bucket, not collection only (got '"
   . ($map['feed'] ?? 'nothing') . "')");
cx(isset($map['feed']) && emits($map['feed']),
   'and that page_type emits the tracker in smacktalk mode, so dwell is collected too');
// The readers are named here so the claim "this changes the reported figure"
// is checkable rather than asserted from memory.
$readers = [
    'smack-multisite-stats.php' => $root . '/smack-multisite-stats.php',
    'core/multisite-api.php'    => $root . '/core/multisite-api.php',
];
foreach ($readers as $label => $path) {
    if (!is_file($path)) { echo "  --    {$label} not present on this tree: NOT CHECKED\n"; continue; }
    $src = (string)file_get_contents($path);
    cx(str_contains($src, "'landing'") || str_contains($src, '"landing"'),
       "{$label} filters on 'landing', so a front-page row now reaches the tile");
}

echo "\nWHAT THIS MEANS FOR THE ARTICLE REQUEST\n";
cx(isset($map['single']),
   "a written post ('single') is counted, so SS-1129's gate can be reached at all");
cx(isset($map['single']) && emits($map['single']),
   'and the page_type it is counted as actually emits the tracker in smacktalk mode');
if ($uncounted !== []) {
    echo '  STILL UNCOUNTED, held by the 5 Nov ruling rather than undecided: '
       . implode(', ', $uncounted) . "\n";
}

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
