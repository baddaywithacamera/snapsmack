<?php
/* SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. */
/** SNAPSMACK — GLIDE kinetic wall landing. */
$limit = 72;
$stmt = $pdo->prepare(
    "SELECT id, img_title, img_slug, img_file, img_thumb_aspect, img_width, img_height
       FROM snap_images
      WHERE img_status = 'published' AND img_date <= NOW()
      ORDER BY sort_order ASC, id DESC
      LIMIT :lim"
);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->execute();
$images = $stmt->fetchAll(PDO::FETCH_ASSOC);

$rows = array_fill(0, 9, []);
foreach ($images as $i => $image) $rows[$i % count($rows)][] = $image;
$travel = (float)($settings['glide_travel'] ?? 0.9);
$travel = max(0.25, min(2, $travel));
$site_name = trim((string)($settings['site_name'] ?? 'SnapSmack'));
$flow_axis = (string)($settings['glide_flow_axis'] ?? 'horizontal');
if (!in_array($flow_axis, ['vertical', 'horizontal', 'diagonal_down', 'diagonal_up'], true)) {
    $flow_axis = 'horizontal';
}

function glide_tile(array $image): string {
    $thumb = trim((string)($image['img_thumb_aspect'] ?? ''));
    $src = BASE_URL . ltrim($thumb !== '' ? $thumb : (string)$image['img_file'], '/');
    $title = trim((string)($image['img_title'] ?? ''));
    $w = max(1, (int)($image['img_width'] ?? 3));
    $h = max(1, (int)($image['img_height'] ?? 2));
    return '<a class="glide-tile" href="' . BASE_URL . htmlspecialchars((string)$image['img_slug']) . '"'
        . ' aria-label="' . htmlspecialchars($title !== '' ? $title : 'View photograph') . '"'
        . ' style="--glide-aspect:' . $w . '/' . $h . '">'
        . '<img src="' . htmlspecialchars($src) . '" alt="' . htmlspecialchars($title) . '" loading="lazy">'
        . '</a>';
}
?>
<div class="glide-page" data-glide-wall data-flow-axis="<?php echo htmlspecialchars($flow_axis); ?>" data-travel="<?php echo htmlspecialchars((string)$travel); ?>">
    <div class="glide-viewport" aria-label="Photograph wall">
        <div class="glide-field">
            <?php foreach ($rows as $row_index => $row): ?>
                <?php if (!$row) continue; ?>
                <div class="glide-row" data-glide-row>
                    <div class="glide-track">
                        <?php foreach (array_merge($row, $row, $row, $row) as $image) echo glide_tile($image); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <header class="glide-overlay">
        <a class="glide-name" href="<?php echo BASE_URL; ?>"><?php echo htmlspecialchars($site_name); ?></a>
        <nav class="glide-nav" aria-label="Site navigation">
            <a href="<?php echo BASE_URL; ?>archive.php">Archive</a>
            <a href="<?php echo BASE_URL; ?>about">About</a>
        </nav>
    </header>
    <div class="glide-scroll-cue" aria-hidden="true"><span>DRAG / SCROLL</span><i></i></div>
</div>
<?php include __DIR__ . '/skin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
