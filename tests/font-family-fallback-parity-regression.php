<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * FONT-FAMILY GENERIC FALLBACK IS CATEGORY-AWARE AND SHARED (SS-1214).
 *
 * A compiled font-family declaration is `"<chosen>", <generic>`. The generic
 * must match the chosen face's category: a monospace face (DotMatrix, Courier,
 * Tiny5, Anonymous, anything "Mono") falls back to a monospace stack, not to
 * sans-serif -- otherwise, when the custom face is unavailable, the text reads
 * as a proportional sans instead of monospace. That was the IMPACT PRINTER
 * footer pixel-parity failure: the presentation builder emitted
 * `"DotMatrix", sans-serif` while the recompiler emitted
 * `"DotMatrix", 'Courier New', monospace` for the SAME font.
 *
 * Root cause was drift: the monospace-aware branch lived inline and identical
 * in core/skin-css-recompile.php and core/skin-compile-mobile.php, and was
 * MISSING from core/skin-presentation.php. The three now share one helper,
 * core/font-stack.php, so they cannot diverge again. This test proves the
 * behaviour AND the structural single-source that prevents re-drift.
 *
 * WHAT THIS CANNOT SEE: a rendered glyph. It checks emitted CSS, not a browser.
 * Codex's computed-font matrix is what proves the visible footer face.
 */

$root = dirname(__DIR__);
if (!defined('BASE_URL')) define('BASE_URL', '/');
require_once $root . '/core/font-stack.php';

$pass = 0; $fail = 0;
function ff(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

echo "THE HELPER IS CATEGORY-AWARE\n";
$mono = ['DotMatrix', 'DotMatrix-Bold', 'DotMatrix-Expanded-Bold', 'Space Mono',
         'Courier Prime', 'Anonymous Pro', 'Tiny5', 'JetBrains Mono'];
foreach ($mono as $f) {
    ff(snapsmack_font_family_generic_fallback($f) === "'Courier New', monospace",
       "{$f} -> a monospace fallback");
}
$prop = ['Raleway', 'Georgia', 'Inter', 'Helvetica', 'DM Sans', 'Libre Baskerville'];
foreach ($prop as $f) {
    ff(snapsmack_font_family_generic_fallback($f) === 'sans-serif',
       "{$f} -> sans-serif (unchanged behaviour)");
}

echo "\nTHE PRESENTATION BUILDER NOW EMITS THE MONOSPACE FALLBACK (the fixed bug)\n";
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-presentation.php';
// IMPACT PRINTER footer_font_family default is the monospace DotMatrix.
$pres = snapsmack_skin_presentation(['footer_font_family' => 'DotMatrix'], 'impact-printer');
$css = (string)($pres['style'] ?? '');
ff(str_contains($css, '"DotMatrix", \'Courier New\', monospace'),
   'footer DotMatrix emits "DotMatrix", \'Courier New\', monospace');
ff(!str_contains($css, '"DotMatrix", sans-serif'),
   'and no longer emits the wrong "DotMatrix", sans-serif');

echo "\nONE SOURCE OF TRUTH -- THE THREE EMITTERS CANNOT DRIFT AGAIN\n";
// Structural: each emitter requires the shared helper, calls it, and no longer
// carries its own inline `$fallback = 'sans-serif'` monospace branch. This is
// what makes the recompiler and mobile outputs provably identical to the
// presentation builder without invoking their DB-bound compile here.
$emitters = [
    'core/skin-presentation.php',
    'core/skin-css-recompile.php',
    'core/skin-compile-mobile.php',
];
foreach ($emitters as $rel) {
    $src = (string)@file_get_contents($root . '/' . $rel);
    ff($src !== '' && str_contains($src, "require_once __DIR__ . '/font-stack.php'"),
       "{$rel} requires the shared font-stack helper");
    ff(str_contains($src, 'snapsmack_font_family_generic_fallback('),
       "{$rel} builds its font-family fallback through the helper");
    ff(!str_contains($src, "\$fallback = 'sans-serif';"),
       "{$rel} carries no inline duplicated 'sans-serif' fallback block");
}

echo "\n{$pass} passed, {$fail} failed\n";
if ($fail > 0) { echo "Font-family fallback parity: FAIL ({$fail}).\n"; exit(1); }
echo "Font-family fallback parity: PASS ({$pass} assertions).\n";
echo "  NOT CHECKED: the rendered footer glyph. This reads emitted CSS, not a\n";
echo "  browser. Codex's computed-font matrix in _continuity is what proves the\n";
echo "  visible face and the installed save/reload.\n";

// ===== SNAPSMACK EOF =====
