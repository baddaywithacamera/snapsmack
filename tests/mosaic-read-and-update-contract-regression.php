<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * The mosaic read, update and reference rules, EXECUTED.
 *
 * WHY IT CAN BE EXECUTED AT ALL. These rules used to live inside
 * core/smackpress-api.php, which authenticates at the top level, so no test
 * could require them: including that file runs the API. They now live in
 * core/mosaic-contract.php and refuse through an INJECTED callable, so this
 * file drives the real validator and the real reader rather than searching the
 * source for strings. Everything below calls the shipped function.
 *
 * WHAT IT GUARDS. SS-1612: COLD SNAP could create a mosaic on a site and could
 * not ask a site what one of its mosaics was, so Open published painted a bar
 * reading "Site mosaic 753" where the photographs should be. The read and the
 * update are what close that, and three of the rules here exist because getting
 * them wrong destroys the owner's work rather than failing visibly:
 *
 *   the AUTHORED ORDER is stored exactly as sent, and the desktop cannot write
 *     the shuffle flag, so a shuffled VIEW can never become the stored order
 *   FOCUS and EMPHASIS survive a copy, because copy-on-edit for a shared
 *     mosaic makes a new row from an existing definition and a create that
 *     dropped them would quietly lose every per-photograph focus point
 *   a reference count uses the RENDERER'S OWN PATTERN, so what this calls a
 *     reference and what the page actually shows cannot disagree
 *
 * Run: php tests/mosaic-read-and-update-contract-regression.php
 */

$root = dirname(__DIR__);
require_once $root . '/core/mosaic-contract.php';

$checks = 0;
$fails  = [];
$check = static function (string $what, bool $ok) use (&$checks, &$fails): void {
    $checks++;
    if (!$ok) { $fails[] = $what; }
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
};

// A refusal collector, so a refusal is DATA rather than the end of the run.
// THE REFUSAL TERMINATES, BECAUSE IN PRODUCTION IT DOES. The API passes
// smackpress_error(), which sets the status and exits, so no line after a
// refusal ever runs. A collector that merely recorded would let the validator
// carry on into states the live route cannot reach -- and it did: the first
// version of this file drew two PHP warnings out of the contract that the API
// itself can never emit, because there the function had already exited.
// Throwing models the real control flow; the catch turns it back into data.
class MosaicRefused extends RuntimeException
{
    public int $status;
    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}
$refusals = [];
$refuse = static function (int $code, string $message) use (&$refusals): void {
    $refusals[] = ['code' => $code, 'message' => $message];
    throw new MosaicRefused($code, $message);
};
$try = static function (callable $fn) {
    try { return $fn(); } catch (MosaicRefused $e) { return null; }
};
$lastRefusal = static function () use (&$refusals): ?array {
    return $refusals ? $refusals[count($refusals) - 1] : null;
};

// ---------------------------------------------------------------------------
// A real schema. SELECT * and prepared statements behave the same on sqlite,
// and the point is to drive the shipped SQL rather than a stub of it.
// ---------------------------------------------------------------------------
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE snap_mosaics (
    id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL DEFAULT "",
    asset_ids TEXT NOT NULL DEFAULT "[]", focus_positions TEXT, mosaic_weights TEXT,
    mosaic_mirrors TEXT, gap INTEGER NOT NULL DEFAULT 4,
    emphasis TEXT NOT NULL DEFAULT "natural", layout TEXT NOT NULL DEFAULT "asymmetric",
    gallery_shuffle INTEGER NOT NULL DEFAULT 0,
    created_at TEXT DEFAULT "2026-11-27 10:00:00", updated_at TEXT DEFAULT "2026-11-27 10:00:00")');
$pdo->exec('CREATE TABLE snap_posts (id INTEGER PRIMARY KEY, content TEXT)');
$pdo->exec('CREATE TABLE snap_pages (id INTEGER PRIMARY KEY, content TEXT)');

$pdo->exec('INSERT INTO snap_mosaics (id, title, asset_ids, focus_positions, mosaic_weights,
    mosaic_mirrors, gap, emphasis, layout, gallery_shuffle, updated_at) VALUES
    (753, "Start your engines", "[41,36,902]", \'{"41":{"x":25,"y":75}}\', \'{"41":2}\',
     "[]", 6, "portrait", "asymmetric", 0, "2026-11-27 10:00:00")');

