/**
 * SNAPSMACK - ss-page-edit-carousel
 *
 * Moved verbatim out of a <script> block in smack-edit-carousel.php during the inline-JS
 * sweep. No logic was changed. Loaded from the same place in the page, so
 * it still runs at the same point relative to everything else.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

window.addEventListener('DOMContentLoaded', function () {
    if (typeof updateLabel === 'function') {
        updateLabel('cat');
        updateLabel('album');
    }
    // Wire FRAME toggle buttons for per_image style panels (PHP-rendered strip items).
    document.querySelectorAll('.ce-style-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var panel = this.nextElementSibling;
            if (!panel || !panel.classList.contains('ce-style-panel')) return;
            var open = panel.style.display !== 'none';
            panel.style.display = open ? 'none' : 'block';
            this.textContent = open ? 'FRAME ▸' : 'FRAME ▾';
        });
    });
});

// ===== SNAPSMACK EOF =====
