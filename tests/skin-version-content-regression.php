<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// One published skin version must mean exactly one set of files.
//
// A site installs a skin BY VERSION. If two different file-states ever ship
// under one number, every site that took the first is frozen on it and no CMS
// release will ever move them — the CMS is not where that markup lives.
//
// That is not hypothetical. photowalk.ing served the 0.7.850D 50 SHADES
// layout.php (6,692 bytes, infobox and footer nested inside the photo article)
// while reporting skin version 1.5.2 — the same 1.5.2 as the repaired 131-byte
// layout in the repository. Four skins had their layout.php replaced wholesale
// with no version change: 50 SHADES, CHAPLIN, GALLERIA and IMPACT PRINTER. The
// sites stayed broken through a fortnight of CMS releases, because the broken
// markup was never in the CMS.
//
// So: change any file in a skin, change its version in the same commit, and
// regenerate the baseline in that same commit:
//
//     php tools/_build/generate-skin-version-baseline.php
//
// The digest lives in that generator and is included here rather than written
// twice, so the baseline and this check cannot drift apart.

$root = dirname(__DIR__);
require_once $root . '/tools/_build/generate-skin-version-baseline.php';

$baselinePath = $root . '/tests/fixtures/skin-version-content-baseline.json';
$baseline = json_decode((string)file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
if (($baseline['schema_version'] ?? null) !== 2) {
    throw new RuntimeException('Skin version/content baseline schema drifted.');
}

$current = snapsmack_skin_version_rows($root);
$recorded = is_array($baseline['skins'] ?? null) ? $baseline['skins'] : [];

// SS-1566: the comparison itself lives in the generator, beside the digest and
// for the same reason. It is reachable from tests/skin-version-gate-shapes-
// regression.php, which drives every shape with crafted rows -- the real
// skins/ folder can only ever produce one of them at a time.
$failures = snapsmack_skin_version_failures($recorded, $current);

// SS-1568, Sean's standing rule of 24 November 2026: a published skin version
// never skips a number. The version on dev is the last PUBLISHED one plus one,
// however many commits touch the skin first. The PM asked for this gate to
// enforce it, and it belongs beside the one-version-one-file-state rule
// because both answer the same question -- can an owner trust what the version
// number tells them.
require_once $root . '/core/skin-published-versions.php';
$published = snapsmack_skin_published_versions($root);
$versions_now = [];
foreach ($current as $skin => $row) { $versions_now[$skin] = (string)($row['version'] ?? ''); }
$failures = array_merge(
    $failures,
    snapsmack_skin_version_skip_failures($published['skins'], $versions_now));

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

printf("Skin version/content contract: PASS (%d skins, one version one file-state).\n",
    count($current));

// ===== SNAPSMACK EOF =====
