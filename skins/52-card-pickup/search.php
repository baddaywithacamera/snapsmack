<?php
/**
 * SNAPSMACK - 52 Card Pickup Search Results
 *
 * 52 Card Pickup is a SMACKONEOUT (solo / photoblog) skin whose landing is the
 * Organized Mayhem tabletop. The search page is a normal content page, modelled
 * on the solo page pattern (cf. 50 Shades of Noah Grey): the skin's own header
 * nav + a content container + footer, with the shared results partial inside.
 * Ghost chrome is forced off so the nav is visible here.
 *
 * $search_q is set by index.php's ?q= route. skin-meta (the <head>) is emitted
 * by that route before this template, matching how landing.php is rendered.
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

// Search is a conventional page — show the standard nav, not the tabletop's
// edge-reveal ghost chrome.
$om_ghost_chrome = false;
?>

<?php include('skin-header.php'); ?>

<div class="pickup-search-page">
    <?php include dirname(__DIR__, 2) . '/core/gram-search-results.php'; ?>
</div>

<?php include('skin-footer.php'); ?>
<?php // ===== SNAPSMACK EOF =====
