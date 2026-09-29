<?php defined('SNAPSMACK_SKIN_RENDER') || exit;
/** SNAPSMACK — TILEZ presentation templates. CMS supplies $skin_view['smacktalk']. */
$view = $skin_view['smacktalk'] ?? ['kind' => 'not_found'];
$kind = (string)($view['kind'] ?? 'not_found');
?><!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($settings['site_language'] ?? 'en'); ?>">
<head>
<?php include __DIR__ . '/skin-meta.php'; ?>
<?php if ($kind === 'single'): ?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/columns.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/shortcodes.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-mosaic.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
<?php endif; ?>
</head>
<body class="<?php echo $kind === 'archive' ? 'archive alfred-archive' : ($kind === 'single' ? 'single' : ($kind === 'feed' ? 'blog' . (!empty($view['show_titles']) ? ' show-preview-titles' : '') : 'not-found')); ?>">
<?php include __DIR__ . '/skin-header.php'; ?>

<?php if ($kind === 'archive'): ?>
<main class="content" role="main"><section class="section-inner">
<?php if (empty($view['tiles'])): ?><p style="color:#fff;text-align:center;padding:4rem 0;">NO PHOTOGRAPHS YET.</p>
<?php else: ?><div class="alfred-archive-grid">
<?php foreach ($view['tiles'] as $tile): ?>
<a href="<?php echo htmlspecialchars($tile['full'], ENT_QUOTES); ?>" class="alfred-archive-tile" data-full="<?php echo htmlspecialchars($tile['full'], ENT_QUOTES); ?>" data-title="<?php echo htmlspecialchars($tile['title'], ENT_QUOTES); ?>" title="<?php echo htmlspecialchars($tile['title'], ENT_QUOTES); ?>"><img src="<?php echo htmlspecialchars($tile['thumb'], ENT_QUOTES); ?>" alt="<?php echo htmlspecialchars($tile['title'], ENT_QUOTES); ?>" loading="lazy"></a>
<?php endforeach; ?></div><?php endif; ?>
</section></main>
<div id="alfred-archive-lightbox" class="alfred-lightbox" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-label="Photograph viewer"><button type="button" class="alfred-lb-close" aria-label="Close">&#10005;</button><button type="button" class="alfred-lb-prev" aria-label="Previous photograph">&#8249;</button><img class="alfred-lb-img" src="" alt=""><button type="button" class="alfred-lb-next" aria-label="Next photograph">&#8250;</button><p class="alfred-lb-caption"></p></div>

<?php elseif ($kind === 'single'): $post = $view['post']; ?>
<main class="content" role="main">
<article class="post-container h-entry">
<?php snapsmack_indieweb_longform_properties($post, $settings); ?>
<div class="post-inner">
<div class="post-content entry-content e-content" data-merge-adjacent-mosaics>
<?php echo $view['rendered_content']; ?>
<?php if (!empty($view['signature'])): ?><div class="post-signature post-signature--closing"><img src="<?php echo htmlspecialchars($view['signature']['url'], ENT_QUOTES); ?>" alt="<?php echo htmlspecialchars($view['signature']['alt'], ENT_QUOTES); ?>"></div><?php endif; ?>
</div>
<aside class="post-record" aria-label="Post details">
<div class="post-header"><h1 class="post-title p-name"><?php echo htmlspecialchars($post['title']); ?></h1><p class="post-date"><time class="dt-published" datetime="<?php echo htmlspecialchars(date(DATE_ATOM, strtotime($post['created_at']))); ?>"><?php echo date('F j, Y', strtotime($post['created_at'])); ?></time></p></div>
<dl class="post-facts"><div><dt>Photos</dt><dd><?php echo number_format($view['photo_count']); ?></dd></div><div><dt>Words</dt><dd><?php echo number_format($view['word_count']); ?></dd></div><?php if (!empty($view['categories'])): ?><div><dt>Category</dt><dd><?php echo htmlspecialchars(implode(', ', $view['categories'])); ?></dd></div><?php endif; ?><?php if (!empty($view['albums'])): ?><div><dt>Album</dt><dd><?php echo htmlspecialchars(implode(', ', $view['albums'])); ?></dd></div><?php endif; ?><?php if ($view['author'] !== ''): ?><div><dt>Author</dt><dd class="p-author"><?php echo htmlspecialchars($view['author']); ?></dd></div><?php endif; ?></dl>
<?php if ($view['colophon'] !== ''): ?><div class="post-record-gear"><h2>Colophon</h2><?php echo $view['colophon']; ?></div><?php endif; ?>
</aside>
<?php if ($view['colophon'] !== ''): ?><div class="post-mobile-gear"><h2>Colophon</h2><?php echo $view['colophon']; ?></div><?php endif; ?>
</div></article>
<?php if (!empty($view['previous']) || !empty($view['next'])): ?><nav class="post-navigation" aria-label="Post navigation">
<?php if (!empty($view['next'])): ?><a href="<?php echo BASE_URL . '?post=' . rawurlencode($view['next']['slug']); ?>" class="post-nav-next" title="<?php echo htmlspecialchars($view['next']['title']); ?>"><span class="fa">&#8249;</span><p><?php echo htmlspecialchars($view['next']['title']); ?></p></a><?php endif; ?>
<?php if (!empty($view['previous'])): ?><a href="<?php echo BASE_URL . '?post=' . rawurlencode($view['previous']['slug']); ?>" class="post-nav-prev" title="<?php echo htmlspecialchars($view['previous']['title']); ?>"><p><?php echo htmlspecialchars($view['previous']['title']); ?></p><span class="fa">&#8250;</span></a><?php endif; ?>
</nav><?php endif; ?>
<?php if (!empty($view['comments_enabled'])): $img = $post; ?><div class="comments-container"><?php include dirname(__DIR__, 2) . '/core/community-component.php'; ?></div><?php endif; ?>
</main>

