<?php
/**
 * SNAPSMACK - Menu Manager
 *
 * Drag-and-drop navigation menu builder. Replaces the slot-based nav
 * toggles scattered across smack-settings.php with a single visual editor.
 * Stores the menu as a JSON array in snap_settings under the key
 * nav_menu_json. core/header.php reads this JSON to render the public nav.
 *
 * Three levels of nesting (root → child → grandchild). Item types: custom,
 * external, container, page, album, category, collection, and built-ins.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once 'core/auth.php';

// ── SETTINGS WE MANAGE ────────────────────────────────────────────────────
// Dropdown appearance settings stored as flat keys in snap_settings.
$dropdown_keys = [
    'nav_dropdown_bg'       => '#000000',
    'nav_dropdown_opacity'  => '88',
    'nav_dropdown_text'     => '#ffffff',
];

// ── POST HANDLER ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_nav_menu'])) {

    // 1. Validate and save the menu JSON
    $raw_json = trim($_POST['menu_json'] ?? '');
    $decoded  = @json_decode($raw_json, true);

    if ($decoded !== null && is_array($decoded)) {
        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val)
                               VALUES ('nav_menu_json', ?)
                               ON DUPLICATE KEY UPDATE setting_val = ?");
        $stmt->execute([$raw_json, $raw_json]);
    }

    // 2. Save dropdown appearance settings
    $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val)
                           VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE setting_val = ?");
    foreach ($dropdown_keys as $key => $_default) {
        $val = htmlspecialchars_decode($_POST[$key] ?? $_default);
        $stmt->execute([$key, $val, $val]);
    }

    header("Location: smack-menu.php?msg=SAVED");
    exit;
}

// ── LOAD CURRENT SETTINGS ─────────────────────────────────────────────────
$dd_bg      = $settings['nav_dropdown_bg']      ?? '#000000';
$dd_opacity = (int)($settings['nav_dropdown_opacity'] ?? 88);
$dd_text    = $settings['nav_dropdown_text']    ?? '#ffffff';

// ── LOAD CURRENT MENU JSON ────────────────────────────────────────────────
$menu_json_raw = $settings['nav_menu_json'] ?? '';
$current_menu  = [];

if (!empty($menu_json_raw)) {
    $decoded = @json_decode($menu_json_raw, true);
    if (is_array($decoded)) {
        $current_menu = $decoded;
    }
}

// ── AUTO-BUILD DEFAULT MENU FROM LEGACY SETTINGS ─────────────────────────
// On first load (empty menu_json) we build a sensible default from the
// existing flat nav settings so nothing disappears on upgrade.
if (empty($current_menu)) {
    $homepage_mode = $settings['homepage_mode'] ?? 'latest_post';

    $current_menu[] = ['id' => 'home', 'type' => 'home', 'label' => 'HOME', 'children' => []];

    if ($homepage_mode === 'static_page') {
        $current_menu[] = ['id' => 'blog', 'type' => 'blog', 'label' => 'BLOG', 'children' => []];
    }

    $archive_layout = $settings['archive_layout'] ?? 'square';
    if ($archive_layout !== 'none') {
        $current_menu[] = ['id' => 'archive', 'type' => 'archive', 'label' => 'ARCHIVE VIEW', 'children' => []];
    }

    if (($settings['albums_link_enabled'] ?? '0') === '1') {
        $current_menu[] = ['id' => 'albums', 'type' => 'albums', 'label' => 'ALBUMS', 'children' => []];
    }

    if (($settings['show_wall_link'] ?? '0') === '1') {
        $current_menu[] = ['id' => 'wall', 'type' => 'wall', 'label' => 'FLOATING GALLERY', 'children' => []];
    }

    if (($settings['blogroll_enabled'] ?? '1') == '1') {
        $current_menu[] = ['id' => 'blogroll', 'type' => 'blogroll', 'label' => 'BLOGROLL', 'children' => []];
    }

    // Load static pages
    try {
        $pg_rows = $pdo->query("SELECT id, title, slug FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($pg_rows as $pg) {
            $current_menu[] = [
                'id'       => 'page_' . $pg['slug'],
                'type'     => 'page',
                'label'    => strtoupper($pg['title']),
                'slug'     => $pg['slug'],
                'children' => [],
            ];
        }
    } catch (PDOException $e) {}
}

// ── LOAD ALL PAGES FOR AVAILABLE POOL ─────────────────────────────────────
$all_pages = [];
try {
    $all_pages = $pdo->query("SELECT id, title, slug FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// ── BUILT-IN ITEM DEFINITIONS FOR JS POOL ─────────────────────────────────
$homepage_mode = $settings['homepage_mode'] ?? 'latest_post';

$builtin_items = [
    ['id' => 'home',     'type' => 'home',     'label' => 'HOME'],
    ['id' => 'archive',  'type' => 'archive',  'label' => 'ARCHIVE VIEW'],
    ['id' => 'albums',   'type' => 'albums',   'label' => 'ALBUMS'],
    ['id' => 'wall',     'type' => 'wall',     'label' => 'FLOATING GALLERY'],
    ['id' => 'blogroll', 'type' => 'blogroll', 'label' => 'BLOGROLL'],
];
if ($homepage_mode === 'static_page') {
    array_splice($builtin_items, 1, 0, [
        ['id' => 'blog', 'type' => 'blog', 'label' => 'BLOG'],
    ]);
}

$page_items = [];
foreach ($all_pages as $pg) {
    $page_items[] = [
        'id'   => 'page_' . $pg['slug'],
        'type' => 'page',
        'label' => strtoupper($pg['title']),
        'slug' => $pg['slug'],
    ];
}

// ── ALBUMS, CATEGORIES, COLLECTIONS POOLS ─────────────────────────────────
// Collections: only those with published=1 surface in the public-nav pool.
// Hidden collections still appear in the admin elsewhere but cannot be added
// to the public navigation (they would dead-end visitors at /collection.php).
$album_items = [];
$category_items = [];
$collection_items = [];
try {
    foreach ($pdo->query("SELECT id, album_name FROM snap_albums ORDER BY album_name ASC")->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $album_items[] = ['id' => 'album_' . $a['id'], 'type' => 'album', 'label' => strtoupper($a['album_name']), 'ref_id' => (int)$a['id']];
    }
    foreach ($pdo->query("SELECT id, cat_name FROM snap_categories ORDER BY cat_name ASC")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $category_items[] = ['id' => 'cat_' . $c['id'], 'type' => 'category', 'label' => strtoupper($c['cat_name']), 'ref_id' => (int)$c['id']];
    }
    foreach ($pdo->query("SELECT id, title, slug FROM snap_collections WHERE published = 1 ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $collection_items[] = ['id' => 'coll_' . $col['id'], 'type' => 'collection', 'label' => strtoupper(\$col['title']), 'ref_id' => (int)$col['id'], 'slug' => $col['slug']];
    }
} catch (PDOException $e) {
    // Tables may be missing on first load before migrations run. Pools stay empty.
}

// ── RENDER ────────────────────────────────────────────────────────────────
$page_title = "Menu Manager";
$current_page = 'smack-menu.php';
$sc_active_nav = 'pimp';
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>MENU MANAGER</h2>
    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'SAVED'): ?>
        <div class="alert alert-success">&gt; MENU SAVED</div>
    <?php endif; ?>

    <form method="post" id="menu-form">
        <input type="hidden" name="save_nav_menu" value="1">
        <input type="hidden" name="menu_json" id="menu_json_input" value="">

        <div class="box">
            <h3>NAVIGATION STRUCTURE</h3>
            <div class="menu-builder-layout">

                <!-- AVAILABLE ITEMS POOL -->
                <div class="menu-pool-panel">
                    <div class="menu-pool-section">
                        <div class="menu-pool-section-label">Built-in Pages</div>
                        <div id="pool-builtin" class="menu-pool-list">
                            <!-- populated by JS -->
                        </div>
                    </div>
                    <div class="menu-pool-section">
                        <div class="menu-pool-section-label">Static Pages</div>
                        <div id="pool-pages" class="menu-pool-list">
                            <!-- populated by JS -->
                        </div>
                    </div>
                    <div class="menu-pool-section">
                        <div class="menu-pool-section-label">Custom Link</div>
                        <div class="menu-custom-link-form">
                            <input type="text" id="custom-label" placeholder="Label" maxlength="60">
                            <input type="url"  id="custom-url"   placeholder="https://" maxlength="500">
                            <button type="button" id="add-custom-btn" class="btn-smack">Add</button>
                        </div>
                    </div>
                    <div class="menu-pool-section">
                        <div class="menu-pool-section-label">Container <span class="dim">(dropdown parent, no URL)</span></div>
                        <div class="menu-custom-link-form">
                            <input type="text" id="container-label" placeholder="Label e.g. WORKS" maxlength="60">
                            <button type="button" id="add-container-btn" class="btn-smack">Add Container</button>
                        </div>
                    </div>
                    <?php if (!empty($album_items)): ?>
                    <div class="menu-pool-section">
                        <div class="menu-pool-section-label">Albums</div>
                        <div id="pool-albums" class="menu-pool-list"></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($category_items)): ?>
                    <div class="menu-pool-section">
                        <div class="menu-pool-section-label">Categories</div>
                        <div id="pool-categories" class="menu-pool-list"></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($collection_items)): ?>
                    <div class="menu-pool-section">
                        <div class="menu-pool-section-label">Collections</div>
                        <div id="pool-collections" class="menu-pool-list"></div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- CURRENT MENU -->
                <div class="menu-current-panel">
                    <div id="menu-list" class="menu-list">
                        <!-- populated by JS -->
                    </div>
                    <p class="dim" style="margin-top:10px;font-size:0.8rem;">Drag to reorder. Drop onto another item to nest (up to 3 levels). Use the eye button to hide items without removing them.</p>
                </div>

            </div>
        </div>

        <!-- DROPDOWN APPEARANCE -->
        <div class="box">
            <h3>DROPDOWN APPEARANCE</h3>
            <div class="dash-grid">
                <div>
                    <label>Background colour</label>
                    <input type="color" name="nav_dropdown_bg" value="<?php echo htmlspecialchars($dd_bg); ?>">
                </div>
                <div>
                    <label>Background opacity <span class="dim">(0–100)</span></label>
                    <input type="number" name="nav_dropdown_opacity" value="<?php echo $dd_opacity; ?>" min="0" max="100" style="width:80px">
                </div>
                <div>
                    <label>Text colour</label>
                    <input type="color" name="nav_dropdown_text" value="<?php echo htmlspecialchars($dd_text); ?>">
                </div>
            </div>
        </div>

        <div class="form-action-row">
            <button type="submit" class="btn-smack" id="save-menu-btn">Save Menu</button>
        </div>
    </form>

</div><!-- .main -->

<style>
/* ── MENU BUILDER LAYOUT ─────────────────────────────────────────────────── */
/* Colors are transparent overlays — the .box background (set by admin theme  */
/* colour CSS) shows through, so this works on any admin theme automatically. */

