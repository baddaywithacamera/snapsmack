<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * A GEOMETRY FIXTURE MUST HAVE THE SAME CASCADE AS A LIVE PAGE.
 *
 * Both fixture generators strip the skin's <link rel="stylesheet"> tags --
 * under file:// they point at a server that is not there -- and inline the
 * resolved css instead. Both used to re-insert that css immediately before
 * </head>, which put it AFTER everything else in the head.
 *
 * THAT REVERSED THE CASCADE IN EVERY FIXTURE EITHER TOOL HAD EVER WRITTEN.
 *
 * A live strict head is: base <link>, skin style.css <link>, the owner's
 * custom style, then the CMS presentation block. At equal specificity the
 * later rule wins, so a CMS :root variable BEATS a skin's. That is the whole
 * basis of the owner-wins ruling on the archive gutter -- the owner's value is
 * appended last on purpose.
 *
 * Inlining at the end of the head swapped those two, so IN A FIXTURE THE SKIN
 * WON. Measured on AURORA before the repair: the CMS value sat at offset 2435
 * and the skin's own "--grid-gap: 2px" at 9722. A browser reading that file
 * saw 2px where a live site shows the owner's value.
 *
 * WHY THAT IS WORSE THAN A BUG IN A TEST. These fixtures are what the headed
 * browser runs measure, and what appearance work gets signed off against. A
 * harness that reverses precedence does not fail -- it produces confident
 * wrong answers, and "measure, do not assert" is only worth anything if the
 * measurement is faithful.
 *
 * It mattered on the nine skins whose own stylesheets set --grid-gap: aurora,
 * game-on, heuristic, instant-camera, jive-turkey, parade, sliders,
 * sudden-impact, the-grid. It did NOT affect the gutter numbers reported on
 * NEW HORIZON, which sets no value of its own.
 *
 * FOUND WHILE VERIFYING SOMEBODY ELSE'S CAPTURE, NOT BY A TEST. Hence this.
 */

$root = dirname(__DIR__);
require_once $root . '/tools/_build/fixture-head.php';

