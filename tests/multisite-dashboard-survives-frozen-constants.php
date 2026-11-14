<?php
// EOF marker required (SNAPSMACK_EOF_HEADER): file must end with `// ===== SNAPSMACK EOF =====`.
declare(strict_types=1);

// SS-1337. THE FLEET BOARD STOPPED LISTING SPOKES, and my own SS-1145 test
// could not have caught it.
//
// SS-1145 added snap_reading_age() to core/constants.php and called it from the
// spoke row in smack-multisite.php. tests/multisite-version-reading-age.php
// opens with `require_once $root . '/core/constants.php'` — so it guaranteed the
// one condition under which the page works, and was structurally incapable of
// noticing the one under which it does not.
//
// On an install upgraded from 0.7.159 or earlier, that install's own
// protected_paths.json still lists core/constants.php, and protected_paths.json
// is ITSELF protected, so the 2026-08-15 decision to stop protecting
// constants.php never reached it. constants.php stays frozen,
// snap_reading_age() does not exist, and the row loop dies on the FIRST spoke
// with an undefined-function Error: the hub self-row renders because it never
// calls it, the other 25 spokes never render, and the CONNECTED SPOKES count is
// computed before the table so it still reads 27 above two rows.
//
// THIS TEST DELIBERATELY DOES NOT LOAD core/constants.php. That absence is the
// whole point. It asserts the dashboard carries what it needs, the same way
// smack-update.php and cron-version-check.php already carry a fallback
// snap_version_compare() for exactly this reason.

$root = dirname(__DIR__);
$fails = 0;
function check(string $label, bool $ok): void {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) { $fails++; }
}

// ---- 1. the page carries its own fallback -----------------------------------
$page = (string) file_get_contents($root . '/smack-multisite.php');

check('the dashboard guards snap_reading_age with function_exists',
      str_contains($page, "if (!function_exists('snap_reading_age'))"));
check('and the guard sits before the row loop that calls it',
      strpos($page, "if (!function_exists('snap_reading_age'))")
      < strpos($page, '$ver_age  = snap_reading_age('));
check('the reason is recorded in the file, not just in a commit message',
      str_contains($page, 'protected_paths.json') && str_contains($page, 'SS-1337'));

// ---- 2. it actually works with constants.php ABSENT -------------------------
// The guarded block is extracted and evaluated on its own, in a process where
// core/constants.php has never been loaded, which is the frozen-install state.
check('snap_reading_age is NOT already defined in this process',
      !function_exists('snap_reading_age'));

$start = strpos($page, "if (!function_exists('snap_reading_age'))");
if ($start === false) {
    echo "FAIL the guarded fallback could not be found at all\n";
    exit(1);
}
// Take to the end of the guard block: the closing brace at the same indent.
$end = strpos($page, "\n}\n", $start);
$fallback = substr($page, $start, (int)$end - $start + 3);
eval($fallback);

check('the fallback defines the function where constants.php is frozen',
      function_exists('snap_reading_age'));

// ---- 3. the fallback behaves identically to the real one --------------------
// A fallback that disagrees with constants.php is a second bug wearing the
// first one's clothes, so every boundary from the SS-1145 test is re-checked
// here against THIS definition.
$now = 1800000000;
$cases = [
    [0,          'never',      false, true],
    [$now + 5,   'clock skew', false, true],
    [$now - 0,          'just now', true, false],
    [$now - 59,         'just now', true, false],
    [$now - 60,         '1m ago',   true, false],
    [$now - 3599,       '59m ago',  true, false],
    [$now - 3600,       '1h ago',   true, false],
    [$now - 86399,      '23h ago',  true, false],
    [$now - 86400,      '1d ago',   true, true],
    [$now - 49 * 86400, '49d ago',  true, true],
];
foreach ($cases as [$ts, $phrase, $known, $stale]) {
    $r = snap_reading_age($ts, $now);
    check("fallback: ts {$ts} reads \"{$phrase}\"", $r['phrase'] === $phrase);
    check("fallback: ts {$ts} known=" . var_export($known, true), $r['known'] === $known);
    check("fallback: ts {$ts} stale=" . var_export($stale, true), $r['stale'] === $stale);
}
check('fallback: the threshold is still a parameter',
      snap_reading_age($now - 3540, $now, 3600)['stale'] === false
      && snap_reading_age($now - 3660, $now, 3600)['stale'] === true);

// ---- 4. the two definitions must not drift ----------------------------------
// If somebody fixes a boundary in one copy and not the other, the fleet board
// and the rest of the CMS disagree about what "stale" means.
$constants = (string) file_get_contents($root . '/core/constants.php');
foreach (["'phrase' => 'never'", "'phrase' => 'clock skew'",
          "intdiv(\$age, 60)", "intdiv(\$age, 3600)", "intdiv(\$age, 86400)",
          "\$age >= \$stale_after"] as $marker) {
    check("both copies share the rule: {$marker}",
          str_contains($constants, $marker) && str_contains($page, $marker));
}

// ---- 5. the row loop still renders every spoke -----------------------------
// The symptom was not a wrong value, it was MISSING ROWS. Render the version
// cell for a run of spokes and prove each one produced output -- including a
// spoke with no data at all, which is where the live board stopped.
$open = strpos($page, 'id="spoke-ver-<?php echo $n[\'id\']; ?>"');
$cellStart = strpos($page, '<?php', (int)$open + 40);
$cellEnd   = strpos($page, '?>', (int)$cellStart);
$cell      = substr($page, (int)$cellStart + 5, (int)$cellEnd - (int)$cellStart - 5);
if (!defined('SNAPSMACK_VERSION_SHORT')) { define('SNAPSMACK_VERSION_SHORT', '0.7.994D'); }
if (!function_exists('snap_version_compare')) {
    function snap_version_compare(string $a, string $b, string $op = '>'): bool {
        return version_compare($a, $b, $op);
    }
}
$spokes = [
    ['id' => 1, 'software_version' => '0.7.993D', 'last_seen_ts' => $now - 120],
    ['id' => 2, 'software_version' => null,       'last_seen_ts' => null],
    ['id' => 3],
    ['id' => 4, 'software_version' => '',         'last_seen_ts' => ''],
    ['id' => 5, 'software_version' => '0.7.900D', 'last_seen_ts' => $now - 49 * 86400],
];
$rendered = 0;
foreach ($spokes as $n) {
    ob_start();
    try {
        eval($cell);
        $html = (string) ob_get_clean();
    } catch (Throwable $e) {
        if (ob_get_level() > 0) { ob_end_clean(); }
        echo 'FAIL spoke ' . $n['id'] . ' threw ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        $fails++;
        continue;
    }
    if ($html !== '') { $rendered++; }
}
check('EVERY spoke row rendered, including the one with no data at all',
      $rendered === count($spokes));

if ($fails > 0) {
    echo "\n{$fails} FAILURE(S)\n";
    exit(1);
}
echo "multisite dashboard survives a frozen constants.php: ok\n";

// ===== SNAPSMACK EOF =====
