<?php
declare(strict_types=1);
/**
 * The predicates behind the declared-controls candidate-reference inventory.
 *
 * SPLIT OUT SO THE INVENTORY'S KNOWN LIMITS CAN BE ASSERTED. Codex's review of
 * 31 October 2026 showed that these predicates are broader than the claim the
 * inventory was making, and the coordinator's ruling was to make the limits
 * visible rather than to build deeper analysis. A limit cannot be asserted
 * while it is buried inside a script that exits, so the predicates live here
 * and both the inventory and its known-limits file use them.
 *
 * tests/declared-controls-have-a-consumer-regression.php   the inventory
 * tests/declared-controls-gate-known-limits.php            what it cannot see
 *
 * # SNAPSMACK_EOF_HEADER
 * # Last non-empty line must be: // ===== SNAPSMACK EOF =====
 */

/* ── Is this key actually READ here, or merely mentioned? ─────────────────
 *
 * CODEX'S REVIEW, 31 October 2026, and it is the second time this gate has
 * been too generous. It decided "consumed" with strpos($body, $key), which
 * matches a COMMENT, a DECLARATION and a WRITE exactly as readily as a read.
 * So a comment saying "we used to read archive_thumb_frame here" marked the
 * control WIRED, and the gate would have reported a dead control as live --
 * the same class of mistake as the search space being too small, which is
 * recorded as instance nine on SS-1114. The predicate was too loose rather
 * than the corpus too narrow, but the output is the same: a confident wrong
 * answer in front of Sean.
 *
 * Comments are stripped WITH THE STRING LITERALS LEFT ALONE, because '//' and
 * '/*' occur inside ordinary strings here -- URLs, CSS selectors, regexes --
 * and a naive strip would silently delete half a file and turn real reads
 * into no reads. This walks the text instead of using a regex.
 */
function dc_strip_comments(string $body, string $ext): string
{
    $out = '';
    $n = strlen($body);
    $i = 0;
    $quote = '';
    while ($i < $n) {
        $c = $body[$i];
        $next = $i + 1 < $n ? $body[$i + 1] : '';
        if ($quote !== '') {
            // Inside a string: copy through, honouring backslash escapes so a
            // quote that is escaped does not end the literal.
            if ($c === '\\' && $next !== '') { $out .= '  '; $i += 2; continue; }
            if ($c === $quote) $quote = '';
            $out .= $c;
            $i++;
            continue;
        }
        if ($c === '"' || $c === "'") { $quote = $c; $out .= $c; $i++; continue; }
        if ($c === '/' && $next === '*') {
            $end = strpos($body, '*/', $i + 2);
            $end = $end === false ? $n : $end + 2;
            $out .= str_repeat(' ', $end - $i);
            $i = $end;
            continue;
        }
        $lineComment = ($c === '/' && $next === '/')
            || ($ext === 'php' && $c === '#');
        if ($lineComment) {
            $end = strpos($body, "\n", $i);
            $end = $end === false ? $n : $end;
            $out .= str_repeat(' ', $end - $i);
            $i = $end;
            continue;
        }
        $out .= $c;
        $i++;
    }
    return $out;
}

/* Does the stripped body READ this key, rather than declare or write it?
 *
 * Rejected, each for a stated reason:
 *   name="settings[key]"      the DECLARATION. The admin page offering the
 *                             control is not evidence that anything uses it;
 *                             this is the whole point of the gate.
 *   $settings['key'] = ...    a WRITE. Storing a value proves nothing reads
 *                             it. "= ..." only, never "==" or "=>".
 * Everything else counts. A defaults table entry ('key' => 850) is kept
 * deliberately: it is how several real consumers reach their value, and
 * excluding it would manufacture the false DEAD verdicts this gate exists to
 * avoid. */
function dc_reads_key(string $stripped, string $key): bool
{
    $len = strlen($key);
    $offset = 0;
    while (($at = strpos($stripped, $key, $offset)) !== false) {
        $offset = $at + $len;
        $before = substr($stripped, max(0, $at - 20), min(20, $at));
        $after = substr($stripped, $at + $len, 12);
        if (strpos($before, 'name="settings[') !== false) continue;
        // A write: the subscript closes and is assigned to.
        if (preg_match('/^[\x27"]\s*\]\s*=[^=>]/', $after) === 1) continue;
        return true;
    }
    return false;
}


