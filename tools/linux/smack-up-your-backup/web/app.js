/* Smack Up Your Backup — window logic for the Chrome/Blink port.
   Every Python action is reached through blink.call(); nothing here does the
   backup work itself. Mirrors the tkinter tabs: Backup, Restore, Audit,
   Schedule, Cloud Sync, Settings, Help, plus the profile / hub / sync-job
   dialogs. Long jobs run on the Python side and are polled via job_status. */

const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

function el(tag, attrs = {}, ...kids) {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === "class") n.className = v;
    else if (k === "html") n.innerHTML = v;
    else if (k.startsWith("on") && typeof v === "function") n.addEventListener(k.slice(2), v);
    else if (v !== null && v !== undefined && v !== false) n.setAttribute(k, v);
  }
  for (const kid of kids.flat()) {
    if (kid === null || kid === undefined || kid === false) continue;
    n.appendChild(typeof kid === "string" ? document.createTextNode(kid) : kid);
  }
  return n;
}

function log(msg, kind) {
  const line = el("div", { class: "line " + (kind || "") }, String(msg));
  $("#log").prepend(line);
}

async function api(method, ...args) {
  return blink.call(method, ...args);
}

let STATE = {};           // last load_state()
let ACTIVE_TAB = "backup";
let ACTIVE_PROFILE = "";  // profile name currently selected

/* ── job polling ──────────────────────────────────────────────────────────
   startJob(jobId, {onLog, onProgress, onDone, onError, onAsk}) polls until the
   Python job reports done, forwarding new log lines / percent / a pending
   abort-or-continue question. */
function startJob(jobId, cb = {}) {
  let since = 0;
  let stopped = false;
  async function tick() {
    if (stopped) return;
    let s;
    try {
      s = await api("job_status", jobId, since);
    } catch (e) {
      cb.onError && cb.onError(e.message);
      return;
    }
    since = s.log_next;
    (s.log || []).forEach((ln) => cb.onLog && cb.onLog(ln));
    if (cb.onProgress) cb.onProgress(s.stage, s.pct, s.stats);
    if (s.ask && cb.onAsk) cb.onAsk(jobId, s.ask);
    if (s.done) {
      stopped = true;
      if (s.error) cb.onError && cb.onError(s.error);
      else cb.onDone && cb.onDone(s.result);
      return;
    }
    setTimeout(tick, 400);
  }
  tick();
  return { stop() { stopped = true; } };
}

/* ── boot ─────────────────────────────────────────────────────────────────*/
async function boot() {
  try {
    STATE = await api("load_state");
  } catch (e) {
    $("#app").textContent = "Could not load SUYB: " + e.message;
    return;
  }
  $("#version").textContent = "v" + (STATE.version || "?");

  // Credential-vault gate: if encryption is on and locked, ask to unlock first.
  if (STATE.vault && STATE.vault.enabled && !STATE.vault.unlocked) {
    await vaultUnlockGate();
  }

  ACTIVE_PROFILE = STATE.last_profile && STATE.profiles.includes(STATE.last_profile)
    ? STATE.last_profile
    : (STATE.profiles[0] || "");
  renderProfileBar();
  wireTabs();
  switchTab("backup");
}

async function refreshState() {
  STATE = await api("load_state");
  renderProfileBar();
}

function vaultUnlockGate() {
  return new Promise((resolve) => {
    openModal("Unlock SUYB", [
      el("p", { class: "muted" }, "Credential encryption is on. Enter your passphrase to unlock saved credentials."),
      field("Passphrase", "vault-pass", "", "password"),
      el("div", { class: "status-line", id: "vault-status" }),
    ], [
      { label: "Unlock", primary: true, keep: true, onClick: async () => {
        const pw = $("#vault-pass").value;
        const r = await api("unlock_vault", pw);
        if (r.ok) { closeModal(); STATE = await api("load_state"); resolve(); }
        else $("#vault-status").textContent = "That passphrase didn't match. Try again.";
      }},
    ], { noCancel: true });
  });
}

/* ── header: profile selector ─────────────────────────────────────────────*/
function renderProfileBar() {
  const sel = $("#profile-select");
  sel.innerHTML = "";
  if (!STATE.profiles.length) {
    sel.appendChild(el("option", { value: "" }, "— no profiles —"));
  }
  STATE.profiles.forEach((n) => {
    sel.appendChild(el("option", { value: n, ...(n === ACTIVE_PROFILE ? { selected: "" } : {}) }, n));
  });
  sel.value = ACTIVE_PROFILE || "";
}

function wireHeader() {
  $("#profile-select").addEventListener("change", async (e) => {
    ACTIVE_PROFILE = e.target.value;
    await api("select_profile", ACTIVE_PROFILE);
    renderActiveTab();
  });
  $("#btn-new").addEventListener("click", () => profileDialog(null));
  $("#btn-edit").addEventListener("click", async () => {
    if (!ACTIVE_PROFILE) return alert("Select a profile first.");
    const p = await api("get_profile", ACTIVE_PROFILE);
    profileDialog(p);
  });
  $("#btn-dup").addEventListener("click", async () => {
    if (!ACTIVE_PROFILE) return;
    const r = await api("duplicate_profile", ACTIVE_PROFILE, "");
    await refreshState();
    ACTIVE_PROFILE = r.name; renderProfileBar(); renderActiveTab();
    log("Duplicated profile → " + r.name, "ok");
  });
  $("#btn-del").addEventListener("click", async () => {
    if (!ACTIVE_PROFILE) return alert("Select a profile first.");
    if (!confirm(`Delete the profile "${ACTIVE_PROFILE}"?\n\nThis removes only its SUYB connection settings on this computer. Backups already saved to disk or the cloud are NOT touched.`)) return;
    const r = await api("delete_profile", ACTIVE_PROFILE);
    await refreshState();
    ACTIVE_PROFILE = r.remaining[0] || ""; renderProfileBar(); renderActiveTab();
    log("Deleted profile.", "ok");
  });
}

/* ── tab plumbing ─────────────────────────────────────────────────────────*/
function wireTabs() {
  $$(".tab").forEach((b) => b.addEventListener("click", () => switchTab(b.dataset.tab)));
}
function switchTab(tab) {
  ACTIVE_TAB = tab;
  $$(".tab").forEach((b) => b.classList.toggle("active", b.dataset.tab === tab));
  renderActiveTab();
}
function renderActiveTab() {
  const map = {
    backup: renderBackup, restore: renderRestore, audit: renderAudit,
    scheduler: renderScheduler, cloud_sync: renderCloudSync,
    settings: renderSettings, help: renderHelp,
  };
  (map[ACTIVE_TAB] || renderBackup)();
}
function setMain(...nodes) {
  const m = $("#app"); m.innerHTML = ""; nodes.flat().forEach((n) => m.appendChild(n));
}

