<?php
/**
 * 52 PICKUP — Tabletop Landing
 *
 * The landing IS the Organized Mayhem tabletop: an infinite, pannable scatter
 * of photo prints. Interaction — hover-lift, click-to-pick-up, ghost chrome,
 * ESC return — comes from the 52 PICKUP layer (ss-engine-52-pickup.js). Image
 * data is served by the shared core endpoint (core/mayhem-data.php) at
 * ?ajax=mayhem (cheap PK-range sampling, trigram-excluded, + server vitals).
 *
 * Variables available from index.php: $pdo, $settings, $site_name, BASE_URL.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// Shared Organized Mayhem data endpoint — emits ?ajax=mayhem JSON and exits.
require_once dirname(__DIR__, 2) . '/core/mayhem-data.php';

// Tabletop config — Organized Mayhem engine controls (set under Engines admin)
// with safe defaults if unset.
$om_count   = (int) ($settings['mayhem_initial_count'] ?? 120);
$om_maxw    = (int) ($settings['mayhem_max_width']     ?? 300);
$om_overlap = max(0.4, min(0.95, ((int) ($settings['mayhem_overlap_max'] ?? 85)) / 100));
$om_drift   = ($settings['mayhem_drift'] ?? '1') === '1' ? '1' : '0';
$om_warp    = ($settings['mayhem_warp']  ?? '1') === '1' ? '1' : '0';

// The landing renders GHOST CHROME — nav/footer stay hidden until the cursor
// reaches a screen edge. skin-header.php / skin-footer.php read this flag.
$om_ghost_chrome = true;
?>

<?php include('skin-header.php'); ?>

<div class="pickup-tabletop"
     data-mayhem
     data-api-url="<?php echo BASE_URL; ?>?ajax=mayhem"
     data-initial-count="<?php echo $om_count; ?>"
     data-max-width="<?php echo $om_maxw; ?>"
     data-overlap-max="<?php echo number_format($om_overlap, 2); ?>"
     data-drift="<?php echo $om_drift; ?>"
     data-warp="<?php echo $om_warp; ?>"
     data-pan="1"
     data-cluster-size="9"
     data-loading-label="Dealing"></div>

<?php include('skin-footer.php'); ?>
<?php // ===== SNAPSMACK EOF =====
