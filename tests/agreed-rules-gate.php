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
// 0 on 23 Oct 2026, and 0 is the real number -- not an aspiration.
//
// This read 27 for weeks and nobody could reduce it, because the 27 were not
// violations. The check matched the opening of any style= attribute and could
// not see whether PHP was inside it, so every per-photograph frame colour and
// every custom property fed from a setting was counted as presentation debt.
// Sean's rule allows exactly those: "no static style= ever; dynamic PHP styles
// the only exception." The gate was counting the exception.
//
// Acting on that count would have been worse than ignoring it. "Clear all 28"
// means deleting the frame colour an owner picked for one photograph -- a real
// feature -- to satisfy a miscount. A number that cannot be acted on honestly
// is one people learn to carry, and that is how this gate came to be red for
// three shipped releases with nobody treating it as real.
//
// It now counts only attributes with no PHP in them, the budget is 0, and the
// next genuine static style fails the build on its first commit instead of
// hiding in a crowd of 28 permitted ones.
//
// The literal-minded hole, agreed in the Brain Trust as not worth closing:
// a style= whose PHP merely echoes a constant string passes. It is contrived,
// review catches
// deliberate disguise, and a rule with no give in it is one that gets
// switched off.
$INLINE_STYLE_BUDGET = 0;    // measured 2026-10-23: ZERO static inline styles
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
    // Match the WHOLE attribute, then keep only the ones with no PHP in them.
    // The old pattern matched '/\bstyle\s*=\s*"/' -- the opening of the
    // attribute -- and stopped looking, so it could not tell
    //     style="color:red"                                 a violation
    // from
    //     style="<?php echo $item['frame_colours']; ..."   the exception
    // and counted both. Measured 2026-10-23: 28 matches, 28 of them dynamic,
    // zero static. The budget of 27 was never presentation debt awaiting
    // cleanup; it was the permitted exception being counted as an offence,
    // which is why it could only ever be carried, never paid down.
    if (!preg_match_all('/\bstyle\s*=\s*"([^"]*)"/i', $src, $m)) continue;
    $n = 0;
    foreach ($m[1] as $value) {
        if (strpos($value, '<?php') !== false || strpos($value, '<?=') !== false) continue;
        $n++;
    }
    if ($n > 0) {
        $inline += $n;
        $rel = ltrim(str_replace(array($root, chr(92)), array('', '/'), $file), '/');
        $offenders[] = $rel . ' (' . $n . ')';
    }
}
$check($inline <= $INLINE_STYLE_BUDGET,
    'STATIC inline style attributes in skin templates rose to ' . $inline . ', budget is '
    . $INLINE_STYLE_BUDGET . '. Hard-coded CSS in markup is not allowed -- geometry belongs in '
    . 'the master stylesheet, colour in the theme files. (A style= carrying a PHP value is the '
    . 'documented exception and is not counted.) Offenders: ' . implode(', ', $offenders));
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
        // A directory with no manifest.json is not a shippable skin -- the
        // packager skips it and the registry has nowhere to list it, so
        // "bump its version" is advice with nothing to bump. skins/beatbox
        // has no manifest and was flagged for two fleet-wide security
        // commits from 29 September that touched every skin at once.
        if (!is_file($root . '/skins/' . $slug . '/manifest.json')) continue;
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
        // PUBLISHED, NOT JUST DEPLOYED. The line that used to stand here said
        // "If it is not deployed, move the tag deliberately and re-run", and on
        // 29 October an agent followed it and re-pointed v0.7.970D -- a tag
        // that was already on origin. The advice was sound for a tag that had
        // never left the machine and wrong for one that had, and this check
        // could not tell the two apart because it only ever asked the fleet
        // what it was SERVING. A tag is immutable the moment it exists on
        // origin, built or not: anyone may have fetched it.
        //
        // The reasoning lives in tools/_build/release-tag-rules.php so it can
        // be mutation-tested; including this file runs it, so a test cannot
        // reach inside it. See tests/release-tag-rules-regression.php.
        require_once $root . '/tools/_build/release-tag-rules.php';
        $verdict = snap_gate_tag_move_verdict(
            $declared, $tagCommit, $headCommit,
            snap_gate_origin_tag_state($root, $tagged));
        $check($verdict['ok'], 'the source declares ' . $declared . '. ' . $verdict['message']);
        if ($verdict['ok'] && $verdict['note'] !== '') {
            echo '  note  ' . $verdict['note'] . "
";
        }
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

