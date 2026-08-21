/* FLKR FCKR — window logic for the Chrome/Blink port.
   Every user action reaches the original Python through blink.call(). No work is
   done here beyond drawing the window and shuttling values. */

const $ = (s) => document.querySelector(s);
const $$ = (s) => Array.prototype.slice.call(document.querySelectorAll(s));

/* ── shared state (mirrors what the tkinter window held) ───────────────────── */
const STATE = {
  photos: [],          // [{flickr_id,title,date,album_ids,excluded,...}]
  albums: [],          // [{flickr_id,title,count}]
  filter: "all",       // 'all' | 'unalbumed' | 'album'
  albumId: "",         // active album flickr_id when filter==='album'
  running: false,
  paused: false,
  loaded: false,
};
const RENDER_CAP = 600;          // same tile cap as the tkinter grid

/* ── logging into the on-page log pane ─────────────────────────────────────── */
function log(msg, kind) {
  const el = document.createElement("div");
  el.className = "line " + (kind || "pri");
  el.textContent = msg;
  const box = $("#log");
  box.appendChild(el);
  box.scrollTop = box.scrollHeight;
  const pop = document.getElementById("log-popup-body");
  if (pop) {
    const c = el.cloneNode(true);
    pop.appendChild(c);
    pop.scrollTop = pop.scrollHeight;
  }
}
function setConn(text, kind) {
  const el = $("#conn-status");
  el.textContent = text || "";
  el.className = "conn-status " + (kind || "");
}

/* ── boot ──────────────────────────────────────────────────────────────────── */
async function boot() {
  try {
    const state = await blink.call("load_state");
    $("#version").textContent = "v" + (state.version || "");
    fillSelectors();
    applySettings(state.settings);
    reflectVault(state.vault);
    wireEvents();
    startPolling();
    if (state.resume) offerResume(state.resume);
  } catch (e) {
    log("Could not start: " + e.message, "err");
  }
}

function fillSelectors() {
  const hours = $("#peak-start");
  const hours2 = $("#peak-end");
  for (let h = 0; h < 24; h++) {
    hours.appendChild(new Option(String(h), String(h)));
    hours2.appendChild(new Option(String(h), String(h)));
  }
}

function applySettings(s) {
  if (!s) return;
  $("#site-url").value = s.site_url || "";
  $("#api-key").value = s.api_key || "";
  $("#export-folder").value = s.export_folder || "";
  const throttle = $("#throttle");
  throttle.innerHTML = "";
  (s.throttle_options || []).forEach((label) => throttle.appendChild(new Option(label, label)));
  throttle.value = s.throttle_label || "";
  $("#private-status").value = s.private_status || "draft";
  $("#unalbumed-action").value = s.unalbumed_action || "feed";
  $("#default-album").value = s.default_album || "";
  $("#offpeak").checked = !!s.offpeak_only;
  $("#peak-start").value = String(s.peak_start != null ? s.peak_start : 9);
  $("#peak-end").value = String(s.peak_end != null ? s.peak_end : 23);
  STATE.authUsername = s.auth_username || "";   // prefills the step-up dialog
  toggleDefaultAlbum();
}

function collectSettings() {
  return {
    site_url: $("#site-url").value,
    api_key: $("#api-key").value,
    export_folder: $("#export-folder").value,
    throttle: $("#throttle").value,
    private_status: $("#private-status").value,
    unalbumed_action: $("#unalbumed-action").value,
    default_album: $("#default-album").value,
    offpeak_only: $("#offpeak").checked,
    peak_start: parseInt($("#peak-start").value, 10),
    peak_end: parseInt($("#peak-end").value, 10),
  };
}
async function saveSettings() {
  try { await blink.call("save_settings", collectSettings()); }
  catch (e) { log("Could not save settings: " + e.message, "err"); }
}

function toggleDefaultAlbum() {
  const on = $("#unalbumed-action").value === "default_album";
  $("#default-album-wrap").classList.toggle("hidden", !on);
}

