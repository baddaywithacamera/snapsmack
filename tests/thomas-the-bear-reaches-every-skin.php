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

// 4. The CMS ships him, so every skin gets him. This is the regression.
$meta = (string)@file_get_contents($root . '/core/meta.php');
$check(str_contains($meta, 'ss-engine-thomas.js'),
    'core/meta.php no longer ships the Thomas engine. He was declared per skin before, '
    . 'and only 3 skins out of 31 ever declared him, so the shortcut was dead on every '
    . 'other skin. If this is gone, that is where it goes back to.');
$check(str_contains($meta, 'ss-engine-thomas.css'),
    'core/meta.php no longer ships the Thomas stylesheet, so he would spawn unstyled.');

// 5. And no skin declares him, because two copies register two key listeners and
//    spawn two sets of bears.
$declaring = [];
foreach (glob($root . '/skins/*/manifest.json') ?: [] as $path) {
    $manifest = json_decode((string)file_get_contents($path), true);
    if (!is_array($manifest)) continue;
    if (in_array('smack-thomas', (array)($manifest['require_scripts'] ?? []), true)) {
        $declaring[] = basename(dirname($path));
    }
}
// Declaring him in a skin is FINE and in fact wanted -- a site can run an older CMS
// that does not ship him yet while installing a newer skin. What must be true is that
// loading him twice is harmless, or that combination spawns two sets of bears.
$check(str_contains($engine, 'window.__snapsmackThomas'),
    'The Thomas engine has lost its load-once guard. He is shipped by the CMS AND declared '
    . 'by some skins, deliberately, so that he survives any CMS/skin combination — without '
    . 'the guard that same belt-and-braces spawns two sets of bears.');
if ($declaring !== []) {
    $check(str_contains($engine, 'if (window.__snapsmackThomas) return;'),
        'These skins declare smack-thomas while the CMS also ships him, and the guard does '
        . 'not return early: ' . implode(', ', $declaring));
}

if ($failures !== []) {
    fwrite(STDERR, "THOMAS THE BEAR CHECK FAILED\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    fwrite(STDERR, "\nThomas is a tribute to a real person who is a friend of Sean's.\n"
        . "He is not a decoration and he is not optional.\n");
    exit(1);
}

$skins = count(glob($root . '/skins/*/manifest.json') ?: []);
echo "Thomas the Bear reaches every skin: shipped by the CMS to all {$skins}, "
    . "both shortcuts intact, dedication intact.\n";
// ===== SNAPSMACK EOF =====
