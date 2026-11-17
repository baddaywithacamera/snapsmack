<?php
/**
 * No column definition in the canonical schema may carry two COMMENT clauses.
 *
 * I broke this, so it is worth saying how. Adding `gallery_shuffle` to
 * snap_mosaics, I inserted it after the `layout` line — but `layout`'s
 * definition CONTINUED on the next line with its COMMENT. The new column went
 * in between. The result:
 *
 *     `layout` VARCHAR(12) … DEFAULT 'asymmetric',        <- comment gone
 *     `gallery_shuffle` TINYINT(1) NOT NULL DEFAULT 0
 *            COMMENT 'SS-1185: …'                          <- mine
 *            COMMENT 'Which engine renders the block: …',  <- layout's, orphaned
 *
 * Two COMMENT clauses on one column. MySQL rejects that, so the canonical
 * schema would not execute — on a fresh install, nothing would be created
 * from the statement at all. `layout` also silently lost its documentation.
 *
 * Nothing caught it. There is no MySQL client on this machine, so no test in
 * this repository executes the canonical schema; every schema test reads it as
 * text. That is a real gap and this test does not close it — it closes the one
 * class of fault that gap let through, which is the class a text reader CAN
 * see: a column whose clauses are duplicated because a neighbour was spliced
 * into the middle of it.
 *
 * It is the same underlying mistake as several others I made the same day:
 * acting on a line instead of on the structure the line belongs to. A
 * multi-line column definition looks like two lines and is one.
 *
 * Checked across EVERY table, not just the one I broke, because the next
 * person to insert a column will be inserting it somewhere else.
 *
 * Static SQL-text assertions — no DB, no site.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root  = dirname(__DIR__);
$fails = 0;

function csc(string $label, bool $ok): void {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fails++;
}

$path   = $root . '/database/schema/snapsmack_canonical.sql';
$schema = (string) @file_get_contents($path);
csc('the canonical schema is readable', $schema !== '');
if ($schema === '') {
    fwrite(STDERR, "\nCanonical schema missing — cannot check.\n");
    exit(1);
}

// Strip -- and /* */ comments so a commented-out column cannot be scored.
$clean = (string) preg_replace('#/\*.*?\*/#s', ' ', $schema);
$clean = (string) preg_replace('/^\s*--.*$/m', ' ', $clean);

/**
 * Split a CREATE TABLE body into its top-level, comma-separated definitions.
 * Commas inside quoted strings and inside parentheses — ENUM lists, key
 * column lists, DECIMAL(10,2) — are not separators. Splitting on a bare comma
 * is exactly how a checker of this kind goes wrong, so it is done properly.
 */
function csc_split_defs(string $body): array
{
    $defs = [];
    $buf = '';
    $depth = 0;
    $quote = '';
    $len = strlen($body);
    for ($i = 0; $i < $len; $i++) {
        $ch = $body[$i];
        if ($quote !== '') {
            $buf .= $ch;
            if ($ch === '\\' && $i + 1 < $len) { $buf .= $body[++$i]; continue; }
            // A doubled quote inside a quoted string is an escaped quote.
            if ($ch === $quote) {
                if ($i + 1 < $len && $body[$i + 1] === $quote) { $buf .= $body[++$i]; continue; }
                $quote = '';
            }
            continue;
        }
        if ($ch === "'" || $ch === '"') { $quote = $ch; $buf .= $ch; continue; }
        if ($ch === '(') { $depth++; $buf .= $ch; continue; }
        if ($ch === ')') { $depth--; $buf .= $ch; continue; }
        if ($ch === ',' && $depth === 0) { $defs[] = trim($buf); $buf = ''; continue; }
        $buf .= $ch;
    }
    if (trim($buf) !== '') $defs[] = trim($buf);
    return array_values(array_filter($defs, static fn($d) => $d !== ''));
}

/** Count COMMENT clauses in a definition, ignoring the word inside a string. */
function csc_count_comments(string $def): int
{
    // Blank out quoted strings first so COMMENT 'a comment about COMMENT' is 1.
    $masked = (string) preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/", "''", $def);
    return preg_match_all('/\bCOMMENT\b/i', $masked);
}

// ── Walk every CREATE TABLE ─────────────────────────────────────────────────
preg_match_all(
    '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?\s*\((.*?)\)\s*ENGINE=/si',
    $clean, $tables, PREG_SET_ORDER
);

csc('CREATE TABLE statements were found', $tables !== []);
if ($tables === []) {
    fwrite(STDERR, "\nNo CREATE TABLE parsed — the regex or the file shape changed.\n");
    exit(1);
}

