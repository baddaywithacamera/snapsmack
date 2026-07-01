<?php
/**
 * SNAPSMACK - PARADE Search Results
 *
 * Full-text search page for PARADE. $search_q is set by index.php's ?q=
 * route before inclusion. Renders inside the standard PARADE chrome (profile
 * header + pa-app) using the shared, CSS-var-themed results partial.
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

<div class="pa-content-wrap">

<?php include __DIR__ . '/skin-profile.php'; ?>

<div id="pa-app">
    <?php include dirname(__DIR__, 2) . '/core/gram-search-results.php'; ?>
</div><!-- /#pa-app -->

</div><!-- /.pa-content-wrap -->

<?php include('skin-footer.php'); ?>
<?php // ===== SNAPSMACK EOF =====
