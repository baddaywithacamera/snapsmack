/* UNZUCKER — window logic for the Chrome/Blink port.
   Every action reaches the original Python through blink.call(). The Python
   session (../unzucker_core.py) holds all state, exactly like the tkinter App;
   this file only draws the window and relays clicks. */

const $ = (s) => document.querySelector(s);
const $$ = (s) => Array.from(document.querySelectorAll(s));

/* ── Log line helper ─────────────────────────────────────────────── */
function log(msg, kind) {
  const box = $("#log");
  const el = document.createElement("div");
  el.className = "line " + (kind || "");
  el.textContent = msg;
  box.prepend(el);
  box.classList.add("show");
}
function setStatus(text, kind) {
  const s = $("#status");
  s.textContent = text;
  s.className = "small " + (kind === "ok" ? "" : "muted");
  s.style.color = kind === "err" ? "var(--fg-err)"
                : kind === "ok" ? "var(--fg-ok)"
                : kind === "warn" ? "var(--fg-warn)" : "";
}

/* ── Client-side view state (Python owns the real state) ─────────── */
const S = {
  cells: [],                 // last-rendered cell dicts
  selecting: new Set(),      // indices in the accumulating trigram selection
  detailIndex: 0,
  detailImages: [],
  posting: false,
  pollTimer: null,
  cfgVisible: true,
};

/* ── Boot ────────────────────────────────────────────────────────── */
async function boot() {
  try {
    const st = await blink.call("load_state");
    $("#build").textContent = "build " + st.build;
    const kr = $("#keyring");
    kr.textContent = st.keyring_ok ? "🔒 keyring" : "⚠ no keyring";
    kr.style.color = st.keyring_ok ? "var(--fg-ok)" : "var(--fg-warn)";

    buildThrottle(st.throttle_options, st.config.import_delay);
    buildHours();
    applyConfig(st.config);
    wire();
  } catch (e) {
    log("Could not start: " + e.message, "err");
  }
}

/* ── Config controls ─────────────────────────────────────────────── */
function buildThrottle(options, current) {
  const box = $("#throttle");
  box.innerHTML = "";
  options.forEach((opt) => {
    const lab = document.createElement("label");
    const r = document.createElement("input");
    r.type = "radio"; r.name = "throttle"; r.value = opt.value;
    if (opt.value === current) r.checked = true;
    r.addEventListener("change", saveConfig);
    lab.appendChild(r);
    lab.appendChild(document.createTextNode(" " + opt.label));
    box.appendChild(lab);
  });
}
function buildHours() {
  ["#peak-start", "#peak-end"].forEach((sel) => {
    const el = $(sel);
    for (let h = 0; h < 24; h++) {
      const o = document.createElement("option");
      o.value = String(h); o.textContent = String(h);
      el.appendChild(o);
    }
  });
}
function applyConfig(c) {
  $("#url").value = c.url || "";
  $("#api-key").value = c.api_key || "";
  $("#export").value = c.export_folder || "";
  $("#copyright").value = c.copyright_text || "";
  $("#offpeak").checked = !!c.offpeak_only;
  $("#peak-start").value = String(c.peak_start || "9");
  $("#peak-end").value = String(c.peak_end || "23");
  applyOffpeak();
}
function readConfig() {
  const throttle = document.querySelector('input[name="throttle"]:checked');
  return {
    url: $("#url").value,
    api_key: $("#api-key").value,
    export_folder: $("#export").value,
    copyright_text: $("#copyright").value,
    import_delay: throttle ? throttle.value : "0.5",
    offpeak_only: $("#offpeak").checked,
    peak_start: $("#peak-start").value,
    peak_end: $("#peak-end").value,
  };
}
let _saveTimer = null;
function saveConfig() {
  clearTimeout(_saveTimer);
  _saveTimer = setTimeout(async () => {
    try { await blink.call("save_config", readConfig()); }
    catch (e) { log("Save config failed: " + e.message, "err"); }
  }, 350);
}
function applyOffpeak() {
  $("#offpeak-hours").hidden = !$("#offpeak").checked;
}

