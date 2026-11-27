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
check(snap_signature_inherit_is_resolvable([]) === false,
    'inherit is reported as NOT resolvable while no blog default exists');
// NO TREE GREP HERE. The first version searched the repository for a
// blog-level signature setting and failed, because the search terms are IN
// THIS FILE -- the same self-matching mistake three times over now. That the
// product has no per-blog signature was measured by hand across the tree and
// is recorded in the contract file's header; it is not something this check
// can honestly establish about itself.

// ---------------------------------------------------------------------------
// THE BLOG DEFAULT, so "inherit" can resolve to something. Until this existed,
// an author choosing "use this blog's signature" got nothing and no
// explanation -- the dead-control fault this whole contract came out of.
// ---------------------------------------------------------------------------
check(snap_signature_inherit_is_resolvable([]) === false,
    'with NO settings at all, inherit cannot resolve');
check(snap_signature_inherit_is_resolvable([
        'signature_default_light_image_id' => '',
        'signature_default_dark_image_id'  => '',
    ]) === false,
    'A BLANK DEFAULT STAYS BLANK: both pickers empty is still not resolvable');
check(snap_signature_inherit_is_resolvable(
        ['signature_default_light_image_id' => '413']) === true,
    'one default set makes inherit resolvable');
check(snap_signature_default_for(['signature_default_light_image_id' => '413'], 'light') === 413,
    'and the default reads back as that id');
check(snap_signature_default_for([], 'light') === 0, 'an unset default is 0');
check(snap_signature_default_for(['signature_default_light_image_id' => 'latest'], 'light') === 0,
    'a non-numeric default is 0, not a guess');
check(snap_signature_default_for(['signature_default_light_image_id' => '-5'], 'light') === 0,
    'a negative default is 0');
check(snap_signature_default_for(['signature_default_light_image_id' => '413'], 'sepia') === 0,
    'an unknown surface has no default');

// The clamp on the way IN. Blank is a real choice and must survive as blank.
foreach ([['413', '413'], [413, '413'], ['', ''], ['  ', ''], ['0', ''], [0, ''],
          ['-5', ''], ['latest', ''], ['12abc', ''], [null, '']] as [$posted, $want]) {
    check(snap_signature_default_sanitise($posted) === $want,
        'clamp ' . var_export($posted, true) . ' -> ' . var_export($want, true));
}

// ---------------------------------------------------------------------------
// RESOLUTION. The four states, turned into the one image a post shows.
// ---------------------------------------------------------------------------
$blog = ['signature_default_light_image_id' => '413',
         'signature_default_dark_image_id'  => '414'];

check(snap_signature_resolve(['signature_light_image_id' => 99], $blog, 'light') === 99,
    'an id on the post wins over the blog default');
check(snap_signature_resolve(['signature_light_image_id' => 0], $blog, 'light') === 0,
    'AN OPT-OUT BEATS THE BLOG DEFAULT -- the author said no signature');
check(snap_signature_resolve(['signature_light_image_id' => null], $blog, 'light') === 413,
    'INHERIT resolves to the blog default');
check(snap_signature_resolve(['signature_dark_image_id' => null], $blog, 'dark') === 414,
    'and each surface resolves to its own default');
check(snap_signature_resolve(['signature_light_image_id' => null], [], 'light') === 0,
    'inherit with no blog default is no signature, exactly as before');

// THE LEGACY FALLBACK MUST NOT INHERIT. This is the whole of finding 2: every
// post written before the two columns holds NULL in the legacy field, and that
// has always meant "no signature". Reading it as inherit would give every post
// ever written a signature the moment an owner set a default.
check(snap_signature_resolve(['signature_image_id' => null], $blog, 'light') === 0,
    'A ROW WITH ONLY THE LEGACY FIELD DOES NOT INHERIT, even with a default set');
check(snap_signature_resolve(['signature_image_id' => 77], $blog, 'light') === 77,
    'and a legacy id still renders');
check(snap_signature_resolve(['signature_image_id' => 0], $blog, 'light') === 0,
    'a legacy 0 is no signature');
check(snap_signature_resolve([], $blog, 'light') === 0,
    'a row with no signature columns at all shows nothing');
// An un-synced install: the columns are absent from the row, so the legacy
// meaning applies even though the blog has a default.
check(snap_signature_resolve(
        ['signature_image_id' => null, 'colophon' => 'x'], $blog, 'light') === 0,
    'an install without the two columns keeps today behaviour');

// ---------------------------------------------------------------------------
// A POST THAT IS NO LONGER PUBLIC SHOWS NO SIGNATURE. (Muse, on 21401a73.)
//
// The controller re-reads the two surface columns with a published-only
// clause. When that returns no row the post is not publicly visible any more,
// and the controller sets BOTH surfaces to an explicit opt-out before
// resolving. The first version left the keys absent instead, which sent the
// resolver down the legacy branch, so a post unpublished between the two reads
// still rendered its legacy signature -- while the comment above it claimed it
// rendered nothing.
//
// This drives the resolver with the two row shapes the controller builds, so
// the claim is held by a check rather than by a comment that was wrong.
$withLegacy = ['signature_image_id' => 77];

