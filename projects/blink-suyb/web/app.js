/*
  blink-suyb — app.js
  Front-end controller. Reads the per-launch token from the URL hash, talks to
  the localhost JSON API, and renders the carry-over data (profiles, credentials,
  settings) plus live engine availability.

  SNAPSMACK_EOF_HEADER
  Last non-empty line MUST be the JS EOF marker:
  double-slash, space, five equals, space, 'SNAPSMACK EOF', space, five equals.
*/

const TOKEN = (location.hash || "").replace(/^#/, "");

async function api(path) {
  const res = await fetch(path, { headers: { "X-Blink-Token": TOKEN } });
  const json = await res.json();
  if (!res.ok || json.ok === false) {
    throw new Error(json.error || res.statusText);
  }
  return json.data;
}

function el(tag, cls, html) {
  const e = document.createElement(tag);
  if (cls) e.className = cls;
  if (html != null) e.innerHTML = html;
  return e;
}

function esc(s) {
  return String(s == null ? "" : s).replace(/[&<>"]/g, c =>
    ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
}

/* ---- Tab switching -------------------------------------------------------- */
document.querySelectorAll("#tabs li").forEach(li => {
  li.addEventListener("click", () => {
    document.querySelectorAll("#tabs li").forEach(x => x.classList.remove("active"));
    document.querySelectorAll(".panel").forEach(x => x.classList.remove("active"));
    li.classList.add("active");
    document.querySelector(`[data-panel="${li.dataset.tab}"]`).classList.add("active");
  });
});

/* ---- Connection + version ------------------------------------------------- */
function setConn(state, label) {
  const dot = document.getElementById("conn-dot");
  dot.className = "dot " + (state === "ok" ? "dot-ok" : state === "bad" ? "dot-bad" : "dot-wait");
  document.getElementById("conn-label").textContent = label;
}

/* ---- Dashboard ------------------------------------------------------------ */
function card(num, lbl, sub) {
  const c = el("div", "card");
  c.appendChild(el("div", "num", esc(num)));
  c.appendChild(el("div", "lbl", esc(lbl)));
  if (sub) c.appendChild(el("div", "sub", esc(sub)));
  return c;
}

async function loadDashboard() {
  const s = await api("/api/status");
  const cards = document.getElementById("dash-cards");
  cards.innerHTML = "";
  cards.appendChild(card(s.found ? "✓" : "✗", "SUYB folder", s.suyb_dir || "not found"));
  cards.appendChild(card(s.profile_count, "Profiles carried over"));
  cards.appendChild(card(s.credential_count, "Credentials"));
  cards.appendChild(card(s.has_config ? "✓" : "—", "config.ini loaded"));

  const eng = await api("/api/engines");
  const grid = document.getElementById("engine-grid");
  grid.innerHTML = "";
  Object.entries(eng).forEach(([name, st]) => {
    const row = el("div", "eng");
    row.appendChild(el("span", "dot " + (st.ok ? "dot-ok" : "dot-bad")));
    row.appendChild(el("span", "name", esc(name)));
    if (!st.ok) row.appendChild(el("span", "err", esc((st.error || "").slice(0, 40))));
    grid.appendChild(row);
  });
}

/* ---- Profiles ------------------------------------------------------------- */
function renderProfile(p) {
  const item = el("div", "item");
  const name = p.name || p.profile_name || p._file || "(unnamed)";
  item.appendChild(el("div", "title", esc(name)));
  const bits = [];
  if (p.transport || p.type) bits.push(esc(p.transport || p.type));
  if (p.host) bits.push(esc(p.host));
  if (p._file) bits.push(esc(p._file));
  item.appendChild(el("div", "meta", bits.join(" · ")));
  if (p._error) { item.appendChild(el("div", "err", "read error: " + esc(p._error))); return item; }

  const fields = el("div", "fields");
  const show = ["host", "port", "username", "remote_path", "local_path", "provider", "login_slug", "transfer_delay"];
  show.forEach(k => {
    if (p[k] != null && p[k] !== "") {
      fields.appendChild(el("div", "k", esc(k)));
      fields.appendChild(el("div", "v", esc(p[k])));
    }
  });
  if (fields.childNodes.length) item.appendChild(fields);
  return item;
}

async function loadProfiles() {
  const profs = await api("/api/profiles");
  document.getElementById("prof-count").textContent = profs.length;
  document.getElementById("backup-profiles").innerHTML = "";
  const list = document.getElementById("profiles-list");
  list.innerHTML = "";
  if (!profs.length) {
    list.appendChild(el("div", "empty", "No saved profiles found in the detected SUYB folder yet."));
    return;
  }
  profs.forEach(p => {
    list.appendChild(renderProfile(p));
    document.getElementById("backup-profiles").appendChild(renderProfile(p));
  });
}

/* ---- Credentials ---------------------------------------------------------- */
async function loadCredentials() {
  const creds = await api("/api/credentials");
  document.getElementById("cred-count").textContent = creds.length;
  const list = document.getElementById("credentials-list");
  list.innerHTML = "";
  if (!creds.length) {
    list.appendChild(el("div", "empty", "No credential library found (credentials.json)."));
    return;
  }
  creds.forEach(c => {
    const item = el("div", "item");
    item.appendChild(el("div", "title", esc(c.name || c.provider || "(credential)")));
    const meta = Object.entries(c).filter(([k]) => k !== "name")
      .map(([k, v]) => `${esc(k)}: ${esc(v)}`).join(" · ");
    item.appendChild(el("div", "meta", meta));
    list.appendChild(item);
  });
}

/* ---- Settings ------------------------------------------------------------- */
async function loadSettings() {
  const cfg = await api("/api/config");
  const view = document.getElementById("settings-view");
  view.innerHTML = "";
  const sections = Object.keys(cfg);
  if (!sections.length) {
    view.appendChild(el("div", "empty", "No config.ini found in the detected SUYB folder."));
    return;
  }
  sections.forEach(sec => {
    const box = el("div", "sect");
    box.appendChild(el("h3", null, esc(sec)));
    Object.entries(cfg[sec]).forEach(([k, v]) => {
      const row = el("div", "row");
      row.appendChild(el("div", "k", esc(k)));
      row.appendChild(el("div", "v", esc(v)));
      box.appendChild(row);
    });
    view.appendChild(box);
  });
}

/* ---- Boot ----------------------------------------------------------------- */
async function boot() {
  try {
    const v = await api("/api/version");
    document.getElementById("ver").textContent = "v" + v.version;
    setConn("ok", "connected");
    await Promise.all([loadDashboard(), loadProfiles(), loadCredentials(), loadSettings()]);
  } catch (e) {
    setConn("bad", "error");
    console.error(e);
    const dash = document.getElementById("dash-cards");
    if (dash) dash.appendChild(el("div", "note", "Could not reach the local server: " + esc(e.message)));
  }
}

boot();

// ===== SNAPSMACK EOF =====
