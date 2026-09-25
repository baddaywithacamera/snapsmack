/**
 * SNAPSMACK - sc-page-assets
 *
 * Moved verbatim out of a <script> block in smack-central/sc-assets.php during the inline-JS
 * sweep. No logic was changed. Loaded from the same place in the page, so
 * it still runs at the same point relative to everything else.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

function scTab(btn, id) {
    document.querySelectorAll('.sc-tab').forEach(b => b.classList.remove('active'));
    ['tab-fonts','tab-scripts','tab-upload'].forEach(t => {
        var el = document.getElementById(t);
        if (el) el.style.display = (t === id) ? '' : 'none';
    });
    btn.classList.add('active');
}

// ===== SNAPSMACK EOF =====