check(snap_signature_resolve($withLegacy, $blog, 'light') === 77,
    'a visible post with only a legacy signature still renders it');
check(snap_signature_resolve(
        array_merge($withLegacy, ['signature_light_image_id' => 0,
                                  'signature_dark_image_id'  => 0]),
        $blog, 'light') === 0,
    'THE NO-ROW SHAPE RENDERS NOTHING, even though the legacy field is set');
check(snap_signature_resolve(
        array_merge($withLegacy, ['signature_light_image_id' => 0,
                                  'signature_dark_image_id'  => 0]),
        $blog, 'dark') === 0,
    'and nothing on the dark surface either');
// The shape that was wrong: keys absent, legacy field honoured. Kept as the
// statement of what the controller must NOT build on a no-row result.
check(snap_signature_resolve($withLegacy, $blog, 'light') !== 0,
    'leaving the keys absent would have rendered the legacy signature -- which is why it is not done');

// The controller's two no-row branches are different facts and must not be
// collapsed: a query that FAILS means the columns are not there yet, and that
// install keeps today's behaviour.
check(snap_signature_resolve($withLegacy, $blog, 'light') === 77,
    'an un-synced install still renders its legacy signature, which is today behaviour');

$controller_src = (string)file_get_contents($root . '/core/smacktalk-public-controller.php');
check(str_contains($controller_src, "'signature_light_image_id' => 0,")
    && str_contains($controller_src, "'signature_dark_image_id'  => 0,"),
    'the controller sets both surfaces to an opt-out on a no-row result');
check(str_contains($controller_src, "status='published' AND created_at <= NOW()"),
    'and its re-read carries the publication clause');

// ---------------------------------------------------------------------------
// WHICH SURFACE THE SKIN GETS. (PM ruling 2026-11-27, Gemini's v2 contract.)
// The skin manifest declares it; light is the default. A manifest is
// hand-edited JSON, so anything that is not one of the two valid values must
// come back as light rather than reaching the resolver as a surface name.
// ---------------------------------------------------------------------------
check(snap_signature_surface([]) === 'light',
    'a manifest that declares nothing gets LIGHT');
check(snap_signature_surface(['signature_surface' => 'dark']) === 'dark',
    'a manifest declaring dark gets DARK');
check(snap_signature_surface(['signature_surface' => 'light']) === 'light',
    'and declaring light gets light');
check(snap_signature_surface(['signature_surface' => ' DARK ']) === 'dark',
    'case and surrounding space are tolerated, because this is hand-edited JSON');
foreach ([['signature_surface' => 'sepia'], ['signature_surface' => ''],
          ['signature_surface' => true], ['signature_surface' => 1],
          ['signature_surface' => ['dark']], ['signature_surface' => null]] as $bad) {
    check(snap_signature_surface($bad) === 'light',
        'a nonsense declaration falls back to light: '
        . json_encode($bad['signature_surface']));
}
check(SNAP_SIGNATURE_SURFACE_DEFAULT === 'light',
    'the default is named once and it is light');

// AND IT RAISES NOTHING WHILE DOING IT. The is_string() guard looked
// unobservable: casting instead of rejecting gives the same ANSWER for every
// input above, because "1", "" and "Array" are all invalid surface names and
// all fall back to light. The difference is that casting an ARRAY emits a PHP
// notice -- on a reader's page, in the middle of rendering a post. That is the
// observable consequence, so that is what is checked.
$raised = [];
set_error_handler(static function (int $no, string $msg) use (&$raised): bool {
    $raised[] = $msg;
    return true;
});
$surface_from_array = snap_signature_surface(['signature_surface' => ['dark']]);
restore_error_handler();
check($surface_from_array === 'light', 'an array declaration still resolves to light');
check($raised === [],
    'AND RAISES NO PHP NOTICE doing it: ' . ($raised === [] ? 'silent' : $raised[0]));

// The two valid names are the SAME two the default settings use. If those ever
// drifted apart, a skin could declare a surface that has no blog default and
// no column behind it.
check(array_keys(SNAP_SIGNATURE_DEFAULT_KEYS) === ['light', 'dark'],
    'the surfaces and the blog-default keys are the same two names');
foreach (array_keys(SNAP_SIGNATURE_DEFAULT_KEYS) as $surface) {
    check(snap_signature_surface(['signature_surface' => $surface]) === $surface,
        "every blog-default surface is a declarable one: {$surface}");
    check(in_array('signature_' . $surface . '_image_id',
        SNAP_SIGNATURE_VARIANT_FIELDS, true),
        "and has a post column behind it: {$surface}");
}

