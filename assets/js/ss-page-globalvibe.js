/**
 * SNAPSMACK - ss-page-globalvibe
 *
 * Moved verbatim out of a <script> block in smack-globalvibe.php during the inline-JS
 * sweep. No logic was changed. Loaded from the same place in the page, so
 * it still runs at the same point relative to everything else.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

document.querySelectorAll('.footer-slot-toggle').forEach(function(sel) {
    sel.addEventListener('change', function() {
        var field = document.getElementById('field-' + this.getAttribute('data-target'));
        if (field) field.style.display = (this.value === 'custom') ? '' : 'none';
    });
});

// ===== SNAPSMACK EOF =====
