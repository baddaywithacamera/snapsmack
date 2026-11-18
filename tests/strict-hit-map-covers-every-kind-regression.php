<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1061 — the hit map and the controllers cannot drift apart again.
 *
 * A response kind with no entry in index.php's $_hit_types map logs NOTHING:
 * no page view, and no scroll time either, because snapsmack_scrolltime_tag()
 * returns immediately when $GLOBALS['snapsmack_hit'] is absent. The traffic
 * does not fall off; it is never written down. That has now happened three
 * times -- the whole map was missing after the strict conversion, then 'feed'
 * (SS-1204), then 'single' (SS-1129's fix could not fire without it).
 *
 * Each time it was found by someone noticing a number looked wrong. This test
 * is the thing that should have caught all three, and it works in BOTH
 * directions:
 *
 *   - every kind a controller can emit is mapped, or is on a short list of
 *     deliberate exclusions WITH a reason;
 *   - every key in a map is a kind some controller emits, or is on a short
 *     list of deliberate forward-compatible entries WITH a reason.
 *
 * The second direction matters as much as the first. SS-1061's own change
 * adds 'blog', which nothing currently emits; without this check a map could
 * quietly fill with keys that look like coverage and are not.
 *
 * Run: php tests/strict-hit-map-covers-every-kind-regression.php
 */

$root = dirname(__DIR__);
$failures = [];

function check(string $label, bool $ok, array &$failures): void {
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures[] = $label;
    }
}

/** Every distinct kind literal a controller hands back. */
function kinds_emitted_by(string $file): array {
    $src = (string)file_get_contents($file);
    preg_match_all("/'kind'\s*=>\s*'([a-z_]+)'/", $src, $m);
    $kinds = array_values(array_unique($m[1] ?? []));
    sort($kinds);
    return $kinds;
}

/** The Nth $_hit_types literal in index.php, as key => page_type. */
function hit_map(string $index_src, int $which): array {
    preg_match_all('/\$_hit_types\s*=\s*\[(.*?)\];/s', $index_src, $m);
    if (!isset($m[1][$which])) return [];
    preg_match_all("/'([a-z_]+)'\s*=>\s*'([a-z_]+)'/", $m[1][$which], $pairs);
    return array_combine($pairs[1], $pairs[2]) ?: [];
}

$index = (string)file_get_contents($root . '/index.php');

echo "SS-1061 — every response kind reaches the hit map\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — both maps were found\n";
// ─────────────────────────────────────────────────────────────────────────────
// If the parse silently returns nothing, every coverage check below passes
// vacuously. That is the shape of failure this repository keeps finding, so
// it is checked first.
$smacktalk_map = hit_map($index, 0);   // the SMACKTALK branch comes first
$strict_map    = hit_map($index, 1);   // then the strict photoblog branch
check('the SMACKTALK hit map parsed and is not empty', count($smacktalk_map) >= 5, $failures);
check('the strict hit map parsed and is not empty',    count($strict_map) >= 5, $failures);
check('they are two different maps',
      $smacktalk_map !== $strict_map, $failures);
check('the SMACKTALK map is the one with the SMACKTALK kinds',
      isset($smacktalk_map['feed'], $smacktalk_map['single']), $failures);
