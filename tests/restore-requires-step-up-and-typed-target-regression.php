<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1430 — the Recovery Kit import is the most destructive action in the
 * product and it had the weakest gate.
 *
 * It overwrites the database and the files and cannot be undone from the
 * page. Before this it required a CSRF token, which every form on that page
 * carries, and a browser confirm() dialog, which is a line of JavaScript any
 * client can decline to run. A scripted POST, or a mis-clicked button on a
 * page left open all afternoon, reached the SQL.
 *
 * Two controls now, BOTH enforced server-side:
 *   1. reauth_verify() -- password plus TOTP, the shared mechanism every
 *      other consequential action uses, so it inherits the SS-1399 attempt
 *      budget rather than needing its own.
 *   2. A typed confirmation matching this install's site_url. Step-up proves
 *      WHO you are; it cannot prove you meant THIS site. An owner with
 *      several installs has the same password and authenticator for all of
 *      them, and restoring one site's kit over another is the accident with
 *      no undo.
 *
 * WHAT THIS FILE CAN AND CANNOT DO. smack-disaster.php is an admin page: it
 * authenticates a session, sends headers and renders. It cannot be included
 * here and driven. So this reads the handler's SOURCE and asserts the shape
 * of the gate, and separately EXECUTES the two decision functions the gate
 * is built from. That is weaker than driving the endpoint and is called out
 * rather than dressed up; a real POST against a running install is the only
 * thing that would close the gap, and that is owner work.
 *
 * Run: php tests/restore-requires-step-up-and-typed-target-regression.php
 */

$root = dirname(__DIR__);
$failures = [];

function check(string $label, bool $ok, array &$failures): void {
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures[] = $label;
    }
}

$src = (string)file_get_contents($root . '/smack-disaster.php');

// The handler only: everything from the import_recovery branch to the end of
// that if block. Asserting against the whole file would let a match in the
// FORM satisfy a claim about the SERVER, which is the exact confusion this
// ticket is about.
$start = strpos($src, "=== 'import_recovery'");
$handler_raw = '';
if ($start !== false) {
    $after = substr($src, $start);
    $end = strpos($after, "\n}\n");
    $handler_raw = $end === false ? $after : substr($after, 0, $end);
}

/**
 * The handler with its comments removed.
 *
 * EVERY code assertion below runs against this, not the raw text. My first
 * version searched the raw handler for 'confirm(' to assert the browser
 * dialog is no longer the only control -- and matched the COMMENT that
 * explains the browser dialog used to be the only control. The check failed
 * on a correct file, because of the sentence documenting the fix.
 *
 * That is the second time today a source assertion of mine could not tell an
 * instruction from a note about an instruction; the first was a build script.
 * So this uses the tokeniser rather than stripping lines that begin with a
 * slash, and it is done ONCE for every check rather than patched at the one
 * that happened to trip.
 */
function handler_code(string $fragment): string {
    if ($fragment === '') return '';
    $out = '';
    foreach (@token_get_all('<?php ' . $fragment) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
            $out .= $t[1];
            continue;
        }
        $out .= $t;
    }
    return $out;
}
$handler = handler_code($handler_raw);

echo "SS-1430 — restore requires step-up and a typed target, server-side\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — the handler was located (nothing below is meaningful otherwise)\n";
// ─────────────────────────────────────────────────────────────────────────────
check('the import_recovery handler was found in the source',
      $handler_raw !== '' && strlen($handler_raw) > 200, $failures);
check('and it is the handler, not the whole file',
      $handler_raw !== '' && strlen($handler_raw) < strlen($src), $failures);
check('the handler does NOT contain the form markup',
      !str_contains($handler_raw, 'type="file"'), $failures);
