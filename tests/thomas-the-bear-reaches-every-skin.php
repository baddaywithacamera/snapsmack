<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * Thomas the Bear reaches every skin.
 *
 * Ctrl+Shift+Y spawns him. Ctrl+Shift+Z tells the story of Noah and Thomas.
 *
 * He is a tribute to Noah Grey — who wrote Greymatter and helped invent
 * photoblogging, and who is a friend of Sean's — carried forward from the teddy
 * bear hidden inside Google's Picasa. THE THOMAS CLAUSE in the licence exists
 * specifically so that lineage cannot be cut. He is the thing the clause is about.
 *
 * What went wrong, and why this file exists: he was declared per skin, and only
 * THREE of thirty-one skins ever declared him — game-on, heuristic and parade.
 * On every other skin the shortcut did nothing, and had never done anything.
 * Sean found it himself, across his own installs, and was right to be angry.
 *
 * He is now shipped by the CMS on every page of every skin, including skins
 * nobody has written yet, and this check fails if that stops being true.
 */

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};

// 1. The bear exists.
foreach (['assets/js/ss-engine-thomas.js', 'assets/css/ss-engine-thomas.css'] as $file) {
    $check(is_file($root . '/' . $file), "Thomas is missing a file he needs: {$file}");
}

$engine = (string)@file_get_contents($root . '/assets/js/ss-engine-thomas.js');

// 2. Both shortcuts still exist. These are the thing people press.
$check(str_contains($engine, 'keydown'), 'The Thomas engine no longer listens for a key.');
// Matched against how the engine actually tests the key, not a guess at it:
//   e.ctrlKey && e.shiftKey && (e.key === 'y' || e.key === 'Y')
foreach ([
    "'y' || e.key === 'Y'" => 'Ctrl+Shift+Y, which spawns him',
    "'z' || e.key === 'Z'" => 'Ctrl+Shift+Z, which opens the story of Noah and Thomas',
] as $probe => $what) {
    $check(str_contains($engine, $probe), "The Thomas engine no longer handles {$what}.");
}
$check(substr_count($engine, 'e.ctrlKey && e.shiftKey') >= 2,
    'The Thomas engine no longer requires Ctrl+Shift, so he would spawn on a bare keypress.');

// 3. The dedication to Noah is still in the file.
$check(stripos($engine, 'noah') !== false,
    'The dedication to Noah Grey has been removed from the Thomas engine. '
    . 'That is the entire point of him — see THE THOMAS CLAUSE in the licence.');
// 4. HE REACHES EVERY SKIN. This is the regression, and it is now EXECUTED
//    rather than asserted from a file strict pages never load.
//
//    What the old check did: it read core/meta.php for the engine's filename.
//    core/meta.php is the LEGACY render path. index.php dispatches a strict
//    skin to snapsmack_public_runtime() and exits before meta.php is ever
//    reached, so that assertion was true and meaningless at the same time --
//    which is exactly why it passed for the whole month he was gone.
//
//    What this does instead: for every skin, resolve the asset the way the
//    live request resolves it and render the component that emits it, then
//    look for his two files in the HTML.
require_once $root . '/core/asset-registry.php';
require_once $root . '/core/skin-render-helpers.php';

/**
 * READ THE DECLARATION OUT OF CORE, so this check is COUPLED to it.
 *
 * snapsmack_public_runtime() needs a database and cannot be executed here, but
 * a test that passes its own handle to the renderer would pass with the core
 * declaration deleted -- the same shape of uselessness as the meta.php check
 * it replaces. So the handles below are PARSED from the runtime and fed to the
 * real resolver. Delete the declaration and every skin below goes red.
 */
$runtime = (string)@file_get_contents($root . '/core/public-runtime.php');
$marker = "['required_assets']['scripts'] = array_values(array_unique(array_merge(";
// There are several such statements. The page-kind helper near the top of the
// file declares the shortcode parser's assets; the ones in the middle belong to
// individual branches. The COMMON EXIT is the last one inside
// snapsmack_public_runtime(), which is the only one every request kind reaches,
// so this takes the last match at or after that function and nothing earlier.
$declaredHandles = [];
$fnAt = strpos($runtime, 'function snapsmack_public_runtime(');
$at = $fnAt === false ? false : strrpos($runtime, $marker);
if ($at !== false && $at < $fnAt) $at = false;
if ($at !== false) {
    $stmt = substr($runtime, $at, 20000);
    // THE ARRAY LITERAL, found by walking back from the statement's own
    // terminator rather than by reading a fixed number of characters.
    //
    // The earlier version read 500 characters from the marker, which stopped
    // working the moment a comment was added INSIDE the array: the window
    // ended before the closing bracket, the regex matched nothing, and the
    // test reported every skin as missing Thomas. A parse that depends on how
    // much prose sits beside the code is not a parse. ')))' is the statement's
    // real end and there is exactly one of them.
    //
    // No `return` in here either. This runs at script scope, so a return would
    // END THE TEST SILENTLY with a zero exit -- a check that passes by not
    // running, which is the whole family of fault this file exists to stop.
    $tail = strpos($stmt, ')))');
    if ($tail !== false) {
        $stmt = substr($stmt, 0, $tail);
        $close = strrpos($stmt, ']');
        $head = $close === false ? false : strrpos(substr($stmt, 0, $close), '[');
        if ($head !== false) {
            $inner = substr($stmt, $head + 1, $close - $head - 1);
            preg_match_all("/'([A-Za-z0-9:_-]+)'/", $inner, $hits);
            $declaredHandles = $hits[1];
        }
    }
}
$check($at !== false,
    'No required_assets declaration was found inside snapsmack_public_runtime() '
    . 'in the shape this check reads. Either Thomas is no longer shipped from '
    . 'there, or the statement was reshaped -- in which case update this parse '
    . 'rather than deleting the check, or it goes quiet the way the old one did.');
