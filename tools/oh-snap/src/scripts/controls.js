/**
 * OH SNAP! — Controls panel builder
 * v0.1.0
 *
 * Reads the css_variables map returned by the ohsnap/skin endpoint and
 * dynamically builds the Colours, Type, and Layout sidebar panels.
 *
 * Every control change:
 *   1. Updates the in-memory currentOverrides map
 *   2. Calls OhSnapPreview.applyOverrides() to push the change into the iframe
 *   3. Syncs the raw CSS editor textarea
 *   4. Marks the project as dirty
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// --- STATE ---

const currentOverrides = {};   // { '--var-name': 'value', ... }
let   _skinVariables   = {};   // The css_variables map from the API
let   _controlInputs   = {};   // { '--var-name': HTMLInputElement }

// --- PUBLIC API ---

/**
 * Initialise the controls panel from skin data returned by ohsnap/skin.
 * @param {Object} skinData   The full response from SnapSmackAPI.skin()
 */
function controlsInit(skinData) {
    _skinVariables = skinData.css_variables || {};
    _controlInputs = {};

    if (skinData.oh_snap_ready) {
        _buildFullControls(skinData.css_variables);
    } else {
        _buildLegacyNotice();
    }
}

/**
 * Apply a set of overrides from an external source (AI, project load).
 * Updates the controls UI and fires the preview refresh.
 * @param {Object} overrides  { '--var-name': 'value', ... }
 */
function controlsApplyExternal(overrides) {
    Object.entries(overrides).forEach(([prop, val]) => {
        currentOverrides[prop] = val;
        if (_controlInputs[prop]) {
            _controlInputs[prop].value = val;
            // Trigger label update for ranges
            const label = _controlInputs[prop].closest('.ctrl-row')?.querySelector('.ctrl-value');
            if (label) label.textContent = val;
        }
    });
    _syncCssEditor();
    OhSnapPreview.applyOverrides(currentOverrides);
}

/**
 * Return the current overrides map (for save/export/push).
 */
function controlsGetOverrides() {
    return { ...currentOverrides };
}

/**
 * Reset all controls to their manifest defaults.
 */
function controlsReset() {
    Object.entries(_skinVariables).forEach(([_group, groupDef]) => {
        Object.entries(groupDef.vars || {}).forEach(([prop, meta]) => {
            currentOverrides[prop] = meta.default;
            if (_controlInputs[prop]) {
                _controlInputs[prop].value = meta.default;
            }
        });
    });
    _syncCssEditor();
    OhSnapPreview.applyOverrides(currentOverrides);
}

// --- INTERNAL BUILDERS ---

function _buildLegacyNotice() {
    document.getElementById('tab-colours').innerHTML = `
        <div class="panel-empty">
            <p>This skin uses the legacy manifest system.<br>
            Controls aren't available — use the CSS tab to edit directly.</p>
        </div>`;
}

function _buildFullControls(cssVars) {
    // Sort: colour-type vars go into Colours tab, typography into Type, rest into Layout
    const coloursGroups    = {};
    const typographyGroups = {};
    const layoutGroups     = {};

    Object.entries(cssVars).forEach(([groupKey, groupDef]) => {
        const colVars  = {};
        const typVars  = {};
        const layVars  = {};

        Object.entries(groupDef.vars || {}).forEach(([prop, meta]) => {
            if (meta.type === 'color') {
                colVars[prop] = meta;
            } else if (groupKey === 'TYPOGRAPHY') {
                typVars[prop] = meta;
            } else {
                layVars[prop] = meta;
            }
        });

        if (Object.keys(colVars).length)  coloursGroups[groupDef.label || groupKey]    = colVars;
        if (Object.keys(typVars).length)  typographyGroups[groupDef.label || groupKey] = typVars;
        if (Object.keys(layVars).length)  layoutGroups[groupDef.label || groupKey]     = layVars;
    });

    _renderGroupsIntoTab('tab-colours',    coloursGroups,    'color');
    _renderGroupsIntoTab('tab-typography', typographyGroups, 'range');
    _renderGroupsIntoTab('tab-layout',     layoutGroups,     'range');
}

function _renderGroupsIntoTab(tabId, groups, defaultType) {
    const tab = document.getElementById(tabId);
    if (!Object.keys(groups).length) {
        tab.innerHTML = '<div class="panel-empty"><p>No controls for this category.</p></div>';
        return;
    }

    tab.innerHTML = '';

    Object.entries(groups).forEach(([groupLabel, vars]) => {
        const section = document.createElement('div');
        section.className = 'ctrl-section';

        const heading = document.createElement('div');
        heading.className = 'ctrl-section-heading';
        heading.textContent = groupLabel;
        section.appendChild(heading);

        Object.entries(vars).forEach(([prop, meta]) => {
            const row = _buildControlRow(prop, meta);
            section.appendChild(row);
        });

        tab.appendChild(section);
    });
}

