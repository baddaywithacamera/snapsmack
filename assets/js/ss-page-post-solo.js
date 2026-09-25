/**
 * SNAPSMACK - ss-page-post-solo
 *
 * Moved verbatim out of a <script> block in smack-post-solo.php during the inline-JS
 * sweep. No logic was changed. Loaded from the same place in the page, so
 * it still runs at the same point relative to everything else.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

window.addEventListener('DOMContentLoaded', () => {
    if(typeof updateLabel === "function") {
        updateLabel('cat');
        updateLabel('album');
        updateLabel('collection');
    }
});

// ===== SNAPSMACK EOF =====
