/* COLD SNAP — window logic. Every Python action is reached through blink.call();
   nothing about posting/compose is reimplemented here. Controls map 1:1 to the
   old tkinter widgets (see README parity table). */
"use strict";
const $  = (s) => document.querySelector(s);
const $$ = (s) => Array.prototype.slice.call(document.querySelectorAll(s));

function log(msg, kind) {
  const el = document.createElement("div");
  el.className = "line " + (kind || "");
  el.textContent = msg;
  $("#log").prepend(el);
}
async function call(method, ...args) {
  return blink.call(method, ...args);
}
function setStatus(el, text, kind) {
  el.className = "status" + (el.classList.contains("wrap") ? " wrap" : "") + (kind ? " " + kind : "");
  el.textContent = text || "";
}

/* thumbnail cache: local path -> data URI (fetched once via Python). */
const _thumbCache = new Map();
async function thumbURI(path) {
  if (!path) return "";
  if (_thumbCache.has(path)) return _thumbCache.get(path);
  let uri = "";
  try { uri = await call("image_data_uri", path); } catch (e) { uri = ""; }
  _thumbCache.set(path, uri);
  return uri;
}
/* set an <img>/preview div background from a local path, async. */
async function fillImg(el, path, asImg) {
  const uri = await thumbURI(path);
  if (asImg) { el.src = uri; }
  else { el.innerHTML = uri ? `<img src="${uri}" alt="">` : ""; }
}

/* ── simple modal ─────────────────────────────────────────────────────────── */
function modal(html, { okText = "OK", cancelText = "Cancel", showCancel = true } = {}) {
  return new Promise((resolve) => {
    $("#modal-body").innerHTML = html;
    const ok = $("#modal-ok"), cancel = $("#modal-cancel"), box = $("#modal");
    ok.textContent = okText; cancel.textContent = cancelText;
    cancel.style.display = showCancel ? "" : "none";
    box.hidden = false;
    const done = (v) => { box.hidden = true; ok.onclick = cancel.onclick = null; resolve(v); };
    ok.onclick = () => done(true);
    cancel.onclick = () => done(false);
  });
}

/* ══════════════════════════════════════════════════════════════════════════
   CONNECTION
   ══════════════════════════════════════════════════════════════════════════ */
function wireConnection(state) {
  $("#version").textContent = "build " + state.version;
  const prof = $("#profile");
  state.profiles.forEach((n) => {
    const o = document.createElement("option"); o.value = n; o.textContent = n; prof.appendChild(o);
  });
  $("#url").value = state.config.url || "";
  $("#api_key").value = state.config.api_key || "";
  if (!state.post_available) {
    const w = $("#post-warn");
    w.hidden = false;
    w.textContent = "Posting/sync is unavailable (" + state.post_error +
      "). You can still compose and save drafts — install requirements to enable SYNC.";
  }

  prof.onchange = async () => {
    if (!prof.value) return;
    try {
      const r = await call("pick_profile", prof.value);
      if (!r.ok) { setStatus($("#conn-status"), r.message, "err"); return; }
      if (r.url) $("#url").value = r.url;
      if (r.api_key) $("#api_key").value = r.api_key;
      setStatus($("#conn-status"), r.message, "warn");
    } catch (e) { setStatus($("#conn-status"), e.message, "err"); }
  };

  $("#save-conn").onclick = async () => {
    try {
      const r = await call("save_connection", $("#url").value, $("#api_key").value);
      setStatus($("#conn-status"), r.message, r.ok ? "ok" : "err");
    } catch (e) { setStatus($("#conn-status"), e.message, "err"); }
  };

  $("#help-btn").onclick = showHelp;
}

/* ══════════════════════════════════════════════════════════════════════════
   MODE SELECTOR
   ══════════════════════════════════════════════════════════════════════════ */
function wireModes() {
  $$(".mode").forEach((btn) => {
    btn.onclick = () => {
      $$(".mode").forEach((b) => b.classList.toggle("active", b === btn));
      const m = btn.dataset.mode;
      $("#mode-solo").hidden = m !== "solo";
      $("#mode-gram").hidden = m !== "gram";
    };
  });
}

