<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A site with no saved menu keeps the navigation it had before the conversion.
 *
 * core/header.php at v0.7.603D built the fallback masthead as
 * HOME | BLOG? | ARCHIVE VIEW | ALBUMS? | FLOATING GALLERY? | BLOGROLL |
 * FEED? | pages, each item gated on its own setting. The rewrite of 6 October
 * 2026 replaced the entire list with Home and Blogroll, so every site without
 * a Menu Manager configuration silently lost Archive View -- and Albums, the
 * wall, Blog and Feed with it.
 *
 * Sean, on hekeepsdroningon.ca: "archive link is missing". He had asked once
 * before and I measured half of it and moved on.
 */

$root = dirname(__DIR__);
$fail = [];
$check = static function (bool $ok, string $what) use (&$fail): void {
    if (!$ok) $fail[] = $what;
};

require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test/');
require_once $root . '/core/public-route-aliases.php';
require_once $root . '/core/skin-view-model.php';

// No pages table: this check is about the built-in items, not page links.
$pdo = new class extends PDO {
    public function __construct() {}
    public function query(string $q, ?int $m = null, mixed ...$a): PDOStatement|false {
        throw new PDOException('no pages in this check');
    }
};
$labels = static function (array $settings) use ($pdo): array {
    $nav = snapsmack_prepare_skin_navigation($pdo, $settings, ['cms_controller' => 'public']);
    return array_map(static fn(array $i): string => (string)($i['label'] ?? ''), $nav);
};

// ── the default masthead ────────────────────────────────────────────────────
$default = $labels([]);
$check($default === ['Home', 'Archive View', 'Blogroll'],
    'the default menu is not Home / Archive View / Blogroll but ' . implode(' | ', $default));

// ── every item still answers to the setting that always gated it ────────────
$cases = [
    ['Archive View', ['archive_layout' => 'none'], false],
    ['Albums', ['albums_link_enabled' => '1'], true],
    ['Floating Gallery', ['show_wall_link' => '1'], true],
    ['Blog', ['homepage_mode' => 'static_page'], true],
    ['Feed', ['photochallenge_feed_enabled' => '1'], true],
    ['Blogroll', ['blogroll_enabled' => '0'], false],
];
foreach ($cases as [$label, $settings, $expected]) {
    $present = in_array($label, $labels($settings), true);
    $check($present === $expected,
        sprintf('%s should be %s with %s', $label, $expected ? 'present' : 'absent', json_encode($settings)));
}

// A carousel site has one continuous scroll and no separate archive. That
// rule predates this and must survive it.
$check(!in_array('Archive View', $labels(['site_mode' => 'carousel']), true),
    'a carousel site was given an Archive View link');

// ── and the archive link points somewhere that exists ───────────────────────
// The fallback used to be 'images'. Measured on hekeepsdroningon.ca:
// /images and /archive-view are 404, /archive is 200.
$nav = snapsmack_prepare_skin_navigation($pdo, [], ['cms_controller' => 'public']);
$archive = '';
foreach ($nav as $item) if (($item['label'] ?? '') === 'Archive View') $archive = (string)($item['url'] ?? '');
$check(str_ends_with($archive, '/archive'),
    'the default Archive View link is ' . ($archive ?: '(none)') . ', not the /archive route that answers');

// ── the two skins that relied on the old uppercase markup ───────────────────
// Five sibling skins state this in CSS. GALLERIA and SLICKR did not, because
// core/header.php printed HOME and ARCHIVE VIEW as literals. The Menu Manager
// model carries natural-case labels, so the rule has to be in the stylesheet.
foreach (['galleria', 'slickr', 'rational-geo', 'true-grit', 'chaplin', 'impact-printer'] as $skin) {
    $css = (string)file_get_contents($root . '/skins/' . $skin . '/style.css');
    $check(preg_match('/[^{}]*nav-menu[^{}]*\{[^}]*text-transform:\s*uppercase/', $css) === 1,
        $skin . ' no longer renders its navigation in capitals');
}

if ($fail) {
    fwrite(STDERR, "FAIL: default navigation\n  - " . implode("\n  - ", $fail) . "\n");
    exit(1);
}
echo "PASS: the default masthead is back, every item on its own setting, pointing at /archive.\n";
// ===== SNAPSMACK EOF =====
