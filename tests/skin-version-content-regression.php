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
if (($baseline['schema_version'] ?? null) !== 1) {
    throw new RuntimeException('Skin version/content baseline schema drifted.');
}

$current = snapsmack_skin_version_rows($root);
$recorded = is_array($baseline['skins'] ?? null) ? $baseline['skins'] : [];
$failures = [];

foreach ($current as $skin => $now) {
    if (($now['version'] ?? '') === '') {
        $failures[] = "{$skin}: manifest has no version.";
        continue;
    }
    $was = $recorded[$skin] ?? null;
    if ($was === null) {
        $failures[] = "{$skin}: new skin is missing from the baseline — regenerate it in this commit.";
        continue;
    }
    $sameVersion = (string)$was['version'] === (string)$now['version'];
    $sameFiles = (string)$was['files_sha256'] === (string)$now['files_sha256'];

    if ($sameVersion && !$sameFiles) {
        $failures[] = "{$skin}: files changed but the version is still {$now['version']}. "
            . 'Sites install by version, so they would never receive this change. '
            . 'Bump the version and regenerate the baseline in the same commit.';
    } elseif (!$sameVersion && $sameFiles) {
        $failures[] = "{$skin}: version moved {$was['version']} -> {$now['version']} with no "
            . 'file change. Regenerate the baseline, or drop the bump.';
    }
}

foreach (array_keys($recorded) as $skin) {
    if (!isset($current[$skin])) {
        $failures[] = "{$skin}: in the baseline but no longer in skins/ — regenerate the baseline.";
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

printf("Skin version/content contract: PASS (%d skins, one version one file-state).\n",
    count($current));

// ===== SNAPSMACK EOF =====
