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

/** Hash every file in a skin except its manifest: sorted path, then bytes. */
function snapsmack_skin_files_digest(string $dir): string {
    $dir = rtrim(str_replace('\\', '/', $dir), '/');
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $path = str_replace('\\', '/', $file->getPathname());
        $rel = ltrim(substr($path, strlen($dir)), '/');
        if ($rel === 'manifest.json') continue;
        $files[$rel] = $path;
    }
    // One global sort over the relative paths. Per-directory ordering is NOT
    // the same thing once a skin has subfolders.
    ksort($files, SORT_STRING);
    $digest = hash_init('sha256');
    foreach ($files as $rel => $path) {
        hash_update($digest, $rel . "\0");
        hash_update($digest, (string)file_get_contents($path));
        hash_update($digest, "\0");
    }
    return hash_final($digest);
}

/** @return array<string,array{version:string,files_sha256:string}> */
function snapsmack_skin_version_rows(string $root): array {
    $rows = [];
    foreach (glob($root . '/skins/*/manifest.json') ?: [] as $manifestPath) {
        $dir = dirname($manifestPath);
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        $rows[basename($dir)] = [
            'version' => is_array($manifest) ? (string)($manifest['version'] ?? '') : '',
            'files_sha256' => snapsmack_skin_files_digest($dir),
        ];
    }
    ksort($rows, SORT_STRING);
    return $rows;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $root = dirname(__DIR__, 2);
    $output = $argv[1] ?? ($root . '/tests/fixtures/skin-version-content-baseline.json');
    $payload = [
        'schema_version' => 1,
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
