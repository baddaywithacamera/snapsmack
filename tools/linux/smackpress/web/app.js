/* SMACKPRESS — window logic. All Python work is reached through blink.call(). */
const $ = (s) => document.querySelector(s);
const $$ = (s) => Array.prototype.slice.call(document.querySelectorAll(s));

/* Client-held state (what the tkinter pane objects used to keep as attributes). */
const state = {
  page: 1,
  totalPages: 1,
  post: null,           // full payload from load_post
  categories: [],       // {name, id}
  aiAvailable: false,
  settings: {},
  noteTimer: null,
};

/* Fields shown in the Settings modal (order + grouping from SettingsDialog). */
const SETTINGS_FIELDS = [
  ["WordPress", null],
  ["wp_url", "WordPress site URL"],
  ["wp_user", "WordPress username"],
  ["wp_app_password", "Application Password"],
  ["SnapSmack", null],
  ["snap_url", "SnapSmack site URL"],
  ["snap_api_key", "SMACKPRESS API key"],
  ["AI (optional)", null],
  ["ai_provider", "Provider (none | gemini | openai | anthropic | deepseek | kimi)"],
  ["ai_model", "Model (leave blank for default)"],
  ["ai_api_key", "AI API key"],
];
const SECRET_KEYS = ["wp_app_password", "snap_api_key", "ai_api_key"];

function log(msg, kind) {
  const el = document.createElement("div");
  el.className = "line " + (kind || "");
  el.textContent = msg;
  $("#log").prepend(el);
}

/* ── boot ──────────────────────────────────────────────── */
async function boot() {
  wireEvents();
  try {
    const st = await blink.call("load_state");
    state.settings = st.settings || {};
    state.categories = st.categories || [];
    state.aiAvailable = !!st.ai_available;
    $("#app-title").textContent = st.app_title || "SMACKPRESS";
    $("#app-version").textContent = st.app_version || "";
    $("#nav-type").value = st.last_wp_type || "Posts";
    $("#nav-status").value = st.last_wp_status || "publish";
    $("#caption-fn").checked = !!st.caption_from_filename;
    fillCategories(state.categories);
    if (st.category_error) log("Categories: " + st.category_error, "err");

    if (st.configured) {
      refreshPosts();
    } else {
      openSettings();  // matches: if unconfigured, tkinter popped Settings
    }
  } catch (e) {
    log("Could not load: " + e.message, "err");
  }
}

/* ── NAVIGATOR ─────────────────────────────────────────── */
function postType() {
  return $("#nav-type").value === "Pages" ? "page" : "post";
}

async function refreshPosts() {
  const list = $("#post-list");
  list.innerHTML = '<div class="loading">Loading…</div>';
  try {
    const res = await blink.call("list_posts",
      state.page, 20, $("#nav-status").value, $("#nav-search").value, postType());
    state.totalPages = res.total_pages || 1;
    state.page = res.page || state.page;
    $("#page-label").textContent = state.page + " / " + state.totalPages;
    renderPostList(res.posts || []);
  } catch (e) {
    list.innerHTML = "";
    log("WordPress: " + e.message, "err");
  }
}

function resetAndRefresh() { state.page = 1; refreshPosts(); }

function renderPostList(posts) {
  const list = $("#post-list");
  list.innerHTML = "";
  if (!posts.length) { list.innerHTML = '<div class="loading">No posts.</div>'; return; }
  posts.forEach((p) => {
    const row = document.createElement("div");
    row.className = "post-row" +
      (p.migrated ? " migrated" : "") + (p.hidden ? " hidden" : "");
    const badge = p.migrated ? "✓ " : "";
    row.textContent = badge + p.badge + " " + p.title;
    row.addEventListener("click", () => selectPost(p.id));
    list.appendChild(row);
  });
}

function prevPage() { if (state.page > 1) { state.page--; refreshPosts(); } }
function nextPage() { if (state.page < state.totalPages) { state.page++; refreshPosts(); } }

/* ── load a post into centre + right panes ─────────────── */
async function selectPost(wpId) {
  setStatus("Loading full post…");
  try {
    const full = await blink.call("load_post", wpId);
    state.post = full;
    // Centre pane
    $("#wp-source").value = full.wp_source || "";
    $("#draft").value = full.draft || "";
    setStatus(full.status_line || "");
    // Right pane
    $("#meta").textContent =
      "ID:     " + full.id + "\n" +
      "Date:   " + (full.date || "").slice(0, 10) + "\n" +
      "Status: " + (full.status || "") + "\n" +
      "Images: " + full.image_count + "\n" +
      "Comments: " + full.comment_count +
      (full.migrated_to ? "\nMigrated: yes" : "");
    $("#tags").value = full.tags || "";
    $("#migration").textContent = full.migration_text || "—";
    renderImages(full.images || []);
  } catch (e) {
    log("WordPress: Could not load post: " + e.message, "err");
    setStatus("Error.");
  }
}

