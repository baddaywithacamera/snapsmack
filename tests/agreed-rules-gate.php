<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The rules we agreed, checked by a machine instead of by me remembering.
 *
 * Sean, 15 October 2026: "you two keep doing things we have agreed not to over
 * and over and i can't seem to get you to stop ... everything from skipping
 * versions when you have promised not to to breaking fundamental design rules
 * we all agreed on."
 *
 * He is right, and the reason is not that the rules were unclear. It is that
 * nothing enforced them, so compliance depended on an agent recalling them at
 * the moment it typed. That is not a mechanism. This file is the mechanism.
 *
 * Each rule below is one Sean has stated and that has since been broken. The
 * gate fails the build, so the rule stops being advice.
 *
 * Counts are a RATCHET: they may shrink, never grow. A new violation fails
 * immediately; fixing violations and not lowering the number also fails, so
 * the baseline cannot quietly drift upward.
 */

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) $failures[] = $what;
};

/* ── RULE 1 ───────────────────────────────────────────────────────────────
 * NEVER inline CSS. "no static style=... ever; geometry -> master CSS,
 * colour -> theme files." Raised repeatedly; broken in RATIONAL GEO, which
 * drew a second yellow frame around every photograph for days.
 * The only file exceptions are the self-contained, one-file bootstrap
 * installers fedup.php and setup.php. Neither is part of the skin/template
 * scan below. The count is the known presentation debt in actual templates. */
// 36 on 15 Oct 2026, measured by this gate. Codex counted 27 because he
// scanned skins/ only; the three CMS renderers that draw skins hold the rest.
// A budget set to a hoped-for number is a gate that gets switched off, so this
// is the truth on the day, and it may only ever go down.
$INLINE_STYLE_BUDGET = 28;   // measured 2026-10-17, see the note below
// 1513b8ac set this to 9 while the real count was 28. The gate failed from
// that commit onward and nobody ran it: 0.7.898D, 899D and 900D all shipped
// with it red, and 900D is deployed on the fleet. A budget is the MEASURED
// count, not the count somebody hoped for -- set below the truth it stops
// being a ratchet and becomes noise that gets ignored, which is exactly what
// happened. Lower it only in the same commit that removes the styles, and run
// the gate before pushing.

$templates = array_merge(
    glob($root . '/skins/*/layout.php') ?: [],
    glob($root . '/core/*-page-component.php') ?: []
);
$inline = 0;
$offenders = [];
foreach ($templates as $file) {
    $src = (string)file_get_contents($file);
    $n = preg_match_all('/\bstyle\s*=\s*"/i', $src);
    if ($n > 0) {
        $inline += $n;
        $rel = ltrim(str_replace(array($root, chr(92)), array('', '/'), $file), '/');
        $offenders[] = $rel . ' (' . $n . ')';
    }
}
$check($inline <= $INLINE_STYLE_BUDGET,
    'inline style attributes in skin templates rose to ' . $inline . ', budget is '
    . $INLINE_STYLE_BUDGET . '. New inline CSS is not allowed: ' . implode(', ', $offenders));
$check($inline >= $INLINE_STYLE_BUDGET,
    'inline style attributes dropped to ' . $inline . ' -- lower $INLINE_STYLE_BUDGET to '
    . $inline . ' so the ratchet holds the ground that was just taken');

/* ── RULE 2 ───────────────────────────────────────────────────────────────
 * CSS is not assembled in PHP. core/public-skin-presentation.php built
 * 'border:...;outline:Npx solid #ffcc00' as a string and the skin printed it
 * as an attribute. Presentation decided in PHP is the same offence wearing a
 * different hat. */
$cssInPhp = [];
foreach (glob($root . '/core/*.php') ?: [] as $file) {
    $base = basename($file);
    // The presentation compiler's job IS to emit declared custom properties
    // from manifest controls; that is the sanctioned mechanism, not a bypass.
    if ($base === 'skin-presentation.php') continue;
    $src = (string)file_get_contents($file);
    if (preg_match_all('/[\'"][a-z-]{3,20}\s*:\s*[^\'"]{0,40}(solid|#[0-9a-fA-F]{3,6}|\d+px)[^\'"]{0,40}[\'"]/', $src, $m)) {
        foreach ($m[0] as $hit) $cssInPhp[] = $base . ': ' . trim($hit);
    }
}
$CSS_IN_PHP_BUDGET = 21;  // measured 15 Oct 2026; may only go down
$check(count($cssInPhp) >= $CSS_IN_PHP_BUDGET,
    'CSS-in-PHP dropped to ' . count($cssInPhp) . ' -- lower $CSS_IN_PHP_BUDGET to '
    . count($cssInPhp) . ' so it cannot climb back');
$check(count($cssInPhp) <= $CSS_IN_PHP_BUDGET,
    'CSS declarations are being assembled in PHP (' . count($cssInPhp) . ', budget '
    . $CSS_IN_PHP_BUDGET . '): ' . implode(' | ', array_slice($cssInPhp, 0, 6)));

/* ── RULE 3 ───────────────────────────────────────────────────────────────
 * A changed skin file must carry a version bump, or it never reaches a site.
 * Skins install BY VERSION. Editing a skin and leaving its version alone
 * produces a fix that exists in the repository and on no site at all -- which
 * is what made five "fixes" to wateronthebrain.ca change nothing. */
