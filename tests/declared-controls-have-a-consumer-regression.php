<?php
declare(strict_types=1);
/**
 * A control the admin offers is consumed by something a skin actually reaches.
 *
 * WHY THIS EXISTS. The owner reported Archive Appearance > THUMBNAIL FRAME
 * reading "1px Outside - Grey" while his archive showed thick white mats, and
 * his point was the general one: "we keep finding these one at a time, so
 * there are more on that page." There were. SS-1013 found five in October and
 * the re-trace on 31 October 2026 found that three of those had since been
 * wired, that four others are consumed only on a path no skin reaches, and
 * that one -- the frame control -- works in a way a search for readers cannot
 * see at all.
 *
 * Every one of those was found by a person looking. This file is the floor
 * under that: a NEW control that nothing consumes fails here on the day it is
 * added, instead of surviving until an owner notices it does nothing.
 *
 * WHAT COUNTS AS CONSUMED, and the second and third ones are the whole
 * subtlety:
 *
 *   1. A READER on a path a strict skin runs -- core/, assets/, a skin, or
 *      index.php. All 30 renderable skins are strict.
 *
 *   2. GENERATED CSS AT SAVE TIME. smack-appearance-archive.php:110-128 turns
 *      the frame preset into CSS rules and upserts them into the
 *      custom_css_public blob. Nothing ever reads archive_thumb_frame, and
 *      nothing needs to: the generated CSS is the output. A test that demanded
 *      a reader would fail on that control for ever, and a gate that always
 *      fails is furniture. These keys are declared below with their line
 *      numbers so the claim can be checked rather than believed.
 *
 *   3. A reader on a LEGACY path does NOT count. core/meta.php is included by
 *      gallery-wall.php alone -- core/public-runtime.php:21 states plainly
 *      that "Strict public skins do not include core/meta.php" -- and
 *      archive.php is a file no strict skin runs. Four Archive Appearance
 *      controls are consumed only there. This is the same mechanism that hid
 *      Thomas, the F1 help modal and the consent banner, each emitted only
 *      from that path and reaching no skin for weeks.
 *
 * WHAT THIS CANNOT DO, said here so a green run is not misread. It checks the
 * SHAPE of the wiring: that a key has a plausible consumer. It cannot tell
 * that the consumer produces a visible change. The frame control is the proof
 * -- it is consumed, its rule IS applied to CHAPLIN, and the owner still sees
 * the wrong thing because CHAPLIN draws its own .chap-img-frame over it. Only
 * a rendered before/after per skin can prove a control works. THIS IS A FLOOR,
 * NOT A CEILING.
 *
 * Run:  php tests/declared-controls-have-a-consumer-regression.php
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = dirname(__DIR__);
$failures = [];
$notices  = [];
function dc_fail(string $message): void { global $failures; $failures[] = $message; }

/* ── The admin pages this file audits ──────────────────────────────────────
 * Archive Appearance only, so far. The same table is owed for Solo Image
 * Appearance, Static Page Appearance and Global Vibe, and each should be added
 * here as its audit lands -- adding a page before its controls are traced
 * would turn this into a wall of failures nobody reads. */
$pages = ['smack-appearance-archive.php'];

$notAuditedYet = [
    'Solo Image Appearance', 'Static Page Appearance', 'Global Vibe',
];

/* ── Keys that act by generating CSS when the admin saves ───────────────── */
$generatesCss = [
    // smack-appearance-archive.php:110-128. Upserts two rules into
    // custom_css_public: arch_opt:grid_border over four grid selectors, and
    // arch_opt:masonry_border over #justified-grid .justified-item.
    'archive_thumb_frame' => 'smack-appearance-archive.php:110-128, custom_css_public',
];

/* ── Controls KNOWN to be consumed by nothing a skin reaches ──────────────
 * Each needs a ticket. The test passes with these present, so that it can go
 * in today, and FAILS the moment a new one appears. It also fails if one of
 * these gains a consumer, because an allow-list nobody prunes becomes a lie. */
$knownDead = [
    'archive_gutter'      => 'SS-1013 -- consumed by nothing anywhere; no reader, no generated CSS',
    'calendar_side'       => 'SS-1013 -- core/meta.php:559 only, and that file is reached from gallery-wall.php alone',
    'calendar_months'     => 'SS-1013 -- core/meta.php:560 only',
    'calendar_post_count' => 'SS-1013 -- core/meta.php:561 only',
    'masonry_use_thumbs'  => 'SS-1013 -- archive.php:770 and :824 only, a file no strict skin runs',
];

/* ── Where a consumer may live, and where it does not count ─────────────── */
$legacyFiles = [
    'core/meta.php', 'core/footer.php', 'core/footer-scripts.php',
    'archive.php', 'gallery-wall.php',
];
// The settings machinery is not a consumer: core/skin-settings.php overlays
// per-skin values onto bare keys and core/manifest-inventory.php inventories
// them. Both mention every key by nature, so counting them would make this
// test pass for everything.
$machinery = ['core/skin-settings.php', 'core/manifest-inventory.php'];

/* ── Collect the files that could consume a setting ─────────────────────── */
$searchRoots = ['core', 'assets/js', 'assets/css', 'skins'];
$searchFiles = ['index.php', 'archive.php', 'gallery-wall.php'];
$candidates = [];
foreach ($searchRoots as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,
        FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, ['php', 'js', 'css'], true)) continue;
        $candidates[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    }
}
foreach ($searchFiles as $f) {
    if (is_file($root . '/' . $f)) $candidates[] = $f;
}
sort($candidates);
dc_fail(count($candidates) > 200 ? '' : 'only ' . count($candidates)
    . ' files were searched for consumers, which is too few to be a real '
    . 'search -- the walk is broken and every control would look dead');
