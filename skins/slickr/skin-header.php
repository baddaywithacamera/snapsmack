<?php
/**
 * SNAPSMACK - Slickr shared masthead (Flickr profile idiom)
 *
 * Self-contained cover masthead used by EVERY Slickr page (landing, albums,
 * collections, archive, static). Computes its own profile data from
 * $settings / $pdo so any page that includes it renders the identical
 * treatment: full-bleed cover image, bottom-heavy dark gradient, and the
 * avatar / name / tagline / stats overlaid in the gradient — then the tab bar
 * below on white.
 *
 * Set $sl_active_tab before include to force the highlighted tab
 * ('photostream' | 'albums' | 'collections' | '<page-slug>'); otherwise it is
 * auto-detected from the script name.
 *
 * @author Sean McCormick
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$sl_now = date('Y-m-d H:i:s');

// ── Active tab (auto-detect from script name unless caller set it) ─────────
if (!isset($sl_active_tab)) {
    $_sn = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($_sn === 'albums.php')           $sl_active_tab = 'albums';
    elseif ($_sn === 'collections.php')  $sl_active_tab = 'collections';
    else                                 $sl_active_tab = 'photostream';
}

// ── Static pages for the tab nav ───────────────────────────────────────────
try {
    $sl_nav_pages = $pdo->query(
        "SELECT title, slug FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $sl_nav_pages = [];
}

// ── Profile fields ─────────────────────────────────────────────────────────
$sl_site = $settings['site_name'] ?? 'SnapSmack';
// Decode any double-encoded entities (Flickr import / hub sync), strip a
// leading separator pipe, then re-escape once on output.
$sl_tag  = (string)($settings['site_tagline'] ?? '');
$_prev = null;
while ($sl_tag !== $_prev) { $_prev = $sl_tag; $sl_tag = html_entity_decode($sl_tag, ENT_QUOTES, 'UTF-8'); }
$sl_tag = preg_replace('/^\s*\|\s*/', '', trim($sl_tag));
$sl_loc  = trim($settings['slickr_location']   ?? '');
$sl_est  = trim($settings['slickr_established'] ?? '');

// ── Photo count ────────────────────────────────────────────────────────────
$_pc = $pdo->prepare("SELECT COUNT(*) FROM snap_images WHERE img_status = 'published' AND img_date <= ?");
$_pc->execute([$sl_now]);
$sl_count = (int)$_pc->fetchColumn();

// ── Avatar (same source as The Grid) ───────────────────────────────────────
$_av           = $settings['skin_avatar'] ?? '';
$sl_av_exists  = $_av && file_exists(dirname(__DIR__, 2) . '/' . $_av);
$sl_av_url     = $sl_av_exists ? BASE_URL . htmlspecialchars($_av) : '';
$sl_av_init    = strtoupper(substr($sl_site, 0, 1));

// ── Cover image: explicit setting → newest landscape fallback ──────────────
$sl_cover = '';
$_cid = (int)($settings['slickr_cover_image_id'] ?? 0);
if ($_cid > 0) {
    $q = $pdo->prepare("SELECT img_file FROM snap_images WHERE id = ? AND img_status = 'published'");
    $q->execute([$_cid]);
    $f = $q->fetchColumn();
    if ($f) $sl_cover = BASE_URL . ltrim($f, '/');
}
if ($sl_cover === '') {
    $q = $pdo->prepare(
        "SELECT img_file FROM snap_images
         WHERE img_status = 'published' AND img_width > img_height AND img_date <= ?
         ORDER BY img_date DESC LIMIT 1"
    );
    $q->execute([$sl_now]);
    $f = $q->fetchColumn();
    if ($f) $sl_cover = BASE_URL . ltrim($f, '/');
}

