/* TAKE YOUR SHIT WITH YOU — Blink window logic.
   Every button here reaches the ORIGINAL Python through blink.call(). This file
   only paints the three screens and the Key-security modal, and pumps the
   export's progress the way main.py's tkinter event loop did. */

const $ = (s) => document.querySelector(s);

let VERSION = "0.1.0";
let FAREWELL = "";
let connected = false;
let pollTimer = null;

/* ---- little helpers ------------------------------------------------------ */
function humanBytes(n) {
  n = Number(n || 0);
  const units = ["B", "KB", "MB", "GB", "TB"];
  for (let i = 0; i < units.length; i++) {
    if (n < 1024 || units[i] === "TB") {
      return units[i] === "B"
        ? Math.round(n) + " B"
        : n.toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + " " + units[i];
    }
    n /= 1024;
  }
  return n.toFixed(1) + " TB";
}
function comma(n) { return Number(n || 0).toLocaleString(); }
function pad(s, w) { s = String(s); return s.length >= w ? s : s + " ".repeat(w - s.length); }
function lpad(s, w) { s = String(s); return s.length >= w ? s : " ".repeat(w - s.length) + s; }

function setStageTitle(t) { $("#stage-title").textContent = t; }

function showScreen(name) {
  for (const id of ["connect", "progress", "done"]) {
    $("#screen-" + id).hidden = (id !== name);
  }
}

/* ---- the streaming log --------------------------------------------------- */
function logLine(box, msg, tag) {
  const span = document.createElement("span");
  span.className = "line " + (tag || "");
  span.textContent = msg + "\n";
  box.appendChild(span);
  box.scrollTop = box.scrollHeight;
}

/* ---- boot ---------------------------------------------------------------- */
async function boot() {
  try {
    const st = await blink.call("load_state");
    VERSION = st.version;
    FAREWELL = st.farewell;
    document.title = "TAKE YOUR SHIT WITH YOU  v" + VERSION;
    $("#tagline").textContent = st.tagline;
    $("#farewell").textContent = st.farewell;

    const s = st.settings || {};
    $("#f-url").value = s.site_url || "";
    $("#f-key").value = s.api_key || "";
    $("#f-dest").value = s.destination || "";
    $("#o-wp").checked = !!s.courtesy_wordpress;
    $("#o-thumbs").checked = !!s.include_thumbnails;
    $("#o-zip").checked = !!s.compress;
    $("#o-conc").value = String(s.media_concurrency || 2);

    refreshSpace();
    maybeWarnLockedVault(st.vault);
    wire();
  } catch (e) {
    alert("Could not start: " + e.message);
  }
}

function maybeWarnLockedVault(vault) {
  if (vault && vault.enabled && !vault.unlocked) {
    const p = window.prompt(
      "Your saved export key is encrypted and this machine could not unlock it " +
      "automatically. Enter your passphrase to load it (or Cancel and type the key):");
    if (p) {
      blink.call("vault_unlock", p).then((r) => {
        if (r.api_key) $("#f-key").value = r.api_key;
      }).catch((e) => alert(e.message));
    }
  }
}

/* ---- wiring -------------------------------------------------------------- */
function wire() {
  $("#b-connect").addEventListener("click", onConnect);
  $("#b-browse").addEventListener("click", onBrowse);
  $("#b-keysec").addEventListener("click", openKeyModal);
  $("#b-go").addEventListener("click", onStart);
  $("#b-cancel").addEventListener("click", onCancel);
  $("#b-open").addEventListener("click", () => blink.call("open_folder").catch((e) => alert(e.message)));
  $("#b-view").addEventListener("click", () => blink.call("view_report").catch((e) => alert(e.message)));
  $("#b-zip").addEventListener("click", onCompress);
  $("#b-another").addEventListener("click", onAnother);
  $("#b-delete").addEventListener("click", onDelete);
  $("#ks-close").addEventListener("click", closeKeyModal);

  let spaceTimer = null;
  $("#f-dest").addEventListener("input", () => {
    clearTimeout(spaceTimer);
    spaceTimer = setTimeout(refreshSpace, 250);
  });
}

