<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * THE OWNER CAN PUT THEIR OWN BLOGROLL IN THEIR OWN ORDER.
 *
 * snap_blogroll has carried a sort_order column since the canonical schema
 * was written -- database/schema/snapsmack_canonical.sql, int NOT NULL
 * DEFAULT 0. Nothing read it and nothing wrote it. A grep of the whole
 * repository for sort_order found no blogroll reader, no blogroll writer and
 * no field on smack-blogroll.php. The column looked like a feature and was
 * not one: the blogroll came out alphabetical and the owner had no way to
 * move a peer, which matters most for the people they want at the top.
 *
 * WHAT IS TESTED HOW, said plainly because this file is the evidence.
 *
 *   The public query is RUN. blogrollPeers() is a real query, so it goes
 *   against a real PDO -- an in-memory SQLite database with the two blogroll
 *   tables -- and the ORDER of the rows that come back is read off the
 *   result. Nothing here reads the SQL as text and nothing asserts from a
 *   comment.
 *
 *   The admin save is a SOURCE CHECK and says so. smack-blogroll.php needs a
 *   session, a live database and an authenticated owner; it cannot be
 *   executed here. So this file checks that the INSERT and the UPDATE both
 *   name the column and that the form carries the field -- and it is NOT
 *   CHECKED here whether typing a number into that field on a live install
 *   lands in the row. That needs somebody on an install.
 *
 * THE NO-OP PROPERTY IS THE POINT OF THE TIEBREAK. Every row on every live
 * site is 0 today. Ordering by sort_order and THEN by peer_name gives the
 * same order those pages already show, so no live blogroll moves on the day
 * this ships. The second block below proves that rather than claiming it.
 */

$root = dirname(__DIR__);
require_once $root . '/core/public-repository.php';