$failures = array_values(array_filter($failures));

$contents = [];
foreach ($candidates as $rel) {
    $contents[$rel] = (string) @file_get_contents($root . '/' . $rel);
}

/* ── Audit each page ────────────────────────────────────────────────────── */
$report = [];
foreach ($pages as $page) {
    $src = (string) @file_get_contents($root . '/' . $page);
    if ($src === '') { dc_fail("admin page {$page} could not be read"); continue; }

    preg_match_all('/name="settings\[([a-z0-9_]+)\]"/', $src, $m);
    $keys = array_values(array_unique($m[1]));
    sort($keys);
    if (count($keys) < 5) {
        dc_fail("only " . count($keys) . " controls were found on {$page}; the "
            . 'markup has changed shape and this extraction no longer works');
        continue;
    }

    foreach ($keys as $key) {
        $strict = [];
        $legacy = [];
        foreach ($contents as $rel => $body) {
            if ($body === '' || strpos($body, $key) === false) continue;
            if (in_array($rel, $machinery, true)) continue;
            if (in_array($rel, $legacyFiles, true)) { $legacy[] = $rel; continue; }
            $strict[] = $rel;
        }

        $verdict = $strict !== [] ? 'WIRED'
                 : (isset($generatesCss[$key]) ? 'CSS-AT-SAVE'
                 : (isset($knownDead[$key]) ? 'KNOWN DEAD' : 'NO CONSUMER'));
        $report[] = [$key, $verdict, $strict, $legacy];

        if ($verdict === 'NO CONSUMER') {
            dc_fail("{$page} offers a control that NOTHING consumes: {$key}. "
                . ($legacy !== [] ? 'Its only mentions are on the legacy path ('
                    . implode(', ', $legacy) . '), which no strict skin reaches. '
                  : 'It is mentioned nowhere else in the repository at all. ')
                . 'Either give it a consumer, or remove it from the admin, or -- '
                . 'if it is knowingly dead for now -- add it to $knownDead in '
                . 'this file WITH A TICKET NUMBER. Do not add it without one.');
        }

        // An allow-list that nobody prunes becomes a lie, so being wired while
        // listed as dead is also a failure -- a welcome one.
        if ($strict !== [] && isset($knownDead[$key])) {
            dc_fail("GOOD NEWS, AND A STALE LIST: {$key} is listed in "
                . '$knownDead as consumed by nothing, and it now has a consumer ('
                . implode(', ', array_slice($strict, 0, 3)) . '). Remove it from '
                . 'the list and update its ticket. Three keys already came off '
                . 'this list between 27 and 31 October.');
        }
        if ($strict !== [] && isset($generatesCss[$key])) {
            $notices[] = "{$key} is listed as acting through generated CSS and now "
                . 'also has a direct consumer (' . implode(', ', array_slice($strict, 0, 2))
                . '); the note in $generatesCss may be out of date.';
        }
    }
}

/* ── The declarations themselves must stay honest ───────────────────────── */
foreach ($generatesCss as $key => $where) {
    [$file] = explode(':', $where, 2);
    $body = (string) @file_get_contents($root . '/' . $file);
    dc_fail($body !== '' && strpos($body, $key) !== false ? ''
        : "\$generatesCss claims {$key} is generated in {$file}, and {$file} does "
        . 'not mention it. The claim is stale or wrong.');
    dc_fail($body !== '' && strpos($body, 'custom_css_public') !== false ? ''
        : "\$generatesCss claims {$key} writes CSS in {$file}, and that file never "
        . 'mentions custom_css_public. Nothing is generated.');
}
foreach ($knownDead as $key => $ticket) {
    dc_fail(preg_match('/SS-\d{3,}/', $ticket) === 1 ? ''
        : "\$knownDead entry for {$key} has no ticket number. Every knowingly "
        . 'dead control needs one, or the list is just a way of hiding them.');
}
$failures = array_values(array_filter($failures));

/* ── Report ─────────────────────────────────────────────────────────────── */
echo "Controls declared by the admin, and what consumes them\n\n";
printf("  %-30s %-12s %s\n", 'KEY', 'VERDICT', 'CONSUMER');
foreach ($report as [$key, $verdict, $strict, $legacy]) {
    $who = $strict !== [] ? implode(', ', array_slice($strict, 0, 2))
         : ($verdict === 'CSS-AT-SAVE' ? $generatesCss[$key]
         : ($legacy !== [] ? 'legacy only: ' . implode(', ', $legacy) : 'nothing'));
    printf("  %-30s %-12s %s\n", $key, $verdict, substr($who, 0, 60));
}
echo "\n  " . count($report) . " controls over " . count($pages) . " page(s), "
   . count($candidates) . " files searched.\n";
echo "  NOT AUDITED YET: " . implode(', ', $notAuditedYet) . ".\n";
echo "  THIS IS A FLOOR, NOT A CEILING: it proves a control has a consumer, "
   . "never that it works.\n";
foreach ($notices as $n) echo "  NOTE: {$n}\n";

if ($failures) {
    fwrite(STDERR, "\nFAIL declared controls (" . count($failures) . ")\n  - "
        . implode("\n  - ", $failures) . "\n");
    exit(1);
}
echo "\nPASS every declared control has a consumer, or a ticket saying why not\n";
// ===== SNAPSMACK EOF =====