/* ── small form helpers ───────────────────────────────────────────────────*/
function field(labelText, id, value = "", type = "text") {
  return el("label", { class: "field" },
    el("span", {}, labelText),
    el("input", { type, id, value: value == null ? "" : String(value) }));
}
function selectField(labelText, id, options, value) {
  const s = el("select", { id });
  options.forEach((o) => {
    const [val, txt] = Array.isArray(o) ? o : [o, o];
    s.appendChild(el("option", { value: val, ...(val === value ? { selected: "" } : {}) }, txt));
  });
  return el("label", { class: "field" }, el("span", {}, labelText), s);
}
function checkbox(labelText, id, checked) {
  return el("label", { class: "checkbox" },
    el("input", { type: "checkbox", id, ...(checked ? { checked: "" } : {}) }),
    el("span", {}, labelText));
}
function progressBlock(idPrefix) {
  return el("div", {},
    el("div", { class: "progress" }, el("i", { id: idPrefix + "-bar" })),
    el("div", { class: "stats" },
      el("span", { id: idPrefix + "-stage", class: "muted" }),
      el("span", { id: idPrefix + "-time", class: "muted" })));
}
function setProgress(idPrefix, stage, pct, stats) {
  const bar = $("#" + idPrefix + "-bar"); if (bar) bar.style.width = Math.round((pct || 0) * 100) + "%";
  const st = $("#" + idPrefix + "-stage"); if (st && stage) st.textContent = stage;
  if (stats) {
    const t = $("#" + idPrefix + "-time");
    if (t && stats.length >= 3) t.textContent = `${stats[0]}/${stats[1]} files · ${stats[2]} failed`;
  }
}

/* live log target inside a tab */
function tabLog(idPrefix, msg, kind) {
  const box = $("#" + idPrefix + "-log");
  if (box) box.prepend(el("div", { class: "line " + (kind || "") }, msg));
  else log(msg, kind);
}

/* handle a pending abort/continue question from a running job */
function askHandler(idPrefix) {
  let answered = false;
  return (jobId, msg) => {
    if (answered) return; answered = true;
    confirmBox("Failure during run", msg, [
      { label: "Abort", danger: true, onClick: async () => { await api("resolve_ask", jobId, false); } },
      { label: "Continue anyway", onClick: async () => { answered = false; await api("resolve_ask", jobId, true); } },
    ]);
  };
}

/* ── BACKUP TAB ───────────────────────────────────────────────────────────*/
function renderBackup() {
  const status = el("div", { class: "card" },
    el("h2", {}, "Backup"),
    el("div", { id: "bk-last", class: "muted" }, ACTIVE_PROFILE ? "Selected: " + ACTIVE_PROFILE : "No profile selected"),
    progressBlock("bk"));

  const opts = el("div", { class: "card" },
    el("div", { class: "radios" },
      el("label", {}, el("input", { type: "radio", name: "bkmode", value: "differential", checked: "" }), " Differential — skip unchanged files"),
      el("label", {}, el("input", { type: "radio", name: "bkmode", value: "full" }), " Full — re-download everything")),
    checkbox("Include SUYB settings in the ZIP", "bk-include", true),
    el("div", { class: "row" },
      el("button", { class: "primary", id: "bk-start", onclick: onStartBackup }, "▶  START BACKUP"),
      el("button", { class: "ghost", id: "bk-all", onclick: onBackupAll }, "▶  BACKUP ALL BLOGS"),
      el("button", { class: "ghost", id: "bk-cancel", disabled: "", onclick: () => currentJob && currentJob.cancel() }, "Cancel")));

  const logbox = el("div", { class: "card" }, el("div", { class: "sub" }, "Live log"), el("div", { id: "bk-log", class: "log", style: "max-height:34vh;position:static;" }));
  setMain(status, opts, logbox);
  if (ACTIVE_PROFILE) {
    api("get_profile", ACTIVE_PROFILE).then((p) => {
      $("#bk-last").textContent = `Last backup: ${p.last_backup_date || "Never"} · ${p.site_url || ""}`;
    }).catch(() => {});
  }
}

let currentJob = null;
function backupBusy(busy) {
  ["bk-start", "bk-all"].forEach((id) => { const b = $("#" + id); if (b) b.disabled = busy; });
  const c = $("#bk-cancel"); if (c) c.disabled = !busy;
}

async function onStartBackup() {
  if (!ACTIVE_PROFILE) return alert("Select or create a blog profile first.");
  const mode = ($("input[name=bkmode]:checked") || {}).value || "differential";
  const includeSettings = $("#bk-include").checked;
  try {
    const pre = await api("precheck_backup", ACTIVE_PROFILE);
    if (!pre.ok) return alert(pre.warning);
    if (pre.warning && !confirm(pre.warning)) return;
    const res = await api("check_resume", ACTIVE_PROFILE);
    let resume = false;
    if (res.found) {
      const ans = confirm(`An interrupted backup was found (started ${res.created_at}, ${res.files_downloaded} downloaded, ${res.files_skipped} skipped).\n\nOK = Resume from where it stopped\nCancel = Start fresh (deletes the checkpoint)`);
      if (ans) resume = true; else await api("clear_resume", ACTIVE_PROFILE);
    }
    backupBusy(true);
    $("#bk-log").innerHTML = "";
    const r = await api("start_backup", ACTIVE_PROFILE, mode, includeSettings, resume);
    runBackupJob(r.job_id);
  } catch (e) { alert("Could not start: " + e.message); backupBusy(false); }
}

async function onBackupAll() {
  const mode = ($("input[name=bkmode]:checked") || {}).value || "differential";
  const includeSettings = $("#bk-include").checked;
  backupBusy(true); $("#bk-log").innerHTML = "";
  try {
    const r = await api("start_backup_all", mode, includeSettings);
    runBackupJob(r.job_id, true);
  } catch (e) { alert("Could not start: " + e.message); backupBusy(false); }
}

function runBackupJob(jobId, isAll) {
  currentJob = startJob(jobId, {
    onLog: (m) => tabLog("bk", m),
    onProgress: (s, p, st) => setProgress("bk", s, p, st),
    onAsk: askHandler("bk"),
    onDone: (res) => {
      backupBusy(false); setProgress("bk", "Done.", 1);
      if (isAll) tabLog("bk", `Finished: ${res.ok} ok, ${res.failed} failed, ${res.skipped} skipped`, res.failed ? "err" : "ok");
      else tabLog("bk", res.success ? `✓ ${res.files_downloaded} downloaded, ${res.files_skipped} skipped, ${res.files_failed} failed` : "✗ Backup failed", res.success ? "ok" : "err");
      if (res.errors) res.errors.slice(0, 10).forEach((er) => tabLog("bk", "✗ " + er, "err"));
      renderProfileBar();
    },
    onError: (m) => { backupBusy(false); tabLog("bk", "✗ " + m, "err"); },
  });
}

