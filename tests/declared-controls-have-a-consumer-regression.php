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
$pages = [
    'smack-appearance-archive.php',
    'smack-appearance-solo.php',
    'smack-appearance-static.php',
    'smack-globalvibe.php',
];

// All four appearance screens are audited. Anything added later belongs here
// until its controls are traced, because adding a page before that makes a
// wall of failures nobody reads.
$notAuditedYet = [];

/* ── Keys that act by generating CSS when the admin saves ───────────────── */
$generatesCss = [
    // smack-appearance-archive.php:110-128. Upserts two rules into
    // custom_css_public: arch_opt:grid_border over four grid selectors, and
    // arch_opt:masonry_border over #justified-grid .justified-item.
    'archive_thumb_frame' => 'smack-appearance-archive.php:110-128, custom_css_public',
];

/* ── Controls whose ONLY consumer is meant to be the Photo Wall ───────────
 * Wall-only is the right answer for a Photo Wall control and a fault for
 * anything else. Without this list the gate would pass static_content_width,
 * whose only consumer is core/meta.php -- true, and useless, because that
 * means a STATIC PAGE setting that applies on the Photo Wall alone (SS-1121).
 * THE GATE CANNOT JUDGE THAT ON ITS OWN: both look identical from the code.
 * So the Photo Wall's own controls are named, and any other wall-only control
 * has to be explained. */
$wallOnlyExpected = [
    'wall_rows', 'wall_gap', 'wall_theme', 'wall_friction',
    'wall_dragweight', 'wall_reflect',
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
    // Solo Image Appearance. The FEATURES work -- core/parser.php:557 and :576
    // convert [pullquote] and [dropcap] unconditionally -- so these are
    // switches nothing consults, not missing features.
    'drop_caps_enabled'   => 'SS-1122 -- nothing reads it; the shortcode always renders',
    'pull_quotes_enabled' => 'SS-1122 -- nothing reads it; the shortcode always renders',
    // Static Page Appearance. Consumed ONLY by core/meta.php, so wall-only:
    // a static-page setting that applies on the Photo Wall alone.
    'static_content_width'  => 'SS-1121 -- core/meta.php only, so it reaches the Photo Wall and no static page',
    'static_content_gutter' => 'SS-1121 -- core/meta.php only, same as the width',
];

/* ── Where a consumer may live, and what that means ──────────────────────
 *
 * THIS WAS A BINARY LIST AND THE BINARY WAS WRONG. It held gallery-wall.php as
 * "legacy", and gallery-wall.php is the PHOTO WALL -- a live page, linked from
 * core/header.php:223 as FLOATING GALLERY and from the shortcode parser. The
 * six wall_* controls on Global Vibe are read there and work. Calling that
 * file legacy would have declared six working controls dead, which is how a
 * gate teaches people to ignore it.
 *
 * So there are THREE answers, not two:
 *
 *   STRICT      a consumer on a path a strict skin runs. All 30 renderable
 *               skins are strict, so this is the one that means "on the sites".
 *   WALL ONLY   the only consumers are the Photo Wall and the head it includes.
 *               core/meta.php belongs here rather than with the dead files:
 *               it IS reached, by gallery-wall.php and nothing else (verified
 *               -- it is the only file in the repository that includes it).
 *               Wall-only is CORRECT for a Photo Wall control and a FAULT for
 *               anything else, which is why $wallOnlyExpected exists below.
 *   UNREACHED   no page runs it. archive.php is a legacy entry point that
 *               redirects (core/public-route-aliases.php:114-121) and no
 *               strict skin runs it; core/footer.php and footer-scripts.php
 *               are the pre-strict footer. NOT INDEPENDENTLY VERIFIED the way
 *               meta.php was: this rests on the repository's own statements.
 */