$doubled   = [];
$columns   = 0;
$commented = 0;
foreach ($tables as $t) {
    [, $table, $body] = $t;
    foreach (csc_split_defs($body) as $def) {
        // Only column definitions: they open with a backticked or bare name,
        // and are not PRIMARY KEY / UNIQUE KEY / KEY / INDEX / CONSTRAINT.
        if (preg_match('/^(?:PRIMARY|UNIQUE|FULLTEXT|SPATIAL|FOREIGN|KEY|INDEX|CONSTRAINT|CHECK)\b/i', $def)) {
            continue;
        }
        if (!preg_match('/^`?([A-Za-z0-9_]+)`?\s+\S/', $def, $m)) continue;
        $columns++;
        $n = csc_count_comments($def);
        if ($n > 0) $commented++;
        if ($n > 1) $doubled[] = $table . '.' . $m[1] . ' has ' . $n . ' COMMENT clauses';
    }
}

echo '     parsed ' . count($tables) . ' tables, ' . $columns . ' columns, '
    . $commented . " carrying a COMMENT\n";

csc('the parse found a realistic number of columns', $columns > 100);
csc('no column carries more than one COMMENT clause', $doubled === []);
foreach ($doubled as $d) {
    fwrite(STDERR, '  ' . $d . "\n");
}

// ── The two columns this was found on, named so the pair cannot drift ──────
// Asserted specifically because the generic check above passes if BOTH
// comments are deleted, and a column losing its documentation silently is the
// other half of what went wrong here.
if (preg_match('/CREATE\s+TABLE[^(]*`snap_mosaics`\s*\((.*?)\)\s*ENGINE=/si', $clean, $mm)) {
    $mosaic_defs = [];
    foreach (csc_split_defs($mm[1]) as $def) {
        if (preg_match('/^`?([A-Za-z0-9_]+)`?\s+\S/', $def, $m)) {
            $mosaic_defs[$m[1]] = $def;
        }
    }
    csc('snap_mosaics still has a layout column', isset($mosaic_defs['layout']));
    csc('snap_mosaics still has a gallery_shuffle column', isset($mosaic_defs['gallery_shuffle']));

    csc(
        'layout kept its own COMMENT',
        isset($mosaic_defs['layout'])
            && csc_count_comments($mosaic_defs['layout']) === 1
            && stripos($mosaic_defs['layout'], 'Which engine renders the block') !== false
    );
    csc(
        'gallery_shuffle carries exactly one COMMENT, its own',
        isset($mosaic_defs['gallery_shuffle'])
            && csc_count_comments($mosaic_defs['gallery_shuffle']) === 1
            && stripos($mosaic_defs['gallery_shuffle'], 'SS-1185') !== false
    );
    csc(
        'gallery_shuffle did not inherit the engine comment',
        isset($mosaic_defs['gallery_shuffle'])
            && stripos($mosaic_defs['gallery_shuffle'], 'Which engine renders the block') === false
    );
    csc(
        'gallery_shuffle still defaults to 0, so an import keeps authored order',
        isset($mosaic_defs['gallery_shuffle'])
            && (bool) preg_match('/\bNOT\s+NULL\s+DEFAULT\s+0\b/i', $mosaic_defs['gallery_shuffle'])
    );
} else {
    csc('the snap_mosaics table was found', false);
}

// ── The calculator, checked against known answers ──────────────────────────
$known = [
    "`a` INT COMMENT 'one'"                               => 1,
    "`a` INT COMMENT 'one' COMMENT 'two'"                 => 2,
    "`a` INT COMMENT 'a COMMENT about COMMENT'"           => 1,
    "`a` INT NOT NULL"                                    => 0,
    "`a` VARCHAR(5) DEFAULT 'it''s' COMMENT 'x'"          => 1,
];
foreach ($known as $def => $want) {
    csc('counts ' . $want . ' in: ' . $def, csc_count_comments($def) === $want);
}
// And the splitter must not be fooled by a comma inside parentheses or quotes.
$split = csc_split_defs("`a` DECIMAL(10,2) NOT NULL,\n`b` ENUM('x','y') DEFAULT 'x',\nPRIMARY KEY (`a`,`b`)");
csc('the splitter ignores commas inside () and quotes', count($split) === 3);

if ($fails) {
    fwrite(STDERR, "\n{$fails} FAILURE(S): a column definition in the canonical schema is "
        . "malformed. MySQL will reject the CREATE TABLE, so a fresh install creates "
        . "nothing from it. This is what happens when a new column is inserted into "
        . "the middle of a multi-line definition instead of after it.\n");
    exit(1);
}
echo "Canonical schema column syntax: PASS\n";
exit(0);

// ===== SNAPSMACK EOF =====
