/**
 * OH SNAP! — Main application controller
 * v0.2.0
 *
 * Orchestrates screen transitions, connection flow, profile management,
 * sidebar tabs, preview controls, and the AI chat drawer.
 *
 * Depends on (loaded before this script):
 *   api.js       — SnapSmackAPI class
 *   settings.js  — OhSnapSettings
 *   controls.js  — controlsInit(), controlsGetOverrides(), controlsApplyExternal()
 *   preview.js   — OhSnapPreview
 *   ai.js        — OhSnapAI
 *   project.js   — OhSnapProject
 *
 * Profiles are stored in localStorage so they survive app restarts.
 * Each profile: { name, url, key, connectedAt }
 * The active connection and skin data are held in memory only.
 */

// --- STATE ---

let api           = null;   // Active SnapSmackAPI instance
let activeProfile = null;
let skinData      = null;   // Last response from api.skin()
let postsData     = null;   // Last response from api.posts()
let _dirty        = false;

// Auto-save draft every 30 seconds when dirty
let _autoSaveTimer = null;

const PROFILES_KEY = 'ohsnap_profiles';

// --- DOM HELPERS ---

const $ = id => document.getElementById(id);

const screens = {
    connect: $('screen-connect'),
    app:     $('screen-app'),
};

// Connection screen
const inputUrl          = $('input-url');
const inputKey          = $('input-key');
const inputProfile      = $('input-profile');
const btnConnect        = $('btn-connect');
const btnConnectLabel   = $('btn-connect-label');
const btnConnectSpinner = $('btn-connect-spinner');
const connectError      = $('connect-error');
const connectProfiles   = $('connect-profiles');
const profilesList      = $('profiles-list');
const btnKeyToggle      = $('btn-key-toggle');

// App toolbar
const toolbarSiteName    = $('toolbar-site-name');
const toolbarProjectName = $('toolbar-project-name');
const toolbarDirty       = $('toolbar-dirty');

// Preview
const previewFrame       = $('preview-frame');
const previewPlaceholder = $('preview-placeholder');

// AI drawer
const aiDrawer     = $('ai-drawer');
const aiMessages   = $('ai-messages');
const aiInput      = $('ai-input');
const btnAiSend    = $('btn-ai-send');
const btnAiToggle  = $('btn-ai-toggle');

// --- DIRTY STATE ---

function markDirty() {
    if (_dirty) return;
    _dirty = true;
    toolbarDirty?.classList.remove('hidden');

    // Auto-save draft 30 s after last change
    clearTimeout(_autoSaveTimer);
    _autoSaveTimer = setTimeout(() => {
        OhSnapProject.saveDraftNow();
    }, 30_000);
}

function clearDirty() {
    _dirty = false;
    toolbarDirty?.classList.add('hidden');
    clearTimeout(_autoSaveTimer);
}

// Expose markDirty globally so controls.js can call it
window.markDirty = markDirty;

// --- SCREEN TRANSITIONS ---

function showScreen(name) {
    Object.entries(screens).forEach(([key, el]) => {
        el.classList.toggle('active',  key === name);
        el.classList.toggle('hidden', key !== name);
    });
}

// --- PROFILES ---

function loadProfiles() {
    try { return JSON.parse(localStorage.getItem(PROFILES_KEY) || '[]'); }
    catch { return []; }
}

function saveProfiles(profiles) {
    localStorage.setItem(PROFILES_KEY, JSON.stringify(profiles));
}

function renderProfiles() {
    const profiles = loadProfiles();
    if (!profiles.length) { connectProfiles.classList.add('hidden'); return; }

    connectProfiles.classList.remove('hidden');
    profilesList.innerHTML = '';

    profiles.forEach((p, i) => {
        const item = document.createElement('div');
        item.className = 'profile-item';
        item.innerHTML = `
            <div>
                <div class="profile-name">${esc(p.name || p.url)}</div>
                <div class="profile-url">${esc(p.url)}</div>
            </div>
            <button class="profile-remove" data-index="${i}" title="Remove">✕</button>`;

        item.addEventListener('click', e => {
            if (e.target.classList.contains('profile-remove')) return;
            inputUrl.value     = p.url;
            inputKey.value     = p.key;
            inputProfile.value = p.name;
        });

        item.querySelector('.profile-remove').addEventListener('click', () => {
            const updated = loadProfiles();
            updated.splice(i, 1);
            saveProfiles(updated);
            renderProfiles();
        });

        profilesList.appendChild(item);
    });
}

// --- CONNECTION ---

btnKeyToggle.addEventListener('click', () => {
    inputKey.type = inputKey.type === 'password' ? 'text' : 'password';
});

btnConnect.addEventListener('click', connectToSite);
inputKey.addEventListener('keydown', e => { if (e.key === 'Enter') connectToSite(); });

async function connectToSite() {
    const url = inputUrl.value.trim();
    const key = inputKey.value.trim();

    if (!url || !key) { showConnectError('Please enter your site URL and API key.'); return; }

    setConnecting(true);
    hideConnectError();

    try {
        const client = new SnapSmackAPI(url, key);
        const ping   = await client.ping();

        const name    = inputProfile.value.trim() || ping.site_name || url;
        const profiles = loadProfiles();
        const existing = profiles.findIndex(p => p.url === url);
        const profile  = { name, url, key, connectedAt: new Date().toISOString() };

        if (existing >= 0) profiles[existing] = profile;
        else               profiles.unshift(profile);

        saveProfiles(profiles);
        api           = client;
        activeProfile = profile;
        await enterApp(ping);

    } catch (err) {
        showConnectError(err.message || 'Could not connect. Check the URL and API key.');
    } finally {
        setConnecting(false);
    }
}