/* ── RESTORE TAB ──────────────────────────────────────────────────────────*/
let restoreCloudId = "";
function renderRestore() {
  restoreCloudId = "";
  const src = el("div", { class: "card" },
    el("h2", {}, "Restore"),
    el("div", { class: "sub" }, "Upload files from a backup package back to your server. Each file is SHA-256 verified before upload."),
    selectField("Source", "rs-source", [["local", "Local ZIP package"], ["cloud", "Cloud package"], ["manual", "Recovery kit + media folder"]], "local"),
    el("div", { id: "rs-fields" }));

  const actions = el("div", { class: "card" },
    progressBlock("rs"),
    el("div", { class: "row" },
      el("button", { class: "primary", id: "rs-start", onclick: onStartRestore }, "▶  START RESTORE"),
      el("button", { class: "ghost", id: "rs-cancel", disabled: "", onclick: () => currentJob && currentJob.cancel() }, "Cancel")),
    el("div", { id: "rs-log", class: "log", style: "max-height:28vh;position:static;margin-top:10px;" }));
  setMain(src, actions);
  $("#rs-source").addEventListener("change", renderRestoreFields);
  renderRestoreFields();
}
function renderRestoreFields() {
  const s = $("#rs-source").value;
  const host = $("#rs-fields"); host.innerHTML = "";
  if (s === "local") {
    host.appendChild(field("Backup package ZIP (full path)", "rs-zip", ""));
    host.appendChild(el("div", { class: "hint" }, "Enter the full path to a .zip package on this computer."));
  } else if (s === "cloud") {
    host.appendChild(el("div", { class: "row" },
      el("button", { class: "ghost", onclick: onBrowseCloud }, "Browse cloud packages…"),
      el("span", { id: "rs-cloud-sel", class: "muted" }, "No package selected")));
  } else {
    host.appendChild(field("Recovery kit (.tar.gz, full path)", "rs-kit", ""));
    host.appendChild(field("Media folder (full path)", "rs-media", ""));
  }
}
async function onBrowseCloud() {
  if (!ACTIVE_PROFILE) return alert("Select a blog profile first.");
  let backups;
  try { backups = await api("list_cloud_backups", ACTIVE_PROFILE); }
  catch (e) { return alert(e.message); }
  if (!backups.length) return alert("No backup ZIPs found in the configured cloud folder.");
  const list = el("div", {});
  backups.forEach((b) => {
    list.appendChild(el("div", { class: "row", style: "justify-content:space-between;border-bottom:1px solid var(--border);padding:6px 0;" },
      el("span", {}, b.name || b.id),
      el("button", { class: "ghost", onclick: () => { restoreCloudId = b.id; $("#rs-cloud-sel").textContent = b.name || b.id; $("#rs-cloud-sel").className = ""; closeModal(); } }, "Select")));
  });
  openModal("Cloud backup packages", [list], []);
}
async function onStartRestore() {
  if (!ACTIVE_PROFILE) return alert("Select a blog profile first.");
  const source = $("#rs-source").value;
  const args = { zip_path: "", file_id: "", kit_path: "", media_dir: "" };
  if (source === "local") args.zip_path = $("#rs-zip").value.trim();
  else if (source === "cloud") { if (!restoreCloudId) return alert("Browse cloud and select a package."); args.file_id = restoreCloudId; }
  else { args.kit_path = $("#rs-kit").value.trim(); args.media_dir = $("#rs-media").value.trim(); }
  $("#rs-start").disabled = true; $("#rs-cancel").disabled = false; $("#rs-log").innerHTML = "";
  try {
    const r = await api("start_restore", ACTIVE_PROFILE, source, args.zip_path, args.file_id, args.kit_path, args.media_dir);
    currentJob = startJob(r.job_id, {
      onLog: (m) => tabLog("rs", m),
      onProgress: (s, p) => setProgress("rs", s, p),
      onDone: (res) => {
        $("#rs-start").disabled = false; $("#rs-cancel").disabled = true; setProgress("rs", "Done.", 1);
        if (res.success) tabLog("rs", `✓ ${res.uploaded} uploaded, ${res.skipped} skipped, ${res.failed} failed`, "ok");
        else { tabLog("rs", "✗ Restore failed", "err"); (res.errors || []).slice(0, 10).forEach((e) => tabLog("rs", "✗ " + e, "err")); }
      },
      onError: (m) => { $("#rs-start").disabled = false; $("#rs-cancel").disabled = true; tabLog("rs", "✗ " + m, "err"); },
    });
  } catch (e) { $("#rs-start").disabled = false; $("#rs-cancel").disabled = true; alert(e.message); }
}