/* ── RULE 6 ───────────────────────────────────────────────────────────────
 * JavaScript is NEVER written inline in a PHP file. It lives in its own file
 * that the page loads.
 *
 * Sean, 29 October 2026: "JS script is never inline in a PHP file for any
 * reason in the CMS... I've been clear for a long time that we keep JS out of
 * PHP and it goes into separate files that are called. I am upset to find out
 * that this is a continuing issue."
 *
 * MEASURED the same day across every root and core PHP file and every skin
 * layout: 53 script blocks with a body and no src.
 *   - ONE is DATA: the schema.org JSON-LD in core/meta.php. It is exempt
 *     below BY TYPE, never by allowlist, because it is not JavaScript and it
 *     belongs in the document.
 *   - 52 were executable JavaScript: 3,178 lines. 8 blocks / 56 lines on
 *     pages a visitor loads, 3 blocks / 39 lines in the installer, and 41
 *     blocks / 3,083 lines in the admin.
 *
 * The 11 public and installer blocks were extracted the same day into
 * assets/js/ss-archive-state.js, ss-config-bootstrap.js, ss-profile-theme.js,
 * ss-pixel-theme.js, ss-wall-mobile-gate.js, ss-snap-in-tabs.js and
 * ss-install-forms.js, and their rows deleted. 41 admin blocks remain.
 *
 * THE LIMIT IS ZERO. Every block still inline is named in the allowlist with
 * its ticket, so the debt is visible and does not fail the build while it is
 * being paid down. A block that is not on the list fails on its first commit.
 *
 * The key is the file plus a hash of the block's contents, which makes the
 * list a one-way ratchet three ways over:
 *   - a NEW block has no row and fails;
 *   - an EXTRACTED block leaves its row matching nothing, and the gate tells
 *     you to delete the row, so the list can only shrink;
 *   - an EDITED block changes its hash and fails. That is intended. These
 *     blocks are not maintained in place; they are moved out.
 *
 * Server values travel to the moved file as data-* attributes. They are never
 * PHP interpolated into JavaScript: a value echoed into a script body is a
 * string that has to be escaped for a language the PHP escapers do not know,
 * which is how an XSS gets written by accident.
 *
 * WHY THIS TOKENIZES INSTEAD OF GREPPING. A regex over the file text counts
 * a <script> written in a DOCBLOCK. core/stats-logger.php says in prose "Emit
 * the Scroll Time tracker <script> tag", and the printf below it emits a tag
 * that carries src=, so a regex matched from the comment to the closing tag
 * and reported 35 lines of inline JS in a file whose own comment says
 * "Config passes via data-* attributes (no inline JS)". The file was right
 * and the first version of this gate was wrong. It now drops comment tokens
 * and keeps what can actually reach a browser: literal markup outside PHP
 * tags, and the string and heredoc literals that get echoed, because
 * core/network-alert.php builds its banner in a heredoc.
 *
 * Found while measuring, and recorded on SS-1041: the public CSP is
 * script-src 'self' unless the site owner has custom code enabled, so an
 * inline block on a public page is REFUSED BY THE BROWSER. Confirmed in a
 * browser on two live sites. Moving these out is not only a tidiness rule; on
 * the public side it is the difference between code that runs and code that
 * does not.
 */
$JS_DATA_TYPES = ['application/ld+json', 'application/json', 'application/schema+json',
                  'text/template', 'text/x-template', 'text/html'];
