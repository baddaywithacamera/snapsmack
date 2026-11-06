<?php
declare(strict_types=1);

/**
 * A FLEET SKIN PUSH MUST INCLUDE THE SITE RUNNING IT.
 *
 * SS-1072. Both selects in smack-multisite.php's skin push read
 * role = 'spoke', and the hub is not a row in snap_multisite_nodes at all, so
 * a fleet-wide skin push had never touched the hub. Found Textures is the
 * observed case: it served TRUE GRIT 1.3.24 while the published package was
 * 1.3.25. Nothing was broken -- it was simply never a target. Sean asked twice
 * for fleet updates to include the hub.
 *
 * WHAT IS TESTED HOW, because this matters more than the count. The hub branch
 * lives inside a POST handler behind a role check in an admin page, so it
 * cannot be called from here: those assertions are SOURCE CHECKS and say so.
 * The STALENESS DECISION, which is the part that could wrongly skip or wrongly
 * reinstall, is the same snap_version_compare the spoke path uses -- so that is
 * CALLED for real, with the actual Found Textures versions.
 */

$root = dirname(__DIR__);
if (!defined('BASE_URL')) define('BASE_URL', '/');
if (!defined('SNAPSMACK_VERSION_SHORT')) define('SNAPSMACK_VERSION_SHORT', '0.7.977D');
require_once $root . '/core/skin-manifest.php';
require_once $root . '/core/constants.php';   // snap_version_compare() lives here

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

$src = (string)file_get_contents($root . '/smack-multisite.php');
$at  = strpos($src, "isset(\$_POST['push_skin']) || isset(\$_POST['push_skin_all'])");
cx($at !== false, 'the skin push handler was located');
// Bounded to the handler, not the whole file: a 2,000-line admin page will
// contain almost any string somewhere, and a check that matches elsewhere is
// not a check. Learned this the hard way three times in two days.
$handler = $at === false ? '' : substr($src, (int)$at, 12000);
cx(strlen($handler) > 2000, 'and a bounded slice of it was taken (' . strlen($handler) . ' chars)');

echo "THE HUB IS A TARGET OF A FLEET PUSH\n";
cx(str_contains($handler, 'SS-1072'), 'the hub branch is present and cites the ticket');
// BOUNDED ON THE NEXT REAL THING, NOT ON A CHARACTER COUNT. My first version
// took 3000 characters from the SS-1072 marker, and the explaining comment
// alone is most of that -- so four assertions failed on code they never
// reached. The branch ends where the spoke loop begins, which is a boundary
// the file guarantees; the length check below fails loudly if it ever stops
// being one. Fourth time in two days a fixed-width slice of mine has measured
// the wrong region.
$hubAt = strpos($handler, 'SS-1072');
$hubEnd = $hubAt === false ? false : strpos($handler, 'foreach ($eligible as $tn)', (int)$hubAt);
$hubBranch = ($hubAt === false || $hubEnd === false)
    ? '' : substr($handler, (int)$hubAt, (int)$hubEnd - (int)$hubAt);
cx($hubEnd !== false, 'the hub branch ends where the spoke loop begins, so it can be bounded exactly');
cx(strlen($hubBranch) > 1500, 'and the bounded branch is the whole of it (' . strlen($hubBranch) . ' chars)');
cx(str_contains($hubBranch, "isset(\$_POST['push_skin_all'])"),
   'it runs on a FLEET push');
// The negative half: a single-spoke push names one site the operator chose,
// and quietly adding the hub to it would install something nobody asked for.
$singleOnly = substr($handler, 0, (int)$hubAt);
cx(!str_contains($hubBranch, "\$_POST['spoke_id']"),
   'and the hub branch never consults spoke_id, so a single-site push cannot reach it');

echo "\nIT USES THE LOCAL SIGNED INSTALLER, NOT HTTP TO ITSELF\n";
cx(str_contains($hubBranch, 'skin_registry_install('),
   'the hub installs in process with skin_registry_install()');