/* ---- WHERE IT GOES: free space + folder picker --------------------------- */
async function refreshSpace() {
  const path = $("#f-dest").value.trim();
  const el = $("#space");
  if (!path) { el.textContent = ""; el.className = "hint"; return; }
  try {
    const r = await blink.call("disk_free", path);
    el.textContent = r.text || "";
    el.className = "hint" + (r.low ? " warn" : "");
  } catch (e) { el.textContent = ""; }
}

async function onBrowse() {
  try {
    const r = await blink.call("pick_folder", $("#f-dest").value.trim());
    if (r.picked && r.path) {
      $("#f-dest").value = r.path;
      refreshSpace();
    } else if (r.no_picker) {
      alert("No graphical folder picker (zenity or kdialog) is installed. " +
            "Type the destination folder path into the box instead.");
    }
  } catch (e) { alert(e.message); }
}

/* ---- CONNECT ------------------------------------------------------------- */
async function onConnect() {
  const url = $("#f-url").value.trim();
  const key = $("#f-key").value.trim();
  if (!url || !key) { alert("A site address and an export key are both needed."); return; }
  const btn = $("#b-connect");
  const st = $("#conn-status");
  btn.disabled = true;
  st.textContent = "Asking the site…";
  st.className = "status";
  try {
    const r = await blink.call("connect", url, key);
    renderManifest(r.manifest);
    st.textContent = "Connected to " + r.manifest.name + ".";
    st.className = "status ok";
    connected = true;
    $("#b-go").disabled = false;
    refreshSpace();
  } catch (e) {
    st.textContent = "Could not connect.";
    st.className = "status err";
    alert(e.message);
  } finally {
    btn.disabled = false;
  }
}

function renderManifest(m) {
  const lines = [];
  lines.push(m.name + "   —   " + m.mode + "   —   SnapSmack " + (m.site_version || "?"));
  lines.push("");
  for (const row of m.rows) lines.push("  " + pad(row.name, 28) + lpad(comma(row.count), 9));
  if (m.other) lines.push("  " + pad("everything else", 28) + lpad(comma(m.other), 9));
  lines.push("");
  lines.push("  Media size is not shown because the site would have to walk");
  lines.push("  its own disk to find out, and that is exactly the kind of");
  lines.push("  thing this tool exists to keep off your server.");
  const el = $("#manifest");
  el.textContent = lines.join("\n");
  el.className = "mono";
}

/* ---- PACK MY SHIT: start the export -------------------------------------- */
async function onStart() {
  const dest = $("#f-dest").value.trim();
  if (!dest) { alert("Choose a folder for the archive first."); return; }
  const options = readOptions();
  try {
    await blink.call("start_export",
      $("#f-url").value.trim(), $("#f-key").value.trim(), dest, options);
  } catch (e) { alert(e.message); return; }

  $("#logbox").textContent = "";
  $("#p-stage").textContent = "Starting…";
  $("#p-detail").textContent = "";
  $("#bar").style.width = "0%";
  $("#b-cancel").disabled = false;
  $("#b-cancel").textContent = "STOP";
  setStageTitle("PACK YOUR SHIT");
  showScreen("progress");
  startPolling();
}

function readOptions() {
  return {
    courtesy_wordpress: $("#o-wp").checked,
    include_thumbnails: $("#o-thumbs").checked,
    compress: $("#o-zip").checked,
    media_concurrency: parseInt($("#o-conc").value, 10) || 2,
  };
}

/* ---- the poll loop (main.py's after(120,_poll), on the JS side) ---------- */
function startPolling() {
  stopPolling();
  pollTimer = setInterval(pollOnce, 200);
}
function stopPolling() {
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
}

async function pollOnce() {
  let r;
  try { r = await blink.call("poll_events"); }
  catch (e) { return; }
  for (const ev of r.events) handleEvent(ev);
}