function renderImages(images) {
  const box = $("#images");
  box.innerHTML = "";
  images.slice(0, 20).forEach((img) => {
    const row = document.createElement("div");
    row.className = "img-row";
    const fn = (img.filename || "").slice(-30);
    row.textContent = "#" + img.id + " " + fn;
    box.appendChild(row);
  });
}

/* ── draft actions ─────────────────────────────────────── */
function onDraftEdit() {
  if (!state.post) return;
  // Debounced autosave into local notes (tkinter saved on every KeyRelease).
  clearTimeout(state.noteTimer);
  state.noteTimer = setTimeout(() => {
    blink.call("save_note", state.post.id, $("#draft").value)
      .catch((e) => log("Autosave failed: " + e.message, "err"));
  }, 400);
}

function resetDraft() {
  if (!state.post) return;
  $("#draft").value = state.post.content_raw || "";
  onDraftEdit();
}

async function aiRewrite() {
  if (!state.post) return;
  if (!state.aiAvailable) {
    log("Configure an AI provider in Settings first.", "err");
    return;
  }
  setStatus("⏳ AI rewriting…");
  try {
    const res = await blink.call("ai_rewrite",
      state.post.id, state.post.content_raw || "", state.post.title || "");
    $("#draft").value = res.draft || "";
    setStatus("✓ AI rewrite applied.");
  } catch (e) {
    log("AI error: " + e.message, "err");
    setStatus("AI rewrite failed.");
  }
}

async function pushPost() {
  if (!state.post) return;
  const draft = $("#draft").value.trim();
  if (!draft) { log("Write something before pushing.", "err"); return; }
  const kind = state.post.type === "page" ? "page" : "post";
  setStatus("⏳ Pushing " + kind + " to SnapSmack…");
  try {
    const res = await blink.call("push_post",
      state.post.id, state.post.title || "", draft,
      state.post.date || "", $("#tags").value,
      categoryId(), state.post.type || "post");
    setStatus(res.status_line || "✓ Pushed.");
    $("#migration").textContent = res.migration_text || $("#migration").textContent;
    log(res.status_line || "Pushed.", "ok");
  } catch (e) {
    log("SnapSmack: " + e.message, "err");
    setStatus("Push failed.");
  }
}

/* ── card-stack actions ────────────────────────────────── */
function fillCategories(cats) {
  state.categories = cats || [];
  const sel = $("#category");
  sel.innerHTML = '<option value="">(none)</option>';
  state.categories.forEach((c) => {
    const opt = document.createElement("option");
    opt.value = String(c.id);
    opt.textContent = c.name;
    sel.appendChild(opt);
  });
}
function categoryId() {
  const v = parseInt($("#category").value, 10);
  return isNaN(v) ? 0 : v;
}

async function toggleCaptionFn() {
  try { await blink.call("set_caption_from_filename", $("#caption-fn").checked); }
  catch (e) { log("Setting failed: " + e.message, "err"); }
}

async function createMosaic() {
  if (!state.post || !(state.post.images || []).length) {
    log("No gallery images found for this post.", "err");
    return;
  }
  const title = window.prompt("Enter a title for this mosaic:", state.post.title || "");
  if (!title) return;
  setStatus("⏳ Building mosaic…");
  try {
    const res = await blink.call("create_mosaic",
      state.post.id, title, state.post.images, $("#caption-fn").checked);
    const draftEl = $("#draft");
    draftEl.value = draftEl.value + "\n\n" + res.shortcode;
    onDraftEdit();
    setStatus("✓ Mosaic #" + res.mosaic_id + " created.");
    log("Mosaic #" + res.mosaic_id + " created. " + res.shortcode + " added to draft.", "ok");
  } catch (e) {
    log("Mosaic: " + e.message, "err");
    setStatus("Mosaic failed.");
  }
}

async function hideWp() {
  if (!state.post) return;
  if (!window.confirm(
      "Set this WordPress post to private?\n" +
      "This cannot be undone without going into WP admin.")) return;
  try {
    const res = await blink.call("hide_wp", state.post.id, "");
    $("#migration").textContent = res.migration_text || "Hidden ✓";
    log(res.message || "Done.", "ok");
  } catch (e) {
    log("Hide post: " + e.message, "err");
  }
}

