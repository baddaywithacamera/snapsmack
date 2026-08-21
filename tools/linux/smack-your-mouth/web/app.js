/* SMACK YOUR MOUTH — window logic for the Chrome/Blink port.
   Every Python action is reached through blink.call(); this file only builds the
   same controls the tkinter window had and renders what the core returns. */

const $ = (s) => document.querySelector(s);

let BUSY = false;
// Track live reply-box edits so we can flush them before a sync (mirrors the
// tkinter shell saving typed-but-unsaved reply text before it syncs).
const REPLY_EDITS = {}; // item_id -> current textarea value

/* ── status line ─────────────────────────────────────────────────────────── */
function setStatus(text, kind) {
  const el = $("#status");
  el.textContent = text;
  el.className = "status " + (kind || "");
}

/* ── busy guard (disables the Sync button while a bg call runs) ───────────── */
function setBusy(on) {
  BUSY = on;
  $("#btn-sync").disabled = on;
}

/* ── call wrapper: guards busy + surfaces errors to the status line ───────── */
async function call(method, ...args) {
  return blink.call(method, ...args);
}

/* ── modal helpers ───────────────────────────────────────────────────────── */
function askFolder(title) {
  return new Promise((resolve) => {
    $("#prompt-title").textContent = title;
    const input = $("#prompt-input");
    input.value = "";
    $("#prompt-overlay").classList.remove("hidden");
    input.focus();
    const done = (val) => {
      $("#prompt-overlay").classList.add("hidden");
      $("#prompt-ok").onclick = null;
      $("#prompt-cancel").onclick = null;
      resolve(val);
    };
    $("#prompt-ok").onclick = () => done(input.value.trim());
    $("#prompt-cancel").onclick = () => done(null);
  });
}

function askConfirm(title, bodyHtml) {
  return new Promise((resolve) => {
    $("#confirm-title").textContent = title;
    $("#confirm-body").innerHTML = bodyHtml;
    $("#confirm-overlay").classList.remove("hidden");
    const done = (val) => {
      $("#confirm-overlay").classList.add("hidden");
      $("#confirm-ok").onclick = null;
      $("#confirm-cancel").onclick = null;
      resolve(val);
    };
    $("#confirm-ok").onclick = () => done(true);
    $("#confirm-cancel").onclick = () => done(false);
  });
}

/* ── boot ────────────────────────────────────────────────────────────────── */
async function boot() {
  wireStaticControls();
  try {
    const state = await call("load_state");
    renderState(state);
  } catch (e) {
    setStatus("Could not load: " + e.message, "err");
  }
}

function renderState(state) {
  $("#build").textContent = "build " + (state.build_version || "");
  if (!state.engine_ok) {
    setStatus("Engine failed to import: " + (state.engine_err || ""), "err");
  }
  $("#author").value = (state.config && state.config.reply_author) || "SnapSmack";
  $("#one-off-url").value = (state.config && state.config.one_off_url) || "";
  if (state.config && state.config.has_one_off_key) {
    $("#one-off-key").placeholder = "•••••• (saved)";
  }
  renderSessions(state.sessions, state.current_session);
  renderFleet(state.fleet);
  renderQueue(state.queue);
  if (state.note) setStatus(state.note, "");
}

/* ── sessions ────────────────────────────────────────────────────────────── */
function renderSessions(sessions, currentId) {
  const sel = $("#session-select");
  sel.innerHTML = "";
  (sessions || []).forEach((s) => {
    const opt = document.createElement("option");
    opt.value = s.session_id;
    opt.textContent = s.label;
    if (s.session_id === currentId) opt.selected = true;
    sel.appendChild(opt);
  });
}

/* ── fleet ───────────────────────────────────────────────────────────────── */
function renderFleet(fleet) {
  const wrap = $("#fleet-list");
  wrap.innerHTML = "";
  if (!fleet || !fleet.length) {
    wrap.innerHTML = '<div class="fleet-empty">No fleet sites. '
      + 'Use a one-off site, or run THE HUB → Discover Fleet.</div>';
    return;
  }
  fleet.forEach((e) => {
    const row = document.createElement("div");
    row.className = "fleet-row";
    row.innerHTML = `<span class="fleet-name">${esc(e.name)}</span>`
      + `<span class="fleet-tag">[${esc(e.tag)}]</span>`
      + `<span class="fleet-url">${esc(e.site_url)}</span>`;
    wrap.appendChild(row);
  });
}

/* ── queue ───────────────────────────────────────────────────────────────── */
const STATUS_COLORS = {
  pulled: "dim", ready: "accent", syncing: "warn",
  synced: "ok", failed: "err", queued: "warn",
};

