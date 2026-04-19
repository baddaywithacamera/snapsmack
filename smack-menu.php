<?php
/**
 * SNAPSMACK - Menu Manager
 *
 * Drag-and-drop navigation menu builder. Replaces the slot-based nav
 * toggles scattered across smack-settings.php with a single visual editor.
 * Stores the menu as a JSON array in snap_settings under the key
 * nav_menu_json. core/header.php reads this JSON to render the public nav.
 *
 * One level of nesting supported. Child items inherit the same item types
 * as top-level items (pages, custom links, built-ins).
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
                    </div>

                    <!-- ARROW -->
                    <div class="menu-builder-arrow">&#8594;</div>

                    <!-- CURRENT MENU -->
                    <div class="menu-current-panel">
                        <div class="menu-panel-title">Current Menu</div>
                        <div id="menu-list" class="menu-list">
                            <!-- populated by JS -->
                        </div>
                        <p class="menu-hint">Drag items to reorder. Drop an item onto another to nest it (one level deep).</p>
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
.menu-builder-layout {
    display: flex;
    gap: 20px;
    align-items: flex-start;
}
.menu-pool-panel {
    width: 240px;
    flex-shrink: 0;
}
.menu-current-panel {
    flex: 1;
    min-width: 300px;
}
.menu-panel-title {
    font-weight: 600;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 10px;
    color: var(--accent);
}
.menu-pool-section {
    margin-bottom: 16px;
}
.menu-pool-section-label {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--text-dim);
    margin-bottom: 6px;
}
.menu-pool-list {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-height: 30px;
}
.menu-pool-item {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--bg-secondary);
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 6px 10px;
    font-size: 0.78rem;
    cursor: grab;
    user-select: none;
    transition: background 0.1s;
}
.menu-pool-item:hover {
    background: var(--bg-hover, var(--bg-secondary));
    border-color: var(--accent);
}
.menu-pool-item .pool-add-btn {
    margin-left: auto;
    background: none;
    border: none;
    color: var(--accent);
    cursor: pointer;
    font-size: 1rem;
    line-height: 1;
    padding: 0 2px;
}
.menu-pool-item .pool-add-btn:hover {
    color: var(--accent-hover, var(--accent));
}
.menu-custom-link-form {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.menu-custom-link-form input {
    width: 100%;
    box-sizing: border-box;
}
.menu-builder-arrow {
    font-size: 1.6rem;
    color: var(--text-dim);
    align-self: center;
    flex-shrink: 0;
}
/* ── MENU LIST ── */
.menu-list {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-height: 60px;
    padding: 6px;
    background: var(--bg-secondary);
    border: 1px dashed var(--border);
    border-radius: 4px;
}
.menu-list.drag-over {
    border-color: var(--accent);
    background: var(--bg-hover, var(--bg-secondary));
}
.menu-item-row {
    border-radius: 4px;
    overflow: visible;
}
.menu-item-main {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--bg-primary);
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 7px 10px;
    cursor: grab;
    user-select: none;
    font-size: 0.8rem;
}
.menu-item-main.dragging {
    opacity: 0.4;
}
.menu-item-main.drop-target-above {
    border-top: 2px solid var(--accent);
}
.menu-item-main.drop-target-nest {
    border: 2px solid var(--accent);
    background: var(--bg-hover, var(--bg-secondary));
}
.menu-item-drag-handle {
    color: var(--text-dim);
    cursor: grab;
    font-size: 0.9rem;
}
.menu-item-label {
    flex: 1;
    font-weight: 500;
}
.menu-item-type-badge {
    font-size: 0.65rem;
    color: var(--text-dim);
    background: var(--bg-secondary);
    border: 1px solid var(--border);
    border-radius: 3px;
    padding: 1px 5px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.menu-item-actions {
    display: flex;
    gap: 4px;
}
.menu-item-actions button {
    background: none;
    border: none;
    color: var(--text-dim);
    cursor: pointer;
    padding: 2px 4px;
    font-size: 0.85rem;
    border-radius: 3px;
    line-height: 1;
}
.menu-item-actions button:hover {
    color: var(--accent);
    background: var(--bg-secondary);
}
.menu-item-actions .btn-remove:hover {
    color: #e05252;
}
/* Children sublist */
.menu-children-list {
    display: flex;
    flex-direction: column;
    gap: 3px;
    margin-top: 4px;
    margin-left: 28px;
    padding: 4px 6px;
    background: var(--bg-secondary);
    border: 1px dashed var(--border);
    border-radius: 4px;
    min-height: 32px;
}
.menu-children-list.drag-over {
    border-color: var(--accent);
}
.menu-children-list .menu-item-main {
    font-size: 0.76rem;
    padding: 5px 8px;
}
.menu-empty-hint {
    font-size: 0.72rem;
    color: var(--text-dim);
    text-align: center;
    padding: 6px;
    pointer-events: none;
}
.menu-hint {
    font-size: 0.75rem;
    color: var(--text-dim);
    margin-top: 8px;
}
/* Custom label editing */
.menu-item-label-edit {
    font-size: 0.78rem;
    padding: 2px 6px;
    border: 1px solid var(--accent);
    border-radius: 3px;
    background: var(--bg-primary);
    color: inherit;
    flex: 1;
}
</style>

<script>
/* ── DATA PASSED FROM PHP ─────────────────────────────────────────────── */
const SS_BUILTIN_ITEMS = <?php echo json_encode($builtin_items, JSON_UNESCAPED_UNICODE); ?>;
const SS_PAGE_ITEMS    = <?php echo json_encode($page_items,    JSON_UNESCAPED_UNICODE); ?>;
const SS_CURRENT_MENU  = <?php echo json_encode($current_menu,  JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="assets/js/ss-engine-menu-builder.js?v=<?php echo SNAPSMACK_VERSION; ?>"></script>

<?php include 'core/admin-footer.php'; ?>
