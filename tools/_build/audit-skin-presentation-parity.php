<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Read-only presentation-parity diagnostic.
 *
 * Finds literal class hooks that:
 *   1. were emitted by a skin's PHP at a pre-migration Git reference,
 *   2. are still selected by that skin's current CSS, and
 *   3. are not emitted by its current PHP or the shared skin render helper.
 *
 * The result is a triage inventory, not a defect verdict. Dynamic class names,
 * intentionally retired markup, and selectors used only by scripts can create
 * false positives and must be classified by a human before restoration.
 *
 * Usage:
 *   php tools/_build/audit-skin-presentation-parity.php [baseline-ref] [output.json]
 */

$root = dirname(__DIR__, 2);
$baselineRef = $argv[1] ?? 'v0.7.772D';
$outputPath = $argv[2] ?? ($root . '/outputs/skin-presentation-recovery-audit.json');

if (!preg_match('/^[A-Za-z0-9._\/-]+$/', $baselineRef)) {
    fwrite(STDERR, "Invalid baseline ref.\n");
    exit(2);
}

/** @return list<string> */
function run_git_lines(string $root, array $arguments): array
{
    $command = array_merge(['git', '-C', $root], $arguments);
    $escaped = array_map('escapeshellarg', $command);
    $output = [];
    $exitCode = 0;
    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    exec(implode(' ', $escaped) . ' 2>' . $nullDevice, $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException('Git command failed: ' . implode(' ', $arguments));
    }

    return array_values(array_filter(array_map('trim', $output), static fn(string $line): bool => $line !== ''));
}

function git_file(string $root, string $ref, string $path): string
{
    $command = ['git', '-C', $root, 'show', $ref . ':' . str_replace('\\', '/', $path)];
    $escaped = array_map('escapeshellarg', $command);
    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $content = shell_exec(implode(' ', $escaped) . ' 2>' . $nullDevice);
    if (!is_string($content)) {
        throw new RuntimeException('Unable to read ' . $path . ' from ' . $ref);
    }

    return $content;
}

/** @return list<string> */
function literal_html_classes(string $source): array
{
    preg_match_all('/\bclass\s*=\s*(["\'])(.*?)\1/is', $source, $matches);
    $classes = [];
    foreach ($matches[2] ?? [] as $attribute) {
        preg_match_all('/(?<![A-Za-z0-9_-])[A-Za-z_][A-Za-z0-9_-]*/', (string)$attribute, $tokens);
        foreach ($tokens[0] ?? [] as $token) {
            if (!in_array($token, ['php', 'echo', 'if', 'else', 'endif', 'true', 'false'], true)) {
                $classes[$token] = true;
            }
        }
    }

    $result = array_keys($classes);
    sort($result, SORT_STRING);
    return $result;
}

/** @return list<string> */
function css_class_selectors(string $source): array
{
    $withoutComments = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;
    preg_match_all('/(?<![A-Za-z0-9_-])\.([A-Za-z_][A-Za-z0-9_-]*)/', $withoutComments, $matches);
    $classes = array_values(array_unique($matches[1] ?? []));
    sort($classes, SORT_STRING);
    return $classes;
}

/** @return list<string> */
function current_files(string $directory, string $extension): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === $extension) {
            $files[] = $file->getPathname();
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

try {
    run_git_lines($root, ['rev-parse', '--verify', $baselineRef . '^{commit}']);

    $skinDirectories = glob($root . '/skins/*', GLOB_ONLYDIR) ?: [];
    sort($skinDirectories, SORT_STRING);
    $sharedHelper = (string)file_get_contents($root . '/core/skin-render-helpers.php');
    $sharedClasses = literal_html_classes($sharedHelper);
    $cmsOwnedComponents = [
        '50-shades-of-noah-grey' => [$root . '/core/50-shades-page-component.php'],
        'chaplin' => [$root . '/core/chaplin-page-component.php'],
        'galleria' => [$root . '/core/galleria-page-component.php'],
        'impact-printer' => [$root . '/core/impact-printer-page-component.php'],
    ];
    $skins = [];
    $totalCandidates = 0;

    foreach ($skinDirectories as $skinDirectory) {
        $slug = basename($skinDirectory);
        $baselinePrefix = 'skins/' . $slug . '/';
        $baselineFiles = array_values(array_filter(
            run_git_lines($root, ['ls-tree', '-r', '--name-only', $baselineRef, '--', $baselinePrefix]),
            static fn(string $path): bool => str_ends_with(strtolower($path), '.php')
        ));
        if ($baselineFiles === []) {
            continue;
        }

        $baselineClasses = [];
        foreach ($baselineFiles as $path) {
            foreach (literal_html_classes(git_file($root, $baselineRef, $path)) as $class) {
                $baselineClasses[$class] = true;
            }
        }

        $currentPhpFiles = current_files($skinDirectory, 'php');
        $currentClasses = array_fill_keys($sharedClasses, true);
        if (isset($cmsOwnedComponents[$slug])) {
            foreach ($cmsOwnedComponents[$slug] as $componentFile) {
                foreach (literal_html_classes((string)file_get_contents($componentFile)) as $class) {
                    $currentClasses[$class] = true;
                }
            }
        }
        foreach ($currentPhpFiles as $path) {
            foreach (literal_html_classes((string)file_get_contents($path)) as $class) {
                $currentClasses[$class] = true;
            }
        }

        $cssFiles = current_files($skinDirectory, 'css');
        $cssClasses = [];
        foreach ($cssFiles as $path) {
            foreach (css_class_selectors((string)file_get_contents($path)) as $class) {
                $cssClasses[$class] = true;
            }
        }

        $candidates = array_values(array_intersect(
            array_keys($baselineClasses),
            array_keys($cssClasses),
            array_diff(array_keys($baselineClasses), array_keys($currentClasses))
        ));
        sort($candidates, SORT_STRING);
        $totalCandidates += count($candidates);

        $skins[$slug] = [
            'candidate_count' => count($candidates),
            'candidate_classes' => $candidates,
            'baseline_php_files' => array_map(
                static fn(string $path): string => str_replace('\\', '/', $path),
                $baselineFiles
            ),
            'current_php_file_count' => count($currentPhpFiles),
            'current_css_file_count' => count($cssFiles),
        ];
    }

    uasort($skins, static function (array $left, array $right): int {
        return ($right['candidate_count'] <=> $left['candidate_count']);
    });

    $report = [
        'schema_version' => 1,
        'generated_at_utc' => gmdate(DATE_ATOM),
        'baseline_ref' => $baselineRef,
        'head' => run_git_lines($root, ['rev-parse', 'HEAD'])[0] ?? '',
        'method' => 'baseline literal PHP classes intersect current CSS classes minus current skin/shared-helper literal PHP classes',
        'warning' => 'Triage candidates only. Dynamic classes and intentionally retired markup require human classification.',
        'skin_count' => count($skins),
        'candidate_total' => $totalCandidates,
        'skins' => $skins,
    ];

    $outputDirectory = dirname($outputPath);
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException('Unable to create output directory: ' . $outputDirectory);
    }
    $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (file_put_contents($outputPath, $encoded) === false) {
        throw new RuntimeException('Unable to write report: ' . $outputPath);
    }

    echo 'Wrote ' . $outputPath . PHP_EOL;
    echo 'Skins: ' . count($skins) . '; candidates: ' . $totalCandidates . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}

// ===== SNAPSMACK EOF =====