/* ── AUDIT TAB ────────────────────────────────────────────────────────────*/
function renderAudit() {
  const card = el("div", { class: "card" },
    el("h2", {}, "Audit & Coverage"),
    el("div", { class: "sub" }, "Server audit compares the manifest, the FTP filesystem, and the database. Coverage scans your local backup ZIPs; de-dupe rewrites over-backed ZIPs."),
    el("div", { class: "row" },
      el("button", { id: "au-run", onclick: onAudit }, "Run Server Audit"),
      el("button", { class: "ghost", id: "au-cov", onclick: onCoverage }, "Run Coverage Check"),
      el("button", { class: "ghost", id: "au-dedup", disabled: "", onclick: onDedupe }, "Clean Duplicates")),
    progressBlock("au"));
  const results = el("div", { class: "card" }, el("div", { class: "sub" }, "Results"), el("pre", { class: "result", id: "au-result" }, "—"));
  setMain(card, results);
}
function auditBusy(b) { ["au-run", "au-cov"].forEach((id) => { const e = $("#" + id); if (e) e.disabled = b; }); }
async function onAudit() {
  if (!ACTIVE_PROFILE) return alert("Select a blog profile first.");
  auditBusy(true); $("#au-result").textContent = "";
  try {
    const r = await api("start_audit", ACTIVE_PROFILE);
    startJob(r.job_id, {
      onProgress: (s, p) => setProgress("au", s, p),
      onLog: (m) => log(m),
      onDone: (rep) => { auditBusy(false); setProgress("au", "Audit complete.", 1); $("#au-result").textContent = renderAuditReport(rep); },
      onError: (m) => { auditBusy(false); $("#au-result").textContent = "✗ " + m; },
    });
  } catch (e) { auditBusy(false); alert(e.message); }
}
async function onCoverage() {
  if (!ACTIVE_PROFILE) return alert("Select a blog profile first.");
  auditBusy(true); $("#au-result").textContent = "";
  try {
    const r = await api("start_coverage", ACTIVE_PROFILE);
    startJob(r.job_id, {
      onProgress: (s, p) => setProgress("au", s, p),
      onLog: (m) => log(m),
      onDone: (rep) => {
        auditBusy(false); setProgress("au", "Coverage complete.", 1);
        $("#au-result").textContent = renderCoverageReport(rep);
        $("#au-dedup").disabled = !((rep.summary || {}).over_backed > 0);
      },
      onError: (m) => { auditBusy(false); $("#au-result").textContent = "✗ " + m; },
    });
  } catch (e) { auditBusy(false); alert(e.message); }
}
async function onDedupe() {
  if (!confirm("Rewrite over-backed ZIPs in place, keeping each file only in its newest ZIP? This cannot be undone.")) return;
  $("#au-dedup").disabled = true; $("#au-result").textContent = "";
  try {
    const r = await api("start_dedupe", ACTIVE_PROFILE);
    startJob(r.job_id, {
      onProgress: (s, p) => setProgress("au", s, p),
      onLog: (m) => log(m),
      onDone: (res) => { setProgress("au", "De-dupe complete.", 1); $("#au-result").textContent = `Removed ${res.total_removed} duplicate entries, saved ${fmtBytes(res.total_saved)}.\n\n` + (res.zips_modified || []).map((z) => `${z.zip_name}: -${z.entries_removed} entries, -${fmtBytes(z.bytes_saved)}${z.ok ? "" : " (ERROR: " + z.error + ")"}`).join("\n"); },
      onError: (m) => { $("#au-result").textContent = "✗ " + m; },
    });
  } catch (e) { alert(e.message); }
}
function renderAuditReport(r) {
  const s = r.summary || {};
  const lines = [`Site: ${r.site_name || ""}  (${r.site_url || ""})`, `Audited: ${r.audit_date || ""}`, ""];
  const labels = { healthy: "✓ Healthy", missing_from_server: "✗ Missing from server", orphaned_on_server: "✗ Orphaned on server", size_mismatch: "✗ Size mismatch", wrong_location: "✗ Wrong location", not_in_db: "✗ Not in database", orphaned_in_db: "✗ Orphaned in DB" };
  Object.keys(labels).forEach((k) => { if (s[k]) lines.push(`${labels[k]}: ${s[k]}`); });
  lines.push("", "── Issues ──");
  (r.entries || []).filter((e) => e.category !== "healthy").slice(0, 300).forEach((e) => lines.push(`[${e.category}] ${e.manifest_key}${e.note ? " — " + e.note : ""}`));
  return lines.join("\n");
}
function renderCoverageReport(r) {
  const s = r.summary || {};
  const lines = [`Site: ${r.site_name || ""}`, `Backup dir: ${r.backup_dir || ""}`, `ZIPs scanned: ${(r.zips_scanned || []).length}`, "",
    `✓ Covered (in one ZIP): ${s.covered || 0}`, `⚠ Over-backed (in 2+ ZIPs): ${s.over_backed || 0}`, `✗ Never backed up: ${s.never_backed || 0}`, "", "── Not covered ──"];
  (r.entries || []).filter((e) => e.status === "never_backed").slice(0, 300).forEach((e) => lines.push("✗ " + e.manifest_key));
  return lines.join("\n");
}

/* ── SCHEDULER TAB ────────────────────────────────────────────────────────*/
const DAYS = ["monday", "tuesday", "wednesday", "thursday", "friday", "saturday", "sunday"];
async function renderScheduler() {
  const rows = await api("list_schedules");
  const gs = await api("global_schedule_state");
  const table = el("table", {},
    el("thead", {}, el("tr", {}, ...["Blog", "On", "Frequency", "Day", "Time", "Last run", "Next run", ""].map((h) => el("th", {}, h)))),
    el("tbody", {}));
  const tbody = table.querySelector("tbody");
  rows.forEach((r) => {
    const nextCell = el("td", { class: r.schedule_enabled ? "ok" : "muted" }, r.next_run);
    const enChk = el("input", { type: "checkbox", ...(r.schedule_enabled ? { checked: "" } : {}) });
    enChk.addEventListener("change", async () => { const res = await api("save_schedule_field", r.name, "schedule_enabled", enChk.checked); nextCell.textContent = res.next_run; nextCell.className = res.enabled ? "ok" : "muted"; });
    const freq = el("select", {}, ...["daily", "weekly"].map((f) => el("option", { value: f, ...(f === r.schedule_type ? { selected: "" } : {}) }, f)));
    freq.addEventListener("change", async () => { const res = await api("save_schedule_field", r.name, "schedule_type", freq.value); nextCell.textContent = res.next_run; });
    const day = el("select", {}, ...DAYS.map((d) => el("option", { value: d, ...(d === r.schedule_day ? { selected: "" } : {}) }, d)));
    day.addEventListener("change", async () => { const res = await api("save_schedule_field", r.name, "schedule_day", day.value); nextCell.textContent = res.next_run; });
    const time = el("input", { type: "text", value: r.schedule_time, style: "width:70px;" });
    time.addEventListener("change", async () => { const res = await api("save_schedule_field", r.name, "schedule_time", time.value); nextCell.textContent = res.next_run; });
    tbody.appendChild(el("tr", {},
      el("td", {}, el("div", {}, r.name), el("div", { class: "muted", style: "font-size:11px;" }, r.site_url)),
      el("td", {}, enChk), el("td", {}, freq), el("td", {}, day), el("td", {}, time),
      el("td", { class: "muted" }, r.last_scheduled_run), nextCell,
      el("td", {}, el("button", { class: "ghost", onclick: () => runNow(r.name) }, "Run Now"))));
  });

  const globalCard = el("div", { class: "card" },
    el("h2", {}, "Automatic Backups (system schedule)"),
    el("div", { class: "sub" }, "Register an OS-level daily task that backs up every blog even when SUYB is closed."),
    checkbox("Enable the daily system backup task", "sch-global", gs.enabled),
    field("Time (HH:MM, 24-hour)", "sch-global-time", "02:00"),
    el("div", { class: "status-line", id: "sch-global-status" }));
  $("#sch-global") && null;
  const card = el("div", { class: "card" }, el("h2", {}, "Per-blog schedule"),
    el("div", { class: "sub" }, "Changes save automatically. SUYB must be running for these to fire."),
    rows.length ? table : el("div", { class: "muted" }, "No profiles configured yet."));
  setMain(card, globalCard);
  $("#sch-global").addEventListener("change", async () => {
    const r = await api("set_global_schedule", $("#sch-global").checked, $("#sch-global-time").value);
    $("#sch-global-status").textContent = r.msg;
    if (!r.ok) $("#sch-global").checked = !$("#sch-global").checked;
  });
}
async function runNow(name) {
  const r = await api("start_backup", name, "differential", true, false);
  switchTab("backup");
  setTimeout(() => runBackupJob(r.job_id), 50);
  backupBusy(true);
}

