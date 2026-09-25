/**
 * SNAPSMACK - sc-page-vax
 *
 * Moved verbatim out of a <script> block in smack-central/sc-vax.php during the inline-JS
 * sweep. No logic was changed. Loaded from the same place in the page, so
 * it still runs at the same point relative to everything else.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

document.querySelectorAll('.vax-token-preview').forEach(function(el) {
    var full    = el.dataset.full;
    var preview = el.textContent.trim();
    var showing = false;
    el.addEventListener('click', function() {
        showing = !showing;
        el.textContent = showing ? full : preview;
        el.style.color = showing ? 'var(--sc-warn)' : '';
    });
});

// ===== SNAPSMACK EOF =====
