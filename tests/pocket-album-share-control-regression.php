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
 * read path, which was the one half that already worked. It clamps a real
 * posted value, writes it with the statement the Configuration page's save loop
 * runs, reads it back the way an admin page load does, and hands THAT to the
 * same gate the endpoint uses.
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
// The server-side clamp. The Configuration page stores every posted
// settings[...] key as it stands, so anything this lets through gets stored.
// ---------------------------------------------------------------------------
$check("the only value that survives the clamp as on is 'on'",
    snap_pocket_album_share_sanitise('on') === 'on');
foreach (['off', '1', 'true', 'yes', 'ON', 'On', 'on ', ' on', '', '0', 'enabled'] as $value) {
    $check('the clamp turns ' . var_export($value, true) . ' into off',
        snap_pocket_album_share_sanitise($value) === 'off');
}
foreach ([['on'], 1, 1.0, true, null, new stdClass()] as $value) {
    $check('the clamp turns a non-string ' . gettype($value) . ' into off',
        snap_pocket_album_share_sanitise($value) === 'off');
}

// ---------------------------------------------------------------------------
// The complete path: posted value -> clamp -> stored -> fresh load -> the gate.
// The write is the statement the Configuration page's save loop runs.
// ---------------------------------------------------------------------------
$store = static function ($posted) use ($pdo): void {
    $value = snap_pocket_album_share_sanitise($posted);
    $key = snap_pocket_album_share_key();
    $sel = $pdo->prepare("SELECT 1 FROM snap_settings WHERE setting_key = ? LIMIT 1");
    $sel->execute([$key]);
    if ($sel->fetchColumn()) {
        $pdo->prepare("UPDATE snap_settings SET setting_val = ? WHERE setting_key = ?")
            ->execute([$value, $key]);
    } else {
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)")
            ->execute([$key, $value]);
    }
};

$store('on');
$stored = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = 'pocket_album_share'")
    ->fetchColumn();
$check('saving ON stores the string the gate tests for', $stored === 'on');
$check('a FRESH load of settings reaches the gate as enabled',
    snap_pocket_album_share_enabled($load()) === true);

$store('off');
$rows = (int)$pdo->query("SELECT COUNT(*) FROM snap_settings WHERE setting_key = 'pocket_album_share'")
    ->fetchColumn();
$check('saving a second time updates the row instead of duplicating it', $rows === 1);
$check('saving OFF switches the gate back off',
    snap_pocket_album_share_enabled($load()) === false);

$store("on'; DROP TABLE snap_settings; --");
$check('a crafted value lands as off and the table is still there',
    $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = 'pocket_album_share'")
        ->fetchColumn() === 'off');
$check('saving did not disturb the other settings',
    ($load()['site_url'] ?? '') === 'https://example.test');

// ---------------------------------------------------------------------------
// Only 'on' opens the gate, whatever is in the row. This widens what the site
// hands to strangers, so a stray value must not open it.
// ---------------------------------------------------------------------------
foreach (['1', 'true', 'yes', 'ON', 'on ', '', 'off'] as $value) {
    $pdo->prepare("UPDATE snap_settings SET setting_val = ? WHERE setting_key = 'pocket_album_share'")
        ->execute([$value]);
    $check('a stored ' . var_export($value, true) . ' does NOT enable sharing',
        snap_pocket_album_share_enabled($load()) === false);
}

// ---------------------------------------------------------------------------
// The rendered control, in both states.
// ---------------------------------------------------------------------------
$store('on');
ob_start(); snap_pocket_album_share_render($load()); $html_on = (string)ob_get_clean();
$store('off');
ob_start(); snap_pocket_album_share_render($load()); $html_off = (string)ob_get_clean();

$check('the control posts into the settings array the Configuration page saves',
    str_contains($html_on, 'name="settings[pocket_album_share]"'));
$check('IT IS NOT A CHECKBOX: an unticked box posts nothing and cannot say off',
    !str_contains($html_on, 'type="checkbox"') && str_contains($html_on, '<select'));
$check('it offers exactly two values',
    preg_match_all('~<option value="(on|off)"~', $html_on) === 2);
