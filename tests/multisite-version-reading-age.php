<?php
// EOF marker required (SNAPSMACK_EOF_HEADER): file must end with `// ===== SNAPSMACK EOF =====`.
declare(strict_types=1);

// SS-1145. The fleet board printed each spoke's software_version with nothing
// to say when the hub last heard it. A heartbeat that fails leaves the stored
// value untouched, so a reading taken seven weeks and three releases ago was
// drawn exactly like one taken a minute ago, and an afternoon went into
// chasing installs that were never on the build the board claimed.
//
// This test does two things. It measures the decision function at every
// boundary, and then it RUNS THE REAL CELL -- the PHP block is cut out of
// smack-multisite.php and evaluated against fabricated node rows, so the
// assertions are about markup the page actually produces, not about source
// text matching a pattern.

$root = dirname(__DIR__);
require_once $root . '/core/constants.php';

$fails = 0;
function check(string $label, bool $ok): void {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) { $fails++; }
}

// ---------------------------------------------------------------- the decision
$DAY = 86400;
$now = 1800000000;

$r = snap_reading_age(0, $now);
check('no heartbeat ever: not known', $r['known'] === false);
check('no heartbeat ever: says never', $r['phrase'] === 'never');
check('no heartbeat ever: STALE, because unknown is not fresh', $r['stale'] === true);

$r = snap_reading_age($now + 5, $now);
check('a timestamp in the future is not aged as fresh', $r['known'] === false);
check('and is named as what it is', $r['phrase'] === 'clock skew');
check('and is stale, not silently trusted', $r['stale'] === true);

// The phrase boundaries. A mutant that swaps < for <=, or 60 for 600, has to
// break one of these.
$cases = [
    [0,          'just now'],
    [59,         'just now'],
    [60,         '1m ago'],
    [119,        '1m ago'],
    [120,        '2m ago'],
    [3599,       '59m ago'],
    [3600,       '1h ago'],
    [7199,       '1h ago'],
    [7200,       '2h ago'],
    [86399,      '23h ago'],
    [86400,      '1d ago'],
    [172799,     '1d ago'],
    [172800,     '2d ago'],
    [49 * 86400, '49d ago'],   // the seven weeks from the report
];
foreach ($cases as [$age, $want]) {
    $r = snap_reading_age($now - $age, $now);
    check("an age of {$age}s reads \"{$want}\"", $r['phrase'] === $want);
    check("an age of {$age}s is known", $r['known'] === true);
    check("an age of {$age}s reports its seconds", $r['seconds'] === $age);
}

// The staleness boundary, which is what greys the version.
check('23h59m59s is still current', snap_reading_age($now - 86399, $now)['stale'] === false);
check('exactly 24h is stale', snap_reading_age($now - 86400, $now)['stale'] === true);
check('seven weeks is stale', snap_reading_age($now - 49 * $DAY, $now)['stale'] === true);
check('the threshold is a parameter: 1h window, 59m is fresh',
      snap_reading_age($now - 3540, $now, 3600)['stale'] === false);
check('the threshold is a parameter: 1h window, 61m is stale',
      snap_reading_age($now - 3660, $now, 3600)['stale'] === true);

// ------------------------------------------------- the cell the page renders
// Cut the version cell's PHP out of the page and run it. A rewrite of the cell
// that drops the age fails here rather than quietly passing.
$page = (string) file_get_contents($root . '/smack-multisite.php');
$open = strpos($page, 'id="spoke-ver-<?php echo $n[\'id\']; ?>"');
if ($open === false) {
    echo "FAIL the spoke version cell could not be found in smack-multisite.php\n";
    exit(1);
}
$start = strpos($page, '<?php', $open + 40);
$end   = strpos($page, '?>', (int)$start);
$cell  = substr($page, (int)$start + 5, (int)$end - (int)$start - 5);

function render_cell(string $cell, array $n): string {
    ob_start();
    eval($cell);
    return (string) ob_get_clean();
}

$behind = '0.7.900D';                 // older than SNAPSMACK_VERSION_SHORT
$fresh  = ['id' => 7, 'software_version' => $behind, 'last_seen_ts' => time() - 120];
$old    = ['id' => 8, 'software_version' => $behind, 'last_seen_ts' => time() - 49 * $DAY];
$never  = ['id' => 9, 'software_version' => '',      'last_seen_ts' => 0];

