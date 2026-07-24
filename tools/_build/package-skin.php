<?php
/**
 * SNAPSMACK - Skin Packager
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

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


if (php_sapi_name() !== 'cli') {
    die("This tool must be run from the command line.\n");
}

// ─── PATHS ──────────────────────────────────────────────────────────────────

$project_root = dirname(__DIR__, 2);
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
    foreach (glob($skins_dir . '/*/manifest.json') as $manifest_path) {
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
    $manifest_file = $skin_path . '/manifest.json';
    if (!file_exists($manifest_file)) {
        echo "  SKIP  {$slug} — no manifest.json\n";
        $errors++;
        continue;
    }

    $manifest = json_decode((string)file_get_contents($manifest_file), true);
    if (!is_array($manifest)) {
        echo "  SKIP  {$slug} — manifest.json is invalid\n";
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

    $file_count   = add_directory_to_zip($zip, $skin_path, $slug, $exclude);
    $smack_hashes = collect_smackback_hashes($skin_path, $slug, $exclude);

    // Add SMACKBACK manifest before signing
    $smackback_manifest = json_encode([
        'smackback_version' => 1,
        'package_version'   => $version,
        'skin_id'           => $slug,
        'generated_at'      => gmdate('Y-m-d\TH:i:s\Z'),
        'files'             => $smack_hashes,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $zip->addFromString('smackback-manifest.json', $smackback_manifest);

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

/**
 * Collect SHA-256 hashes of all monitored skin files for the SMACKBACK manifest.
 * Returns array keyed by zip-relative path (e.g. "slug/header.php").
 *
 * @param  string   $source      Absolute path to skin directory on disk.
 * @param  string   $zip_prefix  Zip prefix (skin slug, e.g. "chaplin").
 * @param  string[] $exclude     Filenames to exclude (same list as add_directory_to_zip).
 * @return array<string, array{hash: string, size: int, eof_signature: string|null}>
 */
function collect_smackback_hashes(string $source, string $zip_prefix, array $exclude): array {
    $hashes   = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }
        if (in_array($item->getBasename(), $exclude, true)) {
            continue;
        }

        $ext = strtolower($item->getExtension());
        if (!in_array($ext, ['php', 'css', 'js'], true)) {
            continue;
        }

        $basename = $item->getBasename();
        if (str_ends_with($basename, '.min.js') || str_ends_with($basename, '.min.css')) {
            continue;
        }

        $relative = substr($item->getPathname(), strlen($source) + 1);
        $zip_path = $zip_prefix . '/' . str_replace('\\', '/', $relative);
        $content  = file_get_contents($item->getPathname());

        if ($content !== false) {
            $hashes[$zip_path] = [
                'hash'          => hash('sha256', $content),
                'size'          => strlen($content),
                'eof_signature' => smackback_build_eof_signature($content),
            ];
        }
    }

    return $hashes;
}

/**
 * Compute the EOF signature for a file's content string.
 * Mirrors smackback_get_eof_signature() in core/smackback.php.
 */
function smackback_build_eof_signature(string $content): ?string {
    if ($content === '') { return null; }
    if (strpos($content, "\x00") !== false) { return 'NULL_BYTES'; }
    $tail  = substr($content, -1024);
    $lines = explode("\n", $tail);
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = rtrim($lines[$i]);
        if ($line !== '') { return substr($line, 0, 512); }
    }
    return null;
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
// ===== SNAPSMACK EOF =====