// MUTANT M4 SURVIVED THE FIRST VERSION TOO. It checked the constant was
// MENTIONED -- and replacing the argument with an empty string leaves the
// $hub_key assignment mentioning it, so an unverified install would have
// passed. Assert the key reaches the CALL.
cx((bool)preg_match('/skin_registry_install\(\s*\$skin_slug,\s*\$download_url,\s*\$signature,\s*\$hub_key,/s', $hubBranch),
   'and the release public key is passed INTO skin_registry_install(), so the signature is verified as for a spoke');
cx(str_contains($hubBranch, 'SNAPSMACK_RELEASE_PUBKEY'),
   'and it is the real release key, not a literal');
cx(!str_contains($hubBranch, 'curl_init('),
   'and it does NOT make an HTTP request to its own site');

echo "\nBOTH SPOKE GATES ARE APPLIED LOCALLY\n";
// MUTANT M2 SURVIVED THE FIRST VERSION OF THIS. It checked that the manifest
// PATH appeared in the branch -- and turning the guard into if (false) leaves
// that path string sitting in the assignment above it, so the check passed
// while the gate was gone and a hub without the skin would have been given it
// fresh. Assert the NEGATION, which is the decision.
cx((bool)preg_match('/if \(!is_file\(\$hub_manifest\)\)/', $hubBranch),
   'HAS THE SKIN: the branch turns on !is_file($hub_manifest), so a hub without the skin is skipped');
cx(str_contains($hubBranch, 'not installed on the hub'),
   'and it says so plainly when it is not, rather than installing it fresh');
cx(str_contains($hubBranch, 'snap_version_compare($skin_version, $hub_ver'),
   'IS BEHIND: the version is compared before reinstalling');
cx(str_contains($hubBranch, 'already up to date'),
   'and an up-to-date hub is reported as skipped, not pushed to');

echo "\nIT IS REPORTED ALONGSIDE THE SPOKES\n";
cx(substr_count($hubBranch, '$skin_results[] =') >= 3,
   'every outcome -- missing skin, up to date, installed -- lands in the same results list ('
   . substr_count($hubBranch, '$skin_results[] =') . ' paths)');
cx(str_contains($hubBranch, '(this hub)') || str_contains($hubBranch, 'This hub'),
   'and is labelled as the hub, so a row cannot be mistaken for a spoke');

echo "\nTHE STALENESS DECISION ITSELF, CALLED FOR REAL\n";
// The Found Textures case, with the actual versions. This is the predicate
// that decides install-or-skip, and it is the same one the spoke path uses.
cx(function_exists('snap_version_compare'), 'snap_version_compare is available to call');
cx(snap_version_compare('1.3.25', '1.3.24', '>') === true,
   'package 1.3.25 against installed 1.3.24 is NEWER, so the hub is pushed to -- the Found Textures case');
cx(snap_version_compare('1.3.25', '1.3.25', '>') === false,
   'package 1.3.25 against installed 1.3.25 is NOT newer, so an up-to-date hub is skipped');
cx(snap_version_compare('1.3.24', '1.3.25', '>') === false,
   'and an OLDER package never reinstalls over a newer hub, which would be a downgrade');

echo "\nTHE HUB'S OWN VERSION IS READ THE WAY EVERYTHING ELSE READS IT\n";
// load_skin_manifest is the canonical reader; a hand-rolled json_decode here
// would drift from it. Called for real against an installed skin.
$mf = load_skin_manifest('impact-printer');
cx(is_array($mf) && ($mf['version'] ?? '') !== '',
   'load_skin_manifest returns a version for an installed skin (got '
   . var_export($mf['version'] ?? null, true) . ')');
cx(str_contains($hubBranch, 'load_skin_manifest($skin_slug)'),
   'and the hub branch uses that reader rather than parsing the manifest itself');

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
