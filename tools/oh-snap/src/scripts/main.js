/**
 * OH SNAP! — Main application controller
 * v0.1.0
 *
 * Handles screen transitions, profile management, connection flow,
 * sidebar tab switching, preview width/view controls, and AI drawer.
 *
 * Profiles are stored in localStorage as a JSON array so they survive
 * app restarts. Each profile: { name, url, key, connectedAt }.
 * The active connection is held in memory only — keys are not persisted
 * past the profile's stored entry.
 */

// --- STATE ---

let api        = null;   // Active SnapSmackAPI instance
let activeProfile = null;
let projectDirty  = false;

const PROFILES_KEY = 'ohsnap_profiles';

// --- DOM ---

const $ = id => document.getElementById(id);

const screens = {
    connect: $('screen-connect'),
    app:     $('screen-app'),
};

// Connection screen
const inputUrl     = $('input-url');
const inputKey     = $('input-key');
const inputProfile = $('input-profile');
const btnConnect   = $('btn-connect');
const btnConnectLabel   = $('btn-connect-label');
const btnConnectSpinner = $('btn-connect-spinner');
const connectError = $('connect-error');
const connectProfiles = $('connect-profiles');
const profilesList    = $('profiles-list');
const btnKeyToggle    = $('btn-key-toggle');

// App toolbar
const toolbarSiteName   = $('toolbar-site-name');
const toolbarProjectName = $('toolbar-project-name');
const toolbarDirty      = $('toolbar-dirty');

// Preview
const previewFrame     = $('preview-frame');
const previewPlaceholder = $('preview-placeholder');

// AI drawer
const aiDrawer    = $('ai-drawer');
const aiDrawerBody = $('ai-drawer-body');
const btnAiToggle = $('btn-ai-toggle');
const aiMessages  = $('ai-messages');
const aiInput     = $('ai-input');
const btnAiSend   = $('btn-ai-send');

// --- SCREEN TRANSITIONS ---

function showScreen(name) {
    Object.entries(screens).forEach(([key, el]) => {
        el.classList.toggle('active',  key === name);
        el.classList.toggle('hidden', key !== name);
    });
}

// --- PROFILES ---

function loadProfiles() {
    try {
        return JSON.parse(localStorage.getItem(PROFILES_KEY) || '[]');
    } catch {
        return [];
    }
}

function saveProfiles(profiles) {
    localStorage.setItem(PROFILES_KEY, JSON.stringify(profiles));
}

function renderProfiles() {
    const profiles = loadProfiles();
    if (!profiles.length) {
        connectProfiles.classList.add('hidden');
        return;
    }
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
            <button class="profile-remove" data-index="${i}" title="Remove profile">✕</button>
        `;
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
    const isPassword = inputKey.type === 'password';
    inputKey.type = isPassword ? 'text' : 'password';
});

btnConnect.addEventListener('click', connectToSite);
inputKey.addEventListener('keydown', e => { if (e.key === 'Enter') connectToSite(); });

async function connectToSite() {
    const url = inputUrl.value.trim();
    const key = inputKey.value.trim();

    if (!url || !key) {
        showConnectError('Please enter your site URL and API key.');
        return;
    }

    setConnecting(true);
    hideConnectError();

    try {
        const client = new SnapSmackAPI(url, key);
        const result = await client.ping();

        // Store profile
        const name = inputProfile.value.trim() || result.site_name || url;
        const profiles = loadProfiles();
        const existing = profiles.findIndex(p => p.url === url);
        const profile  = { name, url, key, connectedAt: new Date().toISOString() };
        if (existing >= 0) {
            profiles[existing] = profile;
        } else {
            profiles.unshift(profile);
        }
        saveProfiles(profiles);

        // Activate
        api = client;
        activeProfile = profile;
        enterApp(result);

    } catch (err) {
        showConnectError(err.message || 'Could not connect. Check the URL and API key.');
    } finally {
        setConnecting(false);
    }
}

function setConnecting(loading) {
    btnConnect.disabled       = loading;
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

    // Pull skin and populate controls
    try {
        const skinData = await api.skin();
        initControls(skinData);
        loadPreview(pingData);
    } catch (err) {
        console.error('Failed to load skin data:', err);
    }
}

function loadPreview(pingData) {
    // Load the live site into the preview iframe via the connected site URL.
    // The iframe is sandboxed — it shows the real site CSS for immediate feedback.
    if (pingData.base_url) {
        previewFrame.src = pingData.base_url;
        previewPlaceholder.classList.add('hidden');
    }
}

// --- CONTROLS INITIALISATION ---

function initControls(skinData) {
    if (!skinData.oh_snap_ready) {
        // Skin doesn't declare css_variables — read-only/import mode.
        document.getElementById('tab-colours').innerHTML =
            `<div class="panel-empty"><p>This skin uses the legacy manifest system.<br>Controls are read-only. You can import and modify the CSS directly.</p></div>`;
        return;
    }
    // TODO: Build colour pickers, font pickers, sliders from skinData.css_variables
    // This is the next build phase.
}

// --- SIDEBAR TABS ---

document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const tab = btn.dataset.tab;
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b === btn));
        document.querySelectorAll('.tab-panel').forEach(p => {
            p.classList.toggle('active', p.id === `tab-${tab}`);
        });
    });
});

// --- PREVIEW WIDTH / VIEW ---

document.querySelectorAll('.preview-width-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const width = parseInt(btn.dataset.width, 10);
        document.querySelectorAll('.preview-width-btn').forEach(b => b.classList.toggle('active', b === btn));
        previewFrame.style.width = width + 'px';
    });
});

document.querySelectorAll('.preview-view-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.preview-view-btn').forEach(b => b.classList.toggle('active', b === btn));
        // TODO: switch preview URL to archive / landing path
    });
});

// --- AI DRAWER ---

btnAiToggle.addEventListener('click', () => {
    aiDrawer.classList.toggle('open');
});

function sendAiMessage() {
    const text = aiInput.value.trim();
    if (!text) return;
    appendAiMessage(text, 'user');
    aiInput.value = '';
    // TODO: wire to AI provider via configurable endpoint
    setTimeout(() => {
        appendAiMessage('AI integration coming soon. Configure your API key in Settings.', 'assistant');
    }, 300);
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

// --- SITE SWITCH ---

$('btn-site-switch').addEventListener('click', () => {
    api = null;
    activeProfile = null;
    showScreen('connect');
    renderProfiles();
});

// --- KEYBOARD SHORTCUTS ---

document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        // TODO: save project
    }
});

// --- INIT ---

renderProfiles();
showScreen('connect');

// --- UTILS ---

function esc(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
