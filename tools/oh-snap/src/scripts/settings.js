/**
 * OH SNAP! — Settings manager
 * v0.1.0
 *
 * Manages AI provider configuration and app preferences.
 * Settings are stored in localStorage under 'ohsnap_settings'.
 *
 * Supported AI providers:
 *   claude   — Anthropic Claude (claude-sonnet-4-6)
 *   gemini   — Google Gemini (gemini-2.0-flash)
 *   openai   — OpenAI (gpt-4o)
 *   ollama   — Local Ollama (configurable endpoint)
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


const OhSnapSettings = (() => {

    const STORE_KEY = 'ohsnap_settings';

    const DEFAULTS = {
        ai_provider:    'claude',
        claude_key:     '',
        gemini_key:     '',
        openai_key:     '',
        ollama_endpoint: 'http://localhost:11434',
        ollama_model:   'llama3',
    };

    // --- PUBLIC ---

    function load() {
        try {
            return { ...DEFAULTS, ...JSON.parse(localStorage.getItem(STORE_KEY) || '{}') };
        } catch {
            return { ...DEFAULTS };
        }
    }

    function save(partial) {
        const current = load();
        const updated = { ...current, ...partial };
        localStorage.setItem(STORE_KEY, JSON.stringify(updated));
        return updated;
    }

    function get(key) {
        return load()[key] ?? DEFAULTS[key];
    }

    // --- MODAL ---

    function openModal() {
        _renderModal();
        document.getElementById('oh-snap-settings-modal').classList.add('open');
    }

    function closeModal() {
        document.getElementById('oh-snap-settings-modal')?.classList.remove('open');
    }

    function _renderModal() {
        let modal = document.getElementById('oh-snap-settings-modal');
        if (modal) { _populateModal(modal); return; }

        modal = document.createElement('div');
        modal.id        = 'oh-snap-settings-modal';
        modal.className = 'settings-modal';
        modal.innerHTML = `
<div class="settings-backdrop"></div>
<div class="settings-panel">
    <div class="settings-header">
        <h2 class="settings-title">Settings</h2>
        <button class="settings-close" id="btn-settings-close" title="Close">✕</button>
    </div>

    <div class="settings-body">

        <section class="settings-section">
            <h3 class="settings-section-title">AI Provider</h3>
            <p class="settings-hint">The AI assistant uses this provider when you describe skin changes in the chat.</p>

            <div class="field">
                <label for="s-ai-provider">Active Provider</label>
                <select id="s-ai-provider" class="settings-select">
                    <option value="claude">Claude (Anthropic) — Recommended</option>
                    <option value="gemini">Gemini (Google)</option>
                    <option value="openai">ChatGPT (OpenAI)</option>
                    <option value="ollama">Ollama (Local)</option>
                    <option value="none">None — Disable AI</option>
                </select>
            </div>
        </section>

        <section class="settings-section" id="s-section-claude">
            <h3 class="settings-section-title">Claude API Key</h3>
            <div class="field">
                <label for="s-claude-key">API Key</label>
                <div class="input-with-toggle">
                    <input type="password" id="s-claude-key" class="settings-input"
                           placeholder="sk-ant-..." autocomplete="off" spellcheck="false">
                    <button type="button" class="key-toggle" data-target="s-claude-key">&#x1F441;</button>
                </div>
                <p class="field-hint">Get yours at <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a></p>
            </div>
        </section>

        <section class="settings-section" id="s-section-gemini">
            <h3 class="settings-section-title">Gemini API Key</h3>
            <div class="field">
                <label for="s-gemini-key">API Key</label>
                <div class="input-with-toggle">
                    <input type="password" id="s-gemini-key" class="settings-input"
                           placeholder="AIza..." autocomplete="off" spellcheck="false">
                    <button type="button" class="key-toggle" data-target="s-gemini-key">&#x1F441;</button>
                </div>
                <p class="field-hint">Get yours at <a href="https://aistudio.google.com" target="_blank">aistudio.google.com</a></p>
            </div>
        </section>

        <section class="settings-section" id="s-section-openai">
            <h3 class="settings-section-title">OpenAI API Key</h3>
            <div class="field">
                <label for="s-openai-key">API Key</label>
                <div class="input-with-toggle">
                    <input type="password" id="s-openai-key" class="settings-input"
                           placeholder="sk-..." autocomplete="off" spellcheck="false">
                    <button type="button" class="key-toggle" data-target="s-openai-key">&#x1F441;</button>
                </div>
                <p class="field-hint">Get yours at <a href="https://platform.openai.com" target="_blank">platform.openai.com</a></p>
            </div>
        </section>

        <section class="settings-section" id="s-section-ollama">
            <h3 class="settings-section-title">Ollama (Local)</h3>
            <div class="field">
                <label for="s-ollama-endpoint">Endpoint</label>
                <input type="url" id="s-ollama-endpoint" class="settings-input"
                       placeholder="http://localhost:11434" autocomplete="off">
            </div>
            <div class="field">
                <label for="s-ollama-model">Model</label>
                <input type="text" id="s-ollama-model" class="settings-input"
                       placeholder="llama3" autocomplete="off" spellcheck="false">
                <p class="field-hint">Must be pulled in Ollama before use.</p>
            </div>
        </section>

    </div>

    <div class="settings-footer">
        <button class="btn btn-primary" id="btn-settings-save">Save</button>
        <button class="btn" id="btn-settings-cancel">Cancel</button>
    </div>
</div>`;

        document.body.appendChild(modal);
        _populateModal(modal);

        // Close on backdrop click
        modal.querySelector('.settings-backdrop').addEventListener('click', closeModal);
        modal.querySelector('#btn-settings-close').addEventListener('click', closeModal);
        modal.querySelector('#btn-settings-cancel').addEventListener('click', closeModal);
        modal.querySelector('#btn-settings-save').addEventListener('click', _saveFromModal);

        // Key visibility toggles
        modal.querySelectorAll('.key-toggle').forEach(btn => {
            btn.addEventListener('click', () => {
                const inp = modal.querySelector(`#${btn.dataset.target}`);
                if (inp) inp.type = inp.type === 'password' ? 'text' : 'password';
            });
        });

        // Show/hide provider sections based on dropdown
        modal.querySelector('#s-ai-provider').addEventListener('change', () => _updateProviderVisibility(modal));
    }

    function _populateModal(modal) {
        const s = load();
        modal.querySelector('#s-ai-provider').value   = s.ai_provider;
        modal.querySelector('#s-claude-key').value     = s.claude_key;
        modal.querySelector('#s-gemini-key').value     = s.gemini_key;
        modal.querySelector('#s-openai-key').value     = s.openai_key;
        modal.querySelector('#s-ollama-endpoint').value = s.ollama_endpoint;
        modal.querySelector('#s-ollama-model').value   = s.ollama_model;
        _updateProviderVisibility(modal);
    }

    function _updateProviderVisibility(modal) {
        const provider = modal.querySelector('#s-ai-provider').value;
        ['claude', 'gemini', 'openai', 'ollama'].forEach(p => {
            const sec = modal.querySelector(`#s-section-${p}`);
            if (sec) sec.style.display = (provider === p) ? '' : 'none';
        });
    }

    function _saveFromModal() {
        const modal = document.getElementById('oh-snap-settings-modal');
        save({
            ai_provider:     modal.querySelector('#s-ai-provider').value,
            claude_key:      modal.querySelector('#s-claude-key').value.trim(),
            gemini_key:      modal.querySelector('#s-gemini-key').value.trim(),
            openai_key:      modal.querySelector('#s-openai-key').value.trim(),
            ollama_endpoint: modal.querySelector('#s-ollama-endpoint').value.trim(),
            ollama_model:    modal.querySelector('#s-ollama-model').value.trim(),
        });
        closeModal();
    }

    return { load, save, get, openModal, closeModal };

})();
// ===== SNAPSMACK EOF =====
