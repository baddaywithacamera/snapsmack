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

// ── RENDER ────────────────────────────────────────────────────────────────
$page_title = "Menu Manager";
$current_page = 'smack-menu.php';
$sc_active_nav = 'pimp';
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="smack-main-content">
    <div class="smack-page-header">
        <h2>Menu Manager</h2>
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'SAVED'): ?>
            <div class="smack-flash smack-flash--ok">Menu saved.</div>
        <?php endif; ?>
    </div>

    <form method="post" id="menu-form">
        <input type="hidden" name="save_nav_menu" value="1">
        <input type="hidden" name="menu_json" id="menu_json_input" value="">

        <div class="smack-box">
            <div class="smack-box-header">
                <span class="smack-box-title">Navigation Structure</span>
            </div>
            <div class="smack-box-body">
                <div class="menu-builder-layout">

                    <!-- AVAILABLE ITEMS POOL -->
                    <div class="menu-pool-panel">
                        <div class="menu-panel-title">Available Items</div>
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
                                <button type="button" id="add-custom-btn" class="smack-btn smack-btn--sm">Add</button>
                            </div>
                        </div>
                        <div class="menu-pool-section">
                            <div class="menu-pool-section-label">Container <span class="smack-dim">(dropdown parent, no URL)</span></div>
                            <div class="menu-custom-link-form">
                                <input type="text" id="container-label" placeholder="Label e.g. WORKS" maxlength="60">
                                <button type="button" id="add-container-btn" class="smack-btn smack-btn--sm">Add Container</button>
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
                        <div class="menu-panel-title">Current Menu</div>
                        <div id="menu-list" class="menu-list">
                            <!-- populated by JS -->
                        </div>
                        <p class="menu-hint">Drag to reorder. Drop onto another item to nest (up to 3 levels). Use the eye button to hide items without removing them.</p>
                    </div>

                </div>
            </div>
        </div>

        <!-- DROPDOWN APPEARANCE -->
        <div class="smack-box">
            <div class="smack-box-header">
                <span class="smack-box-title">Dropdown Appearance</span>
            </div>
            <div class="smack-box-body">
                <table class="smack-settings-table">
                    <tr>
                        <td class="smack-setting-label">Background colour</td>
                        <td>
                            <input type="color" name="nav_dropdown_bg" value="<?php echo htmlspecialchars($dd_bg); ?>">
                        </td>
                    </tr>
                    <tr>
                        <td class="smack-setting-label">Background opacity <span class="smack-dim">(0–100)</span></td>
                        <td>
                            <input type="number" name="nav_dropdown_opacity" value="<?php echo $dd_opacity; ?>" min="0" max="100" style="width:70px">
                        </td>
                    </tr>
                    <tr>
                        <td class="smack-setting-label">Text colour</td>
                        <td>
                            <input type="color" name="nav_dropdown_text" value="<?php echo htmlspecialchars($dd_text); ?>">
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="smack-form-actions">
            <button type="submit" class="smack-btn smack-btn--primary" id="save-menu-btn">Save Menu</button>
        </div>
    </form>

</div><!-- .smack-main-content -->

<style>
/* ── LAYOUT ── */
.menu-builder-layout {
    display: flex;
    gap: 24px;
    align-items: flex-start;
}
.menu-pool-panel {
    width: 220px;
    flex-shrink: 0;
    background: var(--bg-secondary);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 14px;
}
.menu-current-panel {
    flex: 1;
    min-width: 300px;
}
.menu-panel-title {
    font-weight: 700;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    margin-bottom: 12px;
    color: var(--accent);
}
.menu-pool-section {
    margin-bottom: 14px;
}
.menu-pool-section:last-child { margin-bottom: 0; }
.menu-pool-section-label {
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--text-dim);
    margin-bottom: 5px;
    border-bottom: 1px solid var(--border);
    padding-bottom: 3px;
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
    background: var(--bg-primary);
    border: 1px solid var(--border);
    border-radius: 3px;
    padding: 5px 8px;
    font-size: 0.76rem;
    cursor: grab;
    user-select: none;
    transition: border-color 0.1s;
}
.menu-pool-item:hover { border-color: var(--accent); }
.menu-pool-item .pool-add-btn {
    margin-left: auto;
    background: none;
    border: none;
    color: var(--accent);
    cursor: pointer;
    font-size: 1rem;
    line-height: 1;
    padding: 0 2px;
    flex-shrink: 0;
}
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

/* ── MENU LIST ── */
.menu-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-height: 60px;
    padding: 6px;
    background: var(--bg-secondary);
    border: 2px dashed var(--border);
    border-radius: 6px;
}
.menu-list.drag-over {
    border-color: var(--accent);
    background: rgba(255,255,255,0.02);
}

