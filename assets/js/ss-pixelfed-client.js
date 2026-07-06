/*
 * SNAPSMACK — SMACKVERSE Pixelfed Client
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * Client-side layer for the faithful-Pixelfed admin page. Tab switching plus a
 * render layer that talks to the page's own AJAX endpoints:
 *   - Profile / Search are LIVE: they crawl a remote actor's outbox and render
 *     a Pixelfed-style profile + photo grid, with Follow / Unfollow / Like /
 *     Reply (all as the single blog actor).
 *   - Home / Local / Global / Notifications stay quiet until the reader ingest
 *     lands (next phase) — their endpoint returns wired:false.
 * Colours are inherited from the active admin skin via CSS (no theme logic
 * here). Server data arrives via data-* attributes; CSRF token via the admin
 * <meta> tag. No inline script anywhere (skin/admin JS rule).
 */
(function () {
    'use strict';

    var app = document.querySelector('.sspf-app');
    if (!app) return;

    var metaTag = document.querySelector('meta[name="csrf-token"]');
    var CSRF    = metaTag ? metaTag.getAttribute('content') : '';
    var enabled = app.getAttribute('data-enabled') === '1';

    var navLinks = app.querySelectorAll('.sspf-nav a[data-panel]');
    var panels   = app.querySelectorAll('.sspf-panel');
    var WIRED    = { profile: true, search: true };
    var searchQuery = '';   // last handle typed into the search box

    // ── tiny DOM helper ───────────────────────────────────────────────────────
    function el(tag, cls, txt) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (txt != null) e.textContent = txt;
        return e;
    }
    function noteEl(t) { return el('div', 'sspf-note', t); }
    function bodyFor(name) {
        return app.querySelector('.sspf-panel[data-panel="' + name + '"] .sspf-panel-body');
    }
    // Strip a remote HTML bio down to plain text (never inject remote markup).
    function plain(html) {
        var d = document.createElement('div');
        d.innerHTML = html || '';
        return (d.textContent || '').trim();
    }

    // ── navigation ────────────────────────────────────────────────────────────
    function activate(name) {
        navLinks.forEach(function (a) {
            a.classList.toggle('active', a.getAttribute('data-panel') === name);
        });
        panels.forEach(function (p) {
            p.classList.toggle('active', p.getAttribute('data-panel') === name);
        });
        loadPanel(name);
    }
    navLinks.forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            activate(a.getAttribute('data-panel'));
        });
    });

    function loadPanel(name) {
        if (name === 'home' || name === 'local' || name === 'global') { loadFeedPanel(bodyFor(name), name); return; }
        if (name === 'notifications') { loadNotifications(bodyFor('notifications')); return; }
        if (name === 'profile') { loadProfile(bodyFor('profile'), ''); return; }
        if (name === 'search')  { loadProfile(bodyFor('search'), searchQuery); return; }
    }

    // ── Feed panels: Home (ingested), Local, Global (instance timelines) ──────
    var loadingLabel = { home: 'Loading your home feed…', local: 'Loading the local timeline…', global: 'Loading the federated timeline…' };
    function loadFeedPanel(body, panel) {
        if (!body) return;
        body.innerHTML = '';
        body.appendChild(noteEl(loadingLabel[panel] || 'Loading…'));
        fetch('smack-pixelfed.php?ajax=' + panel, { headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (data) {
                if (!data.ok) { body.innerHTML = ''; body.appendChild(noteEl(data.msg || 'Nothing here.')); return; }
                if (!(data.items || []).length && data.msg) { body.innerHTML = ''; body.appendChild(noteEl(data.msg)); return; }
                renderFeed(body, data.items || []);
            })
            .catch(function () {
                body.innerHTML = '';
                body.appendChild(noteEl('Couldn’t reach the fediverse just now — try again.'));
            });
    }

    // ── Notifications (who's engaging with you) ───────────────────────────────
    function loadNotifications(body) {
        if (!body) return;
        body.innerHTML = '';
        body.appendChild(noteEl('Loading notifications…'));
        fetch('smack-pixelfed.php?ajax=notifications', { headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (data) {
                if (!data.ok) { body.innerHTML = ''; body.appendChild(noteEl(data.msg || 'Nothing here.')); return; }
                renderNotifications(body, data.items || []);
                // Opening the panel clears the badge.
                if ((data.unread || 0) > 0) {
                    post({ sspf_action: 'mark_read' }).then(function () { setBadge(0); }).catch(function () {});
                }
            })
            .catch(function () {
                body.innerHTML = '';
                body.appendChild(noteEl('Couldn’t load notifications just now — try again.'));
            });
    }

    var VERB = { follow: 'followed you', like: 'liked your post', reply: 'replied to you', mention: 'mentioned you', boost: 'boosted your post' };
    function renderNotifications(body, items) {
        body.innerHTML = '';
        if (!items.length) {
            body.appendChild(noteEl('No notifications yet. Follow people and engage — when they follow, like, reply or boost you, it shows up here.'));
            return;
        }
        items.forEach(function (n) {
            var row = el('div', 'sspf-notif' + (Number(n.is_read) ? '' : ' sspf-notif-unread'));
            if (n.avatar_url) { var av = el('img', 'sspf-avatar'); av.src = n.avatar_url; av.alt = ''; row.appendChild(av); }
            else { row.appendChild(el('div', 'sspf-avatar')); }
            var body2 = el('div', 'sspf-notif-body');
            var line = el('div', 'sspf-notif-line');
            line.appendChild(el('strong', null, n.actor_name || n.actor_handle || 'Someone'));
            line.appendChild(document.createTextNode(' ' + (VERB[n.ntype] || 'interacted') + (n.actor_handle ? ' · @' + n.actor_handle : '')));
            body2.appendChild(line);
            if (n.content) body2.appendChild(el('div', 'sspf-notif-text', n.content));
            row.appendChild(body2);
            body.appendChild(row);
        });
    }

    function setBadge(n) {
        var link = app.querySelector('.sspf-nav a[data-panel="notifications"]');
        if (!link) return;
        var b = link.querySelector('.sspf-badge');
        if (n > 0) {
            if (!b) { b = el('span', 'sspf-badge'); link.appendChild(document.createTextNode(' ')); link.appendChild(b); }
            b.textContent = String(n);
        } else if (b) { b.remove(); }
    }

    function renderFeed(body, items) {
        body.innerHTML = '';
        if (!items.length) {
            body.appendChild(noteEl('Nothing yet. Search a handle up top and follow some accounts — their latest photos land here.'));
            return;
        }
        items.forEach(function (p) {
            var a = p.author || {};
            var card = el('div', 'sspf-card');

            var head = el('div', 'sspf-card-head');
            if (a.avatar) { var av = el('img', 'sspf-avatar'); av.src = a.avatar; av.alt = ''; head.appendChild(av); }
            else { head.appendChild(el('div', 'sspf-avatar')); }
            var who = el('div', 'sspf-card-who');
            who.appendChild(el('div', 'sspf-card-user', a.name || a.handle || ''));
            who.appendChild(el('div', 'sspf-card-sub', a.handle || ''));
            var profRef = a.handle || a.id || '';
            if (profRef) {
                who.classList.add('sspf-card-userlink');
                who.title = 'View ' + (a.handle || a.name || 'profile');
                who.addEventListener('click', function () { loadProfile(body, profRef); });
            }
            head.appendChild(who);
            card.appendChild(head);

            if (p.images && p.images[0]) {
                var im = el('img', 'sspf-card-media'); im.src = p.images[0];
                im.alt = (p.text || '').slice(0, 120); im.loading = 'lazy';
                im.addEventListener('click', function () { openPost(a, p); });
                card.appendChild(im);
            }
            if (p.count > 1) card.appendChild(el('div', 'sspf-card-sub sspf-card-multi', p.count + ' photos — tap to view all'));
            if (p.text) card.appendChild(el('div', 'sspf-card-caption', p.text));

            if (enabled) {
                var actions = el('div', 'sspf-card-actions');
                actions.appendChild(likeButton(a, p));
                actions.appendChild(boostButton(a, p));
                var reply = el('button', 'sspf-actbtn'); reply.textContent = '💬'; reply.title = 'Reply';
                reply.addEventListener('click', function () { openPost(a, p); });
                actions.appendChild(reply);
                card.appendChild(actions);
            }
            body.appendChild(card);
        });
    }

    // ── authenticated search results: account list + photo feed ───────────────
    function renderResults(body, data) {
        body.innerHTML = '';
        body.appendChild(el('h3', 'sspf-panel-title', 'Results for “' + (data.query || '') + '”'));
        var accts = data.accounts || [];
        if (accts.length) {
            body.appendChild(el('div', 'sspf-results-label', 'Accounts'));
            var list = el('div', 'sspf-acct-list');
            accts.forEach(function (ac) {
                var row = el('div', 'sspf-acct-row');
                if (ac.avatar) { var av = el('img', 'sspf-avatar'); av.src = ac.avatar; av.alt = ''; row.appendChild(av); }
                else { row.appendChild(el('div', 'sspf-avatar')); }
                var who = el('div', 'sspf-acct-who');
                who.appendChild(el('div', 'sspf-card-user', ac.name || ac.handle || ''));
                who.appendChild(el('div', 'sspf-card-sub', ac.handle || ''));
                row.appendChild(who);
                var view = el('button', 'sspf-btn', 'View');
                view.addEventListener('click', function () { loadProfile(body, ac.handle || ac.url); });
                row.appendChild(view);
                list.appendChild(row);
            });
            body.appendChild(list);
        }
        var items = data.items || [];
        if (items.length) {
            body.appendChild(el('div', 'sspf-results-label', 'Photos'));
            var feed = el('div', 'sspf-results-feed');
            body.appendChild(feed);
            renderFeed(feed, items);   // renderFeed clears the div it is given, then fills
        }
        if (!accts.length && !items.length) {
            body.appendChild(noteEl('No accounts or photos matched “' + (data.query || '') + '”.'));
        }
    }

    // ── POST helper (CSRF-signed) ─────────────────────────────────────────────
    function post(params) {
        var fd = new FormData();
        Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
        fd.append('csrf_token', CSRF);
        return fetch('smack-pixelfed.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'fetch' },
            body: fd
        }).then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); });
    }

    // ── profile / search load ─────────────────────────────────────────────────
    function loadProfile(body, handle) {
        if (!body) return;
        if (handle === '' && body === bodyFor('search')) {
            body.innerHTML = '';
            body.appendChild(noteEl('Search the bar above by handle — @user@host — for an account’s profile (follow / like / reply), or a #hashtag (or any word) to pull recent photos tagged that way.'));
            return;
        }
        body.innerHTML = '';
        body.appendChild(noteEl(handle ? 'Looking up ' + handle + '…' : 'Loading your profile…'));
        var url = 'smack-pixelfed.php?ajax=' + (handle ? 'search' : 'profile');
        if (handle) url += '&handle=' + encodeURIComponent(handle);
        fetch(url, { headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (data) {
                if (!data.ok) { body.innerHTML = ''; body.appendChild(noteEl(data.msg || 'Nothing here.')); return; }
                // Hashtag search returns a photo FEED, not a single profile.
                if (data.mode === 'feed') {
                    if (!data.items || !data.items.length) {
                        body.innerHTML = '';
                        body.appendChild(noteEl('No public photos found for ' + (data.title || 'that tag') + ' on your home instance.'));
                        return;
                    }
                    renderFeed(body, data.items);   // clears the body, then fills
                    if (data.title) {
                        body.insertBefore(el('h3', 'sspf-panel-title', 'Photos tagged ' + data.title), body.firstChild);
                    }
                    return;
                }
                // Authenticated (piggyback) search: an account LIST + a photo feed.
                if (data.mode === 'results') { renderResults(body, data); return; }
                renderProfile(body, data);
            })
            .catch(function () {
                body.innerHTML = '';
                body.appendChild(noteEl('Couldn’t reach the fediverse just now — try again.'));
            });
    }

    function statEl(n, label) {
        var s = el('div');
        s.appendChild(el('b', null, (n == null ? '—' : String(n))));
        s.appendChild(document.createTextNode(label));
        return s;
    }

    function renderProfile(body, data) {
        body.innerHTML = '';
        var a = data.actor;

        var head = el('div', 'sspf-profile-head');
        if (a.avatar) { var av = el('img', 'sspf-profile-avatar'); av.src = a.avatar; av.alt = ''; head.appendChild(av); }
        else { head.appendChild(el('div', 'sspf-profile-avatar')); }

        var info = el('div', 'sspf-profile-info');
        info.appendChild(el('div', 'sspf-profile-name', a.name || a.username || 'Unknown'));
        info.appendChild(el('div', 'sspf-profile-handle', a.handle || a.url));

        var stats = el('div', 'sspf-profile-stats');
        stats.appendChild(statEl(a.posts, 'Posts'));
        stats.appendChild(statEl(a.followers, 'Followers'));
        stats.appendChild(statEl(a.following, 'Following'));
        info.appendChild(stats);

        var bioText = plain(a.summary);
        if (bioText) info.appendChild(el('div', 'sspf-profile-bio', bioText));

        if (data.is_self) {
            info.appendChild(el('div', 'sspf-profile-self', 'This is your blog — as the fediverse sees it.'));
        } else if (enabled) {
            info.appendChild(followControl(a, data));
        }
        head.appendChild(info);
        body.appendChild(head);

        if (!data.posts || !data.posts.length) {
            body.appendChild(noteEl('No photo posts found in this outbox.'));
            return;
        }
        var grid = el('div', 'sspf-grid');
        data.posts.forEach(function (p) {
            if (!p.images || !p.images.length) return;
            var cell = el('a');
            cell.href = p.url || '#';
            var img = el('img'); img.src = p.images[0]; img.alt = (p.text || '').slice(0, 120); img.loading = 'lazy';
            cell.appendChild(img);
            if (p.count > 1) cell.appendChild(el('span', 'sspf-grid-multi', '▤'));
            cell.addEventListener('click', function (e) { e.preventDefault(); openPost(a, p); });
            grid.appendChild(cell);
        });
        body.appendChild(grid);
    }

    // ── follow / unfollow control ─────────────────────────────────────────────
    function followControl(a, data) {
        var wrap  = el('div', 'sspf-follow-wrap');
        var btn   = el('button', 'sspf-btn');
        var flash = el('span', 'sspf-flash');
        var state = data.state;
        var rowId = data.row_id;

        function paint() {
            btn.classList.toggle('sspf-btn-ghost', state === 'accepted' || state === 'pending');
            btn.textContent = state === 'accepted' ? 'Following ✓'
                            : state === 'pending'  ? 'Pending…'
                            : 'Follow';
        }
        paint();

        btn.addEventListener('click', function () {
            btn.disabled = true;
            var action = (state === 'accepted' || state === 'pending')
                ? post({ sspf_action: 'unfollow', row_id: rowId })
                : post({ sspf_action: 'follow', target: a.id });
            action.then(function (r) {
                btn.disabled = false;
                if (r.ok) { state = r.state || ''; rowId = r.row_id || 0; }
                paint();
                flash.textContent = r.msg || '';
            }).catch(function () { btn.disabled = false; flash.textContent = 'Request failed — try again.'; });
        });

        wrap.appendChild(btn);
        wrap.appendChild(flash);
        return wrap;
    }

    // ── shared interaction buttons (like toggle, boost) ───────────────────────
    // Like = Pixelfed's heart: outline when not liked, solid red (#ed4956) when
    // liked. The action is a real ActivityPub Like/Undo (sspf_action like/unlike).
    function likeButton(a, p) {
        var btn = el('button', 'sspf-actbtn');
        var liked = false;
        btn.textContent = '♡'; btn.title = 'Like';
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            btn.disabled = true;
            post({ sspf_action: liked ? 'unlike' : 'like', object: p.id, actor: a.id }).then(function (r) {
                btn.disabled = false;
                if (r.ok) { liked = !liked; btn.textContent = liked ? '♥' : '♡'; btn.style.color = liked ? '#ed4956' : ''; btn.classList.toggle('sspf-on', liked); }
            }).catch(function () { btn.disabled = false; });
        });
        return btn;
    }
    function boostButton(a, p) {
        var btn = el('button', 'sspf-actbtn');
        btn.textContent = '🔁'; btn.title = 'Boost to your followers';
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            btn.disabled = true;
            post({ sspf_action: 'boost', object: p.id }).then(function (r) {
                btn.disabled = false;
                if (r.ok) { btn.textContent = '🔁 ✓'; btn.classList.add('sspf-on'); }
            }).catch(function () { btn.disabled = false; });
        });
        return btn;
    }

    // ── carousel (multi-image post): one frame at a time, arrows + dots ───────
    function buildCarousel(images) {
        var wrap  = el('div', 'sspf-carousel');
        var track = el('div', 'sspf-carousel-track');
        images.forEach(function (src) {
            var slide = el('div', 'sspf-carousel-slide');
            var im = el('img'); im.src = src; im.alt = '';
            slide.appendChild(im);
            track.appendChild(slide);
        });
        wrap.appendChild(track);

        if (images.length > 1) {
            var idx  = 0;
            var dots = [];
            function go(n) {
                idx = (n + images.length) % images.length;
                track.style.transform = 'translateX(-' + (idx * 100) + '%)';
                dots.forEach(function (d, i) { d.classList.toggle('active', i === idx); });
            }
            var prev = el('button', 'sspf-carousel-nav sspf-carousel-prev', '‹');
            prev.addEventListener('click', function (e) { e.stopPropagation(); go(idx - 1); });
            var next = el('button', 'sspf-carousel-nav sspf-carousel-next', '›');
            next.addEventListener('click', function (e) { e.stopPropagation(); go(idx + 1); });
            wrap.appendChild(prev);
            wrap.appendChild(next);

            var dotwrap = el('div', 'sspf-carousel-dots');
            images.forEach(function (_, i) {
                var d = el('span', 'sspf-carousel-dot' + (i === 0 ? ' active' : ''));
                d.addEventListener('click', function (e) { e.stopPropagation(); go(i); });
                dotwrap.appendChild(d);
                dots.push(d);
            });
            wrap.appendChild(dotwrap);
            var counter = el('div', 'sspf-carousel-count', '1 / ' + images.length);
            wrap.appendChild(counter);
            var origGo = go;
            go = function (n) { origGo(n); counter.textContent = (idx + 1) + ' / ' + images.length; };
        }
        return wrap;
    }

    // ── reply thread bubbles (existing replies + ones you send) ───────────────
    function replyBubble(item, mine) {
        var b = el('div', 'sspf-reply' + (mine ? ' sspf-reply-mine' : ''));
        var head = el('div', 'sspf-reply-head');
        if (item.avatar) { var av = el('img', 'sspf-reply-av'); av.src = item.avatar; av.alt = ''; head.appendChild(av); }
        head.appendChild(el('span', 'sspf-reply-who', item.name || item.handle || (mine ? 'You' : 'Someone')));
        if (item.handle) head.appendChild(el('span', 'sspf-reply-handle', item.handle));
        b.appendChild(head);
        b.appendChild(el('div', 'sspf-reply-text', item.text || ''));
        return b;
    }
    function loadThread(container, p) {
        container.innerHTML = '';
        container.appendChild(el('div', 'sspf-thread-head', 'Replies'));
        var list = el('div', 'sspf-thread-list');
        container.appendChild(list);
        container._list = list;              // stashed so the composer can append
        list.appendChild(noteEl('Loading replies…'));
        fetch('smack-pixelfed.php?ajax=thread&object=' + encodeURIComponent(p.id || ''),
              { headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (data) {
                list.innerHTML = '';
                var items = (data && data.items) || [];
                if (!items.length) { list.appendChild(noteEl('No replies yet.')); return; }
                items.forEach(function (it) { list.appendChild(replyBubble(it, false)); });
            })
            .catch(function () { list.innerHTML = ''; list.appendChild(noteEl('Couldn’t load replies.')); });
    }
    function threadAppendMine(container, text) {
        var list = container && container._list;
        if (!list) return;
        var ph = list.querySelector('.sspf-note');   // drop the "No replies yet." placeholder
        if (ph) ph.remove();
        list.appendChild(replyBubble({ name: 'You', text: text }, true));
    }

    // ── post lightbox: image(s) + caption + like / reply ──────────────────────
    function openPost(a, p) {
        var ov   = el('div', 'sspf-lightbox');
        var card = el('div', 'sspf-lightbox-card');

        var close = el('button', 'sspf-lightbox-close', '✕');
        close.addEventListener('click', function () { ov.remove(); });
        card.appendChild(close);

        card.appendChild(buildCarousel(p.images || []));
        if (p.text) card.appendChild(el('div', 'sspf-lightbox-caption', p.text));

        var msgline = el('div', 'sspf-reply-msg');
        var actions = el('div', 'sspf-lightbox-actions');

        if (enabled) {
            var likeBtn = el('button', 'sspf-btn'); likeBtn.textContent = '♡ Like';
            var liked = false;
            likeBtn.addEventListener('click', function () {
                likeBtn.disabled = true;
                post({ sspf_action: liked ? 'unlike' : 'like', object: p.id, actor: a.id }).then(function (r) {
                    likeBtn.disabled = false;
                    if (r.ok) { liked = !liked; likeBtn.textContent = liked ? '♥ Liked' : '♡ Like'; likeBtn.style.color = liked ? '#ed4956' : ''; }
                    msgline.textContent = r.msg || '';
                }).catch(function () { likeBtn.disabled = false; });
            });
            actions.appendChild(likeBtn);

            var boost = el('button', 'sspf-btn sspf-btn-ghost'); boost.textContent = '🔁 Boost';
            boost.addEventListener('click', function () {
                boost.disabled = true;
                post({ sspf_action: 'boost', object: p.id }).then(function (r) {
                    boost.disabled = false;
                    if (r.ok) boost.textContent = '🔁 Boosted';
                    msgline.textContent = r.msg || '';
                }).catch(function () { boost.disabled = false; });
            });
            actions.appendChild(boost);

            var replyBtn = el('button', 'sspf-btn sspf-btn-ghost', '💬 Reply');
            actions.appendChild(replyBtn);
        }

        var view = el('a', 'sspf-btn sspf-btn-ghost', 'View on ' + (a.host || 'origin'));
        view.href = p.url || a.url; view.target = '_blank'; view.rel = 'noopener';
        actions.appendChild(view);
        card.appendChild(actions);

        // Reply thread — existing replies (fetched) + ones you send this session.
        var thread = el('div', 'sspf-thread');
        card.appendChild(thread);
        loadThread(thread, p);

        if (enabled) {
            var replyBox = el('div', 'sspf-reply-box');
            replyBox.style.display = 'none';
            var ta = el('textarea', 'sspf-reply-input');
            ta.placeholder = 'Reply as the blog…';
            replyBox.appendChild(ta);
            var send = el('button', 'sspf-btn', 'Send reply');
            replyBox.appendChild(send);
            replyBox.appendChild(msgline);
            card.appendChild(replyBox);

            replyBtn.addEventListener('click', function () {
                replyBox.style.display = (replyBox.style.display === 'none') ? 'block' : 'none';
                if (replyBox.style.display === 'block') ta.focus();
            });
            send.addEventListener('click', function () {
                var t = ta.value.trim();
                if (!t) return;
                send.disabled = true;
                post({ sspf_action: 'reply', object: p.id, actor: a.id, content: t }).then(function (r) {
                    send.disabled = false;
                    msgline.textContent = r.msg || '';
                    if (r.ok) { ta.value = ''; threadAppendMine(thread, t); }
                }).catch(function () { send.disabled = false; msgline.textContent = 'Reply failed — try again.'; });
            });
        } else {
            card.appendChild(msgline);
        }

        ov.appendChild(card);
        ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
        document.addEventListener('keydown', function esc(e) {
            if (e.key === 'Escape') { ov.remove(); document.removeEventListener('keydown', esc); }
        });
        document.body.appendChild(ov);
    }

    // ── search box (top bar) ──────────────────────────────────────────────────
    var search = app.querySelector('.sspf-search input');
    if (search) {
        search.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            var q = search.value.trim();
            if (!q) return;
            searchQuery = q;
            activate('search');
        });
    }

    // Open the default panel.
    activate(app.getAttribute('data-default-panel') || 'home');
})();
// ===== SNAPSMACK EOF =====
