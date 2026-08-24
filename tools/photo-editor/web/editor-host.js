/*
 * PIXEL TICKLER shared host adapter.
 * SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical JS marker.
 */
(function (global) {
    'use strict';

    async function call(method, params) {
        if (global.__TAURI__ && global.__TAURI__.core && global.__TAURI__.core.invoke) {
            return global.__TAURI__.core.invoke('editor_call', { method: method, params: params || {} });
        }
        if (global.blink && typeof global.blink.call === 'function') {
            return global.blink.call('editor_call', method, params || {});
        }
        throw new Error('No supported PIXEL TICKLER host is available.');
    }

    global.SnapSmackEditorHost = Object.freeze({
        call: call,
        hello: function () { return call('hello', { contract_versions: [1] }); },
        validateJob: function (job) { return call('validate_job', job); },
        cancel: function (jobId) { return call('cancel', { job_id: jobId }); },
        shutdown: function () { return call('shutdown', {}); }
    });
    global.SnapPhotoHost = global.SnapSmackEditorHost;
}(window));

// ===== SNAPSMACK EOF =====
