<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * Prose between two inline photographs keeps its paragraph, on the road the
 * content actually travels.
 *
 * 0.7.949D, 0.7.950D and 0.7.951D each repaired paragraphs and each left
 * baddaywithacamera.ca/my-name-is-mud exactly as it was: three loose runs of
 * text with no spacing and one empty paragraph. The repairs were correct. They
 * were in snapsmack_photo_description_html(), and a longform post is rendered
 * by SnapSmack::parseContent() and handed to the skin as rendered_content,
 * which never called it.
 *
 * So this file checks two things, and the second is the one that was missing:
 * that the repair works, and that the controller is still calling it.
 *
 * Run: php tests/rendered-content-paragraph-regression.php   (exit 0 = pass)
 */

require_once dirname(__DIR__) . '/core/trusted-html.php';

$failed = 0;
function check(bool $ok, string $what): void {
    global $failed;
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
    if (!$ok) $failed++;
}

$frame = '<div class="snap-inline-frame align-center"><div class="ip-ascii-frame-inner">'
       . '<img src="a.jpg" data-lightbox-src="a.jpg"></div></div>';
$before = '<p>First.</p>' . $frame . 'To get to Taylor Siding from Strathmore.'
        . $frame . 'Hussar was not much to look at.<p></p>'
        . '<div class="snap-mosaic" data-mosaic="[1,2]"></div><p>Last.</p>';
$after = snap_wrap_bare_paragraphs($before);

check(preg_match('~</div></div>(?=[A-Za-z])~', $after) === 0,
    'no prose is left loose directly after a photograph');
check(preg_match('~<p>\s*</p>~', $after) === 0,
    'the empty paragraph a block-in-paragraph leaves behind is removed');
check(preg_match_all('/<p[\s>]/', $after) === 4,
    'every prose run is a paragraph: the opener, two between photographs, the closer');

// The sanitiser must NOT run here. It would strip exactly the attributes the
// parser just wrote, and the mosaic and lightbox would stop working.
check(str_contains($after, 'data-mosaic'), 'the parser\'s mosaic data survives the repair');
check(str_contains($after, 'data-lightbox-src'), 'the parser\'s lightbox data survives the repair');

// Content that is already tidy must come back byte for byte, so this cannot
// quietly reshape posts that were fine.
$tidy = '<p>One.</p><p>Two.</p>';
check(snap_wrap_bare_paragraphs($tidy) === $tidy, 'tidy content is returned untouched');
check(snap_wrap_bare_paragraphs('') === '', 'empty content is returned untouched');

// The repair existing is not the point. Being called is the point.
$controller = (string)file_get_contents(dirname(__DIR__) . '/core/smacktalk-public-controller.php');
check(preg_match_all('/\$rendered\s*=\s*snap_wrap_bare_paragraphs\(/', $controller) === 2,
    'both rendered_content paths -- the static page and the post -- apply the repair');
check(preg_match('/\$rendered\s*=\s*\$parser->parseContent\(/', $controller) !== 1,
    'no rendered_content path assigns raw parseContent output without the repair');

echo "\n" . ($failed ? "FAILED: $failed" : 'ALL PASS') . "\n";
exit($failed ? 1 : 0);

// ===== SNAPSMACK EOF =====