/* ══════════════════════════════════════════════════════════════════════════
   COLD ONE — solo
   ══════════════════════════════════════════════════════════════════════════ */
const solo = {
  fields() {
    return {
      image_path: $("#solo-imgpath").dataset.path || $("#solo-imgpath-manual").value.trim(),
      title: $("#solo-title").value, tags: $("#solo-tags").value,
      caption: $("#solo-caption").value, alt: $("#solo-alt").value,
      category: $("#solo-category").value, album: $("#solo-album").value,
      orientation: $("#solo-orientation").value, status: $("#solo-status").value,
      color_mode: $("#solo-color").value, allow_dl: $("#solo-allowdl").checked,
      download_url: $("#solo-dlurl").value,
    };
  },
  clearEditor() {
    ["solo-title", "solo-tags", "solo-caption", "solo-alt", "solo-category",
     "solo-album", "solo-dlurl", "solo-imgpath-manual"].forEach((id) => $("#" + id).value = "");
    $("#solo-orientation").value = "auto"; $("#solo-status").value = "published";
    $("#solo-color").value = "—"; $("#solo-allowdl").checked = false;
    $("#solo-preview").innerHTML = ""; $("#solo-imgpath").textContent = "";
    $("#solo-imgpath").dataset.path = "";
    setStatus($("#solo-ai-status"), "");
  },
  loadEditor(d) {
    $("#solo-title").value = d.title; $("#solo-tags").value = d.tags;
    $("#solo-caption").value = d.caption; $("#solo-alt").value = d.alt;
    $("#solo-category").value = d.category; $("#solo-album").value = d.album;
    $("#solo-orientation").value = d.orientation; $("#solo-status").value = d.status;
    $("#solo-color").value = d.color_mode; $("#solo-allowdl").checked = d.allow_dl;
    $("#solo-dlurl").value = d.download_url;
    $("#solo-imgpath").textContent = d.image_path;
    $("#solo-imgpath").dataset.path = d.image_path;
    fillImg($("#solo-preview"), d.preview || d.image_path, false);
  },
  renderSessions(st) {
    const sel = $("#solo-session");
    sel.innerHTML = "";
    st.sessions.forEach((s) => {
      const o = document.createElement("option"); o.value = s.id; o.textContent = s.label; sel.appendChild(o);
    });
    if (st.current) sel.value = st.current;
    if (!st.sessions.length) { const o = document.createElement("option"); o.textContent = "(no session — New…)"; sel.appendChild(o); }
    this.renderDrafts(st.drafts || []);
  },
  async renderDrafts(drafts) {
    const box = $("#solo-drafts"); box.innerHTML = "";
    if (!drafts.length) { box.innerHTML = '<div class="empty">No drafts — compose one on the right.</div>'; return; }
    for (const d of drafts) {
      const row = document.createElement("div"); row.className = "draft";
      row.innerHTML =
        `<img class="thumb" alt=""><div class="info">
           <div class="title">${esc(d.title)}</div>
           <span class="badge ${d.status}">${d.status}</span>
           ${d.error ? `<div class="status err">${esc(d.error)}</div>` : ""}
         </div>
         <div class="btns">
           <button class="mini" data-edit="${d.id}">Edit</button>
           <button class="mini danger" data-del="${d.id}">Del</button>
         </div>`;
      box.appendChild(row);
      fillImg(row.querySelector("img"), d.thumb, true);
      row.querySelector("[data-edit]").onclick = () => solo.edit(d.id);
      row.querySelector("[data-del]").onclick = () => solo.del(d.id);
    }
  },
  async refresh() { this.renderSessions(await call("solo_state")); },
  async edit(id) { this.loadEditor(await call("solo_edit", id)); },
  async del(id) {
    if (!(await modal("<h3>Delete draft</h3><p>Delete this draft?</p>"))) return;
    const r = await call("solo_delete", id); this.renderDrafts(r.drafts); this.clearEditor();
  },
  async chooseImage() {
    try {
      const r = await call("solo_choose_image", "");
      if (r.ok === false && !r.message) return;      // cancelled
      if (r.ok === false) { $("#solo-imgpath-manual").hidden = false; log(r.message, "warn"); return; }
      this._setImage(r);
    } catch (e) {
      $("#solo-imgpath-manual").hidden = false;
      log(e.message + " — paste an absolute image path below.", "warn");
    }
  },
  _setImage(r) {
    $("#solo-imgpath").textContent = r.path;
    $("#solo-imgpath").dataset.path = r.path;
    if (r.preview) $("#solo-preview").innerHTML = `<img src="${r.preview}" alt="">`;
    if (r.basename && !$("#solo-title").value) $("#solo-title").value = r.basename;
  },
  async aiFill() {
    const path = $("#solo-imgpath").dataset.path || $("#solo-imgpath-manual").value.trim();
    if (!path) { await modal("<h3>No image</h3><p>Choose an image first.</p>", { showCancel: false }); return; }
    setStatus($("#solo-ai-status"), "Thinking… (Gemini)");
    try {
      const m = await call("solo_ai_fill", path);
      if (m.caption) $("#solo-caption").value = m.caption;
      if (m.alt) $("#solo-alt").value = m.alt;
      if (m.tags) $("#solo-tags").value = m.tags;
      if (m.title && !$("#solo-title").value.trim()) $("#solo-title").value = m.title;
      if (m.category && !$("#solo-category").value.trim()) $("#solo-category").value = m.category;
      if (m.album && !$("#solo-album").value.trim()) $("#solo-album").value = m.album;
      setStatus($("#solo-ai-status"), "Filled ✓ — review before posting", "ok");
    } catch (e) { setStatus($("#solo-ai-status"), ""); await modal(`<h3>AI Fill failed</h3><p>${esc(e.message)}</p>`, { showCancel: false }); }
  },
  async save(ready) {
    try {
      const r = await call("solo_save_draft", this.fields(), ready);
      if (!r.ok) { await modal(`<h3>Can't save</h3><p>${esc(r.message)}</p>`, { showCancel: false }); return; }
      if (r.not_ready) await modal(`<h3>Not ready</h3><p>${esc(r.not_ready)}</p>`, { showCancel: false });
      if (r.big_batch) log(r.big_batch, "warn");
      this.clearEditor(); this.renderSessions(r.state);
      log("Solo draft saved" + (ready ? " (marked OFFLINE POST)." : "."), "ok");
    } catch (e) { await modal(`<h3>Error</h3><p>${esc(e.message)}</p>`, { showCancel: false }); }
  },
  async sync() {
    const t = await call("solo_sync_target");
    if (!t.ok) { setStatus($("#solo-sync-status"), t.message, "warn"); return; }
    const ok = await modal(
      `<h3>Confirm publish</h3><p>Publish <b>${t.count}</b> photo(s) to <b>${esc(t.host)}</b>?</p>
       <p class="muted">${esc(t.url)}</p>`, { okText: "Publish", cancelText: "Cancel" });
    if (!ok) { setStatus($("#solo-sync-status"), "Publish cancelled."); return; }
    setStatus($("#solo-sync-status"), `Syncing ${t.count} draft(s)…`, "warn");
    try {
      const r = await call("solo_sync");
      if (!r.ok) { setStatus($("#solo-sync-status"), r.message, "err"); return; }
      setStatus($("#solo-sync-status"),
        `Synced ${r.synced}/${r.total}. See badges for any failures.`,
        r.synced === r.total ? "ok" : "err");
      this.renderSessions(r.state);
    } catch (e) { setStatus($("#solo-sync-status"), e.message, "err"); }
  },
};

