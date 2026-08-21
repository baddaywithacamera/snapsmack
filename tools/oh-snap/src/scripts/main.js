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
 * Non-secret profile labels/URLs are stored locally. API and AI keys are
 * session-only until an operating-system credential vault is available.
 * The active connection and skin data are held in memory only.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// --- STATE ---

let api           = null;   // Active SnapSmackAPI instance
let activeProfile = null;
let skinData      = null;   // Last response from api.skin()
let postsData     = null;   // Last response from api.posts()
let libraryData   = null;   // Shared resources library (only when state === 'present')
let libraryState  = 'unknown'; // present | incomplete | unsupported | failed | unknown
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
    _dirty = true;
    toolbarDirty?.classList.remove('hidden');
    OhSnapProject?.recordCheckpoint?.('Design changed');

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
window.clearDirty = clearDirty;
window.showStatus = function showStatus(message, isError = false) {
    document.querySelector('.status-toast')?.remove();
    const toast = document.createElement('div');
    toast.className = 'status-toast';
    if (isError) toast.style.borderColor = '#d96868';
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4500);
};

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

        item.addEventListener('click', async e => {
            if (e.target.classList.contains('profile-remove')) return;
            inputUrl.value     = p.url;
            inputKey.value     = p.vault_account ? (await OhSnapVault.get(p.vault_account).catch(() => null) || '') : '';
            inputProfile.value = p.name;
        });

        item.querySelector('.profile-remove').addEventListener('click', async () => {
            const updated = loadProfiles();
            if (updated[i]?.vault_account) await OhSnapVault.remove(updated[i].vault_account).catch(() => {});
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
        const vaultAccount = `site:${btoa(unescape(encodeURIComponent(url))).replace(/=+$/,'')}`;
        const stored = await OhSnapVault.set(vaultAccount, key).catch(() => false);
        const profile  = { name, url, vault_account: stored ? vaultAccount : null, connectedAt: new Date().toISOString() };

        if (existing >= 0) profiles[existing] = profile;
        else               profiles.unshift(profile);

        saveProfiles(profiles);
        api           = client;
        activeProfile = { ...profile, key };
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
    $('btn-open-browser').disabled = false;

    try {
        const [catalog, recent] = await Promise.all([api.skins(), api.posts()]);
        postsData = recent;
        const picker = $('skin-picker');
        picker.innerHTML = '';
        catalog.skins.forEach(skin => {
            const option = document.createElement('option'); option.value = skin.slug;
            option.textContent = `${skin.name}${skin.locked ? ' — locked' : (!skin.oh_snap_ready ? ' — not OH SNAP-ready' : '')}`;
            option.disabled = skin.locked || !skin.compatible; option.selected = skin.slug === catalog.active_skin; picker.appendChild(option);
        });
        const initialSkin = catalog.skins.find(s => s.active && !s.locked && s.compatible)
            || catalog.skins.find(s => !s.locked && s.compatible && s.oh_snap_ready);
        if (!initialSkin) throw new Error('No editable OH SNAP-ready skin is installed. Locked skins remain read-only.');
        picker.value = initialSkin.slug;
        picker.classList.remove('hidden');
        await loadConnectedSkin(initialSkin.slug, pingData);

        picker.onchange = () => loadConnectedSkin(picker.value, pingData).catch(err => alert(err.message));

        const nameEl = $('toolbar-project-name');
        if (nameEl) nameEl.contentEditable = 'true';

    } catch (err) {
        console.error('Failed to load skin/posts:', err);
        showStatus(`Could not load connected skins: ${err.message}`, true);
    }

    try {
        applyLibraryState(await api.library());
    } catch (err) {
        applyLibraryState({ state: 'failed', message: err.message || 'Library fetch failed.' });
    }
}

async function loadConnectedSkin(slug, pingData) {
        skinData = await api.skin(slug);
        $('btn-push').disabled = skinData.oh_snap_ready !== true;
        $('btn-push').title = skinData.oh_snap_ready === true
            ? `Push variable overrides to ${skinData.skin_slug}`
            : 'This skin has not declared OH SNAP compatibility';

        controlsInit(skinData);
        OhSnapPreview.init(previewFrame, skinData, postsData, pingData.base_url || activeProfile.url);
        OhSnapProject.setContext(skinData.skin_slug || '', {
            source: 'connected',
            base_css: skinData.style_css || '',
            preview_content: {
                site_name: pingData.site_name || 'Preview Site',
                tagline: pingData.tagline || '',
                posts: postsData.posts || [],
            },
            connected_source: {
                url: activeProfile.url,
                site_name: pingData.site_name || activeProfile.url,
                skin_slug: skinData.skin_slug || '',
                skin_version: skinData.manifest?.version || '',
                oh_snap_ready: skinData.oh_snap_ready === true,
            },
        });
}

/**
 * Record the library state and tell the user plainly what it means. Fail CLOSED:
 * when the library isn't cleanly present, the AI is NOT handed a library it can't
 * trust — it stays in active-skin-only mode — rather than silently designing blind.
 */
function applyLibraryState(lib) {
    libraryState = lib.state;
    libraryData  = (lib.state === 'present')
        ? { engines: lib.engines || [], inventory: lib.library || {} }
        : null;

    if (lib.state === 'present') {
        const c = lib.counts || {};
        appendAiMessage(
            `Shared library loaded (schema ${lib.schemaVersion}): ${c.engines || 0} skin engines, ${c.css || 0} CSS blocks, ${c.fonts || 0} fonts. I can design against the shared library.`,
            'assistant'
        );
        return;
    }

    // Not present — say why, and be explicit that we are NOT building blind.
    let why;
    if (lib.state === 'incomplete')       why = "the site served an incomplete library, so I won't design against a half-built list";
    else if (lib.state === 'unsupported') why = lib.message;
    else                                  why = "this site didn't provide the shared library (an older install, or it's unreachable)";
    appendAiMessage(
        `Heads up: I couldn't load the shared engine/CSS library — ${why}. I'll still help with this skin's own colour/spacing controls, but I won't invent shared engines I can't see.`,
        'assistant'
    );
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
$('btn-open')?.addEventListener('click', async () => {
    const loaded = await OhSnapProject.load();
    if (loaded) enterOfflineProject(loaded, false);
});
$('btn-export')?.addEventListener('click', () => OhSnapProject.exportCss());
$('btn-export-package')?.addEventListener('click', () => OhSnapProject.exportPackage());
$('btn-push')?.addEventListener('click', () => OhSnapProject.pushToSite(api));
$('btn-settings')?.addEventListener('click', () => OhSnapSettings.openModal());
$('btn-open-browser')?.addEventListener('click', () => {
    if (activeProfile?.url) window.open(activeProfile.url, '_blank');
});
$('btn-undo')?.addEventListener('click', () => OhSnapProject.undo());
$('btn-redo')?.addEventListener('click', () => OhSnapProject.redo());
$('btn-apply-fixture')?.addEventListener('click', () => {
    OhSnapProject.updatePreviewContent({ site_name: $('fixture-site-name').value.trim() || 'Preview Site', lead_title: $('fixture-post-title').value.trim(), lead_caption: $('fixture-caption').value.trim() }, Number($('fixture-count').value));
});
$('btn-validate')?.addEventListener('click', () => OhSnapProject.validateProject());
window.addEventListener('ohsnap-validation', event => {
    const out = $('diagnostics-list'), { errors = [], warnings = [] } = event.detail || {};
    out.innerHTML = '';
    const title = document.createElement('strong'); title.textContent = errors.length ? `${errors.length} blocking error(s)` : 'Validation passed'; out.appendChild(title);
    [...errors.map(x => `ERROR — ${x}`), ...warnings.map(x => `WARNING — ${x}`)].forEach(text => { const row = document.createElement('div'); row.className = 'history-entry'; row.textContent = text; out.appendChild(row); });
});

function offlineSkin(modeName) {
    const contract = window.OH_SNAP_CONTRACT;
    const mode = contract?.modes?.[modeName];
    if (!mode) throw new Error(`The bundled skin contract does not support ${modeName}.`);
    return { skin_slug: `ohsnap-${mode.profile}`, oh_snap_ready: true, manifest: { name: mode.label, version: contract.contract_schema, oh_snap_ready: true }, style_css: mode.shell_css, css_variables: mode.variables, contract_mode: mode };
}

function enterOfflineProject(existing = null, create = true) {
    const modeName = existing?.mode || $('offline-mode')?.value || 'SMACKONEOUT';
    const kit = offlineSkin(modeName);
    api = null; activeProfile = null; skinData = kit; postsData = null;
    showScreen('app'); toolbarSiteName.innerHTML = '<span class="offline-badge">Offline project</span>';
    $('skin-picker').classList.add('hidden');
    controlsInit(kit);
    const project = create ? OhSnapProject.newProject({ mode: modeName, skin_slug: kit.skin_slug, base_css: kit.style_css, contract: { schema_version: window.OH_SNAP_CONTRACT.contract_schema, inventory_schema: window.OH_SNAP_CONTRACT.source_inventory_schema, source: 'bundled-server-contract' } }) : existing;
    controlsApplyExternal(project.overrides || {});
    OhSnapPreview.initOffline(previewFrame, project);
    toolbarProjectName.contentEditable = 'true';
    $('btn-push').disabled = true;
    $('btn-open-browser').disabled = true;
    appendAiMessage('Offline kit loaded. Manual controls, preview, save, open, history, and CSS export are available without a connection.', 'assistant');
}

$('btn-new-offline')?.addEventListener('click', () => enterOfflineProject());
$('btn-open-project')?.addEventListener('click', async () => {
    controlsInit(offlineSkin($('offline-mode')?.value || 'SMACKONEOUT'));
    const loaded = await OhSnapProject.load();
    if (loaded) enterOfflineProject(loaded, false);
});

// --- SITE SWITCH ---

$('btn-site-switch')?.addEventListener('click', () => {
    api = null; activeProfile = null; skinData = null; postsData = null;
    libraryData = null; libraryState = 'unknown';
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
        const reference = await readReferenceImage($('ai-reference')?.files?.[0]);
        const result = await OhSnapAI.send(text, skinData?.css_variables || {}, libraryData || { engines: window.OH_SNAP_CONTRACT?.asset_inventory?.javascript || [], inventory: window.OH_SNAP_CONTRACT?.asset_inventory || {} }, reference);
        const overrides = result?.overrides || {};

        if (!Object.keys(overrides).length && !result?.user_css && !result?.required_scripts?.length && !result?.required_styles?.length) {
            appendAiMessage("I didn't find any CSS variables to change for that request. Try being more specific about colors, fonts, or spacing.", 'assistant');
        } else {
            const changeSummary = `${Object.keys(overrides).length} variables, ${result.user_css ? 'custom CSS, ' : ''}${(result.required_scripts?.length || 0) + (result.required_styles?.length || 0)} dependencies`;
            if (!confirm(`Review AI proposal\n\n${result.summary || 'Design change'}\n${changeSummary}\n\nApply this as an undoable checkpoint?`)) {
                appendAiMessage('Proposal discarded; the project was not changed.', 'assistant');
                return;
            }
            const validation = OhSnapProject.applyGeneration(result);

            const count  = Object.keys(overrides).length;
            const sample = Object.entries(overrides).slice(0, 3)
                .map(([k, v]) => `${k}: ${v}`).join(', ');
            appendAiMessage(
                `Applied ${result.summary || `${count} change${count !== 1 ? 's' : ''}`}: ${sample}${count > 3 ? '…' : ''}${validation.errors.length ? ` — ${validation.errors.length} validation error(s) remain.` : ''}`,
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

function readReferenceImage(file) {
    if (!file) return Promise.resolve(null);
    if (!['image/jpeg','image/png','image/webp'].includes(file.type)) return Promise.reject(new Error('Reference image must be JPEG, PNG, or WebP.'));
    if (file.size > 5_000_000) return Promise.reject(new Error('Reference image must be 5 MB or smaller.'));
    return new Promise((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve({ type:file.type, base64:String(reader.result).split(',')[1] }); reader.onerror = () => reject(new Error('Could not read reference image.')); reader.readAsDataURL(file); });
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
OhSnapSettings.hydrateVault().catch(err => console.warn('Credential vault unavailable:', err));
showScreen('connect');

// --- UTILS ---

function esc(str) {
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
// ===== SNAPSMACK EOF =====
