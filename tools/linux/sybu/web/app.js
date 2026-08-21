/* SMACK YOUR BATCH UP — window logic. All Python work is reached through
   blink.call(); this file only draws the window and marshals results, the same
   role main.py's tkinter widgets played. */

const $ = (s) => document.querySelector(s);
const $$ = (s) => Array.from(document.querySelectorAll(s));

function log(msg, kind) {
  const el = document.createElement("div");
  el.className = "line " + (kind || "");
  el.textContent = msg;
  $("#log").prepend(el);
  while ($("#log").childElementCount > 200) $("#log").lastChild.remove();
}
async function call(fn, ...args) { return blink.call(fn, ...args); }

// ── modal dialogs (confirm / alert / prompt) ───────────────────────────────
function modal(html) {
  return new Promise((resolve) => {
    const card = $("#modalCard");
    card.innerHTML = html;
    $("#modal").classList.remove("hidden");
    card._resolve = resolve;
  });
}
function closeModal(val) { $("#modal").classList.add("hidden"); const c = $("#modalCard"); if (c._resolve) c._resolve(val); }
function esc(s){ return String(s == null ? "" : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
async function alertBox(title, msg) {
  await modal(`<h3>${esc(title)}</h3><p>${esc(msg)}</p><div class="modal-btns"><button class="accent" onclick="closeModal(true)">OK</button></div>`);
}
async function confirmBox(title, msg, okLabel = "Yes", danger = false) {
  return modal(`<h3>${esc(title)}</h3><p>${esc(msg)}</p><div class="modal-btns">
    <button class="ghost" onclick="closeModal(false)">Cancel</button>
    <button class="${danger ? 'post-btn cancel' : 'accent'}" onclick="closeModal(true)">${esc(okLabel)}</button></div>`);
}
async function promptBox(title, label, val = "") {
  const html = `<h3>${esc(title)}</h3><p>${esc(label)}</p><input id="_pIn" type="text" value="${esc(val)}">
    <div class="modal-btns"><button class="ghost" onclick="closeModal(null)">Cancel</button>
    <button class="accent" onclick="closeModal(document.getElementById('_pIn').value)">OK</button></div>`;
  return modal(html);
}

// ── op polling (streams events from a background job) ───────────────────────
async function pollOp(key, onEvents, interval = 400) {
  let seen = 0;
  while (true) {
    let r;
    try { r = await call("op_poll", key, seen); }
    catch (e) { log("poll " + key + " failed: " + e.message, "err"); return { error: e.message }; }
    seen = r.total_seen;
    if (r.events && r.events.length && onEvents) onEvents(r.events);
    if (!r.running) return r;
    await new Promise((res) => setTimeout(res, interval));
  }
}

// ── app state ───────────────────────────────────────────────────────────────
const S = { tab: "solo", asGrams: false, queue: { rows: [] }, cats: [], albums: [], busy: false };

const orientLabel = { auto: "Auto", "0": "Landscape", "1": "Portrait", "2": "Square" };
const orientToApi = { auto: "auto", landscape: "0", portrait: "1", square: "2" };

// ============================================================================
// BOOT
// ============================================================================
async function boot() {
  try {
    const st = await call("load_state");
    fillConfig(st.config);
    fillProfiles(st.profiles);
    fillPresets(st.preset_names);
    applyConnection(st.connection);
    renderQueue(st.queue);
    if (!st.picker) log("No zenity/kdialog found — type folder/file paths into the boxes.", "warn");
    wireTabs();
    wireConfig();
    wirePost();
    wireDrive();
    wireGemini();
    wireAudit();
    wireRepair();
    wireMatch();
    wireSettings();
    $("#helpBtn").onclick = showHelp;
  } catch (e) {
    log("Could not load: " + e.message, "err");
  }
}

// ── config fields ────────────────────────────────────────────────────────────
function fillConfig(c) {
  $("#url").value = c.url || "";
  $("#apiKey").value = c.api_key || "";
  $("#remember").checked = !!c.remember;
  $("#defCat").value = c.default_category || "";
  $("#defAlbum").value = c.default_album || "";
  $("#defOrient").value = orientLabel[orientToApi[(c.default_orientation || "auto").toLowerCase()] || (c.default_orientation || "auto")] || "Auto";
  $("#folder").value = c.last_image_folder || "";
  $("#manifest").value = c.last_manifest_file || "";
  $("#driveCreds").value = c.google_credentials || "";
  $("#driveFolder").value = c.drive_folder_id || "";
  $("#driveEnabled").checked = c.drive_enabled !== false;
  $("#gemKey").value = c.gemini_api_key || "";
  $("#gemPrompt").value = c.gemini_last_prompt || "";
  $("#copyright").value = c.copyright_text || "";
  updateAiDot();
}
function collectConfig() {
  return {
    url: $("#url").value, api_key: $("#apiKey").value, remember: $("#remember").checked,
    default_category: $("#defCat").value, default_album: $("#defAlbum").value,
    default_orientation: orientToApi[$("#defOrient").value.toLowerCase()] || "auto",
    last_image_folder: $("#folder").value, last_manifest_file: $("#manifest").value,
    google_credentials: $("#driveCreds").value, drive_folder_id: $("#driveFolder").value,
    gemini_api_key: $("#gemKey").value, gemini_last_prompt: $("#gemPrompt").value,
    copyright_text: $("#copyright").value,
  };
}
let saveTimer = null;
function saveConfigSoon() {
  clearTimeout(saveTimer);
  saveTimer = setTimeout(() => call("save_config", collectConfig()).catch(() => {}), 500);
}
function wireConfig() {
  ["url","apiKey","remember","defCat","defAlbum","defOrient","folder","manifest",
   "driveCreds","driveFolder","gemKey","gemPrompt","copyright"].forEach(id => {
    const el = document.getElementById(id);
    el.addEventListener("change", () => { saveConfigSoon(); if (id === "gemKey") updateAiDot(); });
  });
  $("#cfgToggle").onclick = () => {
    const cfg = $("#cfg");
    const hidden = cfg.classList.toggle("hidden");
    $("#cfgToggle").textContent = (hidden ? "▼" : "▲") + "  CONFIGURATION";
  };
  $("#browseFolder").onclick = () => browseInto("browse_folder", "#folder");
  $("#browseManifest").onclick = () => browseInto("browse_manifest", "#manifest");
  $("#browseCreds").onclick = () => browseInto("browse_creds", "#driveCreds");
}
async function browseInto(fn, sel) {
  try {
    const r = await call(fn, document.querySelector(sel).value || "");
    if (r.path) { document.querySelector(sel).value = r.path; saveConfigSoon(); }
  } catch (e) { log(e.message, "err"); }
}

// ── LED bar ──────────────────────────────────────────────────────────────────
function setLed(dotId, lblId, state, text) {
  const cls = { ok: "on-ok", err: "on-err", warn: "on-warn", off: "" }[state] || "";
  const dot = document.getElementById(dotId), lbl = document.getElementById(lblId);
  dot.className = "dot " + cls; lbl.className = cls;
  lbl.textContent = text;
}
function applyConnection(cs) {
  if (cs.connected) {
    const modeTxt = cs.site_mode ? (" — " + (cs.mode_tab === "gram" ? "GRAM" : "SOLO")) : "";
    setLed("siteDot", "siteLbl", "ok", "CONNECTED" + modeTxt);
    if (cs.mode_tab && cs.mode_tab !== S.tab && (S.tab === "solo" || S.tab === "gram")) switchTab(cs.mode_tab);
  } else setLed("siteDot", "siteLbl", "off", "NOT CONNECTED");
  setLed("driveDot", "driveLbl", cs.drive ? "ok" : "off", cs.drive ? "AUTHENTICATED" : "NOT CONNECTED");
  if (cs.categories !== undefined) { S.cats = cs.categories; S.albums = cs.albums; fillDatalists(); }
}
function updateAiDot() {
  const has = ($("#gemKey").value || "").trim().length > 0;
  setLed("aiDot", "aiLbl", has ? "ok" : "off", has ? "KEY SET" : "NO KEY");
}
function fillDatalists() {
  $("#catList").innerHTML = S.cats.map(c => `<option value="${esc(c)}">`).join("");
  $("#albumList").innerHTML = S.albums.map(a => `<option value="${esc(a)}">`).join("");
}

// ── tabs ─────────────────────────────────────────────────────────────────────
function wireTabs() {
  $$("#tabs .tab").forEach(b => b.onclick = () => switchTab(b.dataset.tab));
}
function switchTab(tab) {
  S.tab = tab;
  $$("#tabs .tab").forEach(b => b.classList.toggle("active", b.dataset.tab === tab));
  const post = (tab === "solo" || tab === "gram");
  $("#panel-post").classList.toggle("hidden", !post);
  ["audit","repair","match","settings"].forEach(t => $("#panel-" + t).classList.toggle("hidden", t !== tab));
  if (post) {
    S.asGrams = (tab === "gram");
    $("#postBtn").textContent = "POST BATCH";
  }
  if (tab === "audit" && !S._auditLoaded) auditRefresh();
}

// ============================================================================
// CONNECT
// ============================================================================
function wirePost() {
  $("#connectBtn").onclick = connect;
  $("#scanBtn").onclick = scanFolder;
  $("#loadManifestBtn").onclick = loadManifest;
  $("#validateBtn").onclick = validate;
  $("#enrichBtn").onclick = enrich;
  $("#enrichTopBtn").onclick = enrich;
  $("#postBtn").onclick = postOrCancel;
  $("#clearBtn").onclick = clearQueue;
  $("#clearWarnBtn").onclick = clearWarning;
  $("#randomBtn").onclick = randomize;
  $("#selAll").onchange = async () => { await call("set_all_selected", $("#selAll").checked); refreshQueueMeta(); };
  $("#postProfile").onchange = applyPostProfile;
}
async function connect(ack = false) {
  const btn = $("#connectBtn"); btn.disabled = true;
  setLed("siteDot", "siteLbl", "warn", "CONNECTING…");
  try {
    const r = await call("connect", $("#url").value, $("#apiKey").value, $("#remember").checked, ack);
    if (r.needs_insecure_ack) {
      btn.disabled = false;
      if (await confirmBox("Unencrypted connection", r.reason + "\n\nConnect anyway?", "Connect", true)) return connect(true);
      setLed("siteDot", "siteLbl", "off", "NOT CONNECTED"); return;
    }
    S.cats = r.categories; S.albums = r.albums; fillDatalists();
    applyConnection({ connected: true, site_mode: r.site_mode, mode_tab: r.mode_tab, drive: false });
    log("Connected to " + r.base_url + (r.site_mode ? " (" + r.site_mode + ")" : ""), "ok");
    setPostStatus("Connected. Scan a folder or load a manifest.", "");
  } catch (e) {
    setLed("siteDot", "siteLbl", "err", "CONNECTION FAILED");
    await alertBox("Connection failed", e.message);
  } finally { btn.disabled = false; }
}

// ============================================================================
// QUEUE — scan / load / render / edit
// ============================================================================
async function scanFolder() {
  try {
    const r = await call("scan_folder", $("#folder").value, $("#defCat").value, $("#defAlbum").value, $("#defOrient").value);
    saveConfigSoon(); await afterLoad(r);
  } catch (e) { await alertBox("Scan failed", e.message); }
}
async function loadManifest() {
  try {
    const r = await call("load_manifest", $("#manifest").value, $("#folder").value);
    saveConfigSoon();
    if (r.manifest_errors && r.manifest_errors.length) log("Manifest notes: " + r.manifest_errors.join(" | "), "warn");
    await afterLoad(r);
  } catch (e) { await alertBox("Load failed", e.message); }
}
async function afterLoad(r) {
  S.cats = r.cats && r.cats.length ? r.cats : S.cats;
  S.albums = r.albums && r.albums.length ? r.albums : S.albums;
  fillDatalists();
  renderQueue(r);
  setPostStatus(`Loaded ${r.count} image${r.count !== 1 ? "s" : ""}.`, "");
  if (r.restorable > 0) {
    if (await confirmBox("Resume saved enrichment",
        `Found saved Gemini enrichment for ${r.restorable} of these images (from a previous run).\n\nRestore it and skip re-enriching them?`, "Restore")) {
      const q = await call("apply_resume");
      renderQueue(q);
      log(`Restored enrichment for ${r.restorable} image(s).`, "ok");
    }
  }
  // collapse config to show the queue
  if (!$("#cfg").classList.contains("hidden")) $("#cfgToggle").click();
}

function renderQueue(q) {
  S.queue = q;
  const box = $("#queue");
  box.innerHTML = "";
  q.rows.forEach(row => box.appendChild(buildRow(row)));
  refreshQueueMeta();
  loadThumbs();
}
function refreshQueueMeta() {
  const q = S.queue;
  const n = q.count != null ? q.count : q.rows.length;
  $("#queueLbl").textContent = `QUEUE — ${n} ITEM${n !== 1 ? "S" : ""}`;
  $("#clearWarnBtn").disabled = !(q.warnings > 0);
}
function buildRow(row) {
  const el = document.createElement("div");
  el.className = "qrow" + (row.status === "error" ? " err" : "");
  el.dataset.index = row.index;
  el.draggable = true;
  const sw = (row.colors || "").split(/\s+/).filter(Boolean).slice(0, 3);
  el.innerHTML = `
    <div class="qgutter">
      <input type="checkbox" class="rsel" ${row.selected ? "checked" : ""}>
      <span class="handle" title="drag to reorder">⠿</span>
    </div>
    <img class="qthumb" alt="">
    <div class="qfields">
      <div class="qname">${esc(row.file)}</div>
      <div class="qfield-row"><label>title</label><input class="f" data-k="title" value="${esc(row.title)}"></div>
      <div class="qfield-row"><label>tags</label><input class="f" data-k="tags" value="${esc(row.tags)}"></div>
      <div class="qfield-row"><label>caption</label><input class="f" data-k="caption" value="${esc(row.caption)}"></div>
      <div class="qfield-row"><label>alt</label><input class="f" data-k="alt" value="${esc(row.alt)}"></div>
      <div class="qmeta">
        <div><label>category</label><input class="f" data-k="category" list="catList" value="${esc(row.category)}"></div>
        <div><label>album</label><input class="f" data-k="album" list="albumList" value="${esc(row.album)}"></div>
        <div><label>orient</label><select class="f" data-k="orientation">
          ${["auto","0","1","2"].map(o => `<option value="${o}" ${row.orientation===o?"selected":""}>${orientLabel[o]}</option>`).join("")}
        </select></div>
        <div><label>colors</label><div class="swatches">${
          sw.map(h => `<span class="swatch" style="background:${esc(h)}">${esc(h).slice(1)}</span>`).join("") || '<span class="hint">—</span>'
        }</div></div>
      </div>
      ${row.warning ? '<div class="qwarn">⚠ Gemini enrichment failed — re-enrich or Clear AI Warning before posting.</div>' : ""}
    </div>
    <div class="badge ${row.status}">${badgeText(row.status)}</div>`;

  el.querySelector(".rsel").onchange = (e) => call("set_selected", idxOf(el), e.target.checked);
  el.querySelectorAll(".f").forEach(inp => {
    inp.addEventListener("change", () => {
      call("update_entry", idxOf(el), { [inp.dataset.k]: inp.value }).catch(err => log(err.message, "err"));
    });
  });
  // drag reorder
  el.addEventListener("dragstart", (e) => { e.dataTransfer.setData("text/plain", idxOf(el)); });
  el.addEventListener("dragover", (e) => e.preventDefault());
  el.addEventListener("drop", async (e) => {
    e.preventDefault();
    const from = parseInt(e.dataTransfer.getData("text/plain"), 10);
    const to = idxOf(el);
    if (from !== to && !isNaN(from)) { const q = await call("reorder", from, to); renderQueue(q); }
  });
  return el;
}
function idxOf(el) { return parseInt(el.dataset.index, 10); }
function badgeText(s) {
  return { pending: "PENDING", enriched: "ENRICHED", posting: "POSTING", ok: "POSTED", error: "FAILED", warning: "WARN" }[s] || s.toUpperCase();
}
async function loadThumbs() {
  const imgs = $$("#queue .qthumb");
  let i = 0;
  async function next() {
    if (i >= imgs.length) return;
    const img = imgs[i++];
    const idx = idxOf(img.closest(".qrow"));
    try { const r = await call("thumb", idx); if (r.data) img.src = r.data; } catch (_) {}
    next();
  }
  for (let k = 0; k < 4; k++) next();   // 4 in parallel
}
function updateRow(index, patch) {
  const el = $(`#queue .qrow[data-index="${index}"]`);
  if (!el) return;
  if (patch.status) {
    const b = el.querySelector(".badge");
    b.className = "badge " + patch.status; b.textContent = badgeText(patch.status);
    el.classList.toggle("err", patch.status === "error");
  }
  if (patch.row) {   // full re-fill after enrich
    const r = patch.row;
    el.querySelector('[data-k="title"]').value = r.title;
    el.querySelector('[data-k="tags"]').value = r.tags;
    el.querySelector('[data-k="caption"]').value = r.caption;
    el.querySelector('[data-k="alt"]').value = r.alt;
    el.querySelector('[data-k="category"]').value = r.category;
    el.querySelector('[data-k="album"]').value = r.album;
    el.querySelector('[data-k="orientation"]').value = r.orientation;
  }
}

// ============================================================================
// GEMINI presets + test + ENRICH
// ============================================================================
function fillPresets(names) {
  $("#presetSel").innerHTML = '<option value="">—</option>' + names.map(n => `<option>${esc(n)}</option>`).join("");
}
function wireGemini() {
  $("#gemTestBtn").onclick = gemTest;
  $("#presetSel").onchange = async () => {
    const n = $("#presetSel").value;
    if (n) { const r = await call("preset_text", n); $("#gemPrompt").value = r.text; }
  };
  $("#presetSaveBtn").onclick = async () => {
    const cur = $("#gemPrompt").value.trim();
    if (!cur) return alertBox("Empty prompt", "Write a prompt before saving it as a preset.");
    const name = await promptBox("Save Preset", "Preset name:");
    if (!name) return;
    try { const r = await call("preset_save", name, cur); fillPresets(r.names); $("#presetSel").value = r.selected; }
    catch (e) { alertBox("Save failed", e.message); }
  };
  $("#presetDelBtn").onclick = async () => {
    const n = $("#presetSel").value;
    if (!n) return;
    if (!await confirmBox("Delete Preset", `Delete preset "${n}"?`, "Delete", true)) return;
    const r = await call("preset_delete", n);
    fillPresets(r.names); $("#presetSel").value = "";
    if (r.message) log(r.message, r.refused ? "warn" : "ok");
    if (r.refused) alertBox("Built-in preset", r.message);
  };
}
async function gemTest() {
  const btn = $("#gemTestBtn"), lbl = $("#gemTestLbl");
  btn.disabled = true; lbl.textContent = "Testing…";
  try {
    await call("gemini_test", $("#gemKey").value);
    const r = await pollOp("gemini_test");
    const res = r.result || {};
    lbl.textContent = res.message || (r.error || "");
    lbl.style.color = res.ok ? "var(--ok)" : "var(--err)";
    saveConfigSoon();
  } catch (e) { lbl.textContent = e.message; lbl.style.color = "var(--err)"; }
  finally { btn.disabled = false; }
}

async function enrich() {
  if (S.busy) return;
  const key = $("#gemKey").value.trim();
  if (!key) return alertBox("No Gemini key", "Enter a Gemini API key first.");
  S.busy = true; $("#enrichBtn").disabled = true; $("#enrichTopBtn").disabled = true;
  setPostStatus("Enriching…", "warn");
  try {
    await call("enrich_start", key, $("#gemPrompt").value);
    const r = await pollOp("enrich", (events) => {
      for (const ev of events) {
        if (ev.type !== "progress") continue;
        setProg("#postBar", "#postProgLbl", ev.current, ev.total);
        if (ev.ok) { if (ev.index != null) updateRow(ev.index, { status: "enriched", row: ev.row }); }
        else if (ev.index != null) { updateRow(ev.index, { status: "error" }); log("Enrich " + ev.message, "err"); }
      }
    });
    const warns = (r.result && r.result.warnings) || 0;
    $("#clearWarnBtn").disabled = warns === 0;
    S.queue.warnings = warns;
    setPostStatus(r.error ? ("Enrich stopped: " + r.error) : (warns ? `Enrich done — ${warns} warning(s). Fix or Clear AI Warning.` : "Enrich complete."), warns ? "warn" : "ok");
    saveConfigSoon();
  } catch (e) { await alertBox("Enrich failed", e.message); }
  finally { S.busy = false; $("#enrichBtn").disabled = false; $("#enrichTopBtn").disabled = false; }
}

// ============================================================================
// VALIDATE + POST
// ============================================================================
async function validate() {
  try {
    const r = await call("validate", $("#defCat").value, $("#defAlbum").value);
    if (r.ok) { setPostStatus(`✓ All ${r.count} entries look good.`, "ok"); await alertBox("Validation passed", `All ${r.count} entries validated OK.`); }
    else { setPostStatus(`${r.issues.length} issue(s) — see dialog.`, "warn");
      await alertBox("Validation issues", r.issues.slice(0, 20).join("\n") + (r.issues.length > 20 ? `\n…and ${r.issues.length - 20} more` : "")); }
  } catch (e) { await alertBox("Validate failed", e.message); }
}

async function postOrCancel() {
  if (S.busy) { await call("cancel_post"); setPostStatus("Cancelling…", "warn"); return; }
  return startPost();
}
async function startPost(ackNoDrive = false, ackUnknown = false) {
  try {
    const driveEnabled = $("#driveEnabled").checked;
    if (!ackNoDrive && !ackUnknown) {
      const pf = await call("post_preflight", S.asGrams, driveEnabled);
      if (pf.blocked_enrichment.length) {
        return alertBox("Posting blocked — enrichment failed",
          `${pf.blocked_enrichment.length} selected image(s) failed Gemini enrichment:\n\n` +
          pf.blocked_enrichment.slice(0, 8).join("\n") +
          "\n\nRun ENRICH again, or Clear AI Warning to post them unenriched.");
      }
      if (pf.mode_state === "known_mismatch") {
        return alertBox("Wrong mode — post blocked",
          `You're on the ${pf.tab_label} tab, but ${pf.dest} is a ${pf.site_label} site.\n\nPosting here would be rejected and can wreck the batch — nothing was sent. Switch tabs or connect to a matching site.`);
      }
      if (!await confirmBox("Confirm post",
          `Post ${pf.count} selected image${pf.count !== 1 ? "s" : ""}${pf.count !== pf.total ? " (of " + pf.total + " in queue)" : ""} to ${pf.dest}?\n\nThey appear in the order shown.`, "Post")) return;
    }
    const r = await call("post_start", S.asGrams, $("#defCat").value, $("#defAlbum").value,
      $("#defOrient").value, $("#defColor").value, $("#copyright").value, $("#driveFolder").value,
      ackNoDrive, ackUnknown, $("#driveEnabled").checked);
    if (r.needs_ack === "no_drive") {
      if (await confirmBox("Google Drive not connected",
          "Drive is enabled but not authenticated, so originals won't be uploaded and download links will be blank. Post anyway?", "Post without Drive", true))
        return startPost(true, ackUnknown);
      return;
    }
    if (r.needs_ack === "unknown_mode") {
      if (await confirmBox("Can't verify site mode",
          `${r.dest} didn't report SOLO vs GRAM. You're posting as ${r.tab_label}. Continue only if that matches the site.`, "Continue", true))
        return startPost(ackNoDrive, true);
      return;
    }
    // running
    S.busy = true; setPostBtnCancel(true); setPostStatus("Posting…", "warn");
    // mark selected rows posting
    $$("#queue .qrow").forEach(el => { if (el.querySelector(".rsel").checked) updateRow(idxOf(el), { status: "posting" }); });
    const done = await pollOp("post", (events) => {
      for (const ev of events) {
        if (ev.type !== "progress") continue;
        setProg("#postBar", "#postProgLbl", ev.current, ev.total);
        if (ev.index != null) updateRow(ev.index, { status: ev.status });
        setPostStatus(`${ev.success ? "✓" : "✗"} ${ev.file} — ${ev.message}`, ev.success ? "ok" : "err");
        if (!ev.success) log("POST FAIL " + ev.file + " — " + ev.message, "err");
      }
    });
    const res = done.result || {};
    if (res.cancelled) setPostStatus(`Cancelled — ${res.processed} posted before stop. The rest stay in the queue.`, "warn");
    else if (res.failed) setPostStatus(`Batch done — ${res.processed} processed, ${res.failed} FAILED (red rows).`, "err");
    else setPostStatus(`Batch complete — ${res.processed} processed.`, "ok");
  } catch (e) { await alertBox("Post failed", e.message); }
  finally { S.busy = false; setPostBtnCancel(false); }
}
function setPostBtnCancel(on) {
  const b = $("#postBtn");
  b.classList.toggle("cancel", on);
  b.textContent = on ? "CANCEL" : "POST BATCH";
}

async function clearQueue() {
  const q = await call("clear_queue");
  renderQueue(q);
  setPostStatus(q.failed ? `Cleared — ${q.failed} failed row(s) kept.` : "Queue cleared.", q.failed ? "err" : "");
  if ($("#cfg").classList.contains("hidden")) $("#cfgToggle").click();
}
async function clearWarning() {
  if (!await confirmBox("Clear AI Warning", "Explicitly allow the flagged images to upload without a completed Gemini enrichment?", "Clear")) return;
  const q = await call("clear_enrichment_warning");
  renderQueue(q);
  setPostStatus(`Cleared ${q.cleared} AI warning(s).`, "warn");
}
async function randomize() { const q = await call("shuffle"); renderQueue(q); }

function setProg(barSel, lblSel, cur, total) {
  const pct = total ? Math.round((cur / total) * 100) : 0;
  $(barSel).style.width = pct + "%";
  if (lblSel) $(lblSel).textContent = `${cur} / ${total}`;
}
function setPostStatus(t, kind) { const el = $("#postStatus"); el.textContent = t; el.style.color = ledColor(kind); }
function ledColor(kind) { return { ok: "var(--ok)", err: "var(--err)", warn: "var(--warn)" }[kind] || "var(--dim)"; }

// ============================================================================
// DRIVE
// ============================================================================
function wireDrive() {
  $("#driveEnabled").onchange = async () => {
    const r = await call("drive_toggle", $("#driveEnabled").checked);
    setLed("driveDot", "driveLbl", r.connected ? "ok" : "off",
      r.enabled ? (r.connected ? "AUTHENTICATED" : "NOT CONNECTED") : "DISABLED");
  };
  $("#authDriveBtn").onclick = async () => {
    const btn = $("#authDriveBtn"); btn.disabled = true;
    setLed("driveDot", "driveLbl", "warn", "OPENING BROWSER…");
    try {
      await call("auth_drive", $("#driveCreds").value);
      const r = await pollOp("drive_auth", null, 700);
      if (r.error) { setLed("driveDot", "driveLbl", "err", "AUTH FAILED"); await alertBox("Drive auth failed", r.error); }
      else { setLed("driveDot", "driveLbl", "ok", "AUTHENTICATED"); log("Google Drive connected.", "ok"); saveConfigSoon(); }
    } catch (e) { setLed("driveDot", "driveLbl", "err", "AUTH FAILED"); await alertBox("Drive auth failed", e.message); }
    finally { btn.disabled = false; }
  };
}

// ============================================================================
// AUDIT
// ============================================================================
function wireAudit() {
  $("#auditRefreshBtn").onclick = auditRefresh;
  $("#goRepairBtn").onclick = () => switchTab("repair");
}
async function auditRefresh() {
  const btn = $("#auditRefreshBtn"); btn.disabled = true;
  $("#auditSummary").textContent = "Pulling posts…";
  try {
    await call("audit_refresh");
    const done = await pollOp("audit", null, 700);
    if (done.error) { $("#auditSummary").textContent = "Audit failed: " + done.error; return; }
    const res = done.result;
    S._auditLoaded = true;
    const su = res.summary || {};
    $("#auditSummary").textContent =
      `${res.total} posts · ${res.duplicates.length} duplicate-title group(s) · ${res.missing_drive.length} missing Drive link(s).`;
    $("#auditDups").innerHTML = res.duplicates.length ? res.duplicates.map(d =>
      `<div class="audit-item"><b>${esc(d.title)}</b> <small>× ${d.posts.length}</small></div>`).join("") : '<p class="hint">None</p>';
    $("#auditMissing").innerHTML = res.missing_drive.length ? res.missing_drive.map(p =>
      `<div class="audit-item">#${esc(p.snap_id)} ${esc(p.img_title)} <small>${esc(p.img_date)}</small></div>`).join("") : '<p class="hint">None</p>';
  } catch (e) { $("#auditSummary").textContent = "Audit failed: " + e.message; }
  finally { btn.disabled = false; }
}

// ============================================================================
// REPAIR — rename / re-enrich / backfill
// ============================================================================
function wireRepair() {
  $("#renameBtn").onclick = () => runRepairOp("rename", "renameBtn", "renameStopBtn", "renameStatus", "renameBar", "renameLog", "rename_start");
  $("#renameStopBtn").onclick = () => call("rename_stop");
  $("#reenrichBtn").onclick = () => runRepairOp("reenrich", "reenrichBtn", "reenrichStopBtn", "reenrichStatus", "reenrichBar", "reenrichLog", "reenrich_start");
  $("#reenrichStopBtn").onclick = () => call("reenrich_stop");
  $("#backfillLoadBtn").onclick = backfillLoad;
}
async function runRepairOp(key, startId, stopId, statusId, barId, logId, startFn) {
  const start = document.getElementById(startId), stop = document.getElementById(stopId);
  start.disabled = true; stop.disabled = false;
  document.getElementById(statusId).textContent = "Working…";
  document.getElementById(logId).innerHTML = "";
  try {
    await call(startFn);
    const done = await pollOp(key, (events) => {
      for (const ev of events) {
        if (ev.type === "log") {
          const d = document.createElement("div"); d.className = ev.level; d.textContent = ev.message;
          document.getElementById(logId).prepend(d);
          if (ev.total) setProg("#" + barId, null, ev.current, ev.total);
        }
      }
    });
    const r = done.result || {};
    document.getElementById(statusId).textContent = done.error ? ("Error: " + done.error) :
      `Done — ${r.done || 0} ok, ${r.errors || 0} error(s) of ${r.total || 0}.`;
    if (key === "reenrich") S._auditLoaded = false;
  } catch (e) { document.getElementById(statusId).textContent = e.message; }
  finally { start.disabled = false; stop.disabled = true; }
}
async function backfillLoad() {
  try {
    const r = await call("backfill_list", $("#driveFolder").value);
    const box = $("#backfillRows"); box.innerHTML = "";
    if (!r.rows.length) { $("#backfillStatus").textContent = "No missing-link posts (run an Audit refresh first)."; return; }
    $("#backfillStatus").textContent = `${r.rows.length} post(s) missing a Drive link.` + (r.drive_ready ? " Auto-searching…" : " Type each link.");
    r.rows.forEach((p, i) => {
      const el = document.createElement("div"); el.className = "bf-row";
      el.innerHTML = `<div class="bf-title">#${esc(p.snap_id)} ${esc(p.img_title)}<br><small>${esc(p.img_date)}</small></div>
        <input type="text" placeholder="Drive download URL" class="bf-url">
        <button class="ghost bf-save">Save</button>`;
      const urlIn = el.querySelector(".bf-url"), saveBtn = el.querySelector(".bf-save");
      saveBtn.onclick = async () => {
        try { await call("backfill_save", p.snap_id, urlIn.value); saveBtn.textContent = "✓"; saveBtn.disabled = true; el.style.opacity = ".5"; }
        catch (e) { alertBox("Backfill failed", e.message); }
      };
      box.appendChild(el);
      if (r.drive_ready) setTimeout(async () => {
        try {
          const res = await call("backfill_auto", p.snap_id, p.img_title, $("#driveFolder").value);
          if (res.ok) { urlIn.value = res.url; saveBtn.textContent = "✓ auto"; saveBtn.disabled = true; el.style.opacity = ".5"; }
        } catch (_) {}
      }, i * 300);
    });
  } catch (e) { $("#backfillStatus").textContent = e.message; }
}

// ============================================================================
// ADV. MATCH
// ============================================================================
function wireMatch() {
  $("#browseMatchSrv").onclick = () => browseInto("browse_folder", "#matchSrv");
  $("#browseMatchOrig").onclick = () => browseInto("browse_folder", "#matchOrig");
  $("#matchRunBtn").onclick = matchRun;
  $("#matchStopBtn").onclick = () => call("match_stop");
}
async function matchRun() {
  $("#matchRunBtn").disabled = true; $("#matchStopBtn").disabled = false;
  $("#matchRows").innerHTML = ""; $("#matchStatus").textContent = "Matching…";
  try {
    await call("match_start", $("#matchSrv").value, $("#matchOrig").value);
    const done = await pollOp("match", (events) => {
      for (const ev of events) {
        if (ev.type === "row") { addMatchRow(ev); setProg("#matchBar", null, ev.current, ev.total); $("#matchStatus").textContent = `Matched ${ev.current} / ${ev.total}`; }
      }
    }, 500);
    if (done.error) $("#matchStatus").textContent = "Match failed: " + done.error;
    else $("#matchStatus").textContent = `Done — ${(done.result||{}).rows||0} to review.`;
  } catch (e) { await alertBox("Match failed", e.message); }
  finally { $("#matchRunBtn").disabled = false; $("#matchStopBtn").disabled = true; }
}
function addMatchRow(ev) {
  const el = document.createElement("div"); el.className = "match-row"; el.dataset.rid = ev.row_id;
  const pct = ev.confidence > 0 ? Math.round(ev.confidence * 100) + "%" : "—";
  el.innerHTML = `
    <div><img class="match-img srv" alt=""><div class="hint">#${esc(ev.record.snap_id)} ${esc(ev.record.img_title)}</div></div>
    <div class="match-mid"><div class="conf ${ev.label}">${pct}</div><div class="hint">confidence</div>
      <div class="hint">${ev.match_count || 0} keypoints</div><div class="hint">${labelText(ev.label)}</div></div>
    <div><img class="match-img orig" alt=""><div class="hint match-name">${esc(baseName(ev.match_path)) || "—"}</div>
      <div class="match-btns">
        <button class="accent up">Upload</button>
        <button class="ghost pick">Pick Different</button>
        <button class="ghost skip">Skip</button>
      </div></div>`;
  $("#matchRows").appendChild(el);
  // previews
  call("match_preview", ev.row_id, "server").then(r => { if (r.data) el.querySelector(".srv").src = r.data; });
  if (ev.match_path) call("match_preview", ev.row_id, "original").then(r => { if (r.data) el.querySelector(".orig").src = r.data; });
  el.querySelector(".up").onclick = () => matchUpload(ev.row_id, el);
  el.querySelector(".skip").onclick = async () => { await call("match_skip", ev.row_id); el.remove(); };
  el.querySelector(".pick").onclick = async () => {
    const r = await call("browse_image", $("#matchOrig").value || "");
    if (r.path) { const pr = await call("match_pick", ev.row_id, r.path);
      el.querySelector(".match-name").textContent = baseName(pr.match_path);
      const pv = await call("match_preview", ev.row_id, "original"); if (pv.data) el.querySelector(".orig").src = pv.data; }
  };
}
async function matchUpload(rid, el) {
  const btn = el.querySelector(".up"); btn.disabled = true; btn.textContent = "Uploading…";
  try {
    await call("match_upload", rid, $("#driveFolder").value);
    const done = await pollOp("match_upload", null, 700);
    if (done.error) { btn.disabled = false; btn.textContent = "Retry"; await alertBox("Upload failed", done.error); }
    else { el.classList.add("done"); btn.textContent = "✓ Done"; log("Match uploaded #" + rid, "ok"); setTimeout(() => el.remove(), 1500); }
  } catch (e) { btn.disabled = false; btn.textContent = "Retry"; await alertBox("Upload failed", e.message); }
}
function labelText(l) { return { high: "Auto-matched", medium: "Review suggested", low: "Weak match", none: "No match found" }[l] || ""; }
function baseName(p) { return p ? p.split(/[\\/]/).pop() : ""; }

// ============================================================================
// SETTINGS — profiles
// ============================================================================
function fillProfiles(names) {
  $("#profileList").innerHTML = names.map(n => `<option value="${esc(n)}">${esc(n)}</option>`).join("");
  $("#postProfile").innerHTML = '<option value="">—</option>' + names.map(n => `<option>${esc(n)}</option>`).join("");
}
function wireSettings() {
  $("#profileList").onchange = () => loadProfileForm($("#profileList").value);
  $("#profNewBtn").onclick = async () => { const r = await call("profile_new"); fillProfiles(r.profiles); $("#profileList").value = r.name; loadProfileForm(r.name); };
  $("#profDupBtn").onclick = async () => { const n = $("#profileList").value; if (!n) return; const r = await call("profile_duplicate", n); fillProfiles(r.profiles); $("#profileList").value = r.name; loadProfileForm(r.name); };
  $("#profDelBtn").onclick = async () => {
    const n = $("#profileList").value; if (!n) return;
    if (!await confirmBox("Delete profile", `Delete "${n}"?`, "Delete", true)) return;
    const r = await call("profile_delete", n); fillProfiles(r.profiles); $("#spStatus").textContent = "Deleted.";
  };
  $("#profSaveBtn").onclick = () => saveProfile(false);
  $("#profLoadBtn").onclick = loadProfileToPost;
  $("#spTestBtn").onclick = spTest;
  $("#spGemTestBtn").onclick = spGemTest;
  $("#browseSpCreds").onclick = async () => { const r = await call("browse_creds", $("#spCreds").value || ""); if (r.path) $("#spCreds").value = r.path; };
}
let spCurrent = "";
async function loadProfileForm(name) {
  if (!name) return;
  const p = await call("profile_get", name);
  spCurrent = name;
  $("#spName").value = p.name; $("#spUrl").value = p.url; $("#spApiKey").value = p.api_key;
  $("#spCreds").value = p.google_credentials; $("#spFolder").value = p.drive_folder_id;
  $("#spGemini").value = p.gemini_api_key; $("#spCopyright").value = p.copyright_text;
  $("#spCat").value = p.default_category; $("#spAlbum").value = p.default_album;
  $("#spOrient").value = p.default_orientation || "auto";
  $("#spStatus").textContent = "";
}
function collectProfile() {
  return { name: $("#spName").value, url: $("#spUrl").value, api_key: $("#spApiKey").value,
    google_credentials: $("#spCreds").value, drive_folder_id: $("#spFolder").value,
    gemini_api_key: $("#spGemini").value, copyright_text: $("#spCopyright").value,
    default_category: $("#spCat").value, default_album: $("#spAlbum").value,
    default_orientation: $("#spOrient").value };
}
async function saveProfile(overwrite) {
  try {
    const r = await call("profile_save", collectProfile(), spCurrent, overwrite);
    if (r.needs_overwrite) {
      if (await confirmBox("Overwrite?", `A profile named "${r.name}" already exists. Overwrite it?`, "Overwrite", true)) return saveProfile(true);
      return;
    }
    fillProfiles(r.profiles); $("#profileList").value = r.name; spCurrent = r.name;
    $("#spStatus").textContent = "✓ Saved"; $("#spStatus").style.color = "var(--ok)";
  } catch (e) { await alertBox("Save failed", e.message); }
}
async function loadProfileToPost() {
  const n = $("#profileList").value; if (!n) return;
  const p = await call("profile_apply_to_post", n);
  applyProfileFields(p);
  await call("drive_toggle", p.drive_enabled);
  $("#driveEnabled").checked = p.drive_enabled;
  saveConfigSoon();
  switchTab("solo");
  connect();
}
async function applyPostProfile() {
  const n = $("#postProfile").value; if (!n) return;
  const p = await call("profile_apply_to_post", n);
  applyProfileFields(p);
  $("#driveEnabled").checked = p.drive_enabled;
  saveConfigSoon();
}
function applyProfileFields(p) {
  $("#url").value = p.url; $("#apiKey").value = p.api_key;
  $("#driveCreds").value = p.google_credentials; $("#driveFolder").value = p.drive_folder_id;
  $("#gemKey").value = p.gemini_api_key; $("#copyright").value = p.copyright_text;
  $("#defCat").value = p.default_category; $("#defAlbum").value = p.default_album;
  $("#defOrient").value = orientLabel[p.default_orientation] || "Auto";
  updateAiDot();
}
async function spTest(ack = false) {
  const lbl = $("#spTestLbl"); lbl.textContent = "Testing…"; lbl.style.color = "var(--dim)";
  try {
    const r = await call("sp_test", $("#spUrl").value, $("#spApiKey").value, ack);
    if (r.needs_insecure_ack) { if (await confirmBox("Unencrypted connection", r.reason + "\n\nTest anyway?", "Test", true)) return spTest(true); lbl.textContent = ""; return; }
    const done = await pollOp("sp_test");
    const res = done.result || {};
    lbl.textContent = res.ok ? res.message : (done.error || "Failed");
    lbl.style.color = res.ok ? "var(--ok)" : "var(--err)";
  } catch (e) { lbl.textContent = e.message; lbl.style.color = "var(--err)"; }
}
async function spGemTest() {
  const lbl = $("#spGemTestLbl"); lbl.textContent = "Testing…";
  try {
    await call("sp_gemini_test", $("#spGemini").value);
    const done = await pollOp("sp_gem_test");
    const res = done.result || {};
    lbl.textContent = res.message || (done.error || "");
    lbl.style.color = res.ok ? "var(--ok)" : "var(--err)";
  } catch (e) { lbl.textContent = e.message; lbl.style.color = "var(--err)"; }
}

// ── help ─────────────────────────────────────────────────────────────────────
function showHelp() {
  alertBox("SMACK YOUR BATCH UP — help",
    "1. Connect: enter your site URL + API key (Admin → Settings → API Access), Connect.\n" +
    "2. Pick an image folder, then Scan Folder (or Load Manifest for an advanced .txt).\n" +
    "3. Enrich with Gemini fills title/caption/tags/category/album/colours/ALT; edit any field inline.\n" +
    "4. SMACKONEOUT tab posts SOLO (photoblog); GRAMOFSMACK tab posts single grams.\n" +
    "5. POST BATCH posts every ticked row; failed rows stay red to retry.\n\n" +
    "Google Drive (optional) hosts the full-size originals and fills the download link.\n" +
    "AUDIT lists posts; BASIC REPAIR renames Drive files, re-enriches duplicate titles, and backfills links.\n" +
    "ADV. MATCH pairs server copies to local originals by image content, then uploads + backfills.\n" +
    "SETTINGS holds per-site profiles shared across the SnapSmack tools.");
}

document.addEventListener("DOMContentLoaded", boot);
/* ===== SNAPSMACK EOF ===== */
