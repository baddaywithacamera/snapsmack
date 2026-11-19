<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * SS-1446 — the landing layout is a CMS setting, not skin logic.
 *
 * WHAT THE PM ASKED FOR: ALFRED's landing uses the shared SNAPSMACK mosaic
 * engine as a square grid, three across, "provided by the CMS, not skin
 * logic". mccormickphotography.com is a strict ALFRED site.
 *
 * WHY THAT PHRASE IS THE WHOLE DESIGN. The nearest existing control,
 * scroll_wall_layout, is declared per skin and read in core through an
 * `if ($skin === 'scroll')` branch. Copying that shape for ALFRED would have
 * put a seventh file in core/ keyed by skin name, which is the thing that
 * broke CHAPLIN: markup on the CMS rail, stylesheet on the skin rail, and a
 * page component named after a skin instead of after what it does.
 *
 * So this is keyed by FUNCTION -- 'landing' -- and gated on the option
 * existing, not on a list of skin names. Any skin that declares
 * landing_layout gets it and none of them is named in core.
 *
 * WHAT THE SKIN RECEIVES: a bare class name and a bounded number. It prints
 * the class and branches on nothing. The column count reaches the stylesheet
 * through the manifest's selector/property declaration into a skin-owned
 * custom property, which the skin maps to the shared --ss-square-cols input.
 * No CSS is assembled in PHP and there is no inline style.
 *
 * Rendered geometry remains the appearance owner's browser-test concern. This
 * source test proves only the CMS contract, declared control, and adapter.
 */

$root = dirname(__DIR__);
require_once $root . '/core/skin-presentation.php';
require_once $root . '/core/public-skin-presentation.php';

$failures = [];

function ll_check(string $label, bool $ok, array &$failures): void {
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures[] = $label;
    }
}

function ll_code_only(string $php): string {
    $out = '';
    foreach (@token_get_all($php) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
            $out .= $t[1];
            continue;
        }
        $out .= $t;
    }
    return $out;
}

echo "SS-1446 — landing layout is CMS-provided\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — the square layout produces a class and a column count\n";
// ─────────────────────────────────────────────────────────────────────────────
$p = snapsmack_public_skin_presentation('alfred', [
    'landing_layout' => 'square', 'landing_square_cols' => '3',
]);
ll_check('the presentation carries a landing block', isset($p['landing']), $failures);
ll_check('keyed by FUNCTION, not by skin name: there is no alfred key',
         !isset($p['alfred']), $failures);
ll_check('the layout the owner picked is reported back',
         ($p['landing']['layout'] ?? '') === 'square', $failures);
ll_check('the CMS supplies the engine class, so the skin decides nothing',
         ($p['landing']['grid_class'] ?? '') === 'snap-mosaic-wall ss-square-wall', $failures);