// THE CONTROLLER USES THE DECLARED SURFACE, not a hardcoded one. Source, and
// labelled as source -- rendering a post needs a database and a request.
$controller_surface = (string)file_get_contents($root . '/core/smacktalk-public-controller.php');
check(str_contains($controller_surface, 'snap_signature_surface($skin_manifest)'),
    'SOURCE ONLY: the controller asks the manifest which surface to render');
check(!str_contains($controller_surface, "\$signature_ids['light'] > 0"),
    'SOURCE ONLY: and no longer hardcodes light at the render site');
check(str_contains($controller_surface, 'array $skin_manifest = []'),
    'SOURCE ONLY: the manifest arrives as an optional argument, so the admin'
    . ' preview caller is unaffected');
$front = (string)file_get_contents($root . '/index.php');
check(str_contains($front, 'snapsmack_smacktalk_request($pdo, $settings, ['),
    'SOURCE ONLY: the front controller still calls the request entry point');
check(preg_match('/\$_active_manifest\s*\)\s*\?\s*\$_active_manifest\s*:\s*\[\]/', $front) === 1,
    'SOURCE ONLY: and passes the manifest it already loaded, not a second read');

// NO SKIN DECLARES IT YET, which is the point: every site keeps today's
// behaviour until one does. Measured across every manifest in the tree rather
// than assumed, so if somebody adds a declaration this says so.
$declaring = [];
foreach (glob($root . '/skins/*/manifest.json') ?: [] as $manifest_path) {
    $decoded = json_decode((string)file_get_contents($manifest_path), true);
    if (is_array($decoded) && array_key_exists('signature_surface', $decoded)) {
        $declaring[] = basename(dirname($manifest_path)) . '='
            . json_encode($decoded['signature_surface']);
    }
}
check(count(glob($root . '/skins/*/manifest.json') ?: []) >= 30,
    'every skin manifest was read: ' . count(glob($root . '/skins/*/manifest.json') ?: []));
check($declaring === [],
    'NO skin declares a surface yet, so every site still renders light: '
    . ($declaring === [] ? 'none' : implode(', ', $declaring)));

// ---------------------------------------------------------------------------
// THE ADMIN CONTROL EXISTS AND RENDERS, driven rather than grepped. It must
// offer NONE, mark the stored choice, and say so when the stored image is gone.
// ---------------------------------------------------------------------------
function sig_images_db(): PDO {
    $pdo = (class_exists('Pdo\Sqlite'))
        ? Pdo\Sqlite::connect('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])
        : new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE snap_images (id INTEGER PRIMARY KEY, img_title TEXT DEFAULT '',
        img_file TEXT DEFAULT '', img_date TEXT DEFAULT '', img_status TEXT DEFAULT 'published',
        post_id INTEGER DEFAULT NULL)");
    $pdo->exec("INSERT INTO snap_images (id,img_title,img_file,img_date) VALUES
        (413,'Sean signature','sig.png','2026-01-01'),
        (500,'','untitled-sig.png','2026-01-02')");
    return $pdo;
}

$pdo = sig_images_db();
ob_start();
snap_signature_default_control($pdo, []);
$control = (string)ob_get_clean();

check(substr_count($control, '<select name="settings[signature_default_light_image_id]">') === 1,
    'the control renders the LIGHT picker');
check(substr_count($control, '<select name="settings[signature_default_dark_image_id]">') === 1,
    'and the DARK picker');
check(substr_count($control, 'NONE') === 2, 'each picker offers NONE');
check(str_contains($control, 'value=""' . ' selected'),
    'with nothing chosen, NONE is the selected option');
check(str_contains($control, '#413 Sean signature'),
    'a titled image is offered by id and title');
check(str_contains($control, '#500 untitled-sig.png'),
    'an untitled one falls back to its filename');
check(!str_contains($control, 'style="'),
    'NO STATIC STYLE ATTRIBUTES in the generated control');

ob_start();
snap_signature_default_control($pdo, ['signature_default_light_image_id' => '413']);
$chosen = (string)ob_get_clean();
check(str_contains($chosen, 'value="413" selected'), 'a stored choice comes back selected');
check(substr_count($chosen, 'IMAGE NOT FOUND') === 0,
    'and is not reported missing when it is there');

ob_start();
snap_signature_default_control($pdo, ['signature_default_light_image_id' => '9999']);
$missing = (string)ob_get_clean();
check(str_contains($missing, 'value="9999" selected'),
    'A DELETED IMAGE STAYS SELECTED, so saving the form does not silently drop it');
check(str_contains($missing, 'IMAGE NOT FOUND'), 'and it is named as missing in the list');
check(str_contains($missing, 'NO LONGER IN THE GALLERY'),
    'with a warning that posts inheriting it show nothing');

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
