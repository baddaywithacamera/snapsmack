<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$layout = file_get_contents($root . '/skins/aurora/layout.php');
$engine = file_get_contents($root . '/assets/js/ss-engine-aurora-wave.js');

$checks = [
    'AURORA tiles retain the animated ring overlay' => str_contains($layout, 'class="au-ring"'),
    'AURORA tiles expose their wave row' => str_contains($layout, 'data-row='),
    'AURORA tiles expose their wave column' => str_contains($layout, 'data-col='),
    'shared wave engine still targets AURORA rings' =>
        str_contains($engine, "el.querySelector('.' + P + '-ring')"),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

echo "PASS: AURORA animated border hook regression\n";