ll_check('and three across, as asked',
         ($p['landing']['square_cols'] ?? 0) === 3, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — it is skin-agnostic\n";
// ─────────────────────────────────────────────────────────────────────────────
// The point of the design. A second skin declaring the same option gets the
// same answer without being named anywhere in core/.
$other = snapsmack_public_skin_presentation('galleria', [
    'landing_layout' => 'square', 'landing_square_cols' => '4',
]);
ll_check('another skin declaring the option gets the same class',
         ($other['landing']['grid_class'] ?? '') === 'snap-mosaic-wall ss-square-wall', $failures);
ll_check('with its own column count', ($other['landing']['square_cols'] ?? 0) === 4, $failures);

$src = ll_code_only((string)file_get_contents($root . '/core/public-skin-presentation.php'));
$start = strpos($src, "array_key_exists('landing_layout'");
// Slice to the END of the landing block, not a fixed window. My first version
// took 700 characters and ran straight into the `if ($skin === 'rational-geo')`
// block that follows, so the "names no skin" check failed on correct code --
// it was reading somebody else's branch.
$end   = $start === false ? false : strpos($src, 'if ($skin ===', $start);
$block = ($start === false || $end === false) ? '' : substr($src, $start, $end - $start);
ll_check('the landing block was located in source', $block !== '', $failures);
ll_check('and it names no skin at all',
         $block !== '' && !str_contains($block, '$skin ==='), $failures);

// NO CSS IS ASSEMBLED IN PHP. The property name is the tell: if core were
// building the declaration it would have to contain the custom property's
// name. That literal belongs in the manifest, which STAGE 4 checks, and
// nowhere in core.
ll_check('the property name appears nowhere in core: when the CSS is generated '
         . 'it will be generated from the manifest, not built here',
         !str_contains($src, '--ss-square-cols'), $failures);
ll_check('and no static style attribute is produced',
         $block !== '' && !str_contains($block, 'style='), $failures);

// A skin that does not declare the option is untouched. This matters: 29 other
// skins must not acquire a landing block they never asked for.
$none = snapsmack_public_skin_presentation('alfred', []);
ll_check('a skin that does not declare the option gets no landing block',
         !isset($none['landing']), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — the values are BOUNDED, because they reach a stylesheet\n";
// ─────────────────────────────────────────────────────────────────────────────
foreach ([['2', 3, 'below the minimum'], ['9', 5, 'above the maximum'],
          ['', 3, 'empty'], ['3; }  body{display:none', 3, 'an injection attempt']] as $case) {
    [$in, $want, $what] = $case;
    $r = snapsmack_public_skin_presentation('alfred',
        ['landing_layout' => 'square', 'landing_square_cols' => $in]);
    ll_check("a column count that is {$what} is clamped to {$want}",
             ($r['landing']['square_cols'] ?? null) === $want, $failures);
}

foreach (['nonsense', 'SQUARE', '../../etc', ''] as $bad) {
    $r = snapsmack_public_skin_presentation('alfred', ['landing_layout' => $bad]);
    ll_check("an unrecognised layout '" . ($bad === '' ? '(empty)' : $bad)
             . "' falls back to the skin's own grid, with no class",
             ($r['landing']['layout'] ?? null) === 'default'
             && ($r['landing']['grid_class'] ?? null) === '', $failures);
}

// The three layout words that are accepted but not wired must produce NO
// class rather than a half-wired one. An owner picking 'mosaic' today gets the
// skin's grid, not an engine with no rules behind it.
foreach (['columns', 'rows', 'mosaic'] as $unwired) {
    $r = snapsmack_public_skin_presentation('alfred', ['landing_layout' => $unwired]);
    ll_check("'{$unwired}' is accepted and wired to nothing yet, rather than half-wired",
             ($r['landing']['layout'] ?? null) === $unwired
             && ($r['landing']['grid_class'] ?? null) === '', $failures);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — ALFRED declares it, and the column count generates its own CSS\n";
// ─────────────────────────────────────────────────────────────────────────────
$manifest = json_decode((string)file_get_contents($root . '/skins/alfred/manifest.json'), true);
$opts = $manifest['options'] ?? [];
ll_check('ALFRED declares landing_layout', isset($opts['landing_layout']), $failures);
ll_check('defaulting to square, which is what Sean asked for',
         ($opts['landing_layout']['default'] ?? '') === 'square', $failures);
ll_check('ALFRED declares landing_square_cols', isset($opts['landing_square_cols']), $failures);
ll_check('defaulting to 3 across',
         (string)($opts['landing_square_cols']['default'] ?? '') === '3', $failures);
ll_check('bounded 3 to 5 in the declaration too, not only in PHP',
         (string)($opts['landing_square_cols']['min'] ?? '') === '3'
         && (string)($opts['landing_square_cols']['max'] ?? '') === '5', $failures);

// The PM placed the reusable square-wall rules with the mosaic engine.  The
// setting can therefore use the normal declared-option CSS path instead of a
// skin-specific inline style or a data attribute interpreted by new code.
ll_check('the column control targets ALFRED\'s stable grid hook',
         ($opts['landing_square_cols']['selector'] ?? '') === '.alfred-archive-grid', $failures);
ll_check('and writes the skin-owned input mapped to the shared engine',
         ($opts['landing_square_cols']['property'] ?? '') === '--alfred-square-cols', $failures);
$alfredCss = (string)file_get_contents($root . '/skins/alfred/style.css');
ll_check('the skin maps that input to the shared engine variable without copying geometry',
         str_contains($alfredCss, '.alfred-archive-grid.ss-square-wall')
         && str_contains($alfredCss, '--ss-square-cols: var(--alfred-square-cols, 3)'), $failures);
ll_check('and the hint names the shared engine that now owns the crop',
         stripos((string)($opts['landing_square_cols']['hint'] ?? ''), 'shared mosaic engine') !== false,
         $failures);
ll_check('and only shown when square is chosen',
         ($opts['landing_square_cols']['enable_when']['landing_layout'] ?? '') === 'square',
         $failures);
$alfredLayout = (string)file_get_contents($root . '/skins/alfred/layout.php');
ll_check('and the layout does not print a write-only column-count attribute',
         !str_contains($alfredLayout, 'data-square-cols'), $failures);

// The shared engine has to be loaded, or the class means nothing even once
// the rules exist.
$scripts = $manifest['require_scripts'] ?? [];
// 'smack-mosaic' is the handle the inventory actually knows
// (core/manifest-inventory.php:726 -> assets/js/ss-engine-mosaic.js plus its
// CSS). My first version declared 'asset:public:ss-engine-mosaic', which looks
// plausible and resolves to nothing: tests/declared-engine-reaches-skin-
// regression.php caught it at once -- "6 scripts declared, 5 resolve. A handle
// that does not resolve is silently dropped: the control renders and never
// works." Exactly the fault class this week keeps producing.
ll_check('ALFRED loads the shared mosaic engine by its real handle',
         in_array('smack-mosaic', $scripts, true), $failures);
ll_check('and the engine recognises the square mode this sends it',
         str_contains((string)file_get_contents($root . '/assets/js/ss-engine-mosaic.js'),
                      '.snap-mosaic-wall.ss-square-wall'), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1446 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "SS-1446 PASS — the landing layout is a CMS setting keyed by function, its\n";
echo "values are bounded, ALFRED declares it square and three across, and the\n";
echo "column count reaches the stylesheet as generated CSS.\n";
echo "The appearance owner moved the square-wall geometry into the shared\n";
echo "mosaic stylesheet. Rendered ALFRED and SCROLL geometry is verified by\n";
echo "the separate browser matrix and is not claimed by this source test.\n";
// ===== SNAPSMACK EOF =====
