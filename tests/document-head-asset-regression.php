<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * One <head>, shared, so one fix reaches every skin.
 *
 * The document head was copy-pasted into 25 skin layouts as 6 variants,
 * 19 of them byte-identical. A bug in it was therefore six fixes and thirty
 * skin releases. The `document-head` component is that block, once.
 *
 * This renders each skin's CURRENT head template against the same bounded
 * view data and compares it with the component's output, so adoption is
 * provably a no-op for the skins that match. It asserts the count rather than
 * a list, so a skin can adopt the asset without editing this test.
 */

require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) $failures[] = $what;
};

$view = [
    'site' => [
        'language' => 'en',
        'direction' => 'ltr',
        'site_name' => 'A Site & Its "Name"',
        'skin_style_url' => 'https://example.test/skins/demo/style.css?v=1.2.3',
        'skin_custom_style' => snapsmack_trusted_html('<style>.a{color:red}</style>'),
        'skin_presentation' => ['style' => snapsmack_trusted_html('<style>.b{color:blue}</style>')],
    ],
    'response' => ['page_title' => 'Page <Title> & Co'],
];

// --- the component renders the shared block ------------------------------
$rendered = (string)snap_render_component('document-head', [
    'site' => $view['site'],
    'title' => $view['response']['page_title'],
]);
$check(str_starts_with($rendered, '<head>') && str_ends_with($rendered, '</head>'),
    'document-head must render a complete head element');
$check(str_contains($rendered, 'Page &lt;Title&gt; &amp; Co'),
    'the page title must be escaped');
$check(str_contains($rendered, 'charset="utf-8"') && str_contains($rendered, 'width=device-width'),
    'charset and viewport must be present');
$check(str_contains($rendered, 'style.css?v=1.2.3'),
    'the skin stylesheet must be linked');
$check(str_contains($rendered, '.a{color:red}') && str_contains($rendered, '.b{color:blue}'),
    'owner and skin presentation styles must survive');
$check(!str_contains($rendered, '<script'),
    'the head component must never emit script');

// Falls back to the site name when no title is supplied.
$fallback = (string)snap_render_component('document-head', ['site' => $view['site']]);
$fallback_with_no_title_or_extras = $fallback;
$check(str_contains($fallback, '<title>A Site &amp; Its &quot;Name&quot;</title>')
        || str_contains($fallback, '<title>A Site &amp; Its "Name"</title>'),
    'a missing title falls back to the site name, escaped');

// A skin needing more in its head declares it as bounded data. Raw markup is
// deliberately not accepted: snapsmack_trusted_html() is a content sanitiser
// and strips <link> outright, so a skin passing html would get nothing.
$extra = (string)snap_render_component('document-head', [
    'site' => $view['site'],
    'title' => 'T',
    'preconnect' => ['https://fonts.example'],
    'stylesheets' => ['https://example.test/extra.css'],
    'meta' => ['description' => 'A "quoted" & <tagged> description'],
]);
$check(str_contains($extra, '<link rel="preconnect" href="https://fonts.example" crossorigin>'),
    'a declared preconnect must be emitted');
$check(str_contains($extra, 'extra.css'), 'a declared stylesheet must be emitted');
$check(str_contains($extra, 'content="A &quot;quoted&quot; &amp; &lt;tagged&gt; description"'),
    'declared meta content must be escaped');
$check(str_ends_with($extra, '</head>'), 'declared extras stay inside the head');
$check((string)snap_render_component('document-head', [
        'site' => $view['site'], 'stylesheets' => ['javascript:alert(1)'],
    ]) === $fallback_with_no_title_or_extras,
    'an unsafe url contributes nothing');

// --- how many skins can adopt it unchanged -------------------------------
$identical = 0;
$different = [];
$heads = 0;
foreach (glob($root . '/skins/*/layout.php') ?: [] as $layout) {
    $skin = basename(dirname($layout));
    $source = (string)file_get_contents($layout);
    if (!preg_match('/<head>.*?<\/head>/s', $source, $match)) continue;
    $heads++;
    $template = $root . '/tests/.head-' . $skin . '-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($template, $match[0]);
    try {
        ob_start();
        include $template;                      // the skin's own head template
        $skinHead = (string)ob_get_clean();
    } finally {
        @unlink($template);
    }
    $normalise = static fn (string $html): string => trim((string)preg_replace('/\s+/', ' ', $html));
    if ($normalise($skinHead) === $normalise($rendered)) $identical++;
    else $different[$skin] = $normalise($skinHead);
}

// 25, not 30: five skins whose whole page lives in a core component have no
// head of their own to compare. The measured 25/19 baseline has been true
// since at least 0.7.886D; raise these numbers only when a skin adopts or
// restores its own layout without changing presentation.
$check($heads === 25, 'expected 25 skin-owned head blocks, found ' . $heads);
$check($identical >= 19,
    'at least 19 skins should match the shared head exactly, matched ' . $identical);

printf("document-head: %d of %d skin heads render identically to the asset.\n",
    $identical, $heads);
if ($different) {
    printf("  %d differ and need 'extra' when they adopt it: %s\n",
        count($different), implode(', ', array_keys($different)));
}

if ($failures) {
    fwrite(STDERR, "document-head asset regression FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "document-head asset regression passed.\n";

// ===== SNAPSMACK EOF =====
