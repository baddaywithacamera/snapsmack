<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A closed gate must have a door, and the door must only open on a signed package.
 *
 * Sean, on a live site: "can't change the font on true grit." Nothing was
 * wrong with the font picker. skin_registry_verify_installed_provenance()
 * refuses every setting, upload, mode and active-skin change for a skin with
 * no snap_file_manifest rows, and a site whose rows were never written -- or
 * whose rows a prior update deleted -- has a skin that is visibly working on
 * the public page and silently cannot be configured. What he got after
 * pressing Save was "that skin cannot be activated", on the skin he was
 * looking at.
 *
 * The gate is right. A directory on disk is not an installed skin. The fault
 * was that there was no way out of the refusal: every path that writes the
 * record runs inside an install, and the owner had no install to run.
 *
 * WHY THIS TEST IS NOT ABOUT THE READ PATH. Three control audits already
 * exist -- archive-settings-reach-the-page, specialized-control-authority,
 * remaining-public-control-authority -- and every one of them BEGINS with a
 * settings array that already exists and asks whether the page honours it.
 * archive-settings says so in its own comment: "A setting the owner picks
 * must change the page. Flip it and look." None of them goes through the save
 * handler, which is where the refusal lives. The read path has been audited
 * three times; the write path had never been tested once. That is why this
 * reached him and not us.
 *
 * So this checks the two things that make recovery safe rather than merely
 * present: that it reinstalls the signed package from the registry, and that
 * it never writes a record from whatever happens to be on disk. Baselining
 * the disk would make a tampered file the reference copy, which is the single
 * thing the record exists to prevent. Codex, agreeing the design: "do not
 * baseline arbitrary disk."
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

function pr_test(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

$registry = (string) file_get_contents($root . '/core/skin-registry.php');
$page     = (string) file_get_contents($root . '/smack-skin.php');

echo "Recovery function exists and is reachable\n";
pr_test(str_contains($registry, 'function skin_registry_recover_provenance('),
    'core/skin-registry.php declares skin_registry_recover_provenance()');
pr_test(str_contains($page, 'recover_provenance'),
    'smack-skin.php handles a recover_provenance action');

// Isolate the function body so every claim below is about the recovery path
// and not about something that happens to appear elsewhere in the file.
$start = strpos($registry, 'function skin_registry_recover_provenance(');
$body  = '';
if ($start !== false) {
    $next = strpos($registry, "\nfunction ", $start + 10);
    $body = $next === false ? substr($registry, $start) : substr($registry, $start, $next - $start);
}
pr_test($body !== '', 'the recovery function body was located, so the checks below mean something');

echo "\nIt recovers from the signed package, and from nothing else\n";
pr_test(str_contains($body, 'skin_registry_install('),
    'recovery goes through skin_registry_install(), which verifies the signature and rolls back');
pr_test(preg_match('/INSERT\s+INTO\s+snap_file_manifest/i', $body) !== 1,
    'recovery never writes snap_file_manifest rows itself');
pr_test(!str_contains($body, 'hash_file('),
    'recovery never hashes a file on disk, so a tampered file cannot become the baseline');
pr_test(preg_match('/\$_POST|\$_GET|\$_REQUEST/', $body) !== 1,
    'recovery reads no request input: the package is the one the registry names for the slug');

echo "\nIt reads the registry fresh, and refuses when there is nothing to recover from\n";
pr_test(preg_match('/skin_registry_fetch\([^)]*,\s*true\s*\)/', $body) === 1,
    'the registry is fetched fresh, so recovery cannot reinstall a stale version from cache');
pr_test(str_contains($body, "registry['error']"),
    'an unreachable registry is reported rather than treated as "skin not listed"');
pr_test(str_contains($body, 'does not list'),
    'a slug absent from the registry is refused: no signed package, no invented record');
pr_test(substr_count($body, 'return false;') >= 5,
    'recovery has at least five refusal paths, each leaving the site unchanged');

echo "\nIt proves the gate opened instead of trusting the install\n";
pr_test(substr_count($body, 'skin_registry_verify_installed_provenance(') >= 2,
    'provenance is verified both before (no needless reinstall) and after (the only acceptable evidence)');
$install_at = strpos($body, 'skin_registry_install(');
$verify_after = $install_at === false
    ? false
    : strpos($body, 'skin_registry_verify_installed_provenance(', $install_at);
pr_test($verify_after !== false,
    'the verification after the reinstall comes after it, so a successful install that still fails the gate returns false');

echo "\nThe owner is told before pressing Save, not after\n";
pr_test(str_contains($page, 'NO SETTING ON THIS SKIN WILL SAVE'),
    'the customize tab states plainly that nothing will save');
pr_test(preg_match('/\$_skin_provenance_ok\s*=\s*skin_registry_verify_installed_provenance\(/', $page) === 1,
    'the notice calls the same function the save path gates on, so it cannot disagree with the refusal');
pr_test(str_contains($page, 'RECOVER THE INTEGRITY RECORD'),
    'there is a control to press, in the place the refusal happens');
pr_test(preg_match('/gallery_action"\s+value="recover_provenance"/', $page) === 1,
    'the control posts the recovery action');

echo "\nThe control is as guarded as every other action on the page\n";
$action_at = strpos($page, "'recover_provenance'");
$handler   = $action_at === false ? '' : substr($page, $action_at, 1800);
$before    = $action_at === false ? '' : substr($page, 0, $action_at);
pr_test($action_at !== false && str_contains($before, 'hash_equals($_SESSION[\'csrf_token\']'),
    'the recovery action sits inside the existing CSRF check, not beside it');
pr_test($handler !== '' && preg_match('/\$_POST\[.download_url.\]/', $handler) !== 1,
    'the handler does not accept a download URL from the request');
pr_test(str_contains($handler, 'SKIN_REGISTRY_DEFAULT_URL'),
    'the handler resolves the registry URL server side');

echo "\nIt does not touch the owner's saved choices\n";
pr_test(stripos($body, 'snap_settings') === false,
    'recovery neither reads nor writes snap_settings, so saved choices survive the reinstall');
$notice_at = strpos($page, 'NO SETTING ON THIS SKIN WILL SAVE');
$notice    = $notice_at === false ? '' : substr($page, max(0, $notice_at - 1400), 3000);
pr_test($notice !== '' && preg_match('/style\s*=\s*"[^"]/', $notice) !== 1,
    'the notice carries no static style attribute');

echo "\n";
echo "  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "Skin provenance recovery: FAIL ({$fail}).\n";
    exit(1);
}
echo "Skin provenance recovery: PASS ({$pass} assertions).\n";

// ===== SNAPSMACK EOF =====
