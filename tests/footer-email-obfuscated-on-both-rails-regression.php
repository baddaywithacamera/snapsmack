<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The footer email gets the same treatment whichever rail renders it.
 *
 * FOUND BY THE SS-1446 copyright-owner read-only pass, which was looking at a
 * different slot. core/footer.php:48 -- the classic rail -- reverses the
 * address and wraps it in .reverse-email, and assets/css/public-base.css turns
 * it back the right way round for a reader. core/skin-render-helpers.php, the
 * strict rail, printed it straight into the markup.
 *
 * So the same footer_slot_email setting left an owner's address harvestable on
 * a strict skin and obfuscated on a classic one, decided by nothing more than
 * which skin they picked. Nobody made that decision.
 *
 * IT IS WEAK PROTECTION, and this test is not pretending otherwise: strrev
 * plus a CSS direction flip stops a scraper that reads page text and nothing
 * cleverer. The point is that one rail had it and the other did not.
 * mccormickphotography.com is a strict ALFRED site, so the strict rail is the
 * one carrying Sean's own address.
 *
 * WHAT THIS PINS, and the third one is the one that matters:
 *   1. Both rails reverse the visible text.
 *   2. Neither reverses the href, because a mailto must still work.
 *   3. The CSS rule that un-reverses it EXISTS, and reaches every rail. A
 *      reversal shipped without it displays a backwards address to a reader,
 *      which is worse than no obfuscation at all.
 */

$root = dirname(__DIR__);
$failures = [];

function fe_check(string $label, bool $ok, array &$failures): void {
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures[] = $label;
    }
}

function fe_code_only(string $php): string {
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

echo "Footer email — same obfuscation on both rails\n\n";

$email = 'sean@example.ca';
$rev   = strrev($email);          // ac.elpmaxe@naes

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — the STRICT rail, rendered\n";
// ─────────────────────────────────────────────────────────────────────────────
require_once $root . '/core/skin-render-helpers.php';

$out = (string)snap_render_html(snap_render_component('footer', [
    'footer' => ['slots' => [['kind' => 'email', 'email' => $email]]],
]));
fe_check('the footer component renders something', trim($out) !== '', $failures);
fe_check('the visible address is REVERSED', str_contains($out, $rev), $failures);
fe_check('and the plain address is NOT sitting in the page text',
         !str_contains(strip_tags($out), $email), $failures);
fe_check('the mailto href is NOT reversed, so clicking it still works',
         str_contains($out, 'mailto:' . $email), $failures);
fe_check('and the reversal is wrapped in the class that undoes it',
         str_contains($out, 'class="reverse-email"'), $failures);

// A malformed address must still be dropped rather than reversed into nonsense.
$bad = (string)snap_render_html(snap_render_component('footer', [
    'footer' => ['slots' => [['kind' => 'email', 'email' => 'not-an-address']]],
]));
fe_check('a malformed address is not printed at all',
         !str_contains($bad, 'not-an-address') && !str_contains($bad, 'sserdda-na-ton'),
         $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — the CLASSIC rail does the same thing, read from its source\n";
// ─────────────────────────────────────────────────────────────────────────────
// core/footer.php is a template that needs $settings and the admin context, so
// it is not included here. What must be true of it is that it still does what
// the strict rail now copies, and that is readable from comment-stripped code.
$classic = fe_code_only((string)file_get_contents($root . '/core/footer.php'));
fe_check('the classic footer still reverses the address',
         str_contains($classic, 'strrev($raw_email)'), $failures);
fe_check('and still wraps it in the same class',
         str_contains($classic, 'class="reverse-email"'), $failures);
fe_check('and still leaves the mailto href unreversed',
         str_contains($classic, 'mailto:\' . htmlspecialchars($raw_email)'), $failures);

$strict = fe_code_only((string)file_get_contents($root . '/core/skin-render-helpers.php'));
fe_check('the strict rail uses the same two mechanisms, not a new one',
         str_contains($strict, 'strrev($email)')
         && str_contains($strict, 'class="reverse-email"'), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — THE RULE THAT UNDOES IT, and that it reaches both rails\n";
// ─────────────────────────────────────────────────────────────────────────────
// This is the check worth having. A reversal without this CSS shows a reader a
// backwards address: a self-inflicted visible defect, on every skin at once.
$css = (string)file_get_contents($root . '/assets/css/public-base.css');
fe_check('.reverse-email exists in the always-loaded base stylesheet',
         str_contains($css, '.reverse-email'), $failures);
fe_check('and it actually flips the text, rather than just existing',
         (static function (string $css): bool {
             $i = strpos($css, '.reverse-email');
             if ($i === false) return false;
             $rule = substr($css, $i, 200);
             return str_contains($rule, 'direction') && str_contains($rule, 'rtl')
                 && str_contains($rule, 'unicode-bidi');
         })($css), $failures);

// THE CLASSIC RAIL gets it from core/meta.php, which no strict skin loads.
$meta = (string)file_get_contents($root . '/core/meta.php');
fe_check('the classic rail loads the base stylesheet (core/meta.php)',
         str_contains($meta, 'assets/css/public-base.css'), $failures);

// THE STRICT RAIL gets it from the one shared head component. Checked by
// rendering that component, not by reading the twenty-five layouts: five of
// them are two lines that delegate the whole page, so grepping a layout for
// the stylesheet name finds nothing and means nothing.
$head = (string)snap_render_html(snap_render_component('document-head', [
    'title' => 'x',
    'site'  => ['site_name' => 'x',
                'base_style_url' => '/assets/css/public-base.css?v=1',
                'skin_style_url' => '/skins/alfred/style.css?v=1'],
]));
fe_check('the strict rail loads the base stylesheet (document-head component)',
         str_contains($head, 'assets/css/public-base.css'), $failures);

// And the strict view model is what supplies that url, so a strict page that
// renders at all has it.
$vm = fe_code_only((string)file_get_contents($root . '/core/skin-view-model.php'));
fe_check('the strict view model supplies base_style_url unconditionally',
         str_contains($vm, "assets/css/public-base.css"), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    fwrite(STDERR, 'Footer email FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "PASS — both rails reverse the visible address, neither reverses the href,\n";
echo "and the CSS that turns it back round is in the stylesheet both rails load.\n";
echo "NOT COVERED: no browser. That the flipped text READS correctly to a human\n";
echo "at the supported widths is a rendered check, and it is not claimed here.\n";
// ===== SNAPSMACK EOF =====
