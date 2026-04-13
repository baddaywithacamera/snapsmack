/**
 * SNAPSMACK - AI Writing Assistant Engine
 *
 * Powers the SP/GR and AI ASSIST buttons in the post editor.
 * Talks to smack-ai-assist.php (mode: spellcheck | chat).
 *
 * Expects in DOM:
 *   #desc              — the description textarea
 *   #btn-spellcheck    — triggers spell/grammar check
 *   #btn-ai-assist     — toggles the AI assist panel
 *   #ai-assist-panel   — the collapsible chat panel
 *   #ai-assist-close   — closes the panel
 *   #ai-assist-messages — message thread display
 *   #ai-assist-input   — user chat input
 *   #ai-assist-send    — sends the chat message
 *   #ai-assist-dump    — dumps last AI response into editor
 */

(function () {
    'use strict';

    var ENDPOINT = 'smack-ai-assist.php';
    var lastAiText = '';

    // ── DOM refs ─────────────────────────────────────────────────────────────

    var desc        = document.getElementById('desc');
    var btnSpell    = document.getElementById('btn-spellcheck');
    var btnAssist   = document.getElementById('btn-ai-assist');
    var panel       = document.getElementById('ai-assist-panel');
    var btnClose    = document.getElementById('ai-assist-close');
    var messages    = document.getElementById('ai-assist-messages');
    var input       = document.getElementById('ai-assist-input');
    var btnSend     = document.getElementById('ai-assist-send');
    var btnDump     = document.getElementById('ai-assist-dump');

    if (!desc || !btnSpell || !btnAssist) return; // AI not configured or wrong page

    // ── Helpers ──────────────────────────────────────────────────────────────

    function post(data, callback) {
        var fd = new FormData();
        for (var k in data) fd.append(k, data[k]);
        fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        })
        .then(function (r) { return r.json(); })
        .then(callback)
        .catch(function () { callback({ ok: false, error: 'Request failed.' }); });
    }

    function setWorking(btn, working) {
        btn.disabled = working;
        btn.classList.toggle('sc-btn-working', working);
    }

    // ── Spell / Grammar ──────────────────────────────────────────────────────

    function spellcheck() {
        var selected = desc.value.substring(desc.selectionStart, desc.selectionEnd);
        var content  = desc.value;
        if (!content.trim()) {
            alert('Nothing to check — write something first.');
            return;
        }
        setWorking(btnSpell, true);
        post({ mode: 'spellcheck', selected: selected, content: content }, function (res) {
            setWorking(btnSpell, false);
            if (!res.ok) { alert('SP/GR: ' + res.error); return; }
            showSpellResult(res.text, selected !== '' ? 'selection' : 'full');
        });
    }

    function showSpellResult(corrected, scope) {
        // Build a simple overlay with the corrected text and a replace button
        var overlay = document.getElementById('ss-spell-overlay');
        if (overlay) overlay.remove();

        overlay = document.createElement('div');
        overlay.id = 'ss-spell-overlay';
        overlay.style.cssText =
            'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);' +
            'display:flex;align-items:center;justify-content:center;z-index:9999;';

        var box = document.createElement('div');
        box.style.cssText =
            'background:var(--bg-secondary,#1e1e1e);border:1px solid var(--border-color,#333);' +
            'padding:32px 36px;max-width:600px;width:90%;border-radius:4px;' +
            'font-family:inherit;box-shadow:0 16px 40px rgba(0,0,0,0.8);';

        var label = scope === 'selection' ? 'CORRECTED SELECTION' : 'CORRECTED TEXT';

        box.innerHTML =
            '<h4 style="margin:0 0 16px;letter-spacing:2px;font-size:0.8rem;' +
                'color:var(--color-accent,#e05252);">' + label + '</h4>' +
            '<textarea id="ss-spell-result" style="width:100%;min-height:140px;' +
                'font-family:inherit;font-size:0.9rem;background:var(--bg-primary,#111);' +
                'color:var(--text-primary,#ccc);border:1px solid var(--border-color,#444);' +
                'padding:12px;resize:vertical;box-sizing:border-box;">' +
                escHtml(corrected) +
            '</textarea>' +
            '<div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end;">' +
                '<button type="button" id="ss-spell-cancel" style="' +
                    'background:transparent;border:1px solid var(--border-color,#555);' +
                    'color:var(--text-secondary,#aaa);padding:8px 20px;cursor:pointer;' +
                    'font-family:inherit;font-size:0.8rem;letter-spacing:1px;">DISCARD</button>' +
                '<button type="button" id="ss-spell-apply" style="' +
                    'background:var(--color-accent,#e05252);border:none;color:#fff;' +
                    'padding:8px 20px;cursor:pointer;font-family:inherit;' +
                    'font-size:0.8rem;letter-spacing:1px;">REPLACE</button>' +
            '</div>';

        overlay.appendChild(box);
        document.body.appendChild(overlay);

        document.getElementById('ss-spell-cancel').onclick = function () { overlay.remove(); };
        overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });

        document.getElementById('ss-spell-apply').onclick = function () {
            var result = document.getElementById('ss-spell-result').value;
            if (scope === 'selection') {
                var s = desc.selectionStart, e2 = desc.selectionEnd;
                desc.value = desc.value.substring(0, s) + result + desc.value.substring(e2);
            } else {
                desc.value = result;
            }
            overlay.remove();
            desc.focus();
        };
    }

    function escHtml(str) {
        return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // ── AI Assist panel ──────────────────────────────────────────────────────

    function openPanel() {
        panel.style.display = '';
        if (input) input.focus();
    }

    function closePanel() {
        panel.style.display = 'none';
    }

    function appendMessage(role, text) {
        var el = document.createElement('div');
        el.className = 'ai-msg ai-msg-' + role;
        el.textContent = text;
        messages.appendChild(el);
        messages.scrollTop = messages.scrollHeight;
    }

    function sendChat() {
        var msg = input.value.trim();
        if (!msg) return;
        appendMessage('user', msg);
        input.value = '';
        setWorking(btnSend, true);
        if (btnDump) btnDump.style.display = 'none';

        post({ mode: 'chat', message: msg, content: desc.value }, function (res) {
            setWorking(btnSend, false);
            if (!res.ok) { appendMessage('error', 'Error: ' + res.error); return; }
            lastAiText = res.text;
            appendMessage('ai', res.text);
            if (btnDump) btnDump.style.display = '';
        });
    }

    // ── Event wiring ─────────────────────────────────────────────────────────

    btnSpell.addEventListener('click', spellcheck);

    btnAssist.addEventListener('click', function () {
        if (!panel) return;
        panel.style.display === 'none' ? openPanel() : closePanel();
    });

    if (btnClose)  btnClose.addEventListener('click', closePanel);

    if (btnSend) {
        btnSend.addEventListener('click', sendChat);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChat(); }
        });
    }

    if (btnDump) {
        btnDump.addEventListener('click', function () {
            if (!lastAiText) return;
            var cursor = desc.selectionStart;
            desc.value = desc.value.substring(0, cursor) +
                (cursor > 0 && desc.value[cursor - 1] !== '\n' ? '\n\n' : '') +
                lastAiText +
                desc.value.substring(cursor);
            desc.focus();
        });
    }

})();
