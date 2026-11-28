/**
 * SNAPSMACK - TILE LIKE (SS-1323)
 *
 * A heart inside a photograph's tile. Click it and the like is recorded
 * without leaving the page, so a visitor can like several in a row.
 *
 * THE HARD PART IS NOT THE LIKE, IT IS NOT DOING ANYTHING ELSE. The tile is
 * usually a link to the solo page, and on a GLIDE conveyor the row is also a
 * drag surface that flings. A click on the heart must do neither. So this
 * stops the event at the earliest point each of those listens on:
 *   - pointerdown, because that is where the conveyor begins a drag
 *   - mousedown and touchstart, for anything older listening there
 *   - click, because that is where the tile's link opens
 * Capture phase and stopPropagation, so the heart is reached before any
 * ancestor handler, whatever order they were bound in.
 *
 * Talks to /process-like.php, the same endpoint the community dock uses and
 * the same snap_likes row. A like given here and a like given on the solo
 * page are one like.
 *
 * ===== SNAPSMACK EOF =====
 */
(function () {
    'use strict';

    // One photograph can have a conveyor heart, a redistributed copy and a
    // viewer heart at the same time. The request lock belongs to the photo,
    // not to whichever copy was clicked first.
    var pending = Object.create(null);

    // SS-1385. THE BUTTON FIRST, THE META TAG SECOND, and the order is the fix.
    //
    // This read the meta tag only. csrf_meta_tag() is emitted on ADMIN pages
    // and never on a public one, so on a public page this returned '' and the
    // request carried no token. The first server-side repair was failed on
    // review for exactly that: a guard with nothing to check.
    //
    // The CMS now puts the token on the button itself, in
    // snap_tile_like_html(), because the button is CMS-built and every skin
    // prints it through a component -- so no skin file had to change. The meta
    // tag is kept as a fallback so the admin pages that DO emit it keep
    // working unchanged.
    function csrf(button) {
        if (button && button.dataset && button.dataset.csrf) return button.dataset.csrf;
        var tag = document.querySelector('meta[name="csrf-token"]');
        return tag ? tag.getAttribute('content') : '';
    }

    function paintOne(button, liked) {
        button.classList.toggle('is-liked', !!liked);
        button.dataset.liked = liked ? '1' : '0';
        button.setAttribute('aria-pressed', liked ? 'true' : 'false');
        var label = liked ? 'Unlike this photograph' : 'Like this photograph';
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
    }

    function paint(button, liked) {
        var id = parseInt(button.dataset.tileLike || '0', 10);
        if (!id) return paintOne(button, liked);
        document.querySelectorAll('.ss-tile-like[data-tile-like="' + id + '"]').forEach(function (copy) {
            paintOne(copy, liked);
        });
    }

    function paintBusy(id, busy) {
        document.querySelectorAll('.ss-tile-like[data-tile-like="' + id + '"]').forEach(function (copy) {
            copy.dataset.busy = busy ? '1' : '0';
        });
    }

    function send(button) {
        var id = parseInt(button.dataset.tileLike || '0', 10);
        if (!id || pending[id]) return;
        pending[id] = true;
        paintBusy(id, true);

        // OPTIMISTIC, and reverted if the server disagrees. A heart that waits
        // for a round trip before it fills feels broken, and the whole point
        // of this control is liking several in a row without waiting.
        var was = button.dataset.liked === '1';
        paint(button, !was);

        var body = new FormData();
        body.append('post_id', String(id));
        var token = csrf(button);
        if (token) body.append('csrf_token', token);

        // ROOT-RELATIVE, exactly as ss-engine-community.js:89 posts it.
        //
        // This read 'process-like.php' with no leading slash, which resolves
        // against the CURRENT PATH. On a landing page at the site root that
        // is the same URL, which is why the browser test passed; on any
        // deeper route -- /archive, a photo page, a tag -- it would post to a
        // path that does not exist and every heart would fail silently. Muse
        // found it in review, before a reader did.
        //
        // Matching the dock's spelling rather than inventing a third one: if
        // sub-directory installs ever need a base, both change together.
        fetch('/process-like.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && typeof data.liked === 'boolean') paint(button, data.liked);
                else paint(button, was);          // an error shape: put it back
            })
            .catch(function () { paint(button, was); })
            .then(function () {
                delete pending[id];
                paintBusy(id, false);
            });
    }

    function swallow(event) {
        var button = event.target.closest && event.target.closest('.ss-tile-like');
        if (!button) return;
        // Never let the tile's link open, and never let the conveyor read this
        // as the start of a drag.
        event.stopPropagation();
        if (event.type === 'click' || event.type === 'pointerdown') event.preventDefault();
        if (event.type === 'click') send(button);
    }

    ['pointerdown', 'mousedown', 'touchstart', 'click'].forEach(function (type) {
        document.addEventListener(type, swallow, true);
    });

    // Enter and Space are the button's own behaviour and arrive as a click,
    // which the capture handler above already has. Nothing extra is needed,
    // and adding a keydown handler would fire the like twice.
}());
// ===== SNAPSMACK EOF =====
