<?php
/**
 * SNAPSMACK - Skin Package Builder
 * Alpha v0.7.4
 *
 * Packages individual skins as standalone zip files for optional download
 * from snapsmack.ca/releases/skins/. Users extract into their skins/ directory
 * and the skin appears in the gallery automatically.
 *
 * USAGE:
 *   php build-skin-package.php impact-printer
 *   php build-skin-package.php impact-printer --output ./releases/skins/
 *   php build-skin-package.php --all --output ./releases/skins/
 *
 * REQUIREMENTS:
 *   - Run from within the SnapSmack git repository
 *   - PHP 8.0+ with ZipArchive
 */

if (php_sapi_name() !== 'cli') {
    die("This tool must be run from the command line.\n");
}

// ─── PATHS ──────────────────────────────────────────────────────────────────

$project_root = dirname(__DIR__);
$skins_dir    = $project_root . '/skins';

// ─── ARGUMENT PARSING ───────────────────────────────────────────────────────

$args = parse_args($argv);
$output_dir  = $args['output'] ?? $project_root;
$build_all   = $args['all'] ?? false;
$skin_name   = $args['skin'] ?? null;

if (!$build_all && !$skin_name) {
    echo "ERROR: Specify a skin name or use --all\n\n";
    echo "Usage:\n";
    echo "  php build-skin-package.php <skin-name> [--output DIR]\n";
    echo "  php build-skin-package.php --all [--output DIR]\n\n";
    exit(1);
}

if (!is_dir($output_dir)) {
    mkdir($output_dir, 0755, true);
}

// ─── READ VERSION ───────────────────────────────────────────────────────────

$constants_file = $project_root . '/core/constants.php';
if (!file_exists($constants_file)) {
    die("ERROR: core/constants.php not found. Run from the SnapSmack project root.\n");
}

$constants_content = file_get_contents($constants_file);
preg_match("/SNAPSMACK_VERSION_SHORT',\s*'([^']+)'/", $constants_content, $m_short);
$version_short = $m_short[1] ?? '0.0';

echo "SNAPSMACK SKIN PACKAGE BUILDER\n";
echo "──────────────────────────────\n\n";

// ─── BUILD LIST ─────────────────────────────────────────────────────────────

if ($build_all) {
    $skins = [];
    foreach (glob($skins_dir . '/*/manifest.php') as $manifest) {
        $skins[] = basename(dirname($manifest));
    }
    sort($skins);
} else {
    if (!is_dir($skins_dir . '/' . $skin_name)) {
        die("ERROR: Skin '{$skin_name}' not found in skins/\n");
    }
    $skins = [$skin_name];
}

echo "  Skins to package: " . implode(', ', $skins) . "\n\n";

// ─── PACKAGE EACH SKIN ─────────────────────────────────────────────────────

$results = [];

foreach ($skins as $skin) {
    $skin_path = $skins_dir . '/' . $skin;

    // Read skin metadata from manifest
    $manifest_path = $skin_path . '/manifest.php';
    $meta = extract_skin_meta($manifest_path);

    $zip_name = "snapsmack-skin-{$skin}.zip";
    $zip_path = rtrim($output_dir, '/') . '/' . $zip_name;

    echo "  Packaging: {$skin}";
    if ($meta['label']) echo " ({$meta['label']})";
    echo "\n";

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        echo "    ERROR: Could not create {$zip_path}\n";
        continue;
    }

    // All files go inside skins/{skin-name}/ so users can extract at web root
    $wrapper = "skins/{$skin}/";
    $file_count = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($skin_path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;

        $relative = str_replace($skin_path . DIRECTORY_SEPARATOR, '', $file->getRealPath());
        $relative = str_replace('\\', '/', $relative);

        $zip->addFile($file->getRealPath(), $wrapper . $relative);
        $file_count++;
    }

    $zip->close();

    $checksum = hash_file('sha256', $zip_path);
    $filesize = filesize($zip_path);

    echo "    Files: {$file_count}  Size: " . number_format($filesize) . " bytes  SHA-256: " . substr($checksum, 0, 16) . "...\n";

    $results[] = [
        'skin'       => $skin,
        'label'      => $meta['label'],
        'status'     => $meta['status'],
        'zip'        => $zip_name,
        'checksum'   => $checksum,
        'size'       => $filesize,
        'files'      => $file_count,
    ];
}

// ─── GENERATE SKINS MANIFEST ───────────────────────────────────────────────

$manifest_entries = [];
foreach ($results as $r) {
    $manifest_entries[$r['skin']] = [
        'label'           => $r['label'],
        'status'          => $r['status'],
        'download_url'    => "https://snapsmack.ca/releases/skins/{$r['zip']}",
        'checksum_sha256' => $r['checksum'],
        'download_size'   => $r['size'],
        'compatible_with' => $version_short,
    ];
}

$skins_json_path = rtrim($output_dir, '/') . '/skins.json';
$skins_manifest = [
    'generated'   => date('Y-m-d H:i:s'),
    'base_url'    => 'https://snapsmack.ca/releases/skins/',
    'skins'       => $manifest_entries,
];

file_put_contents($skins_json_path, json_encode($skins_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo "\n  Manifest: skins.json\n";

// ─── SUMMARY ────────────────────────────────────────────────────────────────

echo "\n";
echo "NEXT STEPS\n";
echo "══════════\n";
echo "  1. Upload skin zips to https://snapsmack.ca/releases/skins/\n";
echo "  2. Upload skins.json to https://snapsmack.ca/releases/skins/\n";
echo "  3. Users extract the zip at their web root (creates skins/{name}/)\n\n";

echo "Done. Packaged " . count($results) . " skin(s).\n";

exit(0);


// ─── HELPERS ────────────────────────────────────────────────────────────────

function extract_skin_meta(string $manifest_path): array {
    $meta = ['label' => '', 'status' => 'unknown'];

    if (!file_exists($manifest_path)) return $meta;

    $content = file_get_contents($manifest_path);

    if (preg_match("/'label'\s*=>\s*'([^']+)'/", $content, $m)) {
        $meta['label'] = $m[1];
    }
    if (preg_match("/'status'\s*=>\s*'([^']+)'/", $content, $m)) {
        $meta['status'] = $m[1];
    }

    return $meta;
}

function parse_args(array $argv): array {
    $result = ['output' => null, 'all' => false, 'skin' => null];

    $skip_next = false;
    for ($i = 1; $i < count($argv); $i++) {
        if ($skip_next) { $skip_next = false; continue; }

        $arg = $argv[$i];

        if ($arg === '--output' && isset($argv[$i + 1])) {
            $result['output'] = $argv[$i + 1];
            $skip_next = true;
        } elseif ($arg === '--all') {
            $result['all'] = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            echo "SNAPSMACK SKIN PACKAGE BUILDER\n";
            echo "──────────────────────────────\n\n";
            echo "Packages individual skins as standalone zip files.\n\n";
            echo "Usage:\n";
            echo "  php build-skin-package.php <skin-name> [--output DIR]\n";
            echo "  php build-skin-package.php --all [--output DIR]\n\n";
            echo "Options:\n";
            echo "  --output DIR   Output directory (default: project root)\n";
            echo "  --all          Package every skin in skins/\n\n";
            echo "Examples:\n";
            echo "  php build-skin-package.php impact-printer\n";
            echo "  php build-skin-package.php --all --output ./releases/skins/\n\n";
            exit(0);
        } elseif (!str_starts_with($arg, '-') && $result['skin'] === null) {
            $result['skin'] = $arg;
        }
    }

    return $result;
}
