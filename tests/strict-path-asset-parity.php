<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * What the legacy render path emitted that a strict page never gets.
 *
 * THE REASON THIS FILE EXISTS. The 28 September strict-runtime conversion moved
 * rendering into core/public-runtime.php. index.php dispatches a strict skin
 * there and EXITS before core/meta.php, core/footer-scripts.php, core/header.php
 * and core/footer.php are reached. Every asset those four files emitted had to
 * be re-declared on the new path, and some were not. Nothing noticed, because
 * the thing that stops working is a stylesheet or a behaviour on a live page,
 * not a PHP error.
 *
 * They have been found ONE AT A TIME, each by the owner noticing something was
 * wrong on his own sites, over a month: the archive control row (SS-1017), the
 * archive stylesheet, the generator meta tag, the download overlay stylesheet
 * (SS-1059), the search route's prepared fields (SS-1060), the public config
 * globals (SS-1042), the 'blog' hit type (SS-1061), and Thomas the Bear
 * (SS-1063), who had been gone from 28 of 31 skins for a month behind a test
 * that passed.
 *
 * Finding the eighth one the same way as the first is the failure. So this
 * reads every asset the four legacy files reference and asks whether anything
 * on the strict path can reach it -- by literal path, by registry handle from
 * assets/ASSET-INVENTORY.json, or by an inventory key from
 * core/manifest-inventory.php.
 *
 * SAME SHAPE AS tests/multisite-endpoint-role-contract.php, which Sean
 * approved: the known-bad set is NAMED, it may only shrink, and anything new
 * fails immediately. Nothing here asserts the listed assets are harmless.
 *
 * WHAT IT CANNOT SEE, said now rather than discovered later:
 *  - It matches names in source. An asset reached by a name built at runtime
 *    from pieces would read as unreachable.
 *  - "Reaches the strict path" means SOME strict-path file names it. It does
 *    not prove the code naming it runs on every page, or on any page.
 *  - It says nothing about whether an asset SHOULD still exist. A deliberately
 *    retired engine looks identical to a stranded one, which is why each entry
 *    below carries a reason rather than just a filename.
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