/* ── CLOUD SYNC TAB ───────────────────────────────────────────────────────*/
let syncSelected = "";
async function renderCloudSync() {
  const jobs = await api("list_sync_jobs");
  syncSelected = jobs.includes(syncSelected) ? syncSelected : (jobs[0] || "");
  const sel = el("select", { id: "cs-job" }, ...jobs.map((j) => el("option", { value: j, ...(j === syncSelected ? { selected: "" } : {}) }, j)));
  if (!jobs.length) sel.appendChild(el("option", { value: "" }, "— no sync jobs —"));
  sel.addEventListener("change", () => { syncSelected = sel.value; });
  const bar = el("div", { class: "card" },
    el("h2", {}, "Cloud-to-Cloud Sync"),
    el("div", { class: "sub" }, "Differential file sync between cloud stores (e.g. Google Drive → Backblaze B2)."),
    el("div", { class: "row" }, el("span", { class: "muted" }, "Sync job:"), sel,
      el("button", { class: "ghost", onclick: () => syncJobDialog(null) }, "New"),
      el("button", { class: "ghost", onclick: onEditSyncJob }, "Edit"),
      el("button", { class: "ghost danger-text", onclick: onDeleteSyncJob }, "Delete")));
  const run = el("div", { class: "card" },
    progressBlock("cs"),
    el("div", { class: "row" },
      el("button", { class: "primary", id: "cs-start", onclick: onStartSync }, "▶  RUN SYNC"),
      el("button", { class: "ghost", id: "cs-cancel", disabled: "", onclick: () => currentJob && currentJob.cancel() }, "Cancel")),
    el("div", { id: "cs-log", class: "log", style: "max-height:28vh;position:static;margin-top:10px;" }));
  setMain(bar, run);
}
async function onEditSyncJob() { if (!syncSelected) return; const j = await api("get_sync_job", syncSelected); syncJobDialog(j); }
async function onDeleteSyncJob() {
  if (!syncSelected) return;
  if (!confirm(`Delete sync job "${syncSelected}"?`)) return;
  await api("delete_sync_job", syncSelected); syncSelected = ""; renderCloudSync();
}
async function onStartSync() {
  if (!syncSelected) return alert("Select or create a sync job first.");
  $("#cs-start").disabled = true; $("#cs-cancel").disabled = false; $("#cs-log").innerHTML = "";
  try {
    const r = await api("start_sync", syncSelected);
    currentJob = startJob(r.job_id, {
      onLog: (m) => tabLog("cs", m),
      onProgress: (s, p, st) => setProgress("cs", s, p, st),
      onAsk: askHandler("cs"),
      onDone: (res) => { $("#cs-start").disabled = false; $("#cs-cancel").disabled = true; setProgress("cs", "Sync complete.", 1); tabLog("cs", "✓ Sync finished.", "ok"); },
      onError: (m) => { $("#cs-start").disabled = false; $("#cs-cancel").disabled = true; tabLog("cs", "✗ " + m, "err"); },
    });
  } catch (e) { $("#cs-start").disabled = false; $("#cs-cancel").disabled = true; alert(e.message); }
}