.menu-builder-layout {
    display: flex;
    gap: 24px;
    align-items: flex-start;
}

/* ── POOL PANEL (left column) ── */
.menu-pool-panel {
    width: 220px;
    flex-shrink: 0;
    background: rgba(0,0,0,0.25);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 5px;
    padding: 14px;
}
.menu-pool-section {
    margin-bottom: 16px;
}
.menu-pool-section:last-child { margin-bottom: 0; }
.menu-pool-section-label {
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    opacity: 0.55;
    margin-bottom: 6px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    padding-bottom: 4px;
}
.menu-pool-list {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-height: 20px;
}
.menu-pool-item {
    display: flex;
    align-items: center;
    gap: 6px;
    background: rgba(255,255,255,0.07);
    border: 1px solid rgba(255,255,255,0.14);
    border-radius: 4px;
    padding: 6px 9px;
    font-size: 0.76rem;
    cursor: grab;
    user-select: none;
    transition: background 0.1s, border-color 0.1s;
}
.menu-pool-item:hover {
    background: rgba(255,255,255,0.14);
    border-color: rgba(255,255,255,0.3);
}
.menu-pool-item .pool-add-btn {
    margin-left: auto;
    background: none;
    border: none;
    color: inherit;
    opacity: 0.6;
    cursor: pointer;
    font-size: 1.1rem;
    line-height: 1;
    padding: 0 2px;
    flex-shrink: 0;
    transition: opacity 0.1s;
}
.menu-pool-item:hover .pool-add-btn { opacity: 1; }
.menu-custom-link-form {
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.menu-custom-link-form input {
    width: 100%;
    box-sizing: border-box;
    font-size: 0.76rem;
}
.menu-custom-link-form .btn-smack {
    font-size: 0.72rem;
    padding: 4px 10px;
}

/* ── CURRENT MENU PANEL (right column) ── */
.menu-current-panel { flex: 1; min-width: 340px; }

/* Drop canvas */
.menu-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-height: 80px;
    padding: 8px;
    background: rgba(0,0,0,0.3);
    border: 2px dashed rgba(255,255,255,0.2);
    border-radius: 5px;
}
.menu-list.drag-over {
    border-color: rgba(255,255,255,0.5);
    background: rgba(0,0,0,0.2);
}

