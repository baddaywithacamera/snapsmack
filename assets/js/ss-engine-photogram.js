/**
 * SNAPSMACK - Photogram Engine
 * Alpha v0.7.3
 *
 * Handles all Photogram-specific interactions:
 *   - Comments bottom sheet (open, close, drag-to-dismiss)
 *   - Double-tap to like with heart burst animation
 *   - Like button toggle (optimistic UI)
 *   - Sheet comment input / send (delegates to community component)
 *   - Nav tab active state
 */

(function () {
    'use strict';
    console.log('[PG] ss-engine-photogram.js: script parsed');

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

        // Open
        if (openBtn) {
            openBtn.addEventListener('click', openSheet);
        }

        // Close via button
        if (closeBtn) {
            closeBtn.addEventListener('click', closeSheet);
        }

        // Close via backdrop
        backdrop.addEventListener('click', closeSheet);

        // Drag-to-dismiss on handle
        if (handle) {
            handle.addEventListener('touchstart', onDragStart, { passive: true });
            handle.addEventListener('touchmove',  onDragMove,  { passive: true });
            handle.addEventListener('touchend',   onDragEnd,   { passive: true });
        }

        // Prevent body scroll while sheet is open
        if (body) {
            body.addEventListener('touchmove', function (e) { e.stopPropagation(); }, { passive: true });
        }

        // Input / send wiring
        initSheetInput();
    }

    function openSheet() {
        if (!sheet) return;
        sheet.classList.add('open');
        backdrop.classList.add('open');
        document.body.style.overflow = 'hidden';

        // Focus input after animation
        var input = document.getElementById('pg-sheet-input');
        if (input) {
            setTimeout(function () { /* don't auto-focus — keyboard pops on mobile */ }, 300);
        }
    }

    function closeSheet() {
        if (!sheet) return;
        sheet.classList.remove('open');
        backdrop.classList.remove('open');
        document.body.style.overflow = '';
        sheet.style.transform = '';
    }

    // Touch drag-to-dismiss
    function onDragStart(e) {
        startY   = e.touches[0].clientY;
        currentY = 0;
        dragging = true;
        sheet.style.transition = 'none';
    }

    function onDragMove(e) {
        if (!dragging) return;
        var dy = e.touches[0].clientY - startY;
        if (dy < 0) return; // don't allow dragging up
        currentY = dy;
        // Clamp visual feedback to 60% of sheet height
        var maxDrag = sheet.offsetHeight * 0.6;
        var clamped = Math.min(dy, maxDrag);
        var isMobile = window.innerWidth <= 480;
        if (isMobile) {
            sheet.style.transform = 'translateY(' + clamped + 'px)';
        } else {
            sheet.style.transform = 'translateX(-50%) translateY(' + clamped + 'px)';
        }
    }

    function onDragEnd() {
        if (!dragging) return;
        dragging = false;
        sheet.style.transition = '';

        // If dragged more than 120px down, dismiss
        if (currentY > 120) {
            closeSheet();
        } else {
            // Snap back
            var isMobile = window.innerWidth <= 480;
            if (isMobile) {
                sheet.style.transform = 'translateY(0)';
            } else {
                sheet.style.transform = 'translateX(-50%) translateY(0)';
            }
        }
        currentY = 0;
    }


    // ── Sheet comment input ────────────────────────────────────────────────
    function initSheetInput() {
        var input    = document.getElementById('pg-sheet-input');
        var sendBtn  = document.getElementById('pg-sheet-send');
        var sheetBody = document.getElementById('pg-sheet-body');

        if (!input || !sendBtn) return;

        // Enable send button only when input has content
        input.addEventListener('input', function () {
            if (input.value.trim().length > 0) {
                sendBtn.classList.add('ready');
            } else {
                sendBtn.classList.remove('ready');
            }
        });

        sendBtn.addEventListener('click', function () {
            var text = input.value.trim();
            if (!text) return;

            // Delegate to the community component's comment form if it exists
            // (Submits via the existing ss-engine-community form handler)
            var existingForm = sheetBody ? sheetBody.querySelector('form.ss-comment-form') : null;
            if (existingForm) {
                var hiddenText = existingForm.querySelector('textarea[name="comment_text"], input[name="comment_text"]');
                if (hiddenText) {
                    hiddenText.value = text;
                    existingForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                    input.value = '';
                    sendBtn.classList.remove('ready');
                    return;
                }
            }

            // Fallback: direct POST to process-comment.php
            var imgId = document.getElementById('pg-like-btn') ?
                document.getElementById('pg-like-btn').dataset.imageId : '';
            if (!imgId) return;

            post('process-comment.php', {
                img_id:       imgId,
                comment_text: text
            }, function (data) {
                input.value = '';
                sendBtn.classList.remove('ready');
                if (data && data.success) {
                    // Reload comments section
                    location.reload();
                }
            });
        });

        // Submit on Enter key
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendBtn.click();
            }
        });
    }


    // ══════════════════════════════════════════════════════════════════════
    //  LIKE BUTTON & DOUBLE-TAP
    // ══════════════════════════════════════════════════════════════════════
    var likeInFlight = false;

    // initLike handles only the action-bar heart button click.
    // Image-tap is wired separately in initImageTap so the lightbox always
    // works even if the like system didn't render (DB issue, missing table, etc.)
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

        var isLiked  = btn.dataset.liked === '1';
        var newLiked = !isLiked;

        // Optimistic UI
        btn.dataset.liked = newLiked ? '1' : '0';
        btn.classList.toggle('liked', newLiked);
        btn.setAttribute('aria-label', newLiked ? 'Unlike' : 'Like');

        if (countEl) {
            var current = parseInt(countEl.textContent) || 0;
            var updated = newLiked ? current + 1 : Math.max(0, current - 1);
            if (updated === 0) {
                countEl.style.display = 'none';
            } else {
                countEl.style.display = '';
                countEl.textContent = updated + (updated === 1 ? ' like' : ' likes');
            }
        }

        // Animate the like button
        btn.style.transform = 'scale(1.3)';
        setTimeout(function () { btn.style.transform = ''; }, 150);

        // POST to server
        post('process-reaction.php', {
            action:   'toggle_like',
            img_id:   imageId
        }, function (data) {
            likeInFlight = false;
            if (data && typeof data.liked !== 'undefined') {
                // Server confirmed — sync state
                btn.dataset.liked = data.liked ? '1' : '0';
                btn.classList.toggle('liked', !!data.liked);
                if (countEl && typeof data.like_count !== 'undefined') {
                    var count = parseInt(data.like_count) || 0;
                    if (count === 0) {
                        countEl.style.display = 'none';
                    } else {
                        countEl.style.display = '';
                        countEl.textContent = count + (count === 1 ? ' like' : ' likes');
                    }
                }
            }
        });
    }

    function spawnHeartBurst(container, e) {
        var burst = document.createElement('span');
        burst.className = 'pg-heart-burst';
        burst.textContent = '❤️';

        // Position at tap/click location relative to container
        if (e) {
            var rect = container.getBoundingClientRect();
            var x, y;
            if (e.changedTouches && e.changedTouches[0]) {
                x = e.changedTouches[0].clientX - rect.left;
                y = e.changedTouches[0].clientY - rect.top;
            } else {
                x = e.clientX - rect.left;
                y = e.clientY - rect.top;
            }
            burst.style.top  = y + 'px';
            burst.style.left = x + 'px';
            burst.style.transform = 'translate(-50%, -50%) scale(0)';
        }

        container.style.position = 'relative';
        container.appendChild(burst);

        // Remove after animation
        burst.addEventListener('animationend', function () {
            if (burst.parentNode) burst.parentNode.removeChild(burst);
        });
    }


    // ══════════════════════════════════════════════════════════════════════
    //  NAV TAB ACTIVE STATE
    //  (Handles back/forward navigation restoring correct active tab)
    // ══════════════════════════════════════════════════════════════════════
    function initNavHighlight() {
        var tabs = document.querySelectorAll('.pg-nav-tab');
        if (!tabs.length) return;

        // The PHP sets .active on render; this just ensures it's right
        // if the page was loaded via fragment/history.
        var path = window.location.pathname + window.location.search;
        tabs.forEach(function (tab) {
            var href = tab.getAttribute('href') || '';
            if (href && path === href) {
                tabs.forEach(function (t) { t.classList.remove('active'); });
                tab.classList.add('active');
            }
        });
    }


    // ══════════════════════════════════════════════════════════════════════
    //  INIT
    // ══════════════════════════════════════════════════════════════════════
    document.addEventListener('DOMContentLoaded', function () {
        console.log('[PG] DOMContentLoaded fired');
        console.log('[PG] #pg-comments-sheet:', document.getElementById('pg-comments-sheet'));
        console.log('[PG] #pg-like-btn:', document.getElementById('pg-like-btn'));
        initSheet();
        initLike();
        initNavHighlight();
    });

}());
