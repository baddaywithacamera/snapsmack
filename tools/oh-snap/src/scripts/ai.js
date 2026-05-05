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

When the user describes a change, you must respond with ONLY a raw JSON object of CSS custom property overrides.
No explanation. No markdown. No code fences. Just the JSON object.

Example valid response:
{"--bg-page":"#1a1a2e","--text-primary":"#e0e0ff","--border-accent":"#6c63ff"}

Rules:
- Only use CSS custom properties from the list provided below.
- Values must be valid CSS values for the property type.
- Colors must be hex (#rrggbb) or rgb(). No color names.
- If the user asks something that isn't a skin change, respond with {} and nothing else.
- If you're not sure which variables to change, make your best judgment.

Available CSS variables for this skin:
`;

    // --- PUBLIC ---

    /**
     * Send a user message and return parsed CSS variable overrides.
     * @param {string} userMessage   The user's chat message
     * @param {Object} variables     The skin's css_variables map (for context)
     * @returns {Promise<Object>}    CSS variable overrides { '--var': 'val' }
     */
    async function send(userMessage, variables) {
        const s        = OhSnapSettings.load();
        const provider = s.ai_provider;

        if (!provider || provider === 'none') {
            throw new Error('No AI provider configured. Open Settings to add an API key.');
        }

        const systemPrompt = _systemPromptBase + _formatVariableList(variables);

        switch (provider) {
            case 'claude':  return _callClaude(s, systemPrompt, userMessage);
            case 'gemini':  return _callGemini(s, systemPrompt, userMessage);
            case 'openai':  return _callOpenAI(s, systemPrompt, userMessage);
            case 'ollama':  return _callOllama(s, systemPrompt, userMessage);
            default: throw new Error(`Unknown provider: ${provider}`);
        }
    }

    // --- PROVIDERS ---

    async function _callClaude(s, system, userMsg) {
        if (!s.claude_key) throw new Error('Claude API key not set. Open Settings.');

        const res = await fetch('https://api.anthropic.com/v1/messages', {
            method: 'POST',
            headers: {
                'Content-Type':         'application/json',
                'x-api-key':            s.claude_key,
                'anthropic-version':    '2023-06-01',
                'anthropic-dangerous-direct-browser-access': 'true',
            },
            body: JSON.stringify({
                model:      'claude-sonnet-4-6',
                max_tokens: 512,
                system,
                messages: [{ role: 'user', content: userMsg }],
            }),
        });

        const data = await res.json();
        if (!res.ok) throw new Error(data.error?.message || `Claude error ${res.status}`);

        const text = data.content?.[0]?.text || '{}';
        return _parseOverrides(text);
    }

    async function _callGemini(s, system, userMsg) {
        if (!s.gemini_key) throw new Error('Gemini API key not set. Open Settings.');

        const url = `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=${encodeURIComponent(s.gemini_key)}`;

        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                systemInstruction: { parts: [{ text: system }] },
                contents: [{ role: 'user', parts: [{ text: userMsg }] }],
                generationConfig: { maxOutputTokens: 512, temperature: 0.3 },
            }),
        });

        const data = await res.json();
        if (!res.ok) throw new Error(data.error?.message || `Gemini error ${res.status}`);

        const text = data.candidates?.[0]?.content?.parts?.[0]?.text || '{}';
        return _parseOverrides(text);
    }

    async function _callOpenAI(s, system, userMsg) {
        if (!s.openai_key) throw new Error('OpenAI API key not set. Open Settings.');

        const res = await fetch('https://api.openai.com/v1/chat/completions', {
            method: 'POST',
            headers: {
                'Content-Type':  'application/json',
                'Authorization': `Bearer ${s.openai_key}`,
            },
            body: JSON.stringify({
                model:      'gpt-4o',
                max_tokens: 512,
                messages: [
                    { role: 'system', content: system },
                    { role: 'user',   content: userMsg },
                ],
            }),
        });

        const data = await res.json();
        if (!res.ok) throw new Error(data.error?.message || `OpenAI error ${res.status}`);

        const text = data.choices?.[0]?.message?.content || '{}';
        return _parseOverrides(text);
    }

    async function _callOllama(s, system, userMsg) {
        const endpoint = (s.ollama_endpoint || 'http://localhost:11434').replace(/\/$/, '');
        const model    = s.ollama_model || 'llama3';

        const res = await fetch(`${endpoint}/api/generate`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                model,
                system,
                prompt: userMsg,
                stream: false,
                options: { num_predict: 512, temperature: 0.3 },
            }),
        });

        const data = await res.json();
        if (!res.ok) throw new Error(data.error || `Ollama error ${res.status}`);

        const text = data.response || '{}';
        return _parseOverrides(text);
    }

    // --- UTILS ---

    /**
     * Parse a model response into a CSS override object.
     * Handles raw JSON, JSON wrapped in markdown code fences, etc.
     */
    function _parseOverrides(text) {
        // Strip markdown code fences if present
        let clean = text.trim();
        clean = clean.replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/, '');

        // Extract the first {...} block in case there's prose around it
        const match = clean.match(/\{[^]*\}/);
        if (!match) return {};

        try {
            const obj = JSON.parse(match[0]);
            // Filter: only CSS custom properties with plausible values
            const safe = {};
            Object.entries(obj).forEach(([k, v]) => {
                if (typeof k === 'string' && /^--[a-z][a-z0-9-]*$/i.test(k) &&
                    typeof v === 'string' && !/[;<>{}]/.test(v)) {
                    safe[k] = v;
                }
            });
            return safe;
        } catch {
            return {};
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

    return { send };

})();
// ===== SNAPSMACK EOF =====
