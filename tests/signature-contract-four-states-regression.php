<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Four states, and none of them collapses into another. (SS-1621, queue item 6)
 *
 * THE CONTRACT. Each signature field means one of four things:
 *   omitted  preserve what is stored
 *   null     inherit the blog default
 *   0        explicit opt-out, no signature on this post
 *   an id    that image
 *
 * THE BUG THIS PINS SHUT. The old reader in core/smackpress-api.php was
 * !empty($body[...]) ? (int)$body[...] : null, so a client sending 0 got null.
 * Harmless while null meant "no signature"; the exact opposite of the author's
 * intent the moment null means "inherit". Two of four states were one state and
 * nothing in the suite could see it, because the rules lived inside a file that
 * authenticates at the top level and so can never be required by a test. They
 * live in core/signature-contract.php now.
 *
 * WHAT THIS DRIVES. The real functions, and then the SQL they generate, run
 * against a real database. A null that comes back as 0 after a round trip is
 * the whole fault, so it is checked through storage rather than in memory.
 *
 * SQLITE, NOT MYSQL. The generated SQL is plain INSERT/UPDATE with
 * placeholders and runs unchanged. What this cannot prove is the MySQL column
 * definition, so the canonical schema is checked as source and labelled as
 * such.
 *
 * Run: php -d extension=pdo_sqlite tests/signature-contract-four-states-regression.php
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIP: no pdo_sqlite. Re-run with -d extension=pdo_sqlite\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/core/signature-contract.php';

$fail = 0;
$ran  = 0;
function check(bool $ok, string $what): void {
    global $fail, $ran;
    $ran++;
    if ($ok) { echo "  ok    $what\n"; return; }
    $fail++; echo "  FAIL  $what\n";
}

// A refusal that records instead of exiting, so a refused value can be checked
// rather than taking the process down.
$refusals = [];
$refuse = static function (string $message) use (&$refusals): void {
    $refusals[] = $message;
};

echo "The signature contract: four states, and none collapses into another\n";

// ---------------------------------------------------------------------------
// THE FOUR STATES, one field at a time.
// ---------------------------------------------------------------------------
$omitted = snap_signature_field([], 'signature_light_image_id', $refuse);
check($omitted['sent'] === false, 'an OMITTED field is not sent, so it preserves');

$inherit = snap_signature_field(['signature_light_image_id' => null], 'signature_light_image_id', $refuse);
check($inherit['sent'] === true && $inherit['value'] === null, 'null is INHERIT');

foreach ([0, '0', '', false] as $spelling) {
    $out = snap_signature_field(['signature_light_image_id' => $spelling],
        'signature_light_image_id', $refuse);
    check($out['sent'] === true && $out['value'] === 0,
        var_export($spelling, true) . ' is an explicit OPT-OUT, value 0');
}

foreach ([7 => 7, '7' => 7, 1234 => 1234] as $spelling => $want) {
    $out = snap_signature_field(['signature_light_image_id' => $spelling],
        'signature_light_image_id', $refuse);
    check($out['sent'] === true && $out['value'] === $want,
        var_export($spelling, true) . ' selects image ' . $want);
}

// INHERIT AND OPT-OUT ARE NOT THE SAME VALUE. This is the whole point.
check($inherit['value'] !== snap_signature_field(['signature_light_image_id' => 0],
    'signature_light_image_id', $refuse)['value'],
    'INHERIT and OPT-OUT are different values, which is the entire fault');
check($inherit['value'] === null, 'inherit is null, not 0');

// ---------------------------------------------------------------------------
// THE OLD BUG, ON THE LEGACY FIELD. !empty(0) is true, so the old reader
// turned a deliberate opt-out into null.
// ---------------------------------------------------------------------------
$legacy_zero = snap_signature_field(['signature_image_id' => 0], 'signature_image_id', $refuse);
check($legacy_zero['value'] === 0,
    'THE LEGACY FIELD KEEPS A SENT 0 -- the old reader turned it into null');
check($legacy_zero['value'] !== null, 'and it is not null');

// ---------------------------------------------------------------------------
// VALUES THAT ARE NONE OF THE FOUR STATES ARE REFUSED, NOT GUESSED AT.
// ---------------------------------------------------------------------------
$refusals = [];
snap_signature_field(['signature_dark_image_id' => -3], 'signature_dark_image_id', $refuse);
check(count($refusals) === 1, 'a negative id is REFUSED');
check(str_contains($refusals[0] ?? '', 'signature_dark_image_id'),
    'and the refusal names the field: ' . ($refusals[0] ?? '(none)'));

$refusals = [];
snap_signature_field(['signature_dark_image_id' => 'latest'], 'signature_dark_image_id', $refuse);
check(count($refusals) === 1, 'a non-numeric value is REFUSED, not read as an id');

