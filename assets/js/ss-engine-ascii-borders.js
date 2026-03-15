/**
 * SNAPSMACK - ASCII Border Engine
 * Alpha v0.7.3a
 *
 * Generates text-character borders around images using configurable styles.
 * Measures rendered dimensions and calculates character fit without stretching.
 */

(function () {
    'use strict';

    // --- CHARACTER MEASUREMENT ---
    // Probe the font metrics to determine character width and height
    function probeCharSize(container) {
        var probe = document.createElement('span');
        probe.style.cssText =
            'position:absolute;visibility:hidden;white-space:pre;' +
            'font-family:DotMatrix,"Courier New",monospace;' +
            'font-size:14px;letter-spacing:1px;line-height:1;';
        probe.textContent = '--------------------';
        container.appendChild(probe);
        var w = probe.offsetWidth / 20;
        var h = probe.offsetHeight || 16;
        container.removeChild(probe);
        return { w: Math.max(w, 4), h: Math.max(h, 10) };
    }

    // --- BORDER CHARACTER GENERATION ---
    // Generate solid repeating character string
    function fill(ch, n) {
        var s = '';
        for (var i = 0; i < n; i++) s += ch;
        return s;
    }

    // Generate space-separated character pattern (for plus, equals, slash styles)
    function fillSpaced(ch, n) {
        var parts = [];
        for (var i = 0; i < n; i++) parts.push(ch);
        return parts.join(' ');
    }

    // Generate newline-separated characters for vertical borders (one per line)
    function fillVertical(ch, n) {
        var lines = [];
        for (var i = 0; i < n; i++) lines.push(ch);
        return lines.join('\n');
    }

    // --- BORDER STYLE ASSEMBLY ---
    // Build all four borders based on selected style
    function buildBorders(style, hChars, vChars) {
        var top, bottom, left, right;
        switch (style) {
            case 'plus':
                top = bottom = fillSpaced('+', hChars);
                left = fillVertical('+', vChars);
                right = fillVertical('+', vChars);
                break;
            case 'equals':
                top = bottom = fillSpaced('=', hChars);
                left = fillVertical('=', vChars);
                right = fillVertical('=', vChars);
                break;
            case 'slash':
                top = bottom = fillSpaced('/', hChars);
                left = fillVertical('\\', vChars);
                right = fillVertical('\\', vChars);
                break;
            case 'box':
            default:
                var inner = Math.max(0, hChars - 2);
                top = bottom = '+' + fill('-', inner) + '+';
                left = fillVertical('|', vChars);
                right = fillVertical('|', vChars);
                break;
        }
        return { top: top, bottom: bottom, left: left, right: right };
    }

    // --- FRAME APPLICATION ---
    // Apply borders to a single frame element
    function applyFrame(frame) {
        var inner   = frame.querySelector('.ip-ascii-frame-inner');
        var img     = frame.querySelector('img');
        var leftEl  = frame.querySelector('.ip-border-left');
        var rightEl = frame.querySelector('.ip-border-right');
        if (!inner || !img) return;

        var style = frame.getAttribute('data-border-style') || 'box';
        if (style === 'none') {
            inner.setAttribute('data-border-top', '');
            inner.setAttribute('data-border-bottom', '');
            if (leftEl) leftEl.textContent = '';
            if (rightEl) rightEl.textContent = '';
            return;
        }

        var imgW = img.offsetWidth;
        var imgH = img.offsetHeight;
        if (imgW === 0 || imgH === 0) return;

        var charSize = probeCharSize(frame);
        var isSpaced = (style === 'plus' || style === 'equals' || style === 'slash');
        var unitW = isSpaced ? (charSize.w * 2) : charSize.w;

        var hChars = Math.max(3, Math.floor(imgW / unitW));
        var vChars = Math.max(2, Math.floor(imgH / charSize.h));

        var borders = buildBorders(style, hChars, vChars);
        inner.setAttribute('data-border-top', borders.top);
        inner.setAttribute('data-border-bottom', borders.bottom);
        if (leftEl) leftEl.textContent = borders.left;
        if (rightEl) rightEl.textContent = borders.right;
    }

    // --- PROCESSING PIPELINE ---
    // Apply borders to all frames on the page
    function processAll() {
        var frames = document.querySelectorAll('.ip-ascii-frame');
        for (var i = 0; i < frames.length; i++) {
            applyFrame(frames[i]);
        }
    }

    // --- INITIALIZATION ---
    function init() {
        processAll();
        var timer;
        window.addEventListener('resize', function () {
            clearTimeout(timer);
            timer = setTimeout(processAll, 150);
        });
    }

    if (document.readyState === 'complete') {
        init();
    } else {
        window.addEventListener('load', init);
    }

    document.addEventListener('lightbox-closed', processAll);
})();
