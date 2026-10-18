<?php
/**
 * SNAPSMACK - Public collections index page (0.7.79 — v0.2)
 *
 * Lists all visible (published = 1) collections as a grid of tiles.
 * Each tile: featured image + name + member count, links to /collection.php.
 *
 * Sort options (visitor toggle, persisted via cookie):
 *   manual       — snap_collections.sort_order ASC (admin's drag-reorder)
 *   alphabetical — name ASC
 *   newest       — created_at DESC
 *   oldest       — created_at ASC
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/skin-settings.php';
require_once __DIR__ . '/core/skin-view-model.php';

// SMACKBACK: silent stat check on public page loads
try {
    $_smack_cfg = $pdo->query(
        "SELECT setting_key, setting_val FROM snap_settings
         WHERE setting_key IN ('smackback_enabled', 'smackback_pageload_check')"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    if (($_smack_cfg['smackback_enabled'] ?? '0') === '1'
        && ($_smack_cfg['smackback_pageload_check'] ?? '0') === '1') {
        require_once __DIR__ . '/core/smackback.php';
        smackback_verify_quick();
    }
    unset($_smack_cfg);
} catch (PDOException $e) { /* non-fatal */ }

$settings    = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
require_once __DIR__ . '/core/maintenance-gate.php';
$active_skin = $settings['active_skin'] ?? 'smackdown';
$site_name   = $settings['site_name']   ?? 'SNAPSMACK';

if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim($settings['site_url'] ?? '/', '/') . '/');
}

if (snapsmack_is_mobile() && is_dir(__DIR__ . '/skins/' . SNAPSMACK_MOBILE_SKIN)) {
    $active_skin = SNAPSMACK_MOBILE_SKIN;
}
snapsmack_apply_skin_settings($settings, $active_skin);
$skin_view = snapsmack_prepare_skin_view($pdo, $settings, $active_skin);

// A legacy collections.php link must not drop a strict skin into the old
// unstyled document. That document loads one stylesheet -- public-facing.css --
// and every class this page uses (collections-canvas, collections-series,
// series-row, alt-btn) is styled in assets/css/page-collection.css, which only
// the skin-rendered pages load. It also builds its own menu from the legacy
// branch, which is where foreverphotograph.ing's phantom "ARCHIVE VIEW" came
// from: that site's real menu has no archive item at all.
//
// 0.7.903D tried to fix this with a redirect, the way archive.php does, and it
// could not work. Apache MultiViews resolves /collections to this file before
// any rewrite runs, so the target address IS this address and the loop guard
// correctly declined. Measured 18 October on foreverphotograph.ing:
// /collectionszz reaches index.php and renders a skinned 404 with thirteen
// stylesheets; /collections renders this file with one.
//
// So render the skin here instead of asking somebody else to. Any failure
// falls through to the legacy page below rather than showing a visitor an
// error: a plain page is worse than a styled one and better than nothing.
require_once __DIR__ . '/core/skin-manifest.php';
$_collections_manifest = load_skin_manifest($active_skin);
if (in_array((string)($_collections_manifest['cms_controller'] ?? ''), ['smacktalk', 'public'], true)) {
    try {
        require_once __DIR__ . '/core/public-repository.php';
        require_once __DIR__ . '/core/public-controller.php';
        require_once __DIR__ . '/core/public-runtime.php';
        require_once __DIR__ . '/core/skin-view-contract.php';
        require_once __DIR__ . '/core/skin-render-helpers.php';
        require_once __DIR__ . '/core/trusted-html.php';
        require_once __DIR__ . '/core/asset-registry.php';
        require_once __DIR__ . '/core/skin-presentation.php';
        require_once __DIR__ . '/core/custom-code-policy.php';

        $_c_response = snapsmack_public_runtime($pdo, [
            'view' => 'collections',
            'sort' => $_GET['sort'] ?? '',
            'route_aliases' => $skin_view['route_aliases'] ?? [],
        ], $settings);

        // The controller builds the menu every other strict page shows. An
        // earlier version replaced it with $skin_view['navigation'] here and
        // the page came back with three items where /albums has six --
        // Blogroll, Flkr Fckr and About all gone. Only fill it in if the
        // controller gave nothing.
        if (empty($_c_response['navigation']) && !empty($skin_view['navigation'])
                && is_array($skin_view['navigation'])) {
            $_c_response['navigation'] = $skin_view['navigation'];
        }

        $_c_layout = __DIR__ . '/skins/' . $active_skin . '/layout.php';
        if ((int)($_c_response['status'] ?? 0) === 200 && is_file($_c_layout)) {
            $view = snapsmack_build_skin_view($_c_response, snapsmack_public_presentation(
                $pdo, $settings, (string)$active_skin, $_collections_manifest,
                $_c_response['navigation'] ?? [],
                snapsmack_skin_declared_assets($_collections_manifest)
            ));
            if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
            include $_c_layout;
            exit;
        }
    } catch (Throwable $_c_err) {
        // Fall through to the legacy page. Logged, not shown.
        if (function_exists('error_log')) {
            error_log('collections.php skin render failed: ' . $_c_err->getMessage());
        }
    }
}

