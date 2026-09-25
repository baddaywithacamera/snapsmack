/**
 * SNAPSMACK - ss-page-mosaics
 *
 * Moved verbatim out of a <script> block in smack-mosaics.php during the inline-JS
 * sweep. No logic was changed. Loaded from the same place in the page, so
 * it still runs at the same point relative to everything else.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

function deleteMosaic(id) {
    if (!confirm('Delete mosaic #' + id + '? Any [mosaic:' + id + '] shortcodes will stop rendering.')) return;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'smack-mosaics.php', true);
    xhr.setRequestHeader('Content-Type',     'application/x-www-form-urlencoded');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onload = function () { location.reload(); };
    xhr.send('action=delete_mosaic&mosaic_id=' + id);
}

// ===== SNAPSMACK EOF =====
