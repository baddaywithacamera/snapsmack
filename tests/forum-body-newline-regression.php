<?php
/**
 * A newline in a forum post renders ONCE.
 *
 * WHAT SEAN SAW, 30 October 2026: a screenshot of live thread 94 (SS-1067) in
 * which every hard-wrapped line sat in its own paragraph with a blank gap
 * under it, so sentences broke mid-line. 21 of the 49 ticket threads on the
 * board are hard-wrapped bodies and all of them looked like that.
 *
 * THE CAUSE. The renderer wrapped the escaped body in nl2br(), and BOTH
 * screens that display a body set white-space: pre-wrap on the element around
 * it. pre-wrap already honours a newline, so each one was rendered twice: once
 * by the CSS and once by the <br>. Measured in a browser at a 760px column,
 * with the production rule read out of the stylesheet, a 6-line body produced
 * 12 line boxes; with nl2br removed, 6.
 *
 * WHY THIS TEST EXISTS RATHER THAN A NOTE. The fix removes the only markup the
 * renderer emitted for a line break, so the newline now depends ENTIRELY on a
 * CSS property in two files, one of which is not in this lane. If either rule
 * loses white-space, every forum post on that screen collapses into one
 * paragraph and no PHP test that reads markup would notice. So this asserts
 * the pair: the renderer emits no <br>, AND every element that holds its
 * output has a rule that keeps newlines.
 *
 * Run:  php tests/forum-body-newline-regression.php
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = realpath(__DIR__ . '/..');
$failures = [];
function nl_test(bool $ok, string $message): void {
    global $failures;
    if (!$ok) $failures[] = $message;
}

require_once $root . '/core/forum-post-body.php';

// ── The renderer emits no line-break markup of its own ─────────────────────

$body = "first line\nsecond line\n\nnew paragraph";
$out  = snapsmack_forum_post_body($body, []);

nl_test(stripos($out, '<br') === false,
    'the renderer still emits <br>, so every newline renders twice under '
  . 'white-space: pre-wrap: ' . $out);

nl_test(substr_count($out, "\n") === substr_count($body, "\n"),
    'the newlines the author typed did not survive: the CSS is what renders '
  . 'them now, so losing them here loses the line breaks entirely');

nl_test(strpos($out, 'first line') !== false && strpos($out, 'new paragraph') !== false,
    'the text itself did not survive');

// The escaping must be exactly as it was. This is the part a careless edit
// breaks while removing the nl2br wrapper around it.
$nasty = "<script>alert(1)</script>\n<b>bold</b>";
$safe  = snapsmack_forum_post_body($nasty, []);
preg_match_all('/<([a-zA-Z\/][^>]*)>/', $safe, $tags);
nl_test($tags[0] === [],
    'HTML typed into a post produced tags: ' . implode(' ', $tags[0]));

// An inline image is still placed, and is still the only markup emitted.
$placed = snapsmack_forum_post_body("see [img:1]\nand that is all",
    [['url' => '/api/forum/uploads/a.jpg', 'alt' => 'a.jpg']]);
nl_test(strpos($placed, '<img src="/api/forum/uploads/a.jpg"') !== false,
    'a placed attachment is no longer rendered');
nl_test(stripos($placed, '<br') === false,
    'the newline beside a placed image still produces a <br>');

// ── Every screen that shows a body keeps newlines in CSS ───────────────────
//
// The call sites are single lines of the form
//   <div class="CLASS"><?php echo snapsmack_forum_post_body(...
// so the class that must carry the rule can be read straight off them.

$consumers = [
    'smack-forum.php'             => 'the site forum',
    'smack-central/sc-forum.php'  => 'SMACK CENTRAL',
];

// Where a rule for a class may live. The site forum is styled by the admin
// theme; SMACK CENTRAL carries its own <style> block.
$stylesheets = [
    $root . '/assets/css/admin-theme-geometry-master.css',
    $root . '/smack-central/sc-forum.php',
];
$css = '';
foreach ($stylesheets as $sheet) {
    $css .= (string)file_get_contents($sheet);
}

foreach ($consumers as $file => $what) {
    $src = (string)file_get_contents($root . '/' . $file);

    nl_test(strpos($src, 'snapsmack_forum_post_body(') !== false,
        "{$what} no longer renders bodies through the shared renderer");

    if (!preg_match_all('/class="([a-z0-9_\-]+)"><\?php echo snapsmack_forum_post_body\(/i',
                        $src, $m) ) {
        $failures[] = "{$what}: could not read the class around the rendered "
                    . 'body, so the CSS contract cannot be checked. If the '
                    . 'markup moved, move this check with it.';
        continue;
    }

    foreach (array_unique($m[1]) as $class) {
        // EVERY rule whose selector mentions the class, not just the first.
        // Two things made the first version of this check wrong, and both are
        // worth knowing:
        //
        //   * the admin theme declares .forum-post__body TWICE (the master
        //     stylesheet carries the whole block at two places), so reading
        //     one of them proves nothing about the other; and
        //   * .forum-post--op .forum-post__body also matches, and it sets
        //     only font-size -- so "the last block that matches" is a
        //     modifier that was never meant to carry white-space.
        //
        // The property survives if ANY matching rule turns newlines on and
        // NOTHING turns them off again. That is what the browser does, and it
        // is what this now asserts.
        $pattern = '/([^{}]*\.' . preg_quote($class, '/') . '\b[^{}]*)\{([^}]*)\}/';
        if (!preg_match_all($pattern, $css, $rules, PREG_SET_ORDER)) {
            $failures[] = "{$what}: nothing styles .{$class}, which holds a "
                        . 'rendered post body. Without white-space: pre-wrap '
                        . 'every newline in every post on that screen is gone.';
            continue;
        }

        $keeps   = [];   // selectors that preserve newlines
        $cancels = [];   // selectors that throw them away again
        foreach ($rules as $rule) {
            $selector = trim(preg_replace('/\s+/', ' ', $rule[1]));
            if (!preg_match('/white-space\s*:\s*([a-z-]+)/', $rule[2], $ws)) continue;
            if (in_array($ws[1], ['pre', 'pre-wrap', 'pre-line'], true)) {
                $keeps[] = $selector . ' { white-space: ' . $ws[1] . ' }';
            } else {
                $cancels[] = $selector . ' { white-space: ' . $ws[1] . ' }';
            }
        }

        nl_test($keeps !== [],
            "{$what}: no rule for .{$class} sets white-space to pre-wrap, so "
          . 'newlines in a post body render as nothing. The renderer stopped '
          . 'emitting <br> on purpose; this rule is what replaced it. '
          . count($rules) . ' rule(s) match that class and none keeps newlines.');

        nl_test($cancels === [],
            "{$what}: a rule for .{$class} cancels the newlines another one "
          . 'keeps: ' . implode(' / ', $cancels) . '. Which wins depends on '
          . 'specificity and order, so this is left as a failure rather than '
          . 'guessed at.');
    }
}

// ── And the renderer itself does not quietly get nl2br back ────────────────

$renderer = (string)file_get_contents($root . '/core/forum-post-body.php');
$code = '';
foreach (token_get_all($renderer) as $t) {
    if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
    $code .= is_array($t) ? $t[1] : $t;
}
nl_test(strpos($code, 'nl2br') === false,
    'nl2br is back in core/forum-post-body.php; with pre-wrap in both screens '
  . 'that renders every newline twice, which is the fault this file is about');

if ($failures) {
    fwrite(STDERR, "FAIL forum body newlines (" . count($failures) . ")\n  - "
        . implode("\n  - ", $failures) . "\n");
    exit(1);
}
echo "PASS a newline in a forum post renders once\n";
// ===== SNAPSMACK EOF =====
