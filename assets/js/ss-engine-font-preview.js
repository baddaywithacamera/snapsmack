/**
 * SNAPSMACK - Font Preview Engine
 *
 * Handles live font previews on the skin admin panel.
 * Listens for change events on <select data-font-preview="1"> elements,
 * previews the chosen locally installed font via the CMS-owned @font-face rules,
 * then updates the sibling .font-preview-text spans.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


(function () {
    'use strict';

    /**
     * Update all .font-preview-text spans inside the preview container.
     */
    function updatePreview(select, family) {
        var wrapper = select.closest('.lens-input-wrapper') || select.parentNode;
        var previewBox = wrapper.querySelector('.font-preview');
        if (!previewBox) return;

        var spans = previewBox.querySelectorAll('.font-preview-text');
        for (var i = 0; i < spans.length; i++) {
            spans[i].style.fontFamily = "'" + family + "', sans-serif";
        }
        // Update the name display (first span shows font name)
        if (spans.length > 0) {
            spans[0].textContent = family;
        }
    }

    /**
     * Handle a font select change event.
     *
     * Font previews are deliberately local-only. The admin must not contact a
     * third-party font service merely because an owner opens a settings page.
     */
    function onFontChange(e) {
        var select = e.target;
        var family = select.value;
        if (!family) return;

        updatePreview(select, family);
    }

    /**
     * Initialise: bind listeners to all font-preview selects on the page.
     */
    function init() {
        var selects = document.querySelectorAll('select[data-font-preview]');
        for (var i = 0; i < selects.length; i++) {
            selects[i].addEventListener('change', onFontChange);
        }
    }

    // Boot when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
// ===== SNAPSMACK EOF =====
