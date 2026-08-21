/* SHOTS FIRED — window logic. Every bit of real work is reached through
   blink.call() into the original Python (fleet.py / schedule_client.py /
   config.py). This file only draws the agenda the tkinter version drew. */

const $ = (s) => document.querySelector(s);

/* Per-site colour palette — copied from ui.SITE_SWATCHES so the web agenda
   colour-codes sites exactly like the tkinter one. Assigned round-robin in the
   order sites first appear, mirroring AgendaView._swatch(). */
const SITE_SWATCHES = [
  "#39FF14", "#4EC994", "#D4872A", "#4AA3FF", "#FF6FB5",
  "#C792EA", "#FFCB6B", "#F78C6C", "#89DDFF", "#B0BEC5",
];
const _swatchFor = {};
function swatch(key) {
  if (!(key in _swatchFor)) {
    _swatchFor[key] = SITE_SWATCHES[Object.keys(_swatchFor).length % SITE_SWATCHES.length];
  }
  return _swatchFor[key];
}

let _loading = false;
let _movePost = null;   // the post currently open in the MOVE dialog

function setStatus(text) { $("#status").textContent = text; }

const DAY_NAMES = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
const MONTHS = ["January", "February", "March", "April", "May", "June", "July",
                "August", "September", "October", "November", "December"];

function dayKey(iso) { return iso.slice(0, 10); }            // "YYYY-MM-DD"

function fmtDayHeader(dateStr) {
  // dateStr = "YYYY-MM-DD" (local calendar day, no tz shift).
  const [y, m, d] = dateStr.split("-").map(Number);
  const dt = new Date(y, m - 1, d);
  const label = `${DAY_NAMES[dt.getDay()]}  ${String(d).padStart(2, "0")} ${MONTHS[m - 1]} ${y}`;
  return label.toUpperCase();
}

function relWhen(dateStr) {
  const [y, m, d] = dateStr.split("-").map(Number);
  const day = new Date(y, m - 1, d);
  const now = new Date();
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const rel = Math.round((day - today) / 86400000);
  if (rel === 0) return "TODAY";
  if (rel === 1) return "TOMORROW";
  if (rel < 0) return `${-rel} day${rel === -1 ? "" : "s"} ago`;
  return `in ${rel} days`;
}

function el(tag, cls, text) {
  const e = document.createElement(tag);
  if (cls) e.className = cls;
  if (text != null) e.textContent = text;
  return e;
}

/* ---- render the agenda (mirrors AgendaView.set_posts) -------------------- */
function renderAgenda(posts, notes) {
  const root = $("#agenda");
  root.innerHTML = "";

  // Per-site status notes up top — which sites are NOT represented below.
  (notes || []).forEach((n) => root.appendChild(el("div", "note", n)));

  if (!posts || posts.length === 0) {
    const empty = el("div", "empty");
    empty.textContent =
      "No upcoming scheduled posts in the look-ahead window. " +
      "Future-dated posts made in SYBU / COLD SNAP appear here.";
    root.appendChild(empty);
    return;
  }

  // Group by calendar day (posts arrive already sorted by iso).
  const byDay = {};
  posts.forEach((p) => { (byDay[dayKey(p.iso)] ||= []).push(p); });

  Object.keys(byDay).sort().forEach((day) => {
    const rows = byDay[day];

    const header = el("div", "day-header");
    header.appendChild(el("span", "day-name", fmtDayHeader(day)));
    header.appendChild(el("span", "day-when",
      `${relWhen(day)}   •   ${rows.length} post${rows.length !== 1 ? "s" : ""}`));
    root.appendChild(header);

    rows.forEach((p) => root.appendChild(postRow(p)));
  });
}

/* ---- one post row (mirrors AgendaView._post_row) ------------------------ */
function postRow(p) {
  const row = el("div", "post-row");

  const rail = el("div", "rail");
  rail.style.background = swatch(p.site_url);
  row.appendChild(rail);

  row.appendChild(el("div", "post-time", p.time));

  const col = el("div", "post-col");
  col.appendChild(el("div", "post-title", p.title || "(untitled)"));
  const site = el("div", "post-site", p.site_name);
  site.style.color = swatch(p.site_url);
  col.appendChild(site);
  row.appendChild(col);

  const move = el("button", "btn primary move-btn", "MOVE…");
  move.addEventListener("click", () => openMoveDialog(p));
  row.appendChild(move);

  return row;
}

