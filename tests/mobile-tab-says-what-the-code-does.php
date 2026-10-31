<?php
/**
 * The Mobile tab may only promise automatic serving if something actually
 * serves it.
 *
 * SS-1090. The tab told the owner that mobile-only skins "ARE SERVED
 * AUTOMATICALLY ON PHONES AND ARE HIDDEN FROM THE GALLERY". The second half
 * was true. The first was not: nothing in the serving path reads
 * features.mobile_only. The phone skin is a constant in a file the release
 * packager overwrites, with one hard-coded exception for text-mode sites, so a
 * third-party skin declaring itself a phone skin is accepted, enumerated in
 * that tab with an avatar control, and NEVER SERVED.
 *
 * It went unnoticed because exactly two skins declare the key and they are the
 * same two the constant and the hard-coded branch name. The claim and the
 * reality agreed by coincidence.
 *
 * THIS IS A COUPLING CHECK, NOT A COPY CHECK. It does not pin wording. It
 * asserts that the promise and the mechanism move together: either the
 * selection path reads the manifest key, or the tab does not claim automatic
 * serving. Wire the selection and this test keeps passing; put the old
 * sentence back without wiring it and this test fails.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = realpath(__DIR__ . '/..');
$failures = [];
function mob_test(bool $ok, string $message): void {
    global $failures;
    if (!$ok) $failures[] = $message;
}

/** PHP source with comments removed, so a check cannot match prose about itself. */
function mob_code(string $path): string {
    $out = '';
    foreach (token_get_all((string)file_get_contents($path)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

// Does ANYTHING choose a skin for a phone by reading the manifest key? These
// are the files that substitute the phone skin; the admin and the packager
// reading the key does not count, because neither serves a page.
$servingPaths = ['index.php', 'archive.php', 'albums.php', 'blogroll.php',
                 'collection.php', 'collections.php', 'page.php',
                 'core/skin-view-model.php', 'core/public-controller.php'];
$selectionReadsTheKey = false;
foreach ($servingPaths as $rel) {
    $path = "$root/$rel";
    if (!is_file($path)) continue;
    if (str_contains(mob_code($path), 'mobile_only')) { $selectionReadsTheKey = true; break; }
}

// What does the tab claim? Two corrections to how this is read, both found by
// mutating it:
//   - COMMENTS ARE STRIPPED. Reading the raw file failed on the repaired code,
//     because the comment explaining the repair quotes the old sentence. A
//     check that matches prose about itself is not a check.
//   - IT IS SCOPED TO THIS TAB. "hidden from the gallery" appears four times in
//     this file, so a whole-file search still matched after the phrase was
//     deleted from the Mobile tab. Only the Mobile tab's own hint counts.
$adminAll = mob_code("$root/smack-skin.php");
$from = stripos($adminAll, 'MOBILE SKINS');
mob_test($from !== false, 'the Mobile tab heading has gone, so this check cannot find its copy');
$admin = $from === false ? '' : substr($adminAll, $from, 1200);
$claimsAutomatic = (bool)preg_match('/SERVED\s+AUTOMATICALLY/i', $admin);

mob_test($selectionReadsTheKey || !$claimsAutomatic,
    'the Mobile tab says mobile-only skins are SERVED AUTOMATICALLY, and no '
  . 'serving path reads features.mobile_only. Either wire the selection to the '
  . 'manifest key or stop promising it.');

// The half that was always true must still be said, because it is the thing
// an owner would otherwise go looking for in the gallery and not find.
mob_test((bool)preg_match('/HIDDEN\s+FROM\s+THE\s+GALLERY/i', $admin),
    'the Mobile tab no longer tells the owner these skins are hidden from the gallery');

// And the tab must still be built from the manifest key rather than a list of
// names, so a newly installed phone skin appears at all.
mob_test(str_contains(mob_code("$root/smack-skin.php"), 'mobile_only'),
    'the Mobile tab no longer enumerates skins by features.mobile_only');

if ($failures) {
    fwrite(STDERR, "FAIL mobile tab honesty (" . count($failures) . ")\n  - "
        . implode("\n  - ", $failures) . "\n");
    exit(1);
}
echo "PASS the Mobile tab claims only what the serving path does\n";
// ===== SNAPSMACK EOF =====
