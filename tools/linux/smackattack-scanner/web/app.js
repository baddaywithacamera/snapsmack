/* SMACKATTACK SCANNER — window logic. All Python work is reached through blink.call(). */

const $  = (s) => document.querySelector(s);
const $$ = (s) => Array.from(document.querySelectorAll(s));

const FIELDS = ["db_host", "db_port", "db_name", "db_user", "db_password",
                "api_url", "api_key", "threshold", "min_words"];

let SELECTED_ROW = null;   // db id of the currently-selected results row

// ── status bar ───────────────────────────────────────────────────────────────
function status(msg, kind) {
  const el = $("#statusbar");
  el.textContent = msg;
  el.className = kind || "";
}

// ── help modal (static content, rendered client-side — no blink.call) ──────────
function esc(s) {
  return String(s == null ? "" : s).replace(/[&<>"]/g,
    (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
}
function openModal(html) {
  const m = $("#modal");
  m.innerHTML = html;
  $("#modal-back").hidden = false;
}
function closeModal() {
  $("#modal-back").hidden = true;
  $("#modal").innerHTML = "";
}
function showHelp() {
  openModal(
    `<h3>SMACKATTACK SCANNER — help</h3><p>${esc(
      "SMACKATTACK SCANNER finds commenters whose writing style matches banned users or each other.\n\n" +
      "1. SETTINGS: enter the database connection (Host, Port, Database, Username, Password), then " +
      "SAVE & TEST CONNECTION. The SMACKATTACK HUB API (URL + key) is optional — it only enables " +
      "UPLOAD TO HUB.\n\n" +
      "2. SCAN PARAMETERS (in SETTINGS): Similarity Threshold flags matches at or above this cosine " +
      "similarity (0.0–1.0). Default: 0.55. Minimum Words skips authors with fewer combined words than " +
      "this. Default: 30.\n\n" +
      "3. SCAN: RUN SCAN fetches approved comments from the database, computes 25-dimension writing " +
      "style vectors, and compares all authors against each other and any stored ban profiles. Matches " +
      "above the similarity threshold are stored in snap_gobsmacked_scan.\n\n" +
      "4. RESULTS: VIEW RESULTS shows flagged matches. Filter by All, Peer Matches, vs Banned, or " +
      "Unreviewed. Select a row, then MARK REVIEWED, or UPLOAD TO HUB to report it (needs the hub API " +
      "URL and key in Settings)."
    )}</p>` +
    `<div class="modal-btns"><button class="accent" id="btn-help-ok">OK</button></div>`
  );
  $("#btn-help-ok").addEventListener("click", closeModal);
}

// ── tab switching ────────────────────────────────────────────────────────────
function initTabs() {
  $$(".tab").forEach((t) => {
    t.addEventListener("click", () => showTab(t.dataset.tab));
  });
}
function showTab(name) {
  $$(".tab").forEach((t) => t.classList.toggle("active", t.dataset.tab === name));
  $$(".panel").forEach((p) => p.classList.toggle("active", p.id === "panel-" + name));
  if (name === "results") loadResults();
}

// ── SETTINGS ─────────────────────────────────────────────────────────────────
function fillSettings(settings) {
  FIELDS.forEach((k) => {
    const el = $("#f-" + k);
    if (el) el.value = settings[k] != null ? settings[k] : "";
  });
}
function readSettings() {
  const out = {};
  FIELDS.forEach((k) => {
    const el = $("#f-" + k);
    if (el) out[k] = el.value.trim();
  });
  return out;
}
async function saveAndTest() {
  const msg = $("#conn-msg");
  msg.textContent = "Connecting…";
  msg.className = "muted";
  try {
    const r = await blink.call("save_and_test", readSettings());
    if (r.ok) {
      msg.textContent = "✓ " + r.message;
      msg.className = "ok";
      status(r.message, "ok");
    } else {
      msg.textContent = "✗ " + r.message;
      msg.className = "err";
      status("Connection failed: " + r.message, "err");
    }
  } catch (e) {
    msg.textContent = "✗ " + e.message;
    msg.className = "err";
    status("Connection failed: " + e.message, "err");
  }
}

// ── SCAN ─────────────────────────────────────────────────────────────────────
function logClear() { $("#scan-log").innerHTML = ""; }
function logLine(message, kind) {
  const div = document.createElement("div");
  div.className = "line " + (kind || "");
  div.textContent = message;
  $("#scan-log").appendChild(div);
  $("#scan-log").scrollTop = $("#scan-log").scrollHeight;
}
function setBar(pct) { $("#bar").style.width = Math.max(0, Math.min(100, pct)) + "%"; }

async function runScan() {
  const btn = $("#btn-run");
  btn.disabled = true;
  logClear();
  setBar(0);
  $("#s-authors").textContent = "—";
  $("#s-pairs").textContent = "—";
  $("#s-flags").textContent = "—";
  $("#scan-status").textContent = "Starting…";
  logLine("Running scan… (large databases may take a moment)", "dim");
  try {
    const r = await blink.call("run_scan");
    // Replay the full log the core produced.
    logClear();
    (r.log || []).forEach((l) => logLine(l.message, l.kind));
    $("#s-authors").textContent = r.authors;
    $("#s-pairs").textContent = r.pairs;
    $("#s-flags").textContent = r.flags;
    setBar(100);
    $("#scan-status").textContent = "Scan finished at " + (r.finished_at || "");
    status("Scan complete — " + r.flags + " flag(s) | " + r.pairs + " pairs compared",
           r.flags ? "warn" : "ok");
  } catch (e) {
    logLine("Error: " + e.message, "err");
    status("Scan error: " + e.message, "err");
  } finally {
    btn.disabled = false;
  }
}

// ── RESULTS ──────────────────────────────────────────────────────────────────
function currentFilter() {
  const el = $('input[name="filt"]:checked');
  return el ? el.value : "all";
}
async function loadResults() {
  const body = $("#results-body");
  body.innerHTML = "";
  SELECTED_ROW = null;
  const act = $("#act-msg");
  try {
    const r = await blink.call("get_results", currentFilter());
    if (!r.connected) {
      act.textContent = "Not connected — configure the database in Settings.";
      act.className = "warn";
      return;
    }
    r.rows.forEach((row) => body.appendChild(rowEl(row)));
    act.textContent = r.count + " row(s)";
    act.className = "muted";
  } catch (e) {
    act.textContent = "Could not load: " + e.message;
    act.className = "err";
  }
}
function rowEl(row) {
  const tr = document.createElement("tr");
  tr.dataset.id = row.id;
  if (row.reviewed) tr.classList.add("reviewed");
  const cells = [
    row.similarity_pct,
    (row.match_type || "").toUpperCase(),
    String(row.display_name || row.author_key || "").slice(0, 32),
    String(row.matched_key || "").slice(0, 24) + "…",
    row.email || "",
    row.flagged_at || "",
    row.reviewed ? "Reviewed" : "New",
  ];
  cells.forEach((c, i) => {
    const td = document.createElement("td");
    td.textContent = c;
    if (i === 0 && !row.reviewed) td.style.color = row.colour;
    tr.appendChild(td);
  });
  tr.addEventListener("click", () => {
    $$("#results-body tr").forEach((x) => x.classList.remove("sel"));
    tr.classList.add("sel");
    SELECTED_ROW = row.id;
  });
  return tr;
}
async function markReviewed() {
  const act = $("#act-msg");
  if (!SELECTED_ROW) { act.textContent = "Select a row first."; act.className = "warn"; return; }
  try {
    await blink.call("mark_reviewed", SELECTED_ROW);
    loadResults();
  } catch (e) {
    act.textContent = e.message; act.className = "err";
  }
}
async function uploadSelected() {
  const act = $("#act-msg");
  if (!SELECTED_ROW) { act.textContent = "Select a row first."; act.className = "warn"; return; }
  act.textContent = "Uploading…"; act.className = "muted";
  try {
    const r = await blink.call("upload_to_hub", SELECTED_ROW);
    if (r.ok) {
      act.textContent = "✓ " + r.message; act.className = "ok";
      loadResults();
    } else {
      act.textContent = r.message; act.className = "err";
    }
  } catch (e) {
    act.textContent = "Upload failed: " + e.message; act.className = "err";
  }
}

// ── boot ─────────────────────────────────────────────────────────────────────
async function boot() {
  initTabs();
  $("#btn-save").addEventListener("click", saveAndTest);
  $("#btn-run").addEventListener("click", runScan);
  $("#btn-view").addEventListener("click", () => showTab("results"));
  $("#btn-refresh").addEventListener("click", loadResults);
  $("#btn-reviewed").addEventListener("click", markReviewed);
  $("#btn-upload").addEventListener("click", uploadSelected);
  $("#btn-help").addEventListener("click", showHelp);
  $("#modal-back").addEventListener("click", (e) => { if (e.target.id === "modal-back") closeModal(); });
  $$('input[name="filt"]').forEach((r) => r.addEventListener("change", loadResults));

  try {
    const state = await blink.call("load_state");
    $("#ver").textContent = "v" + state.version;
    fillSettings(state.settings || {});
  } catch (e) {
    status("Could not load settings: " + e.message, "err");
  }
}
document.addEventListener("DOMContentLoaded", boot);
/* ===== SNAPSMACK EOF ===== */
