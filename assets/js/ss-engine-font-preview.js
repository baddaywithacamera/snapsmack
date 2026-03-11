/**
 * SNAPSMACK - Font Preview Engine
 * Alpha v0.7.1
 *
 * Handles live font previews on the skin admin panel.
 * Listens for change events on <select data-font-preview="1"> elements,
 * loads the chosen font (local TTF via @font-face or Google Fonts CDN),
 * then updates the sibling .font-preview-text spans.
 */
(function () {
    'use strict';

    // Google Fonts <link> tags we've already injected (avoid duplicates)
    var loadedGoogleFonts = {};

    /**
     * Inject a Google Fonts <link> into <head> for the given family.
     * Returns a Promise that resolves when the font is ready.
     */
    function loadGoogleFont(family) {
        if (loadedGoogleFonts[family]) {
            return Promise.resolve();
        }
        loadedGoogleFonts[family] = true;

        return new Promise(function (resolve) {
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'https://fonts.googleapis.com/css2?family='
                + encodeURIComponent(family).replace(/%20/g, '+')
                + ':wght@400;700&display=swap';
            link.onload = resolve;
            link.onerror = resolve; // resolve anyway so preview still updates
            document.head.appendChild(link);
        });
    }

    /**
     * Update all .font-preview-text spans inside the preview container.
     */
    function updatePreview(select, family) {
        var wrapper = select.closest('.lens-input-wrapper') || select.parentNode;
        var previewBox = wrapper.querySelector('.font-preview');
        if (!previewBox) return;

        var spans = previewBox.querySelectorAll('.font-preview-text');
        for (var i = 0; i < spans.length; i++) {
            spans[i].style.fontFamily = "'" + family + "', sans-serif";
        }
        // Update the name display (first span shows font name)
        if (spans.length > 0) {
            spans[0].textContent = family;
        }
    }

    /**
     * Handle a font select change event.
     *
     * Strategy: always update immediately (handles local @font-face fonts)
     * AND always try loading from Google Fonts CDN (handles remote fonts).
     * Google returns an empty/error for local-only families — harmless.
     * Once the Google <link> loads, a second render pass shows the real face.
     */
    function onFontChange(e) {
        var select = e.target;
        var family = select.value;
        if (!family) return;

        // Instant update — renders correctly for local TTF fonts that already
        // have @font-face declarations on the page. For Google fonts this will
        // briefly show the fallback until the CDN stylesheet arrives below.
        updatePreview(select, family);

        // Also fire off a Google Fonts load. If the family is local-only,
        // Google returns a 400 and we silently ignore it. If it IS a Google
        // font, the <link> loads and we re-render to swap in the real face.
        loadGoogleFont(family).then(function () {
            setTimeout(function () {
                updatePreview(select, family);
            }, 150);
        });
    }

    /**
     * Initialise: bind listeners to all font-preview selects on the page.
     */
    function init() {
        var selects = document.querySelectorAll('select[data-font-preview]');
        for (var i = 0; i < selects.length; i++) {
            selects[i].addEventListener('change', onFontChange);
        }
    }

    // Boot when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
