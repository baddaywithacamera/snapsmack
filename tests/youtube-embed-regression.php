<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/parser.php';
require_once __DIR__ . '/../core/trusted-html.php';
require_once __DIR__ . '/../core/custom-code-policy.php';

$parser = (new ReflectionClass(SnapSmack::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(SnapSmack::class, 'parseYouTubeEmbeds');

$plain = $method->invoke($parser, '<p>https://www.youtube.com/watch?v=7XSSTOb-t-A</p>');
if (!str_contains($plain, 'https://www.youtube-nocookie.com/embed/7XSSTOb-t-A')) {
    fwrite(STDERR, "Standalone YouTube URL was not embedded.\n"); exit(1);
}

$captioned = $method->invoke($parser, '<figure>https://youtu.be/WWTwLiYqMKc<figcaption>The fun bit</figcaption></figure>');
if (!str_contains($captioned, '<figcaption>The fun bit</figcaption>')) {
    fwrite(STDERR, "Migrated figure caption was not preserved.\n"); exit(1);
}

$prose = $method->invoke($parser, '<p>Watch <a href="https://youtu.be/WWTwLiYqMKc">this video</a>.</p>');
if (str_contains($prose, '<iframe')) {
    fwrite(STDERR, "A prose link was incorrectly embedded.\n"); exit(1);
}

$trusted = (string)snapsmack_trusted_html($plain);
if (!str_contains($trusted, 'youtube-nocookie.com/embed/7XSSTOb-t-A')) {
    fwrite(STDERR, "Approved YouTube iframe was removed.\n"); exit(1);
}

$hostile = (string)snapsmack_trusted_html('<iframe src="https://example.com/embed/7XSSTOb-t-A"></iframe>');
if (str_contains($hostile, '<iframe')) {
    fwrite(STDERR, "Unapproved iframe survived sanitization.\n"); exit(1);
}

$csp = snapsmack_public_csp(false);
if (!str_contains($csp, 'frame-src https://www.youtube-nocookie.com')) {
    fwrite(STDERR, "Public CSP does not permit the approved player origin.\n"); exit(1);
}

$css = file_get_contents(__DIR__ . '/../assets/css/shortcodes.css');
if (!is_string($css) || !str_contains($css, '.snap-video-figure .snap-video') || !str_contains($css, 'margin: 0;')) {
    fwrite(STDERR, "Captioned player spacing contract is missing.\n"); exit(1);
}

echo "YouTube embed regression passed.\n";