/* ── Wire all controls ───────────────────────────────────────────── */
function wire() {
  // Config drawer toggle
  $("#cfg-toggle").addEventListener("click", toggleConfig);

  // Password show/hide
  $("#key-toggle").addEventListener("click", () => {
    const k = $("#api-key");
    const showing = k.type === "text";
    k.type = showing ? "password" : "text";
    $("#key-toggle").textContent = showing ? "show" : "hide";
  });

  // Persist config on edits
  ["#url", "#api-key", "#export", "#copyright"].forEach((s) =>
    $(s).addEventListener("input", saveConfig));
  ["#peak-start", "#peak-end"].forEach((s) => $(s).addEventListener("change", saveConfig));
  $("#offpeak").addEventListener("change", () => { applyOffpeak(); saveConfig(); });

  // Buttons
  $("#btn-connect").addEventListener("click", onConnect);
  $("#btn-parse").addEventListener("click", onParse);
  $("#btn-validate").addEventListener("click", onValidate);
  $("#btn-unload").addEventListener("click", onUnload);
  $("#btn-post").addEventListener("click", onPost);

  // Detail nav
  $("#d-back").addEventListener("click", showGrid);
  $("#d-prev").addEventListener("click", () => moveDetail(-1));
  $("#d-next").addEventListener("click", () => moveDetail(1));
}

function toggleConfig() {
  S.cfgVisible = !S.cfgVisible;
  $("#cfg").hidden = !S.cfgVisible;
  $("#cfg-arrow").textContent = S.cfgVisible ? "▲" : "▼";
  // When posts exist, config becomes a top drawer rather than the whole view.
  $("#cfg").classList.toggle("drawer", S.cfgVisible && S.cells.length > 0);
}

/* ── Connect ─────────────────────────────────────────────────────── */
async function onConnect() {
  const url = $("#url").value.trim();
  const key = $("#api-key").value.trim();
  if (!url || !key) { modalAlert("Missing credentials", "Fill in Site URL and API Key."); return; }
  setConn("Connecting…", "warn");
  try {
    let res = await blink.call("connect", url, key, false);
    if (res && res.needs_confirm) {
      const ok = await modalConfirm(
        "Unencrypted connection",
        "The site URL is not https://, so your API key would travel unencrypted. " +
        "This is a scoped key, not your account password. Send it anyway?");
      if (!ok) { setConn("Not connected", "warn"); setStatus("Connection cancelled — site URL is not https://.", "warn"); return; }
      res = await blink.call("connect", url, key, true);
    }
    setConn(`Connected — ${res.cats} cats, ${res.albums} albums`, "ok");
    setStatus("Connected. Ready to transfer & post.", "ok");
  } catch (e) {
    setConn("Connection failed", "err");
    setStatus("Error: " + e.message, "err");
    modalAlert("Connection failed", e.message);
  }
}
function setConn(text, kind) {
  $("#conn-label").textContent = text;
  const col = kind === "ok" ? "var(--fg-ok)" : kind === "err" ? "var(--fg-err)" : kind === "warn" ? "var(--fg-warn)" : "var(--fg-dim)";
  $("#conn-label").style.color = col;
  $("#conn-dot").style.color = col;
}

