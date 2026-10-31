<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/declared-controls-predicates.php';
/**
 * WHAT THE DECLARED-CONTROLS INVENTORY CANNOT SEE, asserted so it stays said.
 *
 * tests/declared-controls-have-a-consumer-regression.php is a CANDIDATE-
 * REFERENCE INVENTORY. It shows that a control the admin offers is referred to
 * by something a skin reaches. It does NOT follow the owner's value to the
 * page, and two specific things get past it.
 *
 * WHY THIS FILE EXISTS RATHER THAN A FIX. Codex read the inventory on
 * 31 October 2026 and named both cases: the predicate is broader than the
 * claim. He was right. The coordinator's ruling the same morning, 09:50Z, was
 * to take the cheap honest route with a beta ahead -- name the thing for what
 * it is, write the limits down as tests so they cannot be quietly forgotten,
 * and keep functional save-and-render verification as a separate job. Do not
 * build deeper flow analysis.
 *
 * SO THESE TESTS ASSERT THAT THE INVENTORY FAILS TO CATCH SOMETHING. That
 * reads backwards and it is deliberate. If someone later deepens the predicate
 * so that one of these IS caught, this file fails, and the failure message
 * tells them to delete the limit from here and from the inventory's header
 * rather than leaving a stale warning in place. A limit that stops being true
 * is as misleading as one nobody wrote down.
 *
 * NOTHING HERE TOUCHES THE REPOSITORY. Both cases are exercised against
 * strings held in memory, which is why the predicates were split into
 * tests/lib/. An earlier draft mutated smack-appearance-archive.php on disk
 * and restored it afterwards; a test that edits a real admin page is one
 * interrupted run away from leaving the product broken.
 *
 * Run:  php tests/declared-controls-gate-known-limits.php
 *
 * # SNAPSMACK_EOF_HEADER
 * # Last non-empty line must be: // ===== SNAPSMACK EOF =====
 */

$root = dirname(__DIR__);
$failures = [];
$notes = [];

function kl_fail(string $message): void
{
    global $failures;
    if ($message !== '') $failures[] = $message;
}

/* ── LIMIT 1: a defaults-table entry counts as a consumer ─────────────────
 *
 * dc_reads_key() rejects the control's own declaration and rejects a plain
 * write, and accepts everything else. A defaults table -- 'key' => 850 -- is
 * therefore a consumer as far as the inventory is concerned, even when
 * nothing downstream ever reads that array.
 *
 * THIS IS A DELIBERATE CHOICE, NOT AN OVERSIGHT, and the reason matters: a
 * defaults entry is how several real consumers in this repo reach their
 * value. Excluding it would manufacture false DEAD verdicts, which is the
 * exact failure that made the inventory confidently wrong about seven working
 * controls on 31 October (SS-1114, instance nine). Given a choice between
 * missing a dead control and declaring a working one dead, the inventory
 * misses. That is the right way round for a tool Sean reads, and it is still
 * a limit.
 */
$defaultsOnly = "<?php\nreturn ['some_setting' => 850, 'other' => 40];\n";
kl_fail(dc_reads_key($defaultsOnly, 'some_setting') === true ? ''
    : 'LIMIT 1 NO LONGER HOLDS: dc_reads_key() now rejects a defaults-table '
    . 'entry. If that is deliberate, check that no working control has started '
    . 'reading as DEAD, then delete this limit from here and from the '
    . "inventory's header.");
$notes[] = 'LIMIT 1 holds: a defaults-table entry with no downstream read '
    . 'counts as a consumer.';

// The two things it does still reject, so the limit is bounded rather than
// "it accepts anything".
kl_fail(dc_reads_key('<input name="settings[some_setting]">', 'some_setting') === false ? ''
    : 'the control\'s own declaration is being counted as a consumer; that is '
    . 'not a known limit, it is the hole the inventory exists to avoid.');
kl_fail(dc_reads_key("<?php\n\$settings['some_setting'] = 12;\n", 'some_setting') === false ? ''
    : 'a plain write is being counted as a consumer.');