<?php elseif ($kind === 'feed'): ?>
<main class="content" role="main"><section class="section-inner">
<?php if (empty($view['posts'])): ?><p style="color:#fff;text-align:center;padding:4rem 0;">No posts yet.</p>
<?php else: ?><div class="posts ss-masonry tilez-posts">
<?php foreach ($view['posts'] as $post): $has_thumb = $post['image_url'] !== ''; ?>
<a href="<?php echo htmlspecialchars($post['url'], ENT_QUOTES); ?>" class="post<?php echo $has_thumb ? ' has-post-thumbnail' : ''; ?> ss-masonry-item" data-w="<?php echo (int)$post['width']; ?>" data-h="<?php echo (int)$post['height']; ?>" aria-label="<?php echo htmlspecialchars($post['title'], ENT_QUOTES); ?>"><?php if ($has_thumb): ?><img src="<?php echo htmlspecialchars($post['image_url'], ENT_QUOTES); ?>" data-w="<?php echo (int)$post['width']; ?>" data-h="<?php echo (int)$post['height']; ?>" alt="<?php echo htmlspecialchars($post['title'], ENT_QUOTES); ?>" loading="lazy"><?php endif; ?><div class="post-overlay"><div class="archive-post-header"><p class="archive-post-date"><?php echo date('M j, Y', strtotime($post['created_at'])); ?></p><h2 class="archive-post-title"><?php echo htmlspecialchars($post['title']); ?></h2></div></div></a>
<?php endforeach; ?></div><?php endif; ?>
<?php if ($view['total_pages'] > 1): ?><nav class="archive-nav" aria-label="Page navigation"><?php if ($view['page'] > 1): ?><a href="<?php echo BASE_URL . '?page=' . ($view['page'] - 1); ?>"><span>&#8592;</span></a><?php else: ?><span class="sep">&#8592;</span><?php endif; ?><span class="sep"><?php echo (int)$view['page']; ?> / <?php echo (int)$view['total_pages']; ?></span><?php if ($view['page'] < $view['total_pages']): ?><a href="<?php echo BASE_URL . '?page=' . ($view['page'] + 1); ?>"><span>&#8594;</span></a><?php else: ?><span class="sep">&#8594;</span><?php endif; ?></nav><?php endif; ?>
</section></main>

<?php else: ?>
<main class="content" role="main"><section class="section-inner" style="padding:4rem;text-align:center;"><h1>Post not found</h1><p><a href="<?php echo BASE_URL; ?>">Back to the front</a></p></section></main>
<?php endif; ?>

<?php include __DIR__ . '/skin-footer.php'; ?>
</body></html>
<?php // ===== SNAPSMACK EOF =====
