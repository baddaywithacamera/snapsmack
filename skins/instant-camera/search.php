<?php
/**
 * SNAPSMACK - INSTANT CAMERA Search Results
 *
 * Full-text search page for INSTANT CAMERA. $search_q is set by index.php's ?q=
 * route before inclusion. Renders inside the standard Grid chrome (profile
 * header + tg-app) using the shared, CSS-var-themed results partial. INSTANT
 * CAMERA keeps The Grid's tg- namespace, so this mirrors the-grid/search.php;
 * the fixed background layers (.ic-bg / .ic-scrim / .ic-panel) come in via
 * skin-profile.php, exactly as on every other INSTANT CAMERA page.
 *
 * Variables from index.php: $pdo, $settings, $site_name, $search_q. BASE_URL.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$search_q = isset($search_q) ? $search_q : trim((string)($_GET['q'] ?? ''));
?>

<?php include(__DIR__ . '/skin-meta.php'); ?>

<div class="tg-content-wrap">

<?php include __DIR__ . '/skin-profile.php'; ?>

<div id="tg-app">
    <?php include dirname(__DIR__, 2) . '/core/gram-search-results.php'; ?>
</div><!-- /#tg-app -->

</div><!-- /.tg-content-wrap -->

<?php include('skin-footer.php'); ?>
<?php // ===== SNAPSMACK EOF =====
