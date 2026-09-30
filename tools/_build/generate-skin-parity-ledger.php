<?php
declare(strict_types=1);

/**
 * Build a deterministic, machine-readable inventory of the skin presentation
 * that existed before the constrained-runtime conversion.
 */

$root = dirname(__DIR__, 2);
$baseline = $argv[1] ?? 'v0.7.772D';
$destination = $argv[2] ?? ($root . '/outputs/skin-parity-' . str_replace('/', '-', $baseline) . '.json');

function git_output(string $root, array $arguments): string
{
    $command = ['git', '-C', $root];
    foreach ($arguments as $argument) {
        $command[] = $argument;
    }
    $escaped = implode(' ', array_map('escapeshellarg', $command));
    exec($escaped . ' 2>&1', $lines, $status);
    if ($status !== 0) {
        throw new RuntimeException("Git command failed: {$escaped}\n" . implode("\n", $lines));
    }
    return implode("\n", $lines);
}

function sorted_unique(array $values): array
{
    $values = array_values(array_unique(array_filter($values, static fn ($value): bool => $value !== '')));
    sort($values, SORT_STRING);
    return $values;
}

function matches(string $source, string $pattern, int $group = 1): array
{
    preg_match_all($pattern, $source, $found);
    return sorted_unique($found[$group] ?? []);
}

function classify_authority(string $source): array
{
    $rules = [
        'database' => '/\b(?:PDO|mysqli|->(?:query|prepare|execute|fetch|fetchAll))\b/i',
        'filesystem' => '/\b(?:file_get_contents|file_put_contents|fopen|glob|is_file|is_dir|unlink|rename|copy|mkdir|scandir)\s*\(/i',
        'network' => '/\b(?:curl_[a-z_]+|fsockopen|stream_socket_client)\s*\(/i',
        'request' => '/\$_(?:GET|POST|REQUEST|COOKIE|FILES|SERVER|ENV|SESSION)\b/',
        'response' => '/\b(?:header|setcookie|http_response_code)\s*\(/i',
        'dynamic-code' => '/\b(?:eval|assert|include|include_once|require|require_once)\b/i',
        'process' => '/\b(?:exec|shell_exec|system|passthru|proc_open|popen)\s*\(/i',
    ];
    $found = [];
    foreach ($rules as $name => $pattern) {
        if (preg_match($pattern, $source) === 1) {
            $found[] = $name;
        }
    }
    return $found;
}

$treeLines = preg_split('/\R/', trim(git_output($root, ['ls-tree', '-r', $baseline, '--', 'skins']))) ?: [];
$baselineFiles = [];
$blobByFile = [];
foreach ($treeLines as $line) {
    if (preg_match('/^\d+ blob ([0-9a-f]+)\t(.+)$/', $line, $match) === 1) {
        $blobByFile[$match[2]] = $match[1];
        $baselineFiles[] = $match[2];
    }
}

$descriptorSpec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open(['git', '-C', $root, 'cat-file', '--batch'], $descriptorSpec, $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start git object reader.');
}
$sources = [];
foreach ($blobByFile as $file => $object) {
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!in_array($extension, ['php', 'css', 'js', 'json'], true)) {
        continue;
    }
    fwrite($pipes[0], $object . "\n");
    $header = trim((string) fgets($pipes[1]));
    if (preg_match('/^[0-9a-f]+ blob (\d+)$/', $header, $match) !== 1) {
        throw new RuntimeException("Could not read baseline object for {$file}: {$header}");
    }
    $length = (int) $match[1];
    $source = '';
    while (strlen($source) < $length) {
        $chunk = fread($pipes[1], $length - strlen($source));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException("Truncated baseline object for {$file}.");
        }
        $source .= $chunk;
    }
    fgets($pipes[1]);
    $sources[$file] = $source;
}
fclose($pipes[0]);
fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
if (proc_close($process) !== 0) {
    throw new RuntimeException('Git object reader failed: ' . $stderr);
}
$skinNames = [];
foreach ($baselineFiles as $file) {
    if (preg_match('#^skins/([^/]+)/#', $file, $match) === 1) {
        $skinNames[] = $match[1];
    }
}
$baselineSkinNames = sorted_unique($skinNames);
$currentManifests = glob($root . '/skins/*/manifest.json') ?: [];
$currentSkinNames = array_map(static fn (string $manifest): string => basename(dirname($manifest)), $currentManifests);
$currentSkinNames = sorted_unique($currentSkinNames);
$skinNames = array_values(array_intersect($baselineSkinNames, $currentSkinNames));
$baselineOnlySkins = array_values(array_diff($baselineSkinNames, $currentSkinNames));
$currentOnlySkins = array_values(array_diff($currentSkinNames, $baselineSkinNames));

