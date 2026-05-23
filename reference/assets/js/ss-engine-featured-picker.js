/**
 * SNAPSMACK - Featured Image Picker Engine
 * ss-engine-featured-picker.js
 *
 * Shared modal picker used by smack-albums.php and smack-collections.php.
 * Exposes window.ssFeaturedPicker.attach({...}) for each preview block on
 * the page. The engine builds the modal DOM once on first open, then
 * reuses it.
 *
 * Server-side AJAX endpoint contract:
 *   GET <endpoint>&q=<search>&offset=<N>
 *   Response: { posts: [{id, title, thumb}, ...], hasMore: bool }
 *
 * attach() options:
 *   endpoint        AJAX URL that returns the posts response shape above
 *   previewEl       <div class="ssfp-preview"> element on the host page
 *   hiddenInputEl   hidden <input> the engine writes the chosen post id to
 *   baseUrl         BASE_URL for prefixing thumbnail paths
 *   initialThumb    optional URL of currently selected thumb (for first paint)
 *   initialTitle    optional title for the currently selected post
 *   onSelect(id, thumb, title)  optional — fired AFTER preview update
 *   onClear()                   optional — fired AFTER preview update
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


(function () {
    'use strict';

    // ── Modal singleton state ──────────────────────────────────────────────

    var _modal       = null;
    var _modalGrid   = null;
    var _modalSearch = null;
    var _modalLoad   = null;
    var _activeCfg   = null;   // attach() options for the open picker
    var _curQuery    = '';
    var _curOffset   = 0;
    var _hasMore     = false;
    var _loading     = false;
    var _searchTimer = null;

    // ── Modal DOM construction (lazy, once) ────────────────────────────────

    function buildModal() {
        _modal = document.createElement('div');
        _modal.className = 'ssfp-modal';
        _modal.setAttribute('role', 'dialog');
        _modal.setAttribute('aria-label', 'Select featured image');

        var dialog = document.createElement('div');
        dialog.className = 'ssfp-dialog';

        var header = document.createElement('div');
        header.className = 'ssfp-header';
        var title = document.createElement('span');
        title.className = 'ssfp-title';
        title.textContent = 'SELECT FEATURED IMAGE';
        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'ssfp-close';
        closeBtn.setAttribute('aria-label', 'Close');
        closeBtn.innerHTML = '&times;';
        closeBtn.addEventListener('click', closeModal);
        header.appendChild(title);
        header.appendChild(closeBtn);

        _modalSearch = document.createElement('input');
        _modalSearch.type = 'text';
        _modalSearch.className = 'ssfp-search';
        _modalSearch.placeholder = 'Search posts…';
        _modalSearch.addEventListener('input', onSearchInput);

        _modalGrid = document.createElement('div');
        _modalGrid.className = 'ssfp-grid';

        var loadWrap = document.createElement('div');
        loadWrap.className = 'ssfp-load-more-wrap';
        _modalLoad = document.createElement('button');
        _modalLoad.type = 'button';
        _modalLoad.className = 'ssfp-load-more';
        _modalLoad.textContent = 'LOAD MORE';
        _modalLoad.style.display = 'none';
        _modalLoad.addEventListener('click', onLoadMore);
        loadWrap.appendChild(_modalLoad);

        dialog.appendChild(header);
        dialog.appendChild(_modalSearch);
        dialog.appendChild(_modalGrid);
        dialog.appendChild(loadWrap);

        _modal.appendChild(dialog);
        _modal.addEventListener('click', function (e) {
            if (e.target === _modal) closeModal();
        });

        document.body.appendChild(_modal);
    }

    // ── Open / close ───────────────────────────────────────────────────────

    function openModal(cfg) {
        if (!_modal) buildModal();
        _activeCfg = cfg;
        _modalSearch.value = '';
        _curQuery  = '';
        _curOffset = 0;
        _hasMore   = false;
        _modalGrid.innerHTML = '';
        _modal.classList.add('ssfp-modal--open');
        loadPage();
    }

    function closeModal() {
        if (_modal) _modal.classList.remove('ssfp-modal--open');
        _activeCfg = null;
    }

    // ── Search debounce ────────────────────────────────────────────────────

    function onSearchInput() {
        clearTimeout(_searchTimer);
        _searchTimer = setTimeout(function () {
            _curQuery  = _modalSearch.value;
            _curOffset = 0;
            _modalGrid.innerHTML = '';
            loadPage();
        }, 200);
    }

    // ── AJAX load ──────────────────────────────────────────────────────────

    function loadPage() {
        if (!_activeCfg || _loading) return;
        _loading = true;
        if (_curOffset === 0) {
            _modalGrid.innerHTML =
                '<p class="ssfp-status">Loading…</p>';
        }
        _modalLoad.disabled = true;

        var url = _activeCfg.endpoint
                + (_activeCfg.endpoint.indexOf('?') >= 0 ? '&' : '?')
                + 'q='      + encodeURIComponent(_curQuery)
                + '&offset=' + encodeURIComponent(_curOffset);

        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function () {
            _loading = false;
            _modalLoad.disabled = false;
            if (xhr.status !== 200) {
                _modalGrid.innerHTML =
                    '<p class="ssfp-status">Could not load posts.</p>';
                return;
            }
            var data;
            try { data = JSON.parse(xhr.responseText); }
            catch (e) {
                _modalGrid.innerHTML =
                    '<p class="ssfp-status">Bad response from server.</p>';
                return;
            }
            renderPage(data);
        };
        xhr.onerror = function () {
            _loading = false;
            _modalLoad.disabled = false;
            _modalGrid.innerHTML =
                '<p class="ssfp-status">Network error.</p>';
        };
        xhr.send();
    }

    function renderPage(data) {
        var posts = (data && data.posts) || [];
        _hasMore  = !!(data && data.hasMore);

        // Replace "Loading…" with empty state on first page if no results
        if (_curOffset === 0 && posts.length === 0) {
            _modalGrid.innerHTML =
                '<p class="ssfp-status">No posts.</p>';
            _modalLoad.style.display = 'none';
            return;
        }

        // First page: clear the "Loading…" status
        if (_curOffset === 0) _modalGrid.innerHTML = '';

        var frag = document.createDocumentFragment();
        posts.forEach(function (p) {
            frag.appendChild(buildTile(p));
        });
        _modalGrid.appendChild(frag);

        _curOffset += posts.length;
        _modalLoad.style.display = _hasMore ? '' : 'none';
    }

    function buildTile(p) {
        var thumb = p.thumb || '';
        var src   = thumb ? _activeCfg.baseUrl + thumb.replace(/^\//, '') : '';

        var tile = document.createElement('div');
        tile.className = 'ssfp-tile';
        tile.dataset.id    = p.id;
        tile.dataset.src   = src;
        tile.dataset.title = p.title || '';
        tile.addEventListener('click', function () {
            applySelection(this.dataset.id, this.dataset.src, this.dataset.title);
        });

        if (src) {
            var img = document.createElement('img');
            img.src = src;
            img.loading = 'lazy';
            img.alt = p.title || '';
            tile.appendChild(img);
        } else {
            var label = document.createElement('div');
            label.className = 'ssfp-tile-fallback';
            label.textContent = p.title || '(untitled)';
            tile.appendChild(label);
        }
        return tile;
    }

    function onLoadMore() {
        if (_hasMore && !_loading) loadPage();
    }

    // ── Selection / preview rendering ──────────────────────────────────────

    function applySelection(id, thumb, title) {
        if (!_activeCfg) return;
        if (_activeCfg.hiddenInputEl) _activeCfg.hiddenInputEl.value = id;
        renderPreview(_activeCfg, thumb, title);
        var cfg = _activeCfg;
        closeModal();
        if (typeof cfg.onSelect === 'function') {
            cfg.onSelect(id, thumb, title);
        }
    }

    function clearSelection(cfg) {
        if (cfg.hiddenInputEl) cfg.hiddenInputEl.value = '0';
        renderPreview(cfg, null, null);
        if (typeof cfg.onClear === 'function') cfg.onClear();
    }

    function renderPreview(cfg, thumb, title) {
        var wrap = cfg.previewEl;
        if (!wrap) return;
        wrap.innerHTML = '';

        if (thumb) {
            var img = document.createElement('img');
            img.className = 'ssfp-preview-thumb';
            img.src = thumb;
            img.alt = 'Featured image';
            wrap.appendChild(img);

            if (title) {
                var span = document.createElement('span');
                span.className = 'ssfp-preview-title';
                span.textContent = title;
                wrap.appendChild(span);
            }

            wrap.appendChild(buildActions(cfg, true));
        } else {
            var empty = document.createElement('div');
            empty.className = 'ssfp-preview-empty';
            var lbl = document.createElement('span');
            lbl.className = 'ssfp-preview-empty-label';
            lbl.textContent = 'NO IMAGE';
            empty.appendChild(lbl);
            wrap.appendChild(empty);
            wrap.appendChild(buildActions(cfg, false));
        }
    }

    function buildActions(cfg, hasThumb) {
        var actions = document.createElement('div');
        actions.className = 'ssfp-preview-actions';

        var pickBtn = document.createElement('button');
        pickBtn.type = 'button';
        pickBtn.className = 'ssfp-btn';
        pickBtn.textContent = hasThumb ? 'CHANGE' : 'SELECT IMAGE';
        pickBtn.addEventListener('click', function () { openModal(cfg); });
        actions.appendChild(pickBtn);

        if (hasThumb) {
            var rmBtn = document.createElement('button');
            rmBtn.type = 'button';
            rmBtn.className = 'ssfp-btn ssfp-btn--remove';
            rmBtn.textContent = 'REMOVE';
            rmBtn.addEventListener('click', function () { clearSelection(cfg); });
            actions.appendChild(rmBtn);
        }
        return actions;
    }

    // ── Public API ─────────────────────────────────────────────────────────

    function attach(opts) {
        if (!opts || !opts.previewEl) return;
        // Initial render based on supplied initial state
        if (opts.initialThumb) {
            renderPreview(opts, opts.initialThumb, opts.initialTitle || '');
        } else {
            renderPreview(opts, null, null);
        }
    }

    window.ssFeaturedPicker = {
        attach: attach,
        open:   openModal,
        close:  closeModal,
    };

}());
// ===== SNAPSMACK EOF =====
