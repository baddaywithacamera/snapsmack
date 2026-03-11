<?php
/**
 * SNAPSMACK - Registry Generator
 * Alpha v0.7.1
 *
 * Builds registry.json from local skin manifests. The output matches the
 * format consumed by core/skin-registry.php skin_registry_fetch().
 *
 * USAGE:
 *   php generate-registry.php                          Generate with defaults
 *   php generate-registry.php --packages-dir DIR       Read zip sizes from DIR
 *   php generate-registry.php --output registry.json   Write to specific file
 *
 * EXAMPLES:
 *   php generate-registry.php --packages-dir ./packages/ --output registry.json
 */

if (php_sapi_name() !== 'cli') {
    die("This tool must be run from the command line.\n");
}

// ─── PATHS ──────────────────────────────────────────────────────────────────

$project_root = dirname(__DIR__);
$skins_dir    = $project_root . '/skins';

// Base URLs for hosted assets on snapsmack.ca
$base_url_skins       = 'https://snapsmack.ca/skins';
$screenshot_base      = $base_url_skins . '/screenshots';
$package_base         = $base_url_skins . '/packages';

// ─── ARGUMENT PARSING ───────────────────────────────────────────────────────

$args = parse_args($argv);

if ($args['help']) {
    echo "SNAPSMACK REGISTRY GENERATOR\n";
    echo "────────────────────────────\n\n";
    echo "Usage:\n";
    echo "  php generate-registry.php [options]\n\n";
    echo "Options:\n";
    echo "  --packages-dir DIR   Read zip file sizes from DIR\n";
    echo "  --output FILE        Write registry JSON to FILE (default: stdout)\n";
    echo "  --help               Show this help\n\n";
    echo "Examples:\n";
    echo "  php generate-registry.php --packages-dir ./packages/ --output registry.json\n\n";
    exit(0);
}

$packages_dir = $args['packages-dir'] ?? null;
$output_file  = $args['output'] ?? null;

// ─── SCAN MANIFESTS ─────────────────────────────────────────────────────────

$manifest_files = glob($skins_dir . '/*/manifest.php');
if (empty($manifest_files)) {
    die("ERROR: No skin manifests found in {$skins_dir}\n");
}

sort($manifest_files);

$skins   = [];
$skipped = 0;

foreach ($manifest_files as $manifest_path) {
    $slug = basename(dirname($manifest_path));

    // Load manifest — it returns an array
    $manifest = @include $manifest_path;
    if (!is_array($manifest)) {
        echo "  SKIP  {$slug} — manifest.php did not return an array\n";
        $skipped++;
        continue;
    }

    $name        = $manifest['name']        ?? ucfirst($slug);
    $version     = $manifest['version']     ?? '1.0';
    $status      = $manifest['status']      ?? 'stable';
    $author      = $manifest['author']      ?? 'Unknown';
    $description = $manifest['description'] ?? '';
    $features    = $manifest['features']    ?? [];

    // Build the registry entry matching core/skin-registry.php format
    $entry = [
        'name'                => $name,
        'version'             => $version,
        'status'              => $status,
        'author'              => $author,
        'description'         => $description,
        'screenshot'          => $screenshot_base . '/' . $slug . '.png',
        'download_url'        => $package_base . '/' . $slug . '-' . $version . '.zip',
        'download_size'       => 0,
        'signature'           => '',
        'requires_php'        => $manifest['requires_php'] ?? '8.0',
        'requires_snapsmack'  => $manifest['requires_snapsmack'] ?? '0.7',
        'features'            => $features,
    ];

    // If packages dir provided, read actual zip size
    if ($packages_dir) {
        $zip_name = $slug . '-' . $version . '.zip';
        $zip_path = rtrim($packages_dir, '/') . '/' . $zip_name;
        if (file_exists($zip_path)) {
            $entry['download_size'] = filesize($zip_path);
        } else {
            echo "  WARN  {$slug} — zip not found: {$zip_name}\n";
        }
    }

    // Development skins are listed but not installable
    if ($status === 'development') {
        $entry['installable'] = false;
    }

    echo "  ADD   {$slug} v{$version} ({$status})\n";
    $skins[$slug] = $entry;
}

// ─── BUILD REGISTRY ─────────────────────────────────────────────────────────

$registry = [
    'registry_version' => 1,
    'generated'        => gmdate('Y-m-d\TH:i:s\Z'),
    'skins'            => $skins,
];

$json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if (!$json) {
    die("ERROR: JSON encoding failed — " . json_last_error_msg() . "\n");
}

// ─── OUTPUT ─────────────────────────────────────────────────────────────────

if ($output_file) {
    $output_dir = dirname($output_file);
    if (!is_dir($output_dir)) {
        mkdir($output_dir, 0755, true);
    }
    file_put_contents($output_file, $json . "\n");
    echo "\nRegistry written to {$output_file}\n";
} else {
    echo "\n" . $json . "\n";
}

echo "\n" . count($skins) . " skin(s) registered";
if ($skipped > 0) echo ", {$skipped} skipped";
echo ".\n";


// ─── HELPERS ────────────────────────────────────────────────────────────────

function parse_args(array $argv): array {
    $result = ['packages-dir' => null, 'output' => null, 'help' => false];

    $skip_next = false;
    for ($i = 1; $i < count($argv); $i++) {
        if ($skip_next) { $skip_next = false; continue; }

        $arg = $argv[$i];

        if ($arg === '--help' || $arg === '-h') {
            $result['help'] = true;
        } elseif ($arg === '--packages-dir' && isset($argv[$i + 1])) {
            $result['packages-dir'] = $argv[$i + 1];
            $skip_next = true;
        } elseif ($arg === '--output' && isset($argv[$i + 1])) {
            $result['output'] = $argv[$i + 1];
            $skip_next = true;
        }
    }

    return $result;
}