function wireSolo() {
  $("#solo-session").onchange = async (e) => {
    const r = await call("solo_select_session", e.target.value); solo.renderDrafts(r.drafts);
  };
  const acts = {
    "solo-new": async () => {
      const name = await promptText("New session", "Session name:");
      if (name === null) return; solo.renderSessions(await call("solo_new_session", name));
    },
    "solo-export": async () => {
      const r = await call("solo_export_session", ""); if (r.message) log(r.message, r.ok ? "ok" : "warn");
    },
    "solo-import": async () => {
      const r = await call("solo_import_session", ""); if (r.ok) solo.renderSessions(r); else if (r.message) log(r.message, "warn");
    },
    "solo-choose": () => solo.chooseImage(),
    "solo-ai": () => solo.aiFill(),
    "solo-save": () => solo.save(false),
    "solo-post": () => solo.save(true),
    "solo-clear": () => solo.clearEditor(),
  };
  $$("#mode-solo [data-act]").forEach((b) => { const f = acts[b.dataset.act]; if (f) b.onclick = f; });
  $("#solo-sync").onclick = () => solo.sync();
}

/* ══════════════════════════════════════════════════════════════════════════
   COLD STACK — gram
   ══════════════════════════════════════════════════════════════════════════ */
const gram = {
  postFields() {
    return {
      caption: $("#gram-caption").value, tags: $("#gram-tags").value,
      date: $("#gram-date").value, status: $("#gram-status").value,
      allow_comments: $("#gram-comments").checked, allow_dl: $("#gram-allowdl").checked,
      download_url: $("#gram-dlurl").value,
    };
  },
  controls() {
    return {
      crop: (document.querySelector('input[name=gcrop]:checked') || {}).value || "fit",
      size: +$("#gc-size").value, border: +$("#gc-border").value, shadow: +$("#gc-shadow").value,
      fx: +$("#gc-fx").value, fy: +$("#gc-fy").value, zoom: +$("#gc-zoom").value,
      border_color: $("#gc-bcolor").value, bg: $("#gc-bg").value, split: $("#gc-split").checked,
    };
  },
  loadControls(c) {
    setRadio("gcrop", c.crop);
    $("#gc-size").value = c.size; $("#gc-border").value = c.border; $("#gc-shadow").value = c.shadow;
    $("#gc-fx").value = c.fx; $("#gc-fy").value = c.fy; $("#gc-zoom").value = c.zoom;
    $("#gc-bcolor").value = c.border_color; $("#gc-bg").value = c.bg; $("#gc-split").checked = c.split;
    syncOutputs();
  },
  loadPost(p) {
    $("#gram-caption").value = p.caption; $("#gram-tags").value = p.tags;
    $("#gram-date").value = p.date; $("#gram-status").value = p.status;
    $("#gram-comments").checked = p.allow_comments; $("#gram-allowdl").checked = p.allow_dl;
    $("#gram-dlurl").value = p.download_url;
  },
  renderSessions(st) {
    const sel = $("#gram-session"); sel.innerHTML = "";
    st.sessions.forEach((s) => { const o = document.createElement("option"); o.value = s.id; o.textContent = s.label; sel.appendChild(o); });
    if (st.current) sel.value = st.current;
    if (!st.sessions.length) { const o = document.createElement("option"); o.textContent = "(no batch — New…)"; sel.appendChild(o); }
    this.renderDrafts(st.drafts || []);
    if (st.compose) this.renderCompose(st.compose);
  },
  async renderDrafts(drafts) {
    const box = $("#gram-drafts"); box.innerHTML = "";
    if (!drafts.length) { box.innerHTML = '<div class="empty">No items — compose one on the right.</div>'; return; }
    for (const d of drafts) {
      const row = document.createElement("div");
      if (d.type === "trigram") {
        row.className = "draft trigram";
        row.innerHTML =
          `<div class="thumbs"></div><div class="info">
             <div class="title">${esc(d.label)}</div>
             ${d.note ? `<div class="status warn">${esc(d.note)}</div>` : `<span class="badge ${d.badge}">${d.badge}</span>`}
             ${d.error ? `<div class="status err">${esc(d.error)}</div>` : ""}
           </div><div class="btns">
             ${d.synced ? "" : `<button class="mini" data-etri="${d.group_key}">Edit</button>`}
             <button class="mini danger" data-dtri="${d.group_key}">Del</button>
           </div>`;
        box.appendChild(row);
        const strip = row.querySelector(".thumbs");
        for (const t of d.thumbs) { const im = document.createElement("img"); strip.appendChild(im); fillImg(im, t, true); }
        if (!d.synced) row.querySelector("[data-etri]").onclick = () => gram.editTrigram(d.group_key);
        row.querySelector("[data-dtri]").onclick = () => gram.delGroup(d.group_key);
      } else {
        row.className = "draft";
        row.innerHTML =
          `<img class="thumb" alt=""><div class="info">
             <div class="title">${esc(d.label)}</div>
             <span class="badge ${d.status}">${d.status}</span>
             ${d.error ? `<div class="status err">${esc(d.error)}</div>` : ""}
           </div><div class="btns">
             ${d.synced ? "" : `<button class="mini" data-esin="${d.id}">Edit</button>`}
             <button class="mini danger" data-dsin="${d.id}">Del</button>
           </div>`;
        box.appendChild(row);
        fillImg(row.querySelector("img"), d.thumb, true);
        if (!d.synced) row.querySelector("[data-esin]").onclick = () => gram.editSingle(d.id);
        row.querySelector("[data-dsin]").onclick = () => gram.del(d.id);
      }
    }
  },
  renderCompose(c) {
    setRadio("gkind", c.kind);
    $("#gram-trig-style").hidden = c.kind !== "trigram";
    $("#gram-trig-tools").hidden = c.kind !== "trigram";
    setRadio("gtstyle", c.trig_style);
    $("#gram-trig-orient").value = c.trig_orientation;
    $("#gram-cut-a").value = c.cut_a; $("#gram-cut-b").value = c.cut_b;
    this.renderSrc(c.kind);
    this.renderStrip(c);
  },
  renderSrc(kind) {
    const row = $("#gram-src"); row.innerHTML = "";
    if (kind === "trigram") {
      row.innerHTML = `<button data-act="gram-slice" type="button">Choose cover &amp; slice</button>
        <span class="muted">then add images to a slot for carousels</span>`;
      row.querySelector("[data-act]").onclick = () => gram.sliceCover();
    } else {
      row.innerHTML = `<button data-act="gram-add" type="button">Add images…</button>
        <button data-act="gram-clear-imgs" type="button">Clear images</button>`;
      row.children[0].onclick = () => gram.addImages();
      row.children[1].onclick = async () => gram.renderCompose(await call("gram_clear_images"));
    }
  },
  renderStrip(c) {
    const strip = $("#gram-strip"); strip.innerHTML = "";
    if (c.kind === "trigram") {
      if (!c.sliced) { strip.innerHTML = '<div class="empty">Choose a cover and slice it into three.</div>'; }
      else {
        c.slots.forEach((slot, si) => {
          const r = document.createElement("div"); r.className = "slot-row";
          r.innerHTML = `<span class="slot-label">${slot.label}</span><div class="thumbs-inline"></div>
            <button class="mini" data-addslot="${si}">+ imgs</button>`;
          strip.appendChild(r);
          this._cells(r.querySelector(".thumbs-inline"), slot.images, si);
          r.querySelector("[data-addslot]").onclick = () => gram.addToSlot(si);
        });
      }
      // band preview
      const band = $("#gram-band"); band.innerHTML = "";
      (c.band || []).forEach((b) => {
        const cell = document.createElement("div"); cell.className = "cell"; band.appendChild(cell);
        if (b.thumb) fillImg(cell, b.thumb, false);
      });
    } else {
      const wrap = document.createElement("div"); wrap.className = "thumbs-inline"; strip.appendChild(wrap);
      if (!c.images.length) wrap.innerHTML = '<div class="empty">(no images)</div>';
      else this._cells(wrap, c.images, null);
    }
  },
  _cells(wrap, images, slot) {
    images.forEach((im) => {
      const cell = document.createElement("div"); cell.className = "tcell" + (im.selected ? " sel" : "");
      cell.innerHTML =
        `<div class="tframe"><img alt=""></div>
         <div class="tbtns"><span class="muted">${im.tag}</span>
           <button data-mv="-1">◀</button><button data-mv="1">▶</button>
           <button class="danger" data-rm="1">✕</button></div>`;
      wrap.appendChild(cell);
      fillImg(cell.querySelector("img"), im.thumb, true);
      const sarg = slot === null ? null : slot;
      cell.querySelector(".tframe").onclick = () => gram.select(sarg, im.idx);
      cell.querySelector('[data-mv="-1"]').onclick = () => gram.move(sarg, im.idx, -1);
      cell.querySelector('[data-mv="1"]').onclick = () => gram.move(sarg, im.idx, 1);
      cell.querySelector('[data-rm="1"]').onclick = () => gram.remove(sarg, im.idx);
    });
  },
  async refresh() { this.renderSessions(await call("gram_state")); },
  async setKind(k) { this.renderCompose(await call("gram_set_kind", k)); },
  async addImages() {
    try {
      const r = await call("gram_add_images", null);
      if (r.message) log(r.message, "warn");
      this.renderCompose(r);
    } catch (e) {
      $("#gram-paths-manual").hidden = false;
      log(e.message + " — paste path(s) below, separated by |, then press Enter.", "warn");
    }
  },
  async addManual(raw) {
    const paths = raw.split("|").map((s) => s.trim()).filter(Boolean);
    if (!paths.length) return;
    this.renderCompose(await call("gram_add_images", paths));
    $("#gram-paths-manual").value = "";
  },
  async sliceCover() {
    try {
      const r = await call("gram_slice_cover", "", +$("#gram-cut-a").value, +$("#gram-cut-b").value, $("#gram-trig-orient").value);
      if (r.ok === false) { if (r.message && r.message !== "Slice cancelled.") log(r.message, "warn"); if (/No native/.test(r.message || "")) $("#gram-paths-manual").hidden = false; return; }
      this.renderCompose(r);
    } catch (e) {
      $("#gram-paths-manual").hidden = false;
      log(e.message + " — paste the cover's absolute path below, then press Enter.", "warn");
    }
  },
  async reslice() { this.renderCompose(await call("gram_reslice", +$("#gram-cut-a").value, +$("#gram-cut-b").value)); },
  async addToSlot(si) {
    try { const r = await call("gram_add_to_slot", si, null); if (r.message) log(r.message, "warn"); this.renderCompose(r); }
    catch (e) { log(e.message, "warn"); }
  },
  async select(slot, idx) {
    const r = await call("gram_select_image", slot, idx);
    if (!r.ok) return;
    this.loadControls(r.controls);
    fillImg($("#gram-sel-preview"), r.preview, false);
    this.renderCompose(r.compose);
  },
  async writeControls() { await call("gram_write_controls", this.controls()); },
  async recrop() {
    const r = await call("gram_recrop", this.controls());
    if (!r.ok) return;
    fillImg($("#gram-sel-preview"), r.preview, false);
    this.renderCompose(r.compose);
  },
  async move(slot, idx, d) { this.renderCompose(await call("gram_move", slot, idx, d)); },
  async remove(slot, idx) { const r = await call("gram_remove", slot, idx); if (r.message) log(r.message, "warn"); this.renderCompose(r); },
  async editSingle(id) { const r = await call("gram_edit_single", id); if (r.ok) { this.loadPost(r.post); this.renderCompose(r.compose); } },
  async editTrigram(gk) { const r = await call("gram_edit_trigram", gk); if (r.ok) { this.loadPost(r.post); this.renderCompose(r.compose); } },
  async del(id) { if (!(await modal("<h3>Delete</h3><p>Delete this item from the batch?</p>"))) return; this.renderDrafts((await call("gram_delete", id)).drafts); },
  async delGroup(gk) { if (!(await modal("<h3>Delete trigram</h3><p>Delete all three chunks of this trigram?</p>"))) return; this.renderDrafts((await call("gram_delete_group", gk)).drafts); },
  clearCompose() { this.postClear(); },
  postClear() {
    ["gram-caption", "gram-tags", "gram-date", "gram-dlurl", "gram-paths-manual"].forEach((id) => $("#" + id).value = "");
    $("#gram-status").value = "published"; $("#gram-comments").checked = true; $("#gram-allowdl").checked = false;
  },
  async commit(ready) {
    const r = await call("gram_commit", this.postFields(), ready);
    if (!r.ok) { await modal(`<h3>Can't post</h3><p>${esc(r.message)}</p>`, { showCancel: false }); return; }
    if (r.big_batch) log(r.big_batch, "warn");
    this.postClear();
    this.renderSessions(r.state);
    log("Gram item saved" + (ready ? " (marked OFFLINE POST)." : "."), "ok");
  },
  async clear() { const c = await call("gram_clear_compose"); this.postClear(); this.renderCompose(c); },
  async sync() {
    const t = await call("gram_sync_target");
    if (!t.ok) { setStatus($("#gram-sync-status"), t.message, "warn"); return; }
    const ok = await modal(
      `<h3>Confirm publish</h3><p>Publish <b>${t.count}</b> post(s) to <b>${esc(t.host)}</b>?</p>
       <p class="muted">${esc(t.url)}</p>`, { okText: "Publish", cancelText: "Cancel" });
    if (!ok) { setStatus($("#gram-sync-status"), "Publish cancelled."); return; }
    setStatus($("#gram-sync-status"), `Syncing ${t.count} item(s)…`, "warn");
    try {
      const r = await call("gram_sync");
      if (!r.ok) { setStatus($("#gram-sync-status"), r.message, "err"); return; }
      setStatus($("#gram-sync-status"),
        `Synced ${r.synced}/${r.total} & verified. Trigrams promote atomically once all three land.`,
        r.synced === r.total ? "ok" : "err");
      this.renderSessions(r.state);
    } catch (e) { setStatus($("#gram-sync-status"), e.message, "err"); }
  },
};

