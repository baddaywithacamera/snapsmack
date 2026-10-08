<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

define('BASE_URL', 'https://example.test/');
define('SNAPSMACK_SKIN_RENDER', true);

require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';
require_once dirname(__DIR__) . '/core/public-controller.php';
require_once dirname(__DIR__) . '/core/public-route-aliases.php';
require_once dirname(__DIR__) . '/core/public-runtime.php';

function recovery_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

recovery_check(
    snap_route_url('photo', ['slug' => 'ig_20261004_172525_87f9']) === 'https://example.test/ig_20261004_172525_87f9',
    'Photo links no longer use the collision-free canonical root slug.'
);
recovery_check(
    snap_route_url('page', ['slug' => 'about']) === 'https://example.test/about',
    'Page links no longer use the canonical root slug.'
);
recovery_check(
    snap_route_url('blogroll') === 'https://example.test/blogroll',
    'Blogroll links no longer enter the strict public controller.'
);

$legacyMenuSettings = ['nav_menu_json' => json_encode([
    ['type' => 'custom', 'label' => 'ARCHIVE VIEW', 'url' => '/archive.php', 'active' => true],
    ['type' => 'custom', 'label' => 'BLOGROLL', 'url' => '/blogroll.php', 'active' => true],
], JSON_THROW_ON_ERROR)];
$carouselAliases = snapsmack_public_route_aliases($legacyMenuSettings, [
    'cms_controller' => 'public',
    'site_mode' => 'carousel',
]);
recovery_check(
    ($carouselAliases['blogroll'] ?? '') === 'blogroll',
    'A legacy blogroll.php menu record no longer resolves at the clean /blogroll path.'
);
recovery_check(
    !in_array('archive', $carouselAliases, true),
    'A GRAMOFSMACK skin still manufactures a duplicate archive route from archive.php.'
);

$defaultPublicAliases = snapsmack_public_route_aliases([], [
    'cms_controller' => 'public',
    'site_mode' => 'carousel',
]);
recovery_check(
    ($defaultPublicAliases['blogroll'] ?? '') === 'blogroll',
    'An untouched strict GRAM site emits its default Blogroll link without registering the clean route.'
);

class RecoveryPageParserPDO extends PDO {
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        return new RecoveryPageParserStatement([], []);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new RecoveryPageParserStatement([
            'path' => 'media_assets/sean.jpg',
            'name' => 'Sean',
            'alt' => 'Sean McCormick',
            'bw' => 0,
            'bc' => '#000000',
        ], []);
    }
}
class RecoveryPageParserStatement extends PDOStatement {
    public function __construct(private array $row, private array $rows) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->row ?: false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}
$parsedPage = snapsmack_public_parse_page_content(new RecoveryPageParserPDO(), [
    'kind' => 'page',
    'item' => ['content' => '[img:1|full|center] Biography.'],
]);
recovery_check(
    str_contains((string)($parsedPage['item']['content'] ?? ''), 'media_assets/sean.jpg')
        && !str_contains((string)($parsedPage['item']['content'] ?? ''), '[img:1|full|center]'),
    'Strict static pages print legacy image shortcodes instead of expanding them through the CMS parser.'
);

$smacktalkAliases = snapsmack_public_route_aliases([
    'nav_menu_json' => json_encode([
        ['type' => 'archive', 'label' => 'DIARY', 'active' => true],
        ['type' => 'image_archive', 'label' => 'THE IMAGES', 'active' => true],
        ['type' => 'blogroll', 'label' => 'BLOGROLL', 'active' => true],
    ], JSON_THROW_ON_ERROR),
], ['cms_controller' => 'smacktalk']);
recovery_check(
    ($smacktalkAliases['diary'] ?? '') === 'diary'
        && ($smacktalkAliases['the-images'] ?? '') === 'archive'
        && ($smacktalkAliases['blogroll'] ?? '') === 'blogroll',
    'SMACKTALK clean aliases no longer keep the diary, image archive, and blogroll distinct.'
);

