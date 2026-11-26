<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * The POCKET ALBUM sharing switch must be settable from the admin.
 *
 * THE FAULT THIS CLOSES. `pocket_album_share` shipped in 1004D as a READ in
 * core/pocket-album-api.php with no control, no save path and no writer in the
 * product. The cross-site endpoint answered 404 on every site in the fleet and
 * nothing short of a hand-written UPDATE could change that. Sean reported it on
 * 2026-11-26: "cannot find pocket album share option in config."
 *
 * So this test does not inject a settings array -- that only ever proved the
 * read path, which was the one half that already worked. It drives the real
 * reader with a real $_POST, writes through the real save path into a real
 * database, reads the value back out the way an admin page load does, and
 * hands THAT to the same gate the endpoint uses.
 */

require_once dirname(__DIR__) . '/core/pocket-album-share-control.php';

$fails = [];
$check = static function (string $what, bool $ok) use (&$fails): void {
    if (!$ok) { $fails[] = $what; }
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
};

// ---------------------------------------------------------------------------
// A real database, so the save path is executed rather than described.
// ---------------------------------------------------------------------------
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE snap_settings (
    setting_key TEXT PRIMARY KEY NOT NULL,
    setting_val TEXT NOT NULL
)");
$pdo->exec("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('site_url', 'https://example.test')");

// How every admin page and the endpoint load settings.
$load = static function () use ($pdo): array {
    return $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
};

// ---------------------------------------------------------------------------
// Off by default: a site that has never touched the control shares nothing.
// ---------------------------------------------------------------------------
$check('a site that never saved the control is OFF',
    snap_pocket_album_share_enabled($load()) === false);

// ---------------------------------------------------------------------------
// Reading the form.
// ---------------------------------------------------------------------------
$check('a ticked box reads as on',
    snap_pocket_album_share_read(['save_pocket_album_share' => '1', 'pocket_album_share' => 'on']) === 'on');
$check('an UNTICKED box on a submitted control reads as off',
    snap_pocket_album_share_read(['save_pocket_album_share' => '1']) === 'off');
$check('a form WITHOUT this control reads as NULL, not off',
    snap_pocket_album_share_read(['album_name' => 'Summer', 'new_album' => '1']) === null);
$check('an empty post reads as NULL', snap_pocket_album_share_read([]) === null);

// ---------------------------------------------------------------------------
// The complete path: admin save -> stored value -> fresh load -> the gate.
// ---------------------------------------------------------------------------
snap_pocket_album_share_save($pdo, (string)snap_pocket_album_share_read(
    ['save_pocket_album_share' => '1', 'pocket_album_share' => 'on']));

$stored = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = 'pocket_album_share'")
    ->fetchColumn();
$check('saving ON stores the string the gate tests for', $stored === 'on');
$check('a FRESH load of settings reaches the gate as enabled',
    snap_pocket_album_share_enabled($load()) === true);

// Saving again must update the existing row, not fail on the primary key and
// not leave a second one behind.
snap_pocket_album_share_save($pdo, 'off');
$rows = (int)$pdo->query("SELECT COUNT(*) FROM snap_settings WHERE setting_key = 'pocket_album_share'")
    ->fetchColumn();
$check('saving a second time updates the row instead of duplicating it', $rows === 1);
$check('saving OFF switches the gate back off',
    snap_pocket_album_share_enabled($load()) === false);
$check('saving did not disturb the other settings',
    ($load()['site_url'] ?? '') === 'https://example.test');

// ---------------------------------------------------------------------------
// Only 'on' opens it. This widens what the site hands to strangers.
// ---------------------------------------------------------------------------
foreach (['1', 'true', 'yes', 'ON', 'on ', '', 'off'] as $value) {
    $pdo->prepare("UPDATE snap_settings SET setting_val = ? WHERE setting_key = 'pocket_album_share'")
        ->execute([$value]);
    $check("a stored " . var_export($value, true) . " does NOT enable sharing",
        snap_pocket_album_share_enabled($load()) === false);
}
$refused = false;
try { snap_pocket_album_share_save($pdo, '1'); } catch (InvalidArgumentException $e) { $refused = true; }
$check('the save path refuses a value that is neither on nor off', $refused);

