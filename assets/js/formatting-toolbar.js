/**
 * SNAPSMACK — Formatting Toolbar
 * Alpha v0.7.8e
 *
 * Lightweight vanilla JS toolbar for the transmission editor.
 * Wraps selected text in HTML / shortcodes, provides live preview
 * via AJAX calls to smack-preview-ajax.php.
 * AI Assist and Spell/Grammar check via smack-ai-assist.php.
 */

class FormattingToolbar {

    constructor(textareaId, ajaxUrl) {
        this.textarea = document.getElementById(textareaId);
        this.ajaxUrl  = ajaxUrl;

        if (!this.textarea) return;
    }

    // =====================================================================
    //  TOOLBAR BUTTON ACTIONS
    // =====================================================================

    insertBold() {
        this._wrapSelection('<strong>', '</strong>');
    }

    insertItalic() {
        this._wrapSelection('<em>', '</em>');
    }

    insertH2() {
        this._wrapBlock('<h2>', '</h2>');
    }

    insertH3() {
        this._wrapBlock('<h3>', '</h3>');
    }

    insertBlockquote() {
        this._wrapBlock('<blockquote>', '</blockquote>');
    }

    insertLink() {
        // Snapshot selection BEFORE prompt() — the dialog steals focus and
        // collapses selectionStart/selectionEnd to the same point in most browsers.
        // Use sel.start/sel.end directly; never re-read ta.selectionStart/End after
        // the prompt returns or the original text won't be replaced (just duplicated).
        const sel         = this._getSelection();
        const url         = prompt('Enter URL:');
        if (!url) return;
        const text        = sel.text || 'link text';
        const replacement = '<a href="' + url + '">' + text + '</a>';
        const ta          = this.textarea;
        const val         = ta.value;
        ta.value          = val.substring(0, sel.start) + replacement + val.substring(sel.end);
        ta.focus();
        ta.selectionStart = ta.selectionEnd = sel.start + replacement.length;
    }

    insertColumns(count) {
        const placeholder = 'Column 1 content';
        const dividers    = [];
        for (let i = 2; i <= count; i++) {
            dividers.push('\n\n[col]\n\nColumn ' + i + ' content');
        }
        const block = '\n[columns=' + count + ']\n' + placeholder + dividers.join('') + '\n[/columns]\n';
        this._insertAtCursor(block);
    }

    insertDropcap() {
        const sel = this._getSelection();
        if (sel.text.length > 0) {
            // Wrap first character of selection
            const first = sel.text.charAt(0);
            const rest  = sel.text.substring(1);
            this._replaceSelection('[dropcap]' + first + '[/dropcap]' + rest);
        } else {
            this._insertAtCursor('[dropcap]A[/dropcap]');
        }
    }

    // --- IMAGE INSERTION (via dropdown panel) ---

    toggleImagePanel() {
        const panel = document.getElementById('toolbar-img-panel');
        if (panel) {
            panel.style.display = panel.style.display === 'none' ? 'flex' : 'none';
        }
    }

    insertImageFromPanel() {
        const idField    = document.getElementById('toolbar-img-id');
        const sizeField  = document.getElementById('toolbar-img-size');
        const alignField = document.getElementById('toolbar-img-align');

        if (!idField) return;

        const id    = idField.value.trim();
        const size  = sizeField ? sizeField.value : 'full';
        const align = alignField ? alignField.value : 'center';

        if (!id || isNaN(id)) {
            alert('Enter a valid image ID (number).');
            return;
        }

        this._insertAtCursor('[img:' + id + '|' + size + '|' + align + ']');

        // Reset and close panel
        idField.value = '';
        this.toggleImagePanel();
    }

    // =====================================================================
    //  PREVIEW IN NEW TAB
    // =====================================================================

    openPreviewTab() {
        const content = this.textarea.value;
        if (!content.trim()) {
            alert('Nothing to preview — write something first.');
            return;
        }

        // POST to the AJAX endpoint from a hidden form targeting a new tab
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = this.ajaxUrl + '?mode=full';
        form.target = '_blank';

        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'content';
        input.value = content;
        form.appendChild(input);

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }

    // =====================================================================
    //  INTERNAL HELPERS
    // =====================================================================

    _getSelection() {
        const start = this.textarea.selectionStart;
        const end   = this.textarea.selectionEnd;
        return {
            start: start,
            end:   end,
            text:  this.textarea.value.substring(start, end)
        };
    }

    _replaceSelection(replacement) {
        const ta    = this.textarea;
        const start = ta.selectionStart;
        const end   = ta.selectionEnd;
        const val   = ta.value;

        ta.value = val.substring(0, start) + replacement + val.substring(end);
        ta.focus();

        // Place cursor after insertion
        const newPos = start + replacement.length;
        ta.selectionStart = ta.selectionEnd = newPos;

        // preview removed — use PREVIEW button for new-tab preview
    }

    _wrapSelection(before, after) {
        const sel  = this._getSelection();
        const text = sel.text || 'text';
        const replacement = before + text + after;

        const ta  = this.textarea;
        const val = ta.value;

        ta.value = val.substring(0, sel.start) + replacement + val.substring(sel.end);
        ta.focus();

        // Select the inner text (without the tags)
        ta.selectionStart = sel.start + before.length;
        ta.selectionEnd   = sel.start + before.length + text.length;

        // preview removed — use PREVIEW button for new-tab preview
    }

