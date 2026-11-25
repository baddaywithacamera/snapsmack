<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Record every skin's version and the hash of its files.
 *
 * One published skin version must mean exactly one set of files. A site
 * installs a skin BY VERSION, so two file-states under one number means the
 * sites that took the first are frozen on it for ever.
 *
 * This file owns the hashing. tests/skin-version-content-regression.php
 * includes it rather than reimplementing the digest, so the baseline and the
 * check can never drift apart — which is how the first attempt at this guard
 * produced eleven false failures.
 *
 * Usage:
 *   php tools/_build/generate-skin-version-baseline.php [output.json]
 */

require_once dirname(__DIR__, 2) . '/core/skin-version-digest.php';

/** @return array<string,array{version:string,files_sha256:string,manifest_css_sha256:string}> */
function snapsmack_skin_version_rows(string $root): array {
    $rows = [];
    foreach (glob($root . '/skins/*/manifest.json') ?: [] as $manifestPath) {
        $dir = dirname($manifestPath);
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        $rows[basename($dir)] = [
            'version' => is_array($manifest) ? (string)($manifest['version'] ?? '') : '',
            'files_sha256' => snapsmack_skin_files_digest($dir),
            'manifest_css_sha256' => snapsmack_skin_manifest_css_fingerprint($manifest),
        ];
    }
    ksort($rows, SORT_STRING);
    return $rows;
}

/**
 * Compare the recorded baseline against the tree. Returns the complaints.
 *
 * SS-1566 moved this out of the test script so it could be DRIVEN with crafted
 * rows instead of only ever run against the real skins/ folder, where most of
 * the shapes below cannot be produced on demand. The same reasoning as the
 * digest living here: a check nobody can exercise is a check nobody has.
 *
 * @param array<string,array<string,string>> $recorded the baseline's rows
 * @param array<string,array<string,string>> $current  snapsmack_skin_version_rows()
 * @return list<string>
 */
function snapsmack_skin_version_failures(array $recorded, array $current): array {
    $failures = [];

    foreach ($current as $skin => $now) {
        if ((string)($now['version'] ?? '') === '') {
            $failures[] = "{$skin}: manifest has no version.";
            continue;
        }
        $was = $recorded[$skin] ?? null;
        if (!is_array($was)) {
            $failures[] = "{$skin}: new skin is missing from the baseline — regenerate it in this commit.";
            continue;
        }

        $sameVersion = (string)($was['version'] ?? '') === (string)$now['version'];
        $sameFiles = (string)($was['files_sha256'] ?? '') === (string)($now['files_sha256'] ?? '');
        // 986D: a manifest option's selector/property/default/choices generates the
        // public CSS, so a change to it alters what an installed site renders even
        // though manifest.json is outside the file digest. It counts as content.
        $sameManifestCss = (string)($was['manifest_css_sha256'] ?? '') === (string)($now['manifest_css_sha256'] ?? '');

        if ($sameVersion && $sameFiles && $sameManifestCss) {
            continue;   // the recorded row matches the tree: this skin is covered.
        }

        if ($sameVersion && !$sameFiles) {
            $failures[] = "{$skin}: files changed but the version is still {$now['version']}. "
                . 'Sites install by version, so they would never receive this change. '
                . 'Bump the version and regenerate the baseline in the same commit.';
        } elseif ($sameVersion && !$sameManifestCss) {
            $failures[] = "{$skin}: a manifest option's selector/property/default changed but the "
                . "version is still {$now['version']}. The option generates the public CSS, so sites "
                . 'installed by version would never receive the change (this is exactly how SS-1214 hid '
                . 'the dead masthead selector). Bump the version and regenerate the baseline in the same commit.';
        } elseif (!$sameVersion && $sameFiles && $sameManifestCss) {
            $failures[] = "{$skin}: version moved {$was['version']} -> {$now['version']} with no "
                . 'file or manifest-option change. Regenerate the baseline, or drop the bump.';
        } else {
            // SS-1566. Version AND content both moved, which is what a normal,
            // correct skin change looks like -- and it used to be the one shape
            // that fell off the end of this chain and passed in silence. Nothing
            // then required the baseline to be brought forward, so the row stayed
            // behind. That is not a tidiness complaint: EVERY check above compares
            // against this row, so a row left behind turns the whole gate off for
            // that skin. Measured before the fix, on dev, over the real 30 skins:
            // a row saying 1.0.0 with a digest of zeroes, against a manifest
            // saying 1.3.44, reported PASS -- and then a file edited under an
            // unchanged version, the exact fault this gate exists to catch, ALSO
            // reported PASS.
            $failures[] = "{$skin}: the baseline row is out of date -- it records "
                . "{$was['version']} and the tree has {$now['version']}, with different content. "
                . 'Every check here compares against that row, so leaving it behind switches this '
                . 'gate off for this skin: a later file change under an unchanged version would '
                . 'pass. Run php tools/_build/generate-skin-version-baseline.php and commit the '
                . 'result with the skin change.';
        }
    }

    foreach (array_keys($recorded) as $skin) {
        if (!isset($current[$skin])) {
            $failures[] = "{$skin}: in the baseline but no longer in skins/ — regenerate the baseline.";
        }
    }

    return $failures;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $root = dirname(__DIR__, 2);
    $output = $argv[1] ?? ($root . '/tests/fixtures/skin-version-content-baseline.json');
    $payload = [
        'schema_version' => 2,
        'purpose' => 'One published skin version must mean exactly one set of files. '
            . 'A site installs a skin BY VERSION, so if two different file-states ship '
            . 'under one number, the sites that took the first are frozen on it and no '
            . 'CMS release can move them. That is what left photowalk.ing running the '
            . '0.7.850D 50 SHADES layout while reporting the same 1.5.2 as the repaired '
            . 'one. Change any skin file and change its version in the same commit, then '
            . 'regenerate this file.',
        'skins' => snapsmack_skin_version_rows($root),
    ];
    file_put_contents($output, json_encode(
        $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    printf("Wrote %s (%d skins).\n", $output, count($payload['skins']));
}

// ===== SNAPSMACK EOF =====
