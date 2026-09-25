/**
 * SNAPSMACK - ss-page-shortcodes
 *
 * Moved verbatim out of a <script> block in smack-shortcodes.php during the inline-JS
 * sweep. No logic was changed. Loaded from the same place in the page, so
 * it still runs at the same point relative to everything else.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

document.querySelectorAll('.shortcode-copy').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var text = btn.dataset.copy;
        navigator.clipboard.writeText(text).then(function() {
            var orig = btn.textContent;
            btn.textContent = 'Copied!';
            btn.classList.add('copied');
            setTimeout(function() {
                btn.textContent = orig;
                btn.classList.remove('copied');
            }, 1800);
        }).catch(function() {
            // Clipboard API unavailable — show the text to copy manually
            prompt('Copy this shortcode:', text);
        });
    });
});

// ===== SNAPSMACK EOF =====
