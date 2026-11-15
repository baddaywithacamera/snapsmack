<?php
declare(strict_types=1);

/**
 * SS-1355. The fediverse reader must not put somebody else's instance name at
 * the top of the page.
 *
 * pixel.php printed "Mastodon.social" as the brand on every SMACKTALK
 * install. That is a REAL INSTANCE run by other people, and a name in the
 * masthead is not a style reference -- it is a claim about whose service is
 * serving the page. Owner ruling 2026-11-14: it comes off.
 *
 * The replacement names the PROTOCOL, which is true: FEDIVERSE, then which
 * gateway. "Mastodon Gateway" says Mastodon-compatible, the way a product may
 * say it speaks SMTP. "Mastodon.social" said it IS someone's server.
 *
 * THIS RENDERS THE REAL HEADER in both reader modes rather than grepping for
 * a string, because the header is built by a PHP branch and a grep cannot
 * tell which arm a visitor actually sees.
 */

$root = dirname(__DIR__);
$src  = (string)file_get_contents($root . '/pixel.php');

$fails = [];
$check = static function (string $what, bool $ok) use (&$fails): void {
    if (!$ok) $fails[] = $what;
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
};

// --- render the real header block, once per mode ---------------------------
// The block is lifted out of pixel.php so the test cannot drift from it. Only
// the masthead is needed and the rest of the page wants a database.
if (!preg_match('/<a class="sx-brand".*?<\/a>/s', $src, $m)) {
    fwrite(STDERR, "FAIL: could not find the brand block in pixel.php\n");
    exit(1);
}
$block = $m[0];
// The block still mentions the old name, in a comment recording why it went.
// That is history, not markup. The per-mode renders below are what prove
// nothing reaches a visitor, so this file does not try to be clever about
// counting comment lines.

function ss1355_render(string $block, string $style): string {
    $px_e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES);
    $sv_reader_style = $style;
    ob_start();
    eval('?>' . $block);
    return (string)ob_get_clean();
}

foreach (['mastodon' => 'Mastodon Gateway', 'pixelfed' => 'Pixelfed Gateway'] as $mode => $gateway) {
    $html = ss1355_render($block, $mode);

    $check("{$mode}: THE RENDERED HEADER DOES NOT NAME A REAL INSTANCE",
        stripos($html, 'mastodon.social') === false);
    $check("{$mode}: it says FEDIVERSE", str_contains($html, 'FEDIVERSE'));
    $check("{$mode}: it names the gateway, {$gateway}", str_contains($html, $gateway));
    $check("{$mode}: and it does not claim to BE the other project",
        !preg_match('~<b>\s*(pixelfed|mastodon)~i', $html));
}

// --- the two modes must differ, or the label is not telling the truth ------
$check('the two modes render different gateways',
    ss1355_render($block, 'mastodon') !== ss1355_render($block, 'pixelfed'));

// --- and nowhere else in the reader -----------------------------------------
$rendered_src = preg_replace('~//[^\n]*~', '', $src) ?? $src;
$check('no instance name survives anywhere else in pixel.php',
    stripos($rendered_src, 'mastodon.social') === false);
$check('the right-hand card says Gateway too, not "presentation"',
    str_contains($src, 'Mastodon Gateway · powered by SnapSmack'));

// --- THE MARK MUST NOT BE SOMEBODY ELSE'S LOGO ------------------------------
// Sean, on his own unzucked.ca header: the rainbow conic gradient in a
// rounded square, sitting immediately left of the word "pixelfed", reads as
// Pixelfed's own logo. Fixing the word and leaving a lookalike beside it
// would be the same claim in a different place, and he ruled out a recoloured
// lookalike too.
$css = (string)file_get_contents($root . '/assets/css/ss-pixel.css');
if (!preg_match('/\.sx-brand \.sx-mark\s*\{[^}]*\}/s', $css, $mark)) {
    fwrite(STDERR, "FAIL: could not find the brand mark rule
");
    exit(1);
}
$rule = $mark[0];
$check('the brand mark is OUR LOGO ASSET, not drawn in CSS',
    str_contains($rule, 'background-image:url("../img/fediverse-mark-'));
$check("and it is not a CSS-drawn lookalike of anybody else's mark",
    !preg_match('/conic-gradient|linear-gradient|radial-gradient/i', $rule));
$colours = [];
preg_match_all('/#[0-9a-f]{3,8}\b/i', $rule, $hex);
foreach ($hex[0] as $h) { $colours[strtolower($h)] = true; }
$check('it draws no palette of its own at all', count($colours) === 0);

// SHIPPED SMALL. The original is 1000x1000 and 107KB; this box is 26 CSS
// pixels, 78 device pixels at 3x. Serving the original on every reader page
// load would be a hundred kilobytes for a thumbnail.
// SEAN RULED THE MARK SHOULD BE BIGGER than the 26px the old gradient had.
// 44px, from his 40-48 range; the 62px header row takes it without changing.
preg_match('/width:\s*(\d+)px;\s*height:\s*(\d+)px;/', $rule, $box);
$check("the mark is in Sean's 40-48px range, not the old 26",
    isset($box[1]) && (int)$box[1] >= 40 && (int)$box[1] <= 48
    && $box[1] === $box[2]);
$check('and it still fits the 62px header row', isset($box[1]) && (int)$box[1] <= 56);

$asset = $root . '/assets/img/fediverse-mark-160.png';
$check('the logo asset exists where the stylesheet points', is_file($asset));
if (is_file($asset)) {
    $check('and it is a small derivative, not the 1000px original',
        filesize($asset) < 40000);
    [$aw, $ah] = getimagesize($asset);
    $check('big enough for a 44px box at 3x (132px)', $aw >= 132 && $ah >= 132);
    $check('and not larger than any reader needs', $aw <= 256 && $ah <= 256);
    $check('the superseded 128px derivative is gone, not left behind',
        !is_file($root . '/assets/img/fediverse-mark-128.png'));
}

// BOTH MODES. The mark was display:none on the SMACKTALK reader, hidden back
// when it was the rainbow gradient. Sean asked for his logo in both.
$check('the SMACKTALK reader is no longer hiding the mark',
    !preg_match('/\.sx-reader-mastodon \.sx-brand \.sx-mark\{ display:none/', $css));

// --- the stale product name is gone from the public site --------------------
$ca = (string)file_get_contents($root . '/projects/snapsmack-ca/index.php');
$check('snapsmack.ca no longer calls the reader SMACKVERSE',
    !str_contains($ca, 'SMACKVERSE'));

// Legitimate uses are NOT swept up: an API host, an example handle and an
// input placeholder are not claims about who serves the page.
$check('the photo-challenge hashtag source still points at the real host',
    str_contains((string)file_get_contents($root . '/core/photochallenge.php'),
                 "sv_hashtag_timeline('mastodon.social'"));

echo (count($fails) ? "\nFAIL" : "\nPASS")
   . ": the reader header names the protocol, not somebody's instance (SS-1355)\n";
exit(count($fails) ? 1 : 0);
// ===== SNAPSMACK EOF =====