/* ── event wiring ──────────────────────────────────────────────────────────── */
function wireEvents() {
  $("#btn-connect").addEventListener("click", doConnect);
  $("#btn-browse").addEventListener("click", doBrowse);
  $("#btn-load").addEventListener("click", doLoadExport);
  $("#btn-run").addEventListener("click", doToggleRun);
  $("#btn-key").addEventListener("click", openKeyModal);
  $("#btn-logs").addEventListener("click", () => blink.call("open_logs"));
  $("#btn-help").addEventListener("click", openHelpModal);
  $("#btn-log-expand").addEventListener("click", openLogPopup);
  $("#unalbumed-action").addEventListener("change", () => { toggleDefaultAlbum(); saveSettings(); });
  ["#private-status", "#throttle", "#offpeak", "#peak-start", "#peak-end", "#default-album"]
    .forEach((sel) => $(sel).addEventListener("change", saveSettings));
  $$('input[name="filter"]').forEach((r) =>
    r.addEventListener("change", () => { STATE.filter = radioFilter(); STATE.albumId = ""; renderGrid(); refreshSummary(); syncAlbumSelection(); }));
}
function radioFilter() {
  const r = $$('input[name="filter"]').find((x) => x.checked);
  return r ? r.value : "all";
}

/* ── connection ────────────────────────────────────────────────────────────── */
async function doConnect() {
  const url = $("#site-url").value.trim();
  const key = $("#api-key").value.trim();
  if (!url || !key) { setConn("URL and key required", "err"); return; }
  setConn("Testing…", "dim");
  await saveSettings();
  try {
    const r = await blink.call("test_connection", url, key);
    setConn(r.message, r.ok ? "ok" : "err");
    if (r.insecure && r.ok) log("Warning: " + (r.insecure_reason || "connection is not https://"), "warn");
  } catch (e) { setConn(e.message, "err"); }
}

async function doBrowse() {
  try {
    const r = await blink.call("pick_folder");
    if (r && r.path) { $("#export-folder").value = r.path; saveSettings(); }
    else log("No folder picker (zenity/kdialog) available — type or paste the export folder path.", "dim");
  } catch (e) { log("Folder picker failed: " + e.message, "dim"); }
}

/* ── load export ───────────────────────────────────────────────────────────── */
async function doLoadExport() {
  const folder = $("#export-folder").value.trim();
  if (!folder) { log("Please enter a valid export folder first.", "err"); return; }
  await saveSettings();
  setProgress(0);
  $("#grid-count").textContent = "Parsing…";
  try {
    const r = await blink.call("load_export", folder);
    if (!r.ok) log(r.message, "err");
  } catch (e) { log("Load failed: " + e.message, "err"); }
}

function onParseDone(ev) {
  STATE.photos = ev.photos || [];
  STATE.albums = ev.albums || [];
  STATE.loaded = true;
  STATE.filter = "all";
  STATE.albumId = "";
  $$('input[name="filter"]').forEach((r) => (r.checked = r.value === "all"));
  renderAlbums();
  renderGrid();
  refreshSummary();
  $("#btn-run").disabled = false;
  setProgress(0);
}

/* ── album sidebar ─────────────────────────────────────────────────────────── */
function renderAlbums() {
  const ul = $("#album-list");
  ul.innerHTML = "";
  const all = document.createElement("li");
  all.textContent = `All (${STATE.photos.length})`;
  all.className = "album-item active";
  all.dataset.album = "__all__";
  all.addEventListener("click", () => selectAlbum("__all__"));
  ul.appendChild(all);
  STATE.albums.forEach((a) => {
    const li = document.createElement("li");
    li.className = "album-item";
    li.textContent = `${a.title.slice(0, 28)} (${a.count})`;
    li.dataset.album = a.flickr_id;
    li.title = a.title;
    li.addEventListener("click", () => selectAlbum(a.flickr_id));
    ul.appendChild(li);
  });
}
function selectAlbum(id) {
  if (id === "__all__") { STATE.filter = "all"; STATE.albumId = ""; $$('input[name="filter"]').forEach((r) => (r.checked = r.value === "all")); }
  else { STATE.filter = "album"; STATE.albumId = id; $$('input[name="filter"]').forEach((r) => (r.checked = false)); }
  syncAlbumSelection();
  renderGrid();
  refreshSummary();
}
function syncAlbumSelection() {
  const active = STATE.filter === "album" ? STATE.albumId : "__all__";
  $$("#album-list .album-item").forEach((li) => li.classList.toggle("active", li.dataset.album === active));
}