// ---------------------------------------------------------------------------
// THE READ
// ---------------------------------------------------------------------------
echo "\n-- the read --\n";
$m = smackpress_mosaic_read($pdo, 753);
$check('a mosaic reads back at all', is_array($m));
$check('the authored order is returned exactly as stored',
    ($m['asset_ids'] ?? null) === [41, 36, 902]);
$check('the gap comes back as an int', ($m['gap'] ?? null) === 6);
$check('emphasis survives the read', ($m['emphasis'] ?? null) === 'portrait');
$check('focus positions come back as keyed x and y, not strings',
    ($m['focus_positions'][41]['x'] ?? null) == 25 && ($m['focus_positions'][41]['y'] ?? null) == 75);
$check('weights come back keyed by image id', ($m['mosaic_weights'][41] ?? null) === 2);
$check('an unknown id reads as nothing at all', smackpress_mosaic_read($pdo, 99999) === null);

// DEGRADATION. A site that has not run its schema sync has no presentation
// columns, and core/parser.php renders anyway rather than erroring. The read
// answers the same way: the fields that exist are returned, the rest absent.
$bare = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$bare->exec('CREATE TABLE snap_mosaics (id INTEGER PRIMARY KEY, title TEXT, asset_ids TEXT, gap INTEGER)');
$bare->exec('INSERT INTO snap_mosaics (id, title, asset_ids, gap) VALUES (7, "Old", "[1,2]", 10)');
$old = smackpress_mosaic_read($bare, 7);
$check('an un-migrated site still reads its mosaic rather than erroring', is_array($old));
$check('and returns the columns it HAS', ($old['asset_ids'] ?? null) === [1, 2] && ($old['gap'] ?? null) === 10);
$check('while OMITTING the ones it does not have, rather than inventing them',
    !array_key_exists('emphasis', $old) && !array_key_exists('focus_positions', $old)
    && !array_key_exists('layout', $old));

// ---------------------------------------------------------------------------
// THE CONFLICT TOKEN
// ---------------------------------------------------------------------------
echo "\n-- the conflict token --\n";
$row = ['id' => 753, 'updated_at' => '2026-11-27 10:00:00', 'asset_ids' => '[41,36,902]'];
$rev = smackpress_mosaic_revision($row);
$check('the same row gives the same token twice', smackpress_mosaic_revision($row) === $rev);
$check('a later edit time gives a different token',
    smackpress_mosaic_revision(['id' => 753, 'updated_at' => '2026-11-27 10:00:01', 'asset_ids' => '[41,36,902]']) !== $rev);
$check('a reordered mosaic gives a different token even at the same second',
    smackpress_mosaic_revision(['id' => 753, 'updated_at' => '2026-11-27 10:00:00', 'asset_ids' => '[36,41,902]']) !== $rev);
$check('a different mosaic gives a different token',
    smackpress_mosaic_revision(['id' => 754, 'updated_at' => '2026-11-27 10:00:00', 'asset_ids' => '[41,36,902]']) !== $rev);