function handleEvent(ev) {
  const box = $("#logbox");
  switch (ev.type) {
    case "log":
      logLine(box, ev.message, ev.tag);
      break;
    case "progress":
      setStageTitle(stageTitle(ev.stage));
      $("#p-stage").textContent = titleCase(stageTitle(ev.stage));
      $("#p-detail").textContent = ev.message || "";
      if (ev.frac !== null && ev.frac !== undefined) {
        $("#bar").style.width = Math.max(0, Math.min(100, ev.frac * 100)) + "%";
      }
      break;
    case "finished":
      stopPolling();
      renderDone(ev.report);
      break;
    case "cancelled":
      stopPolling();
      $("#b-cancel").disabled = true;
      $("#b-cancel").textContent = "STOPPED";
      logLine(box, "Stopped. Your completed downloads are safe. Reconnect to resume.", "warn");
      alert("Stopped. Your completed downloads are safe.\n\nPoint the tool at the same folder to carry on.");
      break;
    case "failed":
      stopPolling();
      $("#b-cancel").disabled = true;
      onFailed(ev);
      break;
    case "zipped":
      logLine(box, "Zip written: " + ev.path, "accent");
      $("#b-zip").disabled = true;
      alert("Compressed:\n" + ev.path);
      break;
    case "zip_failed":
      $("#b-zip").disabled = false;
      alert("Compress failed: " + ev.error);
      break;
  }
}

const STAGE_TITLES = {
  connect: "PACKING LIST", preflight: "PACKING LIST",
  collect: "PACK YOUR SHIT", assemble: "PACK YOUR SHIT",
  media: "PACK YOUR SHIT", indexes: "PACK YOUR SHIT",
  adapters: "COURTESY COUNTER", verify: "EVERYTHING ACCOUNTED FOR",
  finish: "YOUR SHIT IS PACKED",
};
function stageTitle(s) { return STAGE_TITLES[s] || s || "PACKING LIST"; }
function titleCase(s) { return s.replace(/\w\S*/g, (w) => w[0].toUpperCase() + w.slice(1).toLowerCase()); }

/* ---- STOP ---------------------------------------------------------------- */
async function onCancel() {
  if (!confirm("Everything already downloaded and verified is kept. Point the " +
      "tool at the same folder later and it carries on from where it stopped.\n\nStop now?")) return;
  $("#b-cancel").disabled = true;
  $("#b-cancel").textContent = "STOPPING…";
  try { await blink.call("cancel_export"); } catch (e) { alert(e.message); }
}

/* ---- failure ------------------------------------------------------------- */
function onFailed(ev) {
  const box = $("#logbox");
  logLine(box, "", "");
  logLine(box, "EXPORT STOPPED: " + ev.error, "err");
  const safe = "Nothing has been deleted. Everything already downloaded and " +
    "verified is still in the destination folder, and running the export again " +
    "against that same folder carries on from there.";
  logLine(box, safe, "dim");
  let detail = ev.error;
  if (ev.request_id) detail += "\n\nRequest id (for your site log): " + ev.request_id;
  alert("The export stopped\n\n" + detail + "\n\n" + safe);
  if (ev.traceback) logLine(box, ev.traceback, "dim");
}