/* ── TOP-LEVEL ITEM CARD ── */
.menu-item-row {
    border: 1px solid rgba(255,255,255,0.18);
    border-left: 4px solid rgba(255,255,255,0.5);
    border-radius: 4px;
    background: rgba(255,255,255,0.09);
    box-shadow: 0 2px 5px rgba(0,0,0,0.35);
}

/* ── ITEM BAR (the draggable strip) ── */
.menu-item-main {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 10px;
    cursor: grab;
    user-select: none;
    font-size: 0.82rem;
    background: rgba(255,255,255,0.06);
    border-radius: 3px 3px 0 0;
    transition: background 0.1s;
}
.menu-item-main:hover { background: rgba(255,255,255,0.12); }
.menu-item-main.dragging { opacity: 0.25; }
.menu-item-main.drop-target-above { box-shadow: 0 -3px 0 rgba(255,255,255,0.6); }

/* ── CHILDREN SUBLIST ── */
.menu-children-list {
    display: flex;
    flex-direction: column;
    gap: 5px;
    padding: 8px 8px 8px 30px;
    background: rgba(0,0,0,0.2);
    border-top: 1px solid rgba(255,255,255,0.08);
    min-height: 38px;
}
.menu-children-list.drag-over {
    outline: 2px dashed rgba(255,255,255,0.4);
    outline-offset: -3px;
}
.menu-children-list.empty-children {
    display: flex;
    align-items: center;
    min-height: 36px;
}

