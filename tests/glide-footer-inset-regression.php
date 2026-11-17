<?php
/**
 * GLIDE landing footer — the visible line sits the same distance from the left
 * edge and the bottom edge.
 *
 * Sean reported that on the GLIDE wall the footer line (copyright, email,
 * theme, powered by, RSS) sat tighter to the bottom edge than to the left.
 * Measured in a browser at 1920x1080, 1440x900 and 1024x768 BEFORE the fix:
 *
 *     width   text left gap   text bottom gap
 *     1920    24.00px         30.89px
 *     1440    24.00px         30.63px
 *     1024    24.00px         30.63px
 *
 * So the line actually sat ~6.6-6.9px FURTHER from the bottom than from the
 * left — the mismatch was real, the direction was the other way. Both edges
 * were written as the literal 24px, and the box bottom is not the text bottom:
 * the footer links are inline-blocks with 5px of vertical padding, and
 * line-height 1.4 puts half of its extra leading below the descender. That
 * slack scales with the clamped font size, so no single corrected literal
 * fixes it at every width.
 *
 * AFTER the fix, measured the same way:
 *
 *     1920    24.00px         23.91px
 *     1440    24.00px         23.95px
 *     1024    24.00px         23.95px
 *
 * (The residue is sub-pixel layout rounding, not a second number.)
 *
 * What this test pins is the SHAPE that makes those numbers true, because the
 * numbers themselves need a browser and this repository has no resolvable
 * puppeteer or playwright to re-measure them in CI:
 *
 *   - one custom property, --glide-footer-inset, is the only inset number;
 *   - `left` is that property and nothing else;
 *   - `bottom` is that property minus the slack, never its own literal;
 *   - the slack is expressed with the SAME two properties that the link and
 *     paragraph rules consume, so changing the link padding or the leading
 *     cannot silently desynchronise the arithmetic from the layout;
 *   - the paragraph inherits the font size rather than declaring its own, so
 *     the `em` in the bottom calc resolves against the rendered size.
 *
 * The rule is scoped to body:has(.glide-page), which exists only in GLIDE, so
 * no other skin is touched; that is asserted too rather than assumed.
 *
 * Static stylesheet assertions — no DB, no site, no browser.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root  = dirname(__DIR__);
$fails = 0;

function gfi(string $label, bool $ok): void {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fails++;
}

$css_path = $root . '/skins/glide/style.css';
$css = (string) @file_get_contents($css_path);
gfi('skins/glide/style.css is readable', $css !== '');
if ($css === '') {
    fwrite(STDERR, "\nGLIDE footer inset: style.css missing — cannot check.\n");
    exit(1);
}

/**
 * Cut one declaration block out by its selector, so a later rule that happens
 * to mention the same property cannot satisfy an assertion about this one.
 */
function gfi_block(string $css, string $selector): string
{
    $at = strpos($css, $selector . ' {');
    if ($at === false) return '';
    $open = strpos($css, '{', $at);
    $end  = strpos($css, '}', $open);
    if ($open === false || $end === false) return '';
    return substr($css, $open + 1, $end - $open - 1);
}

$sel_box = 'body:has(.glide-page) > #system-footer';
$box = gfi_block($css, $sel_box);
$par = gfi_block($css, $sel_box . ' p');
$lnk = gfi_block($css, $sel_box . ' a');

gfi('the landing footer rule was found',          $box !== '');
gfi('its paragraph rule was found',               $par !== '');
gfi('its link rule was found',                    $lnk !== '');
if ($box === '' || $par === '' || $lnk === '') {
    fwrite(STDERR, "\nGLIDE footer inset: the landing footer rules moved or were renamed.\n");
    exit(1);
}

// ── One value, named, declared on the rule that uses it ────────────────────
gfi(
    'a single --glide-footer-inset holds the distance from the window',
    (bool) preg_match('/--glide-footer-inset:\s*\d+(?:\.\d+)?px\s*;/', $box)
);

// ── Both edges are driven by it ────────────────────────────────────────────
if (!preg_match('/(?<!-)\bleft:\s*([^;]+);/', $box, $m_left))   { $m_left   = [1 => '']; }
if (!preg_match('/\bbottom:\s*([^;]+);/s',    $box, $m_bottom)) { $m_bottom = [1 => '']; }
$left   = trim($m_left[1]);
$bottom = trim($m_bottom[1]);