$framed = snapsmack_grid_frame_items(
    [['img_slug' => 'sample']],
    [
        'go_customize_level' => 'per_grid',
        'go_frame_size_pct' => '100',
        'go_frame_border_px' => '10',
        'go_frame_border_color' => '#123456',
        'go_frame_bg_color' => '#abcdef',
        'go_frame_shadow' => '0',
    ],
    'game-on'
);
recovery_check(($framed[0]['is_framed'] ?? false) === true, 'GAME ON global frame settings no longer mark framed tiles.');
foreach (['--tile-border-w:10px', '--tile-border-c:#123456', '--tile-bg:#abcdef'] as $needle) {
    recovery_check(str_contains((string)($framed[0]['frame_style'] ?? ''), $needle), 'GAME ON global frame style lost ' . $needle);
}

$orientationFramed = snapsmack_grid_frame_items(
    [
        ['img_slug' => 'rotated-portrait', 'img_orientation' => 1, 'img_width' => 1200, 'img_height' => 800],
        ['img_slug' => 'declared-landscape', 'img_orientation' => 0, 'img_width' => 800, 'img_height' => 1200],
        ['img_slug' => 'legacy-portrait', 'img_width' => 800, 'img_height' => 1200],
    ],
    ['tg_customize_level' => 'per_grid'],
    'the-grid'
);
recovery_check(($orientationFramed[0]['is_portrait'] ?? false) === true, 'THE GRID ignores the authoritative stored portrait orientation.');
recovery_check(($orientationFramed[1]['is_portrait'] ?? true) === false, 'THE GRID overrides an authoritative stored landscape orientation.');
recovery_check(($orientationFramed[2]['is_portrait'] ?? false) === true, 'THE GRID lost the dimension fallback for legacy rows without orientation data.');

$theGridCss = (string)file_get_contents(dirname(__DIR__) . '/skins/the-grid/style.css');
recovery_check(
    preg_match('/\.tg-tile--framed img\s*\{[^}]*width:\s*auto;[^}]*height:\s*auto;[^}]*max-width:\s*var\(--tile-img-size, 100%\);[^}]*max-height:\s*var\(--tile-img-size, 100%\);/s', $theGridCss) === 1,
    'THE GRID frame geometry no longer constrains both intrinsic image axes; legacy portrait covers will expose oversized side gutters.'
);

$gameOnCss = (string)file_get_contents(dirname(__DIR__) . '/skins/game-on/style.css');
recovery_check(
    preg_match('/\.go-tile--framed a\s*\{[^}]*background:\s*var\(--tile-bg, #ffffff\);[^}]*border-radius:\s*inherit;/s', $gameOnCss) === 1,
    'GAME ON framed-tile anchor lost the inherited radius; its square matte will expose opaque corners around rounded photographs.'
);

$response = [
    'kind' => 'blogroll',
    'status' => 200,
    'page_title' => 'BLOGROLL',
    'navigation' => [],
    'blogroll_groups' => [[
        'label' => 'FRIENDS',
        'items' => [[
            'name' => 'Example Photographer',
            'url' => 'https://photographer.example/',
            'description' => 'Photographs and words.',
        ]],
    ]],
];
$site = [
    'site_name' => 'Example',
    'language' => 'en',
    'direction' => 'ltr',
    'skin_style_url' => '/skins/game-on/style.css',
    'skin_presentation' => ['options' => [
        'go_puzzle_mode' => '', 'go_border_palette' => '', 'go_border_direction' => '',
        'go_puzzle_density' => '', 'go_motion_activity' => '', 'go_motion_speed' => '',
        'go_border_activity' => '', 'go_modal_theme' => '', 'go_profile_header' => '0',
    ], 'grid' => ['carousel_indicator' => '', 'hover_overlay' => ''], 'style' => snapsmack_trusted_html('')],
    'registered_assets' => [],
];
$view = snapsmack_build_skin_view($response, $site);
ob_start();
$rendered = snapsmack_render_strict_skin_template(dirname(__DIR__) . '/skins/game-on', 'layout.php', $view);
$html = (string)ob_get_clean();
recovery_check($rendered, 'GAME ON blogroll did not render through the strict skin.');
foreach (['game-on-v2 is-blogroll', 'go-blogroll', 'FRIENDS', 'Example Photographer'] as $needle) {
    recovery_check(str_contains($html, $needle), 'GAME ON blogroll lost ' . $needle);
}
recovery_check(
    str_contains($html, 'footer-metadata-bar') && !str_contains($html, '<p id="sig-text">'),
    'GAME ON no longer renders the configured standard footer.'
);

