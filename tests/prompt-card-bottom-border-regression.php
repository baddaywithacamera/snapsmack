<?php
/**
 * The prompt card's bottom border must survive public-base.
 *
 * Sean reported twice in one day that the cards on /prompts have no bottom
 * edge: "the card box ends right under the date/hashtag line with no bottom
 * border or padding, as if clipped." Measured on the installed build at
 * photofri.day (ONYX 0.1.26, core 0.7.993D), the first Past card computed:
 *
 *     border-top 1px   border-right 1px   border-left 1px   BORDER-BOTTOM 0px
 *
 * It is not a clip and not an ONYX fault. public-base.css carries
 *
 *     .description a:has(img), .static-content a:has(img), …
 *         { border-bottom: none !important; }
 *
 * to stop an inline image link wearing a text underline. A prompt card IS an
 * <a> containing an <img>, and the board shortcode is placed in a static
 * page, so every card matches it. photochallenge-board-embed.css already
 * tried to put the edge back — the comment there says "restore its fourth
 * edge" — but it did so with
 *
 *     .pc-prompt-card:has(img) { border-bottom: 1px solid … !important; }
 *
 * and that selector is LESS SPECIFIC than the one removing it. Both carry
 * !important, so specificity decides and public-base wins. The restore rule
 * has therefore never worked on any page it was written for. The browser was
 * doing exactly what the stylesheets told it to.
 *
 * That is why the earlier fix "did not reach the site": it is installed —
 * 0.1.26 is the version served and the repo's — it just never had any effect.
 *
 * WHAT THIS TEST PINS, and why it is shaped this way: it does not hardcode
 * either selector. It reads the removing rule out of public-base.css, reads
 * the restoring rules out of the board stylesheet, computes CSS specificity
 * for both, and asserts every restore is at least as specific as the
 * strongest removal. So the day somebody makes the public-base selector more
 * specific — adds a class, wraps it in another layer — this fails instead of
 * silently taking the border away again.
 *
 * Shared fault, so the inventory is here rather than in a chat message: the
 * card loses its edge wherever it sits inside .static-content or
 * .description. RENDERED and MEASURED in a browser, before and after, for
 * every skin that could not be settled by reading:
 *
 *     AFFECTED      28 of the 30 skins
 *     NOT AFFECTED  stanley, writing-with-impact - they wrap a static page in
 *                   .post-content.entry-content.e-content, which the removing
 *                   rule does not name. Measured 1px bottom before AND after,
 *                   so the fix neither helped nor harmed them.
 *     NOT A SKIN    skins/beatbox holds only style.css and beatbox-config.json
 *                   - no layout.php, no manifest.json. The skin-version
 *                   ratchet counts 30, not 31, and beatbox is the one it
 *                   leaves out. It renders no page, so it cannot show this.
 *
 * An earlier version of this docblock said 25 of 31, with six NOT CHECKED.
 * That was a static read of layout.php plus any same-named core page
 * component, and it was WRONG for three of the six: 50-shades-of-noah-grey,
 * full-monty and glide take their .static-content/.description wrapper from
 * the SHARED static-page component, which carries neither the skin's name nor
 * its own layout file, so the scan could not see it. Rendered, all three
 * showed 3 of 3 cards at border-bottom 0px before the fix and 1px after. The
 * lesson is the one this file is already about: read the structure, not the
 * filename.
 *
 * Static stylesheet assertions — no DB, no site, no browser.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root  = dirname(__DIR__);
$fails = 0;

function pcb(string $label, bool $ok): void {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fails++;
}

/**
 * CSS specificity as [ids, class-level, type-level]. Class-level counts
 * classes, attribute selectors and pseudo-classes; type-level counts element
 * names and pseudo-elements. :has()/:is()/:not() contribute the specificity of
 * their most specific argument, per the selectors spec, which is exactly the
 * detail this whole defect turned on.
 */
