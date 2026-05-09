/**
 * SNAPSMACK - Public help modal (0.7.80)
 *
 * F1 (or click the footer "?" link) opens a modal listing exactly the
 * keyboard shortcuts and controls available on the current page.
 *
 * Page-aware via DOM feature detection — only shows hints for controls that
 * actually exist. Same pattern as the admin help modal in ss-engine-comms.js
 * but ported to the public side and styled to match whatever skin is active
 * by reading CSS custom properties off documentElement.
 *
 * Triggers:
 *   F1 keydown
 *   Click on any element with [data-snap-help-trigger]
 *   window.snapPublicHelp.open()
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


(function () {
    'use strict';

    var modal = null;
    var isOpen = false;

    // Read theme colours from active skin's CSS variables, with sensible
    // dark/light fallbacks. Skins set --bg-primary and --text-primary by
    // convention; the calendar engine extends this fallback chain too.
    function getThemeColors() {
        var s = getComputedStyle(document.documentElement);
        var pickFirst = function (names, fb) {
            for (var i = 0; i < names.length; i++) {
                var v = s.getPropertyValue(names[i]).trim();
                if (v) return v;
            }
            return fb;
        };
        return {
            bg:     pickFirst(['--bg-primary', '--page-bg', '--bg'], '#0a0a0a'),
            text:   pickFirst(['--text-primary', '--text', '--text-main'], '#cccccc'),
            accent: pickFirst(['--accent-color', '--accent'], '#39FF14'),
            dim:    pickFirst(['--text-secondary', '--text-dim', '--dim'], '#777777'),
        };
    }

    function buildShortcutList() {
        var sliderPresent  = !!document.querySelector('.ss-slider');
        var commentsBlock  = document.getElementById('show-comments') !== null
                          || document.querySelector('.comments-section, #snap-comments') !== null;
        var downloadAvail  = document.querySelector('.snap-download-btn, [data-download]') !== null;
        var layoutToggle   = !!document.querySelector('.archive-layout-toggle');
        var calendarBtn    = !!document.querySelector('.archive-calendar-toggle');
        var sortToggle     = !!document.querySelector('.collections-sort-toggle');
        var searchField    = !!document.querySelector('input[type="search"], input[name="q"]');
        var lightboxAvail  = !!document.querySelector('[data-lightbox], .lightbox-trigger');

        var rows = [];

        // Image / slider navigation (single-image, slideshow, lightbox)
        if (document.querySelector('.image-stage, .single-image-page') ||
            sliderPresent || lightboxAvail) {
            rows.push(['LEFT', sliderPresent ? 'Previous slide' : 'Previous image']);
            rows.push(['RIGHT', sliderPresent ? 'Next slide' : 'Next image']);
            if (!sliderPresent) rows.push(['SPACE', 'Previous image']);
            rows.push(['[ 1 ]', 'Toggle info']);
            if (commentsBlock) rows.push(['[ 2 ]', 'Toggle comments']);
            if (downloadAvail) rows.push(['[ D ]', 'Download']);
        }

        // Archive page
        if (layoutToggle)  rows.push(['[ T ]', 'Thumbs layout']);
        if (layoutToggle)  rows.push(['[ M ]', 'Masonry layout']);
        if (calendarBtn)   rows.push(['[ C ]', 'Toggle calendar panel']);
        if (searchField)   rows.push(['/', 'Focus search']);

        // Collections index sort hint (no hotkeys but worth surfacing)
        if (sortToggle) {
            rows.push(['CLICK', 'Sort: Manual / A→Z / Newest / Oldest']);
        }

        // Universal
        rows.push(['[ F1 ]', 'Show this help']);
        rows.push(['[ ESC ]', 'Close overlays']);

        return rows;
    }

    function buildModal() {
        var c = getThemeColors();

        var backdrop = document.createElement('div');
        backdrop.id = 'snap-public-help-backdrop';
        backdrop.style.cssText =
            'position:fixed;inset:0;z-index:2147483647;display:none;' +
            'background:rgba(0,0,0,0.78);align-items:center;justify-content:center;cursor:pointer;';

        var panel = document.createElement('div');
        panel.id = 'snap-public-help-panel';
        panel.style.cssText =
            'background:' + c.bg + ';color:' + c.text + ';border:1px solid ' + c.dim + ';' +
            'border-radius:6px;padding:32px 36px;min-width:340px;max-width:520px;' +
            'box-shadow:0 20px 60px rgba(0,0,0,0.85);' +
            'font-family:"Courier Prime","Courier New",monospace;cursor:default;';
        panel.addEventListener('click', function (e) { e.stopPropagation(); });

        var title = document.createElement('h2');
        title.textContent = 'KEYBOARD & CONTROLS';
        title.style.cssText =
            'margin:0 0 18px;font-size:1.1rem;letter-spacing:2px;font-weight:700;' +
            'border-bottom:1px solid ' + c.dim + ';padding-bottom:10px;color:' + c.accent + ';';
        panel.appendChild(title);

        var rows = buildShortcutList();
        var grid = document.createElement('div');
        grid.style.cssText =
            'display:grid;grid-template-columns:auto 1fr;gap:8px 18px;' +
            'font-size:13px;line-height:1.5;';
        rows.forEach(function (row) {
            var k = document.createElement('strong');
            k.textContent = row[0];
            k.style.cssText = 'color:' + c.accent + ';font-weight:700;white-space:nowrap;';
            var v = document.createElement('span');
            v.textContent = row[1];
            v.style.color = c.text;
            grid.appendChild(k);
            grid.appendChild(v);
        });
        panel.appendChild(grid);

        // Optional admin-set "About this site" block.
        var aboutText = document.querySelector('meta[name="snap-help-about"]');
        if (aboutText && aboutText.content) {
            var about = document.createElement('p');
            about.textContent = aboutText.content;
            about.style.cssText =
                'margin:20px 0 0;padding-top:14px;font-size:12px;color:' + c.dim + ';' +
                'border-top:1px solid ' + c.dim + ';line-height:1.5;';
            panel.appendChild(about);
        }

        var hint = document.createElement('div');
        hint.textContent = 'PRESS ESC OR CLICK OUTSIDE TO CLOSE';
        hint.style.cssText =
            'margin-top:18px;font-size:10px;letter-spacing:1px;text-align:center;' +
            'color:' + c.dim + ';';
        panel.appendChild(hint);

        backdrop.appendChild(panel);
        backdrop.addEventListener('click', function (e) {
            if (e.target === backdrop) close();
        });

        document.body.appendChild(backdrop);
        return backdrop;
    }

    function open() {
        if (!modal) modal = buildModal();
        modal.style.display = 'flex';
        isOpen = true;
    }

    function close() {
        if (modal) modal.style.display = 'none';
        isOpen = false;
    }

    function toggle() {
        if (isOpen) close(); else open();
    }

    document.addEventListener('keydown', function (e) {
        // F1 — toggle help. Don't trigger when typing.
        var t = e.target;
        if (e.key === 'F1') {
            e.preventDefault();
            toggle();
            return;
        }
        if (e.key === 'Escape' && isOpen) {
            e.preventDefault();
            close();
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        // Wire footer help triggers (any element with data-snap-help-trigger).
        document.querySelectorAll('[data-snap-help-trigger]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                toggle();
            });
        });
    });

    window.snapPublicHelp = { open: open, close: close, toggle: toggle };
}());
// ===== SNAPSMACK EOF =====
