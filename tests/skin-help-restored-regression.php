<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/**
 * Every skin's own help, and the hook that loads it.
 *
 * Thirty skins shipped a help.php. The strict-runtime conversion on 2026-09-28
 * deleted all thirty and put nothing in their place, so smack-help.php's skin
 * hook spent 23 days looking for a file that existed nowhere. Nothing reported
 * it, because a missing help topic looks exactly like a skin that never had one.
 *
 * It could not simply be put back: core/skin-security-policy.php refuses any
 * .php in a skin package that is not a manifest-declared presentation template,
 * and declaring it makes it a template, held to a grammar that cannot return an
 * array. The help is now help.json -- data, which the policy does not examine
 * and the packager already ships.
 *
 * This test exists because the thing that went wrong was silence. It fails if
 * the files go missing again, if one stops parsing, if a skin's help empties
 * out, or if the hook stops reading JSON.
 */

function skin_help_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);

// 1. The hook reads help.json, and still honours a help.php a site may already
//    have on disk from before the sweep.
$hook = (string)file_get_contents($root . '/smack-help.php');
skin_help_check(
    str_contains($hook, 'help.json'),
    'smack-help.php no longer loads skins/<slug>/help.json, so no skin can ship help.'
);
skin_help_check(
    str_contains($hook, 'json_decode'),
    'The skin help hook no longer decodes JSON.'
);
skin_help_check(
    str_contains($hook, '$_help_php_path'),
    'The help.php fallback is gone; a site packaged before 2026-09-28 would lose its skin help.'
);

// 2. Skins that had help before the sweep have it again. These are the skins
//    still in the repo whose help was recovered; a skin removed since is not
//    expected to have one.
$expected = ['50-shades-of-noah-grey', '52-card-pickup', 'aurora', 'chaplin', 'full-monty',
    'galleria', 'game-on', 'heuristic', 'hip-to-be-square', 'impact-printer', 'instant-camera',
    'jive-turkey', 'new-horizon', 'onyx', 'parade', 'photogram', 'rational-geo', 'scroll',
    'show-n-tell', 'slickr', 'sliders', 'stanley', 'sudden-impact', 'the-grid', 'true-grit',
    'writing-with-impact'];

$topics = 0;
foreach ($expected as $slug) {
    $path = $root . '/skins/' . $slug . '/help.json';
    skin_help_check(is_file($path), "skins/$slug/help.json is missing; that skin's help is gone again.");

    $data = json_decode((string)file_get_contents($path), true);
    skin_help_check(
        is_array($data),
        "skins/$slug/help.json does not parse. The hook would silently add nothing."
    );
    skin_help_check(
        !empty($data['skin_name']) && is_string($data['skin_name']),
        "skins/$slug/help.json has no skin_name, so its topics would file under the raw slug."
    );
    skin_help_check(
        isset($data['topics']) && is_array($data['topics']) && $data['topics'] !== [],
        "skins/$slug/help.json carries no topics."
    );
    foreach ($data['topics'] as $i => $t) {
        skin_help_check(
            is_array($t) && !empty($t['title']),
            "skins/$slug/help.json topic $i has no title; the hook drops untitled topics."
        );
        skin_help_check(
            trim((string)($t['body'] ?? $t['content'] ?? '')) !== '',
            "skins/$slug/help.json topic '" . (string)$t['title'] . "' has an empty body, which renders as a blank page."
        );
        $topics++;
    }
}

skin_help_check(
    $topics >= 183,
    "Only $topics skin help topics survive; 183 were recovered. Something has been dropped."
);

// 3. No skin ships help as PHP. core/skin-security-policy.php would refuse the
//    package: 'Every PHP file must be a manifest-declared presentation template.'
$stray = glob($root . '/skins/*/help.php');
skin_help_check(
    $stray === [] || $stray === false,
    'A skin ships help.php again: ' . implode(', ', array_map('basename', (array)$stray))
    . '. That file fails the skin security gate and the package will be refused.'
);

// 4. One Fediverse section, not two. $sections is keyed on the exact string, so
//    'Fediverse' and 'FEDIVERSE' drew two headings and the Directory topic sat
//    alone under the second one.
skin_help_check(
    !preg_match("/'section'\s*=>\s*'Fediverse'/", $hook),
    'The help has a "Fediverse" section alongside "FEDIVERSE"; they render as two separate headings.'
);

fwrite(STDOUT, "Skin-help regression passed: $topics topics across " . count($expected) . " skins.\n");

// ===== SNAPSMACK EOF =====