function pcb_specificity(string $selector): array
{
    $selector = trim($selector);
    $spec = [0, 0, 0];

    // Pull functional pseudo-classes out first and score their arguments.
    $functional = '/:(?:has|is|not|where)\(([^()]*)\)/i';
    while (preg_match($functional, $selector, $m, PREG_OFFSET_CAPTURE)) {
        $whole = $m[0][0];
        $inner = $m[1][0];
        $best  = [0, 0, 0];
        foreach (explode(',', $inner) as $arg) {
            $arg = trim($arg);
            if ($arg === '') continue;
            $s = pcb_specificity($arg);
            if ($s > $best) $best = $s;
        }
        // :where() always contributes zero, however specific its argument.
        if (stripos($whole, ':where(') === 0) $best = [0, 0, 0];
        for ($i = 0; $i < 3; $i++) $spec[$i] += $best[$i];
        $selector = substr_replace($selector, ' ', $m[0][1], strlen($whole));
    }

    $spec[0] += preg_match_all('/#[A-Za-z_][\w-]*/', $selector);
    $spec[1] += preg_match_all('/\.[A-Za-z_][\w-]*/', $selector);
    $spec[1] += preg_match_all('/\[[^\]]+\]/', $selector);
    // Pseudo-ELEMENTS (::x, and the four legacy single-colon ones) score as
    // types; every other pseudo-class scores as a class.
    $spec[2] += preg_match_all('/::[A-Za-z-]+/', $selector);
    $spec[2] += preg_match_all('/(?<!:):(?:before|after|first-line|first-letter)\b/i', $selector);
    $selector = (string) preg_replace('/::[A-Za-z-]+/', ' ', $selector);
    $selector = (string) preg_replace('/(?<!:):(?:before|after|first-line|first-letter)\b/i', ' ', $selector);
    $spec[1] += preg_match_all('/(?<!:):[A-Za-z-]+/', $selector);
    $selector = (string) preg_replace('/(?<!:):[A-Za-z-]+/', ' ', $selector);
    // Whatever bare element names are left.
    $spec[2] += preg_match_all('/(?:^|[\s>+~(,])([a-zA-Z][\w-]*)/', $selector);

    return $spec;
}

function pcb_spec_str(array $s): string { return sprintf('(%d,%d,%d)', $s[0], $s[1], $s[2]); }

/** Every "selector { body }" pair in a stylesheet, at-rule bodies included. */
function pcb_rules(string $css): array
{
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
    $out = [];
    if (preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $sel = trim($hit[1]);
            if ($sel === '' || $sel[0] === '@') continue;
            // A selector list that survived an at-rule opener keeps only its tail.
            $sel = trim((string) preg_replace('/^.*\}/s', '', $sel));
            if ($sel === '') continue;
            $out[] = [$sel, $hit[2]];
        }
    }
    return $out;
}

// ── The two stylesheets ─────────────────────────────────────────────────────
$base_path  = $root . '/assets/css/public-base.css';
$board_path = $root . '/assets/css/photochallenge-board-embed.css';
$base  = (string) @file_get_contents($base_path);
$board = (string) @file_get_contents($board_path);

pcb('public-base.css is readable', $base !== '');
pcb('photochallenge-board-embed.css is readable', $board !== '');
if ($base === '' || $board === '') {
    fwrite(STDERR, "\nPrompt card border: a stylesheet is missing — cannot check.\n");
    exit(1);
}

// ── The removal: whatever in public-base takes a bottom border off an <a>
//    that contains an <img>. Found by shape, not by a copied string. ────────
$removals = [];
foreach (pcb_rules($base) as [$sel, $body]) {
    if (!preg_match('/border-bottom(?:-width)?\s*:\s*(none|0|0px)\b/i', $body)) continue;
    foreach (explode(',', $sel) as $one) {
        $one = trim($one);
        if ($one === '') continue;
        // The ones that reach a card: an <a> qualified by containing an image.
        if (!preg_match('/\ba:has\(\s*img\s*\)/i', $one)) continue;
        $removals[$one] = pcb_specificity($one);
    }
}

pcb(
    'public-base still removes the bottom border from image links',
    $removals !== []
);
if ($removals === []) {
    // Not a pass. If that rule is ever deleted this test must be revisited,
    // because the restore rules would then be load-bearing for nothing.
    fwrite(STDERR, "\nThe public-base rule this test exists to outrank is gone. "
        . "Re-read both stylesheets before trusting the restores.\n");
    exit(1);
}

$strongest = [0, 0, 0];
$strongest_sel = '';
foreach ($removals as $sel => $spec) {
    if ($spec > $strongest) { $strongest = $spec; $strongest_sel = $sel; }
}
echo '     strongest removal: ' . $strongest_sel . ' ' . pcb_spec_str($strongest) . "\n";

