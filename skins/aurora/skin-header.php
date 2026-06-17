<?php
/**
 * SNAPSMACK - AURORA Skin Header
 * Alpha v0.7.9
 *
 * Outputs the sticky top nav bar and opens the page wrapper.
 * No conditional CSS overrides are needed in Phase 1 — all dynamic
 * values are handled via :root custom properties in the compiled CSS blob.
 *
 * $settings is available from the calling template.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// AURORA's header IS the shared profile + sticky-nav block. Rendering it here
// means any core page that pulls in the active skin's header (e.g. blogroll.php)
// gets the identical header without needing skin-specific knowledge.
include __DIR__ . '/skin-profile.php';
// ===== SNAPSMACK EOF =====
