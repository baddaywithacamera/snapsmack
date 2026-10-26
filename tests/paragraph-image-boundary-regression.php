<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/parser.php';

$reflection = new ReflectionClass(SnapSmack::class);
$parser = $reflection->newInstanceWithoutConstructor();
$cleanup = $reflection->getMethod('cleanBlockNesting');

$frame = '<div class="snap-inline-frame align-center"><div class="ip-ascii-frame-inner"><img src="/photo.jpg" alt=""></div></div>';
$input = '<p>Paragraph before the photograph.</p><p>' . $frame . '</p><p>Paragraph after the photograph.</p>';
$actual = $cleanup->invoke($parser, $input);

if (!str_contains($actual, '<p>Paragraph before the photograph.</p>')) {
    throw new RuntimeException('The paragraph before an image was unwrapped.');
}
if (!str_contains($actual, '<p>Paragraph after the photograph.</p>')) {
    throw new RuntimeException('The paragraph after an image was unwrapped.');
}
if (str_contains($actual, '<p><div class="snap-inline-frame')) {
    throw new RuntimeException('The image frame remained nested inside a paragraph.');
}

$mixed = '<p>Leading prose.' . $frame . 'Trailing prose.</p>';
$actualMixed = $cleanup->invoke($parser, $mixed);
foreach (['<p>Leading prose.</p>', $frame, '<p>Trailing prose.</p>'] as $expected) {
    if (!str_contains($actualMixed, $expected)) {
        throw new RuntimeException('Mixed prose/image paragraph was not split safely: ' . $expected);
    }
}

echo "Paragraph/image boundary regression: PASS\n";
