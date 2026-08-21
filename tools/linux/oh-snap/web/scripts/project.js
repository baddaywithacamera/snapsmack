/** OH SNAP! — offline-first project manager, schema v2. */
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

const OhSnapProject = (() => {
    const DRAFT_KEY = 'ohsnap_draft_projects_v2';
    const SCHEMA_VERSION = 2;
    const MAX_HISTORY = 60;
    let project = null;
    let savedPath = null;
    let history = [];
    let historyIndex = -1;
    let restoring = false;

    const clone = value => JSON.parse(JSON.stringify(value));
    const id = () => crypto.randomUUID?.() || `ohsnap-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const previewContent = () => ({
        site_name: 'My Photo Blog', tagline: 'Photographs and field notes', author: 'Photographer', posts: [
            { id: 'fixture-1', title: 'The Long Way Home', description: 'A quiet road, late light, and enough time to notice.', created_at: '2026-08-21T12:00:00Z', width: 1600, height: 1067, colour: '#51606b' },
            { id: 'fixture-2', title: 'After the Rain', description: 'Water holding the last of the sky.', created_at: '2026-08-14T12:00:00Z', width: 1067, height: 1600, colour: '#435b59' },
            { id: 'fixture-3', title: 'Small Hours', description: 'The city before it remembers itself.', created_at: '2026-08-07T12:00:00Z', width: 1600, height: 900, colour: '#403d52' },
        ],
    });

    function seed(context = {}) {
        const now = new Date().toISOString();
        return {
            schema_version: SCHEMA_VERSION, project_id: id(), name: context.name || 'Untitled Offline Skin',
            mode: context.mode || 'SMACKONEOUT', package_lane: 'SHAREABLE', skin_slug: context.skin_slug || 'ohsnap-new-skin',
            source: context.source || 'offline', connected_source: context.connected_source || null,
            contract: context.contract || { schema_version: '1.0', source: 'bundled-snapshot' },
            base_css: context.base_css || '', user_css: '', overrides: {}, required_scripts: [], required_styles: [],
            preview_content: context.preview_content || previewContent(), created: now, modified: now,
            generation_history: [], validation_state: { errors: [], warnings: [] },
        };
    }

    function newProject(context = {}) {
        project = seed(context); savedPath = null; history = []; historyIndex = -1;
        updateProjectName(project.name); applyProject(project); recordCheckpoint('Project created'); saveDraftNow();
        return clone(project);
    }

    function setContext(skinSlug, context = {}) {
        const draft = Object.values(allDrafts()).find(p => p.skin_slug === skinSlug);
        if (!draft) return newProject({ ...context, skin_slug: skinSlug || context.skin_slug });
        project = migrate(draft); savedPath = null; updateProjectName(project.name); applyProject(project);
        restoreHistory(project.generation_history); return clone(project);
    }

    function current() { if (!project) newProject(); sync(); return clone(project); }
    function updateProjectName(name) { if (project) project.name = name; const el = document.getElementById('toolbar-project-name'); if (el) el.textContent = name; }
    function updatePreviewContent(changes, count = null) {
        if (!project) return;
        project.preview_content = { ...project.preview_content, ...changes };
        if (count !== null) {
            const colours = ['#51606b','#435b59','#403d52','#70564b','#384b63','#645b3d'];
            project.preview_content.posts = Array.from({ length: Math.max(0, Number(count) || 0) }, (_, i) => ({
                id: `fixture-${i + 1}`, title: i === 0 ? (changes.lead_title || 'The Long Way Home') : `Sample Photograph ${i + 1}`,
                description: i === 0 ? (changes.lead_caption || 'A quiet road, late light, and enough time to notice.') : `Deterministic preview caption ${i + 1}.`,
                created_at: new Date(Date.UTC(2026,7,21-i)).toISOString(), width: i % 3 === 0 ? 1600 : (i % 3 === 1 ? 1067 : 1200), height: i % 3 === 0 ? 1067 : (i % 3 === 1 ? 1600 : 1200), colour: colours[i % colours.length],
            }));
        }
        OhSnapPreview.setProject(project); recordCheckpoint('Preview content changed'); window.markDirty?.();
    }
    function applyGeneration(result) {
        if (!project) return;
        controlsApplyExternal({ ...project.overrides, ...(result.overrides || {}) });
        if (result.user_css) controlsSetUserCss(`${project.user_css || ''}\n${result.user_css}`.trim());
        project.required_scripts = [...new Set([...(project.required_scripts || []), ...(result.required_scripts || [])])];
        project.required_styles = [...new Set([...(project.required_styles || []), ...(result.required_styles || [])])];
        sync(); recordCheckpoint(result.summary || 'AI design applied'); window.markDirty?.();
        return validateProject();
    }

    async function save() {
        sync(); const filename = safeName(project.name) + '.ohsnap';
        try {
            if (window.blink?.call) {
                // dialog_save replaces the native Tauri save dialog. The Blink runtime
                // is stdlib-only with no native file chooser, so it returns a path under
                // the shared projects dir. See app.py TODO(port): native file picker.
                savedPath ||= await window.blink.call('dialog_save', filename);
                if (!savedPath) return false;
                await window.blink.call('save_project', savedPath, JSON.stringify(project, null, 2));
            } else download(JSON.stringify(project, null, 2), filename, 'application/json');
            saveDraftNow(); window.clearDirty?.(); window.showStatus?.('Project saved.'); return true;
        } catch (err) { alert(`Save failed: ${err.message || err}`); return false; }
    }

    async function load() {
        try {
            let json;
            if (window.blink?.call) {
                // dialog_open replaces the native Tauri open dialog. The Blink runtime
                // has no native file chooser; it returns the most recent project path in
                // the shared projects dir (or null). See app.py TODO(port): file picker.
                const path = await window.blink.call('dialog_open');
                if (!path) return null; savedPath = path; json = await window.blink.call('load_project', path);
            } else { json = await browserOpen(); savedPath = null; }
            if (!json) return null; project = migrate(JSON.parse(json)); validate(project);
            updateProjectName(project.name); applyProject(project); restoreHistory(project.generation_history); saveDraftNow(); window.clearDirty?.(); return clone(project);
        } catch (err) { alert(`Could not open project: ${err.message || err}`); return null; }
    }

    function exportCss() {
        sync(); const vars = Object.entries(project.overrides).map(([p, v]) => `  ${p}: ${v};`).join('\n');
        if (!vars && !project.user_css.trim()) return alert('Nothing to export—this project has no CSS changes.');
        const css = `/**\n * OH SNAP CSS Overrides\n * Project: ${project.name}\n * Target skin: ${project.skin_slug}\n * CSS only—not an installable skin package.\n */\n${vars ? `:root {\n${vars}\n}\n` : ''}${project.user_css}`;
        download(css, safeName(project.name) + '-overrides.css', 'text/css');
    }

    function validateProject() {
        sync();
        const errors = [], warnings = [];
        const css = `${project.base_css || ''}\n${project.user_css || ''}`;
        if (!/^[a-z0-9][a-z0-9-]*$/i.test(project.skin_slug || '')) errors.push('Skin slug must contain only letters, numbers, and hyphens.');
        if (project.package_lane !== 'SHAREABLE') errors.push('Only SHAREABLE packages are permitted.');
        if (!window.OH_SNAP_CONTRACT?.modes?.[project.mode]) errors.push(`Unsupported install mode: ${project.mode}`);
        if ((css.match(/\{/g) || []).length !== (css.match(/\}/g) || []).length) errors.push('CSS braces are unbalanced.');
        if (/@import\b/i.test(css)) errors.push('Remote/imported CSS is forbidden; declare shared styles instead.');
        if (/url\s*\(\s*['"]?https?:/i.test(css)) errors.push('Remote CSS asset URLs are forbidden in SHAREABLE packages.');
        if (/<\/?script|javascript:/i.test(css)) errors.push('Script content is not valid CSS.');
        const inventory = window.OH_SNAP_CONTRACT?.asset_inventory || {};
        const known = new Set([...(inventory.javascript || []), ...(inventory.css || [])].flatMap(x => [x.handle,x.id,x.path,x.file].filter(Boolean)));
        [...(project.required_scripts || []), ...(project.required_styles || [])].forEach(handle => { if (!known.has(handle)) errors.push(`Unknown shared dependency: ${handle}`); });
        if (!project.preview_content?.posts?.length) warnings.push('The selected empty-site scenario has no post-content coverage.');
        if (!project.user_css?.trim() && !Object.keys(project.overrides || {}).length) warnings.push('This package uses the unmodified shell defaults.');
        project.validation_state = { errors, warnings };
        window.dispatchEvent(new CustomEvent('ohsnap-validation', { detail: clone(project.validation_state) }));
        return clone(project.validation_state);
    }

    async function exportPackage() {
        const report = validateProject();
        if (report.errors.length) return alert(`Package blocked:\n\n${report.errors.join('\n')}`);
        const mode = window.OH_SNAP_CONTRACT.modes[project.mode];
        const manifest = {
            schema_version: 1, name: project.name, slug: project.skin_slug, version: '0.1.0', author: 'OH SNAP user',
            description: `SHAREABLE skin generated by OH SNAP for ${project.mode}.`, status: 'draft', modes: [mode.profile],
            install_mode: mode.install_mode, oh_snap_ready: true, package_lane: 'SHAREABLE', css_variables: mode.variables,
            required_scripts: project.required_scripts || [], required_styles: project.required_styles || [],
            ohsnap_contract_schema: window.OH_SNAP_CONTRACT.contract_schema,
        };
        const varCss = Object.entries(project.overrides || {}).map(([p,v]) => `  ${p}: ${v};`).join('\n');
        const style = `${project.base_css || mode.shell_css}\n${varCss ? `:root {\n${varCss}\n}\n` : ''}${project.user_css || ''}\n`;
        const validation = { status: 'pass', errors: [], warnings: report.warnings, contract_schema: window.OH_SNAP_CONTRACT.contract_schema, mode: project.mode, required_hooks: mode.required_hooks };
        const readme = `# ${project.name}\n\nGenerated by OH SNAP as a SHAREABLE SnapSmack skin.\n\n- Mode: ${project.mode} (${mode.install_mode})\n- No PHP or server-executable files\n- Validate and submit through the reviewed skin pipeline\n- Do not upload or unpack directly on a live site\n`;
        const files = [
            { path: 'manifest.json', content: JSON.stringify(manifest, null, 2) + '\n' },
            { path: 'style.css', content: style },
            { path: 'README.md', content: readme },
            { path: 'validation-report.json', content: JSON.stringify(validation, null, 2) + '\n' },
        ];
        if (!window.blink?.call) return alert('Run the desktop (Blink) build to create a validated ZIP package.');
        const path = await window.blink.call('dialog_save', `${safeName(project.name)}.zip`);
        if (!path) return;
        try { await window.blink.call('export_shareable_package', path, files); window.showStatus?.('Validated SHAREABLE skin package exported.'); }
        catch (err) { alert(`Package export failed: ${err.message || err}`); }
    }

    async function pushToSite(client) {
        if (!client || !project?.connected_source) return alert('This is an offline project. Connect to an OH SNAP-ready skin before pushing variable overrides.');
        if (!project.connected_source.oh_snap_ready) return alert('This skin has not declared OH SNAP compatibility. Export CSS for review instead.');
        const vars = controlsGetOverrides(); if (!Object.keys(vars).length) return alert('Nothing to push.');
        const target = project.connected_source.site_name || project.connected_source.url;
        if (!confirm(`Push ${Object.keys(vars).length} variable override(s) to ${target}\nSkin: ${project.skin_slug}\n\nNo skin package will be uploaded or activated.`)) return;
        const btn = document.getElementById('btn-push'); if (btn) { btn.disabled = true; btn.textContent = 'Pushing…'; }
        try { await client.pushVars(project.skin_slug, vars); window.showStatus?.(`Variable overrides pushed to ${target}.`); }
        catch (err) { alert(`Push failed: ${err.message || err}`); }
        finally { if (btn) { btn.disabled = false; btn.textContent = 'Push Variable Overrides'; } }
    }

    function recordCheckpoint(label = 'Design changed') {
        if (restoring || !project) return; sync();
        const state = { at: new Date().toISOString(), label, overrides: clone(project.overrides), user_css: project.user_css };
        const prior = history[historyIndex];
        if (prior && JSON.stringify(prior.overrides) === JSON.stringify(state.overrides) && prior.user_css === state.user_css) return;
        history = history.slice(0, historyIndex + 1); history.push(state); if (history.length > MAX_HISTORY) history.shift();
        historyIndex = history.length - 1; project.generation_history = clone(history); renderHistory();
    }

    function undo() { restore(historyIndex - 1); }
    function redo() { restore(historyIndex + 1); }
    function restore(index) {
        if (index < 0 || index >= history.length || index === historyIndex) return;
        historyIndex = index; const state = history[index]; restoring = true; controlsApplyExternal(state.overrides || {});
        controlsSetUserCss(state.user_css || ''); restoring = false;
        sync(); window.markDirty?.(); renderHistory();
    }

    function saveDraftNow() {
        if (!project) return; sync();
        try { const all = allDrafts(); all[project.project_id] = project; localStorage.setItem(DRAFT_KEY, JSON.stringify(all)); }
        catch (err) { window.showStatus?.(`Autosave failed: ${err.message || err}`, true); }
    }

    function sync() { if (!project) return; project.overrides = controlsGetOverrides(); project.user_css = controlsGetUserCss(); project.modified = new Date().toISOString(); project.generation_history = clone(history); OhSnapPreview.setProject?.(project); }
    function applyProject(p) { controlsApplyExternal(p.overrides || {}); controlsSetUserCss(p.user_css || ''); OhSnapPreview.setProject?.(p); }
    function restoreHistory(items) { history = Array.isArray(items) ? items.slice(-MAX_HISTORY) : []; historyIndex = history.length - 1; if (!history.length) recordCheckpoint('Project opened'); renderHistory(); }
    function renderHistory() {
        const list = document.getElementById('history-list'); if (!list) return; list.innerHTML = '';
        if (!history.length) list.textContent = 'No changes yet.';
        history.slice().reverse().forEach((item, reverse) => { const actual = history.length - 1 - reverse; const row = document.createElement('div'); row.className = 'history-entry'; row.textContent = `${actual === historyIndex ? '● ' : ''}${item.label} — ${new Date(item.at).toLocaleTimeString()}`; list.appendChild(row); });
        const u = document.getElementById('btn-undo'), r = document.getElementById('btn-redo'); if (u) u.disabled = historyIndex <= 0; if (r) r.disabled = historyIndex >= history.length - 1;
    }

    function migrate(input) {
        if (!input || typeof input !== 'object') throw new Error('Project file is not an object.');
        const version = Number(input.schema_version || input.version || 1); if (version > SCHEMA_VERSION) throw new Error('This project needs a newer OH SNAP version.');
        if (version === 1) return { ...seed({ name: input.name || 'Imported Skin', skin_slug: input.skin_slug || 'imported-skin' }), overrides: input.overrides || {}, created: input.created || new Date().toISOString(), modified: input.modified || new Date().toISOString() };
        return { ...seed(), ...input, schema_version: SCHEMA_VERSION };
    }
    function validate(p) { if (!p.project_id || !p.name || !p.preview_content || !p.overrides) throw new Error('Project is missing required schema-v2 fields.'); if (p.package_lane !== 'SHAREABLE') throw new Error('Only SHAREABLE projects are supported.'); }
    function allDrafts() { try { return JSON.parse(localStorage.getItem(DRAFT_KEY) || '{}'); } catch { return {}; } }
    function safeName(name) { return String(name).replace(/[^a-z0-9_-]+/gi, '-').replace(/^-+|-+$/g, '') || 'ohsnap-project'; }
    function download(content, filename, type) { const url = URL.createObjectURL(new Blob([content], { type })); const a = Object.assign(document.createElement('a'), { href: url, download: filename }); document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 1000); }
    function browserOpen() { return new Promise((resolve, reject) => { const input = document.createElement('input'); input.type = 'file'; input.accept = '.ohsnap,application/json'; input.onchange = () => { const file = input.files?.[0]; if (!file) return resolve(null); const reader = new FileReader(); reader.onload = e => resolve(e.target.result); reader.onerror = () => reject(new Error('Could not read file')); reader.readAsText(file); }; input.click(); }); }

    return { newProject, setContext, current, updateProjectName, updatePreviewContent, applyGeneration, save, load, exportCss, validateProject, exportPackage, pushToSite, saveDraftNow, recordCheckpoint, undo, redo };
})();
// ===== SNAPSMACK EOF =====
