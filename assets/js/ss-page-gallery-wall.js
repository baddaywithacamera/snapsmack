/**
 * SNAPSMACK - ss-page-gallery-wall
 *
 * Moved verbatim out of a <script> block in gallery-wall.php during the inline-JS
 * sweep. No logic was changed. Loaded from the same place in the page, so
 * it still runs at the same point relative to everything else.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

// Mobile gate: Redirect touch devices or small screens to archive.
// Runs in <head> before body render as a client-side fallback for the PHP
// user-agent check (some devices slip through server-side sniffing).
if (window.innerWidth < 768 || ('ontouchstart' in window && window.innerWidth < 1024)) {
    window.location.replace('archive.php');
}

// ===== SNAPSMACK EOF =====
