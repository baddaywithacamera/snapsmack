/**
 * OH SNAP! — Project manager
 * v0.1.0
 *
 * Handles saving, loading, exporting, and pushing skin projects.
 *
 * Project format (JSON):
 * {
 *   version:    '1',
 *   skin_slug:  'new-horizon',
 *   name:       'My Dark Project',
 *   created:    ISO date string,
 *   modified:   ISO date string,
 *   overrides:  { '--bg-page': '#000', ... }
 * }
 *
 * Save/Load:  Uses Tauri's file dialog (invoke) if in Tauri context,
 *             falls back to browser <a download> / <input type=file>.
 *
 * Export CSS: Downloads a .css file containing the :root override block.
 *             Drop it into the skin directory as a custom override.
 *
 * Push:       Sends overrides to the connected site via ohsnap/skin/vars.
 *             Changes appear immediately on the live site.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


const OhSnapProject = (() => {

    const DRAFT_KEY    = 'ohsnap_draft_project';
    const SCHEMA_VER   = '1';

    let _projectName   = 'Untitled Skin';
    let _skinSlug      = '';
    let _created       = null;

    // --- PUBLIC ---

    function setContext(skinSlug) {
        _skinSlug = skinSlug;

        // Try to restore a saved draft for this skin
        const draft = _loadDraft(skinSlug);
        if (draft && Object.keys(draft.overrides || {}).length) {
            _projectName = draft.name || 'Untitled Skin';
            _created     = draft.created || new Date().toISOString();
            controlsApplyExternal(draft.overrides);
            updateProjectName(_projectName);
        } else {
            _created = new Date().toISOString();
        }
    }

    function updateProjectName(name) {
        _projectName = name;
        const el = document.getElementById('toolbar-project-name');
        if (el) el.textContent = name;
    }

    // --- SAVE ---

    async function save() {
        const project = _buildProject();
        const json    = JSON.stringify(project, null, 2);
        const filename = _safeFilename(_projectName) + '.ohsnap';

        try {
            if (window.__TAURI__) {
                const { dialog, fs } = window.__TAURI__;
                const path = await dialog.save({
                    title:       'Save Oh Snap! Project',
                    defaultPath: filename,
                    filters:     [{ name: 'Oh Snap! Project', extensions: ['ohsnap'] }],
                });
                if (path) {
                    await fs.writeTextFile(path, json);
                    _saveDraft(project);
                    clearDirty();
                }
            } else {
                _browserDownload(json, filename, 'application/json');
                _saveDraft(project);
                clearDirty();
            }
        } catch (err) {
            console.error('Save failed:', err);
            alert('Save failed: ' + err.message);
        }
    }

    // --- LOAD ---

    async function load() {
        try {
            let json = null;

            if (window.__TAURI__) {
                const { dialog, fs } = window.__TAURI__;
                const path = await dialog.open({
                    title:   'Open Oh Snap! Project',
                    filters: [{ name: 'Oh Snap! Project', extensions: ['ohsnap'] }],
                    multiple: false,
                });
                if (!path) return;
                json = await fs.readTextFile(path);
            } else {
                json = await _browserFileOpen(['.ohsnap', 'application/json']);
            }

            if (!json) return;

            const project = JSON.parse(json);
            if (!project.overrides) throw new Error('Not a valid Oh Snap! project file.');

            _projectName = project.name || 'Imported Skin';
            _created     = project.created || new Date().toISOString();
            updateProjectName(_projectName);
            controlsApplyExternal(project.overrides);
            clearDirty();

        } catch (err) {
            console.error('Load failed:', err);
            alert('Could not open project: ' + err.message);
        }
    }

    // --- EXPORT CSS ---

    function exportCss() {
        const overrides = controlsGetOverrides();
        if (!Object.keys(overrides).length) {
            alert('Nothing to export — no overrides have been set.');
            return;
        }

        const props = Object.entries(overrides)
            .map(([p, v]) => `  ${p}: ${v};`)
            .join('\n');

        const css = `/**
 * Oh Snap! CSS Variable Overrides
 * Skin:     ${_skinSlug}
 * Project:  ${_projectName}
 * Exported: ${new Date().toISOString()}
 *
 * To use: add this block to your skin's style.css, or paste it into
 * SnapSmack Admin → Pimp → Custom CSS.
 */
:root {
${props}
}
`;
        _browserDownload(css, _safeFilename(_projectName) + '.css', 'text/css');
    }

    // --- PUSH TO SITE ---

    async function pushToSite(apiClient) {
        const overrides = controlsGetOverrides();
        if (!Object.keys(overrides).length) {
            alert('Nothing to push — no overrides have been set.');
            return;
        }

        const btn = document.getElementById('btn-push');
        if (btn) { btn.disabled = true; btn.textContent = 'Pushing…'; }

        try {
            await apiClient.pushVars(overrides);
            clearDirty();
            if (btn) { btn.textContent = 'Pushed ✓'; setTimeout(() => { btn.textContent = 'Push to Site'; btn.disabled = false; }, 2000); }
        } catch (err) {
            console.error('Push failed:', err);
            alert('Push failed: ' + err.message);
            if (btn) { btn.textContent = 'Push to Site'; btn.disabled = false; }
        }
    }

    // --- DRAFT (auto-save) ---

    function saveDraftNow() {
        _saveDraft(_buildProject());
    }

    function _saveDraft(project) {
        try {
            const drafts = _allDrafts();
            drafts[_skinSlug || '__default'] = project;
            localStorage.setItem(DRAFT_KEY, JSON.stringify(drafts));
        } catch { /* quota exceeded — ignore */ }
    }

    function _loadDraft(skinSlug) {
        try {
            const drafts = _allDrafts();
            return drafts[skinSlug || '__default'] || null;
        } catch { return null; }
    }

    function _allDrafts() {
        try { return JSON.parse(localStorage.getItem(DRAFT_KEY) || '{}'); }
        catch { return {}; }
    }

    // --- UTILS ---

    function _buildProject() {
        return {
            version:   SCHEMA_VER,
            skin_slug: _skinSlug,
            name:      _projectName,
            created:   _created || new Date().toISOString(),
            modified:  new Date().toISOString(),
            overrides: controlsGetOverrides(),
        };
    }

    function _safeFilename(name) {
        return name.replace(/[^a-z0-9_\-]+/gi, '-').replace(/^-+|-+$/g, '') || 'ohsnap-project';
    }

    function _browserDownload(content, filename, mimeType) {
        const blob = new Blob([content], { type: mimeType });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 1000);
    }

    function _browserFileOpen(accept) {
        return new Promise((resolve, reject) => {
            const input    = document.createElement('input');
            input.type     = 'file';
            input.accept   = accept.join(',');
            input.onchange = () => {
                const file = input.files?.[0];
                if (!file) { resolve(null); return; }
                const reader = new FileReader();
                reader.onload  = e => resolve(e.target.result);
                reader.onerror = () => reject(new Error('Could not read file'));
                reader.readAsText(file);
            };
            input.click();
        });
    }

    return { setContext, updateProjectName, save, load, exportCss, pushToSite, saveDraftNow };

})();
// ===== SNAPSMACK EOF =====