/* ── Parse export ────────────────────────────────────────────────── */
async function onParse() {
  const folder = $("#export").value.trim();
  if (!folder) { modalAlert("No folder", "Enter the Instagram export folder path."); return; }
  setStatus("Parsing…", "warn");
  let info;
  try {
    info = await blink.call("parse_export", folder);
  } catch (e) {
    setStatus("Parse: " + e.message, "err");
    modalAlert("Empty export", e.message);
    return;
  }
  (info.errors || []).forEach((err) => log("Parse: " + err, "warn"));

  // Resume decision
  let resume = false;
  if (info.existing_job) {
    resume = await modalConfirm(
      "Resume job?",
      `A saved job "${info.existing_job.job_name}" exists for this folder with ` +
      `${info.existing_job.upload_count} post(s) already uploaded.\n\n` +
      `Resume from where you left off?`);
  }

  // Job name (only asked when starting fresh and auto-detect failed)
  let jobName = info.suggested_job_name || "";
  if (!resume && !jobName) {
    jobName = await modalPrompt(
      "Job name",
      "Could not detect an account name from the folder. Enter a job name:",
      folder.split(/[\\/]/).filter(Boolean).pop() || "job");
    if (jobName === null) { setStatus("Parse cancelled.", "warn"); return; }
  }

  let res;
  try {
    res = await blink.call("begin_job", resume, jobName, $("#url").value.trim());
  } catch (e) {
    setStatus("Job: " + e.message, "err");
    return;
  }

  // Reveal the grid; collapse config to a drawer.
  S.cfgVisible = false;
  $("#cfg").hidden = true;
  $("#cfg-arrow").textContent = "▼";
  $("#grid-section").hidden = false;
  $("#btn-unload").disabled = false;

  renderGrid(res.posts);
  applyTrigrams(res.trigrams);
  const s = res.stats;
  $("#grid-label").textContent =
    `POSTS — ${s.total_posts}  (${s.carousel_posts} carousel, ${s.single_posts} single)  ·  ${s.total_images} images`;
  updateTgLabel(res.trigrams.length);
  $("#prog").max = res.progress.total || 1;
  $("#prog").value = res.progress.done || 0;
  $("#prog-label").textContent = `${res.progress.done} / ${res.progress.total}`;
  const note = res.resumed ? `  (${res.progress.done} already uploaded)` : "";
  setStatus(`Parsed ${s.total_posts} posts.${note}`, "");
}

/* ── Grid rendering ──────────────────────────────────────────────── */
let _thumbObserver = null;
function renderGrid(cells) {
  S.cells = cells;
  S.selecting.clear();
  const grid = $("#grid");
  grid.innerHTML = "";
  if (_thumbObserver) _thumbObserver.disconnect();
  _thumbObserver = new IntersectionObserver(onThumbVisible, { root: grid, rootMargin: "300px" });

  cells.forEach((c) => grid.appendChild(makeCell(c)));
}
function makeCell(c) {
  const el = document.createElement("div");
  el.className = "cell";
  el.dataset.index = c.index;
  el.dataset.path = c.first_image;
  applyCellState(el, c);

  if (c.image_count > 1) {
    const b = document.createElement("span");
    b.className = "cell-badge"; b.textContent = "▦ " + c.image_count;
    el.appendChild(b);
  }
  const tg = document.createElement("span");
  tg.className = "tg-badge"; tg.hidden = true;
  el.appendChild(tg);
  const ex = document.createElement("span");
  ex.className = "excl-mark"; ex.textContent = "EXCLUDED";
  el.appendChild(ex);
  const tick = document.createElement("span");
  tick.className = "status-tick";
  el.appendChild(tick);
  updateCellDecor(el, c);

  el.addEventListener("click", (e) => {
    if (e.ctrlKey || e.metaKey) { onTrigramSelect(c.index); return; }
    openDetail(c.index);
  });
  el.addEventListener("contextmenu", (e) => { e.preventDefault(); onToggleExclude(c.index); });

  _thumbObserver.observe(el);
  return el;
}
function applyCellState(el, c) {
  el.classList.toggle("excluded", c.excluded);
  el.classList.remove("st-ok", "st-error", "st-skip");
  if (c.status === "ok") el.classList.add("st-ok");
  else if (c.status === "error") el.classList.add("st-error");
  else if (c.status === "skip") el.classList.add("st-skip");
}
function updateCellDecor(el, c) {
  const tick = el.querySelector(".status-tick");
  if (c.status === "ok" || c.status === "skip") { tick.textContent = "✓"; tick.style.color = c.status === "ok" ? "var(--fg-ok)" : "var(--fg-warn)"; }
  else if (c.status === "error") { tick.textContent = "✗"; tick.style.color = "var(--fg-err)"; }
  else tick.textContent = "";
  const tg = el.querySelector(".tg-badge");
  if (c.tg_group > 0) { tg.hidden = false; tg.textContent = "T" + c.tg_group + " " + slotLetter(c.tg_slot); }
  else { tg.hidden = true; }
}
function slotLetter(slot) { return { 1: "L", 2: "M", 3: "R" }[slot] || ""; }

