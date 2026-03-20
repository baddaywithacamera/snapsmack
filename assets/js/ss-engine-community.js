/**
 * SNAPSMACK - Community Engine
 * Alpha v0.7.5
 *
 * Handles all client-side behaviour for the community system:
 *   - Like button toggle (AJAX, optimistic UI) — both inline bar and floating dock
 *   - Reaction picker open/close and reaction toggle (AJAX) — both inline and dock
 *   - Comment form expand/submit/append (AJAX)
 *   - Comment delete (AJAX, removes DOM node)
 *   - Auth nudge: redirect to sign-in when unauthenticated action attempted
 *
 * Requires: ss-community.css
 * Talks to: /process-like.php, /process-reaction.php, /process-community-comment.php
 *
 * Double-load guard prevents duplicate listeners if the script is
 * included by both a skin manifest and footer-scripts.php.
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

    const root = document.querySelector('.ss-community');
    if (root) {

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

        // --- Reaction picker ---
        const reactionWrap    = root.querySelector('.ss-reactions-wrap');
        const reactionTrigger = root.querySelector('.ss-reaction-trigger');
        const reactionPicker  = root.querySelector('.ss-reaction-picker');

        if (reactionTrigger && reactionPicker) {

            reactionTrigger.addEventListener('click', () => {
                // Anonymous reactions allowed — no auth gate
                const isOpen = !reactionPicker.hidden;
                reactionPicker.hidden = isOpen;
                reactionTrigger.setAttribute('aria-expanded', String(!isOpen));
            });

            document.addEventListener('click', e => {
                if (reactionWrap && !reactionWrap.contains(e.target)) {
                    reactionPicker.hidden = true;
                    reactionTrigger.setAttribute('aria-expanded', 'false');
                }
            });

            reactionPicker.querySelectorAll('.ss-reaction-opt').forEach(btn => {
                btn.addEventListener('click', () => {
                    const code = btn.dataset.code;
                    post('/process-reaction.php', { post_id: postId, reaction_code: code })
                        .then(data => {
                            if (data.error) return;

                            const addSpan = reactionTrigger.querySelector('.ss-reaction-add');
                            if (data.reaction) {
                                const chosenBtn = reactionPicker.querySelector(`[data-code="${data.reaction}"]`);
                                if (chosenBtn) {
                                    reactionTrigger.textContent = chosenBtn.textContent.trim().charAt(0);
                                }
                                reactionTrigger.classList.add('has-reaction');
                            } else {
                                reactionTrigger.textContent = '';
                                const span = document.createElement('span');
                                span.className = 'ss-reaction-add';
                                span.textContent = '＋';
                                reactionTrigger.appendChild(span);
                                reactionTrigger.classList.remove('has-reaction');
                            }

                            reactionPicker.querySelectorAll('.ss-reaction-opt').forEach(b => {
                                b.classList.toggle('is-active', b.dataset.code === data.reaction);
                            });

                            updateInlineReactionSummary(data.counts);

                            reactionPicker.hidden = true;
                            reactionTrigger.setAttribute('aria-expanded', 'false');
                        });
                });
            });
        }

        function updateInlineReactionSummary(counts) {
            const summary = root.querySelector('.ss-reaction-summary');
            if (!summary) return;
            summary.innerHTML = '';
            Object.entries(counts).forEach(([code, count]) => {
                const btn = reactionPicker ? reactionPicker.querySelector(`[data-code="${code}"]`) : null;
                if (!btn) return;
                const emoji = btn.textContent.trim().charAt(0);
                const pill = document.createElement('span');
                pill.className = 'ss-rx-pill';
                pill.innerHTML = `${emoji} <span>${count}</span>`;
                summary.appendChild(pill);
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

    } // end root (community component)


    // =========================================================================
    // COMMUNITY DOCK (single FAB: unified picker with heart + reactions)
    // =========================================================================

    const dock = document.getElementById('ss-community-dock');
    if (dock) {

        const dockPostId   = dock.dataset.postId;
        const dockReactBtn = dock.querySelector('.ss-cdock-react-btn');
        const dockPicker   = dock.querySelector('.ss-cdock-picker');
        const dockHeartBtn = dock ? dock.querySelector('.ss-cdock-heart') : null;

        if (dockReactBtn && dockPicker) {

            // Open / close picker on trigger click
            dockReactBtn.addEventListener('click', e => {
                e.stopPropagation();
                const isOpen = !dockPicker.hidden;
                dockPicker.hidden = isOpen;
                dockReactBtn.setAttribute('aria-expanded', String(!isOpen));
            });

            // Close picker on outside click
            document.addEventListener('click', e => {
                if (!dock.contains(e.target)) {
                    dockPicker.hidden = true;
                    dockReactBtn.setAttribute('aria-expanded', 'false');
                }
            });

            // --- Heart / Like (first button in picker) ---
            if (dockHeartBtn) {
                dockHeartBtn.addEventListener('click', () => {
                    const wasLiked  = dockHeartBtn.dataset.liked === '1';
                    const countEl   = dockHeartBtn.querySelector('.ss-cdock-rx-count');
                    const emojiEl   = dockHeartBtn.querySelector('.ss-cdock-emoji');
                    const prevCount = countEl ? (parseInt(countEl.textContent, 10) || 0) : 0;
                    const nowLiked  = !wasLiked;

                    // Optimistic update
                    dockHeartBtn.dataset.liked = nowLiked ? '1' : '0';
                    dockHeartBtn.classList.toggle('is-active', nowLiked);
                    // SVG fill state handled by CSS via is-active class

                    post('/process-like.php', { post_id: dockPostId })
                        .then(data => {
                            if (data.error) {
                                dockHeartBtn.dataset.liked = wasLiked ? '1' : '0';
                                dockHeartBtn.classList.toggle('is-active', wasLiked);
                                // SVG fill state handled by CSS via is-active class
                                updateHeartCount(countEl, prevCount);
                            } else {
                                dockHeartBtn.dataset.liked = data.liked ? '1' : '0';
                                dockHeartBtn.classList.toggle('is-active', data.liked);
                                // SVG fill state handled by CSS via is-active class
                                updateHeartCount(countEl, data.count);
                                updateDockTriggerFace(null, data.liked);
                            }
                        })
                        .catch(() => {
                            dockHeartBtn.dataset.liked = wasLiked ? '1' : '0';
                            dockHeartBtn.classList.toggle('is-active', wasLiked);
                            // SVG fill state handled by CSS via is-active class
                            updateHeartCount(countEl, prevCount);
                        });

                    dockPicker.hidden = true;
                    dockReactBtn.setAttribute('aria-expanded', 'false');
                });
            }

            function updateHeartCount(el, count) {
                if (!el && count > 0) {
                    el = document.createElement('span');
                    el.className = 'ss-cdock-rx-count';
                    dockHeartBtn.appendChild(el);
                }
                if (el) {
                    if (count > 0) { el.textContent = count; }
                    else            { el.remove(); }
                }
            }

            // --- Reaction selection (non-heart buttons) ---
            dockPicker.querySelectorAll('.ss-cdock-reaction:not(.ss-cdock-heart)').forEach(btn => {
                btn.addEventListener('click', () => {
                    const code = btn.dataset.code;

                    post('/process-reaction.php', { post_id: dockPostId, reaction_code: code })
                        .then(data => {
                            if (data.error) return;

                            // Update trigger face
                            updateDockTriggerFace(data.reaction, null);

                            // Update active states on reaction buttons (not heart)
                            dockPicker.querySelectorAll('.ss-cdock-reaction:not(.ss-cdock-heart)').forEach(b => {
                                const isActive = b.dataset.code === data.reaction;
                                b.classList.toggle('is-active', isActive);
                                b.setAttribute('aria-pressed', String(isActive));
                            });

                            // Update count badges
                            dockPicker.querySelectorAll('.ss-cdock-reaction:not(.ss-cdock-heart)').forEach(b => {
                                const rxCode = b.dataset.code;
                                let cntEl    = b.querySelector('.ss-cdock-rx-count');
                                const cnt    = data.counts[rxCode] || 0;
                                if (cnt > 0) {
                                    if (!cntEl) {
                                        cntEl = document.createElement('span');
                                        cntEl.className = 'ss-cdock-rx-count';
                                        b.appendChild(cntEl);
                                    }
                                    cntEl.textContent = cnt;
                                } else if (cntEl) {
                                    cntEl.remove();
                                }
                            });

                            dockPicker.hidden = true;
                            dockReactBtn.setAttribute('aria-expanded', 'false');
                        });
                });
            });

            /**
             * Update the trigger FAB face.
             * Priority: active reaction emoji > active like heart > default smiley.
             * Pass reactionCode when a reaction changes, likedState when a like changes.
             * Pass null for whichever didn't change — the function reads current DOM state.
             */
            function updateDockTriggerFace(reactionCode, likedState) {
                // Determine current states (use passed value or read from DOM)
                var hasReaction = reactionCode !== undefined && reactionCode !== null
                    ? true
                    : !!dockPicker.querySelector('.ss-cdock-reaction:not(.ss-cdock-heart).is-active');
                var rxCode = reactionCode !== undefined && reactionCode !== null
                    ? reactionCode
                    : (function() {
                        var active = dockPicker.querySelector('.ss-cdock-reaction:not(.ss-cdock-heart).is-active');
                        return active ? active.dataset.code : null;
                    })();
                var isLiked = likedState !== undefined && likedState !== null
                    ? likedState
                    : (dockHeartBtn && dockHeartBtn.dataset.liked === '1');

                if (rxCode) {
                    // Show reaction emoji
                    var chosenBtn = dockPicker.querySelector('[data-code="' + rxCode + '"]');
                    var emojiEl   = chosenBtn ? chosenBtn.querySelector('.ss-cdock-emoji') : null;
                    var emoji     = emojiEl ? emojiEl.textContent : '';

                    var curEl = dockReactBtn.querySelector('.ss-cdock-current-rx');
                    if (!curEl) {
                        dockReactBtn.innerHTML = '';
                        curEl = document.createElement('span');
                        curEl.className = 'ss-cdock-current-rx';
                        dockReactBtn.appendChild(curEl);
                    }
                    curEl.textContent = emoji;
                    curEl.classList.remove('ss-cdock-heart-active');
                    dockReactBtn.classList.add('has-reaction');
                    dockReactBtn.classList.remove('is-liked');
                    dockReactBtn.title = chosenBtn ? chosenBtn.getAttribute('title') : '';

                } else if (isLiked) {
                    // Show heart
                    var curEl = dockReactBtn.querySelector('.ss-cdock-current-rx');
                    if (!curEl) {
                        dockReactBtn.innerHTML = '';
                        curEl = document.createElement('span');
                        curEl.className = 'ss-cdock-current-rx ss-cdock-heart-active';
                        dockReactBtn.appendChild(curEl);
                    }
                    curEl.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>';
                    curEl.classList.add('ss-cdock-heart-active');
                    dockReactBtn.classList.remove('has-reaction');
                    dockReactBtn.classList.add('is-liked');
                    dockReactBtn.title = 'Liked';

                } else {
                    // Default smiley SVG
                    dockReactBtn.classList.remove('has-reaction', 'is-liked');
                    dockReactBtn.title = 'React';
                    dockReactBtn.innerHTML =
                        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"' +
                        ' stroke-linecap="round" stroke-linejoin="round" width="20" height="20" aria-hidden="true">' +
                        '<circle cx="12" cy="12" r="10"/>' +
                        '<path d="M8 14s1.5 2 4 2 4-2 4-2"/>' +
                        '<line x1="9" y1="9" x2="9.01" y2="9"/>' +
                        '<line x1="15" y1="9" x2="15.01" y2="9"/>' +
                        '</svg>';
                }
            }

        } // end dock picker

    } // end dock

}); // DOMContentLoaded

} // double-load guard
