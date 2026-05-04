/**
 * SMACK CENTRAL — Forum JS
 *
 * Shared utilities for sc-forum.php: emoji insertion and inline install
 * name editing. No dependencies — runs after DOM is ready.
 */

'use strict';

/**
 * Insert an emoji at the cursor position in the nearest <textarea>.
 * Works inside both the reply composer and the new-thread form.
 */
function scfInsertEmoji(btn, emoji) {
    var form = btn.closest('form');
    var ta   = form ? form.querySelector('textarea') : null;
    if (!ta) return;
    var start  = ta.selectionStart;
    var end    = ta.selectionEnd;
    ta.value   = ta.value.substring(0, start) + emoji + ta.value.substring(end);
    ta.selectionStart = ta.selectionEnd = start + emoji.length;
    ta.focus();
}

/**
 * Inline rename for install display names.
 * Prompts for a new name, then submits the hidden rename form.
 */
function scfEditInstallName(el, id, current) {
    var name = prompt('Display name for this install:', current);
    if (name === null) return;
    name = name.trim();
    if (!name) return;
    document.getElementById('scf-rename-id').value  = id;
    document.getElementById('scf-rename-val').value = name;
    document.getElementById('scf-rename-form').submit();
}
// EOF