/* ---- MOVE dialog (mirrors AgendaView._open_move_dialog) ------------------ */
function openMoveDialog(p) {
  _movePost = p;
  $("#move-post").textContent = p.title || "(untitled)";
  $("#move-current").textContent = `${p.site_name}   —   currently ${p.date} ${p.time}`;
  $("#move-date").value = p.date;
  $("#move-time").value = p.time;
  $("#move-err").textContent = "";
  $("#move-backdrop").hidden = false;
  $("#move-date").focus();
}

function closeMoveDialog() {
  $("#move-backdrop").hidden = true;
  _movePost = null;
}

async function confirmMove() {
  const p = _movePost;
  if (!p) return;
  const dateS = $("#move-date").value;
  const timeS = $("#move-time").value;
  const confirm = $("#move-confirm");
  confirm.disabled = true;
  setStatus(`Moving “${p.title || "post"}”…`);
  try {
    const res = await blink.call("reschedule_post", p.site_url, p.snap_id, dateS, timeS);
    if (res.ok) {
      closeMoveDialog();
      setStatus(`Moved “${p.title || "post"}” to ${res.new_when}.`);
      await doRefresh();
    } else if (res.status === "error" && /valid date/i.test(res.message)) {
      // Bad field entry — keep the dialog open and show the error inline.
      $("#move-err").textContent = res.message;
    } else {
      // Not-deployed / unauthorized / server error — close and report on status.
      closeMoveDialog();
      setStatus("Reschedule failed — " + res.message);
      window.alert(res.message);
    }
  } catch (e) {
    $("#move-err").textContent = "Reschedule failed: " + e.message;
  } finally {
    confirm.disabled = false;
  }
}

/* ---- Help modal (static text; no server call) --------------------------- */
function openHelp()  { $("#help-backdrop").hidden = false; $("#help-close").focus(); }
function closeHelp() { $("#help-backdrop").hidden = true; }

/* ---- refresh (mirrors App.refresh) -------------------------------------- */
async function doRefresh() {
  if (_loading) return;
  _loading = true;
  const days = parseInt($("#lookahead").value, 10) || 60;
  setStatus("Loading fleet + pulling scheduled posts…");
  try {
    const res = await blink.call("refresh", days);
    renderAgenda(res.posts, res.notes);
    const n = res.posts.length;
    const sites = res.site_count;
    setStatus(
      `${n} scheduled post${n !== 1 ? "s" : ""} across ` +
      `${sites} site${sites !== 1 ? "s" : ""}   •   ` +
      `look-ahead ${res.lookahead_days} days`);
  } catch (e) {
    setStatus("Could not load: " + e.message);
    $("#agenda").innerHTML = "";
    $("#agenda").appendChild(el("div", "note", "Load failed: " + e.message));
  } finally {
    _loading = false;
  }
}

/* ---- boot --------------------------------------------------------------- */
async function boot() {
  // Wire controls.
  $("#refresh").addEventListener("click", doRefresh);
  $("#lookahead").addEventListener("change", doRefresh);
  $("#move-cancel").addEventListener("click", closeMoveDialog);
  $("#move-confirm").addEventListener("click", confirmMove);
  $("#move-backdrop").addEventListener("click", (e) => {
    if (e.target === $("#move-backdrop")) closeMoveDialog();
  });
  document.addEventListener("keydown", (e) => {
    if ($("#move-backdrop").hidden) return;
    if (e.key === "Escape") closeMoveDialog();
    if (e.key === "Enter") { e.preventDefault(); confirmMove(); }
  });

  // Help modal — static; open/close only.
  $("#help").addEventListener("click", openHelp);
  $("#help-close").addEventListener("click", closeHelp);
  $("#help-backdrop").addEventListener("click", (e) => {
    if (e.target === $("#help-backdrop")) closeHelp();
  });
  document.addEventListener("keydown", (e) => {
    if (!$("#help-backdrop").hidden && e.key === "Escape") closeHelp();
  });

  try {
    const state = await blink.call("load_state");
    const sel = $("#lookahead");
    sel.innerHTML = "";
    state.lookahead_choices.forEach((d) => {
      const opt = document.createElement("option");
      opt.value = String(d);
      opt.textContent = `${d} days`;
      sel.appendChild(opt);
    });
    sel.value = String(state.lookahead_days);
  } catch (e) {
    setStatus("Could not load settings: " + e.message);
  }
  await doRefresh();
}

document.addEventListener("DOMContentLoaded", boot);
/* ===== SNAPSMACK EOF ===== */