gfi('left is the inset property alone', $left === 'var(--glide-footer-inset)');
gfi(
    'bottom is derived from the same property',
    str_contains($bottom, 'var(--glide-footer-inset)')
);

// The specific fault was two literal numbers. Neither edge may carry one.
gfi(
    'left carries no length of its own',
    !preg_match('/\d+(?:\.\d+)?(px|rem|em|%|vw|vh)/', $left)
);
gfi(
    'bottom carries no length of its own — only the named properties',
    !preg_match('/\d+(?:\.\d+)?(px|rem|%|vw|vh)/', (string) preg_replace('/var\([^)]*\)/', '', $bottom))
);

// ── The slack is expressed in the values the layout actually uses ──────────
// If the link padding or the leading is edited, the arithmetic follows. That is
// the whole reason they are properties and not numbers.
gfi(
    'bottom subtracts the link padding by name',
    str_contains($bottom, 'var(--glide-footer-link-pad)')
);
gfi(
    'bottom subtracts the half-leading by name',
    str_contains($bottom, 'var(--glide-footer-leading)')
);
gfi(
    'it subtracts HALF the extra leading, not all of it',
    (bool) preg_match('/\(\s*var\(--glide-footer-leading\)\s*-\s*1\s*\)\s*\*\s*\.5em/', $bottom)
);

// ── The layout consumes the same two properties ────────────────────────────
gfi(
    'the links take their vertical padding from --glide-footer-link-pad',
    (bool) preg_match('/padding-block:\s*var\(--glide-footer-link-pad\)\s*;/', $lnk)
);
gfi(
    'the paragraph takes its line-height from --glide-footer-leading',
    (bool) preg_match('/line-height:\s*var\(--glide-footer-leading\)\s*;/', $par)
);
gfi(
    'no literal padding-block survives on the links',
    !preg_match('/padding-block:\s*\d/', $lnk)
);
gfi(
    'no literal line-height survives on the paragraph',
    !preg_match('/line-height:\s*\d/', $par)
);

// ── The em in the calc must resolve against the rendered size ──────────────
// The clamp has to live on the footer, and the paragraph has to inherit it. If
// the paragraph re-declares a size, the text renders at one size while the
// bottom calc is computed from another, and the gap is wrong again.
gfi(
    'the footer declares the clamped font size',
    (bool) preg_match('/font-size:\s*clamp\(/', $box)
);
gfi(
    'the paragraph inherits that size instead of declaring its own',
    (bool) preg_match('/font-size:\s*inherit\s*;/', $par)
        && !preg_match('/font-size:\s*clamp\(/', $par)
);

// ── Nothing else wears this rule ───────────────────────────────────────────
$others = [];
foreach (glob($root . '/skins/*/style.css') ?: [] as $sheet) {
    if (basename(dirname($sheet)) === 'glide') { continue; }
    if (str_contains((string) @file_get_contents($sheet), 'glide-page')) {
        $others[] = basename(dirname($sheet));
    }
}
gfi('no other skin stylesheet references .glide-page', $others === []);
if ($others) {
    fwrite(STDERR, 'Also references .glide-page: ' . implode(', ', $others) . "\n");
}

// ── The change ships ───────────────────────────────────────────────────────
// A stylesheet edit that keeps the old version number can never reach a site
// that already installed that version.
$manifest = json_decode((string) @file_get_contents($root . '/skins/glide/manifest.json'), true);
$version  = is_array($manifest) ? ($manifest['version'] ?? '') : '';
gfi('the GLIDE manifest carries a version', $version !== '');
gfi(
    'it is past 0.1.27, the version that shipped the mismatched insets',
    $version !== '' && version_compare((string) $version, '0.1.27', '>')
);

if ($fails) {
    fwrite(STDERR, "\n{$fails} FAILURE(S): the GLIDE footer's left and bottom insets are no longer "
        . "derived from one value, so the line can drift away from one edge without the other.\n");
    exit(1);
}
echo "GLIDE footer inset: PASS\n";
exit(0);

// ===== SNAPSMACK EOF =====