/* ── SETTINGS TAB ─────────────────────────────────────────────────────────*/
async function renderSettings() {
  const s = await api("get_settings");
  const gc = s.global_cloud || {};
  const pac = s.pacing || {};
  const v = s.vault || {};

  const cloud = el("div", { class: "card" },
    el("h2", {}, "Global Cloud Config"),
    el("div", { class: "sub" }, "Default cloud target for every backup. Point it at a Google Drive OAuth client-secret JSON and authenticate once."),
    selectField("Provider", "gc-provider", [["google_drive", "google_drive"], ["none", "none"]], gc.cloud_provider || "none"),
    field("Credentials JSON (full path)", "gc-creds", gc.cloud_credentials_file || ""),
    el("div", { class: "row" },
      el("button", { class: "ghost", onclick: onValidateKey }, "Validate key"),
      el("button", { class: "ghost", id: "gc-auth", onclick: onAuthKey }, "Authenticate with Google")),
    el("div", { class: "status-line", id: "gc-status" }),
    field("Cloud folder ID", "gc-folder", gc.cloud_folder_id || ""),
    el("div", { class: "row" }, el("button", { onclick: onSaveSettings }, "Save Defaults")));

  const pacing = el("div", { class: "card" },
    el("h2", {}, "Pacing defaults"),
    el("div", { class: "grid2" },
      field("Transfer delay (seconds)", "pc-delay", pac.transfer_delay || "2", "number"),
      field("Batch size (0 = unlimited)", "pc-batch", pac.batch_size || "0", "number")));

  const hub = el("div", { class: "card" },
    el("h2", {}, "Discover from Hub"),
    el("div", { class: "sub" }, "Pull every spoke blog from a hub, fill each key, and point them all at the Global Cloud Config. Hub URL + key come from The Hub's shared store."),
    el("div", { class: "row" }, el("button", { class: "ghost", onclick: hubDialog }, "Discover from Hub…"),
      el("button", { class: "ghost", onclick: onPullCloud }, "Pull cloud config from current blog")),
    el("div", { class: "status-line", id: "hub-status" }));

  const creds = credLibraryCard(s.creds || []);
  const vault = vaultCard(v);
  const ai = el("div", { class: "card" },
    el("h2", {}, "AI file matching (optional)"),
    el("div", { class: "status-line", id: "ai-status" }, "Checking…"),
    el("div", { class: "row" }, el("button", { class: "ghost", onclick: onInstallAI }, "Install AI matcher")));
  const io = el("div", { class: "card" },
    el("h2", {}, "Backup / restore SUYB settings"),
    el("div", { class: "sub" }, "Export every profile + global config to JSON, or paste an export back in."),
    el("div", { class: "row" }, el("button", { class: "ghost", onclick: onExport }, "Export settings"),
      el("button", { class: "ghost", onclick: onImport }, "Import settings")),
    el("textarea", { id: "io-text", placeholder: "Export JSON appears here / paste an export to import." }));

  setMain(cloud, pacing, hub, creds, vault, ai, io);
  api("ai_status").then((r) => { $("#ai-status").textContent = r.status; }).catch(() => {});
}
async function onSaveSettings() {
  await api("save_settings",
    { transfer_delay: $("#pc-delay").value, batch_size: $("#pc-batch").value },
    { cloud_provider: $("#gc-provider").value, cloud_credentials_file: $("#gc-creds").value.trim(), cloud_folder_id: $("#gc-folder").value.trim() });
  $("#gc-status").textContent = "Saved global defaults.";
  await refreshState();
}
async function onValidateKey() {
  const r = await api("validate_cloud_key", $("#gc-creds").value.trim());
  $("#gc-status").textContent = r.status;
  $("#gc-auth").style.display = r.is_oauth ? "" : "none";
}
async function onAuthKey() {
  $("#gc-status").textContent = "Opening browser for Google login…";
  const r = await api("authenticate_oauth", $("#gc-creds").value.trim());
  $("#gc-status").textContent = r.msg;
}
async function onPullCloud() {
  if (!ACTIVE_PROFILE) return alert("Select a profile first.");
  $("#hub-status").textContent = "Connecting to blog…";
  try {
    const p = await api("get_profile", ACTIVE_PROFILE);
    const data = await api("pull_cloud_config", p);
    const c = data.cloud_config || {};
    $("#hub-status").textContent = `Pulled: provider=${c.provider || "none"}, folder=${c.folder_id || "—"}, site=${data.site_name || ""}`;
  } catch (e) { $("#hub-status").textContent = "Error: " + e.message; }
}
function credLibraryCard(creds) {
  const list = el("div", { id: "cred-list" });
  const render = (arr) => {
    list.innerHTML = "";
    if (!arr.length) list.appendChild(el("div", { class: "muted" }, "No named credentials yet."));
    arr.forEach((c) => list.appendChild(el("div", { class: "row", style: "justify-content:space-between;border-bottom:1px solid var(--border);padding:6px 0;" },
      el("span", {}, el("b", {}, c.name), el("span", { class: "muted", style: "margin-left:8px;font-size:11px;" }, c.path)),
      el("button", { class: "ghost danger-text", onclick: async () => { const r = await api("remove_cred", c.name); render(r.creds); } }, "Remove"))));
  };
  render(creds);
  return el("div", { class: "card" },
    el("h2", {}, "Credential Library"),
    el("div", { class: "sub" }, "Register a credentials JSON once under a friendly name; every profile and sync job can pick it."),
    list,
    el("div", { class: "row" }, field("Name", "cred-name", ""), field("Path", "cred-path", ""),
      el("button", { class: "ghost", onclick: async () => { const r = await api("add_cred", $("#cred-name").value.trim(), $("#cred-path").value.trim()).catch((e) => alert(e.message)); if (r) render(r.creds); } }, "Add")));
}
function vaultCard(v) {
  const body = el("div", {});
  const render = (v) => {
    body.innerHTML = "";
    body.appendChild(el("div", { class: "muted", style: "margin-bottom:8px;" },
      `Status: ${v.enabled ? "ENABLED" : "off"} · ${v.unlocked ? "unlocked" : "locked"} · crypto ${v.crypto ? "available" : "MISSING"} · keychain ${v.keychain ? "available" : "none"}`));
    if (!v.enabled) {
      body.appendChild(el("div", { class: "row" },
        field("New passphrase", "enc-pass", "", "password"),
        checkbox("Allow unattended (scheduled) backups on this machine", "enc-mk", false),
        el("button", { onclick: async () => { try { const r = await api("enc_enable", $("#enc-pass").value, $("#enc-mk").checked); render(r.vault); } catch (e) { alert(e.message); } } }, "Enable encryption")));
    } else {
      body.appendChild(el("div", { class: "row" },
        field("Old passphrase", "enc-old", "", "password"),
        field("New passphrase", "enc-new", "", "password"),
        el("button", { class: "ghost", onclick: async () => { try { const r = await api("enc_change", $("#enc-old").value, $("#enc-new").value); render(r.vault); alert(r.ok ? "Passphrase changed." : "Old passphrase did not match."); } catch (e) { alert(e.message); } } }, "Change passphrase")));
      body.appendChild(el("div", { class: "row" },
        checkbox("Unattended key cached on this machine", "enc-mk2", v.machine_key),
        el("button", { class: "ghost danger-text", onclick: async () => { if (!confirm("Disable encryption? Credentials return to base64 (not encrypted).")) return; const r = await api("enc_disable"); render(r.vault); } }, "Disable encryption")));
      const mk = body.querySelector("#enc-mk2");
      mk.addEventListener("change", async () => { const r = await api("enc_toggle_machine_key", mk.checked); render(r.vault); });
    }
  };
  render(v);
  return el("div", { class: "card" }, el("h2", {}, "Credential Encryption"),
    el("div", { class: "sub" }, "Encrypt FTP/admin passwords, API keys and cloud tokens with a passphrase. No recovery if forgotten."), body);
}
async function onInstallAI() {
  $("#ai-status").textContent = "Installing…";
  const r = await api("install_ai");
  startJob(r.job_id, { onProgress: (s) => { if (s) $("#ai-status").textContent = s; }, onDone: async () => { const st = await api("ai_status"); $("#ai-status").textContent = st.status; }, onError: (m) => { $("#ai-status").textContent = "✗ " + m; } });
}
async function onExport() { const data = await api("export_settings"); $("#io-text").value = JSON.stringify(data, null, 2); }
async function onImport() {
  let data; try { data = JSON.parse($("#io-text").value); } catch (e) { return alert("Paste a valid settings export JSON first."); }
  try { const r = await api("import_settings", data); await refreshState(); ACTIVE_PROFILE = STATE.profiles[0] || ""; renderProfileBar(); alert(`Imported ${r.imported} profile(s).`); }
  catch (e) { alert(e.message); }
}

/* ── HELP TAB ─────────────────────────────────────────────────────────────*/
function renderHelp() {
  const host = el("div", { class: "card" }, el("h2", {}, "Help"));
  HELP_TOPICS.forEach(([title, body]) => {
    host.appendChild(el("details", { class: "help-topic" }, el("summary", {}, title), el("p", {}, body.trim())));
  });
  setMain(host);
}