function sp_test(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

function sp_read(string $path): string {
    return is_file($path) ? (string) file_get_contents($path) : '';
}

/** Every assets/js|css file the four legacy render files name. */
function sp_legacy_assets(string $root): array {
    $out = [];
    foreach (['meta.php', 'footer-scripts.php', 'header.php', 'footer.php'] as $name) {
        $src = sp_read($root . '/core/' . $name);
        if ($src === '') continue;
        if (preg_match_all('#assets/(?:js|css)/[A-Za-z0-9._-]+\.(?:js|css)#', $src, $m)) {
            foreach ($m[0] as $asset) {
                $out[$asset][$name] = true;
            }
        }
    }
    foreach ($out as $asset => $where) {
        $out[$asset] = array_keys($where);
        sort($out[$asset]);
    }
    ksort($out);
    return $out;
}

/**
 * Every name core could legitimately use to reach a given asset path. Most of
 * core does NOT name the path -- it names a handle, and the asset registry
 * turns the handle into both the script and its stylesheet. A path-only check
 * reports false positives, which is how the first draft of this read
 * ss-engine-social-dock.js as stranded while its own stylesheet read as fine.
 */
function sp_names_for(string $root): array {
    $names = [];

    $catalog = json_decode(sp_read($root . '/assets/ASSET-INVENTORY.json'), true);
    if (is_array($catalog)) {
        foreach (['javascript', 'css'] as $group) {
            foreach (($catalog[$group] ?? []) as $entry) {
                if (!is_array($entry) || !is_string($entry['file'] ?? null)) continue;
                $path = $entry['file'];
                if (is_string($entry['registry_handle'] ?? null) && $entry['registry_handle'] !== '') {
                    $names[$path][] = $entry['registry_handle'];
                }
                $names[$path][] = 'asset:' . (string)($entry['scope'] ?? '') . ':'
                    . pathinfo($path, PATHINFO_FILENAME);
            }
        }
    }

    // Inventory keys such as smack-thomas carry a script AND its stylesheet.
    //
    // BRACKET-MATCHED, not a fixed window. The first version took 700
    // characters from the key and read whatever fell inside, so it ran past the
    // end of short entries and attributed the NEXT entry's files to this key --
    // it had Thomas's stylesheet belonging to smack-keyboard. An asset credited
    // to the wrong handle reads as reachable whenever that wrong handle is
    // declared somewhere, which is a false clean. Same window-bleed mistake I
    // made earlier the same evening in an unrelated scan, which is why it is
    // spelled out here rather than just fixed.
    $inv = sp_read($root . '/core/manifest-inventory.php');
    if (preg_match_all("/'([a-z0-9-]+)'\s*=>\s*\[/", $inv, $keys, PREG_OFFSET_CAPTURE)) {
        foreach ($keys[1] as $i => $hit) {
            $key = $hit[0];
            $open = strpos($inv, '[', $keys[0][$i][1]);
            if ($open === false) continue;
            $depth = 0;
            $end = $open;
            for ($p = $open, $n = strlen($inv); $p < $n; $p++) {
                if ($inv[$p] === '[') $depth++;
                elseif ($inv[$p] === ']') {
                    $depth--;
                    if ($depth === 0) { $end = $p; break; }
                }
            }
            // SKIP THE CONTAINERS, and read FIELDS rather than any path in the
            // block. This is the fourth fault I found in my own detector and
            // by far the worst. manifest-inventory.php is
            // ['scripts' => [ ...every entry... ], 'styles' => [ ... ]], so the
            // key regex matched 'scripts', bracket-matched the WHOLE catalogue,
            // and handed every asset in it the name "scripts" -- a word that
            // appears in nearly every PHP file and every manifest. Every
            // catalogued asset therefore read as reachable no matter what, and
            // the generic arm of this test was dead for all of them. It passed
            // green while proving nothing, which is the exact fault this file
            // was written to stop happening to somebody else.
            if ($key === 'scripts' || $key === 'styles') continue;
            $block = substr($inv, $open, $end - $open + 1);
            foreach (["'path'", "'css'"] as $field) {
                if (preg_match('#' . $field . '\s*=>\s*\'(assets/(?:js|css)/[A-Za-z0-9._-]+\.(?:js|css))\'#', $block, $m)) {
                    $names[$m[1]][] = $key;
                }
            }
            if (preg_match('#\'companions\'\s*=>\s*\[(.*?)\]#s', $block, $cm)) {
                if (preg_match_all('#assets/(?:js|css)/[A-Za-z0-9._-]+\.(?:js|css)#', $cm[1], $m2)) {
                    foreach ($m2[0] as $path) $names[$path][] = $key;
                }
            }
        }
    }

    foreach ($names as $path => $list) {
        $names[$path] = array_values(array_unique($list));
    }
    return $names;
}

/**
 * Everything a strict request can execute: core minus the four legacy files,
 * AND minus the catalogues.
 *
 * THE CATALOGUE EXCLUSION IS THE WHOLE CORRECTNESS OF THIS FILE, and I got it
 * wrong first. core/manifest-inventory.php and core/asset-security-reviews.php
 * are data tables: they `return [...]` keyed BY ASSET PATH, and they emit
 * nothing. Counting them as strict-path source makes every asset that is
 * merely CATALOGUED read as REACHABLE -- a false clean, in the one direction
 * that hides the fault.
 *
 * The first version of this file included them, which meant it would have
 * called Thomas reachable purely because manifest-inventory.php lists his
 * filename. It could not have detected the fault it exists to detect. I found
 * that by checking one specific claim -- the sticky header -- rather than by
 * trusting a green run, and the green run was wrong.
 *
 * Being catalogued is not being requested. Only code that ASKS for an asset
 * puts it on a page.
 */
function sp_strict_blob(string $root): string {
    $legacy = ['meta.php', 'footer-scripts.php', 'header.php', 'footer.php'];
    $catalogues = ['manifest-inventory.php', 'asset-security-reviews.php'];
    $files = array_merge(
        glob($root . '/core/*.php') ?: [],
        glob($root . '/core/*/*.php') ?: [],
        [$root . '/index.php']
    );
    $blob = '';
    foreach ($files as $file) {
        $name = basename($file);
        if (in_array($name, $legacy, true)) continue;
        if (in_array($name, $catalogues, true)) continue;
        $blob .= sp_read($file) . "\n";
    }
    return $blob;
}

/**
 * STRANDED BY THE CONVERSION, not by decision.
 *
 * Each is an asset the legacy path emitted that nothing on the strict path can
 * name. The reason is recorded so a deliberately retired engine can be told
 * apart from a lost one -- and so that removing a row means proving the asset
 * is reachable or proving it should go, not editing a list.
 */
$STRANDED = [
    'assets/css/public-download-overlay.css' =>
        'SS-1059. The download button has markup on the strict path and its '
        . 'stylesheet only on the legacy one, so the control renders unstyled.',
    'assets/js/ss-config-bootstrap.js' =>
        'SS-1042, one of the two public config globals. Strict pages get no '
        . 'bootstrapped config object.',
    'assets/js/ss-engine-config.js' =>
        'SS-1042, the second of the two. The pair is what put the public '
        . 'config on the page, so both have to land together: declaring one '
        . 'without the other would leave a reader with no writer.',
    'assets/js/ss-engine-sticky-header.js' =>
        'The sticky-header BEHAVIOUR. core/footer-scripts.php loaded it only '
        . 'when the admin had switched the setting on; on a strict page it is '
        . 'never loaded at all, so the setting is read by nothing. Strict page '
        . 'components still emit data-sticky-header on the header element, so '
        . 'the markup advertises a behaviour that cannot arrive.',
    'assets/css/ss-engine-sticky-header.css' =>
        'The sticky-header STYLESHEET, stranded with its script. I first wrote '
        . 'that this one DID reach strict pages and that it therefore pinned '
        . 'CHAPLIN header unconditionally (SS-1028). That was wrong: the only '
        . 'strict-path file naming it was core/asset-security-reviews.php, a '
        . 'SECAUDIT data table that emits nothing. Both halves are stranded, '
        . 'so this engine does nothing at all on a strict page and is NOT an '
        . 'explanation for SS-1028. Whatever pins that header is elsewhere.',
    'assets/js/ss-engine-public-share.js' =>
        'Public share buttons. NOT CHECKED whether they are still wanted.',
];

/**
 * REACHES A PAGE ONLY IF AN INDIVIDUAL SKIN ASKS. This is the exact shape
 * Thomas was in: the CMS stopped shipping it, a few manifests happened to
 * declare it, and those were the only skins where it still worked.
 */
$SKIN_DEPENDENT = [
    'assets/js/ss-engine-nav-dropdown.js' =>
        'Declared by 1 skin of 30 (instant-camera). If the dropdown is meant '
        . 'to be a CMS behaviour it belongs on the strict path like Thomas now '
        . 'is; if it is one skin\'s own, the legacy emitter should stop '
        . 'claiming it. Needs a decision, not a repair.',
    'assets/js/ss-engine-comms.js' =>
        'The keyboard engine, handle smack-keyboard, declared by 24 of 30 '
        . 'skins. core/meta.php shipped it to every skin, so SIX lost it: '
        . 'alfred, photogram, stanley, telegram, tilez, writing-with-impact. '
        . 'Exactly the shape Thomas was in, just less far gone. Whether '
        . 'keyboard shortcuts are meant to be fleet-wide is the owner\'s call.',
    'assets/css/ss-engine-comms.css' =>
        'The keyboard engine stylesheet, carried by the same smack-keyboard '
        . 'handle and missing from the same six skins.',
    'assets/js/ss-engine-rows.js' =>
        'Handle smack-rows, declared by 7 of 30. This one is EXPECTED and is '
        . 'not a stranding: the rows engine belongs to the SCROLL wall family '
        . 'and the other skins run a different gallery engine entirely. Listed '
        . 'so the scan stays quiet about it for a stated reason rather than '
        . 'because nobody looked.',
];

$legacy = sp_legacy_assets($root);
$names = sp_names_for($root);
$strict = sp_strict_blob($root);
$manifests = glob($root . '/skins/*/manifest.json') ?: [];
$manifestBlob = '';
foreach ($manifests as $m) $manifestBlob .= sp_read($m) . "\n";

echo "The scan reaches the legacy files at all\n";
sp_test($legacy !== [], 'the four legacy render files were read and name assets');
sp_test(count($legacy) >= 20,
    'at least 20 distinct assets found on the legacy path (found ' . count($legacy) . ')');
sp_test(isset($legacy['assets/js/ss-engine-thomas.js']),
    'Thomas is among them, so this is reading the right files');

echo "\nMUTATION PROOF: the reachability test distinguishes reachable from not\n";
// A check that calls everything reachable passes everything.
sp_test(strpos($strict, 'smack-thomas') !== false,
    'an asset core DOES declare (smack-thomas) is seen on the strict path');
sp_test(strpos($strict, 'asset:public:ss-engine-public-share') === false,
    'and one it does not (asset:public:ss-engine-public-share) is NOT');

echo "\nEvery legacy asset is reachable, named as stranded, or named as skin-dependent\n";
$unaccounted = [];
$wrongly_listed = [];
foreach ($legacy as $asset => $where) {
    $candidates = array_merge([$asset], $names[$asset] ?? []);
    $onStrict = false;
    foreach ($candidates as $name) {
        if ($name !== '' && strpos($strict, $name) !== false) { $onStrict = true; break; }
    }
    $inManifest = false;
    foreach ($candidates as $name) {
        if ($name !== '' && strpos($manifestBlob, $name) !== false) { $inManifest = true; break; }
    }

    $listedStranded = isset($STRANDED[$asset]);
    $listedSkin     = isset($SKIN_DEPENDENT[$asset]);

    if ($onStrict) {
        // Reachable. If it is on either list, the list is stale -- which is the
        // good direction, and must be noticed rather than left to rot.
        if ($listedStranded || $listedSkin) $wrongly_listed[] = $asset;
        continue;
    }
    if ($inManifest) {
        if (!$listedSkin) $unaccounted[] = $asset . ' (skin-declared only)';
        continue;
    }
    if (!$listedStranded) $unaccounted[] = $asset . ' (nothing can reach it)';
}

sp_test($unaccounted === [],
    'no legacy asset is unreachable without being named here'
    . ($unaccounted === [] ? '' : ': ' . implode('; ', $unaccounted)
        . ' -- declare it on the strict path, or add it with a reason and open a ticket'));

sp_test($wrongly_listed === [],
    'nothing on the lists is reachable now'
    . ($wrongly_listed === [] ? '' : ', so delete these rows: ' . implode(', ', $wrongly_listed)));

echo "\nThe lists only shrink\n";
// The ceiling is 9, and it went UP from 8 after this file was first written.
// Saying why, because a bar moving up is exactly what the strength record
// exists to make somebody explain: the 8 was WRONG, not regressed. The first
// version counted two catalogue files as strict-path source and mis-attributed
// inventory entries with a fixed-width window, and both faults hid stranded
// assets by calling them reachable. 9 is what the corrected scan finds. It may
// only shrink from here.
// 6, DOWN from 9. The consent engine, its stylesheet and the F1 help engine
// were repaired on 29 October after the owner found both missing himself, so
// they leave this list. It only shrinks, and this is it shrinking.
sp_test(count($STRANDED) <= 6,
    'the stranded set is no larger than it was after the help/consent repair (now '
    . count($STRANDED) . ', was 6)');
// 4, corrected upward from 1 for the same reason as the stranded ceiling: the
// detector was wrong, not the product. Three of these were invisible while the
// container-key fault was handing every catalogued asset a name that matched
// everywhere.
sp_test(count($SKIN_DEPENDENT) <= 4,
    'the skin-dependent set is no larger than the corrected count (now '
    . count($SKIN_DEPENDENT) . ', corrected to 4 from a wrong 1)');
foreach (array_merge($STRANDED, $SKIN_DEPENDENT) as $asset => $reason) {
    sp_test(trim($reason) !== '' && strlen($reason) > 30,
        "{$asset} carries a stated reason rather than just a filename");
}

echo "\nThe ones already repaired stay repaired\n";
// Thomas and the archive control row were both in this set and were fixed.
// If either falls back out, it is the same fault returning.
foreach ([
    'smack-thomas'                   => 'Thomas the Bear (SS-1063)',
    'asset:public:ss-engine-mosaic'  => 'the mosaic engine',
    'asset:public:ss-engine-lightbox' => 'the lightbox',
] as $needle => $what) {
    sp_test(strpos($strict, $needle) !== false,
        "{$what} is still declared on the strict path");
}

echo "\n  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "Strict-path asset parity: FAIL ({$fail}).\n";
    exit(1);
}
echo "Strict-path asset parity: PASS ({$pass} assertions, "
    . count($STRANDED) . " stranded and " . count($SKIN_DEPENDENT)
    . " skin-dependent, all named).\n";

// ===== SNAPSMACK EOF =====
