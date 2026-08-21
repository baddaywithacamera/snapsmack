/* CRONOMETER — window logic. Every fleet/health action is reached through
   blink.call(); the verdicts are computed by the original Python (heartbeat_client).

   Parity with the tkinter build:
     REFRESH ALL  -> probe_all         (fans out across the shared pool)
     RELOAD FLEET -> reload_fleet + re-probe
     RE-CHECK     -> probe_site        (per card)
     legend / cards / job rows / status line — rebuilt here 1:1
*/
const $ = (s) => document.querySelector(s);

let STATE = { build: "", timeout: 12, fleet: [], job_specs: [], legend: [] };
let BUSY = false;

function log(msg, kind) {
  const el = document.createElement("div");
  el.className = "line " + (kind || "");
  el.textContent = msg;
  $("#log").prepend(el);
}
function setStatus(text) { $("#status").textContent = text || ""; }

function setBusy(b) {
  BUSY = b;
  $("#btn-refresh").disabled = b;
  $("#btn-reload").disabled = b;
}

/* ── legend ───────────────────────────────────────────────────────────────── */
function renderLegend() {
  const wrap = $("#legend");
  wrap.innerHTML = "";
  for (const item of STATE.legend) {
    const cell = document.createElement("span");
    cell.className = "legend-cell";
    cell.innerHTML =
      `<span class="dot" style="color:${item.colour}">●</span>` +
      `<span class="legend-word">${item.word}</span>`;
    wrap.appendChild(cell);
  }
}

/* ── board / cards ────────────────────────────────────────────────────────── */
function cardId(url) { return "card-" + btoa(unescape(encodeURIComponent(url))).replace(/=/g, ""); }

function renderBoard() {
  const board = $("#board");
  board.innerHTML = "";
  if (!STATE.fleet.length) {
    const empty = document.createElement("div");
    empty.className = "empty";
    empty.textContent =
      "No fleet sites found. CRONOMETER reads the shared per-site profiles that " +
      "SYBU, GYSS and COLD SNAP write. Set up a site in one of those tools " +
      "(or confirm ~/snapsmack/shared_library/profiles exists), then press RELOAD FLEET.";
    board.appendChild(empty);
    return;
  }
  for (const site of STATE.fleet) board.appendChild(buildCard(site));
}

function buildCard(site) {
  const card = document.createElement("section");
  card.className = "card";
  card.id = cardId(site.url);

  const jobRows = STATE.job_specs.map((spec) => `
      <div class="job" data-job="${spec.key}">
        <span class="dot job-dot">●</span>
        <span class="job-label">${escapeHtml(spec.label)}</span>
        <span class="job-state">—</span>
        <span class="job-age"></span>
        <span class="job-detail"></span>
      </div>`).join("");

  card.innerHTML = `
    <div class="card-head">
      <span class="dot card-dot">●</span>
      <span class="card-name">${escapeHtml(site.name)}</span>
      <span class="card-ver"></span>
      <span class="card-spacer"></span>
      <span class="card-summary">not checked yet</span>
      <button class="btn recheck">RE-CHECK</button>
    </div>
    <div class="card-url">${escapeHtml(site.url)}</div>
    <div class="jobs">${jobRows}</div>`;

  card.querySelector(".recheck").addEventListener("click", () => recheckSite(site));
  return card;
}

/* Paint one card from a health dict returned by probe_site/probe_all. */
function paintCard(health) {
  const card = document.getElementById(cardId(health.url));
  if (!card) return;

  card.querySelector(".card-dot").style.color = health.overall_colour;
  card.querySelector(".card-ver").textContent = health.version ? ("v" + health.version) : "";
  const summ = card.querySelector(".card-summary");
  summ.textContent = health.summary;
  summ.style.color = health.summary_colour;

  const rows = card.querySelectorAll(".job");
  if (!health.online) {
    rows.forEach((row) => {
      row.querySelector(".job-dot").style.color = "#777777";
      row.querySelector(".job-state").textContent = "—";
      row.querySelector(".job-state").style.color = "#777777";
      row.querySelector(".job-age").textContent = "";
      row.querySelector(".job-detail").textContent = "(site unreachable)";
    });
    return;
  }
  const byKey = {};
  for (const j of health.jobs) byKey[j.key] = j;
  rows.forEach((row) => {
    const j = byKey[row.dataset.job];
    if (!j) return;
    row.querySelector(".job-dot").style.color = j.colour;
    const st = row.querySelector(".job-state");
    st.textContent = j.sev_word;
    st.style.color = j.colour;
    row.querySelector(".job-age").textContent = j.age_text;
    row.querySelector(".job-detail").textContent = j.detail;
  });
}

function markChecking() {
  document.querySelectorAll(".card-summary").forEach((s) => {
    s.textContent = "checking…";
    s.style.color = "#D4872A";
  });
}

/* ── actions ──────────────────────────────────────────────────────────────── */
async function refreshAll() {
  if (BUSY || !STATE.fleet.length) {
    if (!STATE.fleet.length) setStatus("no sites to check");
    return;
  }
  setBusy(true);
  setStatus(`checking ${STATE.fleet.length} site(s)…`);
  markChecking();
  try {
    const res = await blink.call("probe_all", STATE.fleet, STATE.timeout);
    for (const h of res.results) paintCard(h);
    setStatus(`checked ${res.count} site(s) — ${res.headline}`);
  } catch (e) {
    log("Refresh failed: " + e.message, "err");
    setStatus("refresh failed");
  } finally {
    setBusy(false);
  }
}

async function recheckSite(site) {
  const card = document.getElementById(cardId(site.url));
  if (card) {
    const summ = card.querySelector(".card-summary");
    summ.textContent = "checking…";
    summ.style.color = "#D4872A";
    card.querySelector(".recheck").disabled = true;
  }
  setStatus(`re-checking ${site.name}…`);
  try {
    const h = await blink.call("probe_site", site, STATE.timeout);
    paintCard(h);
    setStatus(`${site.name}: ${(h.overall_word || h.overall || "").toLowerCase()}`);
  } catch (e) {
    log("Re-check failed: " + e.message, "err");
    setStatus("re-check failed");
  } finally {
    if (card) card.querySelector(".recheck").disabled = false;
  }
}

async function reloadFleet() {
  if (BUSY) return;
  setStatus("reloading fleet…");
  try {
    const res = await blink.call("reload_fleet");
    STATE.fleet = res.fleet || [];
    renderBoard();
    await refreshAll();
  } catch (e) {
    log("Reload failed: " + e.message, "err");
    setStatus("reload failed");
  }
}

/* ── boot ─────────────────────────────────────────────────────────────────── */
async function boot() {
  $("#btn-refresh").addEventListener("click", refreshAll);
  $("#btn-reload").addEventListener("click", reloadFleet);
  try {
    STATE = await blink.call("load_state");
    document.title = `CRONOMETER — build ${STATE.build}`;
    renderLegend();
    renderBoard();
    // Kick an initial sweep so the board isn't blank on open (matches after(200,...)).
    setTimeout(refreshAll, 200);
  } catch (e) {
    log("Could not load: " + e.message, "err");
  }
}

function escapeHtml(s) {
  return String(s == null ? "" : s)
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
}

document.addEventListener("DOMContentLoaded", boot);
/* ===== SNAPSMACK EOF ===== */