// One row per block still inline. DELETE a row the moment its block moves.
$JS_IN_PHP_ALLOWLIST = [
    // smack-lt-gram.php -- 1 block(s), 503 lines
    'smack-lt-gram.php#d7fe6016d3cc15d9' => 'SS-1041 (503 lines, was line 762)',
    // smack-mosaics.php -- 2 block(s), 489 lines
    'smack-mosaics.php#ac56c92ee3e52060' => 'SS-1041 (480 lines, was line 491)',
    'smack-mosaics.php#b3c8589fdd90507c' => 'SS-1041 (9 lines, was line 1028)',
    // smack-fingerprints.php -- 1 block(s), 399 lines
    'smack-fingerprints.php#2f2e8144e77fe197' => 'SS-1041 (399 lines, was line 424)',
    // smack-post-long.php -- 1 block(s), 332 lines
    'smack-post-long.php#9f449e2a2c968bfc' => 'SS-1041 (332 lines, was line 912)',
    // smack-collections.php -- 1 block(s), 223 lines
    'smack-collections.php#a9d844c5ac182b4c' => 'SS-1041 (223 lines, was line 496)',
    // smack-skin.php -- 3 block(s), 174 lines
    'smack-skin.php#1ceffd47446f70f8' => 'SS-1041 (42 lines, was line 1512)',
    'smack-skin.php#470d5f1e36ff67c4' => 'SS-1041 (108 lines, was line 2277)',
    'smack-skin.php#cf40d7af525c1ee4' => 'SS-1041 (24 lines, was line 2409)',
    // smack-update.php -- 2 block(s), 158 lines
    'smack-update.php#6c4f2241a24882a1' => 'SS-1041 (118 lines, was line 2054)',
    'smack-update.php#8834460c0891fea1' => 'SS-1041 (40 lines, was line 2546)',
    // smack-multisite.php -- 2 block(s), 157 lines
    'smack-multisite.php#9a13d58dd2b3cfb5' => 'SS-1041 (59 lines, was line 1563)',
    'smack-multisite.php#3f5912a9bf2a2412' => 'SS-1041 (98 lines, was line 1625)',
    // smack-settings.php -- 3 block(s), 107 lines
    'smack-settings.php#c21c2f77b86f83fb' => 'SS-1041 (38 lines, was line 1225)',
    'smack-settings.php#53316a828540bd68' => 'SS-1041 (26 lines, was line 1266)',
    'smack-settings.php#b8a2761c1fabe7f5' => 'SS-1041 (43 lines, was line 1295)',
    // smack-manage.php -- 2 block(s), 89 lines
    'smack-manage.php#e9fe414864852768' => 'SS-1041 (40 lines, was line 977)',
    'smack-manage.php#2660b59d5f9cb8af' => 'SS-1041 (49 lines, was line 1023)',
    // smack-ftp.php -- 1 block(s), 79 lines
    'smack-ftp.php#d9f8e4e4fc33b943' => 'SS-1041 (79 lines, was line 341)',
    // smack-appearance-archive.php -- 1 block(s), 48 lines
    'smack-appearance-archive.php#e2bf1362ea966e7f' => 'SS-1041 (48 lines, was line 417)',
    // smack-help.php -- 1 block(s), 43 lines
    'smack-help.php#8681192426ff9674' => 'SS-1041 (43 lines, was line 4668)',
    // smack-albums.php -- 1 block(s), 42 lines
    'smack-albums.php#2faf048283039362' => 'SS-1041 (42 lines, was line 253)',
    // smack-photochallenge.php -- 1 block(s), 39 lines
    'smack-photochallenge.php#70245d0b7d7e1ebe' => 'SS-1041 (39 lines, was line 572)',
    // smack-community-settings.php -- 1 block(s), 37 lines
    'smack-community-settings.php#32e925174c8b47a7' => 'SS-1041 (37 lines, was line 445)',
    // smack-cats.php -- 1 block(s), 32 lines
    'smack-cats.php#670d60642e6b8c46' => 'SS-1041 (32 lines, was line 246)',
    // smack-2fa.php -- 2 block(s), 20 lines
    'smack-2fa.php#916186016be347b6' => 'SS-1041 (1 lines, was line 446)',
    'smack-2fa.php#52e4a432ba83eaaa' => 'SS-1041 (19 lines, was line 448)',
    // smack-back.php -- 2 block(s), 20 lines
    'smack-back.php#b435db3e212e1bdc' => 'SS-1041 (11 lines, was line 828)',
    'smack-back.php#da9ba71b46603980' => 'SS-1041 (9 lines, was line 841)',
    // smack-shortcodes.php -- 1 block(s), 17 lines
    'smack-shortcodes.php#71e419fdde3d6ef5' => 'SS-1041 (17 lines, was line 184)',
    // smack-edit-carousel.php -- 1 block(s), 16 lines
    'smack-edit-carousel.php#e84e68403798cacb' => 'SS-1041 (16 lines, was line 1136)',
    // smack-edit.php -- 2 block(s), 15 lines
    'smack-edit.php#17fda85c483b0213' => 'SS-1041 (7 lines, was line 668)',
    'smack-edit.php#ad27f2a3ec91e96d' => 'SS-1041 (8 lines, was line 678)',
    // smack-forum.php -- 1 block(s), 11 lines
    'smack-forum.php#909fa345668519d7' => 'SS-1041 (11 lines, was line 1074)',
    // smack-privacy.php -- 1 block(s), 10 lines
    'smack-privacy.php#bd6c1f47d020ed03' => 'SS-1041 (10 lines, was line 145)',
    // smack-menu.php -- 1 block(s), 7 lines
    'smack-menu.php#47c7b317b79f334b' => 'SS-1041 (7 lines, was line 586)',
    // smack-post-solo.php -- 1 block(s), 7 lines
    'smack-post-solo.php#17fda85c483b0213' => 'SS-1041 (7 lines, was line 1026)',
    // smack-globalvibe.php -- 1 block(s), 6 lines
    'smack-globalvibe.php#6253d0c2d6c3199b' => 'SS-1041 (6 lines, was line 571)',
    // core/admin-footer.php -- 1 block(s), 1 lines
    'core/admin-footer.php#c51b145e3fdddc58' => 'SS-1041 (1 lines, was line 44)',
    // core/admin-header.php -- 1 block(s), 1 lines
    'core/admin-header.php#e53efbaa5d334bca' => 'SS-1041 (1 lines, was line 135)',
    // core/network-alert.php -- 1 block(s), 1 lines
    'core/network-alert.php#329734de50fb9998' => 'SS-1041 (1 lines, was line 513)',
];