/* ── photo grid + lazy thumbnails ──────────────────────────────────────────── */
let THUMB_OBSERVER = null;
function currentPhotos() {
  if (STATE.filter === "unalbumed") return STATE.photos.filter((p) => !p.album_ids.length);
  if (STATE.filter === "album") return STATE.photos.filter((p) => p.album_ids.indexOf(STATE.albumId) >= 0);
  return STATE.photos;
}
function renderGrid() {
  const grid = $("#grid");
  grid.innerHTML = "";
  if (THUMB_OBSERVER) THUMB_OBSERVER.disconnect();
  THUMB_OBSERVER = new IntersectionObserver(onThumbVisible, { root: grid, rootMargin: "200px" });

  const photos = currentPhotos();
  const total = photos.length;
  const excluded = photos.filter((p) => p.excluded).length;
  const renderN = Math.min(total, RENDER_CAP);
  if (total > renderN) {
    $("#grid-count").textContent =
      `${total} photos — showing first ${renderN}; filter by album to view/exclude the rest` +
      (excluded ? `  (${excluded} excluded)` : "");
  } else {
    $("#grid-count").textContent = `${total} photos` + (excluded ? `  (${excluded} excluded)` : "");
  }

  for (let i = 0; i < renderN; i++) grid.appendChild(makeCell(photos[i]));
}
function makeCell(p) {
  const cell = document.createElement("div");
  cell.className = "cell" + (p.excluded ? " excluded" : "");
  cell.dataset.id = p.flickr_id;

  const thumb = document.createElement("div");
  thumb.className = "thumb";
  thumb.dataset.id = p.flickr_id;
  if (p.has_image) { THUMB_OBSERVER.observe(thumb); }
  cell.appendChild(thumb);

  const title = document.createElement("div");
  title.className = "cell-title";
  title.textContent = p.title || "";
  cell.appendChild(title);

  const date = document.createElement("div");
  date.className = "cell-date muted";
  date.textContent = p.date || "?";
  cell.appendChild(date);

  let badge = p.badge;
  if (badge === "PRIVATE") badge = "PRIVATE → " + $("#private-status").value.toUpperCase();
  else if (!badge && p.excluded) badge = "EXCLUDED";
  if (badge) {
    const b = document.createElement("div");
    b.className = "cell-badge " + (p.badge_kind || "dim");
    b.textContent = badge;
    cell.appendChild(b);
  }

  if (!p.missing_image) cell.addEventListener("click", () => toggleExclude(p, cell));
  return cell;
}
async function onThumbVisible(entries) {
  for (const entry of entries) {
    if (!entry.isIntersecting) continue;
    const el = entry.target;
    THUMB_OBSERVER.unobserve(el);
    const id = el.dataset.id;
    try {
      const r = await blink.call("thumbnail", id);
      if (r && r.data) { el.style.backgroundImage = `url("${r.data}")`; el.classList.add("loaded"); }
    } catch (e) { /* leave placeholder */ }
  }
}
async function toggleExclude(p, cell) {
  try {
    const r = await blink.call("toggle_exclude", p.flickr_id);
    if (!r.ok) return;
    p.excluded = r.excluded;
    cell.classList.toggle("excluded", p.excluded);
    refreshSummary();
    // Keep the "(N excluded)" count line current without a full re-render.
    const photos = currentPhotos();
    const total = photos.length, excluded = photos.filter((x) => x.excluded).length;
    const renderN = Math.min(total, RENDER_CAP);
    $("#grid-count").textContent = (total > renderN
      ? `${total} photos — showing first ${renderN}; filter by album to view/exclude the rest`
      : `${total} photos`) + (excluded ? `  (${excluded} excluded)` : "");
  } catch (e) { log("Could not toggle: " + e.message, "err"); }
}

async function refreshSummary() {
  if (!STATE.loaded) return;
  try {
    const r = await blink.call("summary", STATE.filter, STATE.albumId);
    $("#summary").textContent =
      `${r.selected} of ${r.total} photos selected for import` +
      (r.missing ? `  (${r.missing} missing image files)` : "");
  } catch (e) { /* ignore */ }
}

/* ── run / pause / resume ──────────────────────────────────────────────────── */
function setProgress(pct) { $("#progress-bar").style.width = Math.max(0, Math.min(100, pct)) + "%"; }