/* ── LIMIT 2: a constant in place of the lookup is invisible ──────────────
 *
 * dc_css_at_save_complaints() requires the key to be read out of
 * $_POST['settings'], the variable it lands in to be used again, and a rule
 * upsert to occur, all before custom_css_public is written. Codex's point:
 * replace the preset lookup with a constant and ALL THREE still hold. The key
 * is still read. The variable is still used -- by the validation line that
 * checks it. The upserts are still there. And the owner's choice no longer
 * affects a single pixel.
 *
 * Asserted against the REAL page and against a copy of it with exactly that
 * substitution, because the limit is only interesting if the two are
 * genuinely indistinguishable to the predicate.
 */
$page = 'smack-appearance-archive.php';
$key = 'archive_thumb_frame';
$body = (string) @file_get_contents($root . '/' . $page);
kl_fail($body !== '' ? '' : "cannot read {$page}");

if ($body !== '') {
    $real = dc_css_at_save_complaints($body, $key, $page);
    kl_fail($real === [] ? ''
        : "the inventory now complains about {$key} on the UNMODIFIED page, so "
        . 'this file cannot show anything about its limits. Fix that first: '
        . implode(' | ', $real));

    // The substitution Codex named: the preset is chosen by a constant, not
    // by what the owner picked. One character class of change, nothing else.
    $lookup = '$tf = $thumb_frame_presets[$tfp];';
    $constant = "\$tf = \$thumb_frame_presets['none'];";
    if (strpos($body, $lookup) === false) {
        kl_fail("LIMIT 2 CANNOT BE CHECKED: {$page} no longer contains "
            . "\"{$lookup}\", so the substitution this limit describes does not "
            . 'apply to the current page. Re-express the limit against whatever '
            . 'the page does now, or remove it -- do not leave it unverifiable.');
    } else {
        $disconnected = str_replace($lookup, $constant, $body);
        $after = dc_css_at_save_complaints($disconnected, $key, $page);
        kl_fail($after === [] ? ''
            : 'LIMIT 2 NO LONGER HOLDS: the predicate now notices that the '
            . "owner's value has been replaced by a constant. That is a real "
            . 'improvement -- confirm it, then delete this limit from here and '
            . "from the inventory's header. Complaint: " . implode(' | ', $after));
        $notes[] = 'LIMIT 2 holds: replacing the preset lookup with a constant '
            . 'produces no complaint, so the inventory cannot distinguish a '
            . "disconnected value from a connected one.";

        // And the bound on limit 2: removing the generation outright IS still
        // caught. Otherwise "it misses a constant" would be indistinguishable
        // from "it checks nothing at all", and the limit would read as an
        // excuse rather than a boundary.
        $gone = str_replace(
            "        \$upsert_rule(\$blob, '/* arch_opt:grid_border */', \$grid_rule);\n"
            . "        \$upsert_rule(\$blob, '/* arch_opt:masonry_border */', \$masonry_rule);",
            '        /* generation removed */', $body);
        kl_fail($gone !== $body ? '' : 'could not remove the upsert calls to '
            . 'check the bound on limit 2; the page has changed shape.');
        if ($gone !== $body) {
            kl_fail(dc_css_at_save_complaints($gone, $key, $page) !== [] ? ''
                : 'THE PREDICATE IS NOT CHECKING ANYTHING: removing the rule '
                . 'generation outright produces no complaint either. Limit 2 '
                . 'would then be describing a check that does not exist.');
            $notes[] = 'bound on LIMIT 2 holds: removing the generation outright '
                . 'IS still caught, so the predicate checks something real.';
        }
    }
}

/* ── Report ──────────────────────────────────────────────────────────────── */
echo "\nWHAT THE DECLARED-CONTROLS INVENTORY CANNOT SEE\n";
echo str_repeat('-', 70) . "\n";
foreach ($notes as $n) echo "  " . $n . "\n";
echo "\n  Both limits are Codex's, found by reading the predicate on 31 October\n";
echo "  2026. Functional save-and-render verification is a separate job and is\n";
echo "  deliberately NOT attempted here.\n";

if ($failures !== []) {
    echo "\nFAIL known limits (" . count($failures) . ")\n";
    foreach ($failures as $f) echo "- " . $f . "\n";
    exit(1);
}
echo "\nPASS the documented limits are still exactly the limits.\n";

// ===== SNAPSMACK EOF =====
