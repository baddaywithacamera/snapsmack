/**
 * SNAPSMACK - Community Engine
 * Alpha v0.8
 *
 * Handles all client-side behaviour for the community component:
 *   - Like button toggle (AJAX, optimistic UI)
 *   - Reaction picker open/close and reaction toggle (AJAX)
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

    const root = document.querySelector('.ss-community');
    if (!root) return;

    const postId    = root.dataset.postId;
    const authUrl   = root.dataset.authUrl;
    const loggedIn  = root.dataset.loggedIn === '1';

    // =========================================================================
    // UTILITY
    // =========================================================================

    function post(url, data) {
        const fd = new FormData();
        Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        return fetch(url, { method: 'POST', body: fd })
            .then(r => r.json());
    }

    function redirectToAuth() {
        window.location.href = authUrl;
    }

    // =========================================================================
    // LIKE BUTTON
    // =========================================================================

    const likeBtn   = root.querySelector('.ss-like-btn');
    const likeIcon  = root.querySelector('.ss-like-icon');
    const likeCount = root.querySelector('.ss-like-count');

    if (likeBtn) {
        likeBtn.addEventListener('click', () => {
            if (!loggedIn) { redirectToAuth(); return; }

            const wasLiked  = likeBtn.dataset.liked === '1';
            const prevCount = parseInt(likeCount.textContent, 10) || 0;

            // Optimistic update
            const nowLiked = !wasLiked;
            likeBtn.dataset.liked = nowLiked ? '1' : '0';
            likeBtn.classList.toggle('is-liked', nowLiked);
            likeIcon.textContent  = nowLiked ? '♥' : '♡';
            likeCount.textContent = prevCount + (nowLiked ? 1 : -1);
            likeBtn.setAttribute('aria-pressed', String(nowLiked));
            likeBtn.setAttribute('aria-label', (nowLiked ? 'Unlike' : 'Like') + ' this post');

            post('/process-like.php', { post_id: postId })
                .then(data => {
                    if (data.error) {
                        // Roll back optimistic update
                        likeBtn.dataset.liked = wasLiked ? '1' : '0';
                        likeBtn.classList.toggle('is-liked', wasLiked);
                        likeIcon.textContent  = wasLiked ? '♥' : '♡';
                        likeCount.textContent = prevCount;
                    } else {
                        // Confirm with server count
                        likeBtn.dataset.liked = data.liked ? '1' : '0';
                        likeBtn.classList.toggle('is-liked', data.liked);
                        likeIcon.textContent  = data.liked ? '♥' : '♡';
                        likeCount.textContent = data.count;
                    }
                })
                .catch(() => {
                    // Network error — roll back
                    likeBtn.dataset.liked = wasLiked ? '1' : '0';
                    likeBtn.classList.toggle('is-liked', wasLiked);
                    likeIcon.textContent  = wasLiked ? '♥' : '♡';
                    likeCount.textContent = prevCount;
                });
        });
    }

    // =========================================================================
    // REACTION PICKER
    // =========================================================================

    const reactionWrap    = root.querySelector('.ss-reactions-wrap');
    const reactionTrigger = root.querySelector('.ss-reaction-trigger');
    const reactionPicker  = root.querySelector('.ss-reaction-picker');

    if (reactionTrigger && reactionPicker) {

        // Open / close picker
        reactionTrigger.addEventListener('click', () => {
            if (!loggedIn) { redirectToAuth(); return; }
            const isOpen = !reactionPicker.hidden;
            reactionPicker.hidden = isOpen;
            reactionTrigger.setAttribute('aria-expanded', String(!isOpen));
        });

        // Close picker on outside click
        document.addEventListener('click', e => {
            if (reactionWrap && !reactionWrap.contains(e.target)) {
                reactionPicker.hidden = true;
                reactionTrigger.setAttribute('aria-expanded', 'false');
            }
        });

        // Reaction option click
        reactionPicker.querySelectorAll('.ss-reaction-opt').forEach(btn => {
            btn.addEventListener('click', () => {
                const code = btn.dataset.code;

                post('/process-reaction.php', { post_id: postId, reaction_code: code })
                    .then(data => {
                        if (data.error) return;

                        // Update trigger face
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

                        // Update active state on options
                        reactionPicker.querySelectorAll('.ss-reaction-opt').forEach(b => {
                            b.classList.toggle('is-active', b.dataset.code === data.reaction);
                        });

                        // Update summary counts
                        updateReactionSummary(data.counts);

                        reactionPicker.hidden = true;
                        reactionTrigger.setAttribute('aria-expanded', 'false');
                    });
            });
        });
    }

    function updateReactionSummary(counts) {
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

    // =========================================================================
    // COMMENT FORM
    // =========================================================================

    const commentForm    = root.querySelector('.ss-comment-form');
    const commentActions = root.querySelector('.ss-comment-actions');
    const commentArea    = root.querySelector('.ss-comment-textarea');
    const cancelBtn      = root.querySelector('.ss-comment-cancel');
    const statusEl       = root.querySelector('.ss-comment-status');
    const thread         = root.querySelector('.ss-comment-thread');

    if (commentArea && commentActions) {

        // Expand actions row when textarea is focused
        commentArea.addEventListener('focus', () => {
            commentActions.hidden = false;
            commentArea.rows = 3;
        });

        // Auto-grow textarea
        commentArea.addEventListener('input', () => {
            commentArea.style.height = 'auto';
            commentArea.style.height = commentArea.scrollHeight + 'px';
        });

        // Cancel
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
            if (!loggedIn) { redirectToAuth(); return; }

            const text = commentArea ? commentArea.value.trim() : '';
            if (!text) return;

            const submitBtn = commentForm.querySelector('.ss-comment-submit');
            if (submitBtn) submitBtn.disabled = true;
            setStatus('Posting...');

            post('/process-community-comment.php', {
                post_id:      postId,
                comment_text: text,
            })
            .then(data => {
                if (data.error) {
                    const msgs = {
                        rate_limited:       'Too many comments. Slow down.',
                        email_not_verified: 'Verify your email before commenting.',
                        comments_disabled:  'Comments are currently disabled.',
                        empty_comment:      'Comment cannot be empty.',
                        comment_too_long:   'Comment is too long (max 2000 characters).',
                    };
                    setStatus(msgs[data.error] || 'Something went wrong. Try again.');
                } else {
                    // Append new comment to thread
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
        // Create thread container if this is the first comment
        let threadEl = thread;
        if (!threadEl) {
            threadEl = document.createElement('div');
            threadEl.className = 'ss-comment-thread';
            threadEl.setAttribute('aria-label', 'Comments');
            const commentsSection = root.querySelector('.ss-comments');
            if (commentsSection) commentsSection.insertBefore(threadEl, commentsSection.firstChild);
        }

        const initial = (data.username || '?').charAt(0).toUpperCase();
        const display = data.display_name || data.username;

        const node = document.createElement('div');
        node.className = 'ss-comment';
        node.dataset.commentId = data.comment_id;
        node.innerHTML = `
            <div class="ss-comment-meta">
                ${data.avatar_url
                    ? `<img src="${escHtml(data.avatar_url)}" alt="" class="ss-avatar" width="28" height="28">`
                    : `<span class="ss-avatar-placeholder" aria-hidden="true">${escHtml(initial)}</span>`
                }
                <span class="ss-commenter">${escHtml(display)}</span>
                <span class="ss-comment-date">${escHtml(data.date_label)}</span>
                <button class="ss-comment-delete" data-comment-id="${data.comment_id}"
                        aria-label="Delete comment">✕</button>
            </div>
            <div class="ss-comment-body">${escHtml(data.comment_text).replace(/\n/g, '<br>')}</div>
        `;
        threadEl.appendChild(node);

        // Wire delete on new node
        wireDeleteButton(node.querySelector('.ss-comment-delete'));
    }

    // =========================================================================
    // COMMENT DELETE
    // =========================================================================

    root.querySelectorAll('.ss-comment-delete').forEach(wireDeleteButton);

    function wireDeleteButton(btn) {
        if (!btn) return;
        btn.addEventListener('click', () => {
            const commentId = btn.dataset.commentId;
            if (!confirm('Delete this comment?')) return;

            post('/process-community-comment.php', {
                action:     'delete',
                comment_id: commentId,
            })
            .then(data => {
                if (data.deleted) {
                    const node = root.querySelector(`.ss-comment[data-comment-id="${commentId}"]`);
                    if (node) node.remove();
                }
            });
        });
    }

    // =========================================================================
    // UTILITY: HTML escape for dynamic insertion
    // =========================================================================

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

}); // DOMContentLoaded

} // double-load guard
