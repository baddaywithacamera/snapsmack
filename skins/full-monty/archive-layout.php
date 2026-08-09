<?php
/** FULL MONTY diagonal square archive. */
$fm_hero = !empty($images[0]['img_file']) ? BASE_URL . ltrim($images[0]['img_file'], '/') : '';
?>
<main class="fm-stage fm-archive" data-fm-stage data-fm-src="<?php echo htmlspecialchars($fm_hero, ENT_QUOTES); ?>">
  <?php if ($fm_hero !== ''): ?><div class="fm-atmosphere" aria-hidden="true" style="background-image:url('<?php echo htmlspecialchars($fm_hero, ENT_QUOTES); ?>')"></div><?php endif; ?>
  <div class="fm-diagonal" role="list">
  <?php foreach ($images as $photo):
      $thumb = $photo['img_thumb_square'] ?? '';
      if ($thumb === '') {
          $path = ltrim((string)($photo['img_file'] ?? ''), '/');
          $thumb = dirname($path) . '/thumbs/t_' . basename($path);
      }
  ?>
    <a role="listitem" class="fm-tile" href="<?php echo BASE_URL . htmlspecialchars($photo['img_slug'] ?? ''); ?>" title="<?php echo htmlspecialchars($photo['img_title'] ?? ''); ?>">
      <img src="<?php echo BASE_URL . ltrim($thumb, '/'); ?>" alt="<?php echo htmlspecialchars($photo['img_title'] ?? ''); ?>" loading="lazy">
    </a>
  <?php endforeach; ?>
  </div>
</main>
<?php // ===== SNAPSMACK EOF =====
