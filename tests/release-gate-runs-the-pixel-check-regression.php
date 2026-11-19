<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * SS-1436, the release-gate half — a check nobody calls is not a check.
 *
 * WHAT HAPPENED. The gate's render-snapshot and parity checks fingerprint
 * markup and asset references. Neither reads stylesheet contents and neither
 * looks at a rendered page, so a visible skin CSS mutation passed both; only
 * the version/content check noticed the file had changed at all. So
 * `skin_render_parity: "pass"` in a release-gate note was saying less than it
 * appeared to, and 31 skins could change appearance without the gate caring.
 *
 * The real-browser comparator, tools/browser-tests/skin-render-pixel-parity.cjs,
 * landed on dev on 2026-11-18 as b8c8ff16 — AND NOTHING INVOKED IT. Not this
 * repository's release flow, not the browser runner. The file existed, the
 * ticket looked addressed, and the gate was exactly as weak as before it
 * landed. That is the same shape as the two faults this week: the guard that
 * passes because its output is never read, except here the guard never ran.
 *
 * So this file pins the WIRING, which is the part that was missing, and it
 * pins it from source rather than by running a release: a release run needs a
 * clean tree on dev, a tag, Playwright installed and a browser, none of which
 * belongs in the test suite.
 *
 * LANES. tools/browser-tests/ and the comparator itself belong to the
 * appearance owner. Nothing here edits them; the release flow CALLS the
 * script. This file asserts the call exists.
 */

$root = dirname(__DIR__);
$failures = [];

function rgp_check(string $label, bool $ok, array &$failures): void {
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures[] = $label;
    }
}

/**
 * Source with comments removed.
 *
 * Three source assertions of mine this week matched my own comments instead of
 * the code they described. Tokeniser, not line-prefix stripping, and applied
 * to every check in the file rather than to the one that happened to trip.
 */
function rgp_code_only(string $php): string {
    $out = '';
    foreach (@token_get_all($php) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
            $out .= $t[1];
            continue;
        }
        $out .= $t;
    }
    return $out;
}

echo "SS-1436 — the release gate actually runs the pixel check\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — the comparator exists where the gate expects it\n";
// ─────────────────────────────────────────────────────────────────────────────
$script = $root . '/tools/browser-tests/skin-render-pixel-parity.cjs';
rgp_check('the real-browser pixel comparator is present', is_file($script), $failures);
rgp_check('and is not an empty placeholder',
          is_file($script) && filesize($script) > 1000, $failures);

$flow_raw = (string)file_get_contents($root . '/tools/release-flow.php');
$flow     = rgp_code_only($flow_raw);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — the release flow CALLS it (the gap this ticket was about)\n";
// ─────────────────────────────────────────────────────────────────────────────
rgp_check('the release flow names the pixel comparator',
          str_contains($flow, 'skin-render-pixel-parity.cjs'), $failures);

// It must be called from the browser-test step, not merely mentioned. The
// function body is sliced so a reference in some unrelated helper cannot
// satisfy this.
$start = strpos($flow, 'function rf_browser_tests()');
$body  = $start === false ? '' : substr($flow, $start, 900);
rgp_check('rf_browser_tests() was located', $body !== '', $failures);
// THE EXACT CALL, not "a call and a mention". A mutation that commented out
// the run survived an earlier version of this check: the assignment
// `$pixel = rf_pixel_parity_script();` was still there, and two other
// rf_run([...]) calls were still there, so both halves of a loose AND were
// satisfied by a function that no longer ran the comparator at all.
rgp_check('and it RUNS the comparator, rather than only knowing its path',
          str_contains($body, "rf_run(['node', \$pixel])"), $failures);
rgp_check('and it still runs the existing browser suite as well',
          str_contains($body, 'run-regressions.cjs')
          && str_contains($body, 'browser-release-runner-regression.cjs'), $failures);

// FAIL CLOSED. A missing comparator must stop the release, not be skipped --
// a silent skip is indistinguishable from a pass, which is how the gate came
// to be trusted for something it never checked.
rgp_check('a missing comparator FAILS the release rather than being skipped',
          str_contains($body, 'rf_fail(') && str_contains($body, 'is_file($pixel)'), $failures);