$wallOnlyFiles = ['gallery-wall.php', 'core/meta.php'];
$unreachedFiles = ['archive.php', 'core/footer.php', 'core/footer-scripts.php'];
// The settings machinery is not a consumer: core/skin-settings.php overlays
// per-skin values onto bare keys and core/manifest-inventory.php inventories
// them. Both mention every key by nature, so counting them would make this
// test pass for everything.
$machinery = ['core/skin-settings.php', 'core/manifest-inventory.php'];

/* ── Collect the files that could consume a setting ─────────────────────── */
$searchRoots = ['core', 'assets/js', 'assets/css', 'skins'];
$searchFiles = ['index.php', 'archive.php', 'gallery-wall.php'];
/* THE ADMIN SIDE IS A CONSUMER TOO, and leaving it out made this gate
 * confidently wrong. image_recompress_enabled is read at
 * smack-post-gram.php:225 and smack-post-solo.php:388 -- the UPLOAD pages,
 * which is exactly where recompression happens -- and seeded by
 * install.php:1457. With only the public roots searched it looked dead. Any
 * setting consumed at upload or post time would have.
 *
 * Every top-level PHP file is searched, MINUS the pages being audited: a
 * declaring page mentions its own keys, so counting it would make every
 * control look consumed and the gate would pass on anything. */