$jsScan = array_merge(
    glob($root . '/*.php') ?: [],
    glob($root . '/core/*.php') ?: [],
    glob($root . '/skins/*/layout.php') ?: []
);
$jsOffenders = [];
$jsSeen = [];
$jsDataBlocks = 0;
foreach ($jsScan as $file) {
    $src = (string)file_get_contents($file);
    $rel = str_replace(chr(92), '/', $file);
    $jsPrefix = rtrim(str_replace(chr(92), '/', $root), '/') . '/';
    if ($jsPrefix !== '/' && str_starts_with($rel, $jsPrefix)) $rel = substr($rel, strlen($jsPrefix));
    $tokens = @token_get_all($src);
    if (!is_array($tokens)) continue;
    // Blank the comments, keeping their newlines so line numbers stay real.
    $text = '';
    foreach ($tokens as $t) {
        if (is_string($t)) { $text .= $t; continue; }
        if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
            $text .= str_repeat("\n", substr_count($t[1], "\n"));
            continue;
        }
        $text .= $t[1];
    }
    // A <script> carrying src= is the sanctioned form and never matches.
    if (!preg_match_all('/<script(?![^>]*\bsrc=)([^>]*)>(.*?)<\/script>/s', $text, $m, PREG_SET_ORDER)) continue;
    foreach ($m as $hit) {
        $body = trim($hit[2]);
        if ($body === '') continue;
        $type = '';
        if (preg_match('/type\s*=\s*["\']([^"\']+)["\']/i', $hit[1], $t)) {
            $type = strtolower(trim($t[1]));
        }
        if (in_array($type, $JS_DATA_TYPES, true)) { $jsDataBlocks++; continue; }
        $key = $rel . '#' . substr(hash('sha256', $body), 0, 16);
        if (isset($JS_IN_PHP_ALLOWLIST[$key])) { $jsSeen[$key] = true; continue; }
        $jsOffenders[] = $rel . ' (' . (substr_count($body, "\n") + 1) . ' lines, key ' . $key . ')';
    }
}
$jsStale = array_diff(array_keys($JS_IN_PHP_ALLOWLIST), array_keys($jsSeen));
$check($jsOffenders === [],
    'INLINE JAVASCRIPT IN A PHP FILE, and it is not on the allowlist (' . count($jsOffenders)
    . '). JavaScript goes in its own file under assets/js/ and the page loads it with a '
    . 'script src; server values travel as data-* attributes. THE LIMIT IS ZERO. '
    . 'Offenders: ' . implode(' | ', array_slice($jsOffenders, 0, 6)));
$check($jsStale === [],
    'the JS-in-PHP allowlist has ' . count($jsStale) . ' row(s) matching nothing. A block that '
    . 'has moved must have its row DELETED so the list can only shrink: '
    . implode(' | ', array_slice($jsStale, 0, 6)));


printf("agreed rules: %d inline styles (budget %d), %d CSS-in-PHP (budget %d), %d inline JS blocks left on the allowlist (limit 0 for any new one), %d data blocks exempt, version %s.
",
    $inline, $INLINE_STYLE_BUDGET, count($cssInPhp), $CSS_IN_PHP_BUDGET,
    count($JS_IN_PHP_ALLOWLIST), $jsDataBlocks, $declared);

if ($failures) {
    fwrite(STDERR, "AGREED RULES GATE FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Agreed rules gate passed.\n";

// ===== SNAPSMACK EOF =====
