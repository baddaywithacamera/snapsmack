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

    // PROMPT is primary. The boosting window opens EXACTLY one week after the
    // prompt publishes; the hidden contest Friday is the Friday of that boost week
    // (that is what the server actually stores). Picking the prompt date fills the
    // boost field automatically — the operator never sets two dates by hand.
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function fmtLocal(d) {
        return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate())
            + 'T' + pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes());
    }
    function syncFromPrompt() {
        if (!dropEl || !dropEl.value) return;
        var v = dropEl.value;                       // "YYYY-MM-DDTHH:MM"
        var d = v.slice(0, 10).split('-');
        var t = (v.slice(11) || '10:00').split(':');
        if (d.length !== 3) return;
        var promptAt = Date.UTC(+d[0], +d[1] - 1, +d[2], (+t[0] || 0), (+t[1] || 0), 0);
        var boost = new Date(promptAt + 7 * 24 * 3600 * 1000);   // exactly one week later
        if (windowEl) windowEl.value = fmtLocal(boost);
        if (fridayEl) {
            var wd = boost.getUTCDay();
            var toFri = (5 - wd + 7) % 7;           // the Friday on/after the boost date
            var fri = new Date(boost.getTime() + toFri * 24 * 3600 * 1000);
            fridayEl.value = fri.getUTCFullYear() + '-' + pad(fri.getUTCMonth() + 1) + '-' + pad(fri.getUTCDate());
            fridayEl.setCustomValidity('');
        }
    }

    if (promptEl) { promptEl.addEventListener('input', updateHash); updateHash(); }
    if (dropEl) {
        dropEl.addEventListener('change', syncFromPrompt);
        dropEl.addEventListener('input', syncFromPrompt);
        syncFromPrompt();                            // fill the boost field on load
    }
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