// ---------------------------------------------------------------------------
// REFERENCES, with the renderer's own pattern
// ---------------------------------------------------------------------------
echo "\n-- references --\n";
$pdo->exec('INSERT INTO snap_posts (id, content) VALUES
    (1, "Before [mosaic:753] after"),
    (2, "A wider one [mosaic:753 width=640] here"),
    (3, "A different gallery [mosaic:7530] entirely"),
    (4, "No mosaic at all")');
$pdo->exec('INSERT INTO snap_pages (id, content) VALUES (9, "On a static page [mosaic:753]")');
$refs = smackpress_mosaic_references($pdo, 753);
$check('a plain shortcode counts', in_array(1, $refs['posts'], true));
$check('a shortcode with a width counts', in_array(2, $refs['posts'], true));
$check('A LONGER NUMBER CONTAINING IT DOES NOT COUNT: 7530 is not 753',
    !in_array(3, $refs['posts'], true));
$check('a post with no mosaic does not count', !in_array(4, $refs['posts'], true));
$check('a STATIC PAGE counts as a reference', $refs['pages'] === [9]);
$check('so this mosaic is used in three places, two posts and one page',
    count($refs['posts']) === 2 && count($refs['pages']) === 1);
$check('a mosaic nobody references counts nothing',
    smackpress_mosaic_references($pdo, 41) === ['posts' => [], 'pages' => []]);

// ---------------------------------------------------------------------------
// THE VALIDATOR: what it ACCEPTS
// ---------------------------------------------------------------------------
echo "\n-- the validator, accepting --\n";
$refusals = [];
$ok = smackpress_mosaic_validate([
    'title' => '  Ray  ', 'asset_ids' => [41, 36, 902], 'gap' => 6, 'layout' => 'columns',
    'emphasis' => 'PORTRAIT', 'mosaic_weights' => ['41' => 2, '36' => -1],
    'focus_positions' => ['41' => ['x' => 150, 'y' => -20]],
], $pdo, $refuse);
$check('a good definition is not refused', $refusals === []);
$check('the title is trimmed', ($ok['title'] ?? null) === 'Ray');
$check('the authored order is kept exactly', ($ok['asset_ids'] ?? null) === [41, 36, 902]);
$check('a JSON object key arrives as a string and is accepted as an id',
    ($ok['weights'][41] ?? null) === 2 && ($ok['weights'][36] ?? null) === -1);
$check('emphasis is accepted case-insensitively', ($ok['emphasis'] ?? null) === 'portrait');
$check('A FOCUS POINT IS CLAMPED TO WHAT THE RENDERER WILL USE, never stored out of range',
    ($ok['focus'][41]['x'] ?? null) === 100.0 && ($ok['focus'][41]['y'] ?? null) === 0.0);
$check('an unknown layout falls back to asymmetric rather than refusing',
    (smackpress_mosaic_validate(['asset_ids' => [1], 'layout' => 'spiral'], $pdo, $refuse)['layout'] ?? null) === 'asymmetric');
$check('the gap is clamped rather than refused',
    (smackpress_mosaic_validate(['asset_ids' => [1], 'gap' => 999], $pdo, $refuse)['gap'] ?? null) === 20
    && (smackpress_mosaic_validate(['asset_ids' => [1], 'gap' => -5], $pdo, $refuse)['gap'] ?? null) === 0);

// MIRRORS, which must be a LIST of contiguous runs of the authored order.
$refusals = [];
$mir = smackpress_mosaic_validate([
    'asset_ids' => [41, 36, 902], 'mosaic_weights' => ['41' => 2],
    'mosaic_mirrors' => [[41, 36]],
], $pdo, $refuse);
$check('a mirror over a contiguous run is accepted', $refusals === [] && $mir['mirrors'] === [[41, 36]]);
$refusals = [];
$cleared = smackpress_mosaic_validate(['asset_ids' => [1, 2], 'mosaic_mirrors' => []], $pdo, $refuse);
$check('an empty list CLEARS the mirrors rather than refusing',
    $refusals === [] && $cleared['mirrors'] === []);

// ---------------------------------------------------------------------------
// THE VALIDATOR: what it REFUSES. Each one executed, each status checked.
// ---------------------------------------------------------------------------
echo "\n-- the validator, refusing --\n";
$cases = [
    ['no members at all',                        ['asset_ids' => []], 422],
    ['a weight that is not -1, 1 or 2',          ['asset_ids' => [1], 'mosaic_weights' => ['1' => 3]], 422],
    ['a weight naming an image not in the mosaic', ['asset_ids' => [1], 'mosaic_weights' => ['99' => 2]], 422],
    ['weights that are not a map at all',        ['asset_ids' => [1], 'mosaic_weights' => 'heavy'], 422],
    ['an emphasis that is not one of the five',  ['asset_ids' => [1], 'emphasis' => 'dramatic'], 422],
    ['a focus position with no y',               ['asset_ids' => [1], 'focus_positions' => ['1' => ['x' => 10]]], 422],
    ['a focus position for an image not in the mosaic', ['asset_ids' => [1], 'focus_positions' => ['99' => ['x' => 1, 'y' => 2]]], 422],
    ['mirrors while the gallery is shuffled',    ['asset_ids' => [1, 2], 'gallery_shuffle' => 1, 'mosaic_mirrors' => [[1, 2]]], 422],
    ['mirrors in a columns layout with no weights', ['asset_ids' => [1, 2], 'layout' => 'columns', 'mosaic_mirrors' => [[1, 2]]], 422],
    ['a mirror of only one photograph',          ['asset_ids' => [1, 2], 'mosaic_weights' => ['1' => 2], 'mosaic_mirrors' => [[1]]], 422],
    ['a mirror that is not contiguous',          ['asset_ids' => [1, 2, 3], 'mosaic_weights' => ['1' => 2], 'mosaic_mirrors' => [[1, 3]]], 422],
    ['two mirrors sharing a photograph',         ['asset_ids' => [1, 2, 3], 'mosaic_weights' => ['1' => 2], 'mosaic_mirrors' => [[1, 2], [2, 3]]], 422],
    ['mirrors sent as a map instead of a list',  ['asset_ids' => [1, 2], 'mosaic_weights' => ['1' => 2], 'mosaic_mirrors' => ['a' => [1, 2]]], 422],
];
foreach ($cases as [$label, $body, $expect]) {
    $refusals = [];
    $try(static fn() => smackpress_mosaic_validate($body, $pdo, $refuse));
    $got = $lastRefusal();
    $check('REFUSED, ' . $label, $got !== null && $got['code'] === $expect);
}

// AND THE SCHEMA REFUSAL, which is a 409 and not a 422, because the owner has
// not done anything wrong -- the site cannot store what was sent.
$refusals = [];
$try(static fn() => smackpress_mosaic_validate(['asset_ids' => [1], 'emphasis' => 'portrait'], $bare, $refuse));
$got = $lastRefusal();
$check('REFUSED 409 when the site has no column for the framing',
    $got !== null && $got['code'] === 409);

// ---------------------------------------------------------------------------
// THE TWO RULES THAT PROTECT THE OWNER'S WORK
// ---------------------------------------------------------------------------
echo "\n-- the rules that protect the arrangement --\n";
$refusals = [];
$sh = smackpress_mosaic_validate(['asset_ids' => [41, 36, 902], 'gallery_shuffle' => 1], $pdo, $refuse);
$check('shuffling NEVER rewrites the stored order', $sh['asset_ids'] === [41, 36, 902]);
$api = (string) @file_get_contents($root . '/core/smackpress-api.php');
// SOURCE CHECKS, DECLARED AS SUCH, AND MADE OVER THE WHOLE FILE ON PURPOSE.
// My first version of these carved the update route out with a non-greedy
// regex and silently swallowed the create route with it, so "it never inserts"
// read an INSERT belonging to a different route and failed. A claim about the
// whole file cannot capture the wrong block, and it is a stronger claim: there
// is exactly ONE statement in this API that inserts a mosaic and exactly one
// that updates one.
$check('core/smackpress-api.php is readable', $api !== '');
$check('SOURCE: exactly one statement in the API inserts a mosaic',
    substr_count($api, 'INSERT INTO snap_mosaics') === 1);
$check('SOURCE: exactly one updates a mosaic, and only ever by id',
    substr_count($api, 'UPDATE snap_mosaics SET') === 1
    && str_contains($api, "WHERE id = ?'"));
$check('SOURCE: no statement anywhere sets gallery_shuffle on an update',
    !str_contains($api, 'gallery_shuffle = ?'));

echo "\n";
if ($fails) {
    echo 'FAILED (' . count($fails) . " of {$checks}):\n";
    foreach ($fails as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL PASS: the mosaic read, update and reference rules hold ({$checks} checks)\n";

// NOT CHECKED HERE: the HTTP layer. These are the rules, driven directly; the
// routes that wrap them add auth, the revision comparison and the sharing
// re-count, and those need a live site with a key. Codex's desktop run against
// baddaywithacamera is what proves the wire, and this proves the rules it
// speaks. Nor is MySQL exercised -- sqlite runs the same statements, and the
// one place that matters is the JSON columns, which are TEXT in both.

// ===== SNAPSMACK EOF =====
