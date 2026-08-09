<?php
/** FULL MONTY single photograph: the image is both environment and navigation. */
require_once dirname(__DIR__, 2) . '/core/layout-logic.php';
$fm_src = BASE_URL . ltrim((string)$img['img_file'], '/');
?>
<main class="fm-stage fm-solo" data-fm-stage data-fm-src="<?php echo htmlspecialchars($fm_src, ENT_QUOTES); ?>">
  <div class="fm-atmosphere" aria-hidden="true" style="background-image:url('<?php echo htmlspecialchars($fm_src, ENT_QUOTES); ?>')"></div>
  <?php include __DIR__ . '/skin-header.php'; ?>
  <a class="fm-hero-link" href="<?php echo BASE_URL; ?>archive.php" aria-label="Open the photograph archive">
    <img class="fm-hero post-image" src="<?php echo htmlspecialchars($fm_src, ENT_QUOTES); ?>" alt="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>">
  </a>
  <div class="fm-first-cue" data-fm-cue aria-hidden="true">CLICK THE PHOTOGRAPH TO STEP BACK</div>
  <div class="fm-a11y-meta">
    <h1><?php echo htmlspecialchars($img['img_title'] ?? ''); ?></h1>
    <div><?php echo $snapsmack->parseContent($img['img_description'] ?? ''); ?></div>
  </div>
  <?php include dirname(__DIR__, 2) . '/core/community-dock.php'; ?>
  <?php include __DIR__ . '/skin-footer.php'; ?>
</main>
<?php // ===== SNAPSMACK EOF =====
