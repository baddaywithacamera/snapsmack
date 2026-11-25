// Select, arrange and preview photographs in the mosaic editor.
// Requires: ss-engine-mosaic.js, ss-engine-columns.js, ss-engine-rows.js
// SNAPSMACK_EOF_HEADER
// Last non-empty line must be the JavaScript SNAPSMACK EOF marker.
    (function () {
        var settings      = document.currentScript.dataset;
        var BASE          = settings.base;
        var mosaicId      = Number(settings.mosaicId);
        var selectedIds   = JSON.parse(settings.selectedIds);
        var focusPositions = JSON.parse(settings.focusPositions);
        var allAssets     = {};   // id → {id, asset_name, asset_path}
        var pickerOrder   = [];   // ids in the order the server returned them
        var lastPickIndex = null; // anchor for shift-click range selection
        var usedInMosaic  = {};   // id → "Mosaic title (#7)" for photos already placed
        var searchTimer   = null;
        var dragSrcIndex  = null;
        var pickerOpen    = false;

        // --- Bootstrap ---
        document.addEventListener('DOMContentLoaded', function () {
            loadAssets();
            document.getElementById('mosaic-gap').addEventListener('input', updatePreview);
            document.getElementById('mosaic-emphasis').addEventListener('change', updatePreview);
            document.getElementById('mosaic-layout').addEventListener('change', function () {
                syncLayoutControls();
                updatePreview();
            });
            syncLayoutControls();

            document.getElementById('picker-scope').addEventListener('change', loadAssets);
            document.getElementById('picker-hide-used').addEventListener('change', renderPickerGrid);
            // Debounced: the search runs server-side so it can reach past the
            // display cap, but not on every keystroke.
            document.getElementById('picker-search').addEventListener('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(loadAssets, 250);
            });
        });

        function loadAssets() {
            // "all", or "bucket:<postId>" — the post is carried in the value so
            // the dropdown can offer any post's bucket, not only the one this
            // builder happened to be opened from.
            var raw    = document.getElementById('picker-scope').value;
            var isBkt  = raw.indexOf('bucket:') === 0;
            var scope  = isBkt ? 'bucket' : 'all';
            var postId = isBkt ? parseInt(raw.split(':')[1], 10) : 0;
            var q      = document.getElementById('picker-search').value.trim();

            // keep: the photos already in this mosaic. The server returns them
            // whatever the scope or cap, so narrowing the picker can never blank
            // the tiles of photos this mosaic actually contains.
            ajax('list_assets', { scope: scope, post_id: postId, q: q, keep: JSON.stringify(selectedIds) }, function (resp) {
                pickerOrder  = [];
                lastPickIndex = null;   // grid order just changed; old anchor is meaningless
                usedInMosaic = resp.used || {};
                (resp.images || []).forEach(function (a) {
                    allAssets[a.id] = a;
                    pickerOrder.push(parseInt(a.id, 10));
                });
                // Known, so the strip and preview can draw them — but deliberately
                // NOT in pickerOrder, so the grid still shows only what the scope
                // and search asked for.
                (resp.extra || []).forEach(function (a) { allAssets[a.id] = a; });
                renderPickerCount(resp);
                renderPickerGrid();
                renderSelected();
                updatePreview();
            });
        }

        function renderPickerCount(resp) {
            var el = document.getElementById('picker-count');
            if (!el) return;
            var total = resp.total || 0;
            if (total === 0) {
                el.textContent = resp.scope === 'bucket'
                    ? 'This post has no photos in its bucket yet. Add them in the post editor, or switch to All gallery photos.'
                    : 'No photos match.';
                return;
            }
            // Never let a capped list look like the whole list.
            el.textContent = resp.capped
                ? 'Showing the ' + resp.shown + ' newest of ' + total + ' matching photos — search by name to reach the rest.'
                : 'Showing all ' + total + ' photo' + (total === 1 ? '' : 's') + '.';
        }

        // --- Picker toggle ---
        window.togglePicker = function () {
            var el = document.getElementById('asset-picker');
            pickerOpen = !pickerOpen;
            el.style.display = pickerOpen ? 'block' : 'none';
        };

        // --- Picker grid ---
        function renderPickerGrid() {
            var grid     = document.getElementById('asset-picker-grid');
            var hideUsed = document.getElementById('picker-hide-used').checked;
            var html     = '';
            var hidden   = 0;
            var webExts  = ['jpg','jpeg','png','gif','webp','avif','svg','bmp'];

            // pickerOrder, not Object.keys(allAssets): allAssets accumulates every
            // photo ever loaded this session so selected tiles survive a scope
            // change, but the GRID must only show the current scope's photos.
            pickerOrder.forEach(function (idNum) {
                var a = allAssets[idNum];
                if (!a) return;
                var id   = idNum;
                var ext  = (a.asset_path.split('.').pop() || '').toLowerCase();
                var sel  = selectedIds.indexOf(id) !== -1;
                var used = usedInMosaic[id];

                // A photo already in THIS mosaic is not "used elsewhere" — hiding
                // it would make deselecting it impossible.
                if (used && !sel && hideUsed) { hidden++; return; }

                var dim = (used && !sel) ? 'opacity:.42;' : '';
                html += '<div onclick="toggleAsset(event,' + id + ')" title="' + (used && !sel ? 'Already in ' + esc(used) : esc(a.asset_path.split('/').pop() || ''))
                      + '" style="cursor:pointer;position:relative;aspect-ratio:1;' + dim
                      + 'border:2px solid ' + (sel ? 'var(--accent)' : 'transparent') + ';border-radius:3px;overflow:hidden;background:var(--input-bg, #111);">';
                if (webExts.indexOf(ext) !== -1) {
                    // contain, not cover: a cropped square makes a portrait, a landscape
                    // and a square look identical — useless when you are picking photos
                    // for an arrangement that is entirely about their shape.
                    html += '<img src="' + esc(BASE + a.asset_path) + '" class="img-contain" loading="lazy">';
                } else {
                    html += '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--dim);font-size:10px;">' + esc(ext.toUpperCase()) + '</div>';
                }
                if (sel) {
                    html += '<div style="position:absolute;top:3px;right:3px;background:var(--accent);color:var(--text-muted, #111);'
                          + 'border-radius:50%;width:18px;height:18px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;">✓</div>';
                }
                // Say WHY it is greyed out. A dimmed tile with no reason on it is
                // indistinguishable from a broken thumbnail.
                if (used && !sel) {
                    html += '<div style="position:absolute;left:0;right:0;bottom:0;background:rgba(0,0,0,.72);color:var(--text, #fff);'
                          + 'font-size:9px;line-height:1.25;padding:2px 3px;text-align:center;">IN ' + esc(used) + '</div>';
                }
                html += '</div>';
            });

            if (!html) {
                grid.innerHTML = '<p style="color:var(--dim);font-size:12px;grid-column:1/-1;">'
                               + (hidden ? 'All ' + hidden + ' photo' + (hidden === 1 ? ' is' : 's are') + ' already used in another mosaic. Untick the box above to see them.'
                                         : 'No photos found.')
                               + '</p>';
                return;
            }
            if (hidden) {
                html += '<p style="color:var(--dim);font-size:11px;grid-column:1/-1;margin:6px 0 0;">'
                      + hidden + ' photo' + (hidden === 1 ? '' : 's') + ' hidden — already used in another mosaic.</p>';
            }
            grid.innerHTML = html;
        }

        function esc(s) {
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        window.toggleAsset = function (evt, id) {
            id = parseInt(id, 10);
            var idx = pickerOrder.indexOf(id);

            if (evt && evt.shiftKey && lastPickIndex !== null && idx !== -1) {
                // Shift-click grabs the whole run between your last pick and this
                // one, and only ADDS — a range drag can build the selection but
                // never silently strip it.
                var lo = Math.min(lastPickIndex, idx), hi = Math.max(lastPickIndex, idx);
                for (var k = lo; k <= hi; k++) {
                    var rid = pickerOrder[k];
                    if (selectedIds.indexOf(rid) === -1) selectedIds.push(rid);
                }
            } else {
                var i = selectedIds.indexOf(id);
                if (i === -1) selectedIds.push(id); else selectedIds.splice(i, 1);
            }
            lastPickIndex = idx;
            renderPickerGrid();
            renderSelected();
            updatePreview();
        };

        // --- Selected strip ---
        function renderSelected() {
            var el = document.getElementById('mosaic-selected');
            if (selectedIds.length === 0) {
                el.innerHTML = '<p class="dim" style="padding:12px;margin:0;font-size:12px;">No images selected.</p>';
                return;
            }
            var html = '';
            selectedIds.forEach(function (id, i) {
                var a = allAssets[id];
                if (!a) return;
                html += '<div class="mosaic-thumb-wrap" draggable="true" data-index="' + i + '"'
                      + ' ondragstart="dragStart(event,' + i + ')" ondragend="dragEnd()" ondragover="dragOver(event)" ondragleave="dragLeave(event)" ondrop="dragDrop(event,' + i + ')"'
                      + ' style="position:relative;width:72px;height:72px;border:1px solid var(--border);border-radius:3px;overflow:hidden;cursor:grab;flex-shrink:0;background:var(--input-bg, #111);">'
                      // draggable=false: without it the browser starts dragging the PHOTO
                      // instead of the tile, which is the classic "drag does nothing".
                      // object-fit:contain (not cover): a cropped square hides whether the
                      // photo is portrait, landscape or square — the one thing that matters
                      // when the arrangement setting is about exactly that.
                      + '<img src="' + esc(BASE + a.asset_path) + '" draggable="false" style="width:100%;height:100%;object-fit:contain;pointer-events:none;" loading="lazy">'
                      + '<div style="position:absolute;left:2px;bottom:2px;display:flex;gap:2px;">'
                      + '<button type="button" onclick="moveAsset(' + i + ',-1)" title="Move left" aria-label="Move image left"'
                      + ' style="background:rgba(0,0,0,.72);border:none;color:var(--text, #fff);cursor:pointer;width:20px;height:20px;padding:0;line-height:1;">&#8249;</button>'
                      + '<button type="button" onclick="moveAsset(' + i + ',1)" title="Move right" aria-label="Move image right"'
                      + ' style="background:rgba(0,0,0,.72);border:none;color:var(--text, #fff);cursor:pointer;width:20px;height:20px;padding:0;line-height:1;">&#8250;</button>'
                      + '</div>'
                      + '<button type="button" onclick="removeAsset(' + i + ')" title="Remove"'
                      + ' style="position:absolute;top:2px;right:2px;background:rgba(0,0,0,.65);border:none;color:var(--danger, #ff5555);cursor:pointer;'
                      + 'width:18px;height:18px;border-radius:50%;font-size:13px;line-height:1;padding:0;display:flex;align-items:center;justify-content:center;">×</button>'
                      + '</div>';
            });
            el.innerHTML = html;
        }

        window.removeAsset = function (index) {
            selectedIds.splice(index, 1);
            renderPickerGrid();
            renderSelected();
            updatePreview();
        };

        window.moveAsset = function (index, direction) {
            var target = index + direction;
            if (target < 0 || target >= selectedIds.length) return;
            var moved = selectedIds.splice(index, 1)[0];
            selectedIds.splice(target, 0, moved);
            renderSelected();
            updatePreview();
        };

        // --- Drag reorder ---
        // Drag feedback. Previously the reorder worked but looked identical to a
        // failed drag: nothing dimmed, nothing marked where the tile would land.
        function clearDropMarks() {
            var t = document.querySelectorAll('.mosaic-thumb-wrap');
            for (var i = 0; i < t.length; i++) {
                t[i].style.outline = '';
                t[i].style.opacity = '';
            }
        }
        window.dragStart = function (e, i) {
            dragSrcIndex = i;
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', String(i)); } catch (err) {}
            var src = document.querySelector('.mosaic-thumb-wrap[data-index="' + i + '"]');
            if (src) src.style.opacity = '.35';
        };
        window.dragEnd   = function () { dragSrcIndex = null; clearDropMarks(); };
        window.dragOver  = function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            var tile = e.target && e.target.closest ? e.target.closest('.mosaic-thumb-wrap') : null;
            if (tile && String(tile.getAttribute('data-index')) !== String(dragSrcIndex)) {
                tile.style.outline = '2px solid var(--accent)';
            }
        };
        window.dragLeave = function (e) {
            var tile = e.target && e.target.closest ? e.target.closest('.mosaic-thumb-wrap') : null;
            if (tile) tile.style.outline = '';
        };
        // Drop on empty space in the strip = move to the end.
        window.dragDropEnd = function (e) {
            if (e.target && e.target.closest && e.target.closest('.mosaic-thumb-wrap')) return;
            e.preventDefault();
            if (dragSrcIndex === null) return;
            var moved = selectedIds.splice(dragSrcIndex, 1)[0];
            selectedIds.push(moved);
            dragSrcIndex = null;
            clearDropMarks();
            renderSelected();
            updatePreview();
        };
        window.dragDrop  = function (e, target) {
            e.preventDefault();
            e.stopPropagation();   // don't also fire the container's "move to end"
            clearDropMarks();
            if (dragSrcIndex === null || dragSrcIndex === target) { dragSrcIndex = null; return; }
            var moved = selectedIds.splice(dragSrcIndex, 1)[0];
            selectedIds.splice(target, 0, moved);
            dragSrcIndex = null;
            renderSelected();
            updatePreview();
        };

        function focalValue(value) {
            value = parseFloat(value);
            return isFinite(value) ? Math.max(0, Math.min(100, value)) : 50;
        }


        // --- Live preview ---
        function updatePreview() {
            var container = document.getElementById('mosaic-preview');
            if (selectedIds.length === 0) {
                container.innerHTML = '<p class="dim" style="text-align:center;padding:20px 0;margin:0;">Add images to see preview.</p>';
                container.removeAttribute('data-mosaic');
                return;
            }

            var gap    = parseInt(document.getElementById('mosaic-gap').value, 10);
            if (!Number.isFinite(gap)) gap = 4;
            var images = [];
            selectedIds.forEach(function (id) {
                var a = allAssets[id];
                var focus = focusPositions[id] || { x: 50, y: 50 };
                if (a) images.push({
                    src: BASE + a.asset_path,
                    width: 800,
                    height: 600,
                    alt: a.asset_name,
                    id: id,
                    focusX: focalValue(focus.x),
                    focusY: focalValue(focus.y)
                });
            });

            // Preload to get real dimensions, then render
            var loaded = 0;
            images.forEach(function (img, idx) {
                var t   = new Image();
                t.onload = t.onerror = function () {
                    if (t.naturalWidth) {
                        images[idx].width = t.naturalWidth;
                        allAssets[img.id].naturalWidth = t.naturalWidth;
                    }
                    if (t.naturalHeight) {
                        images[idx].height = t.naturalHeight;
                        allAssets[img.id].naturalHeight = t.naturalHeight;
                    }
                    if (++loaded === images.length) renderWithData(images, gap, container);
                };
                t.src = img.src;
            });
        }

        function currentLayout() {
            var s = document.getElementById('mosaic-layout');
            return s ? s.value : 'asymmetric';
        }

        function renderWithData(images, gap, container) {
            var emphSel = document.getElementById('mosaic-emphasis');
            var layout  = currentLayout();

            // Reset anything the previous layout left behind, or a switch leaves a
            // half-rendered block from the old engine underneath the new one.
            container.removeAttribute('style');
            container.className = (layout === 'asymmetric') ? 'snap-mosaic'
                                : (layout === 'rows')   ? 'ss-scroll-wall snap-mosaic-wall'
                                : (layout === 'square') ? 'ss-square-wall snap-mosaic-wall'
                                                        : 'ss-masonry snap-mosaic-wall';

            if (layout === 'asymmetric') {
                container.innerHTML = '';
                container.setAttribute('data-mosaic', JSON.stringify(images));
                container.setAttribute('data-gap',    gap);
                // The preview must pass the SAME arrangement the published block will
                // carry, or it previews a layout the essay will never render.
                container.setAttribute('data-emphasis', emphSel ? emphSel.value : 'natural');
                if (window.SnapMosaic) {
                    window.SnapMosaic.renderMosaic(container);
                    bindCropPanning(container);
                }
                return;
            }

            // Wall layouts: same markup core/parser.php emits, so the preview and the
            // published essay hand the engines identical input. Shape comes from
            // data-w/data-h, never the loaded image.
            container.removeAttribute('data-mosaic');
            container.removeAttribute('data-emphasis');
            container.style.setProperty('--ss-gap', gap + 'px');
            if (layout === 'masonry') container.style.setProperty('--ss-cols', '3');
            if (layout === 'one-column') container.style.setProperty('--ss-cols', '1');
            var html = '';
            images.forEach(function (im) {
                var dims = (im.width && im.height)
                    ? ' data-w="' + im.width + '" data-h="' + im.height + '"' : '';
                html += '<a class="ss-masonry-item" href="' + esc(im.full || im.src) + '">'
                      + '<img src="' + esc(im.src) + '" alt=""' + dims + ' loading="lazy"></a>';
            });
            container.innerHTML = html;

            // Square is native CSS Grid — no engine call. The other two relayout.
            if (['columns', 'masonry', 'one-column'].includes(layout) && window.SSColumns) {
                window.SSColumns.init(container);
            }
            if (layout === 'rows'    && window.SSRows)    window.SSRows.init(container);
        }

        function syncLayoutControls() {
            var wrap = document.getElementById('emphasis-wrap');
            if (wrap) wrap.style.display = (currentLayout() === 'asymmetric') ? '' : 'none';
        }

        // Same focal-point drag model used by the GRAMOFSMACK square crop.
        // Movement is enabled only on an axis where object-fit:cover overflows.
        function bindCropPanning(container) {
            container.querySelectorAll('.mosaic-item img').forEach(function (image) {
                var frame = image.parentElement;
                var id = image.getAttribute('data-asset-id');
                var asset = allAssets[id];
                if (!asset) return;

                var scale = Math.max(
                    frame.clientWidth / asset.naturalWidth,
                    frame.clientHeight / asset.naturalHeight
                );
                var rangeX = asset.naturalWidth * scale - frame.clientWidth;
                var rangeY = asset.naturalHeight * scale - frame.clientHeight;
                if (rangeX <= 1 && rangeY <= 1) return;

                image.style.cursor = 'grab';
                image.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    image.style.cursor = 'grabbing';

                    var focus = focusPositions[id] || { x: 50, y: 50 };
                    var lastX = e.clientX;
                    var lastY = e.clientY;

                    function move(ev) {
                        var dx = ev.clientX - lastX;
                        var dy = ev.clientY - lastY;
                        lastX = ev.clientX;
                        lastY = ev.clientY;

                        if (rangeX > 1) focus.x = Math.max(0, Math.min(100, focus.x - (dx / rangeX) * 100));
                        if (rangeY > 1) focus.y = Math.max(0, Math.min(100, focus.y - (dy / rangeY) * 100));
                        focusPositions[id] = focus;
                        image.style.objectPosition = focus.x + '% ' + focus.y + '%';
                    }

                    function up() {
                        image.style.cursor = 'grab';
                        document.removeEventListener('mousemove', move);
                        document.removeEventListener('mouseup', up);
                    }

                    document.addEventListener('mousemove', move);
                    document.addEventListener('mouseup', up);
                });
            });
        }

        // --- Save ---
        window.saveMosaic = function () {
            var title = (document.getElementById('mosaic-title').value.trim() || 'Untitled Mosaic');
            var gap   = parseInt(document.getElementById('mosaic-gap').value, 10) || 4;
            if (selectedIds.length === 0) { alert('Select at least one image.'); return; }

            ajax('save_mosaic', {
                mosaic_id: mosaicId,
                title:     title,
                asset_ids: JSON.stringify(selectedIds),
                focus_positions: JSON.stringify(focusPositions),
                gap:       gap,
                emphasis:  (document.getElementById('mosaic-emphasis') || {}).value || 'natural',
                layout:    (document.getElementById('mosaic-layout') || {}).value || 'asymmetric'
            }, function (resp) {
                if (resp.ok) {
                    mosaicId = resp.id;
                    document.getElementById('mosaic-shortcode').textContent = resp.shortcode;
                    history.replaceState(null, '', 'smack-mosaics.php?edit=' + resp.id);
                } else {
                    alert('Error: ' + (resp.error || 'Unknown error'));
                }
            });
        };

        // --- XHR helper ---
        function ajax(action, data, cb) {
            data.action = action;
            var body = Object.keys(data).map(function (k) {
                return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
            }).join('&');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'smack-mosaics.php', true);
            xhr.setRequestHeader('Content-Type',  'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function () { if (xhr.status === 200) cb(JSON.parse(xhr.responseText)); };
            xhr.send(body);
        }

    }());
// ===== SNAPSMACK EOF =====