// Sort resolution: URL → cookie → admin setting → 'manual'.
$valid_sorts = ['manual', 'alphabetical', 'newest', 'oldest'];
$sort = $_GET['sort'] ?? '';
if (!in_array($sort, $valid_sorts, true)) {
    $sort = $_COOKIE['smack_collections_sort'] ?? '';
}
if (!in_array($sort, $valid_sorts, true)) {
    $sort = $settings['collections_default_sort'] ?? 'manual';
}
if (!in_array($sort, $valid_sorts, true)) $sort = 'manual';

// Persist sort choice to cookie when explicit URL param.
if (isset($_GET['sort']) && in_array($_GET['sort'], $valid_sorts, true)) {
    setcookie('smack_collections_sort', $sort, [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'samesite' => 'Lax',
    ]);
}

$order_by = match ($sort) {
    'alphabetical' => 'c.title ASC',
    'newest'       => 'c.created_at DESC',
    'oldest'       => 'c.created_at ASC',
    default        => 'c.sort_order ASC, c.id ASC',
};

// Rows per layout — admin setting (1–5).
$rows_per_layout = max(1, min(5, (int)($settings['collections_index_rows'] ?? 3)));

// Fetch collections + member count + featured image source.
// snap_collection_items is polymorphic (item_type/item_id/sort_order) since the
// collection-items migration — the old subquery referenced ci2.image_id /
// ci2.position, columns that no longer exist, which threw an uncaught PDO
// exception and 500'd this page. The first_thumb fallback now resolves a
// post-type member's first published image; non-post members fall back to the
// explicit cover (featured_thumb) or an empty tile.
$query = "
    SELECT c.id, c.title, c.slug, c.description, c.cover_image_id, c.sort_order, c.created_at,
           (SELECT COUNT(*) FROM snap_collection_items WHERE collection_id = c.id) AS member_count,
           (SELECT i.img_thumb_square FROM snap_images i WHERE i.id = c.cover_image_id LIMIT 1) AS featured_thumb,
           (SELECT i.img_thumb_square
              FROM snap_collection_items ci2
              INNER JOIN snap_post_images pim ON pim.post_id = ci2.item_id
              INNER JOIN snap_images i ON i.id = pim.image_id AND i.img_status = 'published'
              WHERE ci2.collection_id = c.id AND ci2.item_type = 'post'
              ORDER BY ci2.sort_order ASC, pim.sort_position ASC
              LIMIT 1) AS first_thumb,
           (SELECT i.img_thumb_square
              FROM snap_collection_items ci3
              INNER JOIN snap_images i ON i.id = ci3.item_id AND i.img_status = 'published'
              WHERE ci3.collection_id = c.id AND ci3.item_type = 'image'
              ORDER BY ci3.sort_order ASC
              LIMIT 1) AS first_thumb_img
    FROM snap_collections c
    WHERE c.published = 1
    ORDER BY {$order_by}
";
try {
    $collections = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fail soft — show the empty state rather than a white-screen 500 — but LOG
    // the real cause. A swallowed schema/query error was silently masquerading
    // as "No collections published yet", making it near-impossible to diagnose.
    error_log('SnapSmack collections.php — collection query failed: ' . $e->getMessage());
    $collections = [];
}

