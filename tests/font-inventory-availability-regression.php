<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// Every font a skin can choose must actually be obtainable by a live site.
//
// Release packages omit the 22 MB font library so the installer stays small on
// shared hosting. A site therefore fetches each face it uses from Smack
// Central. That works -- 39 of 41 faces -- but nothing checked that a newly
// added family had been uploaded there.
//
// Merriweather and Marcellus were added to the inventory on 2026-10-08 and
// never uploaded. font.php answered 502, the @font-face failed, and the
// 50 SHADES masthead quietly rendered in a default sans. Nobody knew until the
// owner noticed the wrong typeface two days later. A font that falls back
// silently is the worst kind of broken: the page still looks finished.
//
// This program checks the inventory is coherent and, when the network is
// available, that Smack Central really serves every face.
//
// Offline it still runs: it verifies the inventory's shape and that each file
// path is well formed, and reports that the upstream half was skipped.

$root = dirname(__DIR__);
require_once $root . '/core/font-provider.php';

$fonts = snapsmack_font_inventory();
if (count($fonts) < 1) {
    throw new RuntimeException('The local font inventory is empty.');
}

$failures = [];
$families = [];

foreach ($fonts as $family => $font) {
    $family = (string)$family;
    if (!is_array($font)) {
        $failures[] = "{$family}: inventory entry is not an array.";
        continue;
    }
    foreach (['file', 'format', 'weight', 'style'] as $key) {
        if (!isset($font[$key]) || (string)$font[$key] === '') {
            $failures[] = "{$family}: inventory entry is missing '{$key}'.";
        }
    }
    $relative = snapsmack_font_relative_path($family);
    if ($relative === '') {
        $failures[] = "{$family}: file path is missing or not an allowed font path.";
        continue;
    }
    // A full checkout holds the real library. If the face is not even here,
    // the inventory is pointing at something that does not exist.
    if (!is_file($root . '/' . $relative)) {
        $failures[] = "{$family}: {$relative} is not in the repository.";
        continue;
    }
    $families[$family] = $relative;
}

// Upstream check. Skipped without a network rather than failing a build that
// happens to run offline.
$checked = 0;
$unreachable = [];
$networkAvailable = false;

foreach ($families as $family => $relative) {
    $url = snapsmack_font_source_url($relative);
    $context = stream_context_create([
        'http' => ['method' => 'HEAD', 'timeout' => 15, 'follow_location' => 0,
                   'ignore_errors' => true, 'user_agent' => 'SnapSmack font provider'],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $headers = @get_headers($url, false, $context);
    if (!is_array($headers) || $headers === []) {
        continue; // no network, or the host refused the probe entirely
    }
    $networkAvailable = true;
    $checked++;
    $status = 0;
    if (preg_match('#\s(\d{3})\s#', ' ' . (string)$headers[0] . ' ', $m)) {
        $status = (int)$m[1];
    }
    if ($status !== 200) {
        $unreachable[] = sprintf('%s (HTTP %d) %s', $family, $status, $url);
    }
}

if ($unreachable !== []) {
    $failures[] = "Smack Central does not serve these faces, so every site asking for\n"
        . "them gets a 502 from font.php and silently renders a default face.\n"
        . "Upload them to snapsmack.ca/sc-assets/fonts/ :\n  "
        . implode("\n  ", $unreachable);
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

if (!$networkAvailable) {
    printf("Font inventory: PASS (%d families well formed and present locally; "
        . "upstream check skipped, no network).\n", count($families));
} else {
    printf("Font inventory: PASS (%d families, %d verified served by Smack Central).\n",
        count($families), $checked);
}

// ===== SNAPSMACK EOF =====