async function doToggleRun() {
  if (!STATE.running) return startImportFlow();
  if (STATE.paused) return doResume();
  return doPause();
}
async function startImportFlow() {
  const url = $("#site-url").value.trim();
  const key = $("#api-key").value.trim();
  if (!url || !key) { log("Site URL and API key are required.", "err"); return; }
  await saveSettings();
  let pre;
  try { pre = await blink.call("preflight_import", url, key); }
  catch (e) { log("Preflight failed: " + e.message, "err"); return; }

  if (pre.action === "error" || pre.action === "regenerate_key") { log(pre.message, "err"); return; }
  if (pre.action === "stepup") {
    const ok = await stepUpFlow(url, key);
    if (!ok) return;   // cancelled or failed — message already logged
  }
  await beginImport();
}
async function beginImport() {
  try {
    const r = await blink.call("start_import", STATE.filter, STATE.albumId);
    if (!r.ok) { log(r.message, "warn"); return; }
    setRunning(true, false);
    setProgress(0);
  } catch (e) { log("Could not start import: " + e.message, "err"); }
}
async function doPause() {
  try { await blink.call("pause_import"); setRunning(true, true); }
  catch (e) { log(e.message, "err"); }
}
async function doResume() {
  try { await blink.call("resume_import"); setRunning(true, false); }
  catch (e) { log(e.message, "err"); }
}
function setRunning(running, paused) {
  STATE.running = running; STATE.paused = paused;
  const btn = $("#btn-run");
  if (!running) { btn.textContent = "Start Import"; btn.className = "accent"; }
  else if (paused) { btn.textContent = "Resume"; btn.className = "accent"; }
  else { btn.textContent = "Pause"; btn.className = "warn"; }
}

/* Step-up authorize modal: collects username / password / TOTP and authorizes.
   Password and code are used once and never stored (only username is). */
function stepUpFlow(url, key) {
  return new Promise((resolve) => {
    openModal(`
      <h2>Authorize Import</h2>
      <p class="muted">Your key keeps you connected, but writing requires a fresh
      password + 2FA check. Nothing but the username is saved.</p>
      <label>Username<input type="text" id="su-user" autocomplete="off"></label>
      <label>Password<input type="password" id="su-pass" autocomplete="off"></label>
      <label>Authenticator code (6 digits)<input type="text" id="su-totp" autocomplete="off"></label>
      <div class="modal-err" id="su-err"></div>
      <div class="modal-btns">
        <button class="ghost" id="su-cancel">Cancel</button>
        <button class="accent" id="su-go">Authorize</button>
      </div>`);
    $("#su-user").value = STATE.authUsername || "";
    ($("#su-user").value ? $("#su-pass") : $("#su-user")).focus();
    $("#su-cancel").addEventListener("click", () => { closeModal(); resolve(false); });
    $("#su-go").addEventListener("click", async () => {
      const u = $("#su-user").value.trim(), p = $("#su-pass").value, t = $("#su-totp").value.trim();
      if (!u || !p || !t) { $("#su-err").textContent = "Username, password and code are all required."; return; }
      $("#su-err").textContent = "Authorizing…";
      try {
        const r = await blink.call("authorize_import", url, key, u, p, t);
        if (r.ok) { STATE.authUsername = r.username || u; closeModal(); resolve(true); }
        else { $("#su-err").textContent = r.message || "Authorization failed."; }
      } catch (e) { $("#su-err").textContent = e.message; }
    });
  });
}

/* ── resume-from-checkpoint prompt ─────────────────────────────────────────── */
function offerResume(info) {
  openModal(`
    <h2>Resume interrupted import?</h2>
    <p>A previous import was interrupted. <b>${info.imported}</b> photos were already imported.</p>
    <p class="muted">${info.export_folder || ""}</p>
    <div class="modal-btns">
      <button class="ghost" id="rs-no">Start fresh</button>
      <button class="accent" id="rs-yes">Resume</button>
    </div>`);
  $("#rs-no").addEventListener("click", async () => { await blink.call("resume_decline"); closeModal(); });
  $("#rs-yes").addEventListener("click", async () => {
    const r = await blink.call("resume_accept");
    if (r && r.export_folder) $("#export-folder").value = r.export_folder;
    closeModal();
  });
}