$ledger = [
    'schema' => 'snapsmack.skin-parity-ledger.v1',
    'baseline' => $baseline,
    'baseline_commit' => trim(git_output($root, ['rev-parse', $baseline . '^{}'])),
    'skin_count' => count($skinNames),
    'baseline_only_skins' => $baselineOnlySkins,
    'current_only_skins' => $currentOnlySkins,
    'skins' => [],
];

foreach ($skinNames as $skin) {
    $prefix = 'skins/' . $skin . '/';
    $files = array_values(array_filter($baselineFiles, static fn (string $file): bool => str_starts_with($file, $prefix)));
    sort($files, SORT_STRING);
    $entry = [
        'baseline_files' => [],
        'templates' => [],
        'stylesheets' => [],
        'scripts' => [],
        'manifest_option_keys' => [],
        'settings_read' => [],
        'dom_classes' => [],
        'dom_ids' => [],
        'asset_handles' => [],
        'authority_findings' => [],
    ];

    foreach ($files as $file) {
        $relative = substr($file, strlen($prefix));
        $entry['baseline_files'][$relative] = $blobByFile[$file];
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        $source = $sources[$file] ?? '';
        if ($extension === 'php') {
            $entry['templates'][] = $relative;
            $entry['settings_read'] = array_merge($entry['settings_read'], matches($source, '/\$settings\s*\[\s*[\'\"]([^\'\"]+)[\'\"]\s*\]/'));
            $entry['dom_classes'] = array_merge($entry['dom_classes'], matches($source, '/\bclass\s*=\s*[\'\"]([^\'\"]+)[\'\"]/i'));
            $entry['dom_ids'] = array_merge($entry['dom_ids'], matches($source, '/\bid\s*=\s*[\'\"]([^\'\"]+)[\'\"]/i'));
            $entry['asset_handles'] = array_merge($entry['asset_handles'], matches($source, '/\b(?:snap_require_script|snap_require_style)\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/i'));
            $authority = classify_authority($source);
            if ($authority !== []) {
                $entry['authority_findings'][$relative] = $authority;
            }
        } elseif ($extension === 'css') {
            $entry['stylesheets'][] = $relative;
        } elseif ($extension === 'js') {
            $entry['scripts'][] = $relative;
        } elseif ($relative === 'manifest.json') {
            $manifest = json_decode($source, true, flags: JSON_THROW_ON_ERROR);
            $entry['manifest_option_keys'] = array_keys(is_array($manifest['options'] ?? null) ? $manifest['options'] : []);
            $entry['asset_handles'] = array_merge(
                $entry['asset_handles'],
                is_array($manifest['require_scripts'] ?? null) ? $manifest['require_scripts'] : [],
                is_array($manifest['require_styles'] ?? null) ? $manifest['require_styles'] : []
            );
        }
    }

    foreach (['templates', 'stylesheets', 'scripts', 'manifest_option_keys', 'settings_read', 'dom_classes', 'dom_ids', 'asset_handles'] as $key) {
        $entry[$key] = sorted_unique($entry[$key]);
    }
    ksort($entry['baseline_files'], SORT_STRING);
    ksort($entry['authority_findings'], SORT_STRING);
    $ledger['skins'][$skin] = $entry;
}

ksort($ledger['skins'], SORT_STRING);
$json = json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0775, true) && !is_dir(dirname($destination))) {
    throw new RuntimeException('Could not create destination directory.');
}
file_put_contents($destination, $json);
echo "Wrote {$destination} ({$ledger['skin_count']} skins)\n";
