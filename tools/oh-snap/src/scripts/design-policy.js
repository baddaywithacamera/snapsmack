/** SNAPSMACK_EOF_HEADER: deterministic shared design validation. */
const OhSnapPolicy = (() => {
    function definitions(groups) {
        return Object.assign({}, ...Object.values(groups || {}).map(group => group.vars || {}));
    }
    function normalizeOverrides(values, groups) {
        if (!values || typeof values !== 'object' || Array.isArray(values)) throw new Error('CSS overrides must be an object.');
        const defs = definitions(groups), out = {};
        for (const [key, raw] of Object.entries(values)) {
            const meta = defs[key];
            if (!meta) throw new Error(`This skin does not declare ${key}.`);
            if (typeof raw !== 'string' && typeof raw !== 'number') throw new Error(`Invalid value for ${key}.`);
            let value = String(raw).trim();
            if (!value || /[;{}<>\\]|url\s*\(|expression\s*\(/i.test(value)) throw new Error(`Unsafe value for ${key}.`);
            if (meta.type === 'range') {
                const unit = meta.unit || '';
                if (unit && value.endsWith(unit)) value = value.slice(0, -unit.length).trim();
                const number = Number(value);
                if (!/^-?\d+(\.\d+)?$/.test(value) || !Number.isFinite(number) || number < Number(meta.min ?? -Infinity) || number > Number(meta.max ?? Infinity)) throw new Error(`${key} must be between ${meta.min} and ${meta.max}${unit}.`);
                value = String(number) + unit;
            } else if (meta.type === 'color') {
                if (!/^#[0-9a-f]{3,8}$/i.test(value) && !/^rgba?\([\d\s.,%]+\)$/i.test(value)) throw new Error(`${key} needs a hex or RGB colour.`);
            } else if (meta.type === 'select' && !(meta.options || []).some(option => option.value === value)) {
                throw new Error(`Unknown option for ${key}.`);
            }
            out[key] = value;
        }
        return out;
    }
    function validateCss(css) {
        if (typeof css !== 'string' || css.length > 1_000_000) throw new Error('CSS is missing or larger than 1 MB.');
        const clean = css.replace(/\/\*[\s\S]*?\*\//g, '');
        if (/[<>\\]/.test(clean) || /@import\b|url\s*\(|expression\s*\(|javascript:|behavior\s*:|-moz-binding/i.test(clean)) throw new Error('Use CSS without imports, external files, escaped URLs, HTML or executable content. Images come from the site; use system fonts and CSS gradients.');
        if ((clean.match(/\{/g) || []).length !== (clean.match(/\}/g) || []).length) throw new Error('CSS braces are unbalanced.');
        return css;
    }
    function variablesCss(values, groups) {
        const normalized = normalizeOverrides(values, groups);
        return `:root {\n${Object.entries(normalized).map(([key,value]) => `  ${key}: ${value};`).join('\n')}\n}`;
    }
    function validateDependencies(result) {
        if ((result.required_scripts || []).length || (result.required_styles || []).length) throw new Error('This starter supports CSS design. Extra engines need reviewed template hooks and cannot be added by an AI response. Ask for a CSS layout instead.');
    }
    return { definitions, normalizeOverrides, validateCss, variablesCss, validateDependencies };
})();
// ===== SNAPSMACK EOF =====