/* Does this page really turn THIS KEY into stored CSS? Returns a list of
 * complaints; an empty list means the chain held.
 *
 * CODEX'S SECOND FINDING, 31 October 2026. The first version asked two
 * INDEPENDENT questions -- does the key appear anywhere in the file, and does
 * custom_css_public appear anywhere in the file -- and called a pair of yeses
 * a proof. It is not one. Delete the whole archive_thumb_frame rule-building
 * block and both answers stay yes, because the key is still in the page's own
 * <select name="settings[archive_thumb_frame]"> and custom_css_public is still
 * written for every other generated rule.
 *
 * So the facts are tied together: the key must be read out of the submitted
 * form, the variable it lands in must be used again, and a rule upsert must
 * occur, ALL between that read and the custom_css_public write.
 *
 * AND THIS IS STILL NOT A PROOF THAT THE VALUE REACHES THE PAGE, which is
 * Codex's third reading of 31 October and it is correct. Replace the preset
 * lookup with a constant and the key is still read, the variable is still
 * used by the validation, and the upserts are still there -- so this returns
 * no complaint while the owner's choice no longer affects the CSS. That limit
 * is deliberate, it is NOT closed here, and it is asserted as a known limit in
 * tests/declared-controls-gate-known-limits.php so it cannot be forgotten.
 */
function dc_note(array &$out, $complaint): void
{
    if (is_string($complaint) && $complaint !== '') $out[] = $complaint;
}

function dc_css_at_save_complaints(string $body, string $key, string $file): array
{
    $out = [];
    if ($body === '') {
        dc_note($out, "\$generatesCss names {$file}, which cannot be read");
        return $out;
    }
    $clean = dc_strip_comments($body, strtolower(pathinfo($file, PATHINFO_EXTENSION)));

    // Where the key's VALUE is taken from the submitted form.
    if (preg_match('/\$(\w+)\s*=\s*\$_POST\s*\[\s*[\x27"]settings[\x27"]\s*\]'
        . '\s*\[\s*[\x27"]' . preg_quote($key, '/') . '[\x27"]\s*\]/', $clean, $km,
        PREG_OFFSET_CAPTURE) !== 1) {
        dc_note($out, "\$generatesCss claims {$key} is turned into CSS in {$file}, and "
            . "{$file} never takes that key's value out of \$_POST['settings']. "
            . 'Either the generation is gone or the claim was never true. A '
            . 'mention of the key somewhere in the file is not evidence: the '
            . 'page also DECLARES the control, so that mention is always there.');
        return $out;
    }
    $var = $km[1][0];
    $readAt = $km[0][1];

    $storeAt = strpos($clean, 'custom_css_public', $readAt);
    if ($storeAt === false) {
        dc_note($out, "\$generatesCss claims {$key} writes generated CSS in {$file}, and "
            . 'nothing writes custom_css_public after the key is read. The '
            . 'generated rule is not stored.');
        return $out;
    }
    $region = substr($clean, $readAt, $storeAt - $readAt);

    // The value has to be USED between being read and being stored, and it
    // has to reach an actual rule upsert. Either half alone proves nothing.
    dc_note($out, substr_count($region, '$' . $var) >= 2 ? ''
        : "\$generatesCss claims {$key} is turned into CSS in {$file}: the key is "
        . "read into \${$var} and \${$var} is then never used again before "
        . 'custom_css_public is written. The value goes nowhere.');
    dc_note($out, strpos($region, 'upsert_rule') !== false ? ''
        : "\$generatesCss claims {$key} is turned into CSS in {$file}, and between "
        . 'reading the key and storing custom_css_public there is no rule '
        . 'upsert at all. Something else on that page writes the CSS blob; '
        . "this key's rule is not being generated.");

    return $out;
}


// ===== SNAPSMACK EOF =====