$page_title = 'Collections';
$skin_path  = 'skins/' . $active_skin;

if (file_exists(__DIR__ . '/' . $skin_path . '/skin-meta.php')) {
    include __DIR__ . '/' . $skin_path . '/skin-meta.php';
}

$sort_labels = [
    'manual'       => 'Manual',
    'alphabetical' => 'A → Z',
    'newest'       => 'Newest',
    'oldest'       => 'Oldest',
];

// Spell out member counts for the series list ("TWELVE IMAGES"), a nod to the
// Noah Grey Series Collections layout. Collections cap at 100; digits past 99.
if (!function_exists('snap_number_to_words')) {
    function snap_number_to_words(int $n): string {
        if ($n < 0) return (string)$n;
        $ones = ['zero','one','two','three','four','five','six','seven','eight','nine',
                 'ten','eleven','twelve','thirteen','fourteen','fifteen','sixteen',
                 'seventeen','eighteen','nineteen'];
        $tens = ['','','twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety'];
        if ($n < 20)  return $ones[$n];
        if ($n < 100) return $tens[intdiv($n, 10)] . (($n % 10) ? '-' . $ones[$n % 10] : '');
        return (string)$n;
    }
}
?>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/public-facing.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">

<body class="static-transmission is-collections">
    <div id="page-wrapper">
        <div id="scroll-stage">
            <?php
            $header_file = __DIR__ . '/' . $skin_path . '/skin-header.php';
            if (file_exists($header_file)) {
                include $header_file;
            } else {
                include __DIR__ . '/core/header.php';
            }
            ?>

            <div class="collections-canvas" data-rows="<?php echo $rows_per_layout; ?>">
                <h1 class="static-page-title">COLLECTIONS</h1>

                <div class="collections-sort-toggle" role="group" aria-label="Sort collections">
                    <?php foreach ($sort_labels as $sk => $sl):
                        $is_active = ($sort === $sk);
                        $url = 'collections.php?sort=' . urlencode($sk);
                    ?>
                        <a href="<?php echo $url; ?>"
                           class="alt-btn<?php echo $is_active ? ' alt-btn--active' : ''; ?>"
                           data-sort="<?php echo $sk; ?>">
                            <?php echo htmlspecialchars($sl); ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($collections)): ?>
                    <p class="dim">No collections published yet.</p>
                <?php else: ?>
                    <div class="collections-series">
                        <?php foreach ($collections as $c):
                            $thumb = $c['featured_thumb'] ?: ($c['first_thumb'] ?: ($c['first_thumb_img'] ?? null));
                            $href  = BASE_URL . 'collection.php?slug=' . urlencode($c['slug']);
                            $count = (int)$c['member_count'];
                            $count_words = strtoupper(snap_number_to_words($count));
                            $posted = !empty($c['created_at'])
                                ? strtoupper(date('j F Y', strtotime($c['created_at'])))
                                : '';
                        ?>
                            <a class="series-row" href="<?php echo $href; ?>">
                                <div class="series-thumb">
                                    <?php if ($thumb): ?>
                                        <img src="<?php echo BASE_URL . htmlspecialchars(ltrim($thumb, '/')); ?>"
                                             alt="<?php echo htmlspecialchars($c['title']); ?>"
                                             loading="lazy">
                                    <?php else: ?>
                                        <div class="series-thumb-empty"></div>
                                    <?php endif; ?>
                                </div>
                                <div class="series-panel">
                                    <span class="series-title"><?php echo htmlspecialchars($c['title']); ?></span>
                                    <span class="series-count"><?php echo $count_words; ?> <?php echo $count === 1 ? 'IMAGE' : 'IMAGES'; ?></span>
                                    <?php if ($posted): ?>
                                        <span class="series-posted">POSTED <?php echo $posted; ?></span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php
            $footer_file = __DIR__ . '/' . $skin_path . '/skin-footer.php';
            if (file_exists($footer_file)) {
                include $footer_file;
            } else {
                include __DIR__ . '/core/footer.php';
            }
            ?>
        </div>
    </div>
</body>
</html>
<?php // ===== SNAPSMACK EOF =====