    _wrapBlock(before, after) {
        const sel  = this._getSelection();
        const text = sel.text || 'text';

        // If selection doesn't start on a new line, prepend one
        const val   = this.textarea.value;
        const prefix = (sel.start > 0 && val.charAt(sel.start - 1) !== '\n') ? '\n' : '';
        const suffix = (sel.end < val.length && val.charAt(sel.end) !== '\n') ? '\n' : '';

        const replacement = prefix + before + text + after + suffix;

        this.textarea.value = val.substring(0, sel.start) + replacement + val.substring(sel.end);
        this.textarea.focus();

        // preview removed — use PREVIEW button for new-tab preview
    }

    _insertAtCursor(text) {
        const ta  = this.textarea;
        const pos = ta.selectionStart;
        const val = ta.value;

        ta.value = val.substring(0, pos) + text + val.substring(pos);
        ta.focus();

        const newPos = pos + text.length;
        ta.selectionStart = ta.selectionEnd = newPos;

        // preview removed — use PREVIEW button for new-tab preview
    }

}

// =====================================================================
//  INITIALISATION
// =====================================================================

document.addEventListener('DOMContentLoaded', function() {
    var ta = document.getElementById('desc');
    if (ta) {
        window.toolbar = new FormattingToolbar('desc', 'smack-preview-ajax.php');
    }

    // ── Spell / Grammar check ─────────────────────────────────────────
    var spellBtn = document.getElementById('btn-spellcheck');
    if (spellBtn && ta) {
        spellBtn.addEventListener('click', function () {
            var selected = ta.value.substring(ta.selectionStart, ta.selectionEnd);
            var payload  = new FormData();
            payload.append('mode',     'spellcheck');
            payload.append('content',  ta.value);
            payload.append('selected', selected);

            spellBtn.textContent = '…';
            spellBtn.disabled    = true;

            fetch('smack-ai-assist.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: payload,
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.ok) {
                    if (selected) {
                        // Replace only the selection
                        var start = ta.selectionStart;
                        var end   = ta.selectionEnd;
                        ta.value  = ta.value.substring(0, start) + d.text + ta.value.substring(end);
                        ta.selectionStart = start;
                        ta.selectionEnd   = start + d.text.length;
                    } else {
                        ta.value = d.text;
                    }
                } else {
                    alert('Spell check failed: ' + d.error);
                }
            })
            .catch(function () { alert('Spell check request failed.'); })
            .finally(function () {
                spellBtn.textContent = 'SP/GR';
                spellBtn.disabled    = false;
            });
        });
    }

    // ── AI Assist panel ───────────────────────────────────────────────
    var assistBtn   = document.getElementById('btn-ai-assist');
    var panel       = document.getElementById('ai-assist-panel');
    var closeBtn    = document.getElementById('ai-assist-close');
    var sendBtn     = document.getElementById('ai-assist-send');
    var inputEl     = document.getElementById('ai-assist-input');
    var messagesEl  = document.getElementById('ai-assist-messages');
    var dumpBtn     = document.getElementById('ai-assist-dump');
    var _lastResponse = '';

    if (assistBtn && panel) {
        assistBtn.addEventListener('click', function () {
            panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
            if (panel.style.display === 'block' && inputEl) inputEl.focus();
        });
    }

    if (closeBtn && panel) {
        closeBtn.addEventListener('click', function () { panel.style.display = 'none'; });
    }

    function _appendMessage(role, text) {
        if (!messagesEl) return;
        var div = document.createElement('div');
        div.className = 'ai-msg ai-msg--' + role;
        div.textContent = text;
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function _sendAssist() {
        if (!inputEl || !ta) return;
        var msg = inputEl.value.trim();
        if (!msg) return;

        _appendMessage('user', msg);
        inputEl.value   = '';
        sendBtn.disabled = true;
        if (dumpBtn) dumpBtn.style.display = 'none';

        var payload = new FormData();
        payload.append('mode',    'chat');
        payload.append('message', msg);
        payload.append('content', ta.value);

        fetch('smack-ai-assist.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: payload,
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok) {
                _lastResponse = d.text;
                _appendMessage('ai', d.text);
                if (dumpBtn) dumpBtn.style.display = 'inline-block';
            } else {
                _appendMessage('error', 'Error: ' + d.error);
            }
        })
        .catch(function () { _appendMessage('error', 'Request failed — check your connection.'); })
        .finally(function () { sendBtn.disabled = false; });
    }

    if (sendBtn)  sendBtn.addEventListener('click', _sendAssist);
    if (inputEl)  inputEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); _sendAssist(); }
    });

    if (dumpBtn && ta) {
        dumpBtn.addEventListener('click', function () {
            if (!_lastResponse) return;
            var pos = ta.selectionStart;
            var val = ta.value;
            var insert = (pos > 0 && val[pos - 1] !== '\n') ? '\n\n' + _lastResponse : _lastResponse;
            ta.value = val.substring(0, pos) + insert + val.substring(pos);
            ta.focus();
            ta.selectionStart = ta.selectionEnd = pos + insert.length;
            panel.style.display = 'none';
        });
    }
});
