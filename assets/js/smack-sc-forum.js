/**
 * SMACK CENTRAL — Forum JS
 *
 * Shared utilities for sc-forum.php: emoji insertion, the role-change guard,
 * and inline install name editing. No dependencies — runs after DOM is ready.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
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
 * Guard the one rung that matters. Member, Power and Moderator are reversible
 * in the same two clicks; Admin can create boards and set other blogs' roles,
 * so it asks first. A dropdown is easy to land on by accident.
 */
function scfConfirmRole(form) {
    if (!form.role || form.role.value !== '3') return true;
    return confirm('Make ' + (form.dataset.domain || 'this blog')
        + ' a forum ADMIN?\n\n'
        + "Admins can create boards and change other blogs' roles.");
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
// ===== SNAPSMACK EOF =====