/* ── PROFILE DIALOG ───────────────────────────────────────────────────────*/
async function profileDialog(existing) {
  const p = existing || await api("new_profile_template");
  const isNew = !existing;
  const body = [
    el("div", { class: "section-h" }, "Site"),
    field("Blog name", "p-name", p.name),
    field("Site URL", "p-site_url", p.site_url),
    field("API key (scoped 'suyb' key — preferred)", "p-api_key", p.api_key),
    field("Login slug", "p-login_slug", p.login_slug || "snap-in"),
    el("div", { class: "section-h" }, "FTP / SFTP"),
    selectField("Protocol", "p-transport", [["http", "http (pull via suyb-export, no FTP creds)"], ["ftp", "ftp"], ["sftp", "sftp"]], p.transport || "http"),
    el("div", { class: "grid2" }, field("Host", "p-ftp_host", p.ftp_host), field("Port", "p-ftp_port", p.ftp_port, "number")),
    el("div", { class: "grid2" }, field("Username", "p-ftp_user", p.ftp_user), field("Password", "p-ftp_pass", p.ftp_pass, "password")),
    field("Remote directory", "p-ftp_remote_dir", p.ftp_remote_dir || "/"),
    checkbox("Use FTP over TLS (FTP_TLS)", "p-ftp_ssl", p.ftp_ssl),
    checkbox("Verify TLS certificate", "p-ftp_verify_cert", p.ftp_verify_cert),
    el("div", { class: "section-h" }, "SnapSmack Admin"),
    el("div", { class: "grid2" }, field("Admin username", "p-snap_admin_user", p.snap_admin_user), field("Admin password", "p-snap_admin_pass", p.snap_admin_pass, "password")),
    el("div", { class: "section-h" }, "Cloud"),
    selectField("Provider", "p-cloud_provider", [["google_drive", "google_drive"], ["none", "none"]], p.cloud_provider || "none"),
    field("Credentials override (optional path)", "p-cloud_credentials_file", p.cloud_credentials_file),
    field("Cloud folder ID", "p-cloud_folder_id", p.cloud_folder_id),
    el("div", { class: "section-h" }, "Backup"),
    selectField("Backup method", "p-backup_method", [["cloud", "cloud (FTP + cloud upload)"], ["ftp", "ftp (media only)"], ["local", "local (kit + SQL only)"]], p.backup_method || "cloud"),
    field("Local backup directory", "p-backup_dir", p.backup_dir),
    el("div", { class: "grid2" }, field("Transfer delay (pacing)", "p-pacing_delay", p.pacing_delay, "number"), field("Batch size (0=unlimited)", "p-batch_size", p.batch_size, "number")),
    el("div", { class: "section-h" }, "Connection tests"),
    el("div", { class: "row" },
      el("button", { class: "ghost", onclick: () => runProfileTest("test_login") }, "Test Login"),
      el("button", { class: "ghost", onclick: () => runProfileTest("test_conn") }, "Test FTP/SFTP"),
      el("button", { class: "ghost", onclick: () => runProfileTest("test_cloud") }, "Test Cloud")),
    el("div", { class: "status-line", id: "p-test-status" }),
  ];
  openModal(isNew ? "New Blog Profile" : "Edit Profile", body, [
    { label: "Save", primary: true, keep: true, onClick: async () => {
      const data = collectProfile(p);
      if (!data.name.trim()) return alert("Blog name is required.");
      try { const r = await api("save_profile", data); closeModal(); await refreshState(); ACTIVE_PROFILE = r.name; renderProfileBar(); renderActiveTab(); log("Saved profile: " + r.name, "ok"); }
      catch (e) { alert(e.message); }
    }},
  ]);
}
function collectProfile(base) {
  const g = (id) => { const e = $("#p-" + id); return e ? (e.type === "checkbox" ? e.checked : e.value) : base[id]; };
  const out = Object.assign({}, base);
  ["name", "site_url", "api_key", "login_slug", "transport", "ftp_host", "ftp_port", "ftp_user", "ftp_pass",
   "ftp_remote_dir", "ftp_ssl", "ftp_verify_cert", "snap_admin_user", "snap_admin_pass", "cloud_provider",
   "cloud_credentials_file", "cloud_folder_id", "backup_method", "backup_dir", "pacing_delay", "batch_size"]
    .forEach((k) => { out[k] = g(k); });
  return out;
}
async function runProfileTest(method) {
  const data = collectProfile(await api("new_profile_template"));
  $("#p-test-status").textContent = "Testing…";
  try {
    const r = await api(method, data);
    if (method === "test_cloud") { $("#p-test-status").textContent = r.msg; if (r.folder_id) $("#p-cloud_folder_id").value = r.folder_id; }
    else $("#p-test-status").textContent = r.msg;
  } catch (e) { $("#p-test-status").textContent = "✗ " + e.message; }
}

/* ── HUB DISCOVERY DIALOG ─────────────────────────────────────────────────*/
async function hubDialog() {
  const hub = await api("get_hub_creds");
  const stg = await api("default_staging_dir");
  openModal("Discover from Hub", [
    el("p", { class: "muted" }, "SUYB pulls every spoke, fills each key, and points them all at your Global Cloud Config."),
    field("Hub URL", "hub-url", hub.url || ""),
    field("Hub API key", "hub-key", hub.key || "", "password"),
    field("Backup base directory", "hub-dir", stg.dir),
    progressBlock("hub"),
    el("div", { id: "hub-log", class: "log", style: "max-height:20vh;position:static;margin-top:8px;" }),
  ], [
    { label: "Discover", primary: true, keep: true, onClick: async () => {
      const url = $("#hub-url").value.trim(), key = $("#hub-key").value.trim(), dir = $("#hub-dir").value.trim();
      if (!url || !key) return alert("Hub URL and API key are required.");
      try {
        const r = await api("discover_hub", url, key, dir);
        startJob(r.job_id, {
          onLog: (m) => tabLog("hub", m),
          onProgress: (s, p) => setProgress("hub", s, p),
          onDone: async (res) => { setProgress("hub", "Done.", 1); tabLog("hub", `Created ${res.created}, skipped ${res.skipped}.`, "ok"); await refreshState(); renderProfileBar(); },
          onError: (m) => tabLog("hub", "✗ " + m, "err"),
        });
      } catch (e) { alert(e.message); }
    }},
  ]);
}