function cellEl(index) { return $(`.cell[data-index="${index}"]`); }

async function onThumbVisible(entries) {
  for (const en of entries) {
    if (!en.isIntersecting) continue;
    const el = en.target;
    _thumbObserver.unobserve(el);
    const path = el.dataset.path;
    if (!path || el.querySelector("img")) continue;
    try {
      const uri = await blink.call("thumb", path, 200);
      const img = document.createElement("img");
      img.src = uri; img.alt = "";
      el.insertBefore(img, el.firstChild);
    } catch (e) { /* leave the empty tile */ }
  }
}

/* ── Exclude toggle ──────────────────────────────────────────────── */
async function onToggleExclude(index) {
  try {
    const r = await blink.call("toggle_exclude", index);
    const c = S.cells[index]; if (c) c.excluded = r.excluded;
    const el = cellEl(index); if (el) el.classList.toggle("excluded", r.excluded);
    setStatus(r.excluded ? "Post excluded." : "Post included.", "");
  } catch (e) { log("Exclude failed: " + e.message, "err"); }
}

/* ── Trigram selection + panel ───────────────────────────────────── */
async function onTrigramSelect(index) {
  try {
    const r = await blink.call("trigram_select", index);
    if (r.noop) return;
    if (r.error) { setStatus(r.error, "warn"); return; }
    if (r.removed) { applyTrigramRemoval(r); return; }
    if (r.open_panel) { S.selecting.clear(); refreshSelecting(); openTrigramPanel(r.panel, r.indices); return; }
    // Update accumulating selection rings
    S.selecting = new Set(r.selection || []);
    refreshSelecting();
  } catch (e) { log("Trigram select failed: " + e.message, "err"); }
}
function refreshSelecting() {
  $$(".cell").forEach((el) => el.classList.toggle("selecting", S.selecting.has(Number(el.dataset.index))));
}
function applyTrigrams(trigrams) {
  const map = {};
  trigrams.forEach((g) => g.indices.forEach((idx, i) => { map[idx] = { num: g.num, slot: g.slots[i] }; }));
  S.cells.forEach((c) => { const m = map[c.index]; c.tg_group = m ? m.num : 0; c.tg_slot = m ? m.slot : 0; const el = cellEl(c.index); if (el) updateCellDecor(el, c); });
  updateTgLabel(trigrams.length);
}
function applyTrigramRemoval(r) {
  (r.cleared_indices || []).forEach((idx) => { const c = S.cells[idx]; if (c) { c.tg_group = 0; c.tg_slot = 0; } const el = cellEl(idx); if (el && c) updateCellDecor(el, c); });
  updateTgLabel((r.trigrams || []).length);
  setStatus("Trigram group removed.", "");
}
function updateTgLabel(n) {
  $("#tg-label").textContent = n > 0 ? `${n} trigram${n !== 1 ? "s" : ""}` : "";
}

