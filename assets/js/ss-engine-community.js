/**
 * SNAPSMACK - Community Engine
 *
 * Handles all client-side behaviour for the community system:
 *   - Like button toggle (AJAX, optimistic UI) — both inline bar and floating dock
 *   - Reaction picker open/close and reaction toggle (AJAX) — both inline and dock
 *   - Comment form expand/submit/append (AJAX)
 *   - Comment delete (AJAX, removes DOM node)
 *   - Auth nudge: redirect to sign-in when unauthenticated action attempted
 *
 * Requires: ss-community.css
 * Talks to: /process-like.php, /process-community-comment.php
 *
 * Double-load guard prevents duplicate listeners if the script is
 * included by both a skin manifest and footer-scripts.php.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


if (!window._ssCommunityLoaded) {
window._ssCommunityLoaded = true;

document.addEventListener('DOMContentLoaded', () => {

    // =========================================================================
    // SHARED UTILITY
    // =========================================================================

    function post(url, data) {
        const fd = new FormData();
        Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        return fetch(url, { method: 'POST', body: fd })
            .then(r => r.json());
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // =========================================================================
    // COMMUNITY COMPONENT (in-flow: likes bar + reactions + comments)
    // =========================================================================

    // Wire the in-flow community block. Scoped so it runs against the page at load
    // AND against a post fragment injected into a modal at runtime (The Grid modal).
    function wireCommunity(scope) {
        const root = (scope || document).querySelector('.ss-community');
        if (!root) return;
        if (root.dataset.ssWired === '1') return;  // don't double-wire the same node
        root.dataset.ssWired = '1';

        const postId      = root.dataset.postId;
        const authUrl     = root.dataset.authUrl;
        const loggedIn    = root.dataset.loggedIn === '1';
        const commentMode = root.dataset.commentMode || 'open'; // open | hybrid | registered

        // --- Like button ---
        const likeBtn   = root.querySelector('.ss-like-btn');
        const likeIcon  = root.querySelector('.ss-like-icon');
        const likeCount = root.querySelector('.ss-like-count');

        if (likeBtn) {
            likeBtn.addEventListener('click', () => {
                // Anonymous likes allowed — no auth redirect for likes

                const wasLiked  = likeBtn.dataset.liked === '1';
                const prevCount = parseInt(likeCount ? likeCount.textContent : '0', 10) || 0;
                const nowLiked  = !wasLiked;

                // Optimistic update
                likeBtn.dataset.liked = nowLiked ? '1' : '0';
                likeBtn.classList.toggle('is-liked', nowLiked);

                if (likeCount) likeCount.textContent = prevCount + (nowLiked ? 1 : -1);
                likeBtn.setAttribute('aria-pressed', String(nowLiked));
                likeBtn.setAttribute('aria-label', (nowLiked ? 'Unlike' : 'Like') + ' this post');

                post('/process-like.php', { post_id: postId })
                    .then(data => {
                        if (data.error) {
                            likeBtn.dataset.liked = wasLiked ? '1' : '0';
                            likeBtn.classList.toggle('is-liked', wasLiked);
            
                            if (likeCount) likeCount.textContent = prevCount;
                        } else {
                            likeBtn.dataset.liked = data.liked ? '1' : '0';
                            likeBtn.classList.toggle('is-liked', data.liked);
            
                            if (likeCount) likeCount.textContent = data.count;
                        }
                    })
                    .catch(() => {
                        likeBtn.dataset.liked = wasLiked ? '1' : '0';
                        likeBtn.classList.toggle('is-liked', wasLiked);
        
                        if (likeCount) likeCount.textContent = prevCount;
                    });
            });
        }

        // --- Comment form ---
        const commentForm      = root.querySelector('.ss-comment-form');
        const commentActions   = root.querySelector('.ss-comment-actions');
        const commentArea      = root.querySelector('.ss-comment-textarea');
        const cancelBtn        = root.querySelector('.ss-comment-cancel');
        const statusEl         = root.querySelector('.ss-comment-status');
        const thread           = root.querySelector('.ss-comment-thread');
        const guestNameInput   = commentForm ? commentForm.querySelector('.ss-guest-name')  : null;
        const guestEmailInput  = commentForm ? commentForm.querySelector('.ss-guest-email') : null;

        if (commentArea && commentActions) {
            commentArea.addEventListener('focus', () => {
                commentActions.hidden = false;
                commentArea.rows = 3;
            });
            commentArea.addEventListener('input', () => {
                commentArea.style.height = 'auto';
                commentArea.style.height = commentArea.scrollHeight + 'px';
            });
            if (cancelBtn) {
                cancelBtn.addEventListener('click', () => {
                    commentArea.value = '';
                    commentArea.style.height = '';
                    commentArea.rows = 1;
                    commentActions.hidden = true;
                    setStatus('');
                });
            }
        }

        if (commentForm) {
            commentForm.addEventListener('submit', e => {
                e.preventDefault();

                // Registered mode with no session: redirect to sign-in
                if (commentMode === 'registered' && !loggedIn) {
                    window.location.href = authUrl;
                    return;
                }

                const text = commentArea ? commentArea.value.trim() : '';
                if (!text) return;

                // Guest name is required in open/hybrid mode when not logged in
                if (!loggedIn && guestNameInput && !guestNameInput.value.trim()) {
                    setStatus('Please enter your name before posting.');
                    guestNameInput.focus();
                    return;
                }

                const submitBtn = commentForm.querySelector('.ss-comment-submit');
                if (submitBtn) submitBtn.disabled = true;
                setStatus('Posting...');

                const payload = { post_id: postId, comment_text: text };
                if (guestNameInput)  payload.guest_name  = guestNameInput.value.trim();
                if (guestEmailInput) payload.guest_email = guestEmailInput.value.trim();
                if (window.__ssFpHash) payload.fp_hash   = window.__ssFpHash;

                post('/process-community-comment.php', payload)
                .then(data => {
                    if (data.error) {
                        const msgs = {
                            rate_limited:        'Too many comments. Slow down.',
                            email_not_verified:  'Verify your email before commenting.',
                            comments_disabled:   'Comments are currently disabled.',
                            empty_comment:       'Comment cannot be empty.',
                            comment_too_long:    'Comment is too long (max 2000 characters).',
                            guest_name_required: 'Please enter your name.',
                            guest_name_too_long: 'Name is too long (max 100 characters).',
                        };
                        setStatus(msgs[data.error] || 'Something went wrong. Try again.');
                    } else {
                        appendComment(data);
                        commentArea.value = '';
                        commentArea.style.height = '';
                        commentArea.rows = 1;
                        if (commentActions) commentActions.hidden = true;
                        setStatus('');
                    }
                })
                .catch(() => setStatus('Network error. Try again.'))
                .finally(() => {
                    if (submitBtn) submitBtn.disabled = false;
                });
            });
        }

        function setStatus(msg) {
            if (statusEl) statusEl.textContent = msg;
        }

        function appendComment(data) {
            let threadEl = thread;
            if (!threadEl) {
                threadEl = document.createElement('div');
                threadEl.className = 'ss-comment-thread';
                threadEl.setAttribute('aria-label', 'Comments');
                const commentsSection = root.querySelector('.ss-comments');
                if (commentsSection) commentsSection.insertBefore(threadEl, commentsSection.firstChild);
            }

            const isGuest = !!data.is_guest;
            const display = isGuest
                ? (data.guest_name || 'Anonymous')
                : (data.display_name || data.username || '?');
            const initial = display.charAt(0).toUpperCase();

            // Delete button only for authenticated account comments
            const deleteBtn = !isGuest
                ? `<button class="ss-comment-delete" data-comment-id="${data.comment_id}"
                           aria-label="Delete comment">✕</button>`
                : '';

            const node = document.createElement('div');
            node.className = 'ss-comment';
            node.dataset.commentId = data.comment_id;
            node.innerHTML = `
                <div class="ss-comment-meta">
                    ${(!isGuest && data.avatar_url)
                        ? `<img src="${escHtml(data.avatar_url)}" alt="" class="ss-avatar" width="28" height="28">`
                        : `<span class="ss-avatar-placeholder" aria-hidden="true">${escHtml(initial)}</span>`
                    }
                    <span class="ss-commenter">${escHtml(display)}</span>
                    <span class="ss-comment-date">${escHtml(data.date_label)}</span>
                    ${deleteBtn}
                </div>
                <div class="ss-comment-body">${escHtml(data.comment_text).replace(/\n/g, '<br>')}</div>
            `;
            threadEl.appendChild(node);
            if (!isGuest) {
                wireDeleteButton(node.querySelector('.ss-comment-delete'));
            }
        }

        // --- Comment delete ---
        root.querySelectorAll('.ss-comment-delete').forEach(wireDeleteButton);

        function wireDeleteButton(btn) {
            if (!btn) return;
            btn.addEventListener('click', () => {
                const commentId = btn.dataset.commentId;
                if (!confirm('Delete this comment?')) return;
                post('/process-community-comment.php', { action: 'delete', comment_id: commentId })
                    .then(data => {
                        if (data.deleted) {
                            const node = root.querySelector(`.ss-comment[data-comment-id="${commentId}"]`);
                            if (node) node.remove();
                        }
                    });
            });
        }

    } // end wireCommunity (in-flow community component)


    // =========================================================================
    // COMMUNITY DOCK (single heart FAB)
    // =========================================================================

    const dock = document.getElementById('ss-community-dock');
    if (dock) {

        const dockPostId   = dock.dataset.postId;
        const dockHeartBtn = dock.querySelector('.ss-cdock-heart');

        if (dockHeartBtn) {
            const setDockCount = (count) => {
                let el = dockHeartBtn.querySelector('.ss-cdock-rx-count');
                if (!el && count > 0) {
                    el = document.createElement('span');
                    el.className = 'ss-cdock-rx-count';
                    dockHeartBtn.appendChild(el);
                }
                if (el) { if (count > 0) { el.textContent = count; } else { el.remove(); } }
            };
            dockHeartBtn.addEventListener('click', () => {
                const wasLiked  = dockHeartBtn.dataset.liked === '1';
                const cEl       = dockHeartBtn.querySelector('.ss-cdock-rx-count');
                const prevCount = cEl ? (parseInt(cEl.textContent, 10) || 0) : 0;
                const nowLiked  = !wasLiked;
                // Optimistic
                dockHeartBtn.dataset.liked = nowLiked ? '1' : '0';
                dockHeartBtn.classList.toggle('is-active', nowLiked);
                dockHeartBtn.setAttribute('aria-pressed', String(nowLiked));
                dockHeartBtn.title = nowLiked ? 'Unlike' : 'Like';
                post('/process-like.php', { post_id: dockPostId })
                    .then(data => {
                        if (data.error) {
                            dockHeartBtn.dataset.liked = wasLiked ? '1' : '0';
                            dockHeartBtn.classList.toggle('is-active', wasLiked);
                            dockHeartBtn.setAttribute('aria-pressed', String(wasLiked));
                            setDockCount(prevCount);
                        } else {
                            dockHeartBtn.dataset.liked = data.liked ? '1' : '0';
                            dockHeartBtn.classList.toggle('is-active', !!data.liked);
                            dockHeartBtn.setAttribute('aria-pressed', String(!!data.liked));
                            dockHeartBtn.title = data.liked ? 'Unlike' : 'Like';
                            setDockCount(data.count);
                        }
                    })
                    .catch(() => {
                        dockHeartBtn.dataset.liked = wasLiked ? '1' : '0';
                        dockHeartBtn.classList.toggle('is-active', wasLiked);
                        dockHeartBtn.setAttribute('aria-pressed', String(wasLiked));
                        setDockCount(prevCount);
                    });
            });
        }

    } // end dock

    // Wire any community block already present in the initial page.
    wireCommunity(document);

    // The Grid (and any skin) injects the post — with its own .ss-community — into a
    // modal after load. tg-modal.js fires snapsmack:modal:opened on the injected
    // fragment; wire that block too so likes/comments respond inside the modal.
    document.addEventListener('snapsmack:modal:opened', function (e) {
        wireCommunity((e && e.target) || document);
    });

}); // DOMContentLoaded

} // double-load guard


// =============================================================================
// SHARED SKIN UI ACTIONS
// Delegated handlers so skin markup triggers common UI behaviours via a
// data-attribute hook instead of an inline onclick= (skins ship no JavaScript;
// all JS lives here in the shared library). Kept in this engine because every
// grid-family skin that uses these buttons already loads it. Document-level
// delegation — isolated from the community code above, works for dynamically
// inserted markup, and reproduces the exact prior inline behaviour.
//   data-ss-action="history-back"                     -> history.back()
//   data-ss-action="scroll-to" data-ss-target="SEL"   -> smooth-scroll to SEL
// =============================================================================
if (!window._ssUiActionsLoaded) {
    window._ssUiActionsLoaded = true;
    document.addEventListener('click', function (e) {
        const el = e.target.closest('[data-ss-action]');
        if (!el) return;
        const action = el.getAttribute('data-ss-action');
        if (action === 'history-back') {
            history.back();
        } else if (action === 'scroll-to') {
            const sel = el.getAttribute('data-ss-target');
            const target = sel && document.querySelector(sel);
            if (target) target.scrollIntoView({ behavior: 'smooth' });
        }
    });
}
// ===== SNAPSMACK EOF =====
