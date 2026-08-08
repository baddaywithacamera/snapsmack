/**
 * SNAPSMACK - Page HTML Import (admin editor helper)
 *
 * Loads a local .html file straight into the page-content textarea, entirely
 * client-side (FileReader — nothing is uploaded, no server round-trip, no stored
 * file). Strips the repo EOF-marker header/footer comments so a skin page body
 * (e.g. projects/photofri-day/cms-pages/*.html) comes in clean. Same result as
 * pasting, minus the open-file-and-copy step.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

(function () {
    'use strict';

    var btn  = document.querySelector('[data-action="import-html"]');
    var file = document.getElementById('page-html-import-file');
    var ta   = document.getElementById('page-content');
    if (!btn || !file || !ta) return;

    btn.addEventListener('click', function () { file.click(); });

    file.addEventListener('change', function () {
        var f = file.files && file.files[0];
        if (!f) return;
        var reader = new FileReader();
        reader.onload = function () {
            var txt = String(reader.result || '');
            // Drop the repo hygiene comments — they are not page content.
            txt = txt.replace(/<!--[^>]*SNAPSMACK_EOF_HEADER[\s\S]*?-->\s*/i, '');
            txt = txt.replace(/<!--\s*=+\s*SNAPSMACK EOF\s*=+\s*-->\s*$/i, '');
            ta.value = txt.trim();
            // Let any listeners (autosave, char counters) know the field changed.
            ta.dispatchEvent(new Event('input', { bubbles: true }));
            ta.focus();
        };
        reader.readAsText(f);
        file.value = '';  // allow re-importing the same file
    });
}());
// ===== SNAPSMACK EOF =====
