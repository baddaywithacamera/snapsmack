<?php
/**
 * SNAPSMACK — SCROLL/MOSAIC full-library experiment.
 *
 * Hidden test endpoint. Published Gallery photographs are streamed in
 * deterministic six-image MOSAIC blocks without replacing the live landing.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/skin-settings.php';

$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim($settings['site_url'] ?? '/', '/') . '/');
}

$active_skin = $settings['active_skin'] ?? 'scroll';
snapsmack_apply_skin_settings($settings, $active_skin);
$site_name = $settings['site_name'] ?? 'SnapSmack';
$page_title = 'EAT ME, CLAUDE';

// Freeze the published set for the duration of one scrolling visit.
$_emc_now = date('Y-m-d H:i:s');
$cutoff = (string)($_GET['cutoff'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cutoff) || $cutoff > $_emc_now) {
    $cutoff = $_emc_now;
}

function eatmeclaude_block_sizes(int $total): array {
    if ($total <= 0) return [];
    if ($total <= 6) return [$total];

    $full = intdiv($total, 6);
    $remainder = $total % 6;
    $sizes = array_fill(0, $full, 6);

    // Avoid an ugly final singleton: 6+1 becomes 5+2.
    if ($remainder === 1) {
        $sizes[count($sizes) - 1] = 5;
        $sizes[] = 2;
    } elseif ($remainder > 0) {
        $sizes[] = $remainder;
    }
    return $sizes;
}

function eatmeclaude_images(PDO $pdo, string $cutoff, int $offset, int $limit): array {
    $stmt = $pdo->prepare(
        "SELECT i.id, i.img_title, i.img_slug, i.img_file, i.img_thumb_aspect,
                i.img_width, i.img_height,
                COALESCE(pi.img_focus_x, 50) AS img_focus_x,
                COALESCE(pi.img_focus_y, 50) AS img_focus_y
         FROM snap_images i
         LEFT JOIN snap_post_images pi ON pi.image_id = i.id
         WHERE i.img_status = 'published' AND i.img_date <= :cutoff
         ORDER BY i.sort_order ASC, i.id DESC
         LIMIT :lim OFFSET :off"
    );
    $stmt->bindValue(':cutoff', $cutoff);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $images = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $full = ltrim((string)($row['img_file'] ?? ''), '/');
        if ($full === '') continue;
        $thumb = ltrim((string)($row['img_thumb_aspect'] ?? ''), '/');
        if ($thumb === '') $thumb = $full;
        $title = trim((string)($row['img_title'] ?? ''));
        $images[] = [
            'src' => BASE_URL . $thumb,
            'full' => BASE_URL . $full,
            'href' => BASE_URL . ltrim((string)($row['img_slug'] ?? ''), '/'),
            'alt' => $title,
            'id' => (int)$row['id'],
            'width' => max(1, (int)($row['img_width'] ?? 1)),
            'height' => max(1, (int)($row['img_height'] ?? 1)),
            'focusX' => max(0, min(100, (float)($row['img_focus_x'] ?? 50))),
            'focusY' => max(0, min(100, (float)($row['img_focus_y'] ?? 50))),
            'lazy' => true,
        ];
    }
    return $images;
}

$count_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM snap_images
     WHERE img_status = 'published' AND img_date <= ?"
);
$count_stmt->execute([$cutoff]);
$total = (int)$count_stmt->fetchColumn();
$block_sizes = eatmeclaude_block_sizes($total);
$block = max(0, min(100000, (int)($_GET['block'] ?? 0)));
$offset = array_sum(array_slice($block_sizes, 0, $block));
$limit = $block_sizes[$block] ?? 0;
$images = $limit > 0 ? eatmeclaude_images($pdo, $cutoff, $offset, $limit) : [];
$has_more = ($block + 1) < count($block_sizes);

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    $json = json_encode([
        'images' => $images,
        'has_more' => $has_more,
        'next' => $block + 1,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        http_response_code(500);
        echo '{"images":[],"has_more":false,"next":0}';
    } else {
        echo $json;
    }
    exit;
}

$gap = max(0, min(25, (int)($settings['scroll_mosaic_gap'] ?? 6)));
$emphasis = (string)($settings['scroll_mosaic_emphasis'] ?? 'landscape');
if (!in_array($emphasis, ['natural', 'balanced', 'landscape', 'portrait'], true)) {
    $emphasis = 'landscape';
}
$skin_path = __DIR__ . '/skins/' . $active_skin;
include $skin_path . '/skin-meta.php';
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-mosaic.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/page-eatmeclaude.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-mosaic.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>" defer></script>
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-mosaic-feed.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>" defer></script>
<body class="scroll-static eatmeclaude-page">
<?php include $skin_path . '/skin-header.php'; ?>
<main class="scroll-wall eatmeclaude-content">
    <div id="eatmeclaude-feed" class="eatmeclaude-feed"
         data-gap="<?php echo $gap; ?>"
         data-emphasis="<?php echo htmlspecialchars($emphasis, ENT_QUOTES); ?>"
         style="--eatmeclaude-gap:<?php echo $gap; ?>px;">
        <?php if ($images): ?>
            <div class="snap-mosaic eatmeclaude-block"
                 data-gap="<?php echo $gap; ?>"
                 data-emphasis="<?php echo htmlspecialchars($emphasis, ENT_QUOTES); ?>"
                 data-mosaic="<?php echo htmlspecialchars(json_encode($images, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>"></div>
        <?php else: ?>
            <p class="scroll-empty">No photographs found.</p>
        <?php endif; ?>
    </div>
    <div id="eatmeclaude-sentinel" class="eatmeclaude-sentinel"
         data-next="1"
         data-has-more="<?php echo $has_more ? '1' : '0'; ?>"
         data-cutoff="<?php echo htmlspecialchars($cutoff, ENT_QUOTES); ?>"
         aria-hidden="true"></div>
    <p id="eatmeclaude-status" class="eatmeclaude-status" aria-live="polite"></p>
</main>
<?php include $skin_path . '/skin-footer.php'; ?>
<?php include __DIR__ . '/core/footer-scripts.php'; ?>
</body>
</html>
<?php // ===== SNAPSMACK EOF =====
