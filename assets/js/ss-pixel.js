/* ============================================================================
 * SNAPSMACK — SMACKVERSE : Standalone Pixelfed-compatible client  (ss-pixel.js)
 *
 * ORIGINAL code. Drives a from-scratch UI that reproduces the pixelfed.ca web
 * experience (matched by observation, not by copying their GPL source). All
 * data + interactions go through SnapSmack's OWN engine: the ?ajax=<panel> read
 * endpoints and the sspf_action POST handlers (follow/like/reply/boost/dm_send/
 * mark_read) served by pixel.php. No Pixelfed JS is used.
 *
 * PREVIEW MODE: if window.SX_FIXTURE is defined, every network call resolves
 * from that fixture instead of the server, so the exact same renderers can be
 * shown for a fidelity check before deploy. Real deploy = fixture absent.
 * ==========================================================================*/
(function () {
  "use strict";

  var app = document.querySelector(".sx-app");
  if (!app) return;

  var CFG = {
    api:     app.getAttribute("data-api") || "pixel.php",
    handle:  app.getAttribute("data-actor") || "",
    avatar:  app.getAttribute("data-avatar") || "",
    enabled: app.getAttribute("data-enabled") === "1",
    csrf:    (document.querySelector('meta[name="csrf-token"]') || {}).content || ""
  };
  var FIXTURE = window.SX_FIXTURE || null;

  /* ---- tiny helpers ------------------------------------------------------ */
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function node(html) { var t = document.createElement("template"); t.innerHTML = html.trim(); return t.content.firstChild; }
  function avatar(url) { return esc(url || "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg'/%3E"); }

  /* Scheme allowlist for any href/src built from REMOTE data: only http(s)
     survives; javascript:, data:, vbscript:, relative junk collapse to "#".
     Reused by bioHTML and every remote-URL anchor. (secaudit 033 §3.1/§3.2) */
  function safeUrl(u) {
    u = String(u == null ? "" : u).trim();
    return /^https?:\/\//i.test(u) ? u : "#";
  }

  function timeago(iso) {
    if (!iso) return "";
    var t = Date.parse(iso.replace(" ", "T"));
    if (isNaN(t)) return "";
    var s = Math.max(1, (Date.now() - t) / 1000);
    if (s < 60) return Math.floor(s) + "s";
    if (s < 3600) return Math.floor(s / 60) + "m";
    if (s < 86400) return Math.floor(s / 3600) + "h";
    if (s < 604800) return Math.floor(s / 86400) + "d";
    if (s < 2629800) return Math.floor(s / 604800) + "w";
    return Math.floor(s / 2629800) + "mo";
  }
  function fullDate(iso) {
    if (!iso) return "";
    var d = new Date(Date.parse(iso.replace(" ", "T")));
    if (isNaN(d)) return "";
    return d.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" }) +
           ", " + d.toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" });
  }

  /* Linkify caption: #hashtags -> search, @user@host -> search, urls -> anchors. */
  function linkifyCaption(text) {
    var out = esc(text);
    out = out.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
    out = out.replace(/(^|[\s(])#([A-Za-z0-9_]{1,60})/g,
      function (m, pre, tag) { return pre + '<a href="#" data-search="%23' + tag + '">#' + tag + "</a>"; });
    out = out.replace(/(^|[\s(])@([A-Za-z0-9_.-]+@[A-Za-z0-9_.-]+)/g,
      function (m, pre, h) { return pre + '<a href="#" data-search="@' + h + '">@' + h + "</a>"; });
    return out.replace(/\n/g, "<br>");
  }

  /* Render a fediverse bio (remote HTML: <br>, <a>, <p>, light inline tags).
     Allowlist rebuild via an INERT DOMParser document — untrusted markup never
     touches a live node, so no resource load or event handler can fire — keeping
     only safe formatting tags and http(s) hrefs. (secaudit 033 §3.2) */
  function bioHTML(str) {
    if (!str) return "";
    var ALLOW = { A: 1, P: 1, BR: 1, SPAN: 1, STRONG: 1, EM: 1, B: 1, I: 1 };
    var doc = new DOMParser().parseFromString(String(str), "text/html");
    var out = document.createElement("div");
    (function walk(src, dst) {
      Array.prototype.slice.call(src.childNodes).forEach(function (n) {
        if (n.nodeType === 3) { dst.appendChild(document.createTextNode(n.nodeValue)); return; }
        if (n.nodeType !== 1) return;
        var tag = n.tagName;
        if (!ALLOW[tag]) { walk(n, dst); return; }   // drop the tag, keep its text
        var el = document.createElement(tag.toLowerCase());
        if (tag === "A") {
          el.setAttribute("href", safeUrl(n.getAttribute("href")));
          el.setAttribute("target", "_blank");
          el.setAttribute("rel", "noopener nofollow");
        }
        walk(n, el);
        dst.appendChild(el);
      });
    })(doc.body, out);
    return out.innerHTML;
  }

  // Inline line-icons (generic UI glyphs, stroke = currentColor) for the clean
  // Pixelfed look — no color emoji. Original SVG, not lifted from Pixelfed.
  function svg(w, body, fill) {
    return '<svg viewBox="0 0 24 24" width="' + w + '" height="' + w + '" fill="' + (fill || "none") +
      '" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + body + "</svg>";
  }
  var HEART   = svg(21, '<path d="M20.8 5.6a5 5 0 0 0-7.1 0L12 7.3l-1.7-1.7a5 5 0 1 0-7.1 7.1L12 21l8.8-8.8a5 5 0 0 0 0-6.6z"/>');
  var HEART_F = svg(21, '<path d="M20.8 5.6a5 5 0 0 0-7.1 0L12 7.3l-1.7-1.7a5 5 0 1 0-7.1 7.1L12 21l8.8-8.8a5 5 0 0 0 0-6.6z"/>', "currentColor");
  var CHAT    = svg(21, '<path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5 8.4 8.4 0 0 1-3.9-.9L3 21l1.9-5.6A8.5 8.5 0 1 1 21 11.5z"/>');
  var BOOST   = svg(21, '<path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>');
  var MARK    = svg(20, '<path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>');
  var DOTS    = svg(20, '<circle cx="5" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="19" cy="12" r="1.4"/>', "currentColor");
  var GLOBE   = svg(14, '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18z"/>');
  var SEND    = svg(18, '<path d="M22 2 11 13"/><path d="M22 2l-7 20-4-9-9-4z"/>');

  /* ---- network layer (fixture-aware) ------------------------------------- */
  function api(panel, params) {
    if (FIXTURE) {
      var key = panel + (params && params.handle ? ":" + params.handle : "") + (params && params.actor ? ":" + params.actor : "");
      return Promise.resolve(FIXTURE[key] || FIXTURE[panel] || { ok: true, items: [] });
    }
    var qs = new URLSearchParams(Object.assign({ ajax: panel }, params || {}));
    return fetch(CFG.api + "?" + qs.toString(), { credentials: "same-origin" })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, msg: "Network error." }; });
  }
  function act(action, data) {
    if (FIXTURE) { toast("Preview mode — “" + action + "” not sent."); return Promise.resolve({ ok: true }); }
    var body = new URLSearchParams(Object.assign({ sspf_action: action }, data || {}));
    return fetch(CFG.api, {
      method: "POST", credentials: "same-origin",
      headers: { "X-CSRF-Token": CFG.csrf, "Content-Type": "application/x-www-form-urlencoded" },
      body: body.toString()
    }).then(function (r) { return r.json(); }).catch(function () { return { ok: false, msg: "Network error." }; });
  }

  /* ---- toast ------------------------------------------------------------- */
  var toastWrap = document.body.appendChild(node('<div class="sx-toast-wrap"></div>'));
  function toast(msg) {
    var t = toastWrap.appendChild(node('<div class="sx-toast">' + esc(msg) + "</div>"));
    setTimeout(function () { t.style.opacity = "0"; setTimeout(function () { t.remove(); }, 300); }, 2600);
  }

  /* ---- lightbox ---------------------------------------------------------- */
  var lb = document.body.appendChild(node('<div class="sx-lb"><button class="sx-lb-x" aria-label="Close">&times;</button><img alt=""></div>'));
  function openLightbox(src) { $("img", lb).src = src; lb.classList.add("open"); }
  lb.addEventListener("click", function (e) { if (e.target === lb || e.target.classList.contains("sx-lb-x")) lb.classList.remove("open"); });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape") lb.classList.remove("open"); });

  /* ---- post detail overlay ------------------------------------------------ *
   * A grid/search tile opens the full POST (not straight to the image), like
   * Pixelfed: result → post (Like/Comment/Boost + author @handle → profile →
   * Follow) → and only the image when you click the photo inside. Reuses the
   * exact feedCard renderer, so nothing about interactions is duplicated. */
  var postLb = document.body.appendChild(node('<div class="sx-postlb"><div class="sx-postlb-inner"><button class="sx-postlb-x" aria-label="Close">&times;</button><div class="sx-postlb-body"></div></div></div>'));
  function openPost(p) {
    var body = $(".sx-postlb-body", postLb);
    body.innerHTML = ""; body.appendChild(feedCard(p));
    postLb.classList.add("open");
  }
  function closePost() { postLb.classList.remove("open"); }
  postLb.addEventListener("click", function (e) {
    if (e.target === postLb || e.target.classList.contains("sx-postlb-inner") || e.target.classList.contains("sx-postlb-x")) closePost();
    // tapping the author @handle inside the post navigates to their profile — close the post so it shows
    else if (e.target.closest("[data-search]")) closePost();
  });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape" && !lb.classList.contains("open")) closePost(); });

  /* ---- feed card --------------------------------------------------------- */
  function feedCard(p) {
    var a = p.author || {};
    var img0 = (p.images && p.images[0]) || "";
    var multi = (p.count || (p.images ? p.images.length : 0)) > 1;
    var card = node('<article class="sx-card"></article>');
    card.innerHTML =
      '<div class="sx-card-head">' +
        '<img class="sx-av" src="' + avatar(a.avatar) + '" alt="">' +
        '<div class="sx-ch-main">' +
          '<div class="sx-ch-h"><a href="#" data-search="' + esc(a.handle || a.id) + '">' + esc(a.handle || a.name) + "</a></div>" +
          '<div class="sx-ch-sub">' + esc(fullDate(p.published)) + " &middot; <span>" + GLOBE + "</span></div>" +
        "</div>" +
        '<a class="sx-ch-menu" href="' + esc(safeUrl(p.url)) + '" target="_blank" rel="noopener">' + DOTS + "</a>" +
      "</div>" +
      (p.is_boost ? '<div class="sx-boostline">' + BOOST + " Boosted</div>" : "") +
      '<div class="sx-media' + (multi ? " sx-multi" : "") + '"><img src="' + esc(img0) + '" alt="" loading="lazy"></div>' +
      '<div class="sx-actions">' +
        '<button class="sx-act sx-like"><span class="sx-ic">' + HEART + "</span> Like</button>" +
        '<button class="sx-act sx-comment"><span class="sx-ic">' + CHAT + "</span> Comment</button>" +
        '<span class="sx-act-sp"></span>' +
        '<button class="sx-act sx-boost" title="Boost"><span class="sx-ic">' + BOOST + "</span></button>" +
        '<button class="sx-act sx-book" title="Bookmark"><span class="sx-ic">' + MARK + "</span></button>" +
      "</div>" +
      (p.text ? '<div class="sx-caption"><span class="sx-ch-h">' + esc(a.handle ? a.handle.split("@")[0] : a.name) + "</span> " + linkifyCaption(p.text) + "</div>" : "") +
      '<div class="sx-comments sx-hide"></div>';

    $(".sx-media img", card).addEventListener("click", function () { openLightbox(img0); });

    var likeBtn = $(".sx-like", card);
    likeBtn.addEventListener("click", function () {
      var liked = likeBtn.classList.toggle("liked");
      $(".sx-ic", likeBtn).innerHTML = liked ? HEART_F : HEART;
      act(liked ? "like" : "unlike", { object: p.id, actor: a.id }).then(function (r) {
        if (!r.ok) { likeBtn.classList.toggle("liked"); $(".sx-ic", likeBtn).innerHTML = HEART; toast(r.msg || "Couldn’t like."); }
      });
    });
    $(".sx-boost", card).addEventListener("click", function (e) {
      var b = e.currentTarget; b.classList.toggle("on");
      act("boost", { object: p.id }).then(function (r) { if (!r.ok) { b.classList.remove("on"); toast(r.msg || "Couldn’t boost."); } else toast("Boosted."); });
    });
    $(".sx-comment", card).addEventListener("click", function () { toggleComments(card, p); });
    return card;
  }

  function toggleComments(card, p) {
    var box = $(".sx-comments", card);
    if (!box.classList.contains("sx-hide")) { box.classList.add("sx-hide"); return; }
    box.classList.remove("sx-hide");
    if (box.getAttribute("data-loaded")) return;
    box.setAttribute("data-loaded", "1");
    box.innerHTML = '<div class="sx-spin"></div>';
    api("thread", { object: p.id }).then(function (r) {
      box.innerHTML = "";
      (r.items || []).forEach(function (c) {
        box.appendChild(node(
          '<div class="sx-cmt"><img class="sx-av" src="' + avatar(c.avatar) + '" alt="">' +
          '<div class="sx-cmt-b"><div class="sx-cmt-h">' + esc(c.name || c.handle) +
          "<span>" + esc(timeago(c.created)) + "</span></div>" +
          '<div class="sx-cmt-t">' + linkifyCaption(c.text) + "</div></div></div>"));
      });
      var a = p.author || {};
      var cbox = node(
        '<div class="sx-cmt-box"><img class="sx-av" src="' + avatar(CFG.avatar) + '" alt="">' +
        '<input type="text" placeholder="Write a comment…" aria-label="Write a comment"><button>Post</button></div>');
      var input = $("input", cbox), btn = $("button", cbox);
      function submit() {
        var v = input.value.trim(); if (!v) return;
        input.value = ""; btn.disabled = true;
        act("reply", { object: p.id, actor: a.id, content: v }).then(function (r) {
          btn.disabled = false;
          if (r.ok) { cbox.parentNode.insertBefore(node(
            '<div class="sx-cmt"><img class="sx-av" src="' + avatar(CFG.avatar) + '" alt="">' +
            '<div class="sx-cmt-b"><div class="sx-cmt-h">' + esc(CFG.handle) + "<span>now</span></div>" +
            '<div class="sx-cmt-t">' + esc(v) + "</div></div></div>"), cbox); toast("Reply sent."); }
          else toast(r.msg || "Couldn’t reply.");
        });
      }
      btn.addEventListener("click", submit);
      input.addEventListener("keydown", function (e) { if (e.key === "Enter") submit(); });
      box.appendChild(cbox);
    });
  }

  /* ---- feed panels (home/local/global/hashtag) --------------------------- */
  function renderFeed(container, res) {
    container.innerHTML = "";
    var items = res.items || [];
    if (!items.length) { container.appendChild(node('<div class="sx-note">' + esc(res.msg || "Nothing here yet.") + "</div>")); return; }
    items.forEach(function (p) { container.appendChild(feedCard(p)); });
  }

  /* ---- notifications ----------------------------------------------------- */
  function notiText(n) {
    switch (n.ntype) {
      case "follow": return "followed you.";
      case "like":   return "liked your post.";
      case "reply":  return "commented" + (n.content ? ": " + n.content : ".");
      case "boost":  return "boosted your post.";
      case "mention":return "mentioned you.";
      default:       return n.ntype;
    }
  }
  function notiRow(n, big) {
    var who = n.actor_handle || n.actor_name || "Someone";
    return node('<div class="' + (big ? "sx-noti-row" : "sx-noti") + '">' +
      '<img class="sx-av" src="' + avatar(n.avatar_url) + '" alt="">' +
      '<div class="sx-noti-t"><b>' + esc(who) + "</b> " + esc(notiText(n)) +
      '<div class="sx-noti-time">' + esc(timeago(n.created_at)) + "</div></div></div>");
  }

  /* ---- profile ----------------------------------------------------------- */
  function tile(p) {
    var img0 = (p.images && p.images[0]) || "";
    var t = node('<a class="sx-tile" href="#"><img src="' + esc(img0) + '" alt="" loading="lazy">' +
      ((p.count || 1) > 1 ? '<span class="sx-tile-multi">&#9636;</span>' : "") +
      '<span class="sx-tile-time">' + esc(timeago(p.published)) + "</span></a>");
    t.addEventListener("click", function (e) { e.preventDefault(); openPost(p); });   // → post view (not straight to the image)
    return t;
  }
  function renderProfile(container, res) {
    var a = res.actor || {};
    var wrap = node('<div class="sx-prof-wrap"></div>');
    var followBtn = "";
    if (!res.is_self) {
      var following = res.state === "accepted" || res.state === "pending";
      followBtn = '<button class="sx-prof-btn ' + (following ? "" : "primary") + ' sx-follow" data-row="' + esc(res.row_id || 0) + '" data-target="' + esc(a.id) + '">' +
        (res.state === "accepted" ? "Following" : res.state === "pending" ? "Requested" : "Follow") + "</button>";
    }
    var left = node('<div class="sx-prof-left">' +
      '<button class="sx-prof-back" aria-label="Back to home">&#8249;</button>' +
      '<img class="sx-prof-av" src="' + avatar(a.avatar) + '" alt="">' +
      '<div class="sx-prof-name">' + esc(a.name || a.username) + "</div>" +
      '<div class="sx-prof-handle">' + esc(a.handle) + "</div>" +
      '<div class="sx-prof-stats">' +
        "<div><b>" + (a.posts != null ? a.posts : (res.posts ? res.posts.length : 0)) + "</b><span>Posts</span></div>" +
        "<div><b>" + (a.followers != null ? a.followers : "–") + "</b><span>Followers</span></div>" +
        "<div><b>" + (a.following != null ? a.following : "–") + "</b><span>Following</span></div>" +
      "</div>" + followBtn +
      (a.summary ? '<div class="sx-prof-bio">' + bioHTML(a.summary) + "</div>" : "") +
      (a.url ? '<div class="sx-prof-meta">&#128279; <a href="' + esc(safeUrl(a.url)) + '" target="_blank" rel="noopener">' + esc((a.url || "").replace(/^https?:\/\//, "")) + "</a></div>" : "") +
      "</div>");
    var main = node('<div class="sx-prof-main">' +
      '<div class="sx-prof-tabbar"><span class="sx-prof-tab active">Posts</span>' +
      '<span class="sx-prof-layout"><button data-lay="grid" class="active" title="Grid">&#9638;</button>' +
      '<button data-lay="masonry" title="Masonry">&#9636;</button>' +
      '<button data-lay="list" title="List">&#9776;</button></span></div>' +
      '<div class="sx-grid"></div></div>');
    var grid = $(".sx-grid", main);
    (res.posts || []).forEach(function (p) { grid.appendChild(tile(p)); });
    if (!(res.posts || []).length) grid.appendChild(node('<div class="sx-note">No posts yet.</div>'));
    $all(".sx-prof-layout button", main).forEach(function (b) {
      b.addEventListener("click", function () {
        $all(".sx-prof-layout button", main).forEach(function (x) { x.classList.remove("active"); });
        b.classList.add("active");
        grid.className = "sx-grid" + (b.getAttribute("data-lay") === "grid" ? "" : " " + b.getAttribute("data-lay"));
      });
    });
    var fb = $(".sx-follow", left);
    if (fb) fb.addEventListener("click", function () {
      var accepted = fb.textContent === "Following" || fb.textContent === "Requested";
      if (accepted) {
        act("unfollow", { row_id: fb.getAttribute("data-row") }).then(function (r) {
          if (r.ok) { fb.textContent = "Follow"; fb.classList.add("primary"); fb.setAttribute("data-row", 0); }
          else toast(r.msg || "Couldn’t unfollow.");
        });
      } else {
        act("follow", { target: fb.getAttribute("data-target") }).then(function (r) {
          if (r.ok) { fb.textContent = r.state === "accepted" ? "Following" : "Requested"; fb.classList.remove("primary"); if (r.row_id) fb.setAttribute("data-row", r.row_id); }
          else toast(r.msg || "Couldn’t follow.");
        });
      }
    });
    var backBtn = $(".sx-prof-back", left);
    if (backBtn) backBtn.addEventListener("click", function () { loadPanel("home"); });
    wrap.appendChild(left); wrap.appendChild(main);
    container.innerHTML = ""; container.appendChild(wrap);
  }

  /* ---- direct messages --------------------------------------------------- */
  function renderDirect(container, res) {
    var wrap = node('<div class="sx-dm-wrap"><div class="sx-dm-list"></div>' +
      '<div class="sx-dm-side"><button class="sx-dm-compose">&#9993; Compose</button><button class="active">Inbox</button><button>Sent</button><button>Requests</button></div></div>');
    var list = $(".sx-dm-list", wrap);
    var threads = res.threads || [];
    if (!threads.length) list.appendChild(node('<div class="sx-note">No messages yet.</div>'));
    threads.forEach(function (t) {
      var row = node('<div class="sx-dm-row">' +
        '<img class="sx-av" src="" alt="">' +
        '<div><div class="sx-dm-h">' + esc(t.handle || t.remote_handle || t.remote_actor_url) + "</div>" +
        '<div class="sx-dm-prev">' + esc((t.last_body || "").slice(0, 60)) + "</div></div>" +
        (Number(t.unread) > 0 ? '<span class="sx-dm-unread"></span>' : "") +
        '<span class="sx-dm-time">' + esc(timeago(t.last_at)) + "</span></div>");
      row.addEventListener("click", function () { openThread(container, t.remote_actor_url, t.handle || t.remote_handle); });
      list.appendChild(row);
    });
    container.innerHTML = ""; container.appendChild(wrap);
  }
  function openThread(container, actorUrl, handle) {
    container.innerHTML = '<div class="sx-spin"></div>';
    api("direct", { actor: actorUrl }).then(function (res) {
      var view = node('<div><div class="sx-conv-head"><button class="sx-back">&larr;</button>' +
        '<div class="sx-conv-title">' + esc(handle || "Direct Message") + "</div></div>" +
        '<div class="sx-warn">Direct messages aren’t end-to-end encrypted. Use caution with sensitive data.</div>' +
        '<div class="sx-bubbles"></div>' +
        '<div class="sx-send"><input type="text" placeholder="Type a message…" aria-label="Type a message">' +
        '<button class="sx-send-btn">' + SEND + "</button></div></div>");
      var bub = $(".sx-bubbles", view);
      (res.messages || []).forEach(function (m) {
        if (Number(m.is_deleted)) return;
        bub.appendChild(node('<div class="sx-bub ' + (m.direction === "out" ? "out" : "in") + '">' + esc(m.body) + "</div>"));
        bub.appendChild(node('<div class="sx-bub-time">' + esc(timeago(m.created_at)) + "</div>"));
      });
      $(".sx-back", view).addEventListener("click", function () { loadPanel("direct", true); });
      var input = $(".sx-send input", view), sbtn = $(".sx-send-btn", view);
      function send() {
        var v = input.value.trim(); if (!v) return; input.value = "";
        var opt = node('<div class="sx-bub out">' + esc(v) + "</div>"); bub.appendChild(opt);
        act("dm_send", { target: actorUrl, body: v }).then(function (r) { if (!r.ok) { opt.style.opacity = ".5"; toast(r.msg || "Couldn’t send."); } });
      }
      sbtn.addEventListener("click", send);
      input.addEventListener("keydown", function (e) { if (e.key === "Enter") send(); });
      container.innerHTML = ""; container.appendChild(view);
      bub.scrollTop = bub.scrollHeight;
    });
  }

  /* ---- search ------------------------------------------------------------ */
  function runSearch(term) {
    term = (term || "").trim(); if (!term) return;
    showPanel("search");
    var body = $('.sx-panel[data-panel="search"] .sx-panel-body');
    $('.sx-panel[data-panel="search"] .sx-page-title').textContent = "Search";
    body.innerHTML = '<div class="sx-spin"></div>';
    api("search", { handle: term }).then(function (res) {
      if (!res.ok) { body.innerHTML = '<div class="sx-note">' + esc(res.msg || "No results.") + "</div>"; return; }
      if (res.mode === "profile") { renderProfile(body, res); return; }
      if (res.mode === "feed") {
        body.innerHTML = '<div class="sx-page-title">' + esc(res.title || term) + "</div>";
        var g = node('<div class="sx-grid cols4"></div>');
        (res.items || []).forEach(function (p) { g.appendChild(tile(p)); });
        body.appendChild(g); return;
      }
      // results mode: accounts + photos
      body.innerHTML = "";
      (res.accounts || []).forEach(function (ac) {
        var row = node('<div class="sx-res-row"><img class="sx-av" src="' + avatar(ac.avatar) + '" alt="">' +
          '<div class="sx-res-main"><div class="sx-res-h">' + esc(ac.name) + "</div>" +
          '<div class="sx-res-sub">' + esc(ac.handle) + (ac.followers != null ? " &middot; " + ac.followers + " followers" : "") + "</div></div></div>");
        row.addEventListener("click", function () { runSearch(ac.handle); });
        body.appendChild(row);
      });
      var g2 = node('<div class="sx-grid cols4"></div>');
      (res.items || []).forEach(function (p) { g2.appendChild(tile(p)); });
      body.appendChild(g2);
    });
  }

  /* ---- panel loading / routing ------------------------------------------- */
  var loaded = {};
  function bodyOf(panel) { return $('.sx-panel[data-panel="' + panel + '"] .sx-panel-body'); }
  var FEED_PANELS = { home: 1, local: 1, global: 1 };
  function showPanel(panel) {
    $all(".sx-panel").forEach(function (p) { p.classList.toggle("active", p.getAttribute("data-panel") === panel); });
    $all(".sx-nav a").forEach(function (a) { a.classList.toggle("active", a.getAttribute("data-panel") === panel); });
    // right notifications rail only on feed views; profile takes over the whole width
    app.classList.toggle("profile-mode", panel === "profile");
    app.classList.toggle("wide", panel !== "profile" && !FEED_PANELS[panel]);
    window.scrollTo(0, 0);
  }
  function loadPanel(panel, force) {
    showPanel(panel);
    var body = bodyOf(panel); if (!body) return;
    if (loaded[panel] && !force) return;
    loaded[panel] = true;
    if (panel === "search") return;
    body.innerHTML = '<div class="sx-spin"></div>';
    if (panel === "home" || panel === "local" || panel === "global") {
      api(panel).then(function (r) { renderFeed(body, r); });
    } else if (panel === "notifications") {
      api("notifications").then(function (r) {
        var TABS = [["All", ""], ["Mentions", "mention"], ["Likes", "like"], ["Followers", "follow"], ["Reblogs", "boost"], ["DMs", "dm"]];
        body.innerHTML = '<div class="sx-tabs">' + TABS.map(function (t, i) {
          return '<button class="sx-tab' + (i === 0 ? " active" : "") + '" data-nt="' + t[1] + '">' + t[0] + "</button>"; }).join("") + "</div><div class='sx-noti-body'></div>";
        var nb = $(".sx-noti-body", body), items = r.items || [];
        function draw(filter) {
          nb.innerHTML = "";
          if (filter === "dm") { loadPanel("direct"); return; }
          var list = filter ? items.filter(function (n) { return n.ntype === filter; }) : items;
          if (!list.length) { nb.appendChild(node('<div class="sx-note">Nothing here.</div>')); return; }
          list.forEach(function (n) { nb.appendChild(notiRow(n, true)); });
        }
        $all(".sx-tab", body).forEach(function (tab) {
          tab.addEventListener("click", function () {
            $all(".sx-tab", body).forEach(function (x) { x.classList.remove("active"); });
            tab.classList.add("active"); draw(tab.getAttribute("data-nt"));
          });
        });
        draw("");
      });
    } else if (panel === "discover") {
      // Our engine has no separate "trending" endpoint; the global timeline is
      // the closest faithful source, shown as Pixelfed's Discover-style grid.
      api("global").then(function (r) {
        body.innerHTML = "";
        var g = node('<div class="sx-grid cols4"></div>');
        (r.items || []).forEach(function (p) { g.appendChild(tile(p)); });
        if (!(r.items || []).length) g = node('<div class="sx-note">' + esc(r.msg || "Nothing to discover yet.") + "</div>");
        body.appendChild(g);
      });
    } else if (panel === "direct") {
      api("direct").then(function (r) { renderDirect(body, r); });
    } else if (panel === "profile") {
      api("profile").then(function (r) { r.ok ? renderProfile(body, r) : (body.innerHTML = '<div class="sx-note">' + esc(r.msg || "Couldn’t load profile.") + "</div>"); });
    }
  }

  /* ---- right-rail notifications preview ----------------------------------- */
  function loadRail() {
    var rail = $(".sx-rail-body"); if (!rail) return;
    api("notifications").then(function (r) {
      rail.innerHTML = "";
      (r.items || []).slice(0, 6).forEach(function (n) { rail.appendChild(notiRow(n, false)); });
      if (!(r.items || []).length) rail.appendChild(node('<div class="sx-note" style="padding:24px">Quiet for now.</div>'));
    });
  }

  /* ---- wire up ----------------------------------------------------------- */
  $all(".sx-nav a").forEach(function (a) {
    a.addEventListener("click", function (e) { e.preventDefault(); loadPanel(a.getAttribute("data-panel")); });
  });
  document.addEventListener("click", function (e) {
    var s = e.target.closest("[data-search]");
    if (s) { e.preventDefault(); runSearch(decodeURIComponent(s.getAttribute("data-search"))); }
  });
  var searchInput = $(".sx-search input");
  if (searchInput) searchInput.addEventListener("keydown", function (e) { if (e.key === "Enter") runSearch(searchInput.value); });

  var railBtn = $(".sx-railcard-h button");
  if (railBtn) railBtn.addEventListener("click", function () { $(".sx-rail-body").innerHTML = '<div class="sx-spin"></div>'; loadRail(); });

  // Opening Notifications clears the unread badge (and marks read server-side).
  $all('.sx-nav a[data-panel="notifications"]').forEach(function (a) {
    a.addEventListener("click", function () {
      var b = $(".sx-badge", a); if (b) b.remove();
      act("mark_read", {});
    });
  });

  // theme toggle
  var themeBtn = $(".sx-theme");
  if (themeBtn) themeBtn.addEventListener("click", function () {
    var root = document.documentElement;
    var next = root.getAttribute("data-theme") === "dark" ? "light" : "dark";
    root.setAttribute("data-theme", next);
    try { localStorage.setItem("pixel-theme", next); } catch (e) {}
    themeBtn.innerHTML = next === "dark" ? "&#9790;" : "&#9728;";
  });

  // account menu — login indicator + logout
  var accBtn = $(".sx-me-btn"), accMenu = $(".sx-account-menu");
  if (accBtn && accMenu) {
    accBtn.addEventListener("click", function (e) { e.stopPropagation(); accMenu.hidden = !accMenu.hidden; });
    accMenu.addEventListener("click", function (e) { e.stopPropagation(); });
    document.addEventListener("click", function () { accMenu.hidden = true; });
    var accProf = accMenu.querySelector('[data-panel="profile"]');
    if (accProf) accProf.addEventListener("click", function (e) { e.preventDefault(); accMenu.hidden = true; loadPanel("profile"); });
  }

  loadPanel(app.getAttribute("data-default-panel") || "home");
  loadRail();
})();
