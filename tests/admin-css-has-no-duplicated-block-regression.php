<?php
declare(strict_types=1);

/**
 * SS-1340. The admin geometry stylesheet must not ship the same rules twice.
 *
 * It carried 2,407 byte-identical lines back to back -- 52KB, 30% of the file,
 * downloaded on every admin page load. The ticket described it as "a
 * duplicated 31-line block"; the 31 lines somebody spotted were the
 * .version-* rules inside a duplicate two orders of magnitude bigger.
 *
 * Deleting one copy is provably safe because the copies were ADJACENT and
 * identical: the cascade sees the same declarations in the same order either
 * way, so no computed value can change. This test is here so it cannot come
 * back, and it looks for the fault rather than for that one block -- it scans
 * for ANY long run of lines that appears twice.
 */

$path = dirname(__DIR__) . '/assets/css/admin-theme-geometry-master.css';
$lines = explode("\n", str_replace("\r\n", "\n", (string)file_get_contents($path)));
$count = count($lines);

// A run has to be long enough that it cannot be an innocent coincidence --
// two small rules sharing "  display: flex;" is normal CSS, not duplication.
$RUN = 40;
$seen = [];
$worst = null;
for ($i = 0; $i + $RUN <= $count; $i++) {
    $slice = array_slice($lines, $i, $RUN);
    // NOT trimmed. Trimming the window made two windows one line apart hash
    // the same whenever the earlier one began with a blank line, which is how
    // the first version of this test reported "lines 207 and 208" on a file
    // that had nothing wrong there at all.
    $window = implode("
", $slice);
    // Blank or near-blank runs are not duplication. Require real content.
    $substantive = 0;
    foreach ($slice as $line) { if (trim($line) !== '') $substantive++; }
    if ($substantive < 20) continue;
    $key = hash('sha256', $window);
    // Overlapping windows inside one periodic run are that run, not two copies.
    if (isset($seen[$key]) && $i - $seen[$key] >= $RUN) {
        // Grow it so the report says how bad it actually is.
        $len = $RUN;
        $a = $seen[$key];
        while ($i + $len < $count && ($lines[$a + $len] ?? null) === ($lines[$i + $len] ?? null)) {
            $len++;
        }
        if ($worst === null || $len > $worst['len']) {
            $worst = ['len' => $len, 'first' => $a + 1, 'second' => $i + 1];
        }
        $i += $len - 1;          // do not re-report every offset of one run
        continue;
    }
    $seen[$key] = $i;
}

$bytes = filesize($path);
if ($worst !== null) {
    printf("FAIL: %d identical lines appear at line %d and again at line %d.\n",
        $worst['len'], $worst['first'], $worst['second']);
    echo "      Every admin page downloads both copies. Delete one.\n";
    exit(1);
}

// And a floor on the saving, so nobody quietly pastes it back.
if ($bytes > 150000) {
    printf("FAIL: the admin geometry stylesheet is %d bytes. SS-1340 brought it\n"
         . "      down to ~122KB by removing a duplicate; this is large enough\n"
         . "      to suggest another one has been pasted back.\n", $bytes);
    exit(1);
}

printf("PASS: no duplicated block in the admin geometry stylesheet (%d bytes)\n", $bytes);
// ===== SNAPSMACK EOF =====
