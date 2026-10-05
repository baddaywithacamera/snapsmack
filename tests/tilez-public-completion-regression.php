<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string)file_get_contents($root . '/core/smacktalk-public-controller.php');
$repository = (string)file_get_contents($root . '/core/public-repository.php');
$navigation = (string)file_get_contents($root . '/core/skin-view-model.php');
$layout = (string)file_get_contents($root . '/skins/tilez/layout.php');
$style = (string)file_get_contents($root . '/skins/tilez/style.css');
$manifest = (string)file_get_contents($root . '/skins/tilez/manifest.json');
$menuBuilder = (string)file_get_contents($root . '/assets/js/ss-engine-menu-builder.js');
$routes = (string)file_get_contents($root . '/core/skin-render-helpers.php');
$assetRegistry = (string)file_get_contents($root . '/core/asset-registry.php');
$footerController = (string)file_get_contents($root . '/assets/js/ss-engine-footer.js');
$presentation = (string)file_get_contents($root . '/core/public-skin-presentation.php');
$smackpressApi = (string)file_get_contents($root . '/core/smackpress-api.php');
$coldSnapPoster = (string)file_get_contents($root . '/tools/coldsnap/sumna_post.py');
$repairExisting = (string)file_get_contents($root . '/tools/smackpress/repair_existing.py');
$routeAliases = (string)file_get_contents($root . '/core/public-route-aliases.php');
$frontController = (string)file_get_contents($root . '/index.php');

$expect = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$expect(str_contains($controller, "\$view === 'categories' || \$view === 'albums'")
    && str_contains($layout, 'tilez-taxonomy-page'), 'Categories or albums bypass the strict TILEZ directory.');
$expect(str_contains($navigation, "'?view=categories'") || str_contains($navigation, "'categories'"),
    'Category navigation no longer resolves through the strict controller.');
$expect(str_contains($style, '.blogroll-grid { columns: 2;')
    && str_contains($style, 'break-inside: avoid'), 'Blogroll groups can create false vertical holes.');
$expect(str_contains($style, '#page > #system-footer { margin-top: auto;'), 'Footer is no longer anchored to the page.');
$drawerGuard = strpos($footerController, 'if (footer && hasDrawer)');
$footerHide = strpos($footerController, "footer.style.display = 'none';");
$expect(str_contains($footerController, 'const hasDrawer = paneInfo || paneComm || paneHelp;')
    && $drawerGuard !== false
    && $footerHide !== false
    && $drawerGuard < $footerHide,
    'The shared drawer controller can hide the normal Admin-configured footer.');
$expect(!str_contains(strtolower($manifest), 'the-idea')
    && !str_contains(strtolower($manifest), 'bad-day-masthead'),
    'TILEZ package defaults contain site-specific navigation or branding.');
$expect(str_contains($manifest, '"tilez_info_page_slug"')
    && str_contains($manifest, '"default": "about"')
    && str_contains($layout, "tilez_info_page_slug'] ?? 'about'"),
    'TILEZ information shortcut is not backed by a reusable per-site page setting.');
$expect(str_contains($menuBuilder, 'Supports three levels of nesting')
    && str_contains($menuBuilder, 'makeChildRow')
    && str_contains($menuBuilder, 'menu-grandchildren-list')
    && str_contains($menuBuilder, 'depth < 2 ? clean(item.children, depth + 1) : []'),
    'Menu Manager no longer preserves ordered root, child, and grandchild levels.');
$expect(str_contains($controller, 'snapsmack_smacktalk_slug_url')
    && str_contains($controller, "'redirect_status' => 301")
    && !str_contains($navigation, '?view=page&slug=')
    && str_contains($routes, "\$route === 'page' || \$route === 'post'"),
    'SMACKTALK exposed internal query routing instead of canonical readable slugs.');