check('stripping the comments left the code behind, rather than eating it',
      strlen($handler) > 400 && strlen($handler) < strlen($handler_raw), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — both controls are enforced in the handler\n";
// ─────────────────────────────────────────────────────────────────────────────
check('step-up: the handler calls reauth_verify',
      str_contains($handler, 'reauth_verify('), $failures);
check('step-up: it reads the password and TOTP from the POST',
      str_contains($handler, "\$_POST['reauth_password']")
      && str_contains($handler, "\$_POST['reauth_totp']"), $failures);
check('step-up: a failed verification refuses',
      preg_match('/!\$ra\[.ok.\]/', $handler) === 1, $failures);

check('typed target: the handler reads confirm_target from the POST',
      str_contains($handler, "\$_POST['confirm_target']"), $failures);
check('typed target: it is compared with hash_equals, not ==',
      str_contains($handler, 'hash_equals'), $failures);
check('typed target: the comparison is against site_url',
      str_contains($handler, "'site_url'"), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — the destructive call is behind BOTH, and fails closed\n";
// ─────────────────────────────────────────────────────────────────────────────
// The ordering is the control. A gate that refuses after the restore has run
// is not a gate, and this is the one property a reader cannot eyeball.
$import_at   = strpos($handler, 'importRecoveryKit(');
$reauth_at   = strpos($handler, 'reauth_verify(');
$compare_at  = strpos($handler, 'hash_equals');
check('importRecoveryKit is called AFTER reauth_verify',
      $import_at !== false && $reauth_at !== false && $reauth_at < $import_at,
      $failures);
check('importRecoveryKit is called AFTER the typed-target comparison',
      $import_at !== false && $compare_at !== false && $compare_at < $import_at,
      $failures);
check('an empty recorded site address refuses rather than skipping the check',
      preg_match('/\$restore_target === \'\'/', $handler) === 1, $failures);
check('the handler code depends on no browser dialog',
      substr_count($handler, 'confirm(') === 0, $failures);
// This counted the phrase appearing three or more times, which measured
// DUPLICATION. Codex's review made me replace four hand-rolled refusal
// arrays with one constructor, so the phrase now appears once and the old
// check failed on an improvement. What it was reaching for is better
// asserted structurally: the phrase lives in the constructor, and every
// refusal goes through the constructor.
check('the refusal constructor says nothing was restored',
      preg_match('/\$refuse\s*=.*NOTHING WAS RESTORED/s', $handler) === 1, $failures);
check('and every refusal branch goes through it',
      substr_count($handler, '$refuse(') >= 4, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — the comparison itself, executed\n";
// ─────────────────────────────────────────────────────────────────────────────
// The decision the gate turns on is a string comparison. Run it rather than
// trust that hash_equals appearing in the file means it behaves.
$target = 'https://photowalk.ing';
$cases = [
    ['https://photowalk.ing',            true,  'the exact address'],
    ['  https://photowalk.ing  ',        true,  'the address with surrounding spaces'],
    ['https://photowalk.ing/',           false, 'a trailing slash'],
    ['http://photowalk.ing',             false, 'the wrong scheme'],
    ['https://Photowalk.ing',            false, 'different capitalisation'],
    ['https://photowalk.ingx',           false, 'an extra character'],
    ['photowalk.ing',                    false, 'the bare host'],
    ['',                                 false, 'nothing typed'],
    ['https://someshitifound.photoblogs.fyi', false, 'another of the owner sites'],
];
foreach ($cases as [$typed, $want, $label]) {
    $got = hash_equals($target, trim($typed));
    check(($want ? 'accepts ' : 'refuses ') . $label, $got === $want, $failures);
}

// A deliberate note on the trailing slash and the capitalisation: both are
// refused. That is stricter than a URL comparison would be, and it is the
// intent -- the owner is confirming a target by copying what the page shows
// them, so an exact match is the signal. A forgiving comparison would accept
// a near miss, and a near miss is precisely what restoring the wrong site
// looks like.
// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4b — A REFUSAL RENDERS AS A REFUSAL (Codex's review FAIL)\n";
// ─────────────────────────────────────────────────────────────────────────────
// The gap this file did not have, and the reason it did not: I checked that
// the handler REFUSES and never checked what the page then SHOWS. The
// template decides success with empty($import_result['errors']) -- PLURAL,
// because that is importRecoveryKit()'s shape -- and every refusal I wrote
// set 'error' SINGULAR. So each refusal rendered the success block, reading
// "SQL statements: 0, Files restored: 0, Checksums verified: 0". The server
// refused and the owner was told it had worked: SS-1427's fault, produced by
// SS-1430's fix.
//
// MY FIRST REPAIR OF THIS STAGE WAS ALSO WRONG, and Codex caught that too.
// It copied the template's `empty($result['errors'])` condition into a local
// lambda and tested hand-built arrays against the copy. That pins the broken
// condition rather than the fixed behaviour: the renderer could change
// underneath it and the copy would keep passing. One of its checks was worse
// than that -- it asserted the string empty($import_result['errors']) was
// still in the file, and after the repair the only place that string survives
// is the COMMENT explaining what it used to be. A test matching my own
// documentation for the third time this week.
//
// So this stage is now ONE contract check against comment-stripped code, and
// STAGE 4c renders the real template. $handler is already comment-stripped.
$tpl = handler_code(substr($src, strpos($src, '$__ok')));
check('the renderer decides on success === true, not on the errors list',
      str_contains($tpl, "(\$import_result['success'] ?? false) === true"), $failures);
// SS-1427 CHANGED WHAT THIS HAS TO SAY, so it is narrowed rather than dropped.
// The template now reads empty($import_result['errors']) in ONE place: the
// $__partial test, which separates "imported, some files are not here yet"
// from "completed with errors". That is a legitimate use -- it is asking
// whether anything FAILED, not whether the restore succeeded. What must stay
// true is that the errors list does not decide $__ok, so the check now reads
// the $__ok assignment itself instead of the whole template.
$okLine = '';
foreach (explode("
", $tpl) as $line) {
    if (str_contains($line, '$__ok') && str_contains($line, '=')) { $okLine = $line; break; }
}
check('the $__ok assignment was located', $okLine !== '', $failures);
check('and the errors list is not what decides it',
      $okLine !== '' && !str_contains($okLine, "empty(\$import_result['errors'])"), $failures);
check('while the partial branch is allowed to ask whether anything FAILED',
      str_contains($tpl, '$__partial'), $failures);
check('there is ONE error key in the contract: no singular error survives',
      !str_contains($handler, "'error'   =>") && !str_contains($tpl, "['error']"),
      $failures);
check('the exception path refuses too, rather than reporting success',
      str_contains($handler, 'catch (Throwable'), $failures);
// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4c — WHAT THE PAGE ACTUALLY PRINTS (the PM's 'test the output')\n";
// ─────────────────────────────────────────────────────────────────────────────
// STAGE 4b tested the renderer's DECISION by mirroring its condition. That is
// still a shape test: it would not notice the template changing underneath it.
// This extracts the real template block out of smack-disaster.php, runs it
// with a given $import_result, and asserts on the characters the owner sees.
//
// The PM asked for exactly this after the first repair: test the output, not
// the shape. Mirroring a condition is how I missed the bug the first time.
// Slice from the outer `if (isset($import_result)` to its MATCHING endif,
// counted rather than guessed.
//
// TWO FAULTS OF MY OWN ARE PINNED HERE, both the shape the PM named -- a
// guard that passes because its output was never looked at.
//
//   1. My first attempt took the FIRST endif tag after the start, which is an
//      inner one. The fragment was unbalanced, eval() threw a ParseError, the
//      render produced nothing at all -- and every "is NOT printed" assertion
//      passed happily on the empty output. So the render is asserted to have
//      happened, below, before a single content check is trusted.
//   2. The comment explaining fault 1 wrote the closing tag literally. A
//      one-line // comment in PHP ENDS at a close tag, so the comment closed
//      PHP mode and the rest of this file became HTML. php -l caught it, but
//      only because the damage was total; a close tag inside a comment that
//      happened to land between two statements would not have been.
$tpl_start = strpos($src, '<?php if (isset($import_result)');
$template  = '';
if ($tpl_start !== false) {
    // Scan the control tags in order rather than searching for text. `elseif`
    // and `else` do not open a block; `if` does; `endif` closes one.
    preg_match_all('/<\?php\s+(if|endif)\b/', $src, $tags,
                   PREG_OFFSET_CAPTURE | PREG_PATTERN_ORDER, $tpl_start);
    $depth = 0;
    foreach ($tags[1] as $i => $tag) {
        if ($tag[0] === 'if') { $depth++; continue; }
        $depth--;
        if ($depth === 0) {
            $close = strpos($src, '?' . '>', $tags[0][$i][1]);
            if ($close !== false) {
                $template = substr($src, $tpl_start, $close + 2 - $tpl_start);
            }
            break;
        }
    }
}
check('the template block was extracted whole, not truncated at an inner endif',
      $template !== '' && substr_count($template, '<?php if (') === substr_count($template, 'endif;'),
      $failures);

$render = static function (array $result) use ($template): string {
    $import_result = $result;           // the variable the template reads
    ob_start();
    try {
        eval('?>' . $template);
    } catch (Throwable $e) {
        ob_end_clean();
        return 'RENDER THREW: ' . $e->getMessage();
    }
    return (string)ob_get_clean();
};

// Prove the harness renders before trusting a single negative assertion.
$probe = $render(['success' => true, 'refused' => false, 'errors' => [],
                  'sql_imported' => 7]);
check('the harness renders the template without throwing',
      !str_starts_with($probe, 'RENDER THREW'), $failures);
check('and produces output, so a silent empty render cannot pass the checks below',
      trim($probe) !== '', $failures);

// THE BANNERS, NOT THE WORDING. Two mutations survived an earlier version of
// this stage because I asserted on the phrase "NOTHING WAS RESTORED", and
// every refusal MESSAGE begins with those words. So a render that showed the
// wrong banner and printed the message underneath it satisfied the check.
// These three are the banner lines themselves, which only one branch prints.
$SUCCESS = '> RECOVERY KIT IMPORTED SUCCESSFULLY';
$ERRORS  = '> RECOVERY KIT IMPORT COMPLETED WITH ERRORS';
$REFUSED = '> NOTHING WAS RESTORED — THIS SITE IS UNCHANGED';
foreach (['$SUCCESS' => $SUCCESS, '$ERRORS' => $ERRORS, '$REFUSED' => $REFUSED] as $n => $b) {
    check("the {$n} banner line is really in the template, so its absence means something",
          str_contains($template, $b), $failures);
}

// THE HANDLER'S OWN REFUSAL CONSTRUCTOR, not a hand-written copy of its shape.
// Mutation proof: dropping 'refused' from $refuse() in the handler has to fail
// this file, and it cannot if the test builds the array itself.
$refuse_src = '';
$rs = strpos($handler, '$refuse = static function');
if ($rs !== false) {
    $re = strpos($handler, "\n    };", $rs);
    if ($re !== false) $refuse_src = substr($handler, $rs, $re - $rs + 7);
}
check("the handler's own refusal constructor was extracted, so this tests its shape",
      $refuse_src !== '' && str_contains($refuse_src, 'return ['), $failures);
$refuse = null;
if ($refuse_src !== '') { eval($refuse_src); }
if (is_callable($refuse)) {
    $out = $render($refuse('step-up failed'));
    check("the handler's own refusal renders the unchanged-site banner",
          str_contains($out, $REFUSED), $failures);
    check('and not the errors banner, which would say the database was touched',
          !str_contains($out, $ERRORS), $failures);
    check('and not the success banner',
          !str_contains($out, $SUCCESS), $failures);
    // The refusal branch prints the plural errors list and nothing else, so a
    // refusal whose reason lives only under the singular key is a banner with
    // no explanation under it. That is what SS-1427 looked like to the owner.
    check("the handler's own refusal prints WHY, not just the banner",
          str_contains($out, 'step-up failed'), $failures);
}

// THE HANDLER'S OWN ENGINE BOUNDARY. One line in the handler turns
// importRecoveryKit()'s shape into the one key the renderer decides on. If
// that line stops deriving success from the errors list -- hardcoded, or
// reading a key the engine does not set -- a half-run restore reports success
// again, and no amount of testing the renderer alone would notice.
$boundary = '';
foreach (explode("\n", $handler) as $line) {
    if (str_contains($line, "\$import_result['success'] =")) { $boundary = trim($line); break; }
}
check('the engine boundary line was found in the handler',
      $boundary !== '', $failures);
if ($boundary !== '') {
    $apply = static function (array $engine) use ($boundary): array {
        $import_result = $engine;
        eval($boundary);
        $import_result['refused'] = false;
        return $import_result;
    };
    // SS-1427: NO ERRORS IS NO LONGER ENOUGH. Success is completeness now, and
    // the engine says so with 'complete'. A result with no errors that left
    // photographs behind used to render the success banner, which is the whole
    // of SS-1427. This fixture therefore claims completeness, and the case
    // immediately below proves that withholding the claim withholds the
    // banner.
    $out = $render($apply(['errors' => [], 'complete' => true, 'sql_imported' => 412,
                           'files_restored' => 88, 'checksum_ok' => 88]));
    check('a COMPLETE engine result renders as success through the real boundary',
          str_contains($out, $SUCCESS), $failures);
    $out = $render($apply(['errors' => [], 'complete' => false, 'sql_imported' => 412,
                           'files_restored' => 0, 'missing' => 400,
                           'remaining' => ['400 file(s) are not on disk']]));
    check('an INCOMPLETE result with no errors does NOT render as success (SS-1427)',
          !str_contains($out, $SUCCESS), $failures);
    check('and does not render as a refusal either, because the database WAS restored',
          !str_contains($out, $REFUSED), $failures);
    $out = $render($apply(['errors' => ['SQL: table snap_images failed'],
                           'sql_imported' => 40, 'files_restored' => 0]));
    check('an engine result WITH errors does not render as success',
          !str_contains($out, $SUCCESS), $failures);
    check('and renders the errors banner, naming the failure',
          str_contains($out, $ERRORS) && str_contains($out, 'snap_images'), $failures);
}

// Every refusal the handler can produce, rendered for real.
$refusals = [
    'wrong password or TOTP'   => 'NOTHING WAS RESTORED — step-up failed',
    'no site_url recorded'     => 'NOTHING WAS RESTORED — no address recorded',
    'typed target mismatch'    => 'NOTHING WAS RESTORED — confirmation did not match',
    'no kit uploaded'          => 'NOTHING WAS RESTORED — no recovery kit was uploaded.',
    'an exception mid-import'  => 'NOTHING WAS RESTORED — the import failed: boom',
];
foreach ($refusals as $label => $message) {
    $out = $render(['success' => false, 'refused' => true,
                    'errors' => [$message]]);
    check("refusal ({$label}): the success banner is NOT printed",
          !str_contains($out, $SUCCESS), $failures);
    check("refusal ({$label}): the unchanged-site banner is printed",
          str_contains($out, $REFUSED), $failures);
    check("refusal ({$label}): the errors banner is NOT printed -- nothing ran",
          !str_contains($out, $ERRORS), $failures);
    check("refusal ({$label}): the reason is printed",
          str_contains($out, htmlspecialchars($message)) || str_contains($out, $message),
          $failures);
}

// A real import that worked.
$out = $render(['success' => true, 'refused' => false, 'errors' => [],
                'sql_imported' => 412, 'files_restored' => 88, 'checksum_ok' => 88]);
check('a clean import DOES print the success banner',
      str_contains($out, $SUCCESS), $failures);
check('and prints its counts', str_contains($out, '412'), $failures);
check('and does not claim nothing was restored',
      !str_contains($out, $REFUSED), $failures);
check('and does not also print the errors banner',
      !str_contains($out, $ERRORS), $failures);

// A real import that half-ran: neither a refusal nor a success.
$out = $render(['success' => false, 'refused' => false,
                'errors' => ['SQL: table snap_images failed'],
                'sql_imported' => 40, 'files_restored' => 0]);
check('a half-done import prints the errors banner, not the success one',
      str_contains($out, $ERRORS) && !str_contains($out, $SUCCESS), $failures);
check('and does NOT tell the owner the site is unchanged, because it is not',
      !str_contains($out, $REFUSED), $failures);
check('and names the failing statement', str_contains($out, 'snap_images'), $failures);

// The fail-closed property, which is the whole point of deciding on success.
$out = $render(['sql_imported' => 0]);          // a shape nobody planned for
check('an unrecognised result shape does NOT print the success banner',
      !str_contains($out, $SUCCESS), $failures);
$out = $render(['success' => 'yes', 'errors' => []]);   // truthy but not true
check('a truthy-but-not-true success does NOT print the success banner',
      !str_contains($out, $SUCCESS), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — the form offers the controls (reachability, not enforcement)\n";
// ─────────────────────────────────────────────────────────────────────────────
foreach (['confirm_target', 'reauth_password', 'reauth_totp'] as $field) {
    check("the form posts {$field}",
          preg_match('/name="' . preg_quote($field, '/') . '"/', $src) === 1, $failures);
}
check('the typed field shows the owner which address to type',
      str_contains($src, '$restore_target_display'), $failures);
check('the display value is read outside the POST handler, so a GET renders it',
      substr_count($src, "'site_url'") >= 2, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1430 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}
echo "SS-1430 PASS — the restore requires password, TOTP and the site's own\n";
echo "address typed out, all enforced server-side and all before the SQL runs.\n";
// ===== SNAPSMACK EOF =====
