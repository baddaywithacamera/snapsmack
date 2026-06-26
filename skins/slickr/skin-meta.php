<?php
/**
 * SNAPSMACK - Slickr Skin Meta Templates
 * Spec v0.1 — Flickr visual idiom clone for archive migrations.
 *
 * @author Sean McCormick
 */

/**
 * SNAPSMACK_HEADER_PROTECTION
 * <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

// Emit the full <head> (SEO/OG, fonts, engine CSS, skin style.css, dynamic
// overrides) via core/meta.php — the standard pattern every other skin's
// skin-meta uses (cf. skins/rational-geo/skin-meta.php). The previous stub
// emitted ONLY style.css and never pulled core/meta.php, so archive pages got
// the skin baseline but ZERO engine stylesheets: the unified archive filter
// panel and the footer "Browse by Date" calendar rendered raw, and no real
// page <head>/<title> was set. core/meta.php emits this skin's own style.css
// itself (step 4 of its CSS load order), so no separate <link> belongs here.
include(dirname(__DIR__, 2) . '/core/meta.php');
// ===== SNAPSMACK EOF =====