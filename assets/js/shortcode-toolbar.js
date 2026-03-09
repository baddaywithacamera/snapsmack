/**
 * SNAPSMACK — Shortcode Toolbar
 * Alpha v0.7
 *
 * Provides insert-at-cursor shortcode buttons for textarea editors.
 * Each button inserts the appropriate shortcode tag at the cursor position
 * or wraps the current selection.
 *
 * Also provides a "Preview" button that POSTs textarea content to
 * smack-preview-ajax.php?mode=full in a new tab.
 */

(function () {
    'use strict';

    // --- Utility: insert text at cursor position in a textarea ---
    function insertAtCursor(textarea, before, after) {
        after = after || '';
        var start = textarea.selectionStart;
        var end   = textarea.selectionEnd;
        var text  = textarea.value;
        var selected = text.substring(start, end);

        // If there's a selection and we have wrap tags, wrap it
        var insert = before + selected + after;

        textarea.value = text.substring(0, start) + insert + text.substring(end);

        // Place cursor after inserted text (or between tags if no selection)
        var cursorPos = selected.length > 0
            ? start + insert.length
            : start + before.length;

        textarea.selectionStart = cursorPos;
        textarea.selectionEnd   = cursorPos;
        textarea.focus();
    }

    // --- Image shortcode: prompt for ID, size, align ---
    function insertImage(textarea) {
        var id = prompt('Image ID (from Media Library):');
        if (!id || !id.trim()) return;

        var size  = prompt('Size — full, wall, or small:', 'full') || 'full';
        var align = prompt('Align — center, left, or right:', 'center') || 'center';

        var tag = '[img:' + id.trim() + '|' + size.trim() + '|' + align.trim() + ']';
        insertAtCursor(textarea, tag);
    }

    // --- List builder: splits selected text into <li> items ---
    // Splits on blank lines (double CR) or single newlines. If nothing
    // is selected, inserts an empty scaffold with two blank items.
    function insertList(textarea, tag) {
        var start = textarea.selectionStart;
        var end   = textarea.selectionEnd;
        var text  = textarea.value;
        var selected = text.substring(start, end);
        var items;

        if (selected.length > 0) {
            // Split on blank lines first; fall back to single newlines.
            items = selected.split(/\n\s*\n/);
            if (items.length === 1) {
                items = selected.split(/\n/);
            }
            // Trim each item and drop empties.
            items = items.map(function (s) { return s.trim(); })
                         .filter(function (s) { return s.length > 0; });
        }

        var block;
        if (items && items.length > 0) {
            block = '\n<' + tag + '>\n';
            items.forEach(function (item) {
                block += '  <li>' + item + '</li>\n';
            });
            block += '</' + tag + '>\n';
        } else {
            block = '\n<' + tag + '>\n  <li></li>\n  <li></li>\n</' + tag + '>\n';
        }

        textarea.value = text.substring(0, start) + block + text.substring(end);
        // Place cursor inside the first empty <li> or after the block.
        var cursorPos = start + block.indexOf('<li>') + 4;
        textarea.selectionStart = cursorPos;
        textarea.selectionEnd   = cursorPos;
        textarea.focus();
    }

    // --- Column shortcode: prompt for count ---
    function insertColumns(textarea, cols) {
        var block = '[columns=' + cols + ']\nFirst column content.\n';
        for (var i = 1; i < cols; i++) {
            block += '\n[col]\n\nColumn ' + (i + 1) + ' content.\n';
        }
        block += '[/columns]';
        insertAtCursor(textarea, block);
    }

    // --- Preview in new tab via form POST ---
    function previewInNewTab(textarea) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = 'smack-preview-ajax.php?mode=full';
        form.target = '_blank';

        var input = document.createElement('input');
        input.type  = 'hidden';
        input.name  = 'content';
        input.value = textarea.value;
        form.appendChild(input);

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }

    // --- Execute a toolbar action on a textarea ---
    function execAction(action, textarea) {
        switch (action) {
            case 'bold':
                insertAtCursor(textarea, '<strong>', '</strong>');
                break;
            case 'italic':
                insertAtCursor(textarea, '<em>', '</em>');
                break;
            case 'underline':
                insertAtCursor(textarea, '<u>', '</u>');
                break;
            case 'link':
                var url = prompt('URL:', 'https://');
                if (url) insertAtCursor(textarea, '<a href="' + url + '">', '</a>');
                break;
            case 'h2':
                insertAtCursor(textarea, '<h2>', '</h2>');
                break;
            case 'h3':
                insertAtCursor(textarea, '<h3>', '</h3>');
                break;
            case 'blockquote':
                insertAtCursor(textarea, '<blockquote>', '</blockquote>');
                break;
            case 'hr':
                insertAtCursor(textarea, '\n<hr>\n');
                break;
            case 'img':
                insertImage(textarea);
                break;
            case 'col2':
                insertColumns(textarea, 2);
                break;
            case 'col3':
                insertColumns(textarea, 3);
                break;
            case 'dropcap':
                insertAtCursor(textarea, '[dropcap]', '[/dropcap]');
                break;
            case 'ul':
                insertList(textarea, 'ul');
                break;
            case 'ol':
                insertList(textarea, 'ol');
                break;
            case 'preview':
                previewInNewTab(textarea);
                break;
        }
    }

    // --- Bind toolbar buttons ---
    // Called on DOMContentLoaded. Finds all .sc-toolbar containers
    // and wires their buttons to the associated textarea.
    function initToolbars() {
        var toolbars = document.querySelectorAll('.sc-toolbar');

        toolbars.forEach(function (toolbar) {
            var targetId = toolbar.getAttribute('data-target');
            var textarea = document.getElementById(targetId);
            if (!textarea) return;

            toolbar.querySelectorAll('[data-action]').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    execAction(this.getAttribute('data-action'), textarea);
                });
            });

            // --- Keyboard shortcuts (Ctrl+B, Ctrl+I, Ctrl+U) ---
            textarea.addEventListener('keydown', function (e) {
                if (!e.ctrlKey && !e.metaKey) return;

                var key = e.key.toLowerCase();
                if (key === 'b') {
                    e.preventDefault();
                    execAction('bold', textarea);
                } else if (key === 'i') {
                    e.preventDefault();
                    execAction('italic', textarea);
                } else if (key === 'u') {
                    e.preventDefault();
                    execAction('underline', textarea);
                }
            });
        });
    }

    // Fire when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initToolbars);
    } else {
        initToolbars();
    }
})();
