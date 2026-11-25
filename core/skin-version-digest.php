<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Line endings are not content.
 *
 * Git stores LF and hands the working tree whatever `core.autocrlf` says, so
 * the same commit is CRLF in one checkout and LF in another. Hashing the bytes
 * as they sit on disk made this check pass in a worktree and fail in the main
 * checkout at the SAME commit — the baseline was written from one and read in
 * the other. That is a harness fault reported as a skin fault, and it cost a
 * release night. Normalise before hashing so the digest is a property of the
 * commit, not of the machine that checked it out.
 */
function snapsmack_skin_canonical_bytes(string $bytes): string {
    return str_replace(["\r\n", "\r"], "\n", $bytes);
}

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
        // Screenshots are catalog/preview assets (Skin Gallery, admin picker,
        // the snapsmack.ca showcase), never served to a site's visitors, so a
        // screenshot change cannot make an installed version render differently --
        // the same reason manifest.json is skipped. Scoped to the screenshot naming
        // ONLY; any other image a stylesheet renders stays in the digest.
        if (preg_match('#^screenshot(-[a-z0-9]+)?\.(png|jpe?g|webp)$#i', $rel)) continue;
        $files[$rel] = $path;
    }
    // One global sort over the relative paths. Per-directory ordering is NOT
    // the same thing once a skin has subfolders.
    ksort($files, SORT_STRING);
    $digest = hash_init('sha256');
    foreach ($files as $rel => $path) {
        hash_update($digest, $rel . "\0");
        hash_update($digest, snapsmack_skin_canonical_bytes((string)file_get_contents($path)));
        hash_update($digest, "\0");
    }
    return hash_final($digest);
}

/**
 * Hash the CSS-AFFECTING fields of a manifest's options. (986D, PM 19:00.)
 *
 * manifest.json is left out of the file digest above because a screenshot or an
 * admin label cannot change what a visitor sees. A SELECTOR or PROPERTY can:
 * SS-1214 repointed NEW HORIZON's header_font_family from .logo-area a to
 * .site-title-text with no version bump, the option generates the public CSS,
 * and the file digest could not see it -- so sites installed by version never
 * got the fix and nothing flagged it. This fingerprints each option's
 * rendering-affecting fields (everything except the admin-only cosmetics named
 * below) so a selector/property/default/choices change moves the digest and the
 * version gate demands a bump, while a pure label or section edit still does not.
 */
function snapsmack_skin_manifest_css_fingerprint(mixed $manifest): string {
    if (!is_array($manifest) || !is_array($manifest['options'] ?? null)) return '';
    $cosmetic = ['label' => 1, 'section' => 1, 'help' => 1, 'description' => 1,
                 'admin_page' => 1, 'group' => 1, 'order' => 1, 'tooltip' => 1,
                 'placeholder' => 1, 'hint' => 1];
    $norm = [];
    foreach ($manifest['options'] as $key => $meta) {
        if (is_array($meta)) {
            $kept = array_diff_key($meta, $cosmetic);
            ksort($kept, SORT_STRING);
            $norm[(string)$key] = $kept;
        } else {
            $norm[(string)$key] = $meta;
        }
    }
    ksort($norm, SORT_STRING);
    return hash('sha256', (string)json_encode($norm));
}

/** Verify a selected skin using the baseline bound to its package source. */
function snapsmack_skin_package_baseline_problem(string $dir, mixed $expected): string {
    $raw = @file_get_contents($dir . '/manifest.json');
    $manifest = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($manifest) || !is_array($expected)
        || !is_string($manifest['version'] ?? null)
        || !preg_match('/^[0-9][a-zA-Z0-9.+-]*$/D', $manifest['version'])
        || ($expected['version'] ?? null) !== $manifest['version']) {
        return 'The selected skin version is missing from or disagrees with the reviewed baseline.';
    }
    if (($expected['files_sha256'] ?? null) !== snapsmack_skin_files_digest($dir)
        || ($expected['manifest_css_sha256'] ?? null) !== snapsmack_skin_manifest_css_fingerprint($manifest)) {
        return 'The selected skin files or rendering options disagree with the reviewed baseline.';
    }
    return '';
}

// ===== SNAPSMACK EOF =====