$refusals = [];
snap_signature_field(['signature_dark_image_id' => ['id' => 7]], 'signature_dark_image_id', $refuse);
check(count($refusals) === 1, 'an array is REFUSED');

$refusals = [];
snap_signature_read([
    'signature_image_id'       => 7,
    'signature_light_image_id' => 7,
    'signature_dark_image_id'  => null,
], $refuse);
check($refusals === [], 'a well-formed body is refused nothing');

// ---------------------------------------------------------------------------
// ONLY REAL IDS GO FOR VERIFICATION. An omitted, inherited or opted-out field
// has no id to look up, and checking 0 against the image table would refuse a
// deliberate opt-out as a missing image.
// ---------------------------------------------------------------------------
$fields = snap_signature_read([
    'signature_image_id'       => 7,
    'signature_light_image_id' => 0,
    'signature_dark_image_id'  => null,
], $refuse);
$to_verify = snap_signature_ids_to_verify($fields);
check($to_verify === ['signature_image_id' => 7],
    'only the one real id is verified: ' . json_encode($to_verify));

// ---------------------------------------------------------------------------
// THE GENERATED SQL, RUN AGAINST A DATABASE. In memory a null and a 0 are easy
// to tell apart; through storage is where they stop being.
// ---------------------------------------------------------------------------
function sig_db(): PDO {
    $pdo = (class_exists('Pdo\Sqlite'))
        ? Pdo\Sqlite::connect('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])
        : new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE snap_posts (
        id INTEGER PRIMARY KEY,
        title TEXT NOT NULL DEFAULT '',
        signature_image_id INTEGER DEFAULT NULL,
        signature_light_image_id INTEGER DEFAULT NULL,
        signature_dark_image_id INTEGER DEFAULT NULL)");
    return $pdo;
}

/** Insert one post through the contract's own generated SQL. */
function sig_insert(PDO $pdo, array $body, callable $refuse): int {
    $fields = snap_signature_read($body, $refuse);
    $parts  = snap_signature_write_sql($fields, true);
    $sql    = "INSERT INTO snap_posts (title" . $parts['sql']
            . ") VALUES(?" . $parts['marks'] . ")";
    $params = array_merge(['a post'], $parts['params']);
    $pdo->prepare($sql)->execute($params);
    return (int)$pdo->lastInsertId();
}

/** Update one post through the contract's own generated SQL. */
function sig_update(PDO $pdo, int $id, array $body, callable $refuse): void {
    $fields = snap_signature_read($body, $refuse);
    $parts  = snap_signature_write_sql($fields, false);
    $sql    = "UPDATE snap_posts SET title=?" . $parts['sql'] . " WHERE id=?";
    $params = array_merge(['edited'], $parts['params'], [$id]);
    $pdo->prepare($sql)->execute($params);
}

function sig_row(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare("SELECT * FROM snap_posts WHERE id=?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

$pdo = sig_db();

// INSERT: an id, an inherit and an omission, all in one post.
$id = sig_insert($pdo, [
    'signature_light_image_id' => 42,
    'signature_dark_image_id'  => null,
], $refuse);
$row = sig_row($pdo, $id);
check((int)$row['signature_light_image_id'] === 42, 'an id is stored as that id');
check($row['signature_dark_image_id'] === null, 'an INHERIT is stored as NULL');

// INSERT with an explicit opt-out: 0 must reach the column as 0, not NULL.
$id = sig_insert($pdo, ['signature_light_image_id' => 0], $refuse);
$row = sig_row($pdo, $id);
check($row['signature_light_image_id'] !== null, 'an OPT-OUT does not become NULL');
check((int)$row['signature_light_image_id'] === 0, 'it reaches the column as 0');
check($row['signature_dark_image_id'] === null,
    'and the field that was never sent takes the column default');

// UPDATE: an omitted field PRESERVES. This is the re-pull case -- a client
// fixing a post's text must not clear a signature somebody chose.
$id = sig_insert($pdo, ['signature_light_image_id' => 9, 'signature_dark_image_id' => 11], $refuse);
sig_update($pdo, $id, ['title' => 'just the text'], $refuse);
$row = sig_row($pdo, $id);
check((int)$row['signature_light_image_id'] === 9 && (int)$row['signature_dark_image_id'] === 11,
    'A RE-PULL THAT SENDS NEITHER FIELD CLEARS NEITHER');

// UPDATE one field, leave the other: exactly one changes.
sig_update($pdo, $id, ['signature_dark_image_id' => 0], $refuse);
$row = sig_row($pdo, $id);
check((int)$row['signature_light_image_id'] === 9, 'the field not sent is untouched');
check((int)$row['signature_dark_image_id'] === 0, 'the field sent as 0 opts out');

// UPDATE back to inherit: 0 to NULL, deliberately.
sig_update($pdo, $id, ['signature_dark_image_id' => null], $refuse);
check(sig_row($pdo, $id)['signature_dark_image_id'] === null,
    'an opt-out can be changed back to inherit');

// Nothing sent at all means no assignment and no placeholder, so a caller
// cannot accidentally write a column it never mentioned.
$empty = snap_signature_write_sql(snap_signature_read([], $refuse), false);
check($empty['sql'] === '' && $empty['params'] === [],
    'a body with no signature fields generates no SQL at all');
$empty_insert = snap_signature_write_sql(snap_signature_read([], $refuse), true);
check($empty_insert['sql'] === '' && $empty_insert['marks'] === '',
    'and no columns or placeholders on an insert');

// The legacy field is NOT one of the two variant columns: it is written by the
// caller's own existing SQL, and this must never start writing it.
$legacy_only = snap_signature_write_sql(
    snap_signature_read(['signature_image_id' => 5], $refuse), false);
check($legacy_only['sql'] === '',
    'the LEGACY field generates no variant SQL -- its own write path still owns it');

// ---------------------------------------------------------------------------
// WHAT GOES BACK TO THE CLIENT. (int)null is 0, which would hand back an
// opt-out where the post holds an inherit, with no way for the client to tell.
// ---------------------------------------------------------------------------
$out = snap_signature_response([
    'signature_light_image_id' => null,
    'signature_dark_image_id'  => '0',
]);
check($out['signature_light_image_id'] === null, 'a stored NULL goes back as null, NOT 0');
check($out['signature_dark_image_id'] === 0, 'a stored 0 goes back as the integer 0');

$out = snap_signature_response(['signature_light_image_id' => '42']);
check($out['signature_light_image_id'] === 42, 'a stored id goes back as an integer');
check(array_key_exists('signature_dark_image_id', $out)
    && $out['signature_dark_image_id'] === null,
    'a column missing from the row reads as inherit, not as 0');

// A client that reads a post and sends it straight back must get the same four
// states out as it would put in. Driven, not asserted.
foreach ([null, 0, 42] as $stored) {
    $returned = snap_signature_response(['signature_light_image_id' => $stored]);
    $reread   = snap_signature_field($returned, 'signature_light_image_id', $refuse);
    check($reread['sent'] === true && $reread['value'] === $stored,
        'ROUND TRIP: ' . var_export($stored, true) . ' survives read-then-send');
}

// ---------------------------------------------------------------------------
// INHERIT RESOLVES TO NOTHING, AND SAYS SO. There is no per-blog signature
// anywhere in the product, so a resolver would be a control that does nothing.
// ---------------------------------------------------------------------------
check(snap_signature_inherit_is_resolvable() === false,
    'inherit is reported as NOT resolvable while no blog default exists');
// NO TREE GREP HERE. The first version searched the repository for a
// blog-level signature setting and failed, because the search terms are IN
// THIS FILE -- the same self-matching mistake three times over now. That the
// product has no per-blog signature was measured by hand across the tree and
// is recorded in the contract file's header; it is not something this check
// can honestly establish about itself.

// ---------------------------------------------------------------------------
// ONE COPY OF THE RULES. The API must not hold its own.
// ---------------------------------------------------------------------------
$api    = (string)file_get_contents($root . '/core/smackpress-api.php');
$schema = (string)file_get_contents($root . '/database/schema/snapsmack_canonical.sql');

check(str_contains($api, "require_once __DIR__ . '/signature-contract.php'"),
    'the API requires the contract');

// COMMENTS ARE NOT CODE. The first version of the next check failed because
// the API explains the old reader in a comment, quoting it verbatim, and a
// substring search cannot tell the explanation from the thing. Line comments
// are stripped before looking, so the record of the bug can stay in the file
// where the next reader will find it.
$api_code = preg_replace('~^\s*(//|\*|/\*).*$~m', '', $api) ?? $api;
$old_reader = '!empty($body[' . "'signature_image_id'" . '])';
check(!str_contains($api_code, $old_reader),
    'THE OLD COLLAPSING READER IS GONE from the API code');
check(str_contains($api, $old_reader),
    'and it is still quoted in a comment, so the bug stays on the record');
check(substr_count($api_code, 'function snap_signature_field') === 0,
    'the API holds no second copy of the reader');
check(substr_count($api_code, "'signature_light_image_id'") === 0,
    'and does not name a variant field as a literal outside the contract');

// The MySQL column definition is source, not behaviour, and is labelled so.
foreach (SNAP_SIGNATURE_VARIANT_FIELDS as $name) {
    check(str_contains($schema, '`' . $name . '` int unsigned DEFAULT NULL'),
        "SOURCE ONLY: canonical schema declares {$name} nullable");
}
check(str_contains($schema, 'NULL keeps its original meaning here'),
    'SOURCE ONLY: the legacy column records that NULL still means no signature');

echo "\n$ran checks, " . ($fail === 0 ? "all passed\n" : "$fail FAILED\n");
exit($fail === 0 ? 0 : 1);

// ===== SNAPSMACK EOF =====
