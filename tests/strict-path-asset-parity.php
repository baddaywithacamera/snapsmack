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
    $inv = sp_read($root . '/core/manifest-inventory.php');
    if (preg_match_all("/'([a-z0-9-]+)'\s*=>\s*\[/", $inv, $keys, PREG_OFFSET_CAPTURE)) {
        foreach ($keys[1] as $i => $hit) {
            $key = $hit[0];
            $start = $keys[0][$i][1];
            $block = substr($inv, $start, 700);
            if (preg_match_all('#assets/(?:js|css)/[A-Za-z0-9._-]+\.(?:js|css)#', $block, $m)) {
                foreach ($m[0] as $path) $names[$path][] = $key;
            }
        }
    }

    foreach ($names as $path => $list) {
        $names[$path] = array_values(array_unique($list));
    }
    return $names;
}

/** Everything a strict request can execute: core minus the four legacy files. */
function sp_strict_blob(string $root): string {
    $legacy = ['meta.php', 'footer-scripts.php', 'header.php', 'footer.php'];
    $files = array_merge(
        glob($root . '/core/*.php') ?: [],
        glob($root . '/core/*/*.php') ?: [],
        [$root . '/index.php']
    );
    $blob = '';
    foreach ($files as $file) {
        if (in_array(basename($file), $legacy, true)) continue;
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
        'Its STYLESHEET reaches every strict page and its BEHAVIOUR does not, '
        . 'so a sticky header is pinned by CSS with nothing reading the '
        . 'setting. Candidate cause of SS-1028 (CHAPLIN pins its header '
        . 'regardless of the sticky header setting) -- NOT CONFIRMED on a page.',
    'assets/js/ss-engine-consent.js' =>
        'The consent engine. No strict page loads it. NOT CHECKED whether a '
        . 'consent banner is required on these sites or handled elsewhere; '
        . 'that is the owners decision and it needs asking before any repair.',
    'assets/css/ss-engine-consent.css' =>
        'The consent engine stylesheet, same as above.',
    'assets/js/ss-engine-public-help.js' =>
        'Public help, 8.6KB of it. NOT CHECKED whether it is still wanted.',
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
        'Declared by 1 skin of 30. If the dropdown is meant to be a CMS '
        . 'behaviour it belongs on the strict path like Thomas now is; if it '
        . 'is one skin\'s own, the legacy emitter should stop claiming it.',
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
sp_test(strpos($strict, 'asset:public:ss-engine-consent') === false,
    'and one it does not (asset:public:ss-engine-consent) is NOT');

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
sp_test(count($STRANDED) <= 8,
    'the stranded set is no larger than when this was written (now ' . count($STRANDED) . ', was 8)');
sp_test(count($SKIN_DEPENDENT) <= 1,
    'the skin-dependent set is no larger than when this was written (now '
    . count($SKIN_DEPENDENT) . ', was 1)');
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