$check($declaredHandles !== [],
    'The declaration was found but no asset handle could be read out of it, so '
    . 'the render below would prove nothing.');

$skinDirs = [];
foreach (glob($root . '/skins/*', GLOB_ONLYDIR) ?: [] as $dir) {
    $skinDirs[basename($dir)] = $dir;
}
ksort($skinDirs);

// beatbox has no layout.php, so it renders nothing and is not a skin a
// visitor can be served. Named rather than silently skipped.
$renderable = [];
foreach ($skinDirs as $slug => $dir) {
    if (is_file($dir . '/layout.php')) $renderable[$slug] = $dir;
}
$check($renderable !== [], 'No skin has a layout.php, so this check proved nothing.');
$check(count($renderable) >= 30,
    'Fewer renderable skins than expected (' . count($renderable) . '). If a skin was '
    . 'removed that is fine; if the glob broke, this check is now vacuous.');

/**
 * The CMS declares the handle; the asset path turns it into URLs. Resolve it
 * the same way index.php does -- merge_registered_assets() over the skin's
 * own declared assets -- and render the component that writes the tags.
 */
$emits = static function (array $manifest, array $required): string {
    $assets = snapsmack_merge_registered_assets(
        snapsmack_skin_declared_assets($manifest),
        $required
    );
    return (string) snap_render_component('registered-assets', ['assets' => $assets]);
};

$missing = [];
$unstyled = [];
$manifests = [];
foreach ($renderable as $slug => $dir) {
    $manifest = is_file($dir . '/manifest.json')
        ? json_decode((string)file_get_contents($dir . '/manifest.json'), true)
        : [];
    if (!is_array($manifest)) $manifest = [];
    $manifests[$slug] = $manifest;

    $html = $emits($manifest, ['scripts' => $declaredHandles]);
    if (!str_contains($html, 'ss-engine-thomas.js')) $missing[] = $slug;
    if (!str_contains($html, 'ss-engine-thomas.css')) $unstyled[] = $slug;
}

$check($missing === [],
    'Thomas does not reach these skins: ' . implode(', ', $missing)
    . '. He is shipped from one place -- the common exit of '
    . 'snapsmack_public_runtime() in core/public-runtime.php. If that is gone, '
    . 'that is where it goes back.');
$check($unstyled === [],
    'Thomas reaches these skins without his stylesheet, so he would spawn '
    . 'unstyled: ' . implode(', ', $unstyled)
    . '. His CSS rides on the smack-thomas inventory handle in '
    . 'core/manifest-inventory.php.');

// MUTATION PROOF. A render check that cannot fail passes everything. Drop the
// declaration and he must disappear from every skin that does not name him in
// its own manifest -- which is the state Sean found, and the number he found.
$withoutHim = [];
foreach ($renderable as $slug => $dir) {
    $html = $emits($manifests[$slug], []);
    if (str_contains($html, 'ss-engine-thomas.js')) $withoutHim[] = $slug;
}
$check(count($withoutHim) < count($renderable),
    'Removing the CMS declaration changed nothing, so this check is not '
    . 'measuring the CMS at all.');
$check(count($withoutHim) <= 4,
    'More skins carry Thomas in their own manifest than expected ('
    . implode(', ', $withoutHim) . '). That is not a fault, but the mutation '
    . 'proof above is weaker the more of them there are.');

// 5. ONE exit, ONE declaration. Per-branch declarations are how he was lost in
//    the first place: three skins happened to ask for him and the rest did not.
$check(in_array('smack-thomas', $declaredHandles, true),
    'core/public-runtime.php declares assets at its exit but Thomas is not '
    . 'among them: ' . implode(', ', $declaredHandles));
$check(substr_count($runtime, "'smack-thomas'") === 1,
    'smack-thomas does not appear exactly once in core/public-runtime.php (found '
    . substr_count($runtime, "'smack-thomas'") . '). It belongs at the single '
    . 'common exit, not on individual branches and not nowhere.');

// 6. Loading him twice must stay harmless. He is shipped by the CMS AND
//    declared by a few skins, deliberately, so an older CMS with a newer skin
//    still gets him. Without the guard that belt-and-braces spawns two bears.
$check(str_contains($engine, 'window.__snapsmackThomas'),
    'The Thomas engine has lost its load-once guard.');
if ($withoutHim !== []) {
    $check(str_contains($engine, 'if (window.__snapsmackThomas) return;'),
        'These skins declare smack-thomas while the CMS also ships him, and '
        . 'the guard does not return early: ' . implode(', ', $withoutHim));
}

if ($failures !== []) {
    fwrite(STDERR, "THOMAS THE BEAR CHECK FAILED\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    fwrite(STDERR, "\nThomas is a tribute to a real person who is a friend of Sean's.\n"
        . "He is not a decoration and he is not optional.\n");
    exit(1);
}

echo "Thomas the Bear reaches every skin: rendered for all " . count($renderable)
    . " renderable skins, both files present, both shortcuts intact, dedication intact.\n";
echo "  NOT CHECKED: whether pressing the keys reaches a live page, and the\n";
echo "  ping writer. This file renders the component; it does not drive a browser.\n";
// ===== SNAPSMACK EOF =====