$pageResponse = [
    'kind' => 'page',
    'status' => 200,
    'navigation' => [],
    'item' => [
        'title' => 'About Sean',
        'content' => snapsmack_trusted_html('<p>Biography.</p>'),
        'image_asset' => '/media_assets/about.png',
        'image_size' => 'medium',
        'image_align' => 'center',
        'image_shadow' => 1,
    ],
];
$pageView = snapsmack_build_skin_view($pageResponse, $site);
ob_start();
$pageRendered = snapsmack_render_strict_skin_template(dirname(__DIR__) . '/skins/game-on', 'layout.php', $pageView);
$pageHtml = (string)ob_get_clean();
recovery_check($pageRendered, 'GAME ON static page did not render through the strict skin.');
foreach (['is-static-page', 'go-page-hero--medium', 'go-page-hero--center', 'go-page-hero--shadow', '/media_assets/about.png', 'go-static-body'] as $needle) {
    recovery_check(str_contains($pageHtml, $needle), 'GAME ON static page lost ' . $needle);
}

$gameCss = (string)file_get_contents(dirname(__DIR__) . '/skins/game-on/style.css');
recovery_check(
    !preg_match('/#system-footer\s*\{[^}]*position:\s*fixed/s', $gameCss)
        && str_contains($gameCss, '.go-content-wrap > main { flex: 1 0 auto; }'),
    'GAME ON footer is floating over content instead of following it.'
);

class RecoveryEmptyPDO extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new RecoveryEmptyStatement();
    }
}
class RecoveryEmptyStatement extends PDOStatement {
    public function execute(?array $params = null): bool { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
}
$repository = new SnapPublicRepository(new RecoveryEmptyPDO());
$disabledBlogroll = snapsmack_public_controller(
    $repository,
    ['route' => 'blogroll'],
    ['active_skin' => 'game-on', 'blogroll_enabled' => '0']
);
recovery_check(
    ($disabledBlogroll['status'] ?? 0) === 404 && ($disabledBlogroll['kind'] ?? '') === 'not_found',
    'Strict public controller no longer preserves disabled blogroll access control.'
);

$pageEntry = (string)file_get_contents(dirname(__DIR__) . '/page.php');
$blogrollEntry = (string)file_get_contents(dirname(__DIR__) . '/blogroll.php');
foreach ([$pageEntry, $blogrollEntry] as $source) {
    recovery_check(
        str_contains($source, "['smacktalk', 'public']")
            && str_contains($source, "['cms_controller']"),
        'A legacy public entry point no longer redirects both strict controller families.'
    );
}

$jiveLayout = (string)file_get_contents(dirname(__DIR__) . '/skins/jive-turkey/layout.php');
$jiveCss = (string)file_get_contents(dirname(__DIR__) . '/skins/jive-turkey/style.css');
recovery_check(
    str_contains($jiveLayout, 'class="jt-static-page jt-static-content"')
        && str_contains($jiveLayout, 'class="jt-static-body"'),
    'JIVE TURKEY static-page title and body no longer share the original padded reading container.'
);
recovery_check(
    preg_match('/\.jt-static-content\s*\{[^}]*padding:\s*40px 28px 60px/s', $jiveCss) === 1,
    'JIVE TURKEY static-page reading-container spacing changed.'
);

echo "Public route and GAME ON recovery regression passed.\n";
// ===== SNAPSMACK EOF =====
