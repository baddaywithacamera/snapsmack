<?php
/** SMACKTALK must be text-first without changing photography readers. */

$root = dirname(__DIR__);
$pixel = file_get_contents($root . '/pixel.php');
$js = file_get_contents($root . '/assets/js/ss-pixel.js');
$css = file_get_contents($root . '/assets/css/ss-pixel.css');

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

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "SMACKTALK Fediverse reader regression passed.\n";