$lastTag = trim((string)@shell_exec('git -C ' . escapeshellarg($root) . ' describe --tags --abbrev=0 2>/dev/null'));
if ($lastTag !== '') {
    $changed = (string)@shell_exec('git -C ' . escapeshellarg($root)
        . ' diff --name-only ' . escapeshellarg($lastTag) . ' -- skins/ 2>/dev/null');
    $touched = [];
    foreach (array_filter(array_map('trim', explode("\n", $changed))) as $path) {
        if (!preg_match('#^skins/([^/]+)/#', $path, $m)) continue;
        $touched[$m[1]][] = basename($path);
    }
    foreach ($touched as $slug => $files) {
        if (in_array('manifest.json', $files, true)) continue;   // version may have moved
        $check(false, $slug . ' has changed files since ' . $lastTag . ' ('
            . implode(', ', $files) . ') but its manifest.json was not touched. '
            . 'Skins install by version: without a bump this change reaches no site.');
    }
}

/* ── RULE 4 ───────────────────────────────────────────────────────────────
 * The version in the source must not be one that is already tagged, and a
 * tag that has been built must never be moved. Sean has asked for this more
 * times than any other single thing; it was broken again tonight on a build
 * he had deployed half an hour earlier. */
$constants = (string)@file_get_contents($root . '/core/constants.php');
preg_match("/SNAPSMACK_VERSION_SHORT',\s*'([^']+)'/", $constants, $vm);
$declared = $vm[1] ?? '';
$check($declared !== '', 'core/constants.php declares no version');
if ($declared !== '') {
    $tagged = trim((string)@shell_exec('git -C ' . escapeshellarg($root)
        . ' tag --list ' . escapeshellarg('v' . $declared) . ' 2>/dev/null'));
    if ($tagged !== '') {
        $tagCommit  = trim((string)@shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse ' . escapeshellarg($tagged . '^{commit}') . ' 2>/dev/null'));
        $headCommit = trim((string)@shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>/dev/null'));
        // STRICT ON PURPOSE. A tag that has not been deployed may legitimately
        // move -- that is Sean's fold rule. A tag he HAS deployed must never
        // move, which is the offence committed against 0.7.886D half an hour
        // after he shipped it. Git cannot tell those apart.
        //
        // An earlier version of this check asked a live site which version it
        // was serving. From PHP the request failed and the check silently
        // SKIPPED, which is worse than useless: a gate that passes when it
        // cannot tell gives false comfort. So this fails on both cases and
        // makes the tag move a deliberate act rather than a habit.
        //
        // This is not the real control. A local test only runs when somebody
        // runs it, and on 15 October nobody did. Server-side protection of the
        // released tag namespace is the only thing that would have refused
        // that push. Until that exists, treat this as a reminder, not a gate.
        // The fleet, not one site. On 15 October a single-site guard sampled
        // wateronthebrain.ca, found 0.7.891D, and allowed v0.7.892D to be
        // force-moved -- while twelve other sites were already serving 892D.
        $deployed = trim((string)@shell_exec('bash ' . escapeshellarg($root . '/tools/_build/deployed-version.sh') . ' 2>/dev/null'));
        if ($deployed !== '' && $deployed !== 'UNKNOWN') {
            $check($declared !== $deployed,
                'the source declares ' . $declared . ', which is IN SERVICE on the fleet. '
                . 'A deployed tag must never move. Open the next number.');
        }
        $check($tagCommit === $headCommit,
            'the source declares ' . $declared . ', already tagged at '
            . substr($tagCommit, 0, 8) . ' while HEAD is ' . substr($headCommit, 0, 8)
            . '. If that version is DEPLOYED, open the next number -- never move it. '
            . 'If it is not deployed, move the tag deliberately and re-run.');
    }
}

/* ── RULE 5 ───────────────────────────────────────────────────────────────
 * CMS core must not grow new knowledge of individual skins.
 *
 * Sean: "a skin specific css fix should never have been put in the cms core."
 * core/public-skin-presentation.php contains `if ($skin === 'rational-geo')`,
 * and that block is where the double yellow frame was built. A per-skin branch
 * in core is how one skin's presentation decision becomes everybody's problem
 * and how a CMS release can break a single skin for days.
 *
 * Unpicking the existing couplings is a refactor, not a gate. What a gate can
 * do is stop the number rising: core may forget a skin, never learn a new one.
 */
$skinSlugs = [];
foreach (glob($root . '/skins/*/manifest.json') ?: [] as $mf) {
    $skinSlugs[] = basename(dirname($mf));
}
$coupled = [];
foreach (glob($root . '/core/*.php') ?: [] as $file) {
    $src = (string)file_get_contents($file);
    foreach ($skinSlugs as $slug) {
        if (str_contains($src, "'" . $slug . "'")) $coupled[] = basename($file) . ' -> ' . $slug;
    }
}
$COUPLING_BUDGET = 55;   // measured 15 Oct 2026; may only go down
$n = count($coupled);
$check($n <= $COUPLING_BUDGET,
    'CMS core now names ' . $n . ' skin slugs, budget ' . $COUPLING_BUDGET
    . '. Core must not learn about another individual skin -- put the decision in '
    . 'the skin manifest or its stylesheet. New: '
    . implode(', ', array_slice(array_diff($coupled, []), -3)));
$check($n >= $COUPLING_BUDGET,
    'core/skin coupling fell to ' . $n . ' -- lower $COUPLING_BUDGET to ' . $n
    . ' so it cannot climb back');

printf("agreed rules: %d inline styles (budget %d), %d CSS-in-PHP (budget %d), version %s.\n",
    $inline, $INLINE_STYLE_BUDGET, count($cssInPhp), $CSS_IN_PHP_BUDGET, $declared);

if ($failures) {
    fwrite(STDERR, "AGREED RULES GATE FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Agreed rules gate passed.\n";

// ===== SNAPSMACK EOF =====
