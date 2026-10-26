/**
 * OH SNAP! — AI provider integration
 * v0.1.0
 *
 * Sends user messages to the configured AI provider and interprets the
 * response as CSS variable overrides for the active skin.
 *
 * The system prompt instructs the model to respond ONLY with a JSON object
 * of CSS custom property overrides: { "--var-name": "value", ... }
 * No prose. No code fences. Just the object, ready to parse.
 *
 * Supported providers:
 *   claude  — Anthropic Messages API (claude-sonnet-4-6)
 *   gemini  — Google Generative Language API (gemini-2.0-flash)
 *   openai  — OpenAI Chat Completions (gpt-4o)
 *   deepseek — DeepSeek Chat Completions (deepseek-chat)
 *   kimi    — Moonshot Kimi Chat Completions (kimi-latest)
 *   ollama  — Local Ollama generate endpoint (configurable model)
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


const OhSnapAI = (() => {

    // System prompt sent to every provider.
    // The skin variable map is injected at call time so the model knows
    // exactly which properties it can control.
    const _systemPromptBase = `You are Oh Snap!, the AI design assistant for SnapSmack skin designer.
Your job is to help users modify the appearance of their photography website.

When the user describes a change, respond with ONLY this raw JSON shape:
{"overrides":{"--variable":"value"},"user_css":"optional safe CSS","required_scripts":[],"required_styles":[],"summary":"short description"}
No explanation. No markdown. No code fences. Just the JSON object.

Example valid response:
{"overrides":{"--bg-page":"#1a1a2e","--text-primary":"#e0e0ff"},"user_css":"","required_scripts":[],"required_styles":[],"summary":"Deep blue palette"}

Rules:
- Only use CSS custom properties from the list provided below.
- Values must be valid CSS values for the property type.
- Colors must be hex (#rrggbb) or rgb(). No color names.
- If the user asks something that isn't a skin change, respond with {} and nothing else.
- user_css may contain CSS only: never scripts, remote URLs, @import, or server code.
- required_scripts and required_styles may contain ONLY exact handles from the shared library.
- If you're not sure which variables to change, make your best judgment.
- Design CSS for the supplied starter HTML. You can change grids, image sizing, typography, framing, spacing and responsive layout with selectors. Do not invent markup, classes or interactive features.
- required_scripts and required_styles must be empty arrays in this version. Additional engines need separately reviewed template hooks.
- user_css is additional CSS appended after the current design. Do not use url(), @import, backslash escapes, external fonts, HTML or JavaScript. Use system fonts and CSS gradients.

Available CSS variables for this skin:
`;

    // --- PUBLIC ---

    /**
     * Send a user message and return parsed CSS variable overrides.
     * @param {string} userMessage   The user's chat message
     * @param {Object} variables     The skin's css_variables map (for context)
     * @param {Object} [library]     The shared resources library (asset inventory),
     *                               or null when it isn't cleanly available. When
     *                               given, the model is told what shared engines /
     *                               CSS / fonts exist and how a skin declares them,
     *                               so it stops designing blind. Absent = it is told
     *                               plainly it only has this skin's own variables.
     * @returns {Promise<Object>}    CSS variable overrides { '--var': 'val' }
     */
    let conversation = [];
    async function send(userMessage, variables, library, referenceImage = null, project = null) {
        const s        = OhSnapSettings.load();
        const provider = s.ai_provider;

        if (!provider || provider === 'none') {
            throw new Error('No AI provider configured. Open Settings to add an API key.');
        }

        conversation = (project?.ai_conversation || []).slice(-8);
        const snapshot = {mode:project?.mode, overrides:project?.overrides || {}, user_css:(project?.user_css || '').slice(-30000), base_css:(project?.base_css || '').slice(0,15000)};
        const markup = project?.source === 'connected' ? 'Connected CSS study: use the declared variables; export CSS for review on the real site.' : ['photo','archive','page'].map(kind => window.OH_SNAP_CONTRACT.previews[kind]).join('\n').slice(0,22000);
        const systemPrompt = _systemPromptBase + _formatVariableList(variables)
                           + '\n\nCurrent design:\n' + JSON.stringify(snapshot) + '\n\nActual starter markup:\n' + markup;
        const validate = async pending => {
            const result = await pending;
            result.overrides = OhSnapPolicy.normalizeOverrides(result.overrides || {}, variables);
            OhSnapPolicy.validateCss(result.user_css || '');
            OhSnapPolicy.validateDependencies(result);
            return result;
        };

        switch (provider) {
            case 'claude':  return validate(_callClaude(s, systemPrompt, userMessage, referenceImage));
            case 'gemini':  return validate(_callGemini(s, systemPrompt, userMessage, referenceImage));
            case 'openai':  return validate(_callOpenAI(s, systemPrompt, userMessage, referenceImage));
            case 'deepseek': if (referenceImage) throw new Error('DeepSeek reference-image input is not enabled; choose Claude, Gemini, or OpenAI.'); return validate(_callDeepSeek(s, systemPrompt, userMessage));
            case 'kimi':    if (referenceImage) throw new Error('Kimi reference-image input is not enabled; choose Claude, Gemini, or OpenAI.'); return validate(_callKimi(s, systemPrompt, userMessage));
            case 'ollama':  if (referenceImage) throw new Error('This Ollama adapter is text-only; remove the image or choose a vision provider.'); return validate(_callOllama(s, systemPrompt, userMessage));
            default: throw new Error(`Unknown provider: ${provider}`);
        }
    }

    // --- PROVIDERS ---

    async function _callClaude(s, system, userMsg, image) {
        if (!s.claude_key) throw new Error('Claude API key not set. Open Settings.');

        const res = await _fetch('https://api.anthropic.com/v1/messages', {
            method: 'POST',
            headers: {
                'Content-Type':         'application/json',
                'x-api-key':            s.claude_key,
                'anthropic-version':    '2023-06-01',
                'anthropic-dangerous-direct-browser-access': 'true',
            },
            body: JSON.stringify({
                model:      s.claude_model || 'claude-sonnet-4-6',
                max_tokens: 4096,
                system,
                messages: [...conversation, { role: 'user', content: image ? [{ type:'image', source:{ type:'base64', media_type:image.type, data:image.base64 } }, { type:'text', text:userMsg }] : userMsg }],
            }),
        });

        const data = await res.json();
        if (!res.ok) throw new Error(data.error?.message || `Claude error ${res.status}`);

        const text = data.content?.[0]?.text || '{}';
        return _parseResult(text);
    }

    async function _callGemini(s, system, userMsg, image) {
        if (!s.gemini_key) throw new Error('Gemini API key not set. Open Settings.');

        const url = `https://generativelanguage.googleapis.com/v1beta/models/${encodeURIComponent(s.gemini_model || 'gemini-3.8-flash')}:generateContent`;

        const res = await _fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'x-goog-api-key': s.gemini_key },
            body: JSON.stringify({
                systemInstruction: { parts: [{ text: system }] },
                contents: [...conversation.map(turn=>({role:turn.role==='assistant'?'model':'user',parts:[{text:turn.content}]})), { role: 'user', parts: image ? [{ inlineData:{ mimeType:image.type, data:image.base64 } }, { text:userMsg }] : [{ text: userMsg }] }],
                generationConfig: { maxOutputTokens: 8192, temperature: 0.3, responseMimeType: 'application/json' },
            }),
        });

        const data = await res.json();
        if (!res.ok) throw new Error(data.error?.message || `Gemini error ${res.status}`);

        const text = data.candidates?.[0]?.content?.parts?.[0]?.text || '{}';
        return _parseResult(text);
    }

    async function _callOpenAI(s, system, userMsg, image) {
        if (!s.openai_key) throw new Error('OpenAI API key not set. Open Settings.');

        const res = await _fetch('https://api.openai.com/v1/chat/completions', {
            method: 'POST',
            headers: {
                'Content-Type':  'application/json',
                'Authorization': `Bearer ${s.openai_key}`,
            },
            body: JSON.stringify({
                model:      s.openai_model || 'gpt-4o',
                max_completion_tokens: 4096,
                messages: [
                    { role: 'system', content: system },
                    ...conversation,
                    { role: 'user', content: image ? [{type:'text',text:userMsg},{type:'image_url',image_url:{url:`data:${image.type};base64,${image.base64}`}}] : userMsg },
                ],
            }),
        });

        const data = await res.json();
        if (!res.ok) throw new Error(data.error?.message || `OpenAI error ${res.status}`);

        const text = data.choices?.[0]?.message?.content || '{}';
        return _parseResult(text);
    }

    async function _callDeepSeek(s, system, userMsg) {
        if (!s.deepseek_key) throw new Error('DeepSeek API key not set. Open Settings.');

        const res = await _fetch('https://api.deepseek.com/v1/chat/completions', {
            method: 'POST',
            headers: {
                'Content-Type':  'application/json',
                'Authorization': `Bearer ${s.deepseek_key}`,
            },
            body: JSON.stringify({
                model:      s.deepseek_model || 'deepseek-chat',
                max_tokens: 4096,
                messages: [
                    { role: 'system', content: system },
                    ...conversation, { role: 'user',   content: userMsg },
                ],
            }),
        });

        const data = await res.json();
        if (!res.ok) throw new Error(data.error?.message || `DeepSeek error ${res.status}`);

        const text = data.choices?.[0]?.message?.content || '{}';
        return _parseResult(text);
    }

    async function _callKimi(s, system, userMsg) {
        if (!s.kimi_key) throw new Error('Kimi API key not set. Open Settings.');

        const res = await _fetch('https://api.moonshot.ai/v1/chat/completions', {
            method: 'POST',
            headers: {
                'Content-Type':  'application/json',
                'Authorization': `Bearer ${s.kimi_key}`,
            },
            body: JSON.stringify({
                model:      s.kimi_model || 'kimi-latest',
                max_tokens: 4096,
                messages: [
                    { role: 'system', content: system },
                    ...conversation, { role: 'user',   content: userMsg },
                ],
            }),
        });

        const data = await res.json();
        if (!res.ok) throw new Error(data.error?.message || `Kimi error ${res.status}`);

        const text = data.choices?.[0]?.message?.content || '{}';
        return _parseResult(text);
    }

    async function _callOllama(s, system, userMsg) {
        const endpoint = (s.ollama_endpoint || 'http://localhost:11434').replace(/\/$/, '');
        const model    = s.ollama_model || 'llama3';

        if (!/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/i.test(endpoint)) throw new Error('Ollama must use a local localhost or 127.0.0.1 endpoint.');
        const res = await _fetch(`${endpoint}/api/generate`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                model,
                system,
                prompt: [...conversation.map(turn=>`${turn.role}: ${turn.content}`),`user: ${userMsg}`].join('\n'),
                stream: false,
                options: { num_predict: 4096, temperature: 0.3 },
            }),
        });

        const data = await res.json();
        if (!res.ok) throw new Error(data.error || `Ollama error ${res.status}`);

        const text = data.response || '{}';
        return _parseResult(text);
    }

    // --- UTILS ---
    async function _fetch(url, options) {
        try { return await fetch(url, {...options, redirect:'error', signal:AbortSignal.timeout(120000)}); }
        catch(err) { if(err.name==='TimeoutError'||err.name==='AbortError') throw new Error('AI request timed out after two minutes. Your design is unchanged.'); throw new Error(`AI connection failed: ${err.message}`); }
    }

    /**
     * Parse a model response into a CSS override object.
     * Handles raw JSON, JSON wrapped in markdown code fences, etc.
     */
    function _parseResult(text) {
        // Strip markdown code fences if present
        let clean = text.trim();
        clean = clean.replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/, '');

        // Extract the first {...} block in case there's prose around it
        const match = clean.match(/\{[^]*\}/);
        if (!match) throw new Error('AI returned no design JSON. Try again; the current design is unchanged.');

        try {
            const obj = JSON.parse(match[0]);
            if (!obj || Array.isArray(obj) || typeof obj !== 'object') throw new Error('Expected a design object.');
            const rawOverrides = obj.overrides && typeof obj.overrides === 'object' ? obj.overrides : obj;
            // Filter: only CSS custom properties with plausible values
            const safe = {};
            Object.entries(rawOverrides).forEach(([k, v]) => {
                if (typeof k === 'string' && k.startsWith('--')) {
                    safe[k] = v;
                }
            });
            const userCss = typeof obj.user_css === 'string' ? obj.user_css : '';
            const handles = value => Array.isArray(value) ? value.filter(x => typeof x === 'string') : [];
            return { overrides: safe, user_css: userCss, required_scripts: handles(obj.required_scripts), required_styles: handles(obj.required_styles), summary: typeof obj.summary === 'string' ? obj.summary.slice(0,200) : 'AI design' };
        } catch {
            throw new Error('AI returned incomplete or invalid design JSON. Try a smaller change; the current design is unchanged.');
        }
    }

    /**
     * Format the skin's css_variables into a plain-text list for the system prompt.
     */
    function _formatVariableList(variables) {
        if (!variables || !Object.keys(variables).length) {
            return '(no variables declared for this skin)';
        }
        const lines = [];
        Object.entries(variables).forEach(([, groupDef]) => {
            lines.push(`[${groupDef.label || 'Group'}]`);
            Object.entries(groupDef.vars || {}).forEach(([prop, meta]) => {
                lines.push(`  ${prop}  — ${meta.label} (${meta.type}, default: ${meta.default})`);
            });
        });
        return lines.join('\n');
    }

    /**
     * Render the shared resources library into a compact, decision-support block
     * for the system prompt: for each skin-facing engine/helper — what it is, how
     * a skin declares it, and what it depends on — plus the shared CSS and fonts.
     * This is what stops the model designing blind. When the library isn't
     * available it says so plainly, so the model doesn't invent engines it can't see.
     */
    function _formatLibrary(library) {
        if (!library || typeof library !== 'object') {
            return `Shared resources library: NOT AVAILABLE for this site. Do not reference or invent shared engines, fonts or CSS blocks — work only with the CSS variables listed above.`;
        }

        // `engines` is the CURATED skin-facing registry from the server
        // (core/manifest-inventory.php) — only the engines a skin can actually
        // turn on, each with the exact handle it declares in require_scripts. No
        // back-office noise, no guessing the token.
        const engines   = Array.isArray(library.engines) ? library.engines : [];
        const inventory = library.inventory || {};

        const out = ['Shared resources library available (use only exact declared handles in the structured response):'];

        if (engines.length) {
            out.push('Skin engines a skin can turn on (declare the HANDLE shown in the skin manifest "require_scripts"):');
            engines.forEach((e) => {
                const dep      = (e.requires && e.requires.length) ? `  [also needs: ${e.requires.join(', ')}]` : '';
                const settings = e.has_settings ? '  [has adjustable settings]' : '';
                out.push(`  - ${e.handle} — ${e.label || e.purpose}${dep}${settings}`);
            });
        }

        const css = inventory.css || [];
        if (css.length) {
            out.push('Shared CSS blocks:');
            css.forEach((e) => out.push(`  - ${_baseName(e.file)} — ${e.purpose}`));
        }

        const fonts = inventory.fonts || [];
        if (fonts.length) {
            out.push('Fonts (referenced by family name in @font-face / CSS):');
            fonts.forEach((e) => out.push(`  - ${e.family}`));
        }

        return out.join('\n');
    }

    function _baseName(path) {
        return String(path || '').split('/').pop();
    }

    return { send };

})();
// ===== SNAPSMACK EOF =====
