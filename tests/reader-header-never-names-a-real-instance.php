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
