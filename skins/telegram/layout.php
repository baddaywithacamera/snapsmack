<?php defined('SNAPSMACK_SKIN_RENDER') || exit; ?>
<!doctype html>
<html lang="<?php echo snap_escape_attr($view['site']['language']); ?>" dir="<?php echo snap_escape_attr($view['site']['direction']); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo snap_escape_html($view['response']['page_title'] ?? $view['site']['site_name']); ?></title>
    <link rel="stylesheet" href="<?php echo snap_escape_url($view['site']['skin_style_url']); ?>">
    <?php echo snap_render_html($view['site']['skin_custom_style'] ?? ''); ?>
</head>
<body class="telegram-v2">
<header class="site-header">
    <a class="site-title" href="<?php echo snap_route_url('home'); ?>"><?php echo snap_escape_html($view['site']['site_name']); ?></a>
    <?php if (!empty($view['site']['tagline'])): ?>
        <p class="site-tagline"><?php echo snap_escape_html($view['site']['tagline']); ?></p>
    <?php endif; ?>
    <?php echo snap_render_html(snap_render_component('navigation', ['items' => $view['response']['navigation']])); ?>
</header>

<main class="post-inner telegram-reading-column">
<?php if ($view['response']['kind'] === 'single'): ?>
    <article class="telegram-post">
        <h1><?php echo snap_escape_html($view['response']['post']['title']); ?></h1>
        <p class="telegram-date"><?php echo snap_escape_html($view['response']['post']['created_at']); ?></p>
        <?php if (!empty($view['response']['post']['featured_image_path'])): ?>
            <?php echo snap_render_html(snap_render_component('image', ['url' => $view['response']['post']['featured_image_path'], 'alt' => $view['response']['post']['title']])); ?>
        <?php endif; ?>
        <div class="telegram-content"><?php echo snap_render_html($view['response']['rendered_content']); ?></div>
        <?php if (!empty($view['response']['colophon'])): ?>
            <aside class="telegram-colophon"><?php echo snap_escape_html($view['response']['colophon']); ?></aside>
        <?php endif; ?>
        <?php if (!empty($view['response']['comments_enabled'])): ?>
            <?php echo snap_render_html(snap_render_component('comments', ['items' => $view['response']['comments']])); ?>
        <?php endif; ?>
    </article>
<?php elseif ($view['response']['kind'] === 'feed'): ?>
    <section class="telegram-feed">
        <?php foreach ($view['response']['posts'] as $post): ?>
            <article class="telegram-feed-item">
                <a href="<?php echo snap_escape_url($post['url']); ?>">
                    <?php if (!empty($post['image_url'])): ?>
                        <?php echo snap_render_html(snap_render_component('image', ['url' => $post['image_url'], 'alt' => $post['title']])); ?>
                    <?php endif; ?>
                    <h2><?php echo snap_escape_html($post['title']); ?></h2>
                </a>
            </article>
        <?php endforeach; ?>
    </section>
<?php elseif ($view['response']['kind'] === 'archive'): ?>
    <section class="telegram-archive">
        <h1><?php echo snap_escape_html($view['response']['page_title']); ?></h1>
        <?php foreach ($view['response']['tiles'] as $tile): ?>
            <a href="<?php echo snap_escape_url($tile['full']); ?>">
                <?php echo snap_render_html(snap_render_component('image', ['url' => $tile['thumb'], 'alt' => $tile['title']])); ?>
            </a>
        <?php endforeach; ?>
    </section>
<?php else: ?>
    <section class="telegram-not-found"><h1>Not found</h1></section>
<?php endif; ?>
</main>

<footer class="site-footer"><p><?php echo snap_escape_html($view['site']['site_name']); ?></p></footer>
<?php echo snap_render_html(snap_render_component('registered-assets', ['assets' => $view['site']['registered_assets'] ?? []])); ?>
<?php echo snap_render_html($view['site']['owner_custom_code'] ?? ''); ?>
</body>
</html>
