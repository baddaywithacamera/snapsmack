<?php
/**
 * 52 Card Pickup - Pile Landing Page
 *
 * Renders a randomised pile of photographs using the pile engine (ss-engine-pile.js).
 * AJAX endpoint at the top returns random images as JSON when ?ajax=pile is requested.
 * Variables available from index.php: $pdo, $settings, $img, $active_skin, $site_name
 */

// ── AJAX endpoint: return random images as JSON ──────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'pile') {
    header('Content-Type: application/json');

    $count = max(10, min(30, (int)($_GET['count'] ?? 20)));
    $now_local = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        SELECT id, img_title, img_slug, img_file, img_thumb_aspect
        FROM snap_images
        WHERE img_status = 'published' AND img_date <= ?
        ORDER BY RAND()
        LIMIT ?
    ");
    $stmt->execute([$now_local, $count]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $images = [];
    foreach ($rows as $r) {
        $src = !empty($r['img_thumb_aspect'])
            ? BASE_URL . ltrim($r['img_thumb_aspect'], '/')
            : BASE_URL . ltrim($r['img_file'], '/');
        $images[] = [
            'id'    => (int)$r['id'],
            'title' => $r['img_title'],
            'src'   => $src,
            'url'   => BASE_URL . htmlspecialchars($r['img_slug']),
        ];
    }

    echo json_encode(['images' => $images]);
    exit;
}

// ── Config from settings ─────────────────────────────────────────────────
$pile_size         = (int)($settings['htbs_pile_size'] ?? 20);
$scatter           = $settings['htbs_scatter_radius'] ?? 'medium';
$rotation_max      = (int)($settings['htbs_rotation_max'] ?? 8);
$max_image_width   = (int)($settings['htbs_max_image_width'] ?? 280);
$hover_title       = ($settings['htbs_hover_title'] ?? '1') === '1';
$keyboard_reshuffle = ($settings['htbs_keyboard_reshuffle'] ?? '1') === '1';
$transition_speed  = (int)($settings['htbs_transition_speed'] ?? 300);
$frame_styles      = $settings['htbs_frame_styles'] ?? 'polaroid,print';
$reshuffle_label   = $settings['htbs_reshuffle_label'] ?? 'Reshuffle';
?>

<?php include('skin-header.php'); ?>

<div class="pile-canvas"
     data-pile
     data-api-url="<?php echo BASE_URL; ?>?ajax=pile"
     data-pile-size="<?php echo $pile_size; ?>"
     data-scatter="<?php echo htmlspecialchars($scatter); ?>"
     data-rotation-max="<?php echo $rotation_max; ?>"
     data-max-width="<?php echo $max_image_width; ?>"
     data-transition-speed="<?php echo $transition_speed; ?>"
     data-hover-title="<?php echo $hover_title ? '1' : '0'; ?>"
     data-keyboard-reshuffle="<?php echo $keyboard_reshuffle ? '1' : '0'; ?>"
     data-frame-styles="<?php echo htmlspecialchars($frame_styles); ?>">
</div>

<button class="pile-reshuffle" data-pile-reshuffle>
    <?php echo htmlspecialchars($reshuffle_label); ?>
</button>

<?php include('skin-footer.php'); ?>
// EOF