$pass = 0; $fail = 0;
function fh(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

/** A head shaped like a real strict skin's, in the real order. */
$live = '<!doctype html><html><head><meta charset="utf-8">'
      . '<link rel="stylesheet" href="/base.css">'
      . '<link rel="stylesheet" href="/skins/aurora/style.css">'
      . '<style id="snapsmack-owner-custom">:root{--owner:1}</style>'
      . '<style id="snapsmack-grid-common-presentation">:root{--grid-gap:20px;}</style>'
      . '<style id="snapsmack-owner-gutter">:root{--grid-gap:24px;}</style>'
      . '</head><body><div class="au-tile"></div></body></html>';
$skinCss = ':root{--grid-gap:2px;}';

$out = snapsmack_fixture_inline_styles($live, $skinCss);

echo "THE LINKS ARE GONE AND THE CSS IS INLINED ONCE\n";
fh(!str_contains($out, '<link'), 'no stylesheet link survives, so nothing points at a missing server');
fh(substr_count($out, $skinCss) === 1, 'the skin css is inlined exactly once');
fh(str_contains($out, '<style>' . $skinCss . '</style>'),
   'in one plain style block');

echo "\nAND IT SITS WHERE THE LINKS WERE, SO THE CMS STILL COMES LAST\n";
$skinAt    = strpos($out, '--grid-gap:2px');
$commonAt  = strpos($out, 'snapsmack-grid-common-presentation');
$ownerAt   = strpos($out, 'snapsmack-owner-gutter');
$customAt  = strpos($out, 'snapsmack-owner-custom');
fh($skinAt !== false && $commonAt !== false && $ownerAt !== false,
   'all three declarations are present to compare');
fh($skinAt < $customAt, 'the skin css comes before the owner custom style');
fh($skinAt < $commonAt, 'and before the CMS presentation block');
fh($commonAt < $ownerAt, 'the owner gutter block is still after the skin option block');
fh($skinAt < $ownerAt,
   'so the owner value is the last --grid-gap in the document, as on a live page');

// The question the whole thing exists to answer, asked the way a browser
// answers it: of the competing declarations, which is last?
preg_match_all('/--grid-gap:\s*([0-9]+)px/', $out, $all);
fh(($all[1] ?? []) !== [] && end($all[1]) === '24',
   'the last --grid-gap wins and it is the owner\'s 24 (found: '
   . implode(', ', $all[1] ?? []) . ')');

echo "\nMUTATION PROOF: THE OLD BEHAVIOUR MUST FAIL THIS\n";
// Reproduce the pre-repair tool exactly: strip the links, append the css at
// the end of the head. If the checks above cannot tell that apart, they are
// measuring nothing.
$oldWay = preg_replace('#<link[^>]+rel=["\']stylesheet["\'][^>]*>#i', '', $live) ?? $live;
$oldWay = str_replace('</head>', '<style>' . $skinCss . '</style></head>', $oldWay);
preg_match_all('/--grid-gap:\s*([0-9]+)px/', $oldWay, $oldAll);
fh(($oldAll[1] ?? []) !== [] && end($oldAll[1]) === '2',
   'the old insertion makes the SKIN last, which is the inverted cascade '
   . '(found: ' . implode(', ', $oldAll[1] ?? []) . ')');
fh(end($oldAll[1]) !== end($all[1]),
   'and the two orders disagree, so this file is measuring order and not '
   . 'merely presence');

echo "\nBOTH GENERATORS USE THE ONE HELPER, SO THEY CANNOT DRIFT APART\n";
// The fault existed in two copies of the same six lines. One helper, two
// callers: the de-duplication is the repair, not a tidy-up.
foreach ([
    'tools/_build/render-geometry-fixtures.php' => 'the geometry fixtures',
    'tools/_build/render-engine-fixtures.php'   => 'the engine fixtures',
] as $file => $what) {
    $src = (string)@file_get_contents($root . '/' . $file);
    fh($src !== '' && str_contains($src, "require_once __DIR__ . '/fixture-head.php';"),
       $what . ' require the shared helper');
    fh($src !== '' && str_contains($src, 'snapsmack_fixture_inline_styles($html, $css)'),
       $what . ' call it');
    fh($src !== '' && !str_contains($src, "str_replace('</head>', \$style . '</head>'"),
       $what . ' no longer append the css at the end of the head');
}

echo "\nEDGE CASES THAT MUST NOT CRASH A BUILD TOOL\n";
$noLink = snapsmack_fixture_inline_styles(
    '<!doctype html><html><head><title>x</title></head><body></body></html>', $skinCss);
fh(str_contains($noLink, $skinCss) && str_contains($noLink, '</head>'),
   'a page with no stylesheet link still gets the css, at the end of its head');
$noHead = snapsmack_fixture_inline_styles('<div>fragment</div>', $skinCss);
fh(str_starts_with($noHead, '<style>'),
   'a fragment with no head gets the css prepended rather than losing it');
$empty = snapsmack_fixture_inline_styles($live, '');
fh(str_contains($empty, '<style></style>') && !str_contains($empty, '<link'),
   'empty css still removes the links rather than leaving a broken page');

echo "\n  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "Fixture head cascade: FAIL ({$fail}).\n";
    exit(1);
}
echo "Fixture head cascade: PASS ({$pass} assertions).\n";
echo "  NOT CHECKED: a browser. This checks the ORDER of the declarations a\n";
echo "  fixture carries, which is what the cascade is decided by; it does not\n";
echo "  measure a rendered gap. It also cannot tell whether captures taken\n";
echo "  BEFORE this repair were affected -- any conclusion drawn from a fixture\n";
echo "  about owner-versus-skin precedence needs taking again.\n";

// ===== SNAPSMACK EOF =====
