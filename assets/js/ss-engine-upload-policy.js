/**
 * SnapSmack admin upload policy.
 * Reject HEIC/HEIF locally before a browser spends time sending a file the
 * server will refuse. The server-side 415 guard remains authoritative.
 *
 * SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical JS marker.
 */
(function () {
    'use strict';

    var MESSAGE = 'HEIC/HEIF images are not supported because they commonly rely on patent-encumbered HEVC technology. Convert the image to JPEG, PNG or WebP before uploading.';
    var HEIC_MIMES = [
        'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'
    ];

    function isHeic(file) {
        if (!file) return false;
        var name = String(file.name || '').trim();
        var type = String(file.type || '').toLowerCase();
        return /\.(?:heic|heif)$/i.test(name) || HEIC_MIMES.indexOf(type) !== -1;
    }

    function containsHeic(files) {
        return Array.prototype.some.call(files || [], isHeic);
    }

    function reject(event, input) {
        if (event) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
        if (input) input.value = '';
        window.alert(MESSAGE);
    }

    // Capture runs before page-specific preview/upload handlers.
    document.addEventListener('change', function (event) {
        var input = event.target;
        if (!input || input.type !== 'file' || !containsHeic(input.files)) return;
        reject(event, input);
    }, true);

    // Cover uploaders that consume DataTransfer.files without populating an input.
    document.addEventListener('drop', function (event) {
        var transfer = event.dataTransfer;
        if (!transfer || !containsHeic(transfer.files)) return;
        reject(event, null);
    }, true);
}());

// ===== SNAPSMACK EOF =====
