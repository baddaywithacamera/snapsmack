/**
 * SNAPSMACK — Shortcode Toolbar
 * Alpha v0.7.5
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

    // --- Link dialog: modal with URL, text, target, rel options ---
    function insertLink(textarea) {
        // Capture selected text before opening the dialog
        var selStart = textarea.selectionStart;
        var selEnd   = textarea.selectionEnd;
        var selected = textarea.value.substring(selStart, selEnd);

        // Remove any existing dialog
        var existing = document.getElementById('sc-link-dialog-overlay');
        if (existing) existing.remove();

        // Build overlay + dialog
        var overlay = document.createElement('div');
        overlay.id = 'sc-link-dialog-overlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;';

        var dialog = document.createElement('div');
        dialog.style.cssText = 'background:#1a1a1a;border:1px solid #333;border-radius:6px;padding:24px 28px;min-width:380px;max-width:480px;box-shadow:0 8px 32px rgba(0,0,0,0.6);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#ccc;';

        dialog.innerHTML =
            '<div style="font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:1px;margin-bottom:18px;color:#fff;">Insert Link</div>' +

            '<label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;color:#888;">URL</label>' +
            '<input id="sc-link-url" type="url" value="https://" style="width:100%;padding:8px 10px;background:#111;border:1px solid #333;border-radius:4px;color:#fff;font-size:14px;box-sizing:border-box;margin-bottom:14px;">' +

            '<label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;color:#888;">Link Text</label>' +
            '<input id="sc-link-text" type="text" value="' + selected.replace(/"/g, '&quot;') + '" placeholder="Selected text or type here" style="width:100%;padding:8px 10px;background:#111;border:1px solid #333;border-radius:4px;color:#fff;font-size:14px;box-sizing:border-box;margin-bottom:14px;">' +

            '<div style="display:flex;gap:20px;margin-bottom:20px;">' +
                '<label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;color:#bbb;">' +
                    '<input id="sc-link-newtab" type="checkbox" checked style="accent-color:#4a9eff;"> Open in new tab' +
                '</label>' +
                '<label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;color:#bbb;">' +
                    '<input id="sc-link-nofollow" type="checkbox" style="accent-color:#4a9eff;"> Nofollow' +
                '</label>' +
            '</div>' +

            '<div style="display:flex;gap:10px;justify-content:flex-end;">' +
                '<button id="sc-link-cancel" type="button" style="padding:8px 18px;background:#333;border:1px solid #444;border-radius:4px;color:#ccc;cursor:pointer;font-size:13px;">Cancel</button>' +
                '<button id="sc-link-insert" type="button" style="padding:8px 18px;background:#4a9eff;border:none;border-radius:4px;color:#fff;cursor:pointer;font-weight:600;font-size:13px;">Insert Link</button>' +
            '</div>';

        overlay.appendChild(dialog);
        document.body.appendChild(overlay);

        // Focus URL field
        var urlInput = document.getElementById('sc-link-url');
        urlInput.focus();
        urlInput.select();

        // Close helper
        function closeDialog() {
            overlay.remove();
            textarea.focus();
        }

        // Cancel
        document.getElementById('sc-link-cancel').addEventListener('click', closeDialog);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeDialog();
        });

        // Escape key
        function onEscape(e) {
            if (e.key === 'Escape') {
                closeDialog();
                document.removeEventListener('keydown', onEscape);
            }
        }
        document.addEventListener('keydown', onEscape);

        // Insert
        function doInsert() {
            var url     = document.getElementById('sc-link-url').value.trim();
            var text    = document.getElementById('sc-link-text').value.trim();
            var newTab  = document.getElementById('sc-link-newtab').checked;
            var nofollow = document.getElementById('sc-link-nofollow').checked;

            if (!url || url === 'https://') {
                urlInput.style.borderColor = '#ff4444';
                urlInput.focus();
                return;
            }

            // Build the <a> tag
            var tag = '<a href="' + url + '"';
            if (newTab)   tag += ' target="_blank"';

            // Build rel attribute
            var rels = [];
            if (newTab)   rels.push('noopener');
            if (nofollow) rels.push('nofollow');
            if (rels.length > 0) tag += ' rel="' + rels.join(' ') + '"';

            tag += '>';

            var linkText = text || selected || 'link text';
            var closing  = '</a>';

            document.removeEventListener('keydown', onEscape);
            overlay.remove();

            // Restore selection and insert
            textarea.selectionStart = selStart;
            textarea.selectionEnd   = selEnd;
            insertAtCursor(textarea, tag + linkText + closing);
        }

        document.getElementById('sc-link-insert').addEventListener('click', doInsert);

        // Enter key submits from either input
        urlInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); doInsert(); }
        });
        document.getElementById('sc-link-text').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); doInsert(); }
        });
    }

    // --- Image shortcode: open asset picker if available, else fallback prompts ---
    function insertImage(textarea) {
        if (typeof window.ssOpenAssetPicker === 'function') {
            window.ssOpenAssetPicker('shortcode', textarea);
        } else {
            // Fallback for pages that don't load the picker (shouldn't happen)
            var id = prompt('Image ID (from Media Library):');
            if (!id || !id.trim()) return;
            var size  = prompt('Size — full, wall, or small:', 'full') || 'full';
            var align = prompt('Align — center, left, or right:', 'center') || 'center';
            var tag   = '[img:' + id.trim() + '|' + size.trim() + '|' + align.trim() + ']';
            insertAtCursor(textarea, tag);
        }
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
                insertLink(textarea);
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
            case 'spacer':
                var px = prompt('Spacer height in pixels (1–100):', '20');
                if (px !== null) {
                    px = parseInt(px, 10);
                    if (px >= 1 && px <= 100) {
                        insertAtCursor(textarea, '[spacer:' + px + ']');
                    } else {
                        alert('Enter a number between 1 and 100.');
                    }
                }
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
                } else if (key === 'k') {
                    e.preventDefault();
                    execAction('link', textarea);
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
