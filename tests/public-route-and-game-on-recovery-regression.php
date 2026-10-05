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
    snap_route_url('blogroll') === 'https://example.test/?view=blogroll',
    'Blogroll links no longer enter the strict public controller.'
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

echo "Public route and GAME ON recovery regression passed.\n";
// ===== SNAPSMACK EOF =====