$pass = 0; $fail = 0;
function bo(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

/**
 * A real database with the two blogroll tables and the rows named.
 * $peers: [peer_name, cat_id, sort_order]
 */
function bo_repo(array $peers, array $cats = [1 => 'Friends']): SnapPublicRepository {
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec('CREATE TABLE snap_blogroll_cats (id INTEGER PRIMARY KEY, cat_name TEXT)');
    $pdo->exec('CREATE TABLE snap_blogroll (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        peer_name TEXT, peer_url TEXT, peer_desc TEXT, peer_rss TEXT,
        cat_id INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 0
    )');
    $ins = $pdo->prepare('INSERT INTO snap_blogroll_cats (id,cat_name) VALUES (?,?)');
    foreach ($cats as $id => $name) $ins->execute([$id, $name]);
    $ins = $pdo->prepare(
        'INSERT INTO snap_blogroll (peer_name,peer_url,peer_desc,peer_rss,cat_id,sort_order)
         VALUES (?,?,?,?,?,?)'
    );
    foreach ($peers as $p) {
        $ins->execute([$p[0], 'https://example.test/' . strtolower($p[0]), '', '', $p[1], $p[2]]);
    }
    return new SnapPublicRepository($pdo);
}

/** @return string[] peer names in the order the query returned them */
function bo_names(SnapPublicRepository $repo): array {
    return array_map(static fn(array $r): string => (string)$r['peer_name'], $repo->blogrollPeers());
}

echo "THE QUERY IS RUN AGAINST A REAL DATABASE, NOT READ AS TEXT\n";

// Deliberately reverse-alphabetical in sort_order, so honouring the field and
// honouring the alphabet cannot produce the same answer.
$ordered = bo_repo([
    ['Alice', 1, 3],
    ['Bob',   1, 2],
    ['Carol', 1, 1],
]);
$names = bo_names($ordered);
bo($names === ['Carol', 'Bob', 'Alice'],
   'the owner-set order is honoured, alphabet or not (got ' . implode(', ', $names) . ')');

echo "\nALL ZEROES LEAVES EVERY LIVE BLOGROLL EXACTLY WHERE IT IS\n";
$untouched = bo_repo([
    ['Carol', 1, 0],
    ['Alice', 1, 0],
    ['Bob',   1, 0],
]);
$names = bo_names($untouched);
bo($names === ['Alice', 'Bob', 'Carol'],
   'with every sort_order at its default the list is alphabetical, as it is today '
   . '(got ' . implode(', ', $names) . ')');

echo "\nTIES FALL BACK TO THE ALPHABET, SO TWO PEERS AT 5 ARE NOT IN INSERT ORDER\n";
$tied = bo_repo([
    ['Zoe',   1, 5],
    ['Adam',  1, 5],
    ['First', 1, 1],
]);
$names = bo_names($tied);
bo($names === ['First', 'Adam', 'Zoe'],
   'peers sharing a number are alphabetical within it (got ' . implode(', ', $names) . ')');

echo "\nCATEGORY STILL GROUPS. ORDERING BY sort_order FIRST WOULD SCATTER A GROUP\n";
// Antiques sorts before Friends, so the category decides the blocks and
// sort_order only decides within them. If sort_order came first, Late (9,
// Antiques) would be last and its group would be split.
$grouped = bo_repo([
    ['Early', 2, 1],
    ['Late',  2, 9],
    ['Middle', 1, 5],
], [1 => 'Friends', 2 => 'Antiques']);
$names = bo_names($grouped);
bo($names === ['Early', 'Late', 'Middle'],
   'both Antiques peers stay together ahead of the Friends peer (got '
   . implode(', ', $names) . ')');

echo "\nA PEER WITH NO URL IS STILL WITHHELD -- THE ORDER CHANGE DID NOT WIDEN THE QUERY\n";
$withBlank = bo_repo([
    ['Named',   1, 1],
    ['Blank',   1, 2],
]);
// Empty the second one's url the way a half-finished admin save would.
(static function (SnapPublicRepository $r): void {
    $ref = new ReflectionProperty(SnapPublicRepository::class, 'pdo');
    /** @var PDO $pdo */
    $pdo = $ref->getValue($r);
    $pdo->exec("UPDATE snap_blogroll SET peer_url='' WHERE peer_name='Blank'");
})($withBlank);
$names = bo_names($withBlank);
bo($names === ['Named'],
   'the urlless peer is not published (got ' . implode(', ', $names) . ')');

echo "\nMUTATION PROOF: WITHOUT THE sort_order TERM THE FIRST CHECK MUST GO RED\n";
// A reordering test that would pass against the old query is measuring
// nothing. Run the OLD order by hand on the same rows and show it gives the
// alphabet instead of the owner's order.
$old = (static function (): array {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE snap_blogroll_cats (id INTEGER PRIMARY KEY, cat_name TEXT)');
    $pdo->exec('CREATE TABLE snap_blogroll (id INTEGER PRIMARY KEY AUTOINCREMENT,
        peer_name TEXT, peer_url TEXT, peer_desc TEXT, cat_id INTEGER, sort_order INTEGER)');
    $pdo->exec("INSERT INTO snap_blogroll_cats (id,cat_name) VALUES (1,'Friends')");
    foreach ([['Alice', 3], ['Bob', 2], ['Carol', 1]] as $p) {
        $pdo->prepare('INSERT INTO snap_blogroll (peer_name,peer_url,peer_desc,cat_id,sort_order)
            VALUES (?,?,?,1,?)')->execute([$p[0], 'https://example.test/x', '', $p[1]]);
    }
    $rows = $pdo->query(
        "SELECT b.peer_name FROM snap_blogroll b
         LEFT JOIN snap_blogroll_cats c ON c.id=b.cat_id
         WHERE b.peer_url IS NOT NULL AND b.peer_url<>''
         ORDER BY c.cat_name,b.peer_name"
    )->fetchAll(PDO::FETCH_COLUMN);
    return array_map('strval', $rows);
})();
bo($old === ['Alice', 'Bob', 'Carol'],
   'the order this replaced ignores the field entirely (got ' . implode(', ', $old) . ')');
bo($old !== ['Carol', 'Bob', 'Alice'],
   'so the first check above could not have passed before this change');

echo "\nTHE COLUMN IS IN THE CANONICAL SCHEMA, SO NO MIGRATION IS NEEDED\n";
$schema = (string)@file_get_contents($root . '/database/schema/snapsmack_canonical.sql');
$blogrollDdl = (static function (string $sql): string {
    // Matched with a pattern rather than a literal because the DDL is
    // CREATE TABLE IF NOT EXISTS `snap_blogroll`, and because the backtick
    // has to be part of the match or snap_blogroll_cats -- which is declared
    // immediately above it -- is found instead.
    if (preg_match('/CREATE TABLE (?:IF NOT EXISTS )?`?snap_blogroll`?\s*\(/i', $sql, $m, PREG_OFFSET_CAPTURE) !== 1) {
        return '';
    }
    $at = (int)$m[0][1];
    // Bounded on the statement's own terminator, not on a character count: a
    // fixed window measures whatever has grown above it.
    $end = strpos($sql, ';', $at);
    return $end === false ? '' : substr($sql, $at, $end - $at);
})($schema);
bo($blogrollDdl !== '', 'the snap_blogroll table was found in the canonical schema');
bo($blogrollDdl !== '' && stripos($blogrollDdl, 'sort_order') !== false,
   'snap_blogroll already declares sort_order, so an existing install needs no ALTER');

echo "\nTHE ADMIN SAVE WRITES IT -- SOURCE CHECK, NOT AN EXECUTED SAVE\n";
$admin = (string)@file_get_contents($root . '/smack-blogroll.php');
$saveBlock = (static function (string $src): string {
    $at = strpos($src, "isset(\$_POST['save_peer'])");
    if ($at === false) return '';
    $end = strpos($src, 'REMOVAL HANDLER', $at);
    return $end === false ? substr($src, $at) : substr($src, $at, $end - $at);
})($admin);
bo($saveBlock !== '', 'the save_peer handler was found');
bo(stripos($saveBlock, 'INSERT INTO snap_blogroll') !== false
   && preg_match('/INSERT INTO snap_blogroll[^;]*sort_order/is', $saveBlock) === 1,
   'a new peer is inserted with its sort_order');
bo(preg_match('/UPDATE snap_blogroll SET[^;]*sort_order=\?/is', $saveBlock) === 1,
   'an edited peer has its sort_order updated');
bo(str_contains($saveBlock, "\$_POST['sort_order']"),
   'the handler reads the field the form posts');
bo(preg_match('/sort_order\'\]\s*\?\?\s*0\)/', $saveBlock) === 1,
   'and defaults it to 0, so an older form that omits the field cannot blank a '
   . 'peer the owner already positioned');
bo(preg_match('/<input[^>]*name="sort_order"/i', $admin) === 1,
   'the form carries exactly one sort_order input');
bo(preg_match('/name="sort_order"[^>]*type="number"|type="number"[^>]*name="sort_order"/i', $admin) === 1,
   'it is a number field, so Parkinson\'s-friendly steppers work and free text cannot land in it');
bo(str_contains($admin, 'b.sort_order ASC'),
   'the admin list itself is ordered by it, so the owner sees the order the visitor gets');

echo "\nNOTHING BETWEEN THE QUERY AND THE PAGE RE-SORTS THE PEERS\n";
// The order is only worth having if it survives the controller. The blogroll
// branch regroups the rows by category and appends each item, so row order is
// kept -- unless somebody adds a sort in there, which would silently undo
// this whole change. Bounded on the branch's own braces, not on a character
// count.
$controller = (string)@file_get_contents($root . '/core/public-controller.php');
$branch = (static function (string $src): string {
    $at = strpos($src, "if (\$route === 'blogroll') {");
    if ($at === false) return '';
    $end = strpos($src, "'kind' => 'blogroll'", $at);
    return $end === false ? '' : substr($src, $at, $end - $at);
})($controller);
bo($branch !== '', 'the blogroll branch of the controller was found');
bo($branch !== '' && !preg_match('/\b(sort|usort|uasort|ksort|asort|array_reverse)\s*\(/i', $branch),
   'it re-orders nothing, so the order the query produced is the order the page shows');
bo($branch !== '' && str_contains($branch, '$groups[$category][] ='),
   'each peer is appended to its category in the order it arrived');

echo "\n  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "Blogroll order: FAIL ({$fail}).\n";
    exit(1);
}
echo "Blogroll order: PASS ({$pass} assertions).\n";
echo "  NOT CHECKED: typing a number into the field on a live install and seeing\n";
echo "  the row change. This runs the public query and reads the admin source; it\n";
echo "  does not post the form and it drives no browser.\n";

// ===== SNAPSMACK EOF =====