function setConnecting(loading) {
    btnConnect.disabled = loading;
    btnConnectLabel.classList.toggle('hidden', loading);
    btnConnectSpinner.classList.toggle('hidden', !loading);
}

function showConnectError(msg) {
    connectError.textContent = msg;
    connectError.classList.remove('hidden');
}

function hideConnectError() {
    connectError.classList.add('hidden');
}

// --- ENTER APP ---

async function enterApp(pingData) {
    toolbarSiteName.textContent = pingData.site_name || activeProfile.url;
    showScreen('app');

    try {
        // Fetch skin data and recent posts in parallel
        [skinData, postsData] = await Promise.all([api.skin(), api.posts()]);

        // Init controls panel
        controlsInit(skinData);

        // Init srcdoc preview
        OhSnapPreview.init(previewFrame, skinData, postsData, pingData.base_url || activeProfile.url);

        // Set project context (restores draft if one exists)
        OhSnapProject.setContext(skinData.skin_slug || '');

        // Update project name in toolbar
        const nameEl = $('toolbar-project-name');
        if (nameEl) nameEl.contentEditable = 'true';

    } catch (err) {
        console.error('Failed to load skin/posts:', err);
    }
}

// Editable project name in toolbar
$('toolbar-project-name')?.addEventListener('blur', e => {
    const name = e.target.textContent.trim() || 'Untitled Skin';
    OhSnapProject.updateProjectName(name);
    markDirty();
});

$('toolbar-project-name')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
});

// --- TOOLBAR BUTTONS ---

$('btn-save')?.addEventListener('click', () => OhSnapProject.save());
$('btn-export')?.addEventListener('click', () => OhSnapProject.exportCss());
$('btn-push')?.addEventListener('click', () => OhSnapProject.pushToSite(api));
$('btn-settings')?.addEventListener('click', () => OhSnapSettings.openModal());
$('btn-open-browser')?.addEventListener('click', () => {
    if (activeProfile?.url) window.open(activeProfile.url, '_blank');
});

// --- SITE SWITCH ---

$('btn-site-switch')?.addEventListener('click', () => {
    api = null; activeProfile = null; skinData = null; postsData = null;
    showScreen('connect');
    renderProfiles();
});

// --- SIDEBAR TABS ---

document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const tab = btn.dataset.tab;
        document.querySelectorAll('.tab-btn').forEach(b  => b.classList.toggle('active', b === btn));
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id === `tab-${tab}`));
    });
});

// --- PREVIEW WIDTH / VIEW ---

document.querySelectorAll('.preview-width-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const width = parseInt(btn.dataset.width, 10);
        document.querySelectorAll('.preview-width-btn').forEach(b => b.classList.toggle('active', b === btn));
        // Scale the iframe wrapper, not the iframe itself, so the skin renders at true width
        const wrap = $('preview-wrap');
        if (wrap) wrap.dataset.previewWidth = width;
        previewFrame.style.width = width + 'px';
    });
});

document.querySelectorAll('.preview-view-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.preview-view-btn').forEach(b => b.classList.toggle('active', b === btn));
        OhSnapPreview.switchView(btn.dataset.view);
    });
});

// --- AI DRAWER ---

btnAiToggle.addEventListener('click', () => {
    aiDrawer.classList.toggle('open');
});

async function sendAiMessage() {
    const text = aiInput.value.trim();
    if (!text) return;

    appendAiMessage(text, 'user');
    aiInput.value = '';
    aiInput.disabled  = true;
    btnAiSend.disabled = true;

    try {
        const overrides = await OhSnapAI.send(text, skinData?.css_variables || {});

        if (!overrides || !Object.keys(overrides).length) {
            appendAiMessage("I didn't find any CSS variables to change for that request. Try being more specific about colors, fonts, or spacing.", 'assistant');
        } else {
            // Apply the overrides from AI
            controlsApplyExternal(overrides);
            markDirty();

            const count  = Object.keys(overrides).length;
            const sample = Object.entries(overrides).slice(0, 3)
                .map(([k, v]) => `${k}: ${v}`).join(', ');
            appendAiMessage(
                `Applied ${count} change${count !== 1 ? 's' : ''}: ${sample}${count > 3 ? '…' : ''}`,
                'assistant'
            );
        }

    } catch (err) {
        appendAiMessage(`Error: ${err.message}`, 'error');
    } finally {
        aiInput.disabled   = false;
        btnAiSend.disabled = false;
        aiInput.focus();
    }
}

function appendAiMessage(text, role) {
    const el = document.createElement('div');
    el.className = `ai-msg ai-msg-${role}`;
    el.textContent = text;
    aiMessages.appendChild(el);
    aiMessages.scrollTop = aiMessages.scrollHeight;
}

btnAiSend.addEventListener('click', sendAiMessage);
aiInput.addEventListener('keydown', e => { if (e.key === 'Enter') sendAiMessage(); });

// --- KEYBOARD SHORTCUTS ---

document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        OhSnapProject.save();
    }
    if ((e.ctrlKey || e.metaKey) && e.key === ',') {
        e.preventDefault();
        OhSnapSettings.openModal();
    }
    if (e.key === 'Escape') {
        OhSnapSettings.closeModal();
    }
});

// --- INIT ---

renderProfiles();
showScreen('connect');

// --- UTILS ---

function esc(str) {
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
// EOF
