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
    var dropEl   = document.getElementById('pc_drop_at');
    var hintEl   = document.getElementById('pc_drop_hint');
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
        if (!fridayEl || !fridayEl.value) return;
        var parts = fridayEl.value.split('-');
        if (parts.length !== 3) return;
        var fri = Date.UTC(+parts[0], +parts[1] - 1, +parts[2], 0, 0, 0);
        var open = new Date(fri - 14 * 3600 * 1000);
        var date = open.getUTCFullYear() + '-' + pad(open.getUTCMonth() + 1) + '-' + pad(open.getUTCDate());
        var time = pad(open.getUTCHours()) + ':' + pad(open.getUTCMinutes());
        if (dropEl) dropEl.value = date + 'T' + time;
        if (hintEl) hintEl.textContent = date + ' ' + time + ':00 UTC';
    }

    if (promptEl) { promptEl.addEventListener('input', updateHash); updateHash(); }
    if (fridayEl) { fridayEl.addEventListener('change', updateWindowStart); updateWindowStart(); }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _smackPromptScheduleInit);
} else {
    _smackPromptScheduleInit();
}

} // end double-load guard
// ===== SNAPSMACK EOF =====
