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
<nav class="navigation" aria-label="Primary"><div class="section-inner"><ul class="main-menu"><?php echo snap_render_html(snap_render_component('navigation-tree', ['items' => $view['response']['navigation'] ?? []])); ?></ul><button class="nav-toggle" type="button" aria-label="Toggle navigation"><span class="bars"><span class="bar"></span><span class="bar"></span><span class="bar"></span></span></button><div class="mobile-navigation"><ul class="mobile-menu"><?php echo snap_render_html(snap_render_component('navigation-tree', ['items' => $view['response']['navigation'] ?? []])); ?></ul></div></div></nav>
<div class="header-image" aria-hidden="true"><?php if (!empty($view['site']['skin_presentation']['header_media']['image'])): ?><img class="header-media-image" src="<?php echo snap_escape_url($view['site']['skin_presentation']['header_media']['image']); ?>" alt=""><?php endif; ?></div>
<header class="site-header header section-inner">
    <a class="site-title" href="<?php echo snap_route_url('home'); ?>"><?php if (!empty($view['site']['skin_presentation']['header_media']['logo'])): ?><img class="site-logo<?php if (!empty($view['site']['skin_presentation']['header_media']['retina'])): ?> site-logo-retina<?php endif; ?>" src="<?php echo snap_escape_url($view['site']['skin_presentation']['header_media']['logo']); ?>" alt="<?php echo snap_escape_attr($view['site']['site_name']); ?>"><?php else: ?><?php echo snap_escape_html($view['site']['site_name']); ?><?php endif; ?></a>
    <?php if (!empty($view['site']['skin_presentation']['header_media']['show_tagline']) && !empty($view['site']['tagline'])): ?>
        <p class="site-tagline blog-description"><?php echo snap_escape_html($view['site']['tagline']); ?></p>
    <?php endif; ?>
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

