<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/custom-code-policy.php';

function rbr_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @return list<string> */
function rbr_production_php_files(string $root): array
{
    $output = shell_exec('git -C ' . escapeshellarg($root) . ' ls-files -co --exclude-standard -z -- "*.php"');
    rbr_expect(is_string($output), 'Could not enumerate PHP reconstruction surfaces.');
    return array_values(array_filter(explode("\0", $output), static function (string $file): bool {
        $file = str_replace('\\', '/', $file);
        return $file !== '' && !str_starts_with($file, 'tests/') && !str_starts_with($file, 'test/')
            && !str_contains($file, '/tests/') && !str_contains($file, '/fixtures/');
    }));
}

$root = dirname(__DIR__);
$files = rbr_production_php_files($root);
$calls = ['serialize' => [], 'unserialize' => [], 'var_export' => []];
$sessionObjectAssignments = [];
foreach ($files as $file) {
    $source = file_get_contents($root . '/' . $file);
    rbr_expect(is_string($source), "Could not read {$file}.");
    foreach ($calls as $function => $_) {
        $count = preg_match_all('/(?<![A-Za-z0-9_])' . preg_quote($function, '/') . '\s*\(/', $source);
        if ($count > 0) $calls[$function][$file] = $count;
    }
    if (preg_match('/\$_SESSION\s*\[[^\]]+\]\s*=\s*new\s+/s', $source)
        || preg_match('/\$_SESSION\s*\[[^\]]+\]\s*=.{0,300}(SnapTrustedHtml|SnapOwnerCode)/s', $source)) {
        $sessionObjectAssignments[] = $file;
    }
}

$expectedCalls = [
    'serialize' => [],
    'unserialize' => [],
    'var_export' => [
        'core/fediverse.php' => 1,
        'install.php' => 8,
        'tools/_build/generate-skin-visibility-baseline.php' => 1,
    ],
];
rbr_expect($calls === $expectedCalls,
    "Serialization/export inventory changed.\nExpected: " . var_export($expectedCalls, true)
    . "\nActual: " . var_export($calls, true));
rbr_expect($sessionObjectAssignments === [],
    'PHP session state must not store objects or privileged wrappers: ' . implode(', ', $sessionObjectAssignments));

// Executable PHP generation is not a runtime object cache. Keep every such
// reconstruction-like site explicit so it cannot be mistaken for one later.
$phpFileWriters = [
    'install.php' => [
        'origin' => 'installer/recovery form scalar database credentials',
        'integrity' => 'authenticated install ceremony; PHP literals produced by var_export',
        'consumer' => 'core/db.php bootstrap',
        'failure' => 'installer records an error; recovery writes only when db.php is absent',
    ],
    'smack-central/sc-release.php' => [
        'origin' => 'verified release archive fedup.php plus validated hex public key',
        'integrity' => 'release construction and signing boundary',
        'consumer' => 'standalone FEDISTRUCTURE updater bootstrap',
        'failure' => 'release construction aborts',
    ],
    'smack-central/sc-update.php' => [
        'origin' => 'validated fetched tag and JSON codename',
        'integrity' => 'Smack Central authenticated updater boundary; addslashes literal encoding',
        'consumer' => 'Smack Central version bootstrap',
        'failure' => 'update result reports failure; no wrapper reconstruction',
    ],
    'tools/_build/generate-skin-visibility-baseline.php' => [
        'origin' => 'local tokenizer findings arrays',
        'integrity' => 'developer-only build tool; scalar arrays only',
        'consumer' => 'legacy visibility policy include',
        'failure' => 'build command fails; file is obsolete after schema-v1 removal',
    ],
];
foreach (array_keys($phpFileWriters) as $file) {
    rbr_expect(in_array($file, $files, true), "Executable-PHP writer inventory references missing {$file}.");
}

$trusted = snapsmack_trusted_html('<strong>safe</strong>');
$owner = SnapOwnerCode::__snapsmackCmsOnly('<script>owner()</script>');
foreach ([$trusted, $owner] as $wrapper) {
    $class = get_class($wrapper);
    try {
        serialize($wrapper);
        throw new RuntimeException("{$class} serialized successfully.");
    } catch (LogicException $exception) {
        rbr_expect(str_contains($exception->getMessage(), 'cannot be serialized'), "{$class} failed serialization unexpectedly.");
    }

    $forged = 'O:' . strlen($class) . ':"' . $class . '":0:{}';
    try {
        unserialize($forged, ['allowed_classes' => [$class]]);
        throw new RuntimeException("Forged {$class} payload reconstructed successfully.");
    } catch (LogicException $exception) {
        rbr_expect(str_contains($exception->getMessage(), 'cannot be reconstructed'), "{$class} forged payload failed unexpectedly.");
    }

    $export = var_export($wrapper, true);
    try {
        eval('return ' . $export . ';');
        throw new RuntimeException("Exported {$class} rebuilt successfully.");
    } catch (LogicException $exception) {
        rbr_expect(str_contains($exception->getMessage(), 'cannot be exported'), "{$class} export failed unexpectedly.");
    }
}

// Stale scalar/array data remains ordinary untrusted data and is escaped by the
// output boundary rather than promoted after a reconstruction failure.
require_once $root . '/core/skin-render-helpers.php';
$legacy = ['html' => '<img src=x onerror=alert(1)>'];
rbr_expect(snap_render_html($legacy['html']) === '&lt;img src=x onerror=alert(1)&gt;',
    'Legacy reconstructed strings must degrade to escaped text.');

echo 'Serialization/export/reconstruction inventory passed; forged wrappers rejected. PHP-file writers: '
    . implode(', ', array_keys($phpFileWriters)) . ".\n";
