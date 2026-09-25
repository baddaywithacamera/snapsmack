/**
 * SNAPSMACK - ss-page-snap-in
 *
 * Moved verbatim out of a <script> block in snap-in.php during the inline-JS
 * sweep. No logic was changed. Loaded from the same place in the page, so
 * it still runs at the same point relative to everything else.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

(function () {
    document.querySelectorAll('.login-tabs .login-tab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tab = this.dataset.tab;
            document.querySelectorAll('.login-tabs .login-tab').forEach(function (b) {
                b.classList.toggle('active', b.dataset.tab === tab);
            });
            document.querySelectorAll('.login-panel').forEach(function (p) {
                p.classList.toggle('active', p.id === tab);
            });
        });
    });
}());

// ===== SNAPSMACK EOF =====