/* ── settings modal ────────────────────────────────────── */
function openSettings() {
  const wrap = $("#settings-fields");
  wrap.innerHTML = "";
  SETTINGS_FIELDS.forEach(([key, label]) => {
    if (label === null) {
      const h = document.createElement("div");
      h.className = "settings-group";
      h.textContent = key;
      wrap.appendChild(h);
      return;
    }
    const lab = document.createElement("label");
    lab.className = "field-label";
    lab.textContent = label;
    wrap.appendChild(lab);
    const inp = document.createElement("input");
    inp.type = SECRET_KEYS.indexOf(key) >= 0 ? "password" : "text";
    inp.dataset.key = key;
    inp.value = state.settings[key] || "";
    wrap.appendChild(inp);
  });
  $("#ai-prompt").value = state.settings.ai_system_prompt || "";
  $("#settings-result").textContent = "";
  $("#settings-overlay").classList.remove("hidden");
}

function collectSettings() {
  const out = {};
  $$("#settings-fields input").forEach((inp) => { out[inp.dataset.key] = inp.value; });
  return out;
}

async function saveSettings() {
  try {
    const res = await blink.call("save_settings", collectSettings(), $("#ai-prompt").value);
    state.aiAvailable = !!res.ai_available;
    // Refresh cached settings so re-opening shows saved values.
    Object.assign(state.settings, collectSettings(),
      { ai_system_prompt: $("#ai-prompt").value });
    $("#settings-overlay").classList.add("hidden");
    log("Settings saved.", "ok");
    // If we now have creds, load the post list (matches the auto-refresh path).
    if (state.settings.wp_url && state.settings.snap_url) {
      const st = await blink.call("load_state");
      fillCategories(st.categories || []);
      resetAndRefresh();
    }
  } catch (e) {
    log("Settings: " + e.message, "err");
  }
}

async function testConnections() {
  $("#settings-result").textContent = "Testing…";
  try {
    const res = await blink.call("test_connections", collectSettings(), $("#ai-prompt").value);
    const box = $("#settings-result");
    box.innerHTML = "";
    (res.results || []).forEach((r) => {
      const d = document.createElement("div");
      d.className = r.ok ? "ok" : "err";
      d.textContent = r.text;
      box.appendChild(d);
    });
  } catch (e) {
    $("#settings-result").textContent = "Error: " + e.message;
  }
}

/* ── help modal ────────────────────────────────────────── */
/* Static content — lives in index.html, so no blink.call is needed. */
function openHelp() { $("#help-overlay").classList.remove("hidden"); }
function closeHelp() { $("#help-overlay").classList.add("hidden"); }

/* ── misc ──────────────────────────────────────────────── */
function setStatus(msg) { $("#canvas-status").textContent = msg; }

function wireEvents() {
  // Menu
  $("#menu-settings").addEventListener("click", openSettings);
  $("#menu-refresh").addEventListener("click", resetAndRefresh);
  $("#menu-help").addEventListener("click", openHelp);
  $("#menu-quit").addEventListener("click", () => window.close());
  // Navigator
  $("#nav-refresh").addEventListener("click", refreshPosts);
  $("#nav-type").addEventListener("change", resetAndRefresh);
  $("#nav-status").addEventListener("change", resetAndRefresh);
  $("#nav-go").addEventListener("click", resetAndRefresh);
  $("#nav-search").addEventListener("keydown", (e) => { if (e.key === "Enter") resetAndRefresh(); });
  $("#page-prev").addEventListener("click", prevPage);
  $("#page-next").addEventListener("click", nextPage);
  // Canvas
  $("#draft").addEventListener("input", onDraftEdit);
  $("#btn-ai").addEventListener("click", aiRewrite);
  $("#btn-reset").addEventListener("click", resetDraft);
  $("#btn-push").addEventListener("click", pushPost);
  // Cards
  $("#caption-fn").addEventListener("change", toggleCaptionFn);
  $("#btn-mosaic").addEventListener("click", createMosaic);
  $("#btn-hide").addEventListener("click", hideWp);
  // Settings modal
  $("#settings-save").addEventListener("click", saveSettings);
  $("#settings-test").addEventListener("click", testConnections);
  $("#settings-cancel").addEventListener("click", () => $("#settings-overlay").classList.add("hidden"));
  // Help modal
  $("#help-close").addEventListener("click", closeHelp);
}

document.addEventListener("DOMContentLoaded", boot);
/* ===== SNAPSMACK EOF ===== */