function wireGram() {
  $("#gram-session").onchange = async (e) => { const r = await call("gram_select_session", e.target.value); gram.renderDrafts(r.drafts); };
  const acts = {
    "gram-new": async () => { const name = await promptText("New batch", "Batch name:"); if (name === null) return; gram.renderSessions(await call("gram_new_session", name)); },
    "gram-export": async () => { const r = await call("gram_export_session", ""); if (r.message) log(r.message, r.ok ? "ok" : "warn"); },
    "gram-import": async () => { const r = await call("gram_import_session", ""); if (r.ok) gram.renderSessions(r); else if (r.message) log(r.message, "warn"); },
    "gram-reslice": () => gram.reslice(),
    "gram-recrop": () => gram.recrop(),
    "gram-save": () => gram.commit(false),
    "gram-post": () => gram.commit(true),
    "gram-clear": () => gram.clear(),
  };
  $$("#mode-gram [data-act]").forEach((b) => { const f = acts[b.dataset.act]; if (f) b.onclick = f; });
  $("#gram-sync").onclick = () => gram.sync();

  // kind / trig-style / orientation
  $$('input[name=gkind]').forEach((r) => r.onchange = () => gram.setKind(r.value));
  $$('input[name=gtstyle]').forEach((r) => r.onchange = () => call("gram_set_trig_style", r.value));
  $("#gram-trig-orient").onchange = (e) => call("gram_set_trig_orientation", e.target.value);
  $("#gram-cut-a").onchange = () => gram.reslice();
  $("#gram-cut-b").onchange = () => gram.reslice();

  // per-image controls: write back on change; focal/zoom also re-crop
  ["gc-size", "gc-border", "gc-shadow", "gc-bcolor", "gc-bg"].forEach((id) => $("#" + id).addEventListener("change", () => gram.writeControls()));
  $("#gc-split").addEventListener("change", () => gram.writeControls());
  $$('input[name=gcrop]').forEach((r) => r.addEventListener("change", () => gram.writeControls()));
  ["gc-fx", "gc-fy", "gc-zoom"].forEach((id) => $("#" + id).addEventListener("change", () => gram.recrop()));
  // live range outputs
  [["gc-size", "gc-size-o"], ["gc-fx", "gc-fx-o"], ["gc-fy", "gc-fy-o"], ["gc-zoom", "gc-zoom-o"],
   ["gc-border", "gc-border-o"], ["gc-shadow", "gc-shadow-o"]].forEach(([i, o]) =>
    $("#" + i).addEventListener("input", () => $("#" + o).textContent = $("#" + i).value));

  $("#gram-paths-manual").addEventListener("keydown", (e) => { if (e.key === "Enter") gram.addManual(e.target.value); });
  $("#solo-imgpath-manual").addEventListener("change", (e) => {
    const p = e.target.value.trim();
    if (p) { $("#solo-imgpath").textContent = p; $("#solo-imgpath").dataset.path = p; fillImg($("#solo-preview"), p, false); }
  });
}