/* ── SYNC JOB DIALOG ──────────────────────────────────────────────────────*/
async function syncJobDialog(existing) {
  const j = existing || await api("new_sync_job_template");
  const providers = [["google_drive", "google_drive"], ["box", "box"], ["backblaze_b2", "backblaze_b2"]];
  const endpoint = (side, label) => [
    el("div", { class: "section-h" }, label),
    selectField("Provider", `j-${side}_provider`, providers, j[`${side}_provider`]),
    field("Credentials file (OAuth JSON, for Drive/Box)", `j-${side}_credentials_file`, j[`${side}_credentials_file`]),
    field("Folder ID / bucket name", `j-${side}_folder`, j[`${side}_folder`]),
    el("div", { class: "grid2" }, field("B2 Key ID", `j-${side}_b2_key_id`, j[`${side}_b2_key_id`]), field("B2 App Key", `j-${side}_b2_app_key`, j[`${side}_b2_app_key`], "password")),
  ];
  openModal(existing ? "Edit Sync Job" : "New Sync Job", [
    field("Job name", "j-name", j.name),
    ...endpoint("source", "Source"),
    ...endpoint("dest", "Destination"),
    el("div", { class: "row" }, el("button", { class: "ghost", onclick: onTestB2Dest }, "Test destination B2"), el("span", { class: "status-line", id: "j-b2-status" })),
  ], [
    { label: "Save", primary: true, keep: true, onClick: async () => {
      const g = (id) => { const e = $("#j-" + id); return e ? e.value : j[id]; };
      const out = Object.assign({}, j);
      ["name", "source_provider", "source_credentials_file", "source_folder", "source_b2_key_id", "source_b2_app_key",
       "dest_provider", "dest_credentials_file", "dest_folder", "dest_b2_key_id", "dest_b2_app_key"].forEach((k) => { out[k] = g(k); });
      if (!out.name.trim()) return alert("Job name is required.");
      try { await api("save_sync_job", out); syncSelected = out.name; closeModal(); renderCloudSync(); }
      catch (e) { alert(e.message); }
    }},
  ]);
}
async function onTestB2Dest() {
  $("#j-b2-status").textContent = "Testing…";
  const r = await api("test_b2", $("#j-dest_b2_key_id").value, $("#j-dest_b2_app_key").value, $("#j-dest_folder").value, "");
  $("#j-b2-status").textContent = r.msg;
}

/* ── MODAL / CONFIRM PRIMITIVES ───────────────────────────────────────────*/
function openModal(title, bodyNodes, actions, opts = {}) {
  const modal = el("div", { class: "modal" }, el("h2", {}, title), ...bodyNodes);
  const bar = el("div", { class: "modal-actions" });
  if (!opts.noCancel) bar.appendChild(el("button", { class: "ghost", onclick: closeModal }, "Cancel"));
  (actions || []).forEach((a) => {
    bar.appendChild(el("button", { class: a.primary ? "primary" : (a.danger ? "danger" : "ghost"), onclick: async () => { await a.onClick(); if (!a.keep) closeModal(); } }, a.label));
  });
  modal.appendChild(bar);
  $("#modal-root").innerHTML = "";
  $("#modal-root").appendChild(el("div", { class: "modal-backdrop" }, modal));
}
function closeModal() { $("#modal-root").innerHTML = ""; }
function confirmBox(title, msg, actions) {
  openModal(title, [el("p", { style: "white-space:pre-wrap;" }, msg)], actions.map((a) => ({ ...a, keep: false })), { noCancel: false });
}

/* ── utils ────────────────────────────────────────────────────────────────*/
function fmtBytes(n) {
  n = Number(n) || 0;
  const u = ["B", "KB", "MB", "GB", "TB"]; let i = 0;
  while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
  return n.toFixed(i ? 1 : 0) + " " + u[i];
}

/* ── help content (mirrors main.HELP_TOPICS) ──────────────────────────────*/
const HELP_TOPICS = [
  ["What does SUYB do?", "Smack Up Your Backup downloads a complete backup of your SnapSmack blog and packages it into a single dated ZIP file: login, recovery kit (manifest), SQL dumps, media download (differential skips unchanged files), package, cloud push, and verify. Every downloaded file is SHA-256 verified against the manifest."],
  ["First-time setup", "Create a profile with + New (top right): blog name, site URL, and either a scoped 'suyb' API key or admin username/password. Choose a backup method — cloud (FTP + cloud upload), ftp (media only), or local (kit + SQL only). Set a local backup directory, then Save. Or use Settings → Discover from Hub to pull every spoke at once."],
  ["Backup tab", "Select a blog, then START BACKUP. Differential downloads only changed files; Full re-downloads everything. Include SUYB settings bundles your profile config into the ZIP. BACKUP ALL BLOGS runs a differential backup for every profile in turn. An interrupted backup can be resumed on the next run."],
  ["Restore tab", "Restore uploads files from a backup package back to your server. Sources: a local ZIP, a cloud package (Browse cloud), or a bare recovery kit + a media folder. Each file's SHA-256 is verified before upload so a corrupt local file never overwrites a good server copy."],
  ["Audit tab", "Server Audit compares the manifest, the FTP filesystem, and the database image records, categorising each file (healthy, missing, orphaned, size mismatch, wrong location, not in DB). Coverage Check scans your local backup ZIPs; Clean Duplicates rewrites over-backed ZIPs so each file lives in exactly one ZIP."],
  ["Schedule tab", "Per-blog schedules save automatically (frequency, day, time). SUYB must be running for these to fire. The system schedule registers an OS-level daily task that backs up every blog even when SUYB is closed."],
  ["Cloud Sync tab", "Differential file sync between two cloud stores (e.g. Google Drive → Backblaze B2). Create a sync job with source and destination endpoints, then RUN SYNC. Only changed files transfer."],
  ["Credential encryption", "By default passwords are stored base64-obfuscated — NOT encryption. Turn on Credential Encryption in Settings to encrypt FTP/admin passwords, API keys and cloud tokens with a passphrase. There is NO recovery if you forget it. For scheduled backups while encryption is on, allow the unattended key to be cached in this machine's keychain."],
  ["Cloud setup (Google Drive)", "Download an OAuth client-secret JSON from Google Cloud Console (Desktop app). Set it in Settings → Global Cloud Config → Credentials JSON, click Validate then Authenticate (opens a browser once), set the Cloud Folder ID, and Save Defaults."],
  ["The Hub", "Hub URL and key come from The Hub's shared store (filled once in The Hub app's Discover Fleet). SUYB never keeps its own private copy — every login pulls from the shared store."],
  ["Troubleshooting", "\"Recovery kit download failed\" — check admin login (Test Login). \"FTP getaddrinfo failed\" — check the Host field for typos. \"Checksum mismatch\" — SUYB retries; a repeat failure means the server file may be corrupt. \"Cloud upload skipped\" — check Global Cloud Config provider + credentials, then Save Defaults."],
];

/* ── go ───────────────────────────────────────────────────────────────────*/
document.addEventListener("DOMContentLoaded", () => { wireHeader(); boot(); });
/* ===== SNAPSMACK EOF ===== */
