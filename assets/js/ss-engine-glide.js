// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
/**
 * GLIDE Engine. A kinetic photo wall whose rows drag and slide along a chosen
 * axis (horizontal, vertical or diagonal), with pointer-drag plus momentum, and
 * it honours prefers-reduced-motion. Reach for it when a skin wants a playful,
 * motion-led gallery instead of a static grid. Declared in a skin manifest via
 * require_scripts (data hooks: [data-glide-wall], [data-glide-row]).
 */
(function () {
    'use strict';

    var fullscreenNav = document.querySelector('[data-wall-fullscreen] .ss-grid-nav-links');
    if (fullscreenNav && document.fullscreenEnabled && document.documentElement.requestFullscreen) {
        var fullscreenButton = document.createElement('button');
        fullscreenButton.type = 'button';
        fullscreenButton.className = 'ss-grid-nav-link wall-fullscreen';
        fullscreenButton.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path/></svg>';
        function updateFullscreen() {
            var active = Boolean(document.fullscreenElement);
            var label = active ? 'Exit fullscreen' : 'Enter fullscreen';
            fullscreenButton.title = label;
            fullscreenButton.setAttribute('aria-label', label);
            fullscreenButton.querySelector('path').setAttribute('d', active
                ? 'M4 9h5V4m11 5h-5V4M4 15h5v5m11-5h-5v5'
                : 'M9 4H4v5m11-5h5v5M4 15v5h5m11-5v5h-5');
        }
        fullscreenButton.addEventListener('click', async function () {
            try {
                if (document.fullscreenElement) await document.exitFullscreen();
                else await document.documentElement.requestFullscreen();
            } catch {
                updateFullscreen();
            }
        });
        document.addEventListener('fullscreenchange', updateFullscreen);
        updateFullscreen();
        fullscreenNav.append(fullscreenButton);
    }


    var overlayNav = document.querySelector('[data-wall-archive]');
    if (overlayNav && typeof HTMLDialogElement !== 'undefined') {
        var panel = document.createElement('dialog');
        panel.className = 'wall-panel';
        panel.innerHTML = '<header><h2 id="wall-panel-title"></h2><form method="dialog"><button aria-label="Close panel">✕</button></form></header><div class="wall-panel-body"></div>';
        panel.setAttribute('aria-labelledby', 'wall-panel-title');
        document.body.append(panel);
        var content = panel.querySelector('.wall-panel-body');
        var panelRequest;
        var reducedOverlayMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        var closingPanel = false;

        function turnArchiveTiles(closing) {
            if (reducedOverlayMotion.matches) return [];
            var headerBottom = panel.querySelector('header').getBoundingClientRect().bottom;
            var panelBottom = panel.getBoundingClientRect().bottom;
            var tiles = Array.from(content.querySelectorAll('#browse-grid .glide-archive-item'));
            var firstRow;
            var edge = { transform: 'perspective(900px) rotateY(90deg)', opacity: 0 };
            var flat = { transform: 'none', opacity: 1 };
            return tiles.flatMap(function (tile, index) {
                var bounds = tile.getBoundingClientRect();
                if (bounds.bottom <= headerBottom || bounds.top >= panelBottom) return [];
                var row = Math.floor(index / 5);
                if (firstRow === undefined) firstRow = row;
                return [tile.animate(closing ? [flat, edge] : [edge, flat], {
                    delay: (row - firstRow) * 100 + (index % 5) * 20,
                    duration: closing ? 200 : 250,
                    easing: closing ? 'ease-in' : 'ease-out',
                    fill: closing ? 'forwards' : 'backwards'
                })];
            });
        }

        async function loadPanel(url, selector) {
            if (panelRequest) panelRequest.abort();
            var request = new AbortController();
            panelRequest = request;
            content.setAttribute('aria-busy', 'true');
            try {
                var response = await fetch(url, { signal: request.signal, mode: 'same-origin' });
                if (!response.ok) throw new Error('Page unavailable');
                var page = new DOMParser().parseFromString(await response.text(), 'text/html');
                var region = page.querySelector(selector);
                if (!region) throw new Error('Content unavailable');
                if (request.signal.aborted) return;
                region.querySelectorAll('script, iframe, object, embed').forEach(function (node) { node.remove(); });
                content.replaceChildren(region);
                if (panel.dataset.wallPanel === 'About') {
                    var portrait = region.querySelector('img');
                    if (portrait && !portrait.closest('a, button')) {
                        var enlarge = document.createElement('button');
                        enlarge.type = 'button';
                        enlarge.className = 'wall-portrait';
                        enlarge.setAttribute('aria-label', 'Enlarge portrait');
                        portrait.replaceWith(enlarge);
                        enlarge.append(portrait);
                        enlarge.addEventListener('click', function () {
                            viewerPhotos = [{ src: portrait.currentSrc || portrait.src, alt: portrait.alt, title: portrait.alt }];
                            viewer.classList.add('wall-viewer-portrait');
                            viewer.showModal();
                            showViewerPhoto(0, 1);
                        });
                        if (!reducedOverlayMotion.matches) portrait.animate([
                            { transform: 'perspective(1000px) rotateY(-75deg)', opacity: 0 },
                            { transform: 'none', opacity: 1 }
                        ], { duration: 420, delay: 220, fill: 'backwards', easing: 'ease-out' });
                    }
                }
                panel.scrollTop = 0;
                turnArchiveTiles(false);
            } catch {
                if (request.signal.aborted) return;
                var fallback = document.createElement('a');
                fallback.href = url;
                fallback.textContent = 'Open the full page';
                content.replaceChildren(fallback);
            } finally {
                if (panelRequest === request) content.removeAttribute('aria-busy');
            }
        }

        async function closePanel(event) {
            event.preventDefault();
            if (closingPanel) return;
            closingPanel = true;
            if (panelRequest) panelRequest.abort();
            var turns = turnArchiveTiles(true);
            var portrait = content.querySelector('.wall-portrait img');
            if (portrait && !reducedOverlayMotion.matches) turns.push(portrait.animate([
                { transform: 'none', opacity: 1 },
                { transform: 'perspective(1000px) rotateY(-75deg)', opacity: 0 }
            ], { duration: 300, fill: 'forwards', easing: 'ease-in' }));
            await Promise.allSettled(turns.map(function (turn) { return turn.finished; }));
            if (!reducedOverlayMotion.matches) {
                var fade = panel.animate([{ opacity: getComputedStyle(panel).opacity }, { opacity: 0 }],
                    { duration: 180, easing: 'ease-in', fill: 'forwards' });
                await fade.finished.catch(function () {});
                panel.close();
                fade.cancel();
            } else {
                panel.close();
            }
            turns.forEach(function (turn) { turn.cancel(); });
            closingPanel = false;
        }

        content.addEventListener('click', function (event) {
            var link = event.target.closest('.wall-pagination a[href]');
            if (!link || link.origin !== location.origin || event.defaultPrevented || event.button ||
                event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            loadPanel(link.href, '#scroll-stage');
        });
        var viewer = document.createElement('dialog');
        viewer.className = 'wall-viewer';
        viewer.setAttribute('aria-label', 'Photograph viewer');
        viewer.innerHTML = '<figure><img alt=""><figcaption></figcaption><button type="button" data-wall-step="-1" aria-label="Previous photograph">‹</button><button type="button" data-wall-step="1" aria-label="Next photograph">›</button><div class="wall-viewer-tools"><button type="button" data-wall-pane="info" aria-label="Post info" aria-pressed="false">ⓘ</button><button type="button" data-wall-pane="comments" aria-label="Comments" aria-pressed="false"><svg width="21" height="21" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 11a7 7 0 0 1-7 7H8l-4 3v-5a7 7 0 0 1 7-12h2a7 7 0 0 1 7 7Z"/></svg></button></div><section class="wall-viewer-pane" aria-labelledby="wall-viewer-pane-title" inert><h2 id="wall-viewer-pane-title"></h2><p></p><div class="wall-comment-body"></div></section></figure>';
        document.body.append(viewer);
        var viewerImage = viewer.querySelector('img');
        var viewerCaption = viewer.querySelector('figcaption');
        var viewerPhotos = [];
        var viewerIndex = 0;
        var viewerRequest = 0;
        var viewerPane = viewer.querySelector('.wall-viewer-pane');
        var viewerPaneName = '';
        var displayedPhoto;
        var commentBody = viewerPane.querySelector('.wall-comment-body');
        var commentRequest;

        async function loadViewerComments(photo, status) {
            var url = new URL(photo.url, location.href);
            if (url.origin !== location.origin) return;
            url.searchParams.set('viewer_comments', '1');
            var request = new AbortController();
            commentRequest = request;
            try {
                var response = await fetch(url, { mode: 'same-origin', signal: request.signal });
                if (!response.ok) throw new Error('Comments unavailable');
                var page = new DOMParser().parseFromString(await response.text(), 'text/html');
                var thread = page.querySelector('.wall-comment-content .ss-community');
                if (request.signal.aborted) return;
                if (!thread) throw new Error('Comments unavailable');
                thread.querySelectorAll('script, iframe, object, embed').forEach(function (node) { node.remove(); });
                commentBody.replaceChildren(thread);
                status.textContent = !thread.querySelector('.ss-comment-thread') && thread.querySelector('.ss-comment-form')
                    ? 'Be the first to comment.' : '';
                commentBody.dispatchEvent(new CustomEvent('snapsmack:modal:opened', { bubbles: true }));
            } catch {
                if (!request.signal.aborted) status.textContent = 'Comments could not be loaded.';
            }
        }

        function updateViewerPane() {
            commentRequest?.abort();
            commentBody.replaceChildren();
            if (!displayedPhoto || !viewerPaneName) return;
            var isInfo = viewerPaneName === 'info';
            viewerPane.querySelector('h2').textContent = isInfo ? displayedPhoto.title : 'Comments';
            var status = viewerPane.querySelector('p');
            status.textContent = isInfo ? displayedPhoto.alt
                : displayedPhoto.commentsOpen ? 'Loading comments…' : 'Comments are closed.';
            if (!isInfo && displayedPhoto.commentsOpen) loadViewerComments(displayedPhoto, status);
        }

        function setViewerPane(name) {
            if (!name && viewerPane.contains(document.activeElement)) {
                viewer.querySelector('[data-wall-pane="' + viewerPaneName + '"]')?.focus();
            }
            viewerPaneName = name;
            viewer.classList.toggle('has-wall-viewer-pane', Boolean(name));
            viewerPane.inert = !name;
            viewer.querySelectorAll('[data-wall-pane]').forEach(function (button) {
                button.setAttribute('aria-pressed', String(button.dataset.wallPane === name));
            });
            updateViewerPane();
        }

        var closingViewer = false;
        async function showViewerPhoto(index, direction) {
            if (closingViewer) return;
            var request = ++viewerRequest;
            viewerIndex = (index + viewerPhotos.length) % viewerPhotos.length;
            var photo = viewerPhotos[viewerIndex];
            var loaded = new Image();
            loaded.src = photo.src;
            try { await loaded.decode(); } catch { return; }
            if (request !== viewerRequest || !viewer.open) return;
            if (!reducedOverlayMotion.matches && viewerImage.hasAttribute('src')) {
                var turn = viewerImage.animate([{ transform: 'none' },
                    { transform: 'perspective(1200px) rotateY(' + (-direction * 90) + 'deg)' }],
                    { duration: 160, fill: 'forwards' });
                await turn.finished.catch(function () {});
                turn.cancel();
                if (request !== viewerRequest || !viewer.open) return;
            }
            displayedPhoto = photo;
            updateViewerPane();
            viewerImage.src = photo.src;
            viewerImage.alt = photo.alt;
            viewerCaption.textContent = photo.title;
            if (!reducedOverlayMotion.matches) viewerImage.animate([
                { transform: 'perspective(1200px) rotateY(' + (direction * 90) + 'deg)' },
                { transform: 'none' }], { duration: 300, easing: 'ease-out' });
        }

        function openViewer(event) {
            if (event.defaultPrevented || event.button || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            var tile = event.target.closest('.glide-tile, .glide-archive-item > a');
            if (!tile || !tile.querySelector('img')) return;
            var region = tile.closest('#browse-grid') || tile.closest('[data-glide-wall]');
            var tiles = Array.from(region.querySelectorAll('.glide-tile, .glide-archive-item > a'));
            var unique = new Map();
            tiles.forEach(function (entry) {
                var img = entry.querySelector('img');
                if (img && !unique.has(entry.href)) unique.set(entry.href, {
                    src: entry.dataset.wallPhoto || img.currentSrc || img.src,
                    alt: img.alt,
                    url: entry.href,
                    commentCount: entry.hasAttribute('data-comment-count') ? Number(entry.dataset.commentCount) : null,
                    commentsOpen: entry.dataset.commentsEnabled === "true",
                    title: entry.getAttribute('aria-label') || entry.querySelector('h2')?.textContent || img.alt
                });
            });
            var index = Array.from(unique.keys()).indexOf(tile.href);
            if (index < 0) return;
            event.preventDefault();
            if (tile.classList.contains('glide-tile')) {
                tile.setAttribute('data-glide-restored-focus', '');
                tile.addEventListener('focusout', function () {
                    if (!viewer.open) tile.removeAttribute('data-glide-restored-focus');
                }, { once: true });
            }
            viewerPhotos = Array.from(unique.values());
            viewer.showModal();
            showViewerPhoto(index, 1);
        }

        var wallGallery = overlayNav.closest('[data-glide-wall]');
        if (wallGallery) wallGallery.addEventListener('click', openViewer);
        content.addEventListener('click', openViewer);
        async function closeViewer() {
            if (!viewer.open || closingViewer) return;
            closingViewer = true;
            viewerRequest++;
            try {
                if (!reducedOverlayMotion.matches && viewerImage.hasAttribute('src')) {
                    viewerImage.getAnimations().forEach(function (animation) { animation.cancel(); });
                    var flip = viewerImage.animate([
                        { transform: 'perspective(1200px) rotateY(0deg)' },
                        { transform: 'perspective(1200px) rotateY(-90deg)' }
                    ], { duration: 240, easing: 'ease-in', fill: 'forwards' });
                    await flip.finished.catch(function () {});
                    var fadeOptions = { duration: 180, easing: 'ease-out', fill: 'forwards' };
                    var fade = viewer.animate([{ opacity: 1 }, { opacity: 0 }], fadeOptions);
                    var backdrop = viewer.animate([
                        { backgroundColor: 'rgb(0 0 0 / 80%)' },
                        { backgroundColor: 'transparent' }
                    ], { ...fadeOptions, pseudoElement: '::backdrop' });
                    await Promise.allSettled([fade.finished, backdrop.finished]);
                    viewer.close();
                    fade.cancel();
                    backdrop.cancel();
                    flip.cancel();
                } else {
                    viewer.close();
                }
            } finally {
                closingViewer = false;
            }
        }

        viewer.addEventListener('click', function (event) {
            if (closingViewer) return;
            if (event.target === viewer) closeViewer();
            var pane = event.target.closest('[data-wall-pane]')?.dataset.wallPane;
            if (pane) setViewerPane(viewerPaneName === pane ? '' : pane);
            var direction = Number(event.target.closest('[data-wall-step]')?.dataset.wallStep);
            if (direction) showViewerPhoto(viewerIndex + direction, direction);
        });
        viewer.addEventListener('cancel', function (event) {
            event.preventDefault();
            if (closingViewer) return;
            if (viewerPaneName) setViewerPane('');
            else closeViewer();
        });
        viewer.addEventListener('keydown', function (event) {
            if (closingViewer || viewer.classList.contains('wall-viewer-portrait') || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
            if (event.target.closest('input, textarea, select, [contenteditable]') && event.key !== 'Escape') return;
            var pane = event.key === '1' ? 'info' : event.key === '2' ? 'comments' : '';
            if (pane || (event.key === 'Escape' && viewerPaneName)) {
                event.preventDefault();
                event.stopPropagation();
                if (!event.repeat) setViewerPane(viewerPaneName === pane ? '' : pane);
                return;
            }
            var direction = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
            if (!direction) return;
            event.preventDefault();
            event.stopPropagation();
            showViewerPhoto(viewerIndex + direction, direction);
        });
        viewer.addEventListener('close', function () {
            viewerRequest++;
            setViewerPane('');
            displayedPhoto = undefined;
            viewer.classList.remove('wall-viewer-portrait');
            var restored = document.activeElement.closest('.glide-tile[data-glide-restored-focus]');
            if (restored) restored.addEventListener('focusout', function () {
                restored.removeAttribute('data-glide-restored-focus');
            }, { once: true });
            viewerImage.removeAttribute('src');
        });

        panel.addEventListener('submit', closePanel);
        panel.addEventListener('cancel', closePanel);
        function openWallPanel(url, title, selector) {
            panel.querySelector('h2').textContent = title;
            panel.dataset.wallPanel = title;
            content.textContent = 'Loading…';
            panel.showModal();
            if (!reducedOverlayMotion.matches) panel.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 220, easing: 'ease-out' });
            loadPanel(url, selector);
        }

        if (overlayNav.dataset.wallSearch) {
            var searchForm = document.createElement('form');
            searchForm.className = 'wall-search';
            searchForm.setAttribute('role', 'search');
            searchForm.innerHTML = '<input type="search" aria-label="Search photos" placeholder="Search photos" hidden><button type="button" aria-label="Search photos" aria-expanded="false"><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><circle cx="10" cy="10" r="6"/><path d="m15 15 5 5"/></svg></button>';
            overlayNav.after(searchForm);
            var searchInput = searchForm.querySelector('input');
            var searchToggle = searchForm.querySelector('button');
            var searchAnimation;

            async function toggleSearch(open) {
                searchAnimation?.cancel();
                searchToggle.setAttribute('aria-expanded', String(open));
                if (open) {
                    searchInput.hidden = false;
                    searchInput.inert = false;
                    searchInput.focus();
                } else {
                    searchToggle.focus();
                    searchInput.inert = true;
                }
                if (!reducedOverlayMotion.matches) {
                    var frames = [{ transform: 'scaleX(.08)', opacity: 0 }, { transform: 'scaleX(1)', opacity: 1 }];
                    var animation = searchInput.animate(open ? frames : frames.reverse(), { duration: 160, fill: 'both' });
                    searchAnimation = animation;
                    await animation.finished.catch(function () {});
                    if (searchAnimation !== animation) return;
                    animation.cancel();
                }
                searchInput.hidden = !open;
            }

            searchToggle.addEventListener('click', function () { toggleSearch(searchToggle.getAttribute('aria-expanded') !== 'true'); });
            searchInput.addEventListener('keydown', function (event) {
                if (event.key !== 'Escape') return;
                event.preventDefault();
                event.stopPropagation();
                toggleSearch(false);
            });
            searchForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var query = searchInput.value.trim();
                if (!query) return;
                var url = new URL(overlayNav.dataset.wallSearch, location.href);
                if (url.origin !== location.origin) return;
                url.searchParams.set('q', query);
                openWallPanel(url.href, 'Search', '#scroll-stage');
            });
        }

        overlayNav.addEventListener('click', function (event) {
            var link = event.target.closest('a[href]');
            if (!link || event.defaultPrevented || event.button || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            var destinations = [
                [overlayNav.dataset.wallArchive, 'Archive', '#scroll-stage'],
                [overlayNav.dataset.wallBlogroll, 'Blogroll', '.blogroll-canvas'],
                [overlayNav.dataset.wallAbout, 'About', '.static-content, .static-page-content, #scroll-stage']
            ];
            var target = destinations.find(function (entry) { return entry[0] && new URL(entry[0], location.href).href === link.href; });
            if (!target || link.origin !== location.origin) return;
            event.preventDefault();
            openWallPanel(link.href, target[1], target[2]);
        });
    }

    // ---- the arithmetic, kept pure and exposed so it can be MEASURED -------
    //
    // SS-1093. This used to wrap at scrollWidth / 4, which was right only
    // while the markup repeated each row four times -- the way the wall was
    // first built. The markup stopped doing that, the divisor stayed, and the
    // modulus became a quarter of a single-copy track: a row travelled through
    // a quarter of its own photographs and snapped back. Roughly half of each
    // long row could never be brought on screen, and rows with more portraits
    // wrapped sooner than the others, so no two rows agreed.
    //
    // The period is now MEASURED from what is actually in the track, and the
    // engine makes its own copies, so the two can never disagree again.

    /** Translate a running offset into one period. Never divides by zero. */
    function glideWrap(current, period) {
        if (!(period > 0)) return 0;
        return (((current % period) + period) % period) - period;
    }

    /**
     * The distance after which the pattern repeats.
     *
     * NOT the measured width of one copy. A flex gap sits BETWEEN items, so
     * one copy of n tiles measures sum + (n-1) gaps -- but the copy that
     * follows it starts one further gap along, so the period is sum + n gaps.
     * Getting this wrong by one gap is a slow drift at every wrap, and it is
     * the second thing the old divisor got wrong.
     */
    function glidePeriodFrom(widths, gap) {
        if (!widths || !widths.length) return 0;
        var sum = 0;
        for (var i = 0; i < widths.length; i++) sum += (parseFloat(widths[i]) || 0);
        return sum + (widths.length * (parseFloat(gap) || 0));
    }

    /**
     * Repeat the track's own children until the wrap point is off screen.
     *
     * The engine does this itself rather than asking the skin to emit copies:
     * a strict skin's layout is presentation only, and a count agreed between
     * two files is a count that drifts. Returns how many copies it added.
     */
    function glideFill(track, period, minExtent, gap, downPage) {
        if (!track || !track.children || !track.children.length) return 0;
        if (!(period > 0)) return 0;
        var originals = Array.prototype.slice.call(track.children);
        var copies = 0;
        while ((downPage ? track.scrollHeight : track.scrollWidth) < period + minExtent && copies < 8) {
            for (var i = 0; i < originals.length; i++) {
                var clone = originals[i].cloneNode(true);
                // A copy is scenery. The 3 August design note left open
                // whether duplicated links should be hidden from assistive
                // technology; they must be, or a screen reader reads the same
                // wall several times and every copy is a tab stop.
                clone.setAttribute('aria-hidden', 'true');
                clone.tabIndex = -1;
                track.appendChild(clone);
            }
            copies++;
        }
        return copies;
    }

    window.SSGlide = { wrap: glideWrap, periodFrom: glidePeriodFrom, fill: glideFill };

    // How far the pointer may travel before a press counts as a drag
    // rather than a click. 10px on Sean's instruction: 3px discarded
    // intended clicks from an unsteady hand.
    var GLIDE_DRAG_SLOP = 10;

    // How a released drag comes to rest. Friction is per frame, so at
    // ~60fps 0.94 is about a second of travel; the floor is where the
    // remaining motion is below one pixel a frame and not worth painting.
    var GLIDE_FRICTION = 0.94;
    var GLIDE_VEL_FLOOR = 0.4;

    var wall = document.querySelector('[data-glide-wall]');
    if (!wall) return;
    var rows = Array.prototype.slice.call(wall.querySelectorAll('[data-glide-row]'));
    if (wall.hasAttribute('data-wall-inner-coverage') && rows.length >= 5) {
        var firstInner = Math.floor((rows.length - 3) / 2);
        var innerTracks = rows.slice(firstInner, firstInner + 3).map(function (row) {
            return row.querySelector('.glide-track');
        });
        if (innerTracks.every(Boolean)) {
            var seen = new Set(innerTracks.flatMap(function (track) {
                return Array.from(track.querySelectorAll('a[href]'), function (link) { return link.href; });
            }));
            var nextInner = 0;
            rows.forEach(function (row, index) {
                if (index >= firstInner && index < firstInner + 3) return;
                row.querySelectorAll('.glide-track a[href]').forEach(function (tile) {
                    if (seen.has(tile.href)) return;
                    seen.add(tile.href);
                    innerTracks[nextInner++ % innerTracks.length].append(tile.cloneNode(true));
                });
            });
        }
    }


    // THE BROWSER'S OWN DRAG-AND-DROP WAS EATING EVERY FLING.
    //
    // A tile is a link wrapped round a photograph, and both are natively
    // draggable. So a mouse drag on a tile started a native drag, the browser
    // took the gesture, and it fired POINTERCANCEL after the first move.
    // release(event, true) then does exactly what it should on a cancel --
    // clears moved, clears vel, clears flinging and returns -- so the wall
    // never coasted. Sean reported the inertia missing; the wheel half was one
    // cause and this was the other, and this one swallowed the drag entirely.
    //
    // Codex measured it in a headed browser: cancelled=true on all four
    // orientations with velocity 4.32 and 9.00 sitting unused and only an
    // 11-22px easing tail. With native dragstart suppressed on the tile, the
    // same gestures released normally at velocity 10.62 and 22.12 and travelled
    // 206-209px and 441-443px -- which is the 170px and 362px the arithmetic
    // predicted. Nothing was wrong with the fling; it was never reached.
    //
    // SCOPED TO A ROW WE ACTUALLY DRIVE, not the document and not the whole
    // wall. preventDefault on dragstart stops only native drag-and-drop:
    // clicks, links and the lightbox are untouched, and a drag that begins
    // outside a row keeps the browser's normal behaviour.
    //
    // It lives in the engine rather than in markup because every skin using
    // [data-glide-wall] has the same gesture, and draggable="false" would have
    // to be added to each one and would be a presentation file carrying a
    // behaviour fix.
    wall.addEventListener('dragstart', function (event) {
        var target = event.target;
        if (target && target.closest && target.closest('[data-glide-row]')) {
            event.preventDefault();
        }
    });
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var sensitivity = parseFloat(wall.dataset.travel || '0.9');
    var raf = 0;
    var baseAngles = { horizontal: 0, vertical: 90, diagonal_down: 45, diagonal_up: -45 };
    var axis = wall.dataset.flowAxis || 'horizontal';
    var fine = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--glide-angle')) || 0;
    // TWO VECTORS, AND CONFLATING THEM WAS THE BUG.
    //
    // The pointer moves in SCREEN space. The track translates in its OWN
    // space, inside a field the skin has already rotated by the lean. Those
    // are different frames, so they need different vectors:
    //
    //   WORLD, base + lean -- for reading the pointer. On screen the wall
    //   travels along the LEANED direction, because the field is tilted, so a
    //   drag is projected onto that direction.
    //
    //   LOCAL, base ONLY -- for writing the translate. The field's own
    //   rotate() already applies the lean to whatever the track does. Putting
    //   the lean in the translate as well applies it TWICE: the track creeps
    //   across its row as it travels, which is the sideways drift Codex
    //   measured. Excluding it leaves local X at zero for a vertical wall and
    //   lets the field supply the whole visible tilt.
    //
    // I GOT THIS WRONG IN PUBLIC AND IT IS WORTH WRITING DOWN. When Codex
    // reported the translation was "mostly Y" I read the small X term as proof
    // the lean was composing correctly. It was the defect. A leaned vertical
    // wall must paint PURE Y locally; anything else is the double-counting.
    var worldRadians = ((baseAngles[axis] || 0) + fine) * Math.PI / 180;
    var worldX = Math.cos(worldRadians);
    var worldY = Math.sin(worldRadians);
    var localRadians = (baseAngles[axis] || 0) * Math.PI / 180;
    var localX = Math.cos(localRadians);
    var localY = Math.sin(localRadians);
    // MOVING ALONG THE AXIS IS OPT-IN, and it has to be.
    //
    // Until now the axis vector only PROJECTED THE POINTER: it decided which
    // drag direction drives the wall. The wall itself always moved on X, and
    // the apparent direction came from the SKIN rotating the whole field --
    // skins/glide/style.css:29 rotates .glide-field by --glide-axis-angle plus
    // the -7deg lean, and skins/sliders/style.css:1373 does the same to
    // .sl-glide-field with its axis angle DEFAULTING TO -45deg. That is a
    // coherent design and its cost is the one Codex reported: a vertical axis
    // turns the photographs sideways with the field.
    //
    // So a skin can ask for the motion itself to run along the axis, which
    // lets it drop the axis term from its rotate() and keep only the lean,
    // leaving photographs upright. It is OPT-IN because switching every
    // consumer at once would double-transform any site whose owner has chosen
    // a non-horizontal axis -- the field would still be rotated AND the motion
    // would now follow the axis. Both GLIDE and SLIDERS expose the axis as an
    // owner setting, so those sites exist and I cannot see them from here.
    //
    // With the flag absent, every expression below reduces to exactly what it
    // computed before. That is asserted, not assumed.
    var alongAxis = wall.dataset.flowMotion === 'axis';
    // DERIVED FROM THE LOCAL AXIS, not the leaned one. Which edge of a tile is
    // the direction of travel is a fact about the track's own box, and the
    // lean is not in that box's frame. It also removes a wart: with the lean
    // in play a 45deg diagonal was resolved by whichever way -7deg tipped it.
    // Now a diagonal is an exact tie and the tie goes to horizontal, which is
    // arbitrary but FIXED, and a fixed arbitrary answer is a decision.
    var vertical = alongAxis && Math.abs(localY) > Math.abs(localX);
    /** The flex gap actually in force on this track, in pixels. */
    function trackGap(track) {
        if (!window.getComputedStyle) return 0;
        var cs = window.getComputedStyle(track);
        if (!cs) return 0;
        // A column track spaces its children with row-gap, not column-gap.
        var g = parseFloat(vertical ? cs.rowGap : cs.columnGap);
        if (!isFinite(g)) g = parseFloat(cs.gap);
        return isFinite(g) ? g : 0;
    }

    /**
     * The sizes of the ORIGINAL tiles along the direction of travel, ignoring
     * any copies behind them. Width for a horizontal wall, height for a
     * vertical one -- the period is a distance in the direction the wall
     * actually moves, and measuring the wrong edge wraps at the wrong place.
     */
    function originalExtents(state) {
        var out = [], kids = state.track.children;
        for (var i = 0; i < state.originals && i < kids.length; i++) {
            out.push(vertical ? kids[i].offsetHeight : kids[i].offsetWidth);
        }
        return out;
    }

    /** Measure the period and make sure the track is long enough to hide the seam. */
    function measure(state) {
        var gap = trackGap(state.track);
        state.period = glidePeriodFrom(originalExtents(state), gap);
        glideFill(state.track, state.period,
                  (vertical ? window.innerHeight : window.innerWidth) || 0, gap, vertical);
    }

    var states = rows.map(function (row, index) {
        var track = row.firstElementChild;
        var state = {
            row: row, track: track,
            // How many tiles the SKIN emitted. Everything past this is a copy
            // this engine made, and the period is measured from these only.
            originals: track && track.children ? track.children.length : 0,
            period: 0,
            current: index * -90, target: index * -90,
            dragging: false, moved: false, captured: false,
            // vel is the recent speed of the drag, carried past release;
            // flinging says the wall is still coasting on it.
            vel: 0, flinging: false,
            x: 0, y: 0, startX: 0, startY: 0
        };
        measure(state);
        return state;
    });

    // Local Portal preview prototype.
    var idleMode = wall.dataset.idleMotion;
    var idleDrift = ['alternating', 'together', 'staggered'].includes(idleMode) && !reduced;
    var previousFrame = 0;
    var idleSpeed = Math.min(60, Math.max(1, parseFloat(wall.dataset.idleSpeed) || 14)) / 1000;
    var dragStartFactor = 0.6;
    var dragRampLength = 40;

    function paint(now) {
        var elapsed = previousFrame ? Math.min(now - previousFrame, 50) : 0;
        previousFrame = now;
        var moving = false;
        for (var i = 0; i < states.length; i++) {
            var state = states[i];
            var resting = idleDrift && !state.dragging && !state.flinging
                && Math.abs(state.target - state.current) <= 0.1
                && !state.row.querySelector(':focus-visible:not([data-glide-restored-focus])');
            state.idleSince = resting ? (state.idleSince ?? now) : null;
            if (idleDrift && state.idleSince !== null && now - state.idleSince >= 700) {
                var rate = idleMode === 'alternating' && i % 2 !== 0 ? -1 : 1;
                if (idleMode === 'staggered') rate = 0.75 + (i % 3) * 0.25;
                var drift = elapsed * idleSpeed * rate;
                state.current += drift;
                state.target += drift;
            }
            if (state.flinging) {
                state.target += state.vel;
                state.vel *= GLIDE_FRICTION;
                if (Math.abs(state.vel) < GLIDE_VEL_FLOOR) {
                    state.flinging = false;
                    state.vel = 0;
                }
            }
            state.current += (state.target - state.current) * (reduced ? 1 : 0.18);
            var x = glideWrap(state.current, state.period);
            // THE LOCAL VECTOR, because this is the track's own frame and the
            // field's rotate() adds the lean on top. For a horizontal axis
            // localX is 1 and localY is 0, so the opted-in form is the same
            // translation as the original. The branch is kept anyway so a skin
            // that has not asked for this gets the original expression, not a
            // computed one that happens to agree.
            state.track.style.transform = alongAxis
                ? 'translate3d(' + (x * localX) + 'px,' + (x * localY) + 'px,0)'
                : 'translate3d(' + x + 'px,0,0)';
            // Without the flinging term the loop could stop while the wall
            // still had speed left, because target and current can be close
            // together at the moment of release.
            if (state.flinging
                || Math.abs(state.target - state.current) > 0.1) moving = true;
        }
        raf = (moving || idleDrift) ? requestAnimationFrame(paint) : 0;
    }
    function requestPaint() { if (!raf) raf = requestAnimationFrame(paint); }

    states.forEach(function (state) {
        state.row.addEventListener('wheel', function (event) {
            event.preventDefault();
            var delta = Math.abs(event.deltaX) > Math.abs(event.deltaY) ? event.deltaX : event.deltaY;
            var step = -delta * sensitivity;
            state.target += step;
            // THE WHEEL COASTS TOO, AND IT DID NOT BEFORE.
            //
            // Inertia was built for the drag: pointermove kept a smoothed
            // velocity and release handed it to the fling. The wheel never
            // touched velocity at all -- it moved `target` and stopped. So the
            // wall eased to a halt the instant scrolling stopped, and on a
            // desktop skin, where the wheel is the obvious way to move a wall,
            // there was no momentum to feel anywhere. Sean reported the inertia
            // missing while the drag path had it all along; this is the input he
            // was using.
            //
            // Same smoothing as the drag, so a slow notch does not fling and a
            // fast burst builds up. Repeated notches accumulate because each one
            // adds to a velocity that is still decaying.
            //
            // reduced-motion is honoured here as it is on release: somebody who
            // asked for less movement should not be handed a second of coasting
            // after every notch.
            if (!reduced) {
                state.vel = (state.vel * 0.6) + (step * 0.4);
                state.flinging = Math.abs(state.vel) > GLIDE_VEL_FLOOR;
            }
            requestPaint();
        }, { passive: false });

        state.row.addEventListener('pointerdown', function (event) {
            if (event.button !== 0 && event.pointerType === 'mouse') return;
            state.dragging = true;
            state.moved = false;
            // PUTTING A FINGER ON A COASTING WALL STOPS IT, which is what
            // every touch surface does and what makes a wall feel like an
            // object rather than a video.
            //
            // Clearing the fling is not enough on its own: the fling has
            // already pushed `target` far ahead, and `current` eases toward it,
            // so the wall went on gliding 233px out from under the finger.
            // Measured, not guessed. Grabbing it means taking it WHERE IT IS.
            state.flinging = false;
            state.vel = 0;
            state.target = state.current;
            state.x = event.clientX;
            state.y = event.clientY;
            // Where the press STARTED, kept separate from the running x/y that
            // the per-move delta uses. Travel is measured from here.
            state.startX = event.clientX;
            state.startY = event.clientY;
            state.captured = false;
            // THE is-dragging CLASS IS NOT ADDED HERE EITHER. Codex found the
            // fourth independent cause of Sean's dead clicks in GLIDE's CSS:
            //     .glide-row.is-dragging .glide-tile { pointer-events: none }
            // Because this engine announced "dragging" on pointerdown, that
            // rule disabled the tile before any click could land, so the click
            // hit the row instead of the photograph -- defeating even the
            // deferred pointer capture above.
            // He has removed the rule. But the engine should not have been
            // making the claim: it is not dragging until it is. The class now
            // appears at the same moment as the capture, which makes a rule
            // like that harmless if anyone writes one again, because by then a
            // drag really is under way and no click is owed.
            // CAPTURE IS NOT TAKEN HERE. Capturing on pointerdown retargets the
            // events that follow to the ROW, so a plain click never reached the
            // photograph inside it and the lightbox was never offered the
            // event. Capture is taken in pointermove, once travel says this is
            // actually a drag -- so a real click keeps its own target.
        });
        state.row.addEventListener('pointermove', function (event) {
            if (!state.dragging) return;
            var dx = event.clientX - state.x;
            var dy = event.clientY - state.y;
            // THE WORLD VECTOR: dx/dy are screen pixels, and on screen the
            // wall travels along the leaned direction.
            var projected = (dx * worldX) + (dy * worldY);
            // TOTAL TRAVEL SINCE THE PRESS, not the size of one pointermove.
            // dx/dy above are per-move deltas because x/y are rewritten each
            // move, so the old test "dx + dy > 3" asked whether the pointer
            // JUMPED 3px between two events -- a velocity test wearing a
            // displacement test's clothes. A slow, deliberate drag never
            // tripped it, and a single twitch always did. Sean has a tremor, so
            // he got the worst of both: wobble during an intended click threw
            // the click away, and a careful drag still counted as a click.
            // Raising the old number alone would have made it worse.
            if (Math.abs(event.clientX - state.startX)
                + Math.abs(event.clientY - state.startY) > GLIDE_DRAG_SLOP) {
                state.moved = true;
                // Only now is it honest to say so. See the note in pointerdown.
                state.row.classList.add('is-dragging');
                if (!state.captured) {
                    // Now it IS a drag, so take the pointer. In a try: capture
                    // throws if the pointer is no longer active, and losing the
                    // capture must not abort the drag.
                    try {
                        state.row.setPointerCapture(event.pointerId);
                        state.captured = true;
                    } catch (e) { state.captured = false; }
                }
            }
            state.target += projected * sensitivity;
            // A smoothed recent speed, not the last sample: one twitchy
            // pointermove at the end of a slow drag should not fling the
            // wall across the screen.
            state.vel = (state.vel * 0.6) + (projected * sensitivity * 0.4);
            state.x = event.clientX;
            state.y = event.clientY;
            requestPaint();
        });
        function release(event, cancelled) {
            if (!state.dragging) return;
            state.dragging = false;
            state.row.classList.remove('is-dragging');
            if (state.captured && state.row.hasPointerCapture(event.pointerId)) {
                state.row.releasePointerCapture(event.pointerId);
            }
            state.captured = false;
            // A CANCEL IS FOLLOWED BY NO CLICK, so the moved flag had nothing
            // to consume it and stayed true -- swallowing the NEXT genuine
            // click on that row. pointerup is left alone, because its click
            // has not fired yet and still needs the flag.
            if (cancelled) {
                state.moved = false;
                state.vel = 0;
                state.flinging = false;
                return;
            }
            // Let go of a moving wall and it keeps going. Only after a real
            // drag, never on a click, and never under prefers-reduced-motion
            // -- somebody who asked for less movement should not be handed
            // a second of it after every touch.
            if (state.moved && !reduced
                && Math.abs(state.vel) > GLIDE_VEL_FLOOR) {
                state.flinging = true;
                requestPaint();
            }
        }
        state.row.addEventListener('pointerup', function (event) { release(event, false); });
        state.row.addEventListener('pointercancel', function (event) { release(event, true); });
        state.row.addEventListener('click', function (event) {
            if (state.moved) { event.preventDefault(); event.stopPropagation(); state.moved = false; }
        }, true);
    });

    window.addEventListener('resize', function () {
        fine = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--glide-angle')) || 0;
        worldRadians = ((baseAngles[axis] || 0) + fine) * Math.PI / 180;
        worldX = Math.cos(worldRadians);
        worldY = Math.sin(worldRadians);
        // The local vector carries no lean, so a changed --glide-angle cannot
        // move it. Recomputed anyway: the axis attribute is read once, but if
        // that ever changes the two must not drift apart.
        localRadians = (baseAngles[axis] || 0) * Math.PI / 180;
        localX = Math.cos(localRadians);
        localY = Math.sin(localRadians);
        // vertical is DERIVED from the vector, so it has to be recomputed with
        // it. --glide-angle can move the fine angle across the diagonal at a
        // new viewport size, and a stale flag would measure the wrong edge.
        vertical = alongAxis && Math.abs(localY) > Math.abs(localX);
        // The row height changes at the 720px breakpoint, so the tiles change
        // width and the period changes with them. Re-measure rather than keep
        // wrapping at a distance that was true at the old size.
        for (var i = 0; i < states.length; i++) measure(states[i]);
        requestPaint();
    });
    if ('ResizeObserver' in window) {
        new ResizeObserver(requestPaint).observe(wall);
    }
    requestPaint();
}());
// ===== SNAPSMACK EOF =====