/* ── small helpers ────────────────────────────────────────────────────────── */
function esc(s) { const d = document.createElement("div"); d.textContent = s == null ? "" : String(s); return d.innerHTML; }
function setRadio(name, val) { const el = document.querySelector(`input[name=${name}][value="${val}"]`); if (el) el.checked = true; }
function syncOutputs() {
  [["gc-size", "gc-size-o"], ["gc-fx", "gc-fx-o"], ["gc-fy", "gc-fy-o"], ["gc-zoom", "gc-zoom-o"],
   ["gc-border", "gc-border-o"], ["gc-shadow", "gc-shadow-o"]].forEach(([i, o]) => $("#" + o).textContent = $("#" + i).value);
}
function promptText(title, label) {
  return new Promise((resolve) => {
    $("#modal-body").innerHTML = `<h3>${esc(title)}</h3><label class="fld"><span>${esc(label)}</span>
      <input id="_prompt" type="text"></label>`;
    const ok = $("#modal-ok"), cancel = $("#modal-cancel"), box = $("#modal");
    ok.textContent = "OK"; cancel.textContent = "Cancel"; cancel.style.display = "";
    box.hidden = false;
    setTimeout(() => { const i = $("#_prompt"); if (i) i.focus(); }, 20);
    const done = (v) => { box.hidden = true; ok.onclick = cancel.onclick = null; resolve(v); };
    ok.onclick = () => done(($("#_prompt") || {}).value || "");
    cancel.onclick = () => done(null);
  });
}

