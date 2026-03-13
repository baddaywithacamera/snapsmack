/**
 * SNAPSMACK - Photogram Engine
 * Alpha v0.7.3
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

        // Touch: touchend fires immediately, preventDefault blocks the
        // follow-on synthetic click so openLightbox isn't called twice.
        var lbTouched = false;
        img.addEventListener('touchend', function (e) {
            e.preventDefault();
            lbTouched = true;
            openLightbox(img.src);
        }, { passive: false });

        // Mouse / desktop click — skip if a touch just fired.
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
            'inset:0',
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
            'max-height:100vh',
            'object-fit:contain',
            'display:block',
            'pointer-events:none',
            '-webkit-user-drag:none'
        ].join(';');

        overlay.appendChild(pic);
        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';
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
    // ══════════════════════════════════════════════════════════════════════
    var likeInFlight = false;

    function initLike() {
        var likeBtn   = document.getElementById('pg-like-btn');
        var likeCount = document.getElementById('pg-like-count');
        if (!likeBtn) return;
        likeBtn.addEventListener('click', function () {
            toggleLike(likeBtn, likeCount, likeBtn.dataset.imageId);
        });
    }

    function toggleLike(btn, countEl, imageId) {
        if (likeInFlight) return;
        likeInFlight = true;

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
            likeInFlight = false;
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
        initNavHighlight();
    }

    // Scripts load at end of <body> — DOMContentLoaded may have already fired.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', _pgInit);
    } else {
        _pgInit();
    }

}());
