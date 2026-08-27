/**
 * SNAPSMACK - Schedule a Prompt (admin helper)
 *
 * Conveniences on the SCHEDULE A PROMPT admin page. Display only — the server
 * (core/photochallenge.php pc_queue_prompt) rebuilds everything on submit.
 *   1. Live hashtag preview — mirrors pc_hashtag_from_prompt() so "Belonging"
 *      shows as #PhotoFriBelonging as you type. Prefix from data-pc-prefix.
 *   2. Drop-time hint — for the chosen DROP TIMING and Photo-Friday, shows the
 *      exact moment the card will post:
 *        week_before  = the window-open time (Thu 10:00 UTC) minus 7 days,
 *        window_open  = the window-open time itself,
 *        custom       = whatever you enter below (the custom field is revealed).
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
    var modeEl   = document.getElementById('pc_drop_mode');
    var hintEl   = document.getElementById('pc_drop_hint');
    var customEl = document.getElementById('pc_custom_wrap');
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
        if (!hashEl || !promptEl) return;
        var tail = camel(promptEl.value);
        hashEl.textContent = '#' + prefix + (tail || '…');   // … while empty
    }

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function fmt(d) {
        return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()) +
               ' ' + pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()) + ':00 UTC';
    }
    // Window opens Thursday 10:00 UTC — the chosen Friday's 00:00 UTC minus 14 hours.
    function windowOpen() {
        if (!fridayEl || !fridayEl.value) return null;
        var p = fridayEl.value.split('-');
        if (p.length !== 3) return null;
        return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2], 0, 0, 0) - 14 * 3600 * 1000);
    }

    function updateDrop() {
        var mode = modeEl ? modeEl.value : 'week_before';
        if (customEl) customEl.style.display = (mode === 'custom') ? '' : 'none';
        if (!hintEl) return;
        if (mode === 'custom') {
            hintEl.textContent = 'the custom time you set below';
            return;
        }
        var open = windowOpen();
        if (!open) { hintEl.textContent = '—'; return; }
        var target = (mode === 'week_before')
            ? new Date(open.getTime() - 7 * 86400 * 1000)
            : open;
        hintEl.textContent = fmt(target);
    }

    if (promptEl) { promptEl.addEventListener('input', updateHash); updateHash(); }
    if (fridayEl) fridayEl.addEventListener('change', updateDrop);
    if (modeEl)   modeEl.addEventListener('change', updateDrop);
    updateDrop();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _smackPromptScheduleInit);
} else {
    _smackPromptScheduleInit();
}

} // end double-load guard
// ===== SNAPSMACK EOF =====