// ── The restores: every board-stylesheet rule that puts a bottom border
//    back on a prompt card. ─────────────────────────────────────────────────
$restores = [];
foreach (pcb_rules($board) as [$sel, $body]) {
    if (!preg_match('/border-bottom(?:-color|-width|-style)?\s*:/i', $body)) continue;
    if (preg_match('/border-bottom(?:-width)?\s*:\s*(none|0|0px)\b/i', $body)) continue;
    foreach (explode(',', $sel) as $one) {
        $one = trim($one);
        if ($one === '' || !str_contains($one, 'pc-prompt-card')) continue;
        $restores[$one] = pcb_specificity($one);
    }
}

pcb('the board stylesheet restores the card bottom border', $restores !== []);

// Not just SOME rule: the RESTING one. The hover, focus and is-current rules
// already outrank public-base by accident of having two classes, so a test
// that only counted restores stayed green when the plain rule was deleted and
// the card lost its edge again until you moved the mouse over it. Found by
// mutation, not by reading.
$resting = [];
foreach ($restores as $sel => $spec) {
    if (preg_match('/:hover|:focus|:active|\.is-current/i', $sel)) continue;
    $resting[$sel] = $spec;
}
pcb(
    'a card gets its bottom border with no hover or current state',
    $resting !== []
);
foreach ($resting as $sel => $spec) {
    pcb(
        'the resting restore outranks the removal: ' . $sel . ' ' . pcb_spec_str($spec),
        $spec >= $strongest
    );
}
// And it must set a real width, not only a colour: a colour on a zero-width
// border is invisible, which is the state Sean was looking at.
$sets_width = false;
foreach (pcb_rules($board) as [$sel, $body]) {
    if (!str_contains($sel, 'pc-prompt-card')) continue;
    if (preg_match('/:hover|:focus|:active|\.is-current/i', $sel)) continue;
    if (preg_match('/border-bottom\s*:\s*\d/i', $body)
        || preg_match('/border-bottom-width\s*:\s*\d*[1-9]/i', $body)) {
        $sets_width = true;
    }
}
pcb('that restore gives the border a width, not only a colour', $sets_width);

// Every restore must outrank the removal, or it is decoration. Both sides are
// !important, so specificity is the whole contest.
$weak = [];
foreach ($restores as $sel => $spec) {
    $ok = ($spec >= $strongest);
    pcb('outranks the removal: ' . $sel . ' ' . pcb_spec_str($spec), $ok);
    if (!$ok) $weak[] = $sel;
}

// And they must still be !important, or the removal wins on that alone.
foreach (pcb_rules($board) as [$sel, $body]) {
    if (!str_contains($sel, 'pc-prompt-card')) continue;
    if (!preg_match('/border-bottom(?:-color|-width|-style)?\s*:/i', $body)) continue;
    if (preg_match('/border-bottom(?:-width)?\s*:\s*(none|0|0px)\b/i', $body)) continue;
    pcb(
        'declared !important: ' . trim(explode(',', $sel)[0]),
        (bool) preg_match('/border-bottom[^;]*!important/i', $body)
    );
}

// ── The specificity calculator itself, checked against known answers ───────
// A test whose arithmetic is wrong would pass a broken stylesheet, so the
// calculator is not taken on trust.
$known = [
    '.static-content a:has(img)'            => [0, 1, 2],
    '.pc-prompt-card:has(img)'              => [0, 1, 1],
    '.pc-prompt-cards .pc-prompt-card:has(img)' => [0, 2, 1],
    '.pc-prompt-card.is-current:has(img)'   => [0, 2, 1],
    '.pc-prompt-card:hover'                 => [0, 2, 0],
    'a'                                     => [0, 0, 1],
    '#x .y a'                               => [1, 1, 1],
    ':where(.static-content .description a)' => [0, 0, 0],
];
foreach ($known as $sel => $want) {
    $got = pcb_specificity($sel);
    pcb(
        'specificity of ' . $sel . ' is ' . pcb_spec_str($want),
        $got === $want
    );
}

if ($fails) {
    fwrite(STDERR, "\n{$fails} FAILURE(S).\n");
    if ($weak) {
        fwrite(STDERR, "These rules are outranked by public-base and do nothing:\n");
        foreach ($weak as $sel) fwrite(STDERR, "  {$sel}\n");
        fwrite(STDERR, "The card renders with border-bottom: 0 and looks clipped under its\n"
            . "last line of text, on every one of the 25 skins that wrap a static page\n"
            . "in .static-content or .description.\n");
    }
    exit(1);
}
echo "Prompt card bottom border: PASS\n";
exit(0);

// ===== SNAPSMACK EOF =====
