<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

/**
 * An owner's Archive Appearance frame rule survives a skin CSS recompile.
 *
 * Archive Appearance (smack-appearance-archive.php) writes the chosen thumbnail
 * frame as a marker-keyed rule (/* arch_opt:grid_border *​/ ...) into the
 * custom_css_public blob. The bug: it inserted the rule immediately BEFORE
 * /* SKIN_END *​/, i.e. INSIDE the /* SKIN_START *​/ ... /* SKIN_END *​/ region.
 * core/skin-css-recompile.php regenerates that whole region and keeps only what
 * sits OUTSIDE it, so the first skin recompile after an archive save silently
 * deleted the owner's frame -- fleet-wide -- while the admin still showed the
 * preset selected. The fix places the rule AFTER /* SKIN_END *​/ (outside the
 * region), where recompile leaves it alone.
 *
 * This proves the fix against the EXACT wipe mechanism (recompile's region
 * preg_replace) rather than the whole recompile, which needs a live DB, manifest
 * and inventory. It also checks the write path no longer inserts inside the block.
 */

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    echo ($ok ? "  ok    " : "  FAIL  ") . $what . "\n";
    $ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++;
}

// The exact region pattern core/skin-css-recompile.php uses to regenerate the block.
$skin_pattern = '/\/\* SKIN_START \*\/.*?\/\* SKIN_END \*\//s';
$regenerated  = "/* SKIN_START */\n/* SKIN_ID fresh */\n/* regenerated from manifest */\n/* SKIN_END */";
$arch_rule    = '/* arch_opt:grid_border */ .square-grid .thumb-link, .cropped-grid .thumb-link { border: 3px solid #666666 !important }';

// 1. A rule AFTER /* SKIN_END */ (the fix) survives a recompile.
$blob_after   = "/* SKIN_START */\n/* old generated */\n/* SKIN_END */\n" . $arch_rule;
cx(str_contains((string)preg_replace($skin_pattern, $regenerated, $blob_after), $arch_rule),
   'a frame rule placed AFTER /* SKIN_END */ survives a skin recompile');

// 2. A rule BEFORE /* SKIN_END */ (the old placement) is wiped -- documents the bug.
$blob_before  = "/* SKIN_START */\n/* old generated */\n" . $arch_rule . "\n/* SKIN_END */";
cx(!str_contains((string)preg_replace($skin_pattern, $regenerated, $blob_before), $arch_rule),
   'a frame rule placed BEFORE /* SKIN_END */ is deleted by a recompile (the original bug)');

// 3. The write path (smack-appearance-archive.php) now inserts AFTER /* SKIN_END */,
//    not before it.
$src = (string)file_get_contents(dirname(__DIR__) . '/smack-appearance-archive.php');
cx(str_contains($src, "str_replace('/* SKIN_END */', '/* SKIN_END */' . \"\\n\" . \$rule"),
   'the upsert inserts the rule AFTER /* SKIN_END */ (outside the regenerated region)');
cx(!str_contains($src, '$rule . "\n/* SKIN_END */"'),
   'the upsert no longer inserts the rule BEFORE /* SKIN_END */ (inside the region)');

// 4. End-to-end: the upsert's own two-step logic, replicated, places the rule so it
//    survives. (The closure is private to the save handler; the logic is mirrored.)
$blob   = "/* SKIN_START */\n/* x */\n/* SKIN_END */";
$marker = '/* arch_opt:grid_border */';
$blob   = (string)preg_replace('/' . preg_quote($marker, '/') . '[^\n]*\n?/', '', $blob);
$blob   = rtrim($blob);
$blob   = str_replace('/* SKIN_END */', '/* SKIN_END */' . "\n" . $arch_rule, $blob);
cx(str_contains((string)preg_replace($skin_pattern, $regenerated, $blob), $arch_rule),
   'end-to-end: an upsert-placed frame rule survives a recompile');

// 5. A second save REPLACES in place (no duplication), still outside the region.
$blob2  = (string)preg_replace('/' . preg_quote($marker, '/') . '[^\n]*\n?/', '', $blob);
$blob2  = rtrim($blob2);
$newrule = '/* arch_opt:grid_border */ .square-grid .thumb-link, .cropped-grid .thumb-link { border: 1px solid #ffffff !important }';
$blob2  = str_replace('/* SKIN_END */', '/* SKIN_END */' . "\n" . $newrule, $blob2);
cx(substr_count($blob2, $marker) === 1 && str_contains($blob2, $newrule) && !str_contains($blob2, '3px solid #666666'),
   'a repeated save replaces the marker rule once, with no stale duplicate');

// 6. The frame is drawn on a ::after OVERLAY (987D), not on the tile element.
//    An inset box-shadow on the tile paints UNDER the opaque photo and is
//    invisible (Sean's live /archive); the overlay sits on top. The pixel proof
//    is tests/archive-frame-overlay-visible-browser.cjs; this ties the source to
//    that contract so the two cannot drift.
cx(str_contains($src, '::after') && str_contains($src, 'pointer-events: none'),
   'the grid frame is emitted as a ::after overlay with pointer-events:none');
cx((bool)preg_match('/arch_opt:grid_border[\s\S]{0,400}position: relative[\s\S]{0,400}::after/', $src),
   'the grid tile gets position:relative so the overlay anchors to it');
cx((bool)preg_match('/justified-item::after/', $src)
   && !(bool)preg_match('/justified-item\s*\{\s*position:/', $src),
   'the masonry item gets the overlay but NOT position (the wall engine positions it absolutely)');

// Exercise the actual save-path closure, rather than a mirrored implementation.
$closureStart = strpos($src, '$upsert_rule = function');
$closureEnd = strpos($src, "\n        };", $closureStart);
eval(substr($src, $closureStart, $closureEnd - $closureStart) . "\n};");
$skinCss = ".thumb-link { box-shadow: 0 4px 20px #333; }";
$saved = $skinCss . "\n" . $arch_rule . "\n/* arch_opt:masonry_border */ .justified-item { box-shadow:none; }\n";
$upsert_rule($saved, '/* arch_opt:grid_border */', '');
$upsert_rule($saved, '/* arch_opt:masonry_border */', '');
cx($saved === $skinCss, 'none removes both CMS rules and preserves the skin shadow byte-for-byte');
$upsert_rule($saved, '/* arch_opt:grid_border */', '');
cx($saved === $skinCss, 'saving none again adds no empty marker or overlay');
$upsert_rule($saved, '/* arch_opt:grid_border */', $arch_rule);
cx(str_contains($saved, $arch_rule), 'a real frame can still be selected after none');
cx(str_contains($src, "if (\$tfp === 'none') {\n            \$grid_rule = '';\n            \$masonry_rule = '';"),
   'none routes both frame rules through removal');

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
