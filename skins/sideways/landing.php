<?php
/* SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. */
/** SNAPSMACK — SIDEWAYS fixed-ratio intermittent wall. */
$limit = 200;
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
$rows = array_fill(0, 4, []);
foreach ($images as $i => $image) $rows[$i % 4][] = $image;
$site_name = trim((string)($settings['site_name'] ?? 'SnapSmack'));
$rest_min = max(2, min(15, (float)($settings['sideways_rest_min'] ?? 4)));
$rest_max = max($rest_min, min(30, (float)($settings['sideways_rest_max'] ?? 9)));

function sideways_tile(array $image): string {
    $thumb = trim((string)($image['img_thumb_aspect'] ?? ''));
    $src = BASE_URL . ltrim($thumb !== '' ? $thumb : (string)$image['img_file'], '/');
    $title = trim((string)($image['img_title'] ?? ''));
    return '<a class="sideways-tile" href="' . BASE_URL . htmlspecialchars((string)$image['img_slug']) . '"'
        . ' aria-label="' . htmlspecialchars($title !== '' ? $title : 'View photograph') . '">'
        . '<img src="' . htmlspecialchars($src) . '" alt="' . htmlspecialchars($title) . '" loading="lazy">'
        . '</a>';
}
?>
<main class="sideways-page" data-sideways-wall data-rest-min="<?php echo $rest_min; ?>" data-rest-max="<?php echo $rest_max; ?>">
    <div class="sideways-viewport" aria-label="Landscape photograph wall">
        <div class="sideways-field">
            <?php foreach ($rows as $row_index => $row): ?>
                <?php if (!$row) continue; ?>
                <div class="sideways-row" data-sideways-row data-direction="<?php echo $row_index % 2 ? '1' : '-1'; ?>">
                    <div class="sideways-track">
                        <?php foreach (array_merge($row, $row, $row, $row, $row) as $image) echo sideways_tile($image); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <header class="sideways-overlay">
        <a class="sideways-name" href="<?php echo BASE_URL; ?>"><?php echo htmlspecialchars($site_name); ?></a>
        <nav class="sideways-nav" aria-label="Site navigation">
            <a href="<?php echo BASE_URL; ?>archive.php">Archive</a>
            <a href="<?php echo BASE_URL; ?>about">About</a>
        </nav>
    </header>
</main>
<?php include __DIR__ . '/skin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