// ---------------------------------------------------------------------------
// The rendered control and the reader must agree, in both states.
// ---------------------------------------------------------------------------
snap_pocket_album_share_save($pdo, 'on');
ob_start(); snap_pocket_album_share_render($load()); $html_on = (string)ob_get_clean();
snap_pocket_album_share_save($pdo, 'off');
ob_start(); snap_pocket_album_share_render($load()); $html_off = (string)ob_get_clean();

$check('the control posts the presence marker the reader needs',
    str_contains($html_on, 'name="save_pocket_album_share"'));
$check('the control posts the field name the reader looks for',
    str_contains($html_on, 'name="pocket_album_share"'));
$check('a stored ON renders the box ticked',  str_contains($html_on, 'value="on" checked'));
$check('a stored OFF renders the box unticked', !str_contains($html_off, 'checked'));
$check('the control carries no static style attribute', !str_contains($html_on, 'style="'));
$check('the control states that off is the default', stripos($html_on, 'Off by default') !== false);

// What the rendered form would actually post, ticked and unticked, parsed back
// through the reader -- the two halves meeting without a hand-written array.
preg_match_all('~name="([a-z_]+)"~', $html_on, $m);
$posts_on = [];
foreach ($m[1] as $field) { $posts_on[$field] = 'on'; }
$check('the ticked form round-trips to on', snap_pocket_album_share_read($posts_on) === 'on');
unset($posts_on['pocket_album_share']);
$check('the same form with the box unticked round-trips to off',
    snap_pocket_album_share_read($posts_on) === 'off');

// ---------------------------------------------------------------------------
// The admin page and the endpoint must both go through this component. A
// second spelling of the key anywhere is the fault returning.
// ---------------------------------------------------------------------------
$root  = dirname(__DIR__);
$page  = (string)file_get_contents($root . '/smack-albums.php');
$api   = (string)file_get_contents($root . '/core/pocket-album-api.php');

$check('the albums page requires the component',
    str_contains($page, "require_once 'core/pocket-album-share-control.php';"));
$check('the albums page reads the control from the component',
    str_contains($page, 'snap_pocket_album_share_read($_POST)'));
$check('the albums page saves through the component',
    str_contains($page, 'snap_pocket_album_share_save($pdo'));
$check('the albums page renders the control',
    str_contains($page, 'snap_pocket_album_share_render($settings)'));
$check('the save is CSRF-verified', (bool)preg_match(
    '~snap_pocket_album_share_read\(\$_POST\);.*?csrf_verify\(\);.*?snap_pocket_album_share_save~s', $page));
$check('the endpoint gate uses the shared helper',
    str_contains($api, 'snap_pocket_album_share_enabled($pa_settings)'));

$others = [];
foreach (['smack-albums.php', 'core/pocket-album-api.php', 'core/pocket-album-display.php',
          'core/pocket-album-feed.php', 'api.php'] as $rel) {
    $src = (string)file_get_contents($root . '/' . $rel);
    // The literal key may appear in prose; a comparison against it may not.
    if (preg_match('~[\'"]pocket_album_share[\'"]\s*\]?\s*(\?\?|===|==)~', $src) === 1) {
        $others[] = $rel;
    }
}
$check('nothing compares the raw setting key outside the component: '
    . ($others ? implode(', ', $others) : 'none'), $others === []);

// ---------------------------------------------------------------------------
echo "\n";
if ($fails) {
    echo "FAILED (" . count($fails) . "):\n";
    foreach ($fails as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL PASS\n";

// NOT CHECKED, and not checkable here: the rendered admin page in a browser,
// and one live site actually reading another's album with the switch on. Both
// need a real install; this proves the control saves, survives a reload and
// moves the gate.

// ===== SNAPSMACK EOF =====
