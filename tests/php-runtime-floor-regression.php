<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$expectations = [
    'fedup.php' => ['PHP_VERSION_ID >= 80300', 'PHP 8.3+'],
    'install.php' => ["version_compare(PHP_VERSION, '8.3.0', '>=')", 'Requires 8.3+'],
    'setup.php' => ['PHP 8.3+'],
    'smack-central/sc-setup.php' => ["version_compare(PHP_VERSION, '8.3.0', '>=')", 'Need 8.3+.'],
    'smack-central/sc-release.php' => ["?? '8.3'", 'name="requires_php" value="8.3"'],
    'smack-central/sc-skins.php' => ["?? '8.3'"],
    'cron-version-check.php' => ["?? '8.3'"],
    'smack-update.php' => ["'requires_php'    => '8.3'", "?? '8.3'"],
    'tools/_build/sign-release.php' => ["'requires_php'    => '8.3'"],
    'tools/_build/build-release.php' => ["'requires_php'    => '8.3'"],
    'tools/_build/build-install-package.php' => ["'requires_php'    => '8.3'"],
    'tools/_build/generate-registry.php' => ["?? '8.3'"],
    'README.md' => ['PHP 8.3+'],
    'projects/snapsmack-ca/faq-running.php' => ['PHP 8.3 or newer'],
];

foreach ($expectations as $file => $needles) {
    $source = file_get_contents($root . '/' . $file);
    if (!is_string($source)) throw new RuntimeException("Cannot read {$file}.");
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) {
            throw new RuntimeException("{$file} does not encode the PHP 8.3 floor: {$needle}");
        }
    }
}

$tracked = shell_exec('git -C ' . escapeshellarg($root) . ' grep -n -E '
    . escapeshellarg("requires_php.*['\"]8\\.0['\"]|PHP 8\\.0\\+|PHP 8\\+|PHP 8\\.1|PHP_VERSION[^,]*8\\.0\\.0")
    . ' -- "*.php" "*.md" ":(exclude)CHANGELOG.md" ":(exclude)docs/**" ":(exclude)secaudits/**"');
if (is_string($tracked) && trim($tracked) !== '') {
    throw new RuntimeException("Stale supported-runtime claim remains:\n" . $tracked);
}

echo "PHP 8.3 runtime-floor regression passed.\n";
