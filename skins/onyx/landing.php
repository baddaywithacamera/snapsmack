<?php
/** ONYX — native-aspect masonry landing wall, using the SCROLL columns engine. */

$_onyx_page_size = max(12, min(60, (int)($settings['onyx_wall_page_size'] ?? 36)));
$_onyx_json = (($_GET['format'] ?? '') === 'json') && (($_GET['pg'] ?? '') === 'wall');
$_onyx_chunk = $_onyx_json ? min(1000000, max(0, (int)($_GET['c'] ?? 0))) : 0;
$_onyx_offset = $_onyx_chunk * $_onyx_page_size;
$_onyx_now = date('Y-m-d H:i:s');
$_onyx_cutoff = (string)($_GET['t'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $_onyx_cutoff) || $_onyx_cutoff > $_onyx_now) $_onyx_cutoff = $_onyx_now;

$_onyx_count = $pdo->prepare("SELECT COUNT(*) FROM snap_images WHERE img_status='published' AND img_date<=?");
$_onyx_count->execute([$_onyx_cutoff]);
$_onyx_total = (int)$_onyx_count->fetchColumn();
$_onyx_stmt = $pdo->prepare("SELECT id,img_title,img_slug,img_file,img_thumb_aspect,img_width,img_height
    FROM snap_images WHERE img_status='published' AND img_date<=:cutoff
    ORDER BY sort_order ASC,id DESC LIMIT :lim OFFSET :off");
$_onyx_stmt->bindValue(':cutoff', $_onyx_cutoff);
$_onyx_stmt->bindValue(':lim', $_onyx_page_size, PDO::PARAM_INT);
$_onyx_stmt->bindValue(':off', $_onyx_offset, PDO::PARAM_INT);
$_onyx_stmt->execute();
$_onyx_images = $_onyx_stmt->fetchAll(PDO::FETCH_ASSOC);
$_onyx_more = ($_onyx_offset + count($_onyx_images)) < $_onyx_total;

if (!function_exists('onyx_wall_tile')) {
function onyx_wall_tile(array $img): string {
    $w = max(1, (int)($img['img_width'] ?? 0));
    $h = max(1, (int)($img['img_height'] ?? 0));
    if ($w === 1 && $h === 1) { $w = 3; $h = 2; }
    $thumb = trim((string)($img['img_thumb_aspect'] ?? '')) ?: (string)($img['img_file'] ?? '');
    $title = trim((string)($img['img_title'] ?? ''));
    return '<a class="ss-masonry-item onyx-wall-item" href="' . BASE_URL . htmlspecialchars(ltrim((string)$img['img_slug'], '/')) . '"'
        . ' aria-label="' . htmlspecialchars($title ?: 'View photograph') . '">'
        . '<img src="' . htmlspecialchars(BASE_URL . ltrim($thumb, '/')) . '" data-w="' . $w . '" data-h="' . $h . '"'
        . ' alt="' . htmlspecialchars($title) . '" loading="lazy">'
        . '<span class="onyx-wall-title">' . htmlspecialchars($title) . '</span></a>';
}
}

if ($_onyx_json) {
    $_onyx_html = '';
    foreach ($_onyx_images as $_onyx_img) $_onyx_html .= onyx_wall_tile($_onyx_img);
    header('Content-Type: application/json');
    echo json_encode(['html'=>$_onyx_html,'next'=>$_onyx_chunk+1,'has_more'=>$_onyx_more,'cutoff'=>$_onyx_cutoff]);
    return;
}
?>
<div id="scroll-stage" class="onyx-landing">
<?php include __DIR__ . '/skin-header.php'; ?>
<main class="onyx-wall-shell">
  <header class="onyx-wall-heading">
    <p><?php echo htmlspecialchars((string)($settings['site_tagline'] ?? '')); ?></p>
  </header>
  <div class="ss-masonry onyx-photo-wall">
    <?php foreach ($_onyx_images as $_onyx_img) echo onyx_wall_tile($_onyx_img); ?>
  </div>
  <?php if ($_onyx_more): ?><div class="scroll-wall-sentinel" data-next="1" data-cutoff="<?php echo htmlspecialchars($_onyx_cutoff); ?>" data-has-more="1" aria-hidden="true"></div><?php endif; ?>
  <?php if (!$_onyx_images): ?><p class="empty-sector-msg">No photographs found.</p><?php endif; ?>
</main>
</div>
<?php include __DIR__ . '/skin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF ===== ?>
