/* THE HUB — window logic. Every bit of real work is reached through blink.call();
   this file only builds the same controls the tkinter window had and shows results.
   Ported 1:1 from ../../main.py (the tkinter Hub). */

const $ = (s) => document.querySelector(s);
const $$ = (s) => Array.prototype.slice.call(document.querySelectorAll(s));
const CRED_KEYS = ["hub_url", "hub_key", "gemini_api_key", "google_credentials", "drive_folder_id"];

let STATE = { profiles: {}, prompt_sites: [], pool: {} }; // profiles keyed by site-key for the prompt card

function log(msg, kind) {
  const el = document.createElement("div");
  el.className = "line " + (kind || "");
  el.textContent = msg;
  $("#log").prepend(el);
}

function setStat(el, ok, msg) {
  if (!el) return;
  el.textContent = (msg === "" ? "" : (ok ? "✓ " : "✗ ") + msg);
  el.className = "stat " + (msg === "" ? "" : (ok ? "ok" : "err"));
}
function setBusy(el, msg) { if (el) { el.textContent = msg; el.className = "stat dim"; } }

// ── boot ─────────────────────────────────────────────────────────────────────
async function boot() {
  let state;
  try {
    state = await blink.call("load_state");
  } catch (e) { $("#app").innerHTML = ""; log("Could not load: " + e.message, "err"); return; }

  $("#ver").textContent = state.version ? "build " + state.version : "";

  if (!state.shared_ok) {
    $("#app").innerHTML = '<div class="fatal">Shared modules unavailable: ' +
      (state.shared_err || "unknown") + "</div>";
    return;
  }

  // swap the template in
  $("#app").innerHTML = "";
  $("#app").appendChild($("#tpl-body").content.cloneNode(true));

  renderRoster(state.roster || []);
  fillCreds(state.creds || {});
  renderProfiles(state.profiles || []);
  STATE.prompt_sites = state.prompt_sites || [];
  STATE.pool = state.pool || {};
  renderPromptSites();
  wireSetup();
  wirePrompts();
}

// ── LAUNCH ────────────────────────────────────────────────────────────────────
function renderRoster(roster) {
  const grid = $("#roster");
  grid.innerHTML = "";
  roster.forEach((t) => {
    const cell = document.createElement("div");
    cell.className = "cell";
    const btn = document.createElement("button");
    btn.className = "launch" + (t.available ? "" : " off");
    btn.textContent = t.name;
    btn.disabled = !t.available;
    if (t.available) {
      btn.addEventListener("click", () => onLaunch(t));
    }
    const sub = document.createElement("div");
    sub.className = "sub";
    sub.textContent = t.available ? t.sub : "not installed";
    cell.appendChild(btn); cell.appendChild(sub);
    grid.appendChild(cell);
  });
}

async function onLaunch(t) {
  try {
    await blink.call("launch_tool", t.path);
    log("Launched " + t.name, "ok");
  } catch (e) {
    log("Launch failed — " + t.name + ": " + e.message, "err");
  }
}

// ── HUB SETUP ──────────────────────────────────────────────────────────────────
function fillCreds(creds) {
  CRED_KEYS.forEach((k) => { const el = $("#" + k); if (el) el.value = creds[k] || ""; });
}
function readCreds() {
  const out = {};
  CRED_KEYS.forEach((k) => { const el = $("#" + k); out[k] = el ? el.value.trim() : ""; });
  return out;
}

function wireSetup() {
  // field TEST buttons
  $$("button[data-test]").forEach((b) => {
    b.addEventListener("click", () => onTest(b.getAttribute("data-test")));
  });
  $("#btn_save").addEventListener("click", onSaveCreds);
  $("#btn_discover").addEventListener("click", onDiscover);
}

