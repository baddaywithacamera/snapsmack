/**
 * SNAPSMACK — AI metadata enrichment
 *
 * Adds native-sized controls beside title, caption/body, and hashtag fields.
 * Prompt libraries are loaded and saved through smack-ai-assist.php.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

(function () {
    'use strict';

    var endpoint = 'smack-ai-assist.php';
    var library = null;
    var rows = [];
    var activeType = '';
    var modal = null;

    var fieldMap = {
        title: {
            selector: 'input[name="title"]',
            label: 'AI TITLE'
        },
        caption: {
            selector: 'textarea[name="desc"], textarea[name="content"]',
            label: 'AI CAPTION'
        },
        hashtags: {
            selector: 'input[name="tags"]',
            label: 'AI HASHTAGS'
        }
    };

    function request(data) {
        var body = new FormData();
        Object.keys(data).forEach(function (key) {
            body.append(key, typeof data[key] === 'string' ? data[key] : JSON.stringify(data[key]));
        });
        return fetch(endpoint, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (response) {
            return response.json();
        });
    }

    function context() {
        var read = function (selector) {
            var field = document.querySelector(selector);
            return field ? field.value.trim() : '';
        };
        return {
            title: read(fieldMap.title.selector),
            caption: read(fieldMap.caption.selector),
            hashtags: read(fieldMap.hashtags.selector)
        };
    }

    function optionLabel(prompt, type) {
        return prompt.name + (library.preferred[type] === prompt.id ? ' ★' : '');
    }

    function promptsFor(type) {
        return library.prompts.filter(function (prompt) {
            return prompt.type === type;
        });
    }

    function fillSelect(select, type, selectedId) {
        select.textContent = '';
        promptsFor(type).forEach(function (prompt) {
            var option = document.createElement('option');
            option.value = prompt.id;
            option.textContent = optionLabel(prompt, type);
            option.selected = prompt.id === selectedId;
            select.appendChild(option);
        });
    }

    function refreshRows(forceType, forceId) {
        rows.forEach(function (item) {
            var current = item.type === forceType && forceId
                ? forceId
                : (item.select.value || library.preferred[item.type]);
            fillSelect(item.select, item.type, current);
            if (!item.select.value) item.select.value = library.preferred[item.type] || '';
        });
    }

    function setStatus(item, message, error) {
        item.status.textContent = message || '';
        item.status.classList.toggle('is-error', Boolean(error));
    }

    function enrich(item) {
        var current = item.field.value.trim();
        var postContext = context();
        if (!current && !postContext.title && !postContext.caption && !postContext.hashtags) {
            setStatus(item, 'Write something first so the AI has facts and intent to work from.', true);
            return;
        }

        item.button.disabled = true;
        item.button.classList.add('is-working');
        setStatus(item, 'Working…', false);

        request({
            mode: 'enrich',
            target: item.type,
            prompt_id: item.select.value,
            current: current,
            context: postContext
        }).then(function (result) {
            item.button.disabled = false;
            item.button.classList.remove('is-working');
            if (!result.ok) {
                setStatus(item, result.error || 'AI enrichment failed.', true);
                return;
            }
            item.field.value = result.text || '';
            item.field.dispatchEvent(new Event('input', { bubbles: true }));
            item.field.focus();
            setStatus(item, 'Applied. Review it before publishing.', false);
        }).catch(function () {
            item.button.disabled = false;
            item.button.classList.remove('is-working');
            setStatus(item, 'The AI request failed.', true);
        });
    }

    function buildRow(type, field) {
        var row = document.createElement('div');
        row.className = 'ss-ai-enrich-row';

        var select = document.createElement('select');
        select.className = 'full-width-select ss-ai-enrich-select';
        select.setAttribute('aria-label', 'AI ' + type + ' prompt');
        fillSelect(select, type, library.preferred[type] || '');

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'sc-btn sc-btn-ai ss-ai-enrich-button';
        button.textContent = fieldMap[type].label;

        var prompts = document.createElement('button');
        prompts.type = 'button';
        prompts.className = 'sc-btn ss-ai-prompts-button';
        prompts.textContent = 'PROMPTS';

        var status = document.createElement('span');
        status.className = 'ss-ai-enrich-status';
        status.setAttribute('aria-live', 'polite');

        row.appendChild(select);
        row.appendChild(button);
        row.appendChild(prompts);
        row.appendChild(status);
        field.insertAdjacentElement('afterend', row);

        var item = {
            type: type,
            field: field,
            row: row,
            select: select,
            button: button,
            status: status
        };
        rows.push(item);
        button.addEventListener('click', function () { enrich(item); });
        prompts.addEventListener('click', function () { openPromptManager(type, select.value); });
    }

    function buildModal() {
        modal = document.createElement('div');
        modal.className = 'ss-ai-modal';
        modal.hidden = true;
        modal.innerHTML =
            '<section class="ss-ai-modal-card" role="dialog" aria-modal="true" aria-labelledby="ss-ai-modal-title">' +
                '<div class="ss-ai-modal-header">' +
                    '<h3 id="ss-ai-modal-title">AI PROMPTS</h3>' +
                    '<button type="button" class="ss-ai-modal-close" aria-label="Close">✕</button>' +
                '</div>' +
                '<div class="ss-ai-modal-grid">' +
                    '<label>FIELD<select id="ss-ai-prompt-type" class="full-width-select">' +
                        '<option value="title">TITLE</option>' +
                        '<option value="caption">CAPTION / BODY</option>' +
                        '<option value="hashtags">HASHTAGS</option>' +
                    '</select></label>' +
                    '<label>PROMPT<select id="ss-ai-prompt-select" class="full-width-select"></select></label>' +
                    '<label>NAME<input id="ss-ai-prompt-name" type="text" maxlength="80"></label>' +
                    '<label>INSTRUCTIONS<textarea id="ss-ai-prompt-text" maxlength="4000"></textarea></label>' +
                    '<label class="ss-ai-preferred"><input id="ss-ai-prompt-preferred" type="checkbox"> Use this prompt by default for this field</label>' +
                    '<p class="ss-ai-modal-help">Built-in prompts are safe fallbacks and cannot be overwritten. Edit one and SAVE AS NEW to make your own version.</p>' +
                '</div>' +
                '<div class="ss-ai-modal-actions">' +
                    '<button type="button" class="sc-btn ss-ai-modal-button" id="ss-ai-prompt-new">NEW</button>' +
                    '<button type="button" class="sc-btn ss-ai-modal-button" id="ss-ai-prompt-delete">DELETE</button>' +
                    '<button type="button" class="sc-btn sc-btn-ai ss-ai-modal-button" id="ss-ai-prompt-save">SAVE</button>' +
                '</div>' +
            '</section>';
        document.body.appendChild(modal);

        modal.querySelector('.ss-ai-modal-close').addEventListener('click', closeModal);
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden) closeModal();
        });
        document.getElementById('ss-ai-prompt-type').addEventListener('change', function () {
            activeType = this.value;
            loadManagerPrompt(library.preferred[activeType] || '');
        });
        document.getElementById('ss-ai-prompt-select').addEventListener('change', function () {
            loadManagerPrompt(this.value);
        });
        document.getElementById('ss-ai-prompt-new').addEventListener('click', clearManager);
        document.getElementById('ss-ai-prompt-save').addEventListener('click', savePrompt);
        document.getElementById('ss-ai-prompt-delete').addEventListener('click', deletePrompt);
    }

    function managerPromptSelect(selectedId) {
        var select = document.getElementById('ss-ai-prompt-select');
        fillSelect(select, activeType, selectedId);
        if (!select.value && select.options.length) select.selectedIndex = 0;
        return select.value;
    }

    function promptById(id) {
        return library.prompts.find(function (prompt) { return prompt.id === id; }) || null;
    }

    function loadManagerPrompt(id) {
        id = managerPromptSelect(id);
        var prompt = promptById(id);
        document.getElementById('ss-ai-prompt-name').value = prompt ? prompt.name : '';
        document.getElementById('ss-ai-prompt-text').value = prompt ? prompt.prompt : '';
        document.getElementById('ss-ai-prompt-preferred').checked = Boolean(prompt && library.preferred[activeType] === prompt.id);
        document.getElementById('ss-ai-prompt-delete').disabled = !prompt || prompt.builtin;
    }

    function clearManager() {
        document.getElementById('ss-ai-prompt-select').value = '';
        document.getElementById('ss-ai-prompt-name').value = '';
        document.getElementById('ss-ai-prompt-text').value = '';
        document.getElementById('ss-ai-prompt-preferred').checked = false;
        document.getElementById('ss-ai-prompt-delete').disabled = true;
        document.getElementById('ss-ai-prompt-name').focus();
    }

    function openPromptManager(type, selectedId) {
        activeType = type;
        document.getElementById('ss-ai-prompt-type').value = type;
        loadManagerPrompt(selectedId || library.preferred[type] || '');
        modal.hidden = false;
        document.getElementById('ss-ai-prompt-select').focus();
    }

    function closeModal() {
        modal.hidden = true;
    }

    function savePrompt() {
        var selected = promptById(document.getElementById('ss-ai-prompt-select').value);
        var id = selected && !selected.builtin ? selected.id : '';
        var name = document.getElementById('ss-ai-prompt-name').value.trim();
        var prompt = document.getElementById('ss-ai-prompt-text').value.trim();
        var preferred = document.getElementById('ss-ai-prompt-preferred').checked ? '1' : '0';
        if (!name || !prompt) {
            alert('Give the prompt a name and instructions.');
            return;
        }
        if (selected && selected.builtin && selected.name === name && selected.prompt === prompt) {
            request({
                mode: 'prompt_prefer',
                target: activeType,
                prompt_id: selected.id,
                preferred: preferred
            }).then(function (result) {
                if (!result.ok) {
                    alert(result.error || 'Could not save the preference.');
                    return;
                }
                library = result.library;
                refreshRows(activeType, selected.id);
                loadManagerPrompt(selected.id);
            });
            return;
        }
        request({
            mode: 'prompt_save',
            target: activeType,
            prompt_id: id,
            name: name,
            prompt: prompt,
            preferred: preferred
        }).then(function (result) {
            if (!result.ok) {
                alert(result.error || 'Could not save the prompt.');
                return;
            }
            library = result.library;
            refreshRows(activeType, result.prompt_id);
            loadManagerPrompt(result.prompt_id);
        });
    }

    function deletePrompt() {
        var id = document.getElementById('ss-ai-prompt-select').value;
        var prompt = promptById(id);
        if (!prompt || prompt.builtin) return;
        if (!window.confirm('Delete this saved prompt?')) return;
        request({ mode: 'prompt_delete', prompt_id: id }).then(function (result) {
            if (!result.ok) {
                alert(result.error || 'Could not delete the prompt.');
                return;
            }
            library = result.library;
            refreshRows();
            loadManagerPrompt(library.preferred[activeType] || '');
        });
    }

    function initialise() {
        request({ mode: 'prompt_list' }).then(function (result) {
            if (!result.ok) return;
            library = result.library;
            Object.keys(fieldMap).forEach(function (type) {
                var field = document.querySelector(fieldMap[type].selector);
                if (field) buildRow(type, field);
            });
            if (!rows.length) return;
            buildModal();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialise);
    } else {
        initialise();
    }
})();

// ===== SNAPSMACK EOF =====