const SLOT_NAMES = ["LEFT", "MIDDLE", "RIGHT"];
function openTrigramPanel(panel, indices) {
  // Local working order of the 3 selected posts (each = {index, first_image}).
  const order = panel.slice();
  const body = document.createElement("div");
  const draw = () => {
    body.innerHTML = "";
    const h = document.createElement("h2"); h.textContent = "TRIGRAM SLOT ORDER"; body.appendChild(h);
    const p = document.createElement("p"); p.className = "muted small";
    p.textContent = "Arrange the three posts left → middle → right, then Lock."; body.appendChild(p);
    const slots = document.createElement("div"); slots.className = "tg-slots";
    order.forEach((item, i) => {
      const col = document.createElement("div"); col.className = "tg-slot";
      const name = document.createElement("span"); name.className = "slot-name"; name.textContent = SLOT_NAMES[i]; col.appendChild(name);
      const img = document.createElement("img"); img.alt = ""; col.appendChild(img);
      blink.call("thumb", item.first_image, 120).then((u) => { img.src = u; }).catch(() => {});
      slots.appendChild(col);
    });
    body.appendChild(slots);
    const swaps = document.createElement("div"); swaps.className = "tg-swaps";
    [[0, 1, "⇄ L·M"], [1, 2, "⇄ M·R"]].forEach(([a, b, lbl]) => {
      const btn = document.createElement("button"); btn.type = "button"; btn.textContent = lbl;
      btn.addEventListener("click", () => { const t = order[a]; order[a] = order[b]; order[b] = t; draw(); });
      swaps.appendChild(btn);
    });
    body.appendChild(swaps);
    const rowBtns = document.createElement("div"); rowBtns.className = "modal-row";
    const cancel = document.createElement("button"); cancel.className = "ghost"; cancel.textContent = "Cancel";
    cancel.addEventListener("click", closeModal);
    const lock = document.createElement("button"); lock.className = "accent"; lock.textContent = "LOCK TRIGRAM";
    lock.addEventListener("click", async () => {
      const orderedIndices = order.map((o) => o.index);
      closeModal();
      try {
        const res = await blink.call("lock_trigram", orderedIndices, [1, 2, 3]);
        renderGrid(res.posts);
        applyTrigrams(res.trigrams);
        setStatus(res.status, "ok");
      } catch (e) { log("Lock trigram failed: " + e.message, "err"); }
    });
    rowBtns.appendChild(cancel); rowBtns.appendChild(lock);
    body.appendChild(rowBtns);
  };
  draw();
  showModal(body);
}

/* ── Detail view ─────────────────────────────────────────────────── */
async function openDetail(index) {
  try {
    const d = await blink.call("detail", index);
    S.detailIndex = index;
    S.detailImages = d.images;
    $("#d-info").textContent = `${d.index + 1} / ${d.total}`;
    const type = $("#d-type"); type.textContent = d.post_type.toUpperCase();
    type.style.background = d.post_type === "carousel" ? "var(--accent)" : "var(--accent2)";
    $("#d-count").textContent = `${d.image_count} image${d.image_count !== 1 ? "s" : ""}`;
    $("#d-date").textContent = d.date;
    const cap = $("#d-caption");
    if (d.caption) { cap.textContent = d.caption; cap.style.color = "var(--fg-main)"; }
    else { cap.textContent = "(no caption)"; cap.style.color = "var(--fg-dim)"; }
    const tags = $("#d-tags");
    if (d.hashtags.length) { tags.textContent = d.hashtags.map((t) => "#" + t).join("  "); tags.style.color = "var(--accent2)"; }
    else { tags.textContent = "(no tags)"; tags.style.color = "var(--fg-dim)"; }

    loadPreview(d.images[0] || null);
    const strip = $("#d-strip"); strip.innerHTML = "";
    if (d.images.length > 1) {
      d.images.forEach((p) => {
        const img = document.createElement("img"); img.alt = "";
        blink.call("thumb", p, 80).then((u) => { img.src = u; }).catch(() => {});
        img.addEventListener("click", () => loadPreview(p));
        strip.appendChild(img);
      });
    }
    $("#grid").style.visibility = "hidden";
    $("#detail").hidden = false;
  } catch (e) { log("Open detail failed: " + e.message, "err"); }
}
async function loadPreview(path) {
  const el = $("#d-preview");
  if (!path) { el.removeAttribute("src"); return; }
  try { el.src = await blink.call("preview", path, 640, 420); } catch (e) { /* ignore */ }
}
function showGrid() { $("#detail").hidden = true; $("#grid").style.visibility = "visible"; }
function moveDetail(delta) {
  const next = S.detailIndex + delta;
  if (next >= 0 && next < S.cells.length) openDetail(next);
}