$check('a stored ON preselects on', (bool)preg_match('~<option value="on" selected~', $html_on));
$check('a stored OFF preselects off', (bool)preg_match('~<option value="off" selected~', $html_off));
$check('exactly one option is selected at a time',
    preg_match_all('~ selected~', $html_on) === 1
    && preg_match_all('~ selected~', $html_off) === 1);
$check('the control carries no static style attribute', !str_contains($html_on, 'style="'));
$check('the control states that off is the default', stripos($html_on, 'Off by default') !== false);
$check('the control says plainly that this is about OTHER sites',
    stripos($html_on, 'other') !== false);

// Every value the rendered control can post must survive its own clamp, or the
// page would store something the gate does not recognise.
preg_match_all('~<option value="([^"]+)"~', $html_on, $options);
$check('the control offers something at all', $options[1] !== []);
foreach ($options[1] as $value) {
    $check('the control\'s own value ' . var_export($value, true) . ' survives the clamp',
        snap_pocket_album_share_sanitise($value) === $value);
}

// ---------------------------------------------------------------------------
// Where the control lives, and who may reach it. A second spelling of the key
// anywhere is the fault returning.
// ---------------------------------------------------------------------------
$root   = dirname(__DIR__);
$config = (string)file_get_contents($root . '/smack-settings.php');
$api    = (string)file_get_contents($root . '/core/pocket-album-api.php');
$albums = (string)file_get_contents($root . '/smack-albums.php');
$cron   = (string)file_get_contents($root . '/smack-cron.php');
$auth   = (string)file_get_contents($root . '/core/auth-smack.php');

$check('Configuration requires the component',
    str_contains($config, "require_once 'core/pocket-album-share-control.php';"));
$check('Configuration renders the control',
    str_contains($config, 'snap_pocket_album_share_render($settings)'));
$check('Configuration clamps the posted value server-side',
    str_contains($config, 'snap_pocket_album_share_sanitise('));
$check('the clamp is administrator-only', (bool)preg_match(
    '~snap_pocket_album_share_key\(\)\]\)\)\s*\{\s*smack_require_admin\(\);~s', $config));
$check('Configuration itself is administrator-only', str_contains($auth, "'smack-settings.php'"));
$check('the switch is NOT on the albums page, which an editor can open',
    !str_contains($albums, 'pocket_album_share'));
$check('the endpoint gate uses the shared helper',
    str_contains($api, 'snap_pocket_album_share_enabled($pa_settings)'));

// CRON & JOBS: the refresh must be operable, and only by an administrator.
$check('CRON & JOBS lists the POCKET ALBUM refresh',
    str_contains($cron, "'key' => 'pocket_album'"));
$check('its RUN NOW is behind CSRF',
    str_contains($cron, 'csrf_check();') && str_contains($cron, 'value="run_now"'));
$check('CRON & JOBS has an administrator check of its own',
    str_contains($cron, 'smack_require_admin();'));
$check('CRON & JOBS is administrator-only in the central denylist too',
    str_contains($auth, "'smack-cron.php'"));

// The post-deploy refresh must cover every job in the catalogue. A job missing
// from it keeps a stale absolute script path after a deploy and stops firing,
// silently -- which is what had happened to this one.
require_once $root . '/core/cron-register.php';
$catalogue = cron_job_catalogue();
$check('the catalogue holds the POCKET ALBUM job', isset($catalogue['pocket_album']));
$register = (string)file_get_contents($root . '/core/cron-register.php');
$check('the post-deploy refresh is DERIVED from the catalogue, not a second copy',
    (bool)preg_match('~\$jobs = \[\];\s*foreach \(cron_job_catalogue\(\)~s', $register));

$others = [];
foreach (['smack-albums.php', 'smack-settings.php', 'core/pocket-album-api.php',
          'core/pocket-album-display.php', 'core/pocket-album-feed.php', 'api.php'] as $rel) {
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

// NOT CHECKED, and not checkable here: the Configuration page in a browser, an
// HTTP save through it, and one live site actually reading another's album with
// the switch on. All three need a real install.

// ===== SNAPSMACK EOF =====