/* ── event polling loop (replaces tkinter after()/queue) ───────────────────── */
function startPolling() { setInterval(drainEvents, 250); }
async function drainEvents() {
  let events;
  try { events = await blink.call("poll_events"); }
  catch (e) { return; }
  for (const ev of events || []) handleEvent(ev);
}
function handleEvent(ev) {
  switch (ev.type) {
    case "log": log(ev.text, ev.level); break;
    case "progress": setProgress(ev.pct); log(ev.text, ev.level); break;
    case "parse_progress":
      if (ev.total) { setProgress((ev.done / ev.total) * 100); $("#grid-count").textContent = `Parsing… ${ev.done} / ${ev.total}`; }
      break;
    case "parse_done": onParseDone(ev); break;
    case "parse_failed": $("#grid-count").textContent = "Parse failed"; break;
    case "started": setRunning(true, false); break;
    case "auth_expired": setRunning(false, false); break;
    case "done": onImportDone(); break;
    default: break;
  }
}
function onImportDone() {
  setRunning(false, false);
  setProgress(100);
}

/* ── modals ────────────────────────────────────────────────────────────────── */
function openModal(html) {
  const root = $("#modal-root");
  root.innerHTML = `<div class="modal-card">${html}</div>`;
  root.classList.remove("hidden");
}
function closeModal() { const root = $("#modal-root"); root.classList.add("hidden"); root.innerHTML = ""; }

/* Key security — encryption on/off/re-key, mirrors the tkinter "Key" window. */
async function openKeyModal() {
  let v;
  try { v = await blink.call("vault_status"); } catch (e) { log(e.message, "err"); return; }
  if (!v.available) {
    openModal(`
      <h2>Key security</h2>
      <p>Encryption is not available in this build — the "cryptography" package is
      missing. Your API key is stored base64-encoded, which is NOT encryption:
      anyone who can read flkrfckr.ini can recover it. Treat that file as a password.</p>
      <div class="modal-btns"><button class="accent" id="k-close">Close</button></div>`);
    $("#k-close").addEventListener("click", closeModal);
    return;
  }
  const on = v.enabled;
  const detail = on
    ? "Your API key is sealed with a passphrase-derived key. The passphrase itself is never saved, so a copy of this folder is not enough to read the key." +
      (v.has_machine_key ? " The passphrase is also stored on this machine, so you are not asked for it each launch." : " You will be asked for the passphrase each launch.")
    : "Your API key is stored base64-encoded — an encoding, not encryption. Anyone who can read flkrfckr.ini can recover the key. Turning encryption on fixes that.";
  openModal(`
    <h2>Key security</h2>
    <p class="${on ? "ok" : "warn"}"><b>Encryption: ${on ? "ON" : "OFF"}</b></p>
    <p class="muted">${detail}</p>
    <div class="modal-err" id="k-err"></div>
    <div class="modal-btns">
      <button class="ghost" id="k-close">Close</button>
      ${on ? '<button class="ghost" id="k-rekey">Change passphrase</button><button class="warn" id="k-off">Turn off</button>'
           : '<button class="accent" id="k-on">Turn encryption on</button>'}
    </div>`);
  $("#k-close").addEventListener("click", closeModal);
  if (on) {
    $("#k-off").addEventListener("click", async () => {
      try { await blink.call("vault_disable"); closeModal(); openKeyModal(); }
      catch (e) { $("#k-err").textContent = e.message; }
    });
    $("#k-rekey").addEventListener("click", () => rekeyModal());
  } else {
    $("#k-on").addEventListener("click", () => enableModal());
  }
}
function enableModal() {
  openModal(`
    <h2>Turn encryption on</h2>
    <p class="muted">Choose a passphrase. It is never saved anywhere — lose it and you
    just paste your API key in again (harmless).</p>
    <label>Passphrase<input type="password" id="e-p1" autocomplete="off"></label>
    <label>Type it again<input type="password" id="e-p2" autocomplete="off"></label>
    <label class="check"><input type="checkbox" id="e-remember"> Remember on this machine</label>
    <div class="modal-err" id="e-err"></div>
    <div class="modal-btns">
      <button class="ghost" id="e-cancel">Cancel</button>
      <button class="accent" id="e-go">Turn on</button>
    </div>`);
  $("#e-cancel").addEventListener("click", () => { closeModal(); openKeyModal(); });
  $("#e-go").addEventListener("click", async () => {
    const p1 = $("#e-p1").value, p2 = $("#e-p2").value;
    if (!p1) { $("#e-err").textContent = "Passphrase must not be empty."; return; }
    if (p1 !== p2) { $("#e-err").textContent = "The two entries do not match."; return; }
    try { await blink.call("vault_enable", p1, $("#e-remember").checked); closeModal(); openKeyModal(); }
    catch (e) { $("#e-err").textContent = e.message; }
  });
}
function rekeyModal() {
  openModal(`
    <h2>Change passphrase</h2>
    <label>Current passphrase<input type="password" id="r-old" autocomplete="off"></label>
    <label>New passphrase<input type="password" id="r-n1" autocomplete="off"></label>
    <label>Type it again<input type="password" id="r-n2" autocomplete="off"></label>
    <div class="modal-err" id="r-err"></div>
    <div class="modal-btns">
      <button class="ghost" id="r-cancel">Cancel</button>
      <button class="accent" id="r-go">Change</button>
    </div>`);
  $("#r-cancel").addEventListener("click", () => { closeModal(); openKeyModal(); });
  $("#r-go").addEventListener("click", async () => {
    const old = $("#r-old").value, n1 = $("#r-n1").value, n2 = $("#r-n2").value;
    if (!n1 || n1 !== n2) { $("#r-err").textContent = "New passphrase empty or entries do not match."; return; }
    try {
      const r = await blink.call("vault_rekey", old, n1);
      if (r.ok) { closeModal(); openKeyModal(); }
      else { $("#r-err").textContent = "That passphrase did not work."; }
    } catch (e) { $("#r-err").textContent = e.message; }
  });
}

