/**
 * SNAPSMACK - sc-page-enemy-admin
 *
 * Moved verbatim out of a <script> block in smack-central/sc-enemy-admin.php during the inline-JS
 * sweep. No logic was changed. Loaded from the same place in the page, so
 * it still runs at the same point relative to everything else.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

function steTab(name) {
    document.querySelectorAll('.ste-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.ste-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('panel-' + name).classList.add('active');
    event.target.classList.add('active');
}

// ===== SNAPSMACK EOF =====
