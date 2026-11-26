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

    var repair = document.getElementById('page-gallery-repair');
    if (repair) {
        var feedback = document.getElementById('page-gallery-feedback');
        var galleryIds = new Set(repair.dataset.galleryIds.split(','));
        var plainIds = new Set(repair.dataset.plainGalleryIds.split(','));

        document.getElementById('page-gallery-group').addEventListener('click', function () {
            var start = ta.selectionStart;
            var end = ta.selectionEnd;
            var explicit = start !== end;
            var source = explicit ? ta.value.slice(start, end) : ta.value;
            // Accept only the importer's empty figure wrappers. Any caption,
            // link, or image option is authored content this action must keep.
            var figure = '<figure>\\s*<span>\\s*\\[img:\\s*(g?)([1-9]\\d{0,9})\\s*\\]\\s*</span>\\s*<span>\\s*</span>\\s*</figure>';
            var runs = Array.from(source.matchAll(new RegExp('(?:' + figure + '\\s*){2,}', 'gi')));
            if (runs.length !== 1 || (explicit && runs[0][0].trim() !== source.trim())) {
                feedback.textContent = 'Select one uninterrupted group of imported image figures.';
                return;
            }
            var run = runs[0];
            var photos = Array.from(run[0].matchAll(new RegExp(figure, 'gi')));
            if (photos.length > 500 || photos.some(function (photo) {
                return !(photo[1] ? galleryIds : plainIds).has(photo[2]);
            })) {
                feedback.textContent = 'This group contains a missing or ambiguous Gallery photo, or more than 500 photos.';
                return;
            }
            var layout = document.getElementById('page-gallery-layout').value;
            var ids = photos.map(function (photo) { return photo[2]; });
            var blockStart = (explicit ? start : 0) + run.index;
            ta.setRangeText('[mosaic-images:' + ids.join(',') + ' layout=' + layout + ']\n',
                blockStart, blockStart + run[0].length, 'select');
            ta.dispatchEvent(new Event('input', { bubbles: true }));
            feedback.textContent = ids.length + ' photos grouped. Preview the page, then save when ready.';
        });
    }

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
