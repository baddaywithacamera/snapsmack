/**
 * SNAPSMACK - Public help modal (0.7.80)
 *
 * F1 (or click the footer "?" link) opens a modal listing exactly the
 * keyboard shortcuts and controls available on the current page.
 *
 * The keyboard rows are REPORTED BY ss-engine-comms.js, the engine that
 * binds those keys, through window.snapKeyboard.shortcuts(). This file used
 * to re-derive them with DOM probes of its own and the two drifted, so keys
 * that worked stopped being listed. Archive-page rows are still probed here,
 * because those controls belong to other engines and the presence of the
 * control is itself the condition.
 *
 * Colours follow the skin: the conventional CSS custom properties when a
 * skin sets them, otherwise the background the page actually renders, with
 * text and accent derived from its luminance so a light skin gets a light
 * panel. Rebuilt on every open, never cached.
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

    // Guard: bail if another instance of this script already ran.
    if (window.snapPublicHelp) return;

    var modal = null;
    var isOpen = false;

    // --- THEME ---
    //
    // This used to read --bg-primary / --text-primary and fall back to a
    // hardcoded near-black when they were absent. Measured on 30 October
    // 2026: only 13 of the 31 skins set --bg-primary, so 18 of them got the
    // dark fallback regardless of how they actually look -- and two of those
    // are light. 52-CARD-PICKUP is #F5F0E8 and SHOW-N-TELL is #ffffff, so
    // both were handed a black panel on a pale page.
    //
    // Eight more name their background in a bespoke variable of their own
    // (--chap-bg, --wall-bg, --pfd-canvas, --rg-bg-page, --sl-bg,
    // --stanley-body-bg, --pg-outer-bg, --bg-page). Extending the name list
    // would have fixed those eight and broken on the ninth, so it does not
    // guess names any more: a skin that states the conventional variables
    // still wins, and otherwise this reads the background the page ACTUALLY
    // renders and derives a readable palette from its luminance. That covers
    // all 31 and anything added later.

    function parseColor(value) {
        if (!value) return null;
        var v = String(value).trim();

        var m = v.match(/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:\s*[,/]\s*([\d.]+%?))?/i);
        if (m) {
            var a = 1;
            if (m[4] !== undefined) {
                a = m[4].indexOf('%') > -1 ? parseFloat(m[4]) / 100 : parseFloat(m[4]);
            }
            return { r: +m[1], g: +m[2], b: +m[3], a: a };
        }

        m = v.match(/^#([0-9a-f]+)$/i);
        if (m) {
            var h = m[1];
            if (h.length === 3 || h.length === 4) {
                h = h.split('').map(function (x) { return x + x; }).join('');
            }
            if (h.length !== 6 && h.length !== 8) return null;
            return {
                r: parseInt(h.slice(0, 2), 16),
                g: parseInt(h.slice(2, 4), 16),
                b: parseInt(h.slice(4, 6), 16),
                a: h.length === 8 ? parseInt(h.slice(6, 8), 16) / 255 : 1
            };
        }

        // A named colour, or any syntax this function does not know. Let the
        // browser resolve it rather than maintaining a colour table.
        var probe = document.createElement('span');
        probe.style.cssText = 'display:none;color:' + v;
        document.body.appendChild(probe);
        var resolved = getComputedStyle(probe).color;
        document.body.removeChild(probe);
        if (!resolved || resolved === v) return null;
        return parseColor(resolved);
    }

    // WCAG relative luminance.
    function luminance(c) {
        var channel = function (x) {
            x = x / 255;
            return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
        };
        return 0.2126 * channel(c.r) + 0.7152 * channel(c.g) + 0.0722 * channel(c.b);
    }

    function contrast(a, b) {
        var la = luminance(a), lb = luminance(b);
        return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
    }

    function rgb(c) {
        return 'rgb(' + Math.round(c.r) + ',' + Math.round(c.g) + ',' + Math.round(c.b) + ')';
    }

    // The colour a viewer actually sees behind the page. body is often
    // transparent with html carrying the fill, so walk up until something
    // is opaque enough to count.
    //
    // When NOTHING in the chain is opaque the page is painted on the
    // browser's default canvas, which is white -- so that is what the viewer
    // sees and what this returns. Measured 30 October 2026: GLIDE,
    // 50-SHADES-OF-NOAH-GREY, IMPACT-PRINTER and NEW-HORIZON set no body or
    // html background at all, so they render white. Falling back to a dark
    // colour here put a black panel on all four.
    function renderedBackground() {
        var el = document.body;
        while (el) {
            var c = parseColor(getComputedStyle(el).backgroundColor);
            if (c && c.a > 0.5) return c;
            el = el.parentElement;
        }
        return { r: 255, g: 255, b: 255, a: 1 };
    }

    function getThemeColors() {
        var s = getComputedStyle(document.documentElement);
        var pickFirst = function (names) {
            for (var i = 0; i < names.length; i++) {
                var v = s.getPropertyValue(names[i]).trim();
                if (v) return v;
            }
            return '';
        };

        // renderedBackground() always answers, so there is no third fallback
        // and no hardcoded colour standing in for a page nobody looked at.
        var bg = parseColor(pickFirst(['--bg-primary', '--page-bg', '--bg']))
              || renderedBackground();

        var dark = luminance(bg) < 0.4;

        // A skin's own text colour is used only if it is legible against the
        // background we resolved. Taking the variable on trust is how a white
        // panel ended up with #cccccc text.
        var text = parseColor(pickFirst(['--text-primary', '--text', '--text-main']));
        if (!text || contrast(text, bg) < 4) {
            text = dark ? { r: 235, g: 235, b: 235, a: 1 } : { r: 26, g: 26, b: 26, a: 1 };
        }

        var dim = parseColor(pickFirst(['--text-secondary', '--text-dim', '--dim']));
        if (!dim || contrast(dim, bg) < 2) {
            dim = dark ? { r: 119, g: 119, b: 119, a: 1 } : { r: 90, g: 90, b: 90, a: 1 };
        }

        // #39FF14 on a dark panel is the established look and is kept exactly.
        // It fails badly on a pale one, so a light skin that offers no usable
        // accent gets a dark green instead of an unreadable neon.
        var accent = parseColor(pickFirst(['--accent-color', '--accent']));
        if (!accent || contrast(accent, bg) < 3) {
            accent = dark ? { r: 57, g: 255, b: 20, a: 1 } : { r: 10, g: 107, b: 45, a: 1 };
        }

        return {
            bg: rgb(bg),
            text: rgb(text),
            accent: rgb(accent),
            dim: rgb(dim),
            dark: dark,
            // A black veil over a pale page reads as a fault rather than a
            // modal, so the scrim follows the page too.
            scrim: dark ? 'rgba(0,0,0,0.78)' : 'rgba(0,0,0,0.45)',
            shadow: dark ? '0 20px 60px rgba(0,0,0,0.85)' : '0 20px 60px rgba(0,0,0,0.25)'
        };
    }

    // --- THE LIST ---
    //
    // The keyboard rows come from ss-engine-comms.js, which is the engine
    // that binds them. This file used to re-derive them from its own DOM
    // probes, and the two drifted apart: the handler learned to read
    // rel="prev"/rel="next" and #show-details, while these probes went on
    // demanding #snap-nav-data and window.smackdown. The keys kept working
    // and the rows quietly disappeared.
    //
    // The archive rows below are different and stay here: those controls
    // belong to other engines, and the presence of the control IS the
    // condition, so probing for the button is reading the real thing rather
    // than guessing at it.

    function buildShortcutList() {
        var rows = [];

        if (window.snapKeyboard && window.snapKeyboard.shortcuts) {
            rows = window.snapKeyboard.shortcuts().slice();
        }

        var layoutToggle = !!document.querySelector('.archive-layout-toggle');
        var calendarBtn  = !!document.querySelector('.archive-calendar-toggle');
        var sortToggle   = !!document.querySelector('.collections-sort-toggle');
        var searchField  = !!document.querySelector('input[type="search"], input[name="q"]');

        // Archive page controls, inserted before the universal F1/ESC pair so
        // those two stay last however many rows appear above them.
        var extra = [];
        if (layoutToggle) extra.push(['T', 'Thumbs layout']);
        if (layoutToggle) extra.push(['M', 'Masonry layout']);
        if (calendarBtn)  extra.push(['C', 'Toggle calendar panel']);
        if (searchField)  extra.push(['/', 'Focus search']);
        if (sortToggle)   extra.push(['CLICK', 'Sort: Manual / A→Z / Newest / Oldest']);

        if (extra.length) {
            var tail = 0;
            for (var i = rows.length - 1; i >= 0; i--) {
                if (rows[i][0] === 'F1' || rows[i][0] === 'ESC') tail++;
                else break;
            }
            rows = rows.slice(0, rows.length - tail)
                       .concat(extra, rows.slice(rows.length - tail));
        }

        // The comms engine is declared for every skin, so an empty list means
        // the page genuinely binds nothing (a wall canvas swallows the keys)
        // or that engine failed to load. Say so rather than show a bare box.
        if (!rows.length) {
            rows.push(['--', 'No keyboard shortcuts on this page']);
        }

        return rows;
    }

    function buildModal() {
        var c = getThemeColors();

        var backdrop = document.createElement('div');
        backdrop.id = 'snap-public-help-backdrop';
        backdrop.style.cssText =
            'position:fixed;inset:0;z-index:2147483647;display:none;' +
            'background:' + c.scrim + ';align-items:center;justify-content:center;cursor:pointer;';

        var panel = document.createElement('div');
        panel.id = 'snap-public-help-panel';
        panel.style.cssText =
            'background:' + c.bg + ';color:' + c.text + ';border:1px solid ' + c.dim + ';' +
            'border-radius:6px;padding:32px 36px;min-width:340px;max-width:520px;' +
            'box-shadow:' + c.shadow + ';' +
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
        grid.id = 'snap-public-help-rows';
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
        // Rebuilt every time, not cached. The old version built the panel on
        // first open and kept it: a skin whose footer or lightbox engine
        // loaded after the first F1 press was then stuck with the list and
        // the colours from that moment for the rest of the page view.
        if (modal && modal.parentNode) modal.parentNode.removeChild(modal);
        modal = buildModal();
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
        // F1 is handled solely by the comms engine (ss-engine-comms.js), which
        // delegates to snapPublicHelp.toggle() when this engine is loaded.
        // Binding F1 here too made BOTH listeners fire on one press — open then
        // immediately close — so the help panel appeared to do nothing.
        // Escape-to-close stays here.
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