/* Child item bar */
.menu-item-main.depth-1 {
    font-size: 0.78rem;
    padding: 7px 10px;
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.14);
    border-radius: 4px;
}
.menu-item-main.depth-1:hover { background: rgba(255,255,255,0.14); }

/* ── GRANDCHILDREN ZONE ── */
.menu-grandchildren-list {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 5px;
    margin-left: 16px;
    padding: 6px 8px;
    background: rgba(0,0,0,0.2);
    border: 1px dashed rgba(255,255,255,0.18);
    border-radius: 4px;
    min-height: 34px;
}
.menu-grandchildren-list.drag-over {
    border-color: rgba(255,255,255,0.45);
    background: rgba(0,0,0,0.1);
}
.menu-item-main.depth-2 {
    font-size: 0.75rem;
    padding: 6px 10px;
    background: rgba(255,255,255,0.07);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 4px;
}
.menu-item-main.depth-2:hover { background: rgba(255,255,255,0.12); }

/* Empty drop hint */
.menu-empty-drop-hint {
    font-size: 0.67rem;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    opacity: 0.4;
    padding: 5px 8px;
    border: 1px dashed rgba(255,255,255,0.3);
    border-radius: 3px;
    pointer-events: none;
    width: 100%;
    text-align: center;
    box-sizing: border-box;
}

