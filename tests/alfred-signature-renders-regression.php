<?php
/**
 * ALFRED closing signature regression.
 *
 * Verifies skins/alfred/layout.php renders $view['response']['signature'] inside
 * .post-content as .post-signature.post-signature--closing when present and
 * omits it when absent, and skins/alfred/style.css defines the closing scale.
 */
// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

declare(strict_types=1);

define('SNAPSMACK_SKIN_RENDER', true);
require __DIR__ . '/../core/skin-render-helpers.php';

$pass = 0;
$fail = 0;
$check = function (bool $cond, string $label) use (&$pass, &$fail): void {
    if ($cond) {
        echo "  ok    {$label}\n";
        $pass++;
    } else {
        echo "  FAIL  {$label}\n";
        $fail++;
    }
};

$render = function (?array $signature): string {
    $view = [
        'site' => [
            'language'          => 'en',
            'direction'         => 'ltr',
            'site_name'         => 'Test Site',
            'tagline'           => 'Test Tagline',
            'base_style_url'    => '',
            'skin_style_url'    => '/skins/alfred/style.css',
            'skin_custom_style' => '',
            'skin_presentation' => ['header_media' => []],
            'social_dock'       => ['links' => []],
            'footer'            => ['slots' => []],
            'registered_assets' => ['scripts' => [], 'styles' => []],
        ],
        'response' => [
            'kind'             => 'single',
            'page_title'       => 'Single Essay',
            'navigation'       => [],
            'post'             => ['title' => 'Single Essay', 'created_at' => '2026-11-28'],
            'rendered_content' => '<p>Body copy.</p>',
            'signature'        => $signature,
            'comments_enabled' => false,
            'comments'         => [],
        ],
    ];
    ob_start();
    require __DIR__ . '/../skins/alfred/layout.php';
    return (string)ob_get_clean();
};

$withSig = $render(['url' => '/media/sig-413.png', 'alt' => 'Author signature']);
$check(strpos($withSig, 'class="post-signature post-signature--closing"') !== false,
    'single post renders closing signature container when signature URL is present');
$check(strpos($withSig, 'src="/media/sig-413.png"') !== false
    && strpos($withSig, 'alt="Author signature"') !== false,
    'closing signature img preserves url and alt attributes');
$bodyPos = strpos($withSig, 'Body copy.');
$sigPos  = strpos($withSig, '<div class="post-signature post-signature--closing">');
$endPos  = strpos($withSig, '</div></div>', $sigPos !== false ? $sigPos : 0);
$check($bodyPos !== false && $sigPos !== false && $endPos !== false && $bodyPos < $sigPos && $sigPos < $endPos,
    'closing signature appears inside .post-content after rendered_content');

$withoutSig = $render(null);
$check(strpos($withoutSig, 'post-signature') === false,
    'single post omits .post-signature when signature is absent');

$emptySig = $render(['url' => '', 'alt' => 'Ignored']);
$check(strpos($emptySig, 'post-signature') === false,
    'single post omits .post-signature when signature URL is empty');

$css = (string)file_get_contents(__DIR__ . '/../skins/alfred/style.css');
$check(strpos($css, '.post-signature--closing') !== false
    && strpos($css, 'width: min(340px, 72%);') !== false,
    'ALFRED stylesheet defines .post-signature--closing and bounded width');

if ($fail > 0) {
    echo "ALFRED signature regression: FAIL ({$fail}).\n";
    exit(1);
}
echo "ALFRED signature regression: PASS ({$pass} checks).\n";

// ===== SNAPSMACK EOF =====