/* Log pop-out — a bigger scrollable copy of the log. */
function openLogPopup() {
  openModal(`
    <h2>Import Log</h2>
    <div id="log-popup-body" class="log log-popup"></div>
    <div class="modal-btns"><button class="accent" id="lp-close">Close</button></div>`);
  const body = $("#log-popup-body");
  body.innerHTML = $("#log").innerHTML;
  body.scrollTop = body.scrollHeight;
  $("#lp-close").addEventListener("click", () => { closeModal(); });
}

/* Help — the same guidance the tkinter Help window showed. */
function openHelpModal() {
  openModal(`
    <h2>FLKR FCKR — Flickr → SnapSmack Migration Tool</h2>
    <div class="help-body">
      <p>Migrates your Flickr photo archive to a self-hosted SnapSmack photoblog.
      Runs on your computer, not on your server, and talks to your site at a
      throttled rate you control.</p>
      <h3>Quick Start</h3>
      <ol>
        <li>Download and unzip your Flickr data export.</li>
        <li>In SnapSmack admin → Boring Ass Stuff → API Keys, generate a "FLKR FCKR Import" key. Copy it (shown once).</li>
        <li>Enter your site URL and API key above, click Connect.</li>
        <li>Browse to the unzipped export folder, click Load Export.</li>
        <li>Review the grid. Click any tile to exclude it; use the album sidebar to filter.</li>
        <li>Click Start Import. Pause/resume any time. If interrupted, it offers to resume next launch.</li>
      </ol>
      <h3>Key security (the "Key" button)</h3>
      <p>By default the API key is base64 in flkrfckr.ini — an encoding, not
      encryption. Turn encryption on to seal it with a passphrase (never saved).
      Optionally let this machine remember the unlock key.</p>
      <h3>Throttle &amp; Off-peak</h3>
      <p>Delay between photos. Default 1s is safe for shared hosting. "Off-peak only"
      pauses the import during the peak hours you set.</p>
      <h3>Private &rarr;</h3>
      <p>What to do with photos private/friends-only on Flickr: "draft" imports them
      unpublished (recommended); "published" makes everything live.</p>
      <h3>Comments &amp; names</h3>
      <p>Comments are imported and attached to each photo. Flickr stores only the
      commenter's ID; create flkrfckr-names.json in the export folder to map IDs to
      real names.</p>
      <h3>Resume</h3>
      <p>A checkpoint is written after every imported photo. On next launch FLKR FCKR
      offers to resume; already-imported IDs are skipped — no duplicates.</p>
      <h3>How images transfer</h3>
      <p>Each photo is resized locally, then uploaded over the same HTTPS connection
      as the API — no FTP, no separate credentials. GPS/EXIF is preserved on purpose.</p>
      <h3>After the import</h3>
      <p>Revoke the FLKR FCKR API key in your admin panel. To retry failures, re-run
      against the same export folder — duplicates are skipped.</p>
    </div>
    <div class="modal-btns"><button class="accent" id="h-close">Close</button></div>`);
  $("#h-close").addEventListener("click", closeModal);
}

document.addEventListener("DOMContentLoaded", boot);
/* ===== SNAPSMACK EOF ===== */
