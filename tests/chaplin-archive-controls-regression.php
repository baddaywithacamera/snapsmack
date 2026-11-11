<?php
/**
 * Regression — CHAPLIN honours the owner's ARCHIVE APPEARANCE controls.
 *
 * Sean, 2026-11-10: "I don't have good control over how thumbs are laid out."
 * He was right, and it was not a missing feature. Core already computed his
 * COLUMNS and THUMBNAIL SIZE and printed them onto the grid as classes
 * (archive-cols-N, archive-thumbs-STEP, from snapsmack_archive_grid_metrics in
 * core/skin-view-model.php). CHAPLIN's stylesheet then drew the grid from
 * --grid-cols and --thumb-width, which were fixed at the top of the file. The
 * classes arrived on every page and nothing read them, so moving either slider
 * changed the markup and not one pixel of the page.
 *
 * PM ruling (2026-11-10, option B narrowed): the global ARCHIVE APPEARANCE
 * page is the owner's rail and skins honour it rather than growing private
 * copies of the same controls. NEW HORIZON is the cautionary case — it
 * declares its own archive_layout, which core/skin-settings.php skips as
 * global-only, so that dropdown has never done anything.
 *
 * THE RESPONSIVE TRAP, which is why these rules are classes and not an inline
 * style on the element: a custom property written onto the grid element beats
 * the :root value inside a media query at EVERY width. An owner who picked
 * eight columns would get eight on a tablet and the grid would never collapse.
 * The breakpoints therefore re-assert --grid-cols with a doubled class, which
 * matches the specificity and comes later in the file.
 *
 * Static assertions over the stylesheet and the component — no DB, no site.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root  = dirname(__DIR__);
$fails = 0;

function cac(string $label, bool $ok): void {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fails++;
}

$css       = (string) @file_get_contents($root . '/skins/chaplin/style.css');
$component = (string) @file_get_contents($root . '/core/chaplin-page-component.php');
$metrics   = (string) @file_get_contents($root . '/core/skin-view-model.php');

cac('chaplin style.css is readable',            $css       !== '');
cac('chaplin page component is readable',       $component !== '');
cac('core skin-view-model.php is readable',     $metrics   !== '');

if ($css === '' || $component === '' || $metrics === '') {
    fwrite(STDERR, "\nCHAPLIN archive controls: source files missing — cannot check.\n");
    exit(1);
}

// ── Core still sends what the skin now reads ────────────────────────────────
// If core ever stops printing these classes, these rules go quiet and the
// controls die again without anything failing. Assert the producer too.
cac(
    'the component still prints the COLUMNS class',
    (bool) preg_match('/archive-cols-/', $component)
);
cac(
    'the component still prints the THUMBNAIL SIZE class',
    (bool) preg_match('/archive-thumbs-/', $component)
);

// ── COLUMNS reaches the grid ────────────────────────────────────────────────
$cols_missing = [];
for ($n = 1; $n <= 12; $n++) {
    if (!preg_match('/\.fsog-archive-grid\.archive-cols-' . $n . '\s*\{[^}]*--grid-cols:\s*' . $n . '\s*;/', $css)) {
        $cols_missing[] = $n;
    }
}
cac(
    'every column count 1-12 sets --grid-cols' . ($cols_missing ? ' (missing: ' . implode(',', $cols_missing) . ')' : ''),
    $cols_missing === []
);

// ── THUMBNAIL SIZE reaches the grid, per thumb style ────────────────────────
// The widths must match what core resolves, or the admin preview and the page
// disagree. Parsed out of snapsmack_archive_grid_metrics so a change there
// fails here instead of drifting silently.
$expected = [];
if (preg_match("/'square'\s*=>\s*\[([^\]]*)\]/", $metrics, $m)) {
    preg_match_all("/'(xs|s|m|l|xl)'\s*=>\s*(\d+)/", $m[1], $mm, PREG_SET_ORDER);
    foreach ($mm as $row) $expected['square'][$row[1]] = (int) $row[2];
}
if (preg_match("/'cropped'\s*=>\s*\[([^\]]*)\]/", $metrics, $m)) {
    preg_match_all("/'(xs|s|m|l|xl)'\s*=>\s*(\d+)/", $m[1], $mm, PREG_SET_ORDER);
    foreach ($mm as $row) $expected['cropped'][$row[1]] = (int) $row[2];
}

cac('core thumbnail-size map parsed for both styles',
    count($expected['square'] ?? []) === 5 && count($expected['cropped'] ?? []) === 5);

$drift = [];
foreach ($expected as $style => $steps) {
    foreach ($steps as $step => $px) {
        $pattern = '/\.fsog-archive-grid\.' . $style . '-grid\.archive-thumbs-' . $step
                 . '\s*\{[^}]*--thumb-width:\s*' . $px . 'px\s*;/';
        if (!preg_match($pattern, $css)) {
            $drift[] = "{$style}/{$step} should be {$px}px";
        }
    }
}
cac(
    'skin thumbnail widths match core\'s map' . ($drift ? ' (' . implode('; ', $drift) . ')' : ''),
    $drift === []
);

// ── The responsive collapse still wins ──────────────────────────────────────
// This is the part most likely to be broken by a later "tidy-up". Without the
// doubled class the owner's column count would hold at every width.
foreach ([1024 => 3, 768 => 2] as $width => $cols) {
    $block = '';
    if (preg_match('/@media \(max-width:\s*' . $width . 'px\)\s*\{(.*?)\n\}/s', $css, $m)) {
        $block = $m[1];
    }
    cac(
        "the {$width}px breakpoint re-asserts {$cols} columns on the grid itself",
        (bool) preg_match('/\.fsog-archive-grid\.fsog-archive-grid\s*\{\s*--grid-cols:\s*' . $cols . '\s*;/', $block)
    );
}

// The override must come AFTER the per-column rules, or specificity ties the
// wrong way round and the collapse loses.
$first_cols_rule = strpos($css, '.fsog-archive-grid.archive-cols-1');
$first_override  = strpos($css, '.fsog-archive-grid.fsog-archive-grid');
cac(
    'the responsive override is declared after the column rules',
    $first_cols_rule !== false && $first_override !== false && $first_override > $first_cols_rule
);

// ── The grid still consumes the variables ───────────────────────────────────
cac(
    'the live grid rule lays out from --grid-cols and --thumb-width',
    (bool) preg_match('/\.fsog-archive-grid\s*\{[^}]*grid-template-columns:\s*repeat\(\s*var\(--grid-cols[^}]*var\(--thumb-width/s', $css)
);

// ── No private copy of a control core already owns ──────────────────────────
// NEW HORIZON's dead archive_layout dropdown is what this prevents.
$manifest = (string) @file_get_contents($root . '/skins/chaplin/manifest.json');
foreach (['archive_layout', 'archive_thumb_style', 'thumb_size', 'justified_row_height'] as $owned) {
    cac(
        "chaplin does not declare its own {$owned} (core owns it)",
        !preg_match('/"' . $owned . '"\s*:/', $manifest)
    );
}

if ($fails) {
    fwrite(STDERR, "\n{$fails} FAILURE(S): the owner's archive controls do not reach the CHAPLIN grid, "
        . "or the responsive collapse has been broken by them.\n");
    exit(1);
}
echo "CHAPLIN archive controls reach the grid: PASS\n";
exit(0);

// ===== SNAPSMACK EOF =====