/* ── Validate ────────────────────────────────────────────────────── */
async function onValidate() {
  try {
    const r = await blink.call("validate");
    if (r.ok) {
      setStatus(`✓ ${r.active_count} posts validated OK.`, "ok");
      modalAlert("Validation passed", `All ${r.active_count} posts look good.`);
    } else {
      setStatus(`${r.issues.length} issues found.`, "warn");
      const shown = r.issues.slice(0, 20).join("\n") + (r.issues.length > 20 ? `\n…and ${r.issues.length - 20} more` : "");
      modalAlert("Issues found", shown);
    }
  } catch (e) { modalAlert("Nothing loaded", e.message); }
}

/* ── Transfer & Post ─────────────────────────────────────────────── */
async function onPost() {
  if (S.posting) return;
  let prev;
  try { prev = await blink.call("migration_preview"); }
  catch (e) { modalAlert("Cannot post", e.message); return; }
  if (prev.error) { modalAlert("Cannot post", prev.error); return; }

  const tgNote = prev.trigram_count
    ? `\n\n${prev.trigram_count} trigram group${prev.trigram_count !== 1 ? "s" : ""} will be linked.` : "";
  const ok = await modalConfirm(
    "Confirm migration",
    `Transfer & post ${prev.count} post${prev.count !== 1 ? "s" : ""} to ${prev.dest}?\n\n` +
    `Images will be uploaded via HTTPS and posts created via the API.${tgNote}`);
  if (!ok) return;

  try {
    const r = await blink.call("start_migration");
    if (r.error) { modalAlert("Cannot post", r.error); return; }
    setPosting(true);
    $("#prog").max = r.total; $("#prog").value = 0;
    $("#prog-label").textContent = `0 / ${r.total}`;
    setStatus("Migrating…", "warn");
    showGrid();
    startPolling();
  } catch (e) { modalAlert("Migration failed", e.message); }
}
function setPosting(on) {
  S.posting = on;
  $("#btn-post").classList.toggle("busy", on);
  $("#btn-post").disabled = on;
  ["#btn-parse", "#btn-validate", "#btn-connect"].forEach((s) => { $(s).disabled = on; });
}
function startPolling() {
  if (S.pollTimer) clearInterval(S.pollTimer);
  S.pollTimer = setInterval(pollOnce, 400);
}
async function pollOnce() {
  let r;
  try { r = await blink.call("poll"); } catch (e) { return; }
  (r.events || []).forEach(handleEvent);
  if (!r.posting && S.posting === false) { clearInterval(S.pollTimer); S.pollTimer = null; }
}
function handleEvent(ev) {
  if (ev.type === "progress") {
    $("#prog").value = ev.current;
    $("#prog-label").textContent = `${ev.current} / ${ev.total}`;
    const c = S.cells[ev.index];
    if (c) { c.status = ev.status; const el = cellEl(ev.index); if (el) { applyCellState(el, c); updateCellDecor(el, c); } }
    const icon = ev.success ? "✓" : "✗";
    setStatus(`${icon}  Post ${ev.current}/${ev.total} — ${ev.message}`, ev.success ? "ok" : "err");
  } else if (ev.type === "waiting") {
    setStatus(`⏸  Off-peak only — resuming at ${String(ev.hour).padStart(2, "0")}:00…`, "warn");
  } else if (ev.type === "error") {
    log("Migration error: " + ev.message, "err");
  } else if (ev.type === "done") {
    setPosting(false);
    if (S.pollTimer) { clearInterval(S.pollTimer); S.pollTimer = null; }
    setStatus(`Migration complete — ${ev.total} processed.`, "ok");
  }
}

