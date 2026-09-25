/**
 * SNAPSMACK - ss-page-forum
 *
 * Moved verbatim out of a <script> block in smack-forum.php during the inline-JS
 * sweep. No logic was changed. Loaded from the same place in the page, so
 * it still runs at the same point relative to everything else.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

function forumInsertEmoji(btn, emoji) {
    var form = btn.closest('form') || btn.closest('.lens-input-wrapper')?.closest('form');
    var ta = form ? form.querySelector('textarea') : btn.closest('.lens-input-wrapper')?.querySelector('textarea');
    if (!ta) return;
    var start = ta.selectionStart;
    var end   = ta.selectionEnd;
    var val   = ta.value;
    ta.value  = val.substring(0, start) + emoji + val.substring(end);
    ta.selectionStart = ta.selectionEnd = start + emoji.length;
    ta.focus();
}

// ===== SNAPSMACK EOF =====
