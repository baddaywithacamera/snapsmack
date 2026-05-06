/**
 * SNAPSMACK - Archive Filter Engine
 *
 * Unified taxonomy filter panel for the public archive page.
 * Combines categories, albums, and collections into a single
 * debounced multi-select panel with live text search.
 *
 * AND logic: every checked item must match for a photo to show.
 * Debounce: 400ms after last checkbox change before navigating.
 * URL format: ?f[]=cat:5&f[]=alb:3&f[]=col:2
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

(function () {
    'use strict';

    var DEBOUNCE_MS = 400;
    var _timer      = null;
    var _panel      = null;
    var _btn        = null;
    var _label      = null;

    // ── INIT ──────────────────────────────────────────────────────────────────
    function init() {
        _panel = document.getElementById('smack-archive-filter-panel');
        _btn   = document.getElementById('smack-archive-filter-btn');
        if (!_panel || !_btn) return;

        _label = _btn.querySelector('.saf-btn-label');

        // Toggle panel open/close
        _btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = _panel.classList.toggle('saf-panel--open');
            _btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                var input = document.getElementById('smack-archive-filter-search');
                if (input) setTimeout(function () { input.focus(); }, 50);
            }
        });

        // Close on outside click
        document.addEventListener('click', function (e) {
            if (!_panel.contains(e.target) && e.target !== _btn && !_btn.contains(e.target)) {
                closePanel();
            }
        });

        // Close on Escape
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closePanel();
        });

        // Live text search within the panel
        var filterInput = document.getElementById('smack-archive-filter-search');
        if (filterInput) {
            filterInput.addEventListener('input', function () {
                var q = this.value.trim().toLowerCase();
                var groups = _panel.querySelectorAll('.saf-group');
                groups.forEach(function (group) {
                    var anyVisible = false;
                    group.querySelectorAll('.saf-item').forEach(function (item) {
                        var text = (item.querySelector('.saf-label') || item).textContent.toLowerCase();
                        var show = !q || text.indexOf(q) !== -1;
                        item.style.display = show ? '' : 'none';
                        if (show) anyVisible = true;
                    });
                    // Hide the whole group header if nothing in it matches
                    var hdr = group.querySelector('.saf-group-header');
                    if (hdr) hdr.style.display = anyVisible ? '' : 'none';
                });
            });
        }

        // Wire checkboxes
        _panel.querySelectorAll('.saf-checkbox').forEach(function (cb) {
            cb.addEventListener('change', function () {
                updateButton();
                clearTimeout(_timer);
                _timer = setTimeout(applyFilter, DEBOUNCE_MS);
            });
        });

        updateButton();
    }

    // ── PANEL CLOSE ───────────────────────────────────────────────────────────
    function closePanel() {
        if (_panel) _panel.classList.remove('saf-panel--open');
        if (_btn)   _btn.setAttribute('aria-expanded', 'false');
    }

    // ── SELECTION COUNT ───────────────────────────────────────────────────────
    function getSelections() {
        var cats = [], albs = [], cols = [];
        if (!_panel) return { cats: cats, albs: albs, cols: cols };
        _panel.querySelectorAll('.saf-checkbox:checked').forEach(function (cb) {
            var type = cb.dataset.type;
            if (type === 'cat') cats.push(cb.value);
            else if (type === 'alb') albs.push(cb.value);
            else if (type === 'col') cols.push(cb.value);
        });
        return { cats: cats, albs: albs, cols: cols };
    }

    function updateButton() {
        var sel   = getSelections();
        var total = sel.cats.length + sel.albs.length + sel.cols.length;
        if (_label) _label.textContent = total === 0 ? 'FILTER' : total + ' SELECTED';
        if (_btn) _btn.classList.toggle('saf-btn--active', total > 0);
    }

    // ── NAVIGATE ──────────────────────────────────────────────────────────────
    function applyFilter() {
        var sel    = getSelections();
        var params = new URLSearchParams();

        // Preserve non-filter params (layout, text search, calendar)
        var current = new URLSearchParams(window.location.search);
        ['layout', 'q', 'date', 'from', 'to'].forEach(function (k) {
            if (current.has(k)) params.set(k, current.get(k));
        });

        // Append each selection as f[]=type:id
        sel.cats.forEach(function (id) { params.append('f[]', 'cat:' + id); });
        sel.albs.forEach(function (id) { params.append('f[]', 'alb:' + id); });
        sel.cols.forEach(function (id) { params.append('f[]', 'col:' + id); });

        var qs = params.toString();
        window.location.href = 'archive.php' + (qs ? '?' + qs : '');
    }

    // ── BOOT ──────────────────────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

}());
// ===== SNAPSMACK EOF =====