foreach (glob($root . '/*.php') ?: [] as $gatePath) {
    $gateRel = basename($gatePath);
    if (in_array($gateRel, $pages, true)) continue;
    $searchFiles[] = $gateRel;
}
$searchFiles = array_values(array_unique($searchFiles));
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
$rowsPerPage = [];
foreach ($pages as $page) {
    $rowsPerPage[$page] = 0;
    $src = (string) @file_get_contents($root . '/' . $page);
    if ($src === '') { dc_fail("admin page {$page} could not be read"); continue; }

    preg_match_all('/name="settings\[([a-z0-9_]+)\]"/', $src, $m);
    $keys = array_values(array_unique($m[1]));
    sort($keys);
    // A FLOOR OF ONE, NOT FIVE. This read "< 5" and was written against the
    // archive page's fourteen controls; Static Page Appearance legitimately
    // has two, so the guard failed a page for being small and skipped its
    // controls entirely -- which hid them from the table it prints. The real
    // question is whether the extraction found ANYTHING on a page that
    // declares controls; a general break shows as a collapse in the total,
    // which is checked after the loop.
    if ($keys === []) {
        dc_fail("no controls were found on {$page} at all; either the page "
            . 'stopped declaring any, or the name="settings[...]" extraction '
            . 'no longer matches its markup');
        continue;
    }

    foreach ($keys as $key) {
        $strict = [];
        $wall = [];
        $unreached = [];
        foreach ($contents as $rel => $body) {
            if ($body === '' || strpos($body, $key) === false) continue;
            if (in_array($rel, $machinery, true)) continue;
            if (in_array($rel, $wallOnlyFiles, true)) { $wall[] = $rel; continue; }
            if (in_array($rel, $unreachedFiles, true)) { $unreached[] = $rel; continue; }
            $strict[] = $rel;
        }

        if ($strict !== []) {
            $verdict = 'WIRED';
        } elseif (isset($generatesCss[$key])) {
            $verdict = 'CSS-AT-SAVE';
        } elseif ($wall !== []) {
            $verdict = in_array($key, $wallOnlyExpected, true) ? 'WALL ONLY'
                     : (isset($knownDead[$key]) ? 'KNOWN DEAD' : 'WALL ONLY, UNEXPECTED');
        } elseif (isset($knownDead[$key])) {
            $verdict = 'KNOWN DEAD';
        } else {
            $verdict = 'NO CONSUMER';
        }
        $report[] = [$key, $verdict, $strict, array_merge($wall, $unreached)];
        $rowsPerPage[$page]++;

        if ($verdict === 'NO CONSUMER') {
            dc_fail("{$page} offers a control that NOTHING consumes: {$key}. "
                . ($unreached !== [] ? 'Its only mentions are on a path no page '
                    . 'runs (' . implode(', ', $unreached) . '). '
                  : 'It is mentioned nowhere else in the repository at all. ')
                . 'Either give it a consumer, or remove it from the admin, or -- '
                . 'if it is knowingly dead for now -- add it to $knownDead in '
                . 'this file WITH A TICKET NUMBER. Do not add it without one.');
        }

        if ($verdict === 'WALL ONLY, UNEXPECTED') {
            dc_fail("{$page} offers {$key}, and its only consumer is the Photo "
                . 'Wall (' . implode(', ', $wall) . '). That is correct for a '
                . 'Photo Wall control and a fault for anything else: a setting '
                . 'on this screen that applies only on gallery-wall.php reaches '
                . 'no skin page. If it IS a Photo Wall control, add it to '
                . '$wallOnlyExpected; if it is not, it needs a ticket in '
                . '$knownDead. SS-1121 is the live example.');
        }

        // An allow-list that nobody prunes becomes a lie, so being wired while
        // listed as dead is also a failure -- a welcome one.
        if (($strict !== [] || ($wall !== [] && in_array($key, $wallOnlyExpected, true)))
            && isset($knownDead[$key])) {
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

/* ── A page that contributed nothing is a silent hole, not a pass ──────
 * THIS GUARD EXISTS BECAUSE I WROTE THE BUG IT CATCHES, and the honest
 * version of that story is narrower than I first wrote here. The per-page
 * floor above read "< 5 controls means the extraction broke", and the
 * two-control Static Page screen tripped it. It did NOT print PASS -- it
 * called dc_fail and the gate failed. The fault was that the failure BLAMED
 * THE WRONG THING (it said the markup had changed shape; the markup was fine
 * and the floor was wrong) and that it then `continue`d, so the page's two
 * controls never reached the table either.
 *
 * So this guard is not what catches that old bug. It catches the NEXT one: any
 * future guard that skips a page WITHOUT failing, which would be silent. The
 * mutation that proves it is a bare `continue` with no dc_fail above it, and
 * that mutation is in the proof run for this file. The page list and the pages
 * actually audited have to match, whatever the reason they stop matching.
 */
foreach ($rowsPerPage as $page => $n) {
    dc_fail($n > 0 ? ''
        : "{$page} is in \$pages and contributed NOTHING to the audit. Some "
        . 'guard above skipped it instead of failing, so the gate reported on '
        . 'fewer pages than it claims to cover. Make that guard fail, not '
        . 'continue.');
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
printf("  %-30s %-22s %s\n", 'KEY', 'VERDICT', 'CONSUMER');
foreach ($report as [$key, $verdict, $strict, $legacy]) {
    $who = $strict !== [] ? implode(', ', array_slice($strict, 0, 2))
         : ($verdict === 'CSS-AT-SAVE' ? $generatesCss[$key]
         : ($legacy !== [] ? implode(', ', $legacy) : 'nothing'));
    printf("  %-30s %-22s %s\n", $key, $verdict, substr($who, 0, 56));
}
// A general break in the extraction shows as a collapse in the total, so the
// floor lives here rather than per page -- 37 were traced on 31 October 2026.
dc_fail(count($report) >= 30 ? ''
    : 'only ' . count($report) . ' controls were found across all '
    . count($pages) . ' pages; 37 were traced on 31 October 2026, so the '
    . 'extraction has broken rather than the pages having shrunk');
$failures = array_values(array_filter($failures));

echo "\n  " . count($report) . " controls over " . count($pages) . " page(s), "
   . count($candidates) . " files searched.\n";
echo "  NOT AUDITED YET: "
   . ($notAuditedYet ? implode(', ', $notAuditedYet) : 'nothing; all four screens are traced') . ".\n";
echo "  WALL ONLY means the Photo Wall and nothing else. Right for a wall "
   . "control, a fault for any other (SS-1121).\n";
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
