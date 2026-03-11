/**
 * SNAPSMACK - Community Engine
 * Alpha v0.7.1
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
                if (!loggedIn) { window.location.href = authUrl; return; }

                const wasLiked  = likeBtn.dataset.liked === '1';
                const prevCount = parseInt(likeCount ? likeCount.textContent : '0', 10) || 0;
                const nowLiked  = !wasLiked;

                // Optimistic update
                likeBtn.dataset.liked = nowLiked ? '1' : '0';
                likeBtn.classList.toggle('is-liked', nowLiked);
                if (likeIcon)  likeIcon.textContent  = nowLiked ? '♥' : '♡';
                if (likeCount) likeCount.textContent = prevCount + (nowLiked ? 1 : -1);
                likeBtn.setAttribute('aria-pressed', String(nowLiked));
                likeBtn.setAttribute('aria-label', (nowLiked ? 'Unlike' : 'Like') + ' this post');

                post('/process-like.php', { post_id: postId })
                    .then(data => {
                        if (data.error) {
                            likeBtn.dataset.liked = wasLiked ? '1' : '0';
                            likeBtn.classList.toggle('is-liked', wasLiked);
                            if (likeIcon)  likeIcon.textContent  = wasLiked ? '♥' : '♡';
                            if (likeCount) likeCount.textContent = prevCount;
                        } else {
                            likeBtn.dataset.liked = data.liked ? '1' : '0';
                            likeBtn.classList.toggle('is-liked', data.liked);
                            if (likeIcon)  likeIcon.textContent  = data.liked ? '♥' : '♡';
                            if (likeCount) likeCount.textContent = data.count;
                        }
                    })
                    .catch(() => {
                        likeBtn.dataset.liked = wasLiked ? '1' : '0';
                        likeBtn.classList.toggle('is-liked', wasLiked);
                        if (likeIcon)  likeIcon.textContent  = wasLiked ? '♥' : '♡';
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
                if (!loggedIn) { window.location.href = authUrl; return; }
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
    // COMMUNITY DOCK (floating FAB: likes + reactions)
    // =========================================================================

    const dock = document.getElementById('ss-community-dock');
    if (dock) {

        const dockPostId   = dock.dataset.postId;
        const dockAuthUrl  = dock.dataset.authUrl;
        const dockLoggedIn = dock.dataset.loggedIn === '1';

        const dockLikeBtn   = dock.querySelector('.ss-cdock-like-btn');
        const dockLikeIcon  = dock.querySelector('.ss-cdock-like-icon');
        const dockLikeCount = dock.querySelector('.ss-cdock-like-count');
        const dockReactBtn  = dock.querySelector('.ss-cdock-react-btn');
        const dockPicker    = dock.querySelector('.ss-cdock-picker');

        // --- Dock like toggle ---
        if (dockLikeBtn) {
            dockLikeBtn.addEventListener('click', () => {
                if (!dockLoggedIn) { window.location.href = dockAuthUrl; return; }

                const wasLiked  = dockLikeBtn.dataset.liked === '1';
                const prevCount = dockLikeCount ? (parseInt(dockLikeCount.textContent, 10) || 0) : 0;
                const nowLiked  = !wasLiked;

                // Optimistic update
                dockLikeBtn.dataset.liked = nowLiked ? '1' : '0';
                dockLikeBtn.classList.toggle('is-liked', nowLiked);
                if (dockLikeIcon)  dockLikeIcon.textContent  = nowLiked ? '♥' : '♡';
                if (dockLikeCount) dockLikeCount.textContent = prevCount + (nowLiked ? 1 : -1);
                dockLikeBtn.setAttribute('aria-pressed', String(nowLiked));
                dockLikeBtn.setAttribute('aria-label', (nowLiked ? 'Unlike' : 'Like') + ' this photo');

                post('/process-like.php', { post_id: dockPostId })
                    .then(data => {
                        if (data.error) {
                            dockLikeBtn.dataset.liked = wasLiked ? '1' : '0';
                            dockLikeBtn.classList.toggle('is-liked', wasLiked);
                            if (dockLikeIcon)  dockLikeIcon.textContent  = wasLiked ? '♥' : '♡';
                            if (dockLikeCount) dockLikeCount.textContent = prevCount;
                        } else {
                            dockLikeBtn.dataset.liked = data.liked ? '1' : '0';
                            dockLikeBtn.classList.toggle('is-liked', data.liked);
                            if (dockLikeIcon)  dockLikeIcon.textContent  = data.liked ? '♥' : '♡';
                            if (dockLikeCount) dockLikeCount.textContent = data.count;
                        }
                    })
                    .catch(() => {
                        dockLikeBtn.dataset.liked = wasLiked ? '1' : '0';
                        dockLikeBtn.classList.toggle('is-liked', wasLiked);
                        if (dockLikeIcon)  dockLikeIcon.textContent  = wasLiked ? '♥' : '♡';
                        if (dockLikeCount) dockLikeCount.textContent = prevCount;
                    });
            });
        }

        // --- Dock reaction picker ---
        if (dockReactBtn && dockPicker) {

            // Open / close picker on trigger click
            dockReactBtn.addEventListener('click', e => {
                e.stopPropagation();
                if (!dockLoggedIn) { window.location.href = dockAuthUrl; return; }
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

            // Reaction selection
            dockPicker.querySelectorAll('.ss-cdock-reaction').forEach(btn => {
                btn.addEventListener('click', () => {
                    const code = btn.dataset.code;

                    post('/process-reaction.php', { post_id: dockPostId, reaction_code: code })
                        .then(data => {
                            if (data.error) return;

                            // Update trigger face
                            updateDockTriggerFace(data.reaction);

                            // Update active states on picker buttons
                            dockPicker.querySelectorAll('.ss-cdock-reaction').forEach(b => {
                                const isActive = b.dataset.code === data.reaction;
                                b.classList.toggle('is-active', isActive);
                                b.setAttribute('aria-pressed', String(isActive));
                            });

                            // Update count badges on picker buttons
                            dockPicker.querySelectorAll('.ss-cdock-reaction').forEach(b => {
                                const rxCode = b.dataset.code;
                                let countEl  = b.querySelector('.ss-cdock-rx-count');
                                const cnt    = data.counts[rxCode] || 0;
                                if (cnt > 0) {
                                    if (!countEl) {
                                        countEl = document.createElement('span');
                                        countEl.className = 'ss-cdock-rx-count';
                                        b.appendChild(countEl);
                                    }
                                    countEl.textContent = cnt;
                                } else if (countEl) {
                                    countEl.remove();
                                }
                            });

                            dockPicker.hidden = true;
                            dockReactBtn.setAttribute('aria-expanded', 'false');
                        });
                });
            });

            /**
             * Update the reaction trigger button face after a reaction is set or cleared.
             * When a reaction is active: shows the emoji in a .ss-cdock-current-rx span.
             * When cleared: restores the default smiley SVG.
             */
            function updateDockTriggerFace(reactionCode) {
                if (reactionCode) {
                    const chosenBtn = dockPicker.querySelector(`[data-code="${reactionCode}"]`);
                    const emojiEl   = chosenBtn ? chosenBtn.querySelector('.ss-cdock-emoji') : null;
                    const emoji     = emojiEl ? emojiEl.textContent : '';

                    let curEl = dockReactBtn.querySelector('.ss-cdock-current-rx');
                    if (!curEl) {
                        // First time: replace SVG icon with emoji span
                        dockReactBtn.innerHTML = '';
                        curEl = document.createElement('span');
                        curEl.className = 'ss-cdock-current-rx';
                        dockReactBtn.appendChild(curEl);
                    }
                    curEl.textContent = emoji;
                    dockReactBtn.classList.add('has-reaction');
                    dockReactBtn.title = chosenBtn ? chosenBtn.getAttribute('title') : '';

                } else {
                    // No reaction — restore default smiley SVG
                    dockReactBtn.classList.remove('has-reaction');
                    dockReactBtn.title = 'Add reaction';
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

        } // end dock reaction picker

    } // end dock

}); // DOMContentLoaded

} // double-load guard
