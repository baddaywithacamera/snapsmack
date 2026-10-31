<?php
/**
 * A fresh install subscribes to the line it was installed from.
 *
 * SS-1076. install.php seeds about sixty settings defaults and update_track is
 * not one of them -- not set to stable, not set at all. updater_get_track()
 * returns 'stable' whenever the row is missing, so the default was never a
 * decision anybody made: it fell out of an absent row.
 *
 * WHAT THAT COSTS. The track decides what the updater OFFERS. A site installed
 * from a dev package and left on the stable track is never offered the older
 * stable build -- updater_check_status() only fires when the remote version is
 * NEWER -- so it reports UP TO DATE and receives nothing. Every security fix
 * after install day sits unoffered until somebody remembers a toggle nothing
 * mentions. It is stranded, not downgraded. A hub can correct a spoke's track;
 * a standalone site has nothing that corrects it.
 *
 * NO NEW QUESTION IS ASKED. The installer already knows the answer: it reads
 * the package version, and a dev package's version ends in D. Asking would
 * move a known fact into a guess, and "BORING or BITCHIN'" means nothing to
 * somebody who has just unzipped a file they were handed.
 *
 * Run:  php tests/install-records-its-update-track.php
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = realpath(__DIR__ . '/..');
$failures = [];
function trk_test(bool $ok, string $message): void {
    global $failures;
    if (!$ok) $failures[] = $message;
}

/** Source with comments stripped, so no check can match prose about itself. */
function trk_code(string $path): string {
    $out = '';
    foreach (token_get_all((string)file_get_contents($path)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

// ── The rule itself, EXECUTED. It lives in core so it can be. ───────────────
//
// INCLUDING THE UPDATER WRITES FILES. It self-heals .htaccess and .user.ini
// into the webroot at include time, so simply requiring it leaves artefacts
// behind -- and .user.ini is NOT in .gitignore, so it turns up as untracked
// clutter that somebody could commit by accident. That is not this ticket's
// business to fix, but a test must not leave a mess it made, so anything that
// was not there before this ran is removed afterwards.
$selfHealed = ['.htaccess', '.user.ini'];
$existedBefore = [];
foreach ($selfHealed as $f) $existedBefore[$f] = is_file("$root/$f");

require_once "$root/core/updater.php";

register_shutdown_function(static function () use ($root, $selfHealed, $existedBefore): void {
    foreach ($selfHealed as $f) {
        if (!$existedBefore[$f] && is_file("$root/$f")) @unlink("$root/$f");
    }
});

trk_test(function_exists('updater_track_for_version'),
    'core/updater.php has no updater_track_for_version(); the rule is not callable, '
  . 'so it can only be eyeballed in the installer');

if (function_exists('updater_track_for_version')) {
    // A dev package subscribes to the dev line.
    foreach (['0.7.973D', '0.7.184D', 'v0.7.973D', '1.0.0D'] as $v) {
        trk_test(updater_track_for_version($v) === 'dev', "a dev package ($v) did not choose the dev line");
    }
    // A stable package subscribes to stable.
    foreach (['0.7.670', '0.7.191', 'v0.6', '1.0.0'] as $v) {
        trk_test(updater_track_for_version($v) === 'stable', "a stable package ($v) did not choose stable");
    }
    // Nonsense must not become 'dev' by accident -- stable is the safe answer
    // because it is what every existing site already is.
    foreach (['', '   ', 'd', 'dev', 'D'] as $v) {
        trk_test(in_array(updater_track_for_version($v), ['stable', 'dev'], true),
            'the rule returned something that is not a track for ' . var_export($v, true));
    }
    trk_test(updater_track_for_version('') === 'stable', 'an empty version did not fall back to stable');
    // CASE MATTERS AND MUST NOT. A lowercase d is not a dev build -- the early
    // tags 0.7.9d and 0.7.9e exist on origin and are not dev packages.
    trk_test(updater_track_for_version('0.7.9d') === 'stable',
        'a lowercase d was read as a dev build; 0.7.9d is an old stable tag, not a dev package');
}

// ── The installer must actually use it, and seed the row ───────────────────
$install = trk_code("$root/install.php");
trk_test(str_contains($install, "'update_track'"),
    'install.php still seeds no update_track, so every new site is born on stable by accident');
// NOT just "the function name appears somewhere in install.php". The finished
// screen calls it too, so a bare search stayed green when the SEEDED VALUE was
// changed to a hardcoded 'dev'. The row itself has to come from the rule.
trk_test((bool)preg_match("/'update_track'\s*=>\s*updater_track_for_version\s*\(/", $install),
    'install.php seeds a hardcoded track instead of deriving it from the package version');

// ── The operator can see it without opening the database ───────────────────
// Acceptance point 3. A wrong value sitting unnoticed for weeks is the reason
// this ticket exists at all, and nothing anywhere states the track today.
// Checked on the WORDS THE OPERATOR READS, not on the code near them. Looking
// for "track" matched the updater_track_for_version() call still sitting in
// that block, so deleting the sentence left this green. The track's NAME is
// the thing a person can actually see.
$completeScreen = substr($install, (int)strpos($install, 'INSTALLATION COMPLETE'), 1800);
// Both the track's NAME and the sentence that connects it to this site. The
// labels alone were not enough: they sit inside a PHP expression, so deleting
// the sentence around them left them in the file and this check green.
trk_test(stripos($completeScreen, 'BITCHIN') !== false && stripos($completeScreen, 'BORING') !== false,
    'the finished-install screen never names the update track');
trk_test((bool)preg_match('/follows\s+the/i', $completeScreen),
    'the finished-install screen no longer tells the operator that THIS SITE '
  . 'follows a track; a label with no sentence around it says nothing');

// ── What must NOT change: an existing site with no row stays on stable ─────
$updater = trk_code("$root/core/updater.php");
trk_test(str_contains($updater, "('\$val === 'dev') ? 'dev' : 'stable'")
      || (bool)preg_match("/\\\$val\s*===\s*'dev'\s*\)\s*\?\s*'dev'\s*:\s*'stable'/", $updater),
    'updater_get_track() no longer falls back to stable for a site with no row; '
  . 'that would silently move every existing install onto the dev line');

if ($failures) {
    fwrite(STDERR, "FAIL install records its update track (" . count($failures) . ")\n  - "
        . implode("\n  - ", $failures) . "\n");
    exit(1);
}
echo "PASS a fresh install subscribes to the line it was installed from\n";
// ===== SNAPSMACK EOF =====
