<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Every site mode that has a scrollable feed records scroll time.
 *
 * SMACKTALK -- text mode -- was missing from the eligibility condition in
 * core/stats-logger.php, so a text-mode site recorded no scroll time on any
 * page at all. Four modes are valid and only three were listed:
 *
 *   core/app-mode.php:20        photoblog, carousel, smacktalk, smackthemup
 *   core/mode-guard.php:132     the same four
 *   install.php:303             the same four
 *
 * It was not a strict-conversion stranding. The mode was never added, and
 * there is no second route to the tracker: the only callers are
 * core/footer-scripts.php:59, archive.php:935 and
 * core/skin-render-helpers.php:536, and all three go through one condition.
 *
 * Member of SS-1006 and related to SS-1061, which is the same shape -- the
 * strict hit-type map has no 'blog' entry, so the text side of the product is
 * the under-served path for statistics generally.
 *
 * WHY A TEST AND NOT JUST THE FIX. The condition is a list of mode names. A
 * fifth mode added later would be silently uncovered in exactly the way
 * smacktalk was for months, and nothing would say so. This fails when the set
 * of valid modes and the set of covered modes stop agreeing.
 *
 * WHAT IT CANNOT SEE: it drives the eligibility decision directly. It does not
 * prove a tag reaches a rendered page -- tests/thomas-the-bear and
 * tests/help-and-consent do that for assets, and a live page is what settles
 * it. SS-1006 keeps that distinction.
 */

$root = dirname(__DIR__);
require_once $root . '/core/stats-logger.php';

$pass = 0;
$fail = 0;

function st_test(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

/**
 * Did the tag get emitted for this mode and page type?
 *
 * snapsmack_scrolltime_tag() reads $GLOBALS['snapsmack_hit'], prints, and has
 * a static one-shot guard -- so it is exercised in a child process, which also
 * keeps the guard from making the second case pass for the wrong reason.
 */
function st_emits(string $root, string $mode, string $type): bool {
    $script = <<<'PHP'
<?php
$root = $argv[1];
require_once $root . '/core/stats-logger.php';
$GLOBALS['snapsmack_hit'] = ['id' => 7, 'page_type' => $argv[3]];
ob_start();
snapsmack_scrolltime_tag(['site_mode' => $argv[2]]);
$out = (string)ob_get_clean();
echo str_contains($out, 'ss-scrolltime') ? 'YES' : 'NO';
PHP;
    $tmp = tempnam(sys_get_temp_dir(), 'st') . '.php';
    file_put_contents($tmp, $script);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' '
         . escapeshellarg($root) . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($type);
    $out = (string) shell_exec($cmd);
    @unlink($tmp);
    return trim($out) === 'YES';
}

echo "The harness distinguishes emitted from not emitted\n";
// A check that always says yes would pass everything below it.
st_test(st_emits($root, 'photoblog', 'landing'),
    'photoblog landing emits, which was already true');
st_test(!st_emits($root, 'photoblog', 'page'),
    'a static page does NOT emit, so the harness is not just printing YES');

echo "\nSMACKTALK, the mode that was missing\n";
st_test(st_emits($root, 'smacktalk', 'landing'),
    'a text-mode landing feed records scroll time');
st_test(st_emits($root, 'smacktalk', 'archive'),
    'and so does its archive');

echo "\nThe modes that already worked still work\n";
st_test(st_emits($root, 'photoblog', 'archive'), 'photoblog archive');
st_test(st_emits($root, 'carousel', 'landing'), 'carousel landing');
st_test(st_emits($root, 'smackthemup', 'landing'), 'smackthemup landing');

echo "\nAnd the deliberate exclusions are still excluded\n";
// carousel is a single-item view: its archive is not a scrollable feed, and
// that was a decision rather than an oversight.
st_test(!st_emits($root, 'carousel', 'archive'),
    'carousel archive still does not emit');
st_test(!st_emits($root, 'smacktalk', 'image'),
    'a single post page does not emit');
st_test(!st_emits($root, 'photoblog', 'search'),
    'a search results page does not emit');

echo "\nEVERY valid site mode is accounted for, named or excluded\n";
/**
 * The real ratchet. If a fifth mode is added to the product and not to the
 * condition, it goes uncovered silently -- which is precisely what happened to
 * smacktalk. So the valid modes are read from the product and each must either
 * record scroll time on its landing, or be listed here with a reason.
 */
$EXCLUDED = [
    // none today: all four modes have a scrollable landing and all four emit.
];
$appMode = (string) file_get_contents($root . '/core/app-mode.php');
preg_match("/in_array\(\\\$mode,\s*\[([^\]]*)\]/", $appMode, $m);
preg_match_all("/'([a-z]+)'/", $m[1] ?? '', $hits);
$valid = $hits[1] ?? [];

st_test(count($valid) >= 4,
    'the valid site modes were read from core/app-mode.php (found: '
    . implode(', ', $valid) . ')');

$uncovered = [];
foreach ($valid as $mode) {
    if (isset($EXCLUDED[$mode])) continue;
    if (!st_emits($root, $mode, 'landing')) $uncovered[] = $mode;
}
st_test($uncovered === [],
    'every valid mode records scroll time on its landing'
    . ($uncovered === [] ? '' : ': ' . implode(', ', $uncovered)
        . ' does not -- add it to the condition in core/stats-logger.php, or '
        . 'list it in $EXCLUDED here with the reason'));

echo "\n  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "Scroll time across site modes: FAIL ({$fail}).\n";
    exit(1);
}
echo "Scroll time across site modes: PASS ({$pass} assertions, "
    . count($valid) . " modes).\n";

// ===== SNAPSMACK EOF =====
