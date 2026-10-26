<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/parser.php';
require_once dirname(__DIR__) . '/core/trusted-html.php';

$parser = (new ReflectionClass(SnapSmack::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(SnapSmack::class, 'parseYouTubeEmbeds');
$html = $method->invoke($parser, '<p>https://www.youtube.com/watch?v=7XSSTOb-t-A</p>');

if (!str_contains($html, 'https://www.youtube-nocookie.com/embed/7XSSTOb-t-A')) {
    throw new RuntimeException('Standalone YouTube URL was not embedded.');
}
$figure = $method->invoke($parser, '<figure>https://youtu.be/WWTwLiYqMKc<figcaption>The fun bit</figcaption></figure>');
if (!str_contains($figure, 'youtube-nocookie.com/embed/WWTwLiYqMKc') || !str_contains($figure, '<figcaption>The fun bit</figcaption>')) {
    throw new RuntimeException('Captioned YouTube figure was not embedded intact.');
}
$trusted = (string)snapsmack_trusted_html($html);
if (!str_contains($trusted, '<iframe') || !str_contains($trusted, 'youtube-nocookie.com/embed/7XSSTOb-t-A')) {
    throw new RuntimeException('Trusted HTML removed the CMS YouTube player.');
}
$hostile = (string)snapsmack_trusted_html('<iframe src="https://evil.example/embed/7XSSTOb-t-A"></iframe>');
if (str_contains($hostile, '<iframe')) {
    throw new RuntimeException('Trusted HTML accepted a non-YouTube iframe.');
}

echo "PASS: safe YouTube embed regression\n";
