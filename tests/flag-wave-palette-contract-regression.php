<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$engine = file_get_contents($root . '/assets/js/ss-engine-flag-wave.js');
$presentation = file_get_contents($root . '/core/skin-presentation.php');

$checks = [
    'PARADE publishes its saved palette as JSON colour strings' =>
        str_contains($presentation, "'stripes' => json_encode(\$colors"),
    'flag engine accepts the established string palette contract' =>
        str_contains($engine, 'Array.isArray(s)')
        && str_contains($engine, ': [String(s), 1]'),
    'flag engine retains weighted colour-pair support' =>
        str_contains($engine, '[String(s[0]), Math.max(0.01, +s[1] || 1)]'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

echo "PASS: flag-wave palette contract regression\n";