$h = render_cell($cell, $fresh);
check('a fresh row prints the version', str_contains($h, $behind));
// SS-1338 CHANGED THIS ASSERTION DELIBERATELY. SS-1145 printed the age under
// every spoke. .version-age is display:block in a narrow column, so on a fresh
// row "read just now" wrapped and every row in the fleet table grew, squeezing
// the ACTION column until its buttons stacked. A fresh reading tells a reader
// nothing the version does not already say. So the useful rule is: STALE SHOWS
// THE AGE, FRESH DOES NOT -- and the fresh phrase stays in the tooltip, which
// is where it was always available anyway.
check('a fresh row does NOT print an age line under the version',
      !str_contains($h, 'version-age'));
check('but the fresh reading is still readable, in the tooltip',
      str_contains($h, 'Reported 2m ago.'));
check('a fresh row is NOT marked stale', !str_contains($h, 'version-stale'));
check('a fresh row still shows the behind-hub arrow', str_contains($h, 'version-behind'));
check('a fresh row carries the value id the update JS can find',
      str_contains($h, 'id="spoke-ver-val-7"'));

$h = render_cell($cell, $old);
check('a seven-week-old reading still prints the version', str_contains($h, $behind));
check('a seven-week-old reading prints its age', str_contains($h, 'read 49d ago'));
check('and that age line still carries the id the update JS looks for',
      str_contains($h, 'id="spoke-ver-age-8"'));
check('a seven-week-old reading is marked stale', str_contains($h, 'version-stale'));
check('and says in words that it is not a reading taken now',
      str_contains($h, 'not a reading taken now'));

$h = render_cell($cell, $never);
check('a spoke never heard from shows no version', str_contains($h, "\u{2014}"));
check('and says never rather than an age', str_contains($h, 'never'));
check('and is not labelled "read never"', !str_contains($h, 'read never'));
// snap_reading_age() reports stale=true for an unheard-of spoke, so SS-1338's
// gate must not have hidden this one: "never" is exactly the case worth showing.
check('a spoke never heard from DOES still print its age line',
      str_contains($h, 'version-age'));

// The fault itself: the two rows must not be indistinguishable.
check('THE DEFECT: a fresh reading and a seven-week-old one render differently',
      render_cell($cell, $fresh) !== render_cell($cell, $old));

// ------------------------------------- A KNOWN LIMIT, PINNED SO IT IS VISIBLE
// The UPDATE / UPDATE ALL path writes the new version over the WHOLE version
// cell, which removes the age line with it. After a push the row shows a bare
// version again until the page is reloaded.
//
// It is left that way on purpose. The code that does it lives inside the
// 158-line inline <script> in smack-multisite.php that the agreed-rules gate
// allowlists under SS-1041, and the gate's rule is explicit: an allowlisted
// block is not maintained in place, it is moved to assets/js/ in full. That
// block drives UPDATE ALL across the live fleet and cannot be verified here,
// so it is not being rewritten for a label. This assertion records the limit;
// when the block is extracted, the age line is refreshed there and this check
// is replaced.
check('the push path still replaces the whole cell (known, SS-1041 block)',
      str_contains($page, '.textContent = String(res.to)'));
check('and the cell id it writes to is still on the td, so nothing else breaks',
      str_contains($page, 'id="spoke-ver-' . '<?php echo $n' . "['id']" . '; ?>"'));

// ------------------------------------------------------------- the stylesheet
$css = (string) file_get_contents($root . '/assets/css/admin-theme-geometry-master.css');
foreach (['.version-age', '.version-stale'] as $cls) {
    check("the admin stylesheet defines {$cls}", str_contains($css, $cls));
}
// The stylesheet carries TWO copies of this block (a known duplication in
// admin-theme-geometry-master.css, not introduced here), so checking the file
// as a whole would pass with one copy repaired and one broken. Every
// declaration has to carry the non-colour marking.
preg_match_all('/\.version-stale\s*\{[^}]*\}/', $css, $m);
check('the stylesheet declares .version-stale at least once', count($m[0]) >= 1);
$all_marked = count($m[0]) >= 1;
foreach ($m[0] as $block) {
    if (!str_contains($block, 'underline dotted')) { $all_marked = false; }
}
check('and EVERY declaration marks it by more than colour', $all_marked);

if ($fails > 0) {
    echo "\n{$fails} FAILURE(S)\n";
    exit(1);
}
echo "multisite version reading age: ok\n";

// ===== SNAPSMACK EOF =====