async function onTest(which) {
  if (which === "hub") {
    const el = $("#stat_hub"); setBusy(el, "testing…");
    try { const r = await blink.call("test_hub", $("#hub_url").value, $("#hub_key").value);
      setStat(el, r.ok, r.msg); } catch (e) { setStat(el, false, e.message); }
  } else if (which === "gemini") {
    const el = $("#stat_gemini"); setBusy(el, "testing…");
    try { const r = await blink.call("test_gemini", $("#gemini_api_key").value);
      setStat(el, r.ok, r.msg); } catch (e) { setStat(el, false, e.message); }
  } else if (which === "drive") {
    const el = $("#stat_drive"); setBusy(el, "testing…");
    try { const r = await blink.call("test_drive", $("#google_credentials").value, $("#drive_folder_id").value);
      setStat(el, r.ok, r.msg); } catch (e) { setStat(el, false, e.message); }
  }
}

async function onSaveCreds() {
  const el = $("#setup_status");
  try {
    const r = await blink.call("save_creds", readCreds());
    setStat(el, true, r.saved + " credential(s) saved to shared vault");
    fillCreds(r.creds || {});
  } catch (e) { setStat(el, false, "save failed: " + e.message); }
}

async function onDiscover() {
  const el = $("#setup_status");
  if (!$("#hub_url").value.trim()) {
    setStat(el, false, "enter your hub site URL first"); return;
  }
  setBusy(el, "saving + discovering…");
  let r;
  try {
    r = await blink.call("discover", readCreds());
  } catch (e) { setStat(el, false, e.message); log("Discovery failed: " + e.message, "err"); return; }
  fillCreds(r.creds || {});
  renderProfiles(r.profiles || []);
  STATE.prompt_sites = r.prompt_sites || [];
  STATE.pool = r.pool || {};
  renderPromptSites();
  setStat(el, true, "saved + " + (r.count || 0) + " site(s) into the shared store");
}

// ── SHARED PROFILES ─────────────────────────────────────────────────────────────
function renderProfiles(profiles) {
  const ul = $("#profiles");
  ul.innerHTML = "";
  if (!profiles.length) {
    const li = document.createElement("li");
    li.className = "empty";
    li.textContent = "(no shared profiles yet — Discover Fleet, or save one in any tool)";
    ul.appendChild(li);
    return;
  }
  profiles.forEach((p) => {
    const li = document.createElement("li");
    const nm = document.createElement("span"); nm.className = "pname"; nm.textContent = p.name || "";
    const url = document.createElement("span"); url.className = "purl"; url.textContent = p.site_url || "";
    li.appendChild(nm); li.appendChild(url);
    ul.appendChild(li);
  });
}

// ── PROMPT SYNC ─────────────────────────────────────────────────────────────────
function renderPromptSites() {
  const sel = $("#psite");
  const prev = sel.value;
  sel.innerHTML = "";
  STATE.prompt_sites.forEach((k) => {
    const o = document.createElement("option"); o.value = k; o.textContent = k; sel.appendChild(o);
  });
  if (STATE.prompt_sites.indexOf(prev) >= 0) sel.value = prev;
  onSiteSelected();
}

function wirePrompts() {
  $("#psite").addEventListener("change", onSiteSelected);
  $("#btn_pull_all").addEventListener("click", onPullAll);
  $("#btn_pull_one").addEventListener("click", onPullOne);
  $("#btn_push").addEventListener("click", onPushOne);
  $("#btn_copy").addEventListener("click", openCopyModal);
  $("#modal_cancel").addEventListener("click", closeCopyModal);
  $("#modal_use").addEventListener("click", useCopyModal);
}

function currentSite() { return $("#psite").value; }

async function onSiteSelected() {
  const key = currentSite();
  setStat($("#psync_status"), true, "");
  if (!key) { $("#ptext").value = ""; return; }
  // pool is already loaded client-side, but confirm through the handler to match Windows behaviour
  try {
    const r = await blink.call("load_prompt", key);
    $("#ptext").value = r.prompt || "";
  } catch (e) { $("#ptext").value = STATE.pool[key] || ""; }
}

