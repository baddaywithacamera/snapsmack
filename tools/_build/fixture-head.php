<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * Put a fixture's inlined stylesheets WHERE THE LINKS WERE.
 *
 * Both fixture generators -- render-geometry-fixtures.php and
 * render-engine-fixtures.php -- strip the skin's <link rel="stylesheet"> tags,
 * because under file:// they point at a server that is not there, and inline
 * the resolved css instead. Both used to re-insert that css immediately before
 * </head>, which put it AFTER everything else in the head.
 *
 * THAT REVERSED THE CASCADE IN EVERY FIXTURE EITHER TOOL HAS WRITTEN.
 *
 * A live strict head is, in order:
 *
 *   1. <link> the base stylesheet
 *   2. <link> the skin's style.css
 *   3. the owner's custom style
 *   4. the CMS presentation block  (skin_presentation['style'])
 *
 * At equal specificity the later rule wins, so a CMS :root variable BEATS a
 * skin's :root variable. That is the entire basis of the owner-wins ruling on
 * the archive gutter: the owner's value is appended last on purpose.
 *
 * Inlining the sheets at the end of the head inverted 2 and 4, so in a fixture
 * the SKIN won. Measured on AURORA before the repair: the CMS value sat at
 * offset 2435 and the skin's own "--grid-gap: 2px" at 9722, so a browser
 * reading that file saw 2px where a live site shows the owner's value. A
 * geometry fixture that reverses precedence produces confident wrong answers,
 * and these fixtures are what the headed runs measure and what appearance work
 * has been signed off against.
 *
 * It mattered on the nine skins whose own stylesheets set --grid-gap: aurora,
 * game-on, heuristic, instant-camera, jive-turkey, parade, sliders,
 * sudden-impact, the-grid.
 *
 * Found while verifying somebody else's capture, not by a test -- which is why
 * there is now a test.
 */

/**
 * @param string $html The rendered page, with its <link> tags still present.
 * @param string $css  The resolved stylesheet text to inline.
 * @return string The page with one <style> block where the first link stood
 *                and every stylesheet link removed.
 */
function snapsmack_fixture_inline_styles(string $html, string $css): string
{
    $pattern = '#<link[^>]+rel=["\']stylesheet["\'][^>]*>#i';
    $at = null;
    if (preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE)) {
        $at = (int)$m[0][1];
    }
    $html = preg_replace($pattern, '', $html) ?? $html;
    $style = '<style>' . $css . '</style>';
    if ($at !== null) {
        // Where the first <link> stood, so everything the CMS emits after it
        // still comes after it -- the live order.
        return substr($html, 0, $at) . $style . substr($html, $at);
    }
    if (str_contains($html, '</head>')) {
        // No stylesheet link at all: nothing to sit ahead of, so the end of
        // the head is the only sensible place left.
        return str_replace('</head>', $style . '</head>', $html);
    }
    return $style . $html;
}

// ===== SNAPSMACK EOF =====