/* ── Unload job ──────────────────────────────────────────────────── */
async function onUnload() {
  if (S.posting) { modalAlert("Busy", "Cannot unload while a migration is running."); return; }
  const ok = await modalConfirm(
    "Unload job",
    "Unload this job?\n\nThe saved progress file will be deleted. Uploaded posts on your site are NOT affected.");
  if (!ok) return;
  try {
    await blink.call("unload_job");
    S.cells = [];
    $("#grid").innerHTML = "";
    $("#grid-section").hidden = true;
    $("#btn-unload").disabled = true;
    $("#grid-label").textContent = "POSTS — 0";
    updateTgLabel(0);
    $("#prog").value = 0; $("#prog").max = 1; $("#prog-label").textContent = "";
    // Show config again
    S.cfgVisible = true; $("#cfg").hidden = false; $("#cfg").classList.remove("drawer"); $("#cfg-arrow").textContent = "▲";
    setStatus("Job unloaded.", "");
  } catch (e) { modalAlert("Cannot unload", e.message); }
}

/* ── Modal primitives ────────────────────────────────────────────── */
function showModal(node) { const m = $("#modal"); m.innerHTML = ""; m.appendChild(node); $("#modal-back").hidden = false; }
function closeModal() { $("#modal-back").hidden = true; $("#modal").innerHTML = ""; }
function _mkParagraphs(text) {
  const wrap = document.createElement("div");
  text.split("\n").forEach((line) => { const p = document.createElement("p"); p.textContent = line || " "; p.style.margin = "0 0 6px"; wrap.appendChild(p); });
  return wrap;
}
function modalAlert(title, text) {
  return new Promise((resolve) => {
    const body = document.createElement("div");
    const h = document.createElement("h2"); h.textContent = title; body.appendChild(h);
    body.appendChild(_mkParagraphs(text));
    const row = document.createElement("div"); row.className = "modal-row";
    const ok = document.createElement("button"); ok.className = "accent"; ok.textContent = "OK";
    ok.addEventListener("click", () => { closeModal(); resolve(); });
    row.appendChild(ok); body.appendChild(row);
    showModal(body); ok.focus();
  });
}
function modalConfirm(title, text) {
  return new Promise((resolve) => {
    const body = document.createElement("div");
    const h = document.createElement("h2"); h.textContent = title; body.appendChild(h);
    body.appendChild(_mkParagraphs(text));
    const row = document.createElement("div"); row.className = "modal-row";
    const no = document.createElement("button"); no.className = "ghost"; no.textContent = "No";
    no.addEventListener("click", () => { closeModal(); resolve(false); });
    const yes = document.createElement("button"); yes.className = "accent"; yes.textContent = "Yes";
    yes.addEventListener("click", () => { closeModal(); resolve(true); });
    row.appendChild(no); row.appendChild(yes); body.appendChild(row);
    showModal(body); yes.focus();
  });
}
function modalPrompt(title, text, initial) {
  return new Promise((resolve) => {
    const body = document.createElement("div");
    const h = document.createElement("h2"); h.textContent = title; body.appendChild(h);
    body.appendChild(_mkParagraphs(text));
    const input = document.createElement("input"); input.className = "fld"; input.type = "text"; input.value = initial || "";
    body.appendChild(input);
    const row = document.createElement("div"); row.className = "modal-row";
    const cancel = document.createElement("button"); cancel.className = "ghost"; cancel.textContent = "Cancel";
    cancel.addEventListener("click", () => { closeModal(); resolve(null); });
    const ok = document.createElement("button"); ok.className = "accent"; ok.textContent = "OK";
    const done = () => { const v = input.value.trim(); closeModal(); resolve(v || initial || "job"); };
    ok.addEventListener("click", done);
    input.addEventListener("keydown", (e) => { if (e.key === "Enter") done(); });
    row.appendChild(cancel); row.appendChild(ok); body.appendChild(row);
    showModal(body); input.focus(); input.select();
  });
}

document.addEventListener("DOMContentLoaded", boot);
/* ===== SNAPSMACK EOF ===== */