$expect(str_contains($routeAliases, 'snapsmack_public_route_slug')
    && str_contains($routeAliases, "'blogroll' => 'blogroll'")
    && str_contains($routeAliases, "? 'diary' : 'archive'")
    && str_contains($navigation, 'snapsmack_public_route_url')
    && !str_contains($navigation, "\$base . '?view=blogroll'")
    && !str_contains($navigation, "\$base . '?view=archive'")
    && str_contains($controller, "'redirect_status' => 301")
    && str_contains($frontController, "'route_aliases' => \$skin_view['route_aliases'] ?? []"),
    'Configured public menu labels no longer produce canonical readable routes.');
$expect(str_contains($controller, "if (\$view === 'diary')")
    && str_contains($controller, "'is_diary' => true")
    && str_contains($controller, 'snapsmack_smacktalk_feed'),
    'The SMACKTALK diary route collapsed back into the unrelated photograph archive.');
$expect(str_contains($manifest, '"asset:public:public-base"')
    && str_contains($manifest, '"asset:public:shortcodes"')
    && str_contains($manifest, '"asset:public:columns"'),
    'TILEZ stopped loading the shared longform image presentation styles.');
$expect(str_contains($assetRegistry, "if(isset(\$registry[\$handle]))")
    && str_contains($assetRegistry, "':css'"),
    'Paired mosaic JavaScript and CSS can overwrite one another in the asset registry.');
$expect(str_contains($controller, "\$post['signature_image_id']")
    && !str_contains(strtolower($controller), 'mccormick')
    && !str_contains(strtolower($repository), 'mccormick'),
    'Public rendering guesses a site-specific signature instead of using the post contract.');
$expect(!str_contains($controller, 'ownedPhotographsForPost')
    && !str_contains($controller, 'images? (?:made|taken|shot) with')
    && !str_contains($repository, 'ownedPhotographsForPost'),
    'Public rendering is repairing incomplete imports instead of rendering stored post fields.');
$expect(!str_contains(strtolower($presentation), 'bad-day-masthead'),
    'Core presentation contains site-specific TILEZ branding.');
$expect(str_contains($repository, 'menu_order,created_at')
    && str_contains($controller, "'publication_date'")
    && str_contains($controller, "'photo_count'")
    && str_contains($controller, "'word_count'")
    && str_contains($layout, 'page-record post-record')
    && str_contains($layout, '<dt>Photos</dt>')
    && str_contains($layout, '<dt>Words</dt>'),
    'Static pages no longer expose their publication record beside the content.');
$expect(str_contains($layout, 'taxonomy-split')
    && str_contains($layout, 'taxonomy-record')
    && str_contains($layout, 'taxonomy-links')
    && str_contains($style, '.taxonomy-split { display: grid;'),
    'Category and album directories lost their linked image-and-list split.');
$expect(str_contains($smackpressApi, "\$body['created_at'] ?? (\$body['date'] ?? null)")
    && str_contains($coldSnapPoster, 'payload["date"] = draft.post_date'),
    'SMACKPRESS pages no longer retain the source publication date.');
$expect(str_contains($smackpressApi, "preg_match('#^pages/(\\d+)$#'")
    && str_contains($smackpressApi, "\$page['bucket'] = \$images")
    && str_contains($coldSnapPoster, 'def get_page(self, page_id: int) -> dict:')
    && str_contains($repairExisting, 'current = poster.get_page(int(destination["id"]))')
    && str_contains($repairExisting, 'image.remote_image_id = int(existing["id"])'),
    'SMACKPRESS pages lost the read-back media contract required for idempotent repair.');
$expect(str_contains($style, '.blogroll-heading h1 {')
    && str_contains($style, 'font-size: clamp(2rem, 2.6vw, 2.7rem);')
    && str_contains($style, 'font-weight: 700;'),
    'The BLOGROLL title no longer matches post-title typography.');

echo "TILEZ public completion regression passed.\n";

// ===== SNAPSMACK EOF =====
