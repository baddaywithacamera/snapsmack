/*
 * SNAPSMACK — AI spending-cap modal
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * Shown on smack-settings.php the first time a per-use AI provider key is
 * present and the owner hasn't confirmed a spend cap (liability protection).
 * The page renders #ai-spendcap-modal with data-prompt / data-provider; this
 * reveals it and records the choice. CONFIRM = never ask again for that
 * provider; DEFER 1 WEEK = snooze 7 days. Per provider.
 */
(function () {
    'use strict';

    var modal = document.getElementById('ai-spendcap-modal');
    if (!modal || modal.dataset.prompt !== '1') return;

    var provider = modal.dataset.provider || '';
    modal.style.display = 'flex';

    function record(choice) {
        var fd = new FormData();
        fd.append('action', 'ai_spendcap');
        fd.append('choice', choice);
        fd.append('provider', provider);
        return fetch('smack-settings.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        }).then(function (r) { return r.json(); })
          .catch(function () { return { ok: false }; });
    }

    function wire(id, choice) {
        var btn = document.getElementById(id);
        if (!btn) return;
        btn.addEventListener('click', function () {
            btn.disabled = true;
            record(choice).finally(function () { modal.style.display = 'none'; });
        });
    }

    wire('ai-spendcap-confirm', 'confirm');
    wire('ai-spendcap-defer', 'defer');
})();
// ===== SNAPSMACK EOF =====
