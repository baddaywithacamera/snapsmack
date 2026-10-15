<?php
defined('SNAPSMACK_SKIN_RENDER') || exit;

$item = is_array($view['response']['item'] ?? null) ? $view['response']['item'] : [];
$title = (string)($item['title'] ?? $view['response']['page_title'] ?? '');
$hero = trim((string)($item['image_asset'] ?? ''));
$base = (string)($view['site']['base_url'] ?? (defined('BASE_URL') ? BASE_URL : '/'));
$heroUrl = $hero === '' ? '' : rtrim($base, '/') . '/' . ltrim($hero, '/');
?>
<?php if ($heroUrl !== ''): ?><div id="photobox" class="page-hero"><div class="main-photo"><?php echo snap_render_html(snap_render_component('image', ['url' => $heroUrl, 'alt' => $title, 'class' => 'post-image'])); ?></div></div><?php endif; ?>
<div class="static-content"><h1 class="static-page-title"><?php echo snap_escape_html($title); ?></h1><div class="description entry-content e-content"><?php echo snap_render_html($item['content'] ?? $item['description'] ?? $view['response']['rendered_content'] ?? ''); ?></div></div>
<?php
// ===== SNAPSMACK EOF =====
