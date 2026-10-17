<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The separate PROMPTS page index, and the menu that said HOME twice.
 *
 * Sean, looking at photofri.day/the-board once the page rendered at all:
 * the list of current and past prompts came out as one unbroken line of
 * words -- "Current and Past PromptsCurrent: FallOct 9, 2026 ..." -- and the
 * menu read HOME BLOGROLL HOME HOW IT WORKS.
 *
 * THE INDEX. pc_prompt_index_embed_html() builds the index as a section of
 * linked poster cards. Reached through the dedicated board page it is echoed
 * straight out and renders. Reached through a static page it goes through the
 * shortcode parser and then the content sanitiser, and 'nav' was not on that
 * allowlist. An unknown tag there is replaced by its own textContent, so the
 * nav, its heading, its anchors, its posters and its spans all collapsed into
 * a single text node. Allowing nav alone was not enough: 'a' could not keep a
 * class, so the cards would have survived with no styling.
 *
 * THE MENU. With no saved Menu Manager configuration the CMS builds the menu
 * itself: Home, Blogroll, then every active page. photofri.day has an active
 * page of its own called Home, so Home appeared twice pointing at two
 * different addresses.
 */

require_once dirname(__DIR__) . '/core/trusted-html.php';

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) $failures[] = $what;
};

// --- the prompt index survives the sanitiser, intact ---------------------
$index = '<div class="pc-prompt-index" aria-label="Current, coming and past prompts">'
    . '<h2>Current and Past Prompts</h2><div class="pc-prompt-cards">'
    . '<a class="pc-prompt-card is-current" href="#round-2026-10-09">'
    . '<img src="/uploads/fall.jpg" alt="Fall prompt">'
    . '<span class="pc-prompt-copy"><strong>Current: Fall</strong>'
    . '<span>Oct 9, 2026 &middot; #PhotoFriFall</span></span></a></div></div>';
$clean = (string)snapsmack_trusted_html($index);

$check(str_contains($clean, '<div'), 'the prompt index wrapper survives');
$check(str_contains($clean, 'class="pc-prompt-index"'), 'the index keeps its class');
$check(str_contains($clean, 'pc-prompt-card'),
    'the anchor must keep its class, or the poster cards render unstyled');
$check(str_contains($clean, '<img'), 'the poster images stay');
$check(str_contains($clean, 'href="#round-2026-10-09"'), 'the card still links to its round');
$check(substr_count($clean, '<a ') === 1, 'exactly one anchor, not a flattened copy');

// --- and the sanitiser is still a sanitiser ------------------------------
$hostile = '<nav class="x" onclick="steal()"><script>alert(1)</script>'
    . '<a class="y" href="javascript:alert(1)">go</a>'
    . '<a class="z" href="/ok" onmouseover="x()">fine</a>'
    . '<iframe src="//evil"></iframe></nav>';
$out = (string)snapsmack_trusted_html($hostile);
foreach (['onclick', '<script', 'javascript:', 'onmouseover', '<iframe'] as $bad) {
    $check(!str_contains($out, $bad), 'the sanitiser must still strip ' . $bad);
}
$check(str_contains($out, 'href="/ok"'), 'an ordinary link still works');

// --- the auto-built menu does not link Home twice ------------------------
$model = (string)@file_get_contents($root . '/core/skin-view-model.php');
$boardCss = (string)@file_get_contents($root . '/assets/css/photochallenge-board-embed.css');
$check(str_contains($model, "if (\$slug === 'home' || \$slug === 'blogroll') continue;"),
    'the auto-built menu must skip a page the CMS has already linked');
$at = strpos($model, "['type' => 'home', 'label' => 'Home']");
$skip = strpos($model, "if (\$slug === 'home' || \$slug === 'blogroll') continue;");
$check($at !== false && $skip !== false && $skip > $at,
    'the skip belongs in the page loop that follows the Home and Blogroll entries');

// public-base deliberately removes border-bottom from linked images. Prompt
// cards are image links but also bordered components, so their own stylesheet
// must explicitly restore the fourth edge with equal !important authority.
$check(str_contains($boardCss, '.pc-prompt-card:has(img)')
    && str_contains($boardCss, 'border-bottom: 1px solid rgba(0,0,0,.18) !important;')
    && str_contains($boardCss, '.pc-prompt-card.is-current:has(img)')
    && str_contains($boardCss, 'border-bottom-width: 2px !important;'),
    'prompt cards must keep their bottom border against the base image-link reset');

printf("board index: nav and card classes survive, scripts and javascript: urls do not; menu adds Home once.\n");

if ($failures) {
    fwrite(STDERR, "Board index and menu regression FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Board index and menu regression passed.\n";

// ===== SNAPSMACK EOF =====