function renderQueue(queue) {
  const q = $("#queue");
  q.innerHTML = "";
  for (const k in REPLY_EDITS) delete REPLY_EDITS[k];
  const counts = (queue && queue.counts) || {};
  $("#queue-count").textContent =
    `   ${counts.total || 0} total · ${counts.ready || 0} ready · `
    + `${counts.synced || 0} synced · ${counts.failed || 0} failed`;
  const items = (queue && queue.items) || [];
  if (!items.length) {
    q.innerHTML = '<div class="queue-empty">No comments pulled yet. '
      + 'Use PULL ALL PENDING (or PULL THIS SITE) when you have a connection.</div>';
    return;
  }
  items.forEach((it) => q.appendChild(renderItem(it)));
}

function renderItem(it) {
  const card = document.createElement("div");
  card.className = "qcard";
  card.dataset.itemId = it.item_id;

  const head = document.createElement("div");
  head.className = "qhead";
  head.innerHTML = `<span class="qsrc">▶ ${esc(it.site_name)}</span>`
    + `<span class="qstatus ${STATUS_COLORS[it.status] || "dim"}">`
    + `${esc((it.status || "").toUpperCase())}</span>`;
  card.appendChild(head);

  const who = document.createElement("div");
  who.className = "qwho";
  who.textContent = it.who;
  card.appendChild(who);

  const meta = document.createElement("div");
  meta.className = "qmeta";
  meta.textContent = it.meta;
  card.appendChild(meta);

  const body = document.createElement("div");
  body.className = "qbody";
  body.textContent = it.text;
  card.appendChild(body);

  // Decision buttons + CLEAR
  const drow = document.createElement("div");
  drow.className = "qdecide";
  const decideBtn = (act, label, kindClass) => {
    const b = document.createElement("button");
    b.className = "btn " + (it.action === act ? kindClass : "");
    b.textContent = label;
    b.onclick = () => onDecision(it.item_id, act);
    return b;
  };
  drow.appendChild(decideBtn("approve", "APPROVE", "ok"));
  drow.appendChild(decideBtn("delete", "DELETE", "danger"));
  drow.appendChild(decideBtn("spam", "SPAM", "warn"));
  const sp = document.createElement("span");
  sp.className = "spacer";
  drow.appendChild(sp);
  const clr = document.createElement("button");
  clr.className = "btn tiny";
  clr.textContent = "CLEAR";
  clr.onclick = () => onDecision(it.item_id, "none");
  drow.appendChild(clr);
  card.appendChild(drow);

  // Reply row
  const rrow = document.createElement("div");
  rrow.className = "qreply-head";
  rrow.innerHTML = '<label class="tag">REPLY</label>';
  const saveBtn = document.createElement("button");
  saveBtn.className = "btn";
  saveBtn.textContent = "SAVE REPLY";
  saveBtn.style.marginLeft = "auto";
  const ta = document.createElement("textarea");
  ta.className = "qreply";
  ta.rows = 2;
  ta.value = it.reply_text || "";
  ta.oninput = () => { REPLY_EDITS[it.item_id] = ta.value; };
  saveBtn.onclick = () => onSaveReply(it.item_id, ta.value);
  rrow.appendChild(saveBtn);
  card.appendChild(rrow);
  card.appendChild(ta);

  if (it.error) {
    const err = document.createElement("div");
    err.className = "qerr";
    err.textContent = "⚠ " + it.error;
    card.appendChild(err);
  }
  return card;
}

/* ── actions ─────────────────────────────────────────────────────────────── */
async function onDecision(itemId, action) {
  // Pass any typed-but-unsaved reply so a decision click never loses it.
  const reply = REPLY_EDITS[itemId];
  try {
    await call("set_decision", itemId, action, reply === undefined ? null : reply);
    await reloadQueue();
    await reloadSessions();
  } catch (e) {
    setStatus("Decision failed: " + e.message, "err");
  }
}

async function onSaveReply(itemId, text) {
  try {
    await call("save_reply", itemId, text, $("#author").value.trim());
    delete REPLY_EDITS[itemId];
    await reloadQueue();
    await reloadSessions();
    setStatus("Reply saved.", "ok");
  } catch (e) {
    setStatus("Save reply failed: " + e.message, "err");
  }
}

async function reloadQueue() {
  const queue = await call("load_queue");
  renderQueue(queue);
}

async function reloadSessions() {
  // Re-read state cheaply for the session labels (counts change with edits).
  const state = await call("load_state");
  renderSessions(state.sessions, state.current_session);
}