check('the strict map is the one with the photoblog kinds',
      isset($strict_map['photo'], $strict_map['albums']), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — every emitted kind is mapped, or excluded with a reason\n";
// ─────────────────────────────────────────────────────────────────────────────
// A kind here and not in the map is a page that records nothing at all.
$EXCLUDED = [
    'not_found' => 'a 404 is not a page view. The status === 200 guard beside '
                 . 'the map already stops it, so an entry would be unreachable.',
    'taxonomy'  => 'HELD by ruling: nobody has asked for a beacon on a '
                 . 'SMACKTALK taxonomy page. Recorded here so held does not '
                 . 'quietly become forgotten (index.php, SS-1204 comment).',
];

$controllers = [
    'core/public-controller.php'           => &$strict_map,
    'core/smacktalk-public-controller.php' => &$smacktalk_map,
];
foreach ($controllers as $file => $map) {
    $emitted = kinds_emitted_by($root . '/' . $file);
    check("{$file}: kinds were actually found in it", count($emitted) >= 5, $failures);
    foreach ($emitted as $kind) {
        if (isset($EXCLUDED[$kind])) {
            continue;
        }
        check("{$file}: kind '{$kind}' has a hit-map entry", isset($map[$kind]), $failures);
    }
}
unset($map);

// The exclusions are only honest while they stay deliberate. If one starts
// being mapped, this test should be the thing that makes somebody delete the
// excuse rather than leaving it to read as still-held.
foreach (array_keys($EXCLUDED) as $kind) {
    check("'{$kind}' is still excluded on purpose, not mapped behind the reason",
          !isset($strict_map[$kind]) && !isset($smacktalk_map[$kind]), $failures);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — every mapped key is a kind, or is declared dead on purpose\n";
// ─────────────────────────────────────────────────────────────────────────────
// SS-1061's own change adds a key nothing emits. Without this direction, a
// map can fill with entries that look like coverage and cover nothing.
$FORWARD_COMPATIBLE = [
    'blog' => "'blog' is a ROUTE, not a kind. core/public-controller.php:483 "
            . 'serves it by forcing latest_post and falling through the '
            . 'landing branches, which return photo / landing / page. Measured: '
            . 'no file in core/ emits kind blog. Mapped anyway, by owner ruling, '
            . 'so the feed logs rather than vanishes the day it gets its own kind.',
];
$all_emitted = array_unique(array_merge(
    kinds_emitted_by($root . '/core/public-controller.php'),
    kinds_emitted_by($root . '/core/smacktalk-public-controller.php')
));
foreach (['strict' => $strict_map, 'smacktalk' => $smacktalk_map] as $name => $map) {
    foreach (array_keys($map) as $key) {
        if (isset($FORWARD_COMPATIBLE[$key])) continue;
        check("{$name} map: '{$key}' is a kind some controller emits",
              in_array($key, $all_emitted, true), $failures);
    }
}

// And the claim that justifies the one dead key has to stay true.
foreach (['core/public-controller.php', 'core/smacktalk-public-controller.php',
          'core/portfolio-route.php'] as $f) {
    $src = (string)file_get_contents($root . '/' . $f);
    check("{$f} still emits no kind 'blog'",
          preg_match("/'kind'\s*=>\s*'blog'/", $src) !== 1, $failures);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — the page types the maps produce are ones the CMS knows\n";
// ─────────────────────────────────────────────────────────────────────────────
// A typo on the right-hand side is as silent as a missing key: the row is
// written and then no reader ever filters for it.
$KNOWN_TYPES = ['landing', 'image', 'page', 'archive', 'hashtag', 'search'];
foreach (['strict' => $strict_map, 'smacktalk' => $smacktalk_map] as $name => $map) {
    foreach ($map as $kind => $type) {
        check("{$name} map: '{$kind}' => '{$type}' is a known page type",
              in_array($type, $KNOWN_TYPES, true), $failures);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — the owner's second ruling, executed against the real gate\n";
// ─────────────────────────────────────────────────────────────────────────────
// "In smacktalk mode make the single-article kind eligible for scroll time."
// Checked rather than implemented: SS-1129 added ($mode === 'smacktalk' &&
// $type === 'page') to the gate, and SS-1204 mapped 'single' => 'page', so
// the chain already closes. Run it rather than read it.
require_once $root . '/core/stats-logger.php';

check("the single-article kind maps to 'page'",
      ($smacktalk_map['single'] ?? null) === 'page', $failures);

// snapsmack_scrolltime_tag() has a `static $emitted` that latches after the
// first ELIGIBLE call, so every ineligible case must run first and the one
// eligible case last. Ineligible calls return before the latch.
function emits_tracker(array $hit, array $settings): bool {
    $GLOBALS['snapsmack_hit'] = $hit;
    ob_start();
    snapsmack_scrolltime_tag($settings);
    return trim((string)ob_get_clean()) !== '';
}

$hit = ['id' => 42, 'page_type' => 'page'];
check('no tracker for a photoblog static page (unchanged, deliberately)',
      emits_tracker($hit, ['site_mode' => 'photoblog']) === false, $failures);
check('no tracker for a single photo in smacktalk mode',
      emits_tracker(['id' => 42, 'page_type' => 'image'], ['site_mode' => 'smacktalk']) === false,
      $failures);
check('no tracker when there is no hit at all (the SS-1061 failure mode)',
      emits_tracker([], ['site_mode' => 'smacktalk']) === false, $failures);

// The eligible one, last, because it latches.
check("a SMACKTALK single article DOES get the scroll tracker",
      emits_tracker($hit, ['site_mode' => 'smacktalk']) === true, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1061 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}
echo "SS-1061 PASS — every kind both controllers emit reaches the hit map,\n";
echo "every mapped key is a real kind or a declared-dead one, and a SMACKTALK\n";
echo "article gets its scroll tracker.\n";
// ===== SNAPSMACK EOF =====