/* ---- YOUR SHIT IS PACKED: render the report ------------------------------ */
function renderDone(rep) {
  setStageTitle("YOUR SHIT IS PACKED");
  showScreen("done");

  const head = $("#done-head");
  const sub = $("#done-sub");
  if (rep.complete && (!rep.warnings || !rep.warnings.length)) {
    head.textContent = "YOUR SHIT IS PACKED.";
    head.className = "done-head accent";
    sub.textContent = "Every image. Every scrap of portable data. JSON sidecars " +
      "included. Courtesy import files are in the box.\n\n" + FAREWELL;
  } else if (rep.complete) {
    head.textContent = "COMPLETE, WITH WARNINGS.";
    head.className = "done-head warn";
    sub.textContent = "Everything expected arrived, and the things worth knowing " +
      "about are listed below. Nothing is missing without being named.";
  } else {
    head.textContent = "NOT COMPLETE.";
    head.className = "done-head err";
    sub.textContent = "Something expected did not arrive. Nothing has been deleted " +
      "— run the export again against the same folder and it will fetch what is missing.";
  }

  const L = [];
  const w = (t) => L.push(t);
  w("Folder:  " + rep.root);
  w("Export:  " + rep.export_uuid);
  w("");
  w("WHAT CAME ACROSS");
  Object.keys(rep.counts || {}).sort().forEach((k) => {
    if (k.startsWith("records:")) return;
    w("  " + pad(k, 28) + lpad(comma(rep.counts[k]), 9));
  });
  w("");
  w("PER TABLE — EXPECTED vs EXPORTED");
  Object.keys(rep.expected || {}).sort().forEach((t) => {
    const exp = rep.expected[t];
    const act = (rep.counts || {})["records:" + t] || 0;
    w("  " + pad(t, 28) + lpad(comma(exp), 9) + "  vs " + lpad(comma(act), 9));
  });
  w("");
  w("MEDIA");
  w("  " + pad("files downloaded", 28) + lpad(comma(rep.media_files), 9));
  w("  " + pad("bytes", 28) + lpad(humanBytes(rep.media_bytes), 9));
  const missing = rep.media_missing || [];
  if (missing.length) {
    w("  " + pad("UNAVAILABLE", 28) + lpad(comma(missing.length), 9));
    missing.slice(0, 20).forEach((m) => w("    " + m.source + " " + m.id + ": " + m.reason));
    if (missing.length > 20) w("    …and " + (missing.length - 20) + " more, all listed in verification.json");
  }
  w("");
  if (rep.adapters && Object.keys(rep.adapters).length) {
    w("COURTESY COUNTER");
    for (const [name, a] of Object.entries(rep.adapters)) {
      const it = a.items || {};
      w("  " + name + " (" + a.format + "): " + comma(it.posts || 0) + " posts, " +
        comma(it.pages || 0) + " pages, " + comma(it.attachments || 0) + " images, " +
        comma(it.comments || 0) + " comments");
      (a.losses || []).forEach((loss) => w("    — " + loss));
    }
    w("");
  }
  if (rep.warnings && rep.warnings.length) {
    w("WARNINGS");
    rep.warnings.forEach((x) => w("  — " + x));
    w("");
  }
  w("DELIBERATELY NOT EXPORTED");
  (rep.exclusions || []).forEach((x) => w("  — " + x));

  $("#reportbox").textContent = L.join("\n");
  $("#b-zip").disabled = !!rep.zip_path;
  $("#delete-wrap").hidden = !!rep.complete;
}

/* ---- done-screen actions ------------------------------------------------- */
async function onCompress() {
  $("#b-zip").disabled = true;
  try { await blink.call("compress"); }
  catch (e) { $("#b-zip").disabled = false; alert(e.message); }
}

async function onAnother() {
  try { await blink.call("reset"); } catch (e) { /* non-fatal */ }
  connected = false;
  $("#b-go").disabled = true;
  $("#conn-status").textContent = "";
  $("#manifest").textContent = "Connect and this fills in.";
  $("#manifest").className = "mono dim";
  setStageTitle("PACKING LIST");
  showScreen("connect");
}

async function onDelete() {
  if (!confirm("This permanently deletes this incomplete export, including every " +
      "photograph downloaded so far. You would be starting the export again from " +
      "nothing.\n\nYou do NOT need to do this to resume — just run the export again " +
      "against the same folder.\n\nDelete it?")) return;
  if (!confirm("Last chance. Delete it?")) return;
  try {
    await blink.call("delete_incomplete");
    alert("The incomplete export has been deleted.");
    onAnother();
  } catch (e) { alert(e.message); }
}