/* ── static control wiring ───────────────────────────────────────────────── */
function wireStaticControls() {
  $("#session-select").onchange = async (e) => {
    try {
      const st = await call("select_session", e.target.value);
      renderState(st);
      setStatus("Session selected.", "ok");
    } catch (err) { setStatus("Select failed: " + err.message, "err"); }
  };

  $("#btn-new-session").onclick = async () => {
    try {
      renderState(await call("new_session"));
      setStatus("New session created.", "ok");
    } catch (e) { setStatus("New session failed: " + e.message, "err"); }
  };

  $("#author").onchange = async () => {
    try { await call("set_author", $("#author").value.trim()); }
    catch (e) { setStatus("Author save failed: " + e.message, "err"); }
  };

  $("#btn-refresh-fleet").onclick = async () => {
    try {
      const r = await call("refresh_fleet");
      renderFleet(r.fleet);
      if (r.note) setStatus(r.note, r.fleet.length ? "" : "warn");
    } catch (e) { setStatus("Refresh failed: " + e.message, "err"); }
  };

  $("#btn-probe").onclick = async () => {
    if (BUSY) return;
    setBusy(true);
    setStatus("Probing fleet…", "warn");
    try {
      const r = await call("probe_fleet");
      renderFleet(r.fleet);
      setStatus(r.note || "Fleet probed.", "ok");
    } catch (e) { setStatus("Probe failed: " + e.message, "err"); }
    finally { setBusy(false); }
  };

  $("#btn-pull-all").onclick = async () => {
    if (BUSY) return;
    setBusy(true);
    setStatus("Pulling from the fleet…", "warn");
    try {
      const r = await call("pull_all");
      await reloadQueue();
      await reloadSessions();
      setStatus(r.note, r.errors && r.errors.length ? "err" : "ok");
    } catch (e) { setStatus("Pull failed: " + e.message, "err"); }
    finally { setBusy(false); }
  };

  $("#btn-pull-one").onclick = async () => {
    if (BUSY) return;
    const url = $("#one-off-url").value.trim();
    const key = $("#one-off-key").value.trim();
    setBusy(true);
    setStatus("Pulling one site…", "warn");
    try {
      const r = await call("pull_one", url, key);
      await reloadQueue();
      await reloadSessions();
      setStatus(r.note, "ok");
    } catch (e) { setStatus("Pull failed: " + e.message, "err"); }
    finally { setBusy(false); }
  };

  $("#btn-export").onclick = async () => {
    const dest = await askFolder("Export moderation batch to this folder:");
    if (!dest) return;
    try {
      const r = await call("export_session", dest);
      setStatus(r.note, "ok");
    } catch (e) { setStatus("Export failed: " + e.message, "err"); }
  };

  $("#btn-import").onclick = async () => {
    const src = await askFolder("Import a moderation batch from this folder:");
    if (!src) return;
    try {
      renderState(await call("import_session", src));
      setStatus("Batch imported.", "ok");
    } catch (e) { setStatus("Import failed: " + e.message, "err"); }
  };

  $("#btn-sync").onclick = onSync;
}

/* ── sync (flush unsaved replies → confirm → run) ────────────────────────── */
async function onSync() {
  if (BUSY) return;
  // Flush any typed-but-unsaved reply text first (mirrors _flush_unsaved_replies).
  const edits = Object.keys(REPLY_EDITS).map((id) => [id, REPLY_EDITS[id]]);
  try {
    if (edits.length) await call("flush_replies", edits);
    for (const k in REPLY_EDITS) delete REPLY_EDITS[k];

    const prev = await call("sync_preview");
    if (!prev.count) {
      setStatus("Nothing to sync — decide or reply on a comment first.", "warn");
      return;
    }
    const iword = prev.count === 1 ? "decision" : "decisions";
    let headline = `Apply ${prev.count} ${iword}`;
    if (prev.deletes) {
      headline += ` (${prev.deletes} ${prev.deletes === 1 ? "delete" : "deletes"})`;
    }
    headline += "?";
    const where = prev.sites.length === 1 ? prev.sites[0] : `${prev.sites.length} sites`;
    let body = `<div>Destination: <b>${esc(where)}</b></div>`;
    if (prev.sites.length > 1) {
      body += '<ul class="site-list">'
        + prev.sites.map((s) => `<li>${esc(s)}</li>`).join("") + "</ul>";
    }
    if (prev.deletes) {
      body += `<div class="danger-note">⚠ ${prev.deletes} `
        + `comment${prev.deletes === 1 ? "" : "s"} will be permanently DELETED.</div>`;
    }
    const ok = await askConfirm(headline, body);
    if (!ok) { setStatus("Sync cancelled.", "warn"); return; }

    setBusy(true);
    setStatus(`Syncing ${prev.count} item(s)…`, "warn");
    const r = await call("sync_run");
    await reloadQueue();
    await reloadSessions();
    const kind = r.fail === 0 ? "ok" : (r.ok ? "warn" : "err");
    setStatus(r.note, kind);
  } catch (e) {
    setStatus("Sync failed: " + e.message, "err");
  } finally {
    setBusy(false);
  }
}

/* ── util ────────────────────────────────────────────────────────────────── */
function esc(s) {
  return String(s == null ? "" : s)
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

document.addEventListener("DOMContentLoaded", boot);
/* ===== SNAPSMACK EOF ===== */