/* ── help (mirrors coldsnap.py _show_help) ────────────────────────────────── */
function showHelp() {
  const h = `
    <h3>COLD SNAP — Help</h3>
    <div class="help-sec"><b>WHAT COLD SNAP IS</b>
      <p>COLD SNAP builds posts completely offline, then publishes them to your SnapSmack
      site when you have a connection. Compose now — on a plane, a couch, or a dead zone —
      and sync later.</p></div>
    <div class="help-sec"><b>THE TWO MODES</b>
      <div class="help-item"><span class="k">COLD ONE</span> — one photo per post, for SOLO (SmackOneOut) photoblog sites.</div>
      <div class="help-item"><span class="k">COLD STACK</span> — a stack of photos as one carousel (or a trigram), for GRAM (GramOfSmack) sites.</div>
      <p class="muted">Connect to a site that matches the mode you're posting from.</p></div>
    <div class="help-sec"><b>CONNECTING</b>
      <p>Pick a saved profile, or type your Site URL and API Key (generate the key in
      SnapSmack Admin → Settings → API Access) and click SAVE / APPLY. You do NOT need to
      be connected to compose — only to sync.</p></div>
    <div class="help-sec"><b>THE WORKFLOW</b>
      <div class="help-item"><span class="k">1. Compose</span> — add your photo(s), caption and options. No network needed.</div>
      <div class="help-item"><span class="k">2. OFFLINE POST</span> — commits a draft as ready to go. Compose as many as you like.</div>
      <div class="help-item"><span class="k">3. SYNC</span> — when online, click SYNC. COLD SNAP confirms — naming the site — then publishes each ready post and verifies it landed.</div></div>
    <div class="help-sec"><b>AI CAPTIONS</b>
      <p>If a Gemini key is set, COLD SNAP can suggest a caption for a photo. Treat it as a
      starting point and edit it to your own voice before posting.</p></div>
    <div class="help-sec"><b>YOUR DRAFTS ARE SAFE</b>
      <p>Drafts are saved on this computer and survive closing COLD SNAP. A failed sync leaves
      the post marked so you can retry — nothing is lost. Export a batch to a USB/folder to move
      it between machines.</p></div>`;
  modal(h, { okText: "Close", showCancel: false });
}

/* ── boot ─────────────────────────────────────────────────────────────────── */
async function boot() {
  try {
    const state = await call("load_state");
    wireConnection(state);
    wireModes();
    wireSolo();
    wireGram();
    solo.renderSessions(state.solo);
    gram.renderSessions(state.gram);
    log("COLD SNAP ready — compose offline, sync when connected.", "ok");
  } catch (e) {
    $("#log").innerHTML = "";
    log("Could not start: " + e.message, "err");
  }
}
document.addEventListener("DOMContentLoaded", boot);
/* ===== SNAPSMACK EOF ===== */
