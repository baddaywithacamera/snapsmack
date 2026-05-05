/**
 * SNAPSMACK - Cross-Post Engine
 *
 * Form validation and image picker interaction for the multisite cross-post page.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


function confirmCrossPost() {
    var checked = document.querySelectorAll('input[name="img_ids[]"]:checked');
    var spokes  = document.querySelectorAll('input[name="spoke_ids[]"]:checked');
    if (checked.length === 0) { alert('Select at least one post.'); return false; }
    if (spokes.length === 0)  { alert('Select at least one spoke.'); return false; }
    var status = document.querySelector('input[name="xp_status"]:checked').value;
    return confirm(
        'Cross-post ' + checked.length + ' post' + (checked.length > 1 ? 's' : '') +
        ' to ' + spokes.length + ' spoke' + (spokes.length > 1 ? 's' : '') +
        ' as ' + status.toUpperCase() + '?'
    );
}

document.querySelectorAll('.img-picker-cb').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var card = this.nextElementSibling;
        if (this.checked) {
            card.style.borderColor = 'var(--accent-primary, #fff)';
        } else {
            card.style.borderColor = 'var(--border, #333)';
        }
    });
});
// ===== SNAPSMACK EOF =====
