<?php
/**
 * SNAPSMACK - Skin Packager
 * Alpha v0.7.3
 *
 * Packages a skin directory into a distributable zip for the skin registry.
 * Creates {slug}-{version}.zip with {slug}/ as the top-level folder,
 * matching the unwrap logic in core/skin-registry.php skin_registry_install().
 *
 * USAGE:
 *   php package-skin.php <slug>               Package one skin
 *   php package-skin.php --all                Package all skins
 *   php package-skin.php --all --output DIR   Output to specific directory
 *
 * EXAMPLES:
 *   php package-skin.php galleria
 *   php package-skin.php --all --output ./packages/
 */

if (php_sapi_name() !== 'cli') {
    die("This tool must be run from the command line.\n");
}

// ─── PATHS ──────────────────────────────────────────────────────────────────

$project_root = dirname(__DIR__);
$skins_dir    = $project_root . '/skins';

// Files/dirs excluded from the zip
$exclude = ['.git', '.DS_Store', 'Thumbs.db', '.gitkeep', '__MACOSX'];

// ─── ARGUMENT PARSING ───────────────────────────────────────────────────────

$args = parse_args($argv);

if (!$args['slug'] && !$args['all']) {
    echo "SNAPSMACK SKIN PACKAGER\n";
    echo "───────────────────────\n\n";
    echo "Usage:\n";
    echo "  php package-skin.php <slug>               Package one skin\n";
    echo "  php package-skin.php --all                Package all skins\n";
    echo "  php package-skin.php --all --output DIR   Output to specific directory\n\n";
    echo "Examples:\n";
    echo "  php package-skin.php galleria\n";
    echo "  php package-skin.php --all --output ./packages/\n\n";
    exit(0);
}

$output_dir = $args['output'] ?? $project_root;
if (!is_dir($output_dir)) {
    mkdir($output_dir, 0755, true);
}

// ─── BUILD SKIN LIST ────────────────────────────────────────────────────────

$slugs = [];

if ($args['all']) {
    foreach (glob($skins_dir . '/*/manifest.php') as $manifest_path) {
        $slugs[] = basename(dirname($manifest_path));
    }
    sort($slugs);
} else {
    $slugs[] = $args['slug'];
}

if (empty($slugs)) {
    die("ERROR: No skins found.\n");
}

// ─── PACKAGE EACH SKIN ─────────────────────────────────────────────────────

$results = [];
$errors  = 0;

foreach ($slugs as $slug) {
    $skin_path = $skins_dir . '/' . $slug;

    // Validate skin directory
    if (!is_dir($skin_path)) {
        echo "  SKIP  {$slug} — directory not found\n";
        $errors++;
        continue;
    }

    // Validate manifest
    $manifest_file = $skin_path . '/manifest.php';
    if (!file_exists($manifest_file)) {
        echo "  SKIP  {$slug} — no manifest.php\n";
        $errors++;
        continue;
    }

    $manifest = @include $manifest_file;
    if (!is_array($manifest)) {
        echo "  SKIP  {$slug} — manifest.php did not return an array\n";
        $errors++;
        continue;
    }

    $name    = $manifest['name']    ?? ucfirst($slug);
    $version = $manifest['version'] ?? '1.0';
    $status  = $manifest['status']  ?? 'unknown';

    // Build zip filename
    $zip_name = "{$slug}-{$version}.zip";
    $zip_path = rtrim($output_dir, '/') . '/' . $zip_name;

    echo "  PACK  {$slug} v{$version} ({$status})\n";

    // Create zip with {slug}/ as top-level folder
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        echo "  FAIL  Could not create {$zip_path}\n";
        $errors++;
        continue;
    }

    $file_count = add_directory_to_zip($zip, $skin_path, $slug, $exclude);
    $zip->close();

    // Checksum
    $checksum = hash_file('sha256', $zip_path);
    $filesize = filesize($zip_path);

    echo "        → {$zip_name} ({$file_count} files, " . number_format($filesize) . " bytes)\n";
    echo "        → SHA-256: {$checksum}\n";

    $results[] = [
        'slug'     => $slug,
        'name'     => $name,
        'version'  => $version,
        'status'   => $status,
        'zip'      => $zip_name,
        'size'     => $filesize,
        'checksum' => $checksum,
    ];
}

// ─── SUMMARY ────────────────────────────────────────────────────────────────

echo "\n";
echo "Packaged " . count($results) . " skin(s)";
if ($errors > 0) echo ", {$errors} error(s)";
echo ".\n";

if (!empty($results)) {
    echo "Output directory: {$output_dir}\n";
}

exit($errors > 0 ? 1 : 0);


// ─── HELPERS ────────────────────────────────────────────────────────────────

/**
 * Recursively add a directory to a ZipArchive.
 *
 * @param ZipArchive $zip        Open zip archive
 * @param string     $source     Absolute path to source directory
 * @param string     $zip_prefix Prefix inside the zip (e.g. "galleria")
 * @param array      $exclude    Filenames to skip
 * @return int Number of files added
 */
function add_directory_to_zip(ZipArchive $zip, string $source, string $zip_prefix, array $exclude): int {
    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $basename = $item->getBasename();

        // Skip excluded files/dirs
        if (in_array($basename, $exclude, true)) {
            continue;
        }

        // Relative path inside the skin directory
        $relative = substr($item->getPathname(), strlen($source) + 1);
        $zip_path = $zip_prefix . '/' . $relative;

        if ($item->isDir()) {
            $zip->addEmptyDir($zip_path);
        } else {
            $zip->addFile($item->getPathname(), $zip_path);
            $count++;
        }
    }

    return $count;
}

function parse_args(array $argv): array {
    $result = ['slug' => null, 'all' => false, 'output' => null];

    $skip_next = false;
    for ($i = 1; $i < count($argv); $i++) {
        if ($skip_next) { $skip_next = false; continue; }

        $arg = $argv[$i];

        if ($arg === '--all') {
            $result['all'] = true;
        } elseif ($arg === '--output' && isset($argv[$i + 1])) {
            $result['output'] = $argv[$i + 1];
            $skip_next = true;
        } elseif (!str_starts_with($arg, '-') && $result['slug'] === null) {
            $result['slug'] = $arg;
        }
    }

    return $result;
}