/* ── TOP-LEVEL ROW — the card wrapping item + children ── */
.menu-item-row {
    border: 1px solid var(--border);
    border-left: 3px solid var(--accent);
    border-radius: 4px;
    padding: 6px 6px 6px 8px;
    background: var(--bg-primary);
}
.menu-item-row:hover { border-left-color: var(--accent); }

/* ── ITEM BAR ── */
.menu-item-main {
    display: flex;
    align-items: center;
    gap: 8px;
    border-radius: 3px;
    padding: 6px 8px;
    cursor: grab;
    user-select: none;
    font-size: 0.8rem;
    background: transparent;
}
.menu-item-main.dragging { opacity: 0.35; }
.menu-item-main.drop-target-above { box-shadow: 0 -2px 0 var(--accent); }

/* ── CHILDREN sublist ── */
.menu-children-list {
    display: flex;
    flex-direction: column;
    gap: 3px;
    margin-top: 4px;
    margin-left: 22px;
    padding: 3px 6px;
    border-left: 2px solid var(--border);
    border-radius: 0 3px 3px 0;
    min-height: 10px;
}
.menu-children-list.has-children {
    padding: 5px 6px;
    background: rgba(255,255,255,0.02);
}
.menu-children-list.drag-over {
    border-left-color: var(--accent);
    background: rgba(255,255,255,0.03);
}
/* Child items: slightly dimmer/smaller */
.menu-children-list .menu-item-main {
    font-size: 0.76rem;
    padding: 4px 8px;
    background: var(--bg-secondary);
    border-radius: 3px;
}
/* The thin "drop here" hint when no children */
.menu-children-list.empty-children {
    min-height: 10px;
    padding: 0 6px;
}
.menu-empty-drop-hint {
    font-size: 0.65rem;
    color: var(--text-dim);
    opacity: 0.5;
    padding: 3px 0;
    pointer-events: none;
}

/* ── ITEM PARTS ── */
.menu-item-position {
    font-size: 0.65rem;
    color: var(--text-dim);
    min-width: 16px;
    text-align: right;
    flex-shrink: 0;
}
.menu-item-drag-handle {
    color: var(--text-dim);
    cursor: grab;
    font-size: 0.85rem;
    flex-shrink: 0;
}
.menu-item-label { flex: 1; font-weight: 600; }
.menu-item-type-badge {
    font-size: 0.62rem;
    color: var(--text-dim);
    background: var(--bg-secondary);
    border: 1px solid var(--border);
    border-radius: 3px;
    padding: 1px 5px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    flex-shrink: 0;
}
.menu-item-actions { display: flex; gap: 2px; }
.menu-item-actions button {
    background: none;
    border: none;
    color: var(--text-dim);
    cursor: pointer;
    padding: 2px 5px;
    font-size: 0.85rem;
    border-radius: 3px;
    line-height: 1;
}
.menu-item-actions button:hover { color: var(--accent); background: var(--bg-secondary); }
.menu-item-actions .btn-remove:hover { color: #e05252; }

.menu-child-row {
    margin-top: 2px;
}

/* Grandchildren zone */
.menu-grandchildren-list {
    display: flex;
    flex-direction: column;
    gap: 2px;
    margin-top: 3px;
    margin-left: 44px;
    padding: 2px 6px;
    border-left: 2px dashed var(--border);
    min-height: 8px;
}
.menu-grandchildren-list.has-children {
    padding: 4px 6px;
    background: rgba(255,255,255,0.01);
}
.menu-grandchildren-list.drag-over {
    border-left-color: var(--accent);
    background: rgba(255,255,255,0.03);
}

/* Depth-based item bar sizing */
.menu-item-main.depth-1 { font-size: 0.76rem; padding: 4px 8px; background: var(--bg-secondary); border-radius: 3px; }
.menu-item-main.depth-2 { font-size: 0.72rem; padding: 3px 8px; background: var(--bg-secondary); border-radius: 3px; opacity: 0.9; }

/* Inactive badge */
.menu-item-inactive-badge {
    font-size: 0.6rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #e05252;
    border: 1px solid #e05252;
    border-radius: 3px;
    padding: 1px 4px;
    flex-shrink: 0;
}

/* Active toggle dimmed state */
.btn-toggle-active.btn-inactive { opacity: 0.3; }

.menu-hint {
    font-size: 0.72rem;
    color: var(--text-dim);
    margin-top: 8px;
    opacity: 0.7;
}
.menu-item-label-edit {
    font-size: 0.78rem;
    padding: 2px 6px;
    border: 1px solid var(--accent);
    border-radius: 3px;
    background: var(--bg-primary);
    color: inherit;
    flex: 1;
}
.menu-empty-hint {
    font-size: 0.76rem;
    color: var(--text-dim);
    text-align: center;
    padding: 12px 6px;
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