/* ── ITEM PART ELEMENTS ── */
.menu-item-position {
    font-size: 0.65rem;
    opacity: 0.4;
    min-width: 18px;
    text-align: right;
    flex-shrink: 0;
}
.menu-item-drag-handle {
    opacity: 0.4;
    cursor: grab;
    font-size: 1rem;
    flex-shrink: 0;
    transition: opacity 0.1s;
}
.menu-item-main:hover .menu-item-drag-handle { opacity: 0.8; }
.menu-item-label { flex: 1; font-weight: 600; letter-spacing: 0.3px; }
.menu-item-type-badge {
    font-size: 0.6rem;
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.3);
    border-radius: 3px;
    padding: 2px 7px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    flex-shrink: 0;
    font-weight: 700;
}
.menu-item-actions { display: flex; gap: 2px; }
.menu-item-actions button {
    background: none;
    border: none;
    color: inherit;
    opacity: 0.45;
    cursor: pointer;
    padding: 3px 6px;
    font-size: 0.9rem;
    border-radius: 3px;
    line-height: 1;
    transition: opacity 0.1s, background 0.1s;
}
.menu-item-actions button:hover { opacity: 1; background: rgba(255,255,255,0.08); }
.menu-item-actions .btn-remove:hover { color: #e05252; opacity: 1; }
.menu-child-row { margin-top: 3px; }

/* ── INACTIVE / ACTIVE TOGGLE ── */
.menu-item-inactive-badge {
    font-size: 0.6rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #e05252;
    border: 1px solid #e05252;
    border-radius: 3px;
    padding: 1px 5px;
    flex-shrink: 0;
    font-weight: 700;
}
.btn-toggle-active.btn-inactive { opacity: 0.3; }

/* ── INLINE LABEL EDIT ── */
.menu-item-label-edit {
    font-size: 0.8rem;
    padding: 3px 7px;
    border: 1px solid rgba(255,255,255,0.4);
    border-radius: 3px;
    background: rgba(0,0,0,0.3);
    color: inherit;
    flex: 1;
}
.menu-empty-hint {
    font-size: 0.78rem;
    opacity: 0.4;
    text-align: center;
    padding: 16px 6px;
    pointer-events: none;
}
</style>

<script>
/* ── DATA PASSED FROM PHP ─────────────────────────────────────────────── */
const SS_BUILTIN_ITEMS = <?php echo json_encode($builtin_items, JSON_UNESCAPED_UNICODE); ?>;
const SS_PAGE_ITEMS    = <?php echo json_encode($page_items,    JSON_UNESCAPED_UNICODE); ?>;
const SS_CURRENT_MENU  = <?php echo json_encode($current_menu,  JSON_UNESCAPED_UNICODE); ?>;
const SS_ALBUM_ITEMS      = <?php echo json_encode($album_items,      JSON_UNESCAPED_UNICODE); ?>;
const SS_CATEGORY_ITEMS   = <?php echo json_encode($category_items,   JSON_UNESCAPED_UNICODE); ?>;
const SS_COLLECTION_ITEMS = <?php echo json_encode($collection_items, JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="assets/js/ss-engine-menu-builder.js?v=<?php echo SNAPSMACK_VERSION; ?>"></script>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