async function onPullAll() {
  const el = $("#psync_status");
  setBusy(el, "pulling from the fleet…");
  let r;
  try { r = await blink.call("pull_all"); }
  catch (e) { setStat(el, false, e.message); log("Pull failed: " + e.message, "err"); return; }
  STATE.prompt_sites = r.prompt_sites || STATE.prompt_sites;
  STATE.pool = r.pool || STATE.pool;
  renderPromptSites();
  const rep = r.report || {};
  const added = rep.added || [], unchanged = rep.unchanged || [], differs = rep.differs || [], failed = rep.failed || [];
  let msg = "Pulled " + (added.length + unchanged.length) + " blog(s) into the shared pool. " +
            added.length + " new · " + unchanged.length + " matched";
  if (differs.length) msg += " · " + differs.length + " differ (untouched): " + differs.map((d) => d.site).join(", ");
  if (failed.length) msg += " · " + failed.length + " unreachable: " + failed.map((f) => f.site + " (" + f.error + ")").join(", ");
  log(msg, failed.length ? "warn" : "ok");
  setStat(el, !failed.length, added.length + " added · " + differs.length + " differ · " + failed.length + " failed");
}

async function onPullOne() {
  const key = currentSite();
  if (!key) { setStat($("#psync_status"), false, "choose a site first"); return; }
  const el = $("#psync_status");
  setBusy(el, "fetching this site's live prompt…");
  try {
    const r = await blink.call("pull_one", key);
    $("#ptext").value = r.prompt || "";
    setStat(el, true, "showing the live prompt — PUSH to keep it, or edit first");
  } catch (e) { setStat(el, false, e.message); log("Fetch failed: " + e.message, "err"); }
}

async function onPushOne() {
  const key = currentSite();
  if (!key) { setStat($("#psync_status"), false, "choose a site first"); return; }
  const text = $("#ptext").value.trim();
  const ok = window.confirm(
    "Send this prompt to " + key + "?\n\nIt becomes the prompt that blog's one-call AI fill " +
    "uses for every new image. An empty prompt resets that blog to its built-in default.");
  if (!ok) return;
  const el = $("#psync_status");
  setBusy(el, "pushing…");
  try {
    const r = await blink.call("push_one", key, text);
    STATE.pool = r.pool || STATE.pool;
    if (r.pushed) setStat(el, true, "pushed to " + key + " and saved to the shared pool");
    else setStat(el, false, "push rejected — check the site key (re-run Discover Fleet)");
  } catch (e) { setStat(el, false, e.message); log("Push failed: " + e.message, "err"); }
}

// COPY FROM… modal
function openCopyModal() {
  const key = currentSite();
  const others = STATE.prompt_sites.filter((k) => k !== key);
  if (!others.length) { log("No other blog to copy from yet.", "warn"); return; }
  const ul = $("#copy_list");
  ul.innerHTML = "";
  others.forEach((k) => {
    const li = document.createElement("li");
    li.textContent = k; li.setAttribute("data-key", k);
    li.addEventListener("click", () => {
      $$("#copy_list li").forEach((x) => x.classList.remove("sel"));
      li.classList.add("sel");
    });
    ul.appendChild(li);
  });
  $("#modal").classList.remove("hidden");
}
function closeCopyModal() { $("#modal").classList.add("hidden"); }
async function useCopyModal() {
  const sel = $("#copy_list li.sel");
  if (!sel) { closeCopyModal(); return; }
  const src = sel.getAttribute("data-key");
  try {
    const r = await blink.call("copy_from", src);
    $("#ptext").value = r.prompt || "";
    setStat($("#psync_status"), true, "copied " + src + "'s prompt into the editor — review, then PUSH");
  } catch (e) { log("Copy failed: " + e.message, "err"); }
  closeCopyModal();
}

document.addEventListener("DOMContentLoaded", boot);
/* ===== SNAPSMACK EOF ===== */
