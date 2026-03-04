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
                    var action = this.getAttribute('data-action');

                    switch (action) {
                        case 'bold':
                            insertAtCursor(textarea, '<strong>', '</strong>');
                            break;
                        case 'italic':
                            insertAtCursor(textarea, '<em>', '</em>');
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
                        case 'preview':
                            previewInNewTab(textarea);
                            break;
                    }
                });
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
