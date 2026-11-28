<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

require_once __DIR__ . '/shipped-review-manifest.php';

/** Remove only unchanged release-owned copies. Never recurse through local content. */
function snapsmack_cleanup_shipped_review_files(string $root): array {
    $removed = [];
    $failed = [];
    if (!is_file($root . '/core/db.php') || is_file($root . '/smack-central/sc-config.php')) {
        return ['removed' => $removed, 'failed' => $failed];
    }

    $root = realpath($root);
    if ($root === false || !is_dir($root . '/_review') || is_link($root . '/_review')) {
        return ['removed' => $removed, 'failed' => $failed];
    }

    $directories = [];
    foreach (SNAPSMACK_SHIPPED_REVIEW_FILES as $path => $hashes) {
        $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        for ($parent = dirname($target); $parent !== $root; $parent = dirname($parent)) {
            $directories[$parent] = true;
        }
        if (!is_file($target) || is_link($target) || realpath($target) !== $target) continue;
        if (!in_array(hash_file('sha256', $target), $hashes, true)) continue;
        if (@unlink($target)) {
            $removed[] = $path;
        } else {
            $failed[] = $path;
        }
    }
    krsort($directories, SORT_STRING);
    foreach (array_keys($directories) as $directory) {
        if (is_dir($directory) && !is_link($directory) && realpath($directory) === $directory) {
            @rmdir($directory); // Empty only. Locally added or edited files stay in place.
        }
    }
    return ['removed' => $removed, 'failed' => $failed];
}

// ===== SNAPSMACK EOF =====
