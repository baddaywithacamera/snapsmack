/**
 * SNAPSMACK — Formatting Toolbar
 * Alpha v0.7
 *
 * Lightweight vanilla JS toolbar for the transmission editor.
 * Wraps selected text in HTML / shortcodes, provides live preview
 * via AJAX calls to smack-preview-ajax.php.
 */

class FormattingToolbar {

    constructor(textareaId, previewId, ajaxUrl) {
        this.textarea = document.getElementById(textareaId);
        this.preview  = document.getElementById(previewId);
        this.ajaxUrl  = ajaxUrl;
        this.debounceTimer = null;

        if (!this.textarea || !this.preview) return;

        this._setupAutoPreview();

        // Initial preview render if textarea already has content (edit page)
        if (this.textarea.value.trim()) {
            this.refreshPreview();
        }
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
        const url = prompt('Enter URL:');
        if (!url) return;
        const sel = this._getSelection();
        const text = sel.text || 'link text';
        this._replaceSelection('<a href="' + url + '">' + text + '</a>');
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
    //  PREVIEW
    // =====================================================================

    refreshPreview() {
        const content = this.textarea.value;
        const preview = this.preview;

        if (!content.trim()) {
            preview.innerHTML = '<p style="opacity:0.4;">Preview will appear here&hellip;</p>';
            return;
        }

        // Show loading indicator
        preview.style.opacity = '0.6';

        const formData = new FormData();
        formData.append('content', content);

        fetch(this.ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            preview.style.opacity = '1';
            if (data.success) {
                preview.innerHTML = data.html;
            } else {
                preview.innerHTML = '<p style="color:#f44;">Preview error: ' + (data.error || 'unknown') + '</p>';
            }
        })
        .catch(function(err) {
            preview.style.opacity = '1';
            preview.innerHTML = '<p style="color:#f44;">Preview failed — check console.</p>';
            console.error('Preview fetch error:', err);
        });
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

        this._triggerPreview();
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

        this._triggerPreview();
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

        this._triggerPreview();
    }

    _insertAtCursor(text) {
        const ta  = this.textarea;
        const pos = ta.selectionStart;
        const val = ta.value;

        ta.value = val.substring(0, pos) + text + val.substring(pos);
        ta.focus();

        const newPos = pos + text.length;
        ta.selectionStart = ta.selectionEnd = newPos;

        this._triggerPreview();
    }

    _triggerPreview() {
        clearTimeout(this.debounceTimer);
        const self = this;
        this.debounceTimer = setTimeout(function() {
            self.refreshPreview();
        }, 400);
    }

    _setupAutoPreview() {
        const self = this;
        this.textarea.addEventListener('input', function() {
            self._triggerPreview();
        });
    }
}

// =====================================================================
//  INITIALISATION
// =====================================================================

document.addEventListener('DOMContentLoaded', function() {
    var ta      = document.getElementById('desc');
    var preview = document.getElementById('preview-panel');
    if (ta && preview) {
        window.toolbar = new FormattingToolbar('desc', 'preview-panel', 'smack-preview-ajax.php');
    }
});
