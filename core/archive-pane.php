<?php
/**
 * SNAPSMACK — which archive pane a page component should show.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * WHY THIS EXISTS. The visitor's choice of archive layout is resolved once,
 * in core/archive-controls.php, from the URL, the cookie and the site
 * default. public-runtime.php publishes the answer as
 * response.archive_layout_active, and the T/M control row is drawn from the
 * same resolution, so the buttons always tell the truth about it.
 *
 * Not one page component read it. All four that render an archive derived
 * the layout again, independently, from site.archive_layout — the stored
 * DEFAULT, which knows nothing about this visitor's URL or cookie. So the
 * control row said thumbs while the component rendered masonry, and
 * /archive?layout=thumbs did not give you thumbs.
 *
 * Worse, the two sides no longer speak the same language. archive-controls
 * normalises the old four-way vocabulary to two — square, cropped and
 * croppedwithcalendar all collapse to 'thumbs' — while the components still
 * tested against the four-way list and fell through to a default when they
 * met a normalised value.
 *
 * One resolution, one vocabulary, read in one place. The components ask
 * this; they no longer work it out.
 */

if (!function_exists('snap_archive_pane')) {
    /**
     * 'thumbs' or 'masonry' — the pane this request should show.
     *
     * Prefers the resolved value. FAILS OPEN to the stored site default when
     * the resolution is absent, because these components render every page
     * kind and only an archive route carries the controls: a landing page
     * must not lose its grid because nothing resolved a layout for it.
     */
    function snap_archive_pane(array $view): string
    {
        $active = (string)($view['response']['archive_layout_active'] ?? '');
        if ($active === 'masonry' || $active === 'thumbs') return $active;

        // Fallback. Accepts either vocabulary: 'masonry' is the only value in
        // both that means the wall, so everything else is the thumbnail grid.
        return ((string)($view['site']['archive_layout'] ?? '')) === 'masonry'
            ? 'masonry'
            : 'thumbs';
    }
}
// ===== SNAPSMACK EOF =====
