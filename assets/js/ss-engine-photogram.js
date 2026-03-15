/**
 * SNAPSMACK - Photogram Engine
 * Alpha v0.7.3a
 *
 * Handles all Photogram-specific interactions:
 *   - Lightbox: tap to open, swipe-down or tap backdrop to dismiss
 *   - Comments bottom sheet (open, close, drag-to-dismiss)
 *   - Like button toggle (optimistic UI)
 *   - Sheet comment input / send (delegates to community component)
 *   - Nav tab active state
 */

(function () {
    'use strict';

    // ── Shared POST helper ─────────────────────────────────────────────────
    function post(url, data, cb) {
        var params = new URLSearchParams();
        for (var k in data) params.append(k, data[k]);
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        })
        .then(function (r) { return r.json(); })
        .then(cb)
        .catch(function (e) { console.warn('[Photogram] POST failed:', e); });
    }


    // ══════════════════════════════════════════════════════════════════════
    //  LIGHTBOX
    //  Mobile-native: tap to open, swipe down to dismiss (with live opacity
    //  feedback), or tap the backdrop. No shared desktop engine dependency.
    // ══════════════════════════════════════════════════════════════════════
    var lbOverlay  = null;
    var lbStartY   = 0;
    var lbDragging = false;

    function initLightbox() {
        var img = document.getElementById('pg-post-image');
        if (!img) return;

        img.style.cursor = 'zoom-in';

        var lbTouched = false;
        img.addEventListener('touchend', function (e) {
            e.preventDefault();
            lbTouched = true;
            openLightbox(img.src);
        }, { passive: false });

        img.addEventListener('click', function () {
            if (lbTouched) { lbTouched = false; return; }
            openLightbox(img.src);
        });
    }

    function openLightbox(src) {
        if (lbOverlay) return;

        var overlay = document.createElement('div');
        overlay.id = 'pg-lightbox';
        overlay.style.cssText = [
            'position:fixed',
            'top:0',
            'right:0',
            'bottom:0',
            'left:0',
            'width:100%',
            'height:100dvh',  /* Dynamic viewport height for mobile */
            'background:rgba(0,0,0,0.92)',
            'display:flex',
            'align-items:center',
            'justify-content:center',
            'z-index:9999',
            'opacity:0',
            'transition:opacity 0.15s ease',
            'touch-action:none',
            'cursor:zoom-out'
        ].join(';');

        var pic = document.createElement('img');
        pic.src = src;
        pic.style.cssText = [
            'max-width:100vw',
            'max-height:100dvh',  /* Dynamic viewport height for mobile */
            'object-fit:contain',
            'display:block',
            'pointer-events:none',
            '-webkit-user-drag:none'
        ].join(';');

        overlay.appendChild(pic);
        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';

        // On mobile, try to request fullscreen to hide browser chrome
        if (overlay.requestFullscreen && window.innerWidth <= 480) {
            overlay.requestFullscreen().catch(function () {
                // Fullscreen denied or not supported — fallback to fixed positioning
            });
        }
        lbOverlay = overlay;

        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                overlay.style.opacity = '1';
            });
        });

        // Swipe down to dismiss — visual feedback tracks the finger.
        overlay.addEventListener('touchstart', function (e) {
            lbStartY   = e.touches[0].clientY;
            lbDragging = true;
            pic.style.transition = 'none';
            overlay.style.transition = 'none';
        }, { passive: true });

        overlay.addEventListener('touchmove', function (e) {
            if (!lbDragging) return;
            var dy = e.touches[0].clientY - lbStartY;
            if (dy < 0) return; // no upward drag
            pic.style.transform  = 'translateY(' + dy + 'px)';
            overlay.style.opacity = String(Math.max(0, 1 - dy / 250));
        }, { passive: true });

        overlay.addEventListener('touchend', function (e) {
            if (!lbDragging) return;
            lbDragging = false;
            var dy = e.changedTouches[0].clientY - lbStartY;
            if (dy > 80) {
                closeLightbox();
            } else {
                // Snap back
                pic.style.transition     = 'transform 0.15s ease';
                pic.style.transform      = '';
                overlay.style.transition = 'opacity 0.15s ease';
                overlay.style.opacity    = '1';
            }
        }, { passive: true });

        // Tap backdrop to close
        overlay.addEventListener('click', closeLightbox);

        // ESC key via hotkey engine
        window.smackdown = window.smackdown || {};
        window.smackdown.closeLightbox = closeLightbox;
    }

    function closeLightbox() {
        if (!lbOverlay) return;
        var el = lbOverlay;
        lbOverlay = null;
        el.style.transition = 'opacity 0.15s ease';
        el.style.opacity    = '0';

        // Exit fullscreen if active
        if (document.fullscreenElement) {
            document.exitFullscreen().catch(function () {});
        }

        setTimeout(function () {
            if (el.parentNode) el.parentNode.removeChild(el);
            document.body.style.overflow = '';
        }, 150);
    }


    // ══════════════════════════════════════════════════════════════════════
    //  BOTTOM SHEET
    // ══════════════════════════════════════════════════════════════════════
    var sheet    = null;
    var backdrop = null;
    var startY   = 0;
    var currentY = 0;
    var dragging = false;

    function initSheet() {
        sheet    = document.getElementById('pg-comments-sheet');
        backdrop = document.getElementById('pg-sheet-backdrop');
        var openBtn  = document.getElementById('pg-open-comments');
        var closeBtn = document.getElementById('pg-sheet-close');
        var handle   = document.getElementById('pg-sheet-handle');
        var body     = document.getElementById('pg-sheet-body');

        if (!sheet || !backdrop) return;

        if (openBtn)  openBtn.addEventListener('click', openSheet);
        if (closeBtn) closeBtn.addEventListener('click', closeSheet);
        backdrop.addEventListener('click', closeSheet);

        if (handle) {
            handle.addEventListener('touchstart', onDragStart, { passive: true });
            handle.addEventListener('touchmove',  onDragMove,  { passive: true });
            handle.addEventListener('touchend',   onDragEnd,   { passive: true });
        }

        if (body) {
            body.addEventListener('touchmove', function (e) { e.stopPropagation(); }, { passive: true });
        }

        initSheetInput();
    }

    function openSheet() {
        if (!sheet) return;
        sheet.classList.add('open');
        backdrop.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeSheet() {
        if (!sheet) return;
        sheet.classList.remove('open');
        backdrop.classList.remove('open');
        document.body.style.overflow = '';
        sheet.style.transform = '';
    }

    function onDragStart(e) {
        startY   = e.touches[0].clientY;
        currentY = 0;
        dragging = true;
        sheet.style.transition = 'none';
    }

    function onDragMove(e) {
        if (!dragging) return;
        var dy = e.touches[0].clientY - startY;
        if (dy < 0) return;
        currentY = dy;
        var clamped = Math.min(dy, sheet.offsetHeight * 0.6);
        sheet.style.transform = window.innerWidth <= 480
            ? 'translateY(' + clamped + 'px)'
            : 'translateX(-50%) translateY(' + clamped + 'px)';
    }

    function onDragEnd() {
        if (!dragging) return;
        dragging = false;
        sheet.style.transition = '';
        if (currentY > 120) {
            closeSheet();
        } else {
            sheet.style.transform = window.innerWidth <= 480
                ? 'translateY(0)'
                : 'translateX(-50%) translateY(0)';
        }
        currentY = 0;
    }


    // ── Sheet comment input ────────────────────────────────────────────────
    function initSheetInput() {
        var input     = document.getElementById('pg-sheet-input');
        var sendBtn   = document.getElementById('pg-sheet-send');
        var sheetBody = document.getElementById('pg-sheet-body');

        if (!input || !sendBtn) return;

        input.addEventListener('input', function () {
            sendBtn.classList.toggle('ready', input.value.trim().length > 0);
        });

        sendBtn.addEventListener('click', function () {
            var text = input.value.trim();
            if (!text) return;

            // Delegate to community component form if present
            var existingForm = sheetBody && sheetBody.querySelector('form.ss-comment-form');
            if (existingForm) {
                var ta = existingForm.querySelector('textarea[name="comment_text"], input[name="comment_text"]');
                if (ta) {
                    ta.value = text;
                    existingForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                    input.value = '';
                    sendBtn.classList.remove('ready');
                    return;
                }
            }

            // Fallback: direct POST
            var likeBtn = document.getElementById('pg-like-btn');
            var imgId   = likeBtn ? likeBtn.dataset.imageId : '';
            if (!imgId) return;

            post('process-comment.php', { img_id: imgId, comment_text: text }, function (data) {
                input.value = '';
                sendBtn.classList.remove('ready');
                if (data && data.success) location.reload();
            });
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendBtn.click();
            }
        });
    }


    // ══════════════════════════════════════════════════════════════════════
    //  LIKE BUTTON
    //  Works for both the solo page (#pg-like-btn) and the feed view
    //  (.pg-feed-like-btn × N). likesInFlight is keyed by image ID so
    //  multiple images can process simultaneously in the feed.
    // ══════════════════════════════════════════════════════════════════════
    var likesInFlight = {};

    function initLike() {
        var likeBtn   = document.getElementById('pg-like-btn');
        var likeCount = document.getElementById('pg-like-count');
        if (!likeBtn) return;
        likeBtn.addEventListener('click', function () {
            toggleLike(likeBtn, likeCount, likeBtn.dataset.imageId);
        });
    }

    function toggleLike(btn, countEl, imageId) {
        if (likesInFlight[imageId]) return;
        likesInFlight[imageId] = true;

        var newLiked = btn.dataset.liked !== '1';
        btn.dataset.liked = newLiked ? '1' : '0';
        btn.classList.toggle('liked', newLiked);
        btn.setAttribute('aria-label', newLiked ? 'Unlike' : 'Like');
        btn.style.transform = 'scale(1.3)';
        setTimeout(function () { btn.style.transform = ''; }, 150);

        if (countEl) {
            var n = (parseInt(countEl.textContent) || 0) + (newLiked ? 1 : -1);
            n = Math.max(0, n);
            countEl.style.display = n ? '' : 'none';
            countEl.textContent   = n + (n === 1 ? ' like' : ' likes');
        }

        post('process-reaction.php', { action: 'toggle_like', img_id: imageId }, function (data) {
            delete likesInFlight[imageId];
            if (data && typeof data.liked !== 'undefined') {
                btn.dataset.liked = data.liked ? '1' : '0';
                btn.classList.toggle('liked', !!data.liked);
                if (countEl && typeof data.like_count !== 'undefined') {
                    var c = Math.max(0, parseInt(data.like_count) || 0);
                    countEl.style.display = c ? '' : 'none';
                    countEl.textContent   = c + (c === 1 ? ' like' : ' likes');
                }
            }
        });
    }

    function spawnHeartBurst(container, e) {
        var burst = document.createElement('span');
        burst.className   = 'pg-heart-burst';
        burst.textContent = '❤️';
        if (e) {
            var rect = container.getBoundingClientRect();
            var src  = e.changedTouches ? e.changedTouches[0] : e;
            burst.style.top       = (src.clientY - rect.top)  + 'px';
            burst.style.left      = (src.clientX - rect.left) + 'px';
            burst.style.transform = 'translate(-50%, -50%) scale(0)';
        }
        container.style.position = 'relative';
        container.appendChild(burst);
        burst.addEventListener('animationend', function () {
            if (burst.parentNode) burst.parentNode.removeChild(burst);
        });
    }


    // ══════════════════════════════════════════════════════════════════════
    //  FEED VIEW
    //  Manages #pg-feed:
    //    - Infinite scroll via IntersectionObserver on #pg-feed-sentinel
    //    - Like button event delegation (multiple .pg-feed-like-btn elements)
    //    - Double-tap on feed images to like (touchend, preventDefault)
    //    - Scroll position save before navigating to solo page, restore on return
    // ══════════════════════════════════════════════════════════════════════

    function initFeed() {
        var feed           = document.getElementById('pg-feed');
        var sentinelBottom = document.getElementById('pg-feed-sentinel');
        var sentinelTop    = document.getElementById('pg-feed-sentinel-top');
        if (!feed) return;

        // ── Absolute post ID bounds (PHP-rendered into the DOM) ──────────
        // maxId = highest published post ID; minId = lowest.
        // Lets us know immediately when the feed has reached the true edge,
        // avoiding ghost AJAX calls that return 0 rows and trigger false
        // "No more posts" messages.
        var maxId = parseInt(feed.dataset.maxId, 10) || 0;
        var minId = parseInt(feed.dataset.minId, 10) || 0;

        // ── Scroll restoration ──────────────────────────────────────────
        // Save key is the full page URL so different feed entry points don't
        // clobber each other.
        var scrollKey = 'pg-feed-scroll:' + window.location.href;
        var savedY    = sessionStorage.getItem(scrollKey);
        if (savedY) {
            sessionStorage.removeItem(scrollKey);
            // Two rAF passes let the browser finish initial layout before jumping.
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    window.scrollTo(0, parseInt(savedY, 10) || 0);
                });
            });
        }

        // ── Scroll to the tapped post when entering from the grid ────────
        // Without this, the top sentinel fires immediately on page load and
        // prepends newer posts above the tapped post, pushing it out of view.
        var fromParam = new URLSearchParams(window.location.search).get('from');
        if (fromParam && !savedY) {
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    var fromPost = feed.querySelector('[data-image-id="' + fromParam + '"]');
                    if (fromPost) fromPost.scrollIntoView({ behavior: 'instant', block: 'start' });
                });
            });
        }

        // ── Save scroll before navigating to a solo page ────────────────
        feed.addEventListener('click', function (e) {
            if (e.target.closest('.pg-feed-solo-link, .pg-feed-image-link')) {
                sessionStorage.setItem(scrollKey, String(window.scrollY));
            }
        });

        // ── Like button delegation ───────────────────────────────────────
        feed.addEventListener('click', function (e) {
            var likeBtn = e.target.closest('.pg-feed-like-btn');
            if (!likeBtn) return;
            e.preventDefault();
            var article    = likeBtn.closest('.pg-feed-item');
            var likeCountEl = article ? article.querySelector('.pg-feed-like-count') : null;
            toggleLike(likeBtn, likeCountEl, likeBtn.dataset.imageId);
        });

        // ── Double-tap to like on feed images ────────────────────────────
        var dtLastTime   = 0;
        var dtLastTarget = null;
        feed.addEventListener('touchend', function (e) {
            var imgLink = e.target.closest('.pg-feed-image-link');
            if (!imgLink) return;

            var now = Date.now();
            if (dtLastTarget === imgLink && (now - dtLastTime) < 300) {
                // Double-tap confirmed — prevent navigation, fire like.
                e.preventDefault();
                dtLastTime   = 0;
                dtLastTarget = null;

                var article     = imgLink.closest('.pg-feed-item');
                if (!article) return;
                var likeBtn     = article.querySelector('.pg-feed-like-btn');
                var likeCountEl = article.querySelector('.pg-feed-like-count');
                var wrap        = imgLink.querySelector('.pg-post-image-wrap');

                if (wrap) spawnHeartBurst(wrap, e);

                // Only fire the like if not already liked (IG behaviour)
                if (likeBtn && likeBtn.dataset.liked !== '1') {
                    toggleLike(likeBtn, likeCountEl, likeBtn.dataset.imageId);
                }
            } else {
                dtLastTime   = now;
                dtLastTarget = imgLink;
            }
        }, { passive: false });

        // ── Bidirectional infinite scroll via IntersectionObserver ──────────
        if (!window.IntersectionObserver) return;

        var loadingDown = false;
        var loadingUp   = false;

        // ─ Bottom sentinel: load older posts ────────────────────────────
        if (sentinelBottom) {
            var observerDown = new IntersectionObserver(function (entries) {
                if (!entries[0].isIntersecting || loadingDown) return;

                var cursor = sentinelBottom.dataset.cursor;
                if (!cursor || cursor === '0') {
                    observerDown.disconnect();
                    sentinelBottom.classList.add('pg-feed-end');
                    sentinelBottom.textContent = '— No older posts —';
                    return;
                }

                loadingDown = true;
                sentinelBottom.classList.add('pg-feed-loading');

                var ajaxUrl = window.location.pathname
                    + '?pg=feed&format=json&cursor=' + encodeURIComponent(cursor);

                fetch(ajaxUrl)
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        loadingDown = false;
                        sentinelBottom.classList.remove('pg-feed-loading');

                        if (data.html) {
                            var tmp = document.createElement('div');
                            tmp.innerHTML = data.html;
                            while (tmp.firstChild) {
                                feed.insertBefore(tmp.firstChild, sentinelBottom);
                            }
                        }

                        // Done if: server says no more, no cursor, or we've
                        // reached the absolute oldest post ID.
                        var reachedBottom = !data.has_more
                            || !data.next_cursor
                            || (minId > 0 && data.next_cursor <= minId);

                        if (!reachedBottom) {
                            sentinelBottom.dataset.cursor = String(data.next_cursor);
                            observerDown.disconnect();
                            observerDown.observe(sentinelBottom);
                        } else {
                            observerDown.disconnect();
                            sentinelBottom.classList.add('pg-feed-end');
                            sentinelBottom.textContent = '— No older posts —';
                        }
                    })
                    .catch(function (err) {
                        loadingDown = false;
                        sentinelBottom.classList.remove('pg-feed-loading');
                        console.warn('[Photogram] Feed load (down) failed:', err);
                    });

            }, { rootMargin: '0px 0px 300px 0px' });

            observerDown.observe(sentinelBottom);
        }

        // ─ Top sentinel: load newer posts via scroll event ──────────────
        // IntersectionObserver has too many edge cases for upward scroll
        // (fires on page load, doesn't re-fire after reconnect, etc.).
        // A scroll listener + getBoundingClientRect is simpler and reliable.
        if (sentinelTop) {
            var topDone = false;

            function loadNewer() {
                if (topDone || loadingUp) return;
                var cursor = sentinelTop.dataset.cursor;
                if (!cursor || cursor === '0') {
                    topDone = true;
                    sentinelTop.classList.add('pg-feed-end');
                    sentinelTop.textContent = '— No newer posts —';
                    return;
                }
                // Only fire when the sentinel is within the top 200px of viewport
                var rect = sentinelTop.getBoundingClientRect();
                if (rect.top > 200) return;

                loadingUp = true;
                sentinelTop.classList.add('pg-feed-loading');

                var ajaxUrl = window.location.pathname
                    + '?pg=feed&format=json&newer=1&cursor=' + encodeURIComponent(cursor);

                fetch(ajaxUrl)
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        loadingUp = false;
                        sentinelTop.classList.remove('pg-feed-loading');

                        if (data.html) {
                            var tmp = document.createElement('div');
                            tmp.innerHTML = data.html;
                            // Keep anchor fixed at the current first post so that
                            // new posts stack newest-first just below the sentinel.
                            // (Updating anchor each iteration reverses insertion order.)
                            var anchor = sentinelTop.nextElementSibling;
                            while (tmp.firstChild) {
                                feed.insertBefore(tmp.firstChild, anchor);
                            }
                        }

                        // Done if: server says no more, no cursor, or next_cursor
                        // has reached the absolute newest post ID.
                        var reachedTop = !data.has_more
                            || !data.next_cursor
                            || (maxId > 0 && data.next_cursor >= maxId);

                        if (!reachedTop) {
                            sentinelTop.dataset.cursor = String(data.next_cursor);
                        } else {
                            topDone = true;
                            sentinelTop.classList.add('pg-feed-end');
                            sentinelTop.textContent = '— No newer posts —';
                        }
                    })
                    .catch(function (err) {
                        loadingUp = false;
                        sentinelTop.classList.remove('pg-feed-loading');
                        console.warn('[Photogram] Feed load (up) failed:', err);
                    });
            }

            // Don't start watching until after the scroll-to-from-post settles
            var topReady = false;
            setTimeout(function () {
                topReady = true;
                loadNewer(); // check immediately in case already at top
            }, fromParam && !savedY ? 500 : 50);

            window.addEventListener('scroll', function () {
                if (topReady) loadNewer();
            }, { passive: true });
        }
    }


    // ══════════════════════════════════════════════════════════════════════
    //  NAV TAB ACTIVE STATE
    // ══════════════════════════════════════════════════════════════════════
    function initNavHighlight() {
        var tabs = document.querySelectorAll('.pg-nav-tab');
        if (!tabs.length) return;
        var path = window.location.pathname + window.location.search;
        tabs.forEach(function (tab) {
            if (tab.getAttribute('href') === path) {
                tabs.forEach(function (t) { t.classList.remove('active'); });
                tab.classList.add('active');
            }
        });
    }


    // ══════════════════════════════════════════════════════════════════════
    //  INIT
    // ══════════════════════════════════════════════════════════════════════
    function _pgInit() {
        initLightbox();
        initSheet();
        initLike();
        initFeed();
        initNavHighlight();
    }

    // Scripts load at end of <body> — DOMContentLoaded may have already fired.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', _pgInit);
    } else {
        _pgInit();
    }

}());
