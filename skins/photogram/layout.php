<?php defined('SNAPSMACK_SKIN_RENDER') || exit; ?>
<!doctype html>
<html lang="<?php echo snap_escape_attr($view['site']['language']); ?>" dir="<?php echo snap_escape_attr($view['site']['direction']); ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo snap_escape_html($view['response']['page_title'] ?? $view['site']['site_name']); ?></title><link rel="stylesheet" href="<?php echo snap_escape_url($view['site']['skin_style_url']); ?>"></head>
<body class="photogram-v2"><?php echo snap_render_html(snap_render_component('public-page', $view)); ?></body>
</html>