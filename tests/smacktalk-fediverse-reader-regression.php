<?php
/** SMACKTALK must be text-first without changing photography readers. */

$root = dirname(__DIR__);
$pixel = file_get_contents($root . '/pixel.php');
$js = file_get_contents($root . '/assets/js/ss-pixel.js');
$css = file_get_contents($root . '/assets/css/ss-pixel.css');
$sidebar = file_get_contents($root . '/core/sidebar.php');

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};

$check(str_contains($pixel, "=== 'smacktalk'"), 'pixel.php does not select the SMACKTALK reader');
$check(str_contains($pixel, "? 'mastodon'") && str_contains($pixel, ": 'pixelfed'"), 'reader fallback is not Pixelfed');
$check(str_contains($pixel, 'data-reader-style='), 'reader style is not exposed to the client');
$check(str_contains($js, 'CFG.readerStyle === "mastodon"'), 'feed cards do not branch for SMACKTALK');
$check(str_contains($js, 'Favourite</button>') && str_contains($js, 'Reply</button>'), 'Mastodon-style actions are missing');
$check(str_contains($js, 'Like</button>') && str_contains($js, 'Comment</button>'), 'Pixelfed-style actions were not preserved');
$check(str_contains($css, '.sx-reader-mastodon .sx-caption') && str_contains($css, 'order:3'), 'SMACKTALK text is not ordered before media');
$check(str_contains($css, '.sx-reader-mastodon .sx-media') && str_contains($css, 'order:4'), 'SMACKTALK media order is missing');
$check(str_contains($css, '--fg:#f5f5f7') && str_contains($css, '--muted:#9baec8'), 'SMACKTALK reader does not lock in readable foreground colours');
$check(str_contains($sidebar, "\$_site_is_smacktalk ? 'Mastodon' : 'Pixelfed'"), 'admin reader label is not mode-aware');
// SS-1355 CHANGED THIS ASSERTION DELIBERATELY, AND IT WAS HOLDING A LEGAL
// PROBLEM IN PLACE. It required pixel.php to contain the literal string
// "Mastodon.social" -- a real instance, run by other people, printed as the
// brand at the top of every SMACKTALK install. Owner ruling 2026-11-14: that
// comes off. A test that pins the defect is the SS-1322 fault again, where a
// check blocked the repair it was written to protect.
//
// The useful rule is the one underneath: the brand must track the mode and
// must name the PROTOCOL rather than somebody's server. Rendered proof of
// both modes lives in tests/reader-header-never-names-a-real-instance.php;
// this one keeps the source-level guard that the two arms still differ.
$check(str_contains($pixel, "'Mastodon Gateway' : 'Pixelfed Gateway'"),
    'reader brands do not name the gateway for each presentation mode');
$check(!preg_match('~<b>\s*Mastodon\.social~i', $pixel),
    'the reader masthead names a real instance again');
$check(str_contains($pixel, 'sx-masto-primary') && str_contains($pixel, '>Explore</a>'), 'Mastodon navigation shell is missing');
$check(str_contains($css, '.sx-reader-pixelfed .sx-card') && str_contains($css, '.sx-reader-mastodon .sx-nav'), 'reader-specific shells are not separated');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "SMACKTALK Fediverse reader regression passed.\n";