/* ---- Key security modal (the KeySecurityDialog, ported) ------------------ */
async function openKeyModal() {
  $("#modal").hidden = false;
  await renderKeyModal();
}
function closeKeyModal() { $("#modal").hidden = true; }

async function renderKeyModal() {
  let v;
  try { v = await blink.call("vault_status"); }
  catch (e) { alert(e.message); return; }
  const status = $("#ks-status");
  const detail = $("#ks-detail");
  const btns = $("#ks-buttons");
  btns.innerHTML = "";

  if (!v.available) {
    status.textContent = "Encryption unavailable";
    status.className = "ks-status warn";
    detail.textContent = "The cryptography package is not in this build, so your " +
      "export key is stored as base64 — an encoding, not encryption. Anyone with " +
      "this folder can read it. The key is read-only and expires, but treat it as " +
      "a secret anyway.";
    return;
  }

  if (v.enabled) {
    status.textContent = "Encryption is ON" + (v.unlocked ? "" : " (locked)");
    status.className = "ks-status " + (v.unlocked ? "accent" : "warn");
    detail.textContent = "Your export key is sealed with a key derived from your " +
      "passphrase. The passphrase is not stored anywhere, so a copy of this folder " +
      "is not enough to read the key." +
      (v.unlocked ? "" : "\n\nThe vault is locked. Unlock to use the saved key.");
    if (!v.unlocked) addKsBtn("Unlock", ksUnlock, true);
    addKsBtn("Change passphrase", ksChange);
    addKsBtn("Turn encryption off", ksOff);
  } else {
    status.textContent = "Encryption is OFF";
    status.className = "ks-status warn";
    detail.textContent = "Your export key is stored as base64. That is an ENCODING, " +
      "not encryption — it reverses with one function call. Turn encryption on and " +
      "it is sealed with your passphrase instead.";
    addKsBtn("Turn encryption on", ksOn, true);
  }
}

function addKsBtn(text, fn, primary) {
  const b = document.createElement("button");
  b.textContent = text;
  b.className = primary ? "primary" : "";
  b.type = "button";
  b.addEventListener("click", fn);
  $("#ks-buttons").appendChild(b);
}

async function ksOn() {
  const p = window.prompt("Choose a passphrase. If you lose it, nothing is destroyed " +
    "— you paste the key in again or mint a new one from your site. But we cannot " +
    "recover it for you.");
  if (!p) return;
  const again = window.prompt("Again, to be sure:");
  if (again !== p) { alert("Those do not match."); return; }
  const remember = confirm("Let THIS computer remember the unlock key, so you are " +
    "not typing a passphrase every launch?\n\nThe remembered key is machine-bound " +
    "and never travels with the folder.");
  try { await blink.call("vault_enable", p, remember); await renderKeyModal(); }
  catch (e) { alert(e.message); }
}

async function ksOff() {
  if (!confirm("The export key will be rewritten as base64 — readable by anyone " +
      "with this folder. Continue?")) return;
  try { await blink.call("vault_disable"); await renderKeyModal(); }
  catch (e) { alert(e.message); }
}

async function ksChange() {
  const old = window.prompt("Current passphrase:");
  if (!old) return;
  const nw = window.prompt("New passphrase:");
  if (!nw) return;
  const again = window.prompt("Again, to be sure:");
  if (again !== nw) { alert("Those do not match."); return; }
  try { await blink.call("vault_change", old, nw); await renderKeyModal(); }
  catch (e) { alert(e.message); }
}

async function ksUnlock() {
  const p = window.prompt("Passphrase to unlock the vault:");
  if (!p) return;
  try {
    const r = await blink.call("vault_unlock", p);
    if (r.api_key) $("#f-key").value = r.api_key;
    await renderKeyModal();
  } catch (e) { alert(e.message); }
}

document.addEventListener("DOMContentLoaded", boot);
/* ===== SNAPSMACK EOF ===== */