rgp_check('and the refusal names the file it looked for',
          str_contains($flow_raw, 'LOOKED FOR: ') , $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — the gate note records it, and cannot be tagged without it\n";
// ─────────────────────────────────────────────────────────────────────────────
rgp_check('skin_pixel_parity is a required gate-note field',
          str_contains($flow, "'skin_pixel_parity' => 'pass'"), $failures);
rgp_check('and the markup-level skin_render_parity is still required too',
          str_contains($flow, "'skin_render_parity' => 'pass'"), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — THE WRITER AND THE VERIFIER AGREE\n";
// ─────────────────────────────────────────────────────────────────────────────
// The failure mode that would be worse than the bug: requiring a field the
// note writer never emits. Every release would then be refused, by this
// repository's own tooling, with a message about a field nobody can produce.
//
// Both lists live in tools/release-flow.php -- the verifier near the top of
// rf_gate_problem(), the writer in the note template -- so they are compared
// here by counting occurrences rather than trusted to stay in step.
foreach (['skin_render_parity', 'skin_pixel_parity', 'browser_regressions',
          'authority_review', 'security', 'parity'] as $field) {
    $n = substr_count($flow, "'" . $field . "' =>");
    rgp_check("{$field} appears in BOTH the required list and the note the flow writes",
              $n >= 2, $failures);
}

// And the note the writer builds must satisfy the verifier's own list. This is
// the check that would have caught a one-sided edit of either.
preg_match('/\$required = \[(.*?)\];/s', $flow, $req);
preg_match('/\$note = \[(.*?)\];/s', $flow, $note);
rgp_check('both the required list and the note template were located',
          !empty($req[1]) && !empty($note[1]), $failures);
if (!empty($req[1]) && !empty($note[1])) {
    preg_match_all("/'([a-z_]+)' => '([a-z]+)'/", $req[1], $rm, PREG_SET_ORDER);
    $missing = [];
    foreach ($rm as $m) {
        if ($m[1] === 'commit') continue;          // filled from HEAD, not a literal
        if (!str_contains($note[1], "'" . $m[1] . "' => '" . $m[2] . "'")) {
            $missing[] = $m[1];
        }
    }
    rgp_check('every required field is one the flow actually writes: '
              . ($missing ? 'MISSING ' . implode(', ', $missing) : 'all present'),
              $missing === [], $failures);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — the release policy says so in words\n";
// ─────────────────────────────────────────────────────────────────────────────
// A gate that is only in code is a gate the person cutting the release cannot
// check themselves. RELEASING.md already documents skin_render_parity.
$doc = (string)file_get_contents($root . '/RELEASING.md');
// THE LOAD-BEARING SENTENCE, not any mention. Removing the field from the
// "must record" line survived an earlier check that only looked for the name
// anywhere in the file -- the paragraph explaining the field still named it,
// so the document still "mentioned" a requirement it no longer stated.
preg_match('/must record[^.]*\./s', $doc, $mustRecord);
rgp_check('RELEASING.md was located and states what a gate note must record',
          !empty($mustRecord[0]), $failures);
rgp_check('and that sentence names skin_pixel_parity as required',
          !empty($mustRecord[0]) && str_contains($mustRecord[0], 'skin_pixel_parity'),
          $failures);
rgp_check('and says what it proves that markup parity does not',
          stripos($doc, 'rendered') !== false && stripos($doc, 'pixel') !== false, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1436 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "SS-1436 PASS — the pixel comparator is called by the release flow, a\n";
echo "missing one fails the release, and the note field is both required and\n";
echo "written.\n";
echo "NOT COVERED: this asserts the WIRING from source. It does not run a\n";
echo "release, does not start a browser, and does not re-prove that the\n";
echo "comparator detects a CSS mutation -- that is the comparator's own\n";
echo "self-test, in the appearance owner's lane, and it is reported separately.\n";
// ===== SNAPSMACK EOF =====
