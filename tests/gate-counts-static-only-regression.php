<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/**
 * The inline-CSS gate counts violations, not the exception.
 *
 * It read 27 for weeks and nobody could reduce it, because the 27 were not
 * violations. The check matched the OPENING of any style= attribute and never
 * looked inside, so a per-photograph frame colour and a hard-coded colour were
 * the same thing to it. Measured 2026-10-23: 28 matches, 28 dynamic, 0 static.
 *
 * Sean's rule is "no static style= ever; dynamic PHP styles the only
 * exception." The gate was counting the exception, so the number could only be
 * carried, never paid down — and acting on it would have meant deleting the
 * frame colour an owner chose for one photograph to satisfy a miscount.
 *
 * A number nobody can act on honestly is one people stop reading. This gate was
 * red through 0.7.933D, 934D and 935D, all of which shipped.
 */

function gate_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$gate = (string)file_get_contents($root . '/tests/agreed-rules-gate.php');

// The budget is zero and must stay there. Anything else means a static style
// has been accepted into the tree rather than removed.
gate_check(
    (bool)preg_match('/\$INLINE_STYLE_BUDGET\s*=\s*0\s*;/', $gate),
    'The static inline-style budget is no longer 0. Zero is the measured truth; raising it accepts '
    . 'hard-coded CSS in markup rather than removing it.'
);

// It must capture the attribute VALUE, not just the opening.
gate_check(
    str_contains($gate, 'style\s*=\s*"([^"]*)"'),
    'The gate no longer captures the attribute value, so it cannot tell a static style from one '
    . 'carrying a PHP expression — which is what made the old count meaningless.'
);
gate_check(
    str_contains($gate, "strpos(\$value, '<?php')") && str_contains($gate, "strpos(\$value, '<?='"),
    'The gate no longer skips attributes containing PHP. Both <?php and the short echo tag must be '
    . 'excluded, or the documented exception is counted as an offence again.'
);

// And it must actually be running over the templates.
gate_check(
    str_contains($gate, 'layout.php') || str_contains($gate, '-page-component.php'),
    'The gate no longer scans the skin templates at all.'
);

// The live tree must be clean. If this fails, someone added a static style.
$templates = array_merge(
    glob($root . '/skins/*/layout.php') ?: [],
    glob($root . '/core/*-page-component.php') ?: []
);
gate_check($templates !== [], 'No skin templates found to check; the glob is wrong.');

$static = [];
foreach ($templates as $file) {
    $src = (string)file_get_contents($file);
    if (!preg_match_all('/\bstyle\s*=\s*"([^"]*)"/i', $src, $m)) continue;
    foreach ($m[1] as $value) {
        if (str_contains($value, '<?php') || str_contains($value, '<?=')) continue;
        $static[] = basename(dirname($file)) . '/' . basename($file) . ': style="' . $value . '"';
    }
}
gate_check(
    $static === [],
    'Hard-coded CSS has appeared in markup: ' . implode(' | ', $static)
);

fwrite(STDOUT, 'Gate-counts-static-only regression passed: ' . count($templates) . " templates, 0 static inline styles.\n");

// ===== SNAPSMACK EOF =====
