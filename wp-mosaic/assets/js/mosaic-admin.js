/**
 * WP Mosaic — Admin UI
 *
 * Handles the mosaic editor: image selection via WP Media Library,
 * drag-to-reorder, live preview, save/delete via AJAX.
 */

(function ($) {
    'use strict';

    var selectedIds = [];
    var imageCache  = {};  // id → {id, url, width, height, alt}

    // ── Init ─────────────────────────────────────────────────
    $(function () {
        var $selected = $('#wpm-selected');
        if (!$selected.length) {
            // List view — just wire up delete links
            initListView();
            return;
        }

        // Editor view
        var initial = $selected.data('ids');
        if (Array.isArray(initial) && initial.length) {
            selectedIds = initial.map(Number);
            loadAttachmentData(selectedIds, function () {
                renderSelected();
                updatePreview();
            });
        }

        // Sortable
        $selected.sortable({
            tolerance: 'pointer',
            update: function () {
                selectedIds = [];
                $selected.children('.wpm-thumb').each(function () {
                    selectedIds.push($(this).data('id'));
                });
                updatePreview();
            }
        });

        // Add Images button → WP Media Library
        $('#wpm-add-images').on('click', function (e) {
            e.preventDefault();
            var frame = wp.media({
                title:    'Select Images for Mosaic',
                library:  { type: 'image' },
                multiple: true,
                button:   { text: 'Add to Mosaic' }
            });
            frame.on('select', function () {
                var attachments = frame.state().get('selection').toJSON();
                attachments.forEach(function (att) {
                    if (selectedIds.indexOf(att.id) === -1) {
                        selectedIds.push(att.id);
                        cacheAttachment(att);
                    }
                });
                renderSelected();
                updatePreview();
            });
            frame.open();
        });

        // Save
        $('#wpm-save').on('click', function () {
            var $btn = $(this);
            var mosaicId = $btn.data('mosaic-id') || 0;
            var title = $('#wpm-title').val().trim() || 'Untitled Mosaic';
            var gap   = parseInt($('#wpm-gap').val(), 10) || 4;

            if (!selectedIds.length) {
                alert('Select at least one image.');
                return;
            }

            $btn.prop('disabled', true).text('Saving…');

            $.post(wpmData.ajaxUrl, {
                action:    'wpm_save_mosaic',
                nonce:     wpmData.nonce,
                mosaic_id: mosaicId,
                title:     title,
                image_ids: JSON.stringify(selectedIds),
                gap:       gap
            }, function (resp) {
                $btn.prop('disabled', false).text('Save Mosaic');
                if (resp.success) {
                    $btn.data('mosaic-id', resp.data.id);
                    $('#wpm-shortcode').html('<code>' + resp.data.shortcode + '</code>');
                    history.replaceState(null, '', 'admin.php?page=wp-mosaic&edit=' + resp.data.id);
                    // Brief flash to confirm
                    $btn.text('Saved!');
                    setTimeout(function () { $btn.text('Save Mosaic'); }, 1500);
                } else {
                    alert('Error: ' + (resp.data || 'Unknown'));
                }
            });
        });

        // Gap change → re-preview
        $('#wpm-gap').on('input', updatePreview);

        // Copy shortcode on click
        $(document).on('click', '#wpm-shortcode code', function () {
            var text = $(this).text();
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text);
            }
        });
    });

    // ── List view: delete handler ────────────────────────────
    function initListView() {
        $(document).on('click', '.wpm-delete', function (e) {
            e.preventDefault();
            var id = $(this).data('id');
            if (!confirm('Delete this mosaic? Any [mosaic id="' + id + '"] shortcodes will stop rendering.')) return;

            $.post(wpmData.ajaxUrl, {
                action:    'wpm_delete_mosaic',
                nonce:     wpmData.nonce,
                mosaic_id: id
            }, function () {
                location.reload();
            });
        });
    }

    // ── Load attachment data via WP REST ─────────────────────
    function loadAttachmentData(ids, callback) {
        if (!ids.length) { callback(); return; }

        // Batch fetch via WP REST API
        var loaded = 0;
        var total  = ids.length;

        ids.forEach(function (id) {
            if (imageCache[id]) {
                loaded++;
                if (loaded === total) callback();
                return;
            }

            wp.media.attachment(id).fetch().then(function (att) {
                cacheFromWPAttachment(att);
                loaded++;
                if (loaded === total) callback();
            }).fail(function () {
                loaded++;
                if (loaded === total) callback();
            });
        });
    }

    function cacheAttachment(att) {
        var url = att.sizes && att.sizes.large ? att.sizes.large.url : att.url;
        imageCache[att.id] = {
            id:     att.id,
            url:    url,
            thumb:  (att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : url),
            width:  att.width  || 800,
            height: att.height || 600,
            alt:    att.alt || att.title || ''
        };
    }

    function cacheFromWPAttachment(att) {
        var sizes = att.sizes || att.attributes && att.attributes.sizes || {};
        var url   = sizes.large ? sizes.large.url : (att.url || att.attributes.url);
        var thumb = sizes.thumbnail ? sizes.thumbnail.url : url;
        imageCache[att.id] = {
            id:     att.id,
            url:    url,
            thumb:  thumb,
            width:  att.width  || (att.attributes ? att.attributes.width : 800),
            height: att.height || (att.attributes ? att.attributes.height : 600),
            alt:    att.alt || att.title || ''
        };
    }

    // ── Render selected image thumbnails ─────────────────────
    function renderSelected() {
        var $container = $('#wpm-selected');
        $container.empty();

        if (!selectedIds.length) {
            $container.html('<p class="description">Click "+ Add Images" to select from the Media Library</p>');
            return;
        }

        selectedIds.forEach(function (id) {
            var img = imageCache[id];
            if (!img) return;

            var $thumb = $('<div class="wpm-thumb" data-id="' + id + '">' +
                '<img src="' + img.thumb + '" alt="">' +
                '<span class="wpm-thumb-remove" title="Remove">&times;</span>' +
                '</div>');

            $thumb.find('.wpm-thumb-remove').on('click', function (e) {
                e.stopPropagation();
                selectedIds = selectedIds.filter(function (sid) { return sid !== id; });
                renderSelected();
                updatePreview();
            });

            $container.append($thumb);
        });
    }

    // ── Update live preview ──────────────────────────────────
    function updatePreview() {
        var $preview = $('#wpm-preview');
        if (!selectedIds.length) {
            $preview.html('<p class="description">Add images to see preview</p>');
            $preview.removeAttr('data-mosaic');
            return;
        }

        var gap    = parseInt($('#wpm-gap').val(), 10) || 4;
        var images = [];

        selectedIds.forEach(function (id) {
            var img = imageCache[id];
            if (!img) return;
            images.push({
                src:    img.url,
                width:  img.width,
                height: img.height,
                alt:    img.alt,
                id:     id
            });
        });

        $preview.attr('data-mosaic', JSON.stringify(images));
        $preview.attr('data-gap', gap);

        if (window.WPMosaic) {
            window.WPMosaic.renderMosaic($preview[0]);
        }
    }

})(jQuery);
