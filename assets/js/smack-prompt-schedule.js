/**
 * SNAPSMACK - Schedule a Prompt (admin helper)
 *
 * Two small conveniences on the PHOTO CHALLENGE admin's SCHEDULE A PROMPT panel:
 *   1. Live hashtag preview — mirrors core/photochallenge.php pc_hashtag_from_prompt()
 *      so "Belonging" shows as #PhotoFriBelonging as you type. The prefix comes
 *      from the form's data-pc-prefix (PhotoFri, ArtFri…). The server rebuilds the
 *      hashtag on submit, so this is display only.
 *   2. Drop time — when the Photo-Friday changes, put the matching window-open
 *      moment into DROPS AT (Thursday 10:00 UTC = Friday 00:00 UTC minus 14 hours)
 *      and mirror it in the explanatory hint.
 *
 * Admin-only helper (smack-* namespace). Ships zero dependencies.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (!window._smackPromptScheduleLoaded) {
window._smackPromptScheduleLoaded = true;

function _smackPromptScheduleInit() {
    var promptEl = document.getElementById('pc_prompt');
    var hashEl   = document.getElementById('pc_hash_preview');
    var fridayEl = document.getElementById('pc_friday');
    var windowEl = document.getElementById('pc_window_start');
    var dropEl   = document.getElementById('pc_drop_at');
    var hintEl   = document.getElementById('pc_drop_hint');
    var imageEl  = document.getElementById('pc_prompt_image');
    var imageNameEl = document.getElementById('pc_prompt_image_name');
    var form     = promptEl ? promptEl.closest('form') : null;
    var prefix   = (form && form.getAttribute('data-pc-prefix')) || 'PhotoFri';

    // "golden hour!" -> "GoldenHour" (mirrors the PHP: split on non-alphanumeric,
    // lowercase then upper-first each word, join).
    function camel(raw) {
        var words = String(raw || '').split(/[^A-Za-z0-9]+/);
        var out = '';
        for (var i = 0; i < words.length; i++) {
            var w = words[i];
            if (!w) continue;
            out += w.charAt(0).toUpperCase() + w.slice(1).toLowerCase();
        }
        return out;
    }

    function updateHash() {
        if (!hashEl) return;
        var tail = camel(promptEl.value);
        hashEl.textContent = '#' + prefix + (tail || '…');   // … while empty
    }

    // Window opens Thursday 10:00 UTC — i.e. the chosen Friday's 00:00 UTC minus
    // 14 hours. Format as "YYYY-MM-DD HH:MM UTC" to match the server's hint text.
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function updateWindowStart() {
        if (!windowEl || !windowEl.value) return;
        var parts = windowEl.value.slice(0, 10).split('-');
        if (parts.length !== 3) return;
        var selectedAt = Date.UTC(+parts[0], +parts[1] - 1, +parts[2], 0, 0, 0);
        var selected = new Date(selectedAt);
        var day = selected.getUTCDay();
        var distance = day <= 5 ? 5 - day : 5 - day;
        var fri = selectedAt + distance * 24 * 3600 * 1000;
        var friday = new Date(fri);
        var fridayValue = friday.getUTCFullYear() + '-' + pad(friday.getUTCMonth() + 1)
            + '-' + pad(friday.getUTCDate());
        fridayEl.value = fridayValue;
        fridayEl.setCustomValidity('');
        var open = new Date(fri - 14 * 3600 * 1000);
        var date = open.getUTCFullYear() + '-' + pad(open.getUTCMonth() + 1) + '-' + pad(open.getUTCDate());
        var time = pad(open.getUTCHours()) + ':' + pad(open.getUTCMinutes());
        windowEl.value = date + 'T' + time;
        var prompt = new Date(open.getTime() - 7 * 24 * 3600 * 1000);
        var promptDate = prompt.getUTCFullYear() + '-' + pad(prompt.getUTCMonth() + 1) + '-' + pad(prompt.getUTCDate());
        var promptTime = pad(prompt.getUTCHours()) + ':' + pad(prompt.getUTCMinutes());
        if (dropEl) dropEl.value = promptDate + 'T' + promptTime;
        if (hintEl) hintEl.textContent = promptDate + ' ' + promptTime + ':00 UTC';
    }

    function updateFromPromptPost() {
        if (!dropEl || !dropEl.value || !windowEl) return;
        var promptParts = dropEl.value.slice(0, 10).split('-');
        if (promptParts.length !== 3) return;
        var promptAt = Date.UTC(+promptParts[0], +promptParts[1] - 1, +promptParts[2], 10, 0, 0);
        var opening = new Date(promptAt + 7 * 24 * 3600 * 1000);
        windowEl.value = opening.getUTCFullYear() + '-' + pad(opening.getUTCMonth() + 1)
            + '-' + pad(opening.getUTCDate()) + 'T10:00';
        updateWindowStart();
    }

    if (promptEl) { promptEl.addEventListener('input', updateHash); updateHash(); }
    if (windowEl && fridayEl) { windowEl.addEventListener('change', updateWindowStart); updateWindowStart(); }
    if (dropEl) dropEl.addEventListener('change', updateFromPromptPost);
    if (imageEl && imageNameEl) {
        imageEl.addEventListener('change', function () {
            imageNameEl.textContent = imageEl.files && imageEl.files[0]
                ? imageEl.files[0].name
                : 'No file chosen';
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _smackPromptScheduleInit);
} else {
    _smackPromptScheduleInit();
}

} // end double-load guard
// ===== SNAPSMACK EOF =====
