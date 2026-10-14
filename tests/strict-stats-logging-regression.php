<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A converted site must still count its visitors.
 *
 * index.php requires core/stats-logger.php at the top and calls
 * snapsmack_log_hit() in seven places -- every one of them BELOW the strict
 * branch, which renders the page and exits. So from the day a site was
 * converted to the strict runtime it recorded no views, no visitors and no
 * scroll time. Nothing errored and nothing was lost; the visits were simply
 * never written down, which on the fleet rollup looks exactly like traffic
 * collapsing. Sean saw it as a daily traffic chart falling to the floor.
 *
 * Both strict branches exit -- the public one and the SMACKTALK one -- so
 * both must log first. The test is structural rather than behavioural: it
 * asserts that every strict render is preceded by a hit, because the failure
 * was an ordering mistake, not a logic one.
 */

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) $failures[] = $what;
};

$src = (string)file_get_contents($root . '/index.php');
$lines = explode("\n", $src);

// --- every strict render must be preceded by a logged hit ---------------
$renders = 0;
foreach ($lines as $i => $line) {
    if (!str_contains($line, 'snapsmack_render_strict_skin_template')) continue;
    $renders++;
    $before = implode("\n", array_slice($lines, max(0, $i - 40), min($i, 40)));
    $check(str_contains($before, 'snapsmack_log_hit('),
        'the strict render on line ' . ($i + 1) . ' must count the visit before it exits');
}
$check($renders >= 2, 'expected both strict branches to be present, found ' . $renders);

// --- the endless-scroll feed must NOT be counted as a page view ---------
$feedAt = strpos($src, "(\$_GET['pg'] ?? '') === 'wall'");
$logAt  = strpos($src, "'page_type'   => \$_hit_types[\$_hit_kind]");
$check($feedAt !== false && $logAt !== false && $feedAt < $logAt,
    'the wall feed must answer and exit BEFORE the hit is logged, or scrolling inflates the view count');

// --- the kinds must map onto the vocabulary the rest of the CMS uses ----
$vocabulary = ['archive', 'hashtag', 'image', 'landing', 'page', 'search'];
preg_match_all("/'(?:landing|photo|post|page|collection|blogroll|hashtag|search|archive|albums|collections)' => '([a-z]+)'/", $src, $m);
$mapped = array_values(array_unique($m[1] ?? []));
sort($mapped);
$check($mapped !== [], 'the strict kinds must be mapped to page types');
foreach ($mapped as $type) {
    $check(in_array($type, $vocabulary, true),
        'page_type "' . $type . '" is not one the rest of the CMS writes; old and new rows must be comparable');
}
// Every public kind a strict skin can render must be accounted for, or that
// kind silently stops counting the way all of them just did.
foreach (['landing', 'photo', 'post', 'page', 'collection', 'blogroll',
          'hashtag', 'search', 'archive', 'albums', 'collections'] as $kind) {
    $check(preg_match("/'" . $kind . "' => '(?:archive|hashtag|image|landing|page|search)'/", $src) === 1,
        'the "' . $kind . '" kind must be counted');
}

// --- a failed request must not be counted -------------------------------
$check(str_contains($src, "(int)(\$_public['status'] ?? 200) === 200"),
    'only a page that actually rendered may be counted');

// --- and the rollup must still run, or the daily table never fills ------
$check(substr_count($src, 'snapsmack_maybe_rollup($pdo)') >= 3,
    'the strict paths must roll up as the legacy paths do, or snap_stats_daily stays empty');

if ($failures) {
    fwrite(STDERR, "Strict stats logging regression FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
printf("strict stats: %d strict renders, each counts its visit first.\n", $renders);
echo "Strict stats logging regression passed.\n";

// ===== SNAPSMACK EOF =====