function _buildControlRow(prop, meta) {
    const row      = document.createElement('div');
    row.className  = 'ctrl-row';
    row.dataset.prop = prop;

    const label    = document.createElement('label');
    label.className = 'ctrl-label';
    label.textContent = meta.label;
    label.title = prop;  // Show the CSS var name on hover
    row.appendChild(label);

    const inputWrap = document.createElement('div');
    inputWrap.className = 'ctrl-input-wrap';

    if (meta.type === 'color') {
        inputWrap.appendChild(_buildColorInput(prop, meta));
    } else if (meta.type === 'range') {
        inputWrap.appendChild(_buildRangeInput(prop, meta));
    } else if (meta.type === 'select') {
        inputWrap.appendChild(_buildSelectInput(prop, meta));
    }

    row.appendChild(inputWrap);
    return row;
}

function _buildColorInput(prop, meta) {
    const wrap = document.createElement('div');
    wrap.className = 'ctrl-color-wrap';

    const swatch = document.createElement('input');
    swatch.type      = 'color';
    swatch.className = 'ctrl-color';
    swatch.value     = currentOverrides[prop] || meta.default;
    swatch.title     = prop;

    // Text field that shows/allows hex entry
    const hex = document.createElement('input');
    hex.type        = 'text';
    hex.className   = 'ctrl-hex';
    hex.value       = swatch.value.toUpperCase();
    hex.maxLength   = 7;
    hex.spellcheck  = false;

    // Keep swatch and hex in sync
    swatch.addEventListener('input', () => {
        hex.value = swatch.value.toUpperCase();
        _onControlChange(prop, swatch.value);
    });

    hex.addEventListener('input', () => {
        const val = hex.value.trim();
        if (/^#[0-9a-f]{6}$/i.test(val)) {
            swatch.value = val;
            _onControlChange(prop, val);
        }
    });

    hex.addEventListener('blur', () => {
        hex.value = swatch.value.toUpperCase();
    });

    _controlInputs[prop] = swatch;
    currentOverrides[prop] = swatch.value;

    wrap.appendChild(swatch);
    wrap.appendChild(hex);
    return wrap;
}

function _buildRangeInput(prop, meta) {
    const wrap = document.createElement('div');
    wrap.className = 'ctrl-range-wrap';

    const slider = document.createElement('input');
    slider.type      = 'range';
    slider.className = 'ctrl-range';
    slider.min       = meta.min  || '0';
    slider.max       = meta.max  || '100';
    slider.step      = meta.step || '1';
    slider.value     = currentOverrides[prop] || meta.default;

    const valueDisplay = document.createElement('span');
    valueDisplay.className = 'ctrl-value';
    valueDisplay.textContent = slider.value + (meta.unit || '');

    slider.addEventListener('input', () => {
        const val = slider.value + (meta.unit || '');
        valueDisplay.textContent = val;
        _onControlChange(prop, slider.value);
    });

    _controlInputs[prop] = slider;
    currentOverrides[prop] = slider.value;

    wrap.appendChild(slider);
    wrap.appendChild(valueDisplay);
    return wrap;
}

function _buildSelectInput(prop, meta) {
    const sel = document.createElement('select');
    sel.className = 'ctrl-select';

    (meta.options || []).forEach(opt => {
        const o = document.createElement('option');
        o.value = opt.value;
        o.textContent = opt.label;
        if ((currentOverrides[prop] || meta.default) === opt.value) o.selected = true;
        sel.appendChild(o);
    });

    sel.addEventListener('change', () => {
        _onControlChange(prop, sel.value);
    });

    _controlInputs[prop] = sel;
    currentOverrides[prop] = sel.value || meta.default;
    return sel;
}

function _onControlChange(prop, value) {
    currentOverrides[prop] = value;
    OhSnapPreview.applyOverrides(currentOverrides);
    _syncCssEditor();
    markDirty();
}

// --- CSS EDITOR SYNC ---

function _syncCssEditor() {
    const editor = document.getElementById('css-editor');
    if (!editor) return;

    const lines = Object.entries(currentOverrides)
        .map(([prop, val]) => `  ${prop}: ${val};`)
        .join('\n');

    editor.value = `:root {\n${lines}\n}`;
}

// Wire the CSS editor — when the user types directly, parse and apply
document.addEventListener('DOMContentLoaded', () => {
    const editor = document.getElementById('css-editor');
    if (!editor) return;

    editor.addEventListener('input', () => {
        _parseCssEditorIntoOverrides(editor.value);
    });
});

function _parseCssEditorIntoOverrides(css) {
    // Extract CSS custom property declarations from the editor content
    const matches = [...css.matchAll(/(-{2}[a-z][a-z0-9-]*)\s*:\s*([^;}\n]+)/gi)];
    if (!matches.length) return;

    matches.forEach(([, prop, val]) => {
        const trimmed = val.trim();
        if (trimmed) {
            currentOverrides[prop] = trimmed;
            if (_controlInputs[prop]) {
                _controlInputs[prop].value = trimmed;
            }
        }
    });

    OhSnapPreview.applyOverrides(currentOverrides);
    markDirty();
}
// ===== SNAPSMACK EOF =====