// ── Cover framing (admin-set in smack-masthead.php) ────────────────────────
// Pan (object-position %) + zoom (scale multiplier). Defaults = centred, 1×.
$sl_cpx   = max(0, min(100, (int)($settings['slickr_cover_pos_x'] ?? 50)));
$sl_cpy   = max(0, min(100, (int)($settings['slickr_cover_pos_y'] ?? 50)));
$sl_czoom = max(100, min(300, (int)($settings['slickr_cover_zoom'] ?? 100))) / 100;
?>
<header class="sl-masthead">
    <!-- Cover image + bottom-heavy gradient, profile overlaid in it -->
    <div class="sl-cover"<?php if ($sl_cover): ?> style="--sl-cover-pos:<?php echo $sl_cpx; ?>% <?php echo $sl_cpy; ?>%; --sl-cover-zoom:<?php echo $sl_czoom; ?>;"<?php endif; ?>>
        <?php if ($sl_cover): ?><img class="sl-cover-img" src="<?php echo htmlspecialchars($sl_cover, ENT_QUOTES); ?>" alt="" aria-hidden="true"><?php endif; ?>
        <div class="sl-cover-scrim" aria-hidden="true"></div>
        <div class="sl-cover-profile">
            <div class="sl-profile-inner">
                <div class="sl-profile-avatar<?php echo $sl_av_exists ? ' sl-profile-avatar--zoom' : ''; ?>"
                     <?php if ($sl_av_exists): ?>role="button" tabindex="0" aria-label="View profile photo" data-sl-lightbox="<?php echo $sl_av_url; ?>"<?php endif; ?>>
                    <?php if ($sl_av_exists): ?>
                        <img src="<?php echo $sl_av_url; ?>" alt="<?php echo htmlspecialchars($sl_site); ?>">
                    <?php else: ?>
                        <span class="sl-profile-avatar-initials"><?php echo htmlspecialchars($sl_av_init); ?></span>
                    <?php endif; ?>
                </div>
                <div class="sl-profile-info">
                    <h1 class="sl-profile-name"><?php echo htmlspecialchars($sl_site); ?></h1>
                    <?php if ($sl_tag !== ''): ?>
                        <p class="sl-profile-tagline"><?php echo htmlspecialchars($sl_tag); ?></p>
                    <?php endif; ?>
                </div>
                <div class="sl-profile-stats">
                    <div class="sl-stat">
                        <strong><?php echo number_format($sl_count); ?></strong>
                        <span>Photo<?php echo $sl_count !== 1 ? 's' : ''; ?></span>
                    </div>
                    <?php if ($sl_loc !== ''): ?>
                        <div class="sl-stat-line"><?php echo htmlspecialchars($sl_loc); ?></div>
                    <?php endif; ?>
                    <?php if ($sl_est !== ''): ?>
                        <div class="sl-stat-line">Joined <?php echo htmlspecialchars($sl_est); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab / utility bar (below the cover, on white) -->
    <nav class="sl-profile-tabs">
        <div class="sl-tabs-inner">
            <div class="sl-tabs-left">
                <a href="<?php echo BASE_URL; ?>" class="sl-tab<?php echo $sl_active_tab === 'photostream' ? ' sl-tab--active' : ''; ?>">Photostream</a>
                <a href="<?php echo BASE_URL; ?>albums.php" class="sl-tab<?php echo $sl_active_tab === 'albums' ? ' sl-tab--active' : ''; ?>">Albums</a>
                <a href="<?php echo BASE_URL; ?>collections.php" class="sl-tab<?php echo $sl_active_tab === 'collections' ? ' sl-tab--active' : ''; ?>">Collections</a>
                <?php foreach ($sl_nav_pages as $np): ?>
                    <a href="<?php echo BASE_URL . htmlspecialchars($np['slug']); ?>" class="sl-tab<?php echo $sl_active_tab === $np['slug'] ? ' sl-tab--active' : ''; ?>"><?php echo htmlspecialchars($np['title']); ?></a>
                <?php endforeach; ?>
            </div>
            <div class="sl-tabs-right">
                <form class="sl-search" action="<?php echo BASE_URL; ?>archive.php" method="get" role="search">
                    <input type="search" name="search" class="sl-search-input" placeholder="Search photos" aria-label="Search photos">
                </form>
                <button type="button" class="sl-cal-toggle" id="sl-cal-toggle" data-calendar-toggle aria-label="Calendar filter" title="Calendar filter">
                    <span class="sl-cal-c">C</span>
                </button>
            </div>
        </div>
    </nav>
</header>
<?php // ===== SNAPSMACK EOF =====
