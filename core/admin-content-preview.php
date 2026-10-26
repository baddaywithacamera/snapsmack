<?php
/** SNAPSMACK_EOF_HEADER: authenticated editor presentation; no persistence. */
declare(strict_types=1);

require_once __DIR__ . '/skin-settings.php';
require_once __DIR__ . '/skin-view-model.php';
require_once __DIR__ . '/skin-render-helpers.php';
require_once __DIR__ . '/skin-view-contract.php';
require_once __DIR__ . '/skin-presentation.php';
require_once __DIR__ . '/custom-code-policy.php';
require_once __DIR__ . '/asset-registry.php';
require_once __DIR__ . '/smacktalk-public-controller.php';

/** Called only after the preview endpoint has enforced the admin session. */
function snapsmack_admin_content_preview(PDO $pdo, array $settings, array $input): string
{
    $skin = (string)($settings['active_skin'] ?? '');
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $skin)) throw new RuntimeException('No valid active skin is configured.');
    if (function_exists('snapsmack_is_mobile') && snapsmack_is_mobile() && defined('SNAPSMACK_MOBILE_SKIN')
        && is_dir(dirname(__DIR__) . '/skins/' . SNAPSMACK_MOBILE_SKIN)) $skin = SNAPSMACK_MOBILE_SKIN;
    snapsmack_apply_skin_settings($settings, $skin);
    $base = defined('BASE_URL') ? BASE_URL : rtrim((string)($settings['site_url'] ?? '/'), '/') . '/';
    $manifest = load_skin_manifest($skin);
    $kind = ($input['preview_kind'] ?? '') === 'post' ? 'post' : 'page';
    $content = is_string($input['content'] ?? null) ? $input['content'] : '';
    $title = is_string($input['title'] ?? null) ? $input['title'] : '';
    $assets = snapsmack_merge_registered_assets(snapsmack_skin_declared_assets($manifest), [
        'styles' => ['asset:public:ss-engine-mosaic:css'],
        'scripts' => ['asset:public:ss-engine-mosaic', 'asset:public:ss-engine-lightbox'],
    ]);
    $version = rawurlencode((string)($manifest['version'] ?? '1'));
    $presentation = [
        'site_name' => (string)($settings['site_name'] ?? ''), 'tagline' => (string)($settings['site_tagline'] ?? ''),
        'owner_name' => (string)($settings['site_author'] ?? ''), 'base_url' => $base,
        'language' => 'en', 'direction' => 'ltr', 'skin_slug' => $skin,
        'avatar_url' => snapsmack_public_avatar_url($settings),
        'skin_style_url' => $base . 'skins/' . rawurlencode($skin) . '/style.css?v=' . $version,
        'base_style_url' => $base . 'assets/css/public-base.css',
        'skin_custom_style' => snapsmack_skin_custom_style($settings),
        'skin_presentation' => snapsmack_skin_presentation($settings, $skin),
        'owner_custom_code' => snapsmack_owner_custom_code($settings),
        'registered_assets' => $assets, 'footer' => snapsmack_prepare_public_footer($settings, $manifest),
    ];
    $variant = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($settings['active_skin_variant'] ?? '')));
    if ($variant !== '' && array_key_exists($variant, $manifest['variants'] ?? [])
        && is_file(dirname(__DIR__) . '/skins/' . $skin . '/variant-' . $variant . '.css')) {
        $presentation['skin_variant_url'] = $base . 'skins/' . rawurlencode($skin) . '/variant-' . $variant . '.css?v=' . $version;
    }
    $navigation = snapsmack_prepare_skin_view($pdo, $settings, $skin)['navigation'] ?? [];
    if ($kind === 'post' && ($manifest['cms_controller'] ?? '') === 'smacktalk') {
        $id = max(0, (int)($input['post_id'] ?? 0));
        $post = [];
        if ($id) {
            $query = $pdo->prepare("SELECT * FROM snap_posts WHERE id=? AND post_type='longform' LIMIT 1");
            $query->execute([$id]); $post = $query->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        $post = array_merge($post, ['id' => $id, 'title' => $title !== '' ? $title : ($post['title'] ?? 'Untitled preview'),
            'content' => $content, 'allow_comments' => 0,
            'created_at' => $post['created_at'] ?? date('Y-m-d H:i:s')]);
        if (isset($input['featured_image_id'])) $post['featured_image_id'] = max(0, (int)$input['featured_image_id']);
        if (is_string($input['post_date'] ?? null) && $input['post_date'] !== '' && strtotime($input['post_date']) !== false)
            $post['created_at'] = date('Y-m-d H:i:s', strtotime($input['post_date']));
        $response = snapsmack_smacktalk_single($pdo, $settings, $base, '', $id, $post);
        if (!$response) throw new RuntimeException('Could not build the unsaved post preview.');
        $response['kind'] = 'single'; $response['status'] = 200;
        $response['rendered_content'] = snapsmack_trusted_html((string)$response['rendered_content']);
    } else {
        $rendered = snap_wrap_bare_paragraphs((new SnapSmack($pdo))->parseContent($content));
        $response = ['status' => 200, 'kind' => 'page', 'page_title' => $title,
            'item' => ['title' => $title, 'content' => snapsmack_trusted_html($rendered)],
            'rendered_content' => snapsmack_trusted_html($rendered), 'comments_enabled' => false];
    }
    $response['navigation'] = $navigation;
    $view = snapsmack_build_skin_view($response, $presentation);
    $templates = $manifest['templates'] ?? [];
    $template = (string)($templates[$response['kind']] ?? $templates['default'] ?? '');
    ob_start();
    $ok = $template !== '' && snapsmack_render_strict_skin_template(dirname(__DIR__) . '/skins/' . $skin, $template, $view);
    $html = ob_get_clean();
    if (!$ok) throw new RuntimeException('The active skin does not provide a supported preview template.');
    // Keep the actual skin shell. The notice adds no substitute post layout.
    $notice = '<aside class="snap-preview-notice" role="status">PREVIEW — NOT PUBLISHED</aside>';
    $html = preg_replace('/(<body\b[^>]*>)/i', '$1' . $notice, $html, 1);
    return preg_replace('/(<title>)(.*?)(<\/title>)/is', '$1PREVIEW — $2$3', $html, 1);
}
// ===== SNAPSMACK EOF =====
