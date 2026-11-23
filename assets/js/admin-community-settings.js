/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * Community settings form interactions.
 */

(function () {
    'use strict';

    document.querySelectorAll('.radio-option').forEach(function (label) {
        var input = label.querySelector('input');
        if (!input) return;
        input.addEventListener('change', function () {
            document.querySelectorAll('.radio-option').forEach(function (option) {
                var optionInput = option.querySelector('input');
                option.classList.toggle('is-selected', !!optionInput && optionInput.checked);
            });
        });
    });
})();
// ===== SNAPSMACK EOF =====
