/**
 * SNAPSMACK - Update Manager Modal
 * ss-engine-updater.js
 *
 * XHR-driven update modal. Exposes SnapUpdater.open() globally.
 * Drives smack-update.php stage-by-stage without page navigation.
 */

var SnapUpdater = (function () {
    'use strict';

    // ── Constants ─────────────────────────────────────────────────────────────
    var UPDATE_URL = 'smack-update.php';
    var STAGE_LABELS = ['Download', 'Verify', 'Backup', 'Extract', 'Migrate'];
    var STAGE_KEYS   = ['download', 'verify', 'backup', 'extract', 'migrate'];
    var POLL_INTERVAL_MS = 1500;

    // ── State ─────────────────────────────────────────────────────────────────
    var _modal   = null;
    var _panels  = {};
    var _csrf    = '';
    var _pollTimer = null;

    // ── DOM Builder ───────────────────────────────────────────────────────────
    function _buildModal() {
        var el = document.createElement('div');
        el.id = 'snap-updater-modal';
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-modal', 'true');
        el.setAttribute('aria-label', 'SnapSmack Update');
        el.innerHTML = [
            '<div class="su-overlay" onclick="SnapUpdater._overlayClick()"></div>',
            '<div class="su-dialog">',
              '<div class="su-header">',
                '<span class="su-title">SNAPSMACK UPDATE</span>',
                '<button class="su-close" onclick="SnapUpdater.close()" aria-label="Close">&times;</button>',
              '</div>',
              '<div class="su-body">',
                '<!-- checking -->',
                '<div class="su-panel" id="su-panel-checking">',
                  '<div class="su-spinner"></div>',
                  '<p class="su-status-line" id="su-checking-msg">Checking for updates&hellip;</p>',
                '</div>',
                '<!-- up to date -->',
                '<div class="su-panel" id="su-panel-uptodate">',
                  '<div class="su-uptodate-icon">&#10003;</div>',
                  '<p class="su-uptodate-msg">Your installation is up to date.</p>',
                  '<div class="su-footer-btns">',
                    '<button class="su-btn" onclick="SnapUpdater.close()">CLOSE</button>',
                  '</div>',
                '</div>',
                '<!-- review -->',
                '<div class="su-panel" id="su-panel-review">',
                  '<div class="su-review-header" id="su-review-header"></div>',
                  '<div class="su-changelog" id="su-changelog"></div>',
                  '<div class="su-file-changes" id="su-file-changes"></div>',
                  '<div id="su-schema-warn" class="su-schema-warn" style="display:none">',
                    '&#9888; This update includes database schema changes. A migration will run automatically.',
                  '</div>',
                  '<div class="su-footer-btns">',
                    '<button class="su-btn su-btn-secondary" onclick="SnapUpdater.close()">CANCEL</button>',
                    '<button class="su-btn su-btn-primary" id="su-apply-btn" onclick="SnapUpdater._startApply()">APPLY UPDATE</button>',
                  '</div>',
                '</div>',
                '<!-- applying -->',
                '<div class="su-panel" id="su-panel-applying">',
                  '<div class="su-stages" id="su-stages"></div>',
                  '<p class="su-status-line" id="su-apply-status">Starting&hellip;</p>',
                  '<div class="su-extract-bar-wrap" id="su-extract-bar-wrap" style="display:none">',
                    '<div class="su-extract-bar-bg"><div class="su-extract-bar-fill" id="su-extract-bar-fill"></div></div>',
                    '<span class="su-extract-pct" id="su-extract-pct">0%</span>',
                  '</div>',
                  '<div class="su-log" id="su-apply-log"></div>',
                '</div>',
                '<!-- success -->',
                '<div class="su-panel" id="su-panel-success">',
                  '<div class="su-success-icon">&#10003;</div>',
                  '<p class="su-success-msg" id="su-success-msg">Update complete.</p>',
                  '<div class="su-footer-btns">',
                    '<button class="su-btn su-btn-primary" onclick="location.reload()">RELOAD PAGE</button>',
                  '</div>',
                '</div>',
                '<!-- error -->',
                '<div class="su-panel" id="su-panel-error">',
                  '<div class="su-error-icon">&#9888;</div>',
                  '<p class="su-error-msg" id="su-error-msg">An error occurred.</p>',
                  '<div class="su-footer-btns">',
                    '<button class="su-btn su-btn-danger" id="su-rollback-btn" style="display:none" onclick="SnapUpdater._rollback()">ROLLBACK</button>',
                    '<button class="su-btn su-btn-secondary" onclick="SnapUpdater.close()">CLOSE</button>',
                  '</div>',
                '</div>',
              '</div>',
            '</div>',
        ].join('');
        document.body.appendChild(el);

        _modal = el;
        _panels = {
            checking: el.querySelector('#su-panel-checking'),
            uptodate: el.querySelector('#su-panel-uptodate'),
            review:   el.querySelector('#su-panel-review'),
            applying: el.querySelector('#su-panel-applying'),
            success:  el.querySelector('#su-panel-success'),
            error:    el.querySelector('#su-panel-error'),
        };

        _buildStages();
    }

    function _buildStages() {
        var el = _modal.querySelector('#su-stages');
        var html = '<div class="su-stage-track">';
        for (var i = 0; i < STAGE_LABELS.length; i++) {
            html += '<div class="su-stage-step" id="su-stage-' + STAGE_KEYS[i] + '">';
            html += '<div class="su-stage-dot"></div>';
            html += '<span class="su-stage-label">' + STAGE_LABELS[i] + '</span>';
            html += '</div>';
            if (i < STAGE_LABELS.length - 1) {
                html += '<div class="su-stage-line"></div>';
            }
        }
        html += '</div>';
        el.innerHTML = html;
    }

    // ── Panel switcher ────────────────────────────────────────────────────────
    function _showPanel(name) {
        for (var key in _panels) {
            _panels[key].style.display = key === name ? '' : 'none';
        }
    }

    // ── Open / close ──────────────────────────────────────────────────────────
    function open() {
        if (!_modal) _buildModal();
        _showPanel('checking');
        _modal.classList.add('su-open');
        document.body.style.overflow = 'hidden';
        _doCheck();
    }

    function close() {
        if (!_modal) return;
        _modal.classList.remove('su-open');
        document.body.style.overflow = '';
        _stopPoll();
    }

    function _overlayClick() {
        // Don't allow close while applying
        var applying = _panels.applying && _panels.applying.style.display !== 'none';
        if (!applying) close();
    }

    // ── XHR helpers ───────────────────────────────────────────────────────────
    function _get(url, cb) {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url + (url.indexOf('?') >= 0 ? '&' : '?') + 'json=1', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            if (xhr.status === 200) {
                try { cb(null, JSON.parse(xhr.responseText)); }
                catch (e) { cb('JSON parse error: ' + xhr.responseText.substring(0, 120)); }
            } else {
                cb('HTTP ' + xhr.status);
            }
        };
        xhr.send();
    }

    function _post(action, extra, cb) {
        var params = 'action=' + encodeURIComponent(action) + '&csrf=' + encodeURIComponent(_csrf) + '&json=1';
        if (extra) {
            for (var k in extra) {
                params += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(extra[k]);
            }
        }
        var xhr = new XMLHttpRequest();
        xhr.open('POST', UPDATE_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            if (xhr.status === 200) {
                try { cb(null, JSON.parse(xhr.responseText)); }
                catch (e) { cb('JSON parse error: ' + xhr.responseText.substring(0, 120)); }
            } else {
                cb('HTTP ' + xhr.status);
            }
        };
        xhr.send(params);
    }

    // ── Check ─────────────────────────────────────────────────────────────────
    function _doCheck() {
        _get(UPDATE_URL + '?action=check', function (err, data) {
            if (err) { _showError('Update check failed: ' + err); return; }
            if (data.status === 'error') { _showError(data.message); return; }

            _csrf = (data.data && data.data.csrf_token) ? data.data.csrf_token : '';

            if (data.data && data.data.up_to_date) {
                _showPanel('uptodate');
                return;
            }
            _renderReview(data.data || {});
            _showPanel('review');
        });
    }

    // ── Review rendering ──────────────────────────────────────────────────────
    function _renderReview(d) {
        var hdr = _modal.querySelector('#su-review-header');
        var size = d.download_size ? (d.download_size / 1048576).toFixed(1) + ' MB' : '';
        hdr.innerHTML = '<strong>' + _esc(d.version_full || d.version || '') + '</strong>'
            + (d.codename ? ' &ldquo;' + _esc(d.codename) + '&rdquo;' : '')
            + (d.released ? ' &mdash; ' + _esc(d.released) : '')
            + (size ? '<span class="su-dl-size">' + _esc(size) + '</span>' : '');

        _renderChangelog(d.changelog || []);
        _renderFileChanges(d.file_changes || []);

        var warn = _modal.querySelector('#su-schema-warn');
        warn.style.display = d.schema_changes ? '' : 'none';
    }

    function _renderChangelog(entries) {
        var el = _modal.querySelector('#su-changelog');
        if (!entries.length) { el.innerHTML = ''; return; }
        var html = '<div class="su-changelog-inner">';
        var current = '';
        for (var i = 0; i < entries.length; i++) {
            var line = entries[i];
            if (typeof line === 'string') {
                if (/^###/.test(line)) {
                    if (current) html += '</ul>';
                    current = line.replace(/^###\s*/, '');
                    html += '<h4 class="su-cl-section">' + _esc(current) + '</h4><ul class="su-cl-list">';
                } else if (/^-/.test(line)) {
                    html += '<li>' + _esc(line.replace(/^-\s*/, '')) + '</li>';
                }
            }
        }
        if (current) html += '</ul>';
        html += '</div>';
        el.innerHTML = html;
    }

    function _renderFileChanges(changes) {
        var el = _modal.querySelector('#su-file-changes');
        if (!changes.length) { el.innerHTML = ''; return; }
        var added = 0, modified = 0, removed = 0, protected_count = 0;
        for (var i = 0; i < changes.length; i++) {
            var c = changes[i];
            if (c.status === 'A') added++;
            else if (c.status === 'D') removed++;
            else modified++;
            if (c.protected) protected_count++;
        }
        var summary = modified + ' modified, ' + added + ' added, ' + removed + ' removed';
        if (protected_count) summary += ' (' + protected_count + ' protected, skipped)';

        var html = '<details class="su-file-details"><summary class="su-file-summary">File changes: ' + _esc(summary) + '</summary><ul class="su-file-list">';
        for (var j = 0; j < changes.length; j++) {
            var fc = changes[j];
            var cls = fc.protected ? 'su-fc-protected' : (fc.status === 'A' ? 'su-fc-added' : (fc.status === 'D' ? 'su-fc-removed' : 'su-fc-modified'));
            html += '<li class="' + cls + '">' + _esc(fc.file || fc.path || '') + (fc.protected ? ' <em>(protected)</em>' : '') + '</li>';
        }
        html += '</ul></details>';
        el.innerHTML = html;
    }

    // ── Apply stages ──────────────────────────────────────────────────────────
    function _startApply() {
        _showPanel('applying');
        _setStatus('Downloading update package&hellip;');
        _setStage('download', 'active');
        _post('stage_download', {}, _afterDownload);
    }

    function _afterDownload(err, data) {
        if (err || data.status === 'error') {
            _showError((data && data.message) || err, false);
            return;
        }
        _logItem('Download', 'ok', data.data ? (data.data.filename || '') : '');
        _setStage('download', 'done');
        _setStage('verify', 'active');
        _setStatus('Verifying package integrity&hellip;');
        _post('stage_verify', {}, _afterVerify);
    }

    function _afterVerify(err, data) {
        if (err || data.status === 'error') {
            _showError((data && data.message) || err, false);
            return;
        }
        _logItem('Signature verified', 'ok', 'SHA-256 + Ed25519 OK');
        _setStage('verify', 'done');
        _setStage('backup', 'active');
        _setStatus('Creating pre-update backup&hellip;');
        _post('stage_backup', {}, _afterBackup);
    }

    function _afterBackup(err, data) {
        if (err || data.status === 'error') {
            _showError((data && data.message) || err, false);
            return;
        }
        _logItem('Backup created', 'ok', (data.data && data.data.backup_path) ? data.data.backup_path : '');
        _setStage('backup', 'done');
        _setStage('extract', 'active');
        _setStatus('Initialising extraction&hellip;');
        _post('stage_extract', {}, _afterExtractInit);
    }

    function _afterExtractInit(err, data) {
        if (err || data.status === 'error') {
            _showError((data && data.message) || err, true);
            return;
        }
        // Begin polling chunks
        var bar = _modal.querySelector('#su-extract-bar-wrap');
        if (bar) bar.style.display = '';
        _setStatus('Extracting files&hellip;');
        _startPoll();
    }

    function _startPoll() {
        _stopPoll();
        _pollTimer = setTimeout(_pollChunk, 500);
    }

    function _stopPoll() {
        if (_pollTimer) { clearTimeout(_pollTimer); _pollTimer = null; }
    }

    function _pollChunk() {
        _get(UPDATE_URL + '?action=stage_extract_chunk', function (err, data) {
            if (err || data.status === 'error') {
                _showError((data && data.message) || err, true);
                return;
            }
            var pct = data.progress || 0;
            var fill = _modal.querySelector('#su-extract-bar-fill');
            var pctEl = _modal.querySelector('#su-extract-pct');
            if (fill) fill.style.width = pct + '%';
            if (pctEl) pctEl.textContent = pct + '%';
            _setStatus('Extracting files&hellip; ' + pct + '%');

            if (data.done) {
                _stopPoll();
                var d = data.data || {};
                _logItem('Files extracted', 'ok', (d.files_written || 0) + ' written, ' + (d.files_skipped || 0) + ' protected');
                var bar = _modal.querySelector('#su-extract-bar-wrap');
                if (bar) bar.style.display = 'none';
                _setStage('extract', 'done');
                _setStage('migrate', 'active');
                _setStatus('Running schema migrations&hellip;');
                _post('stage_migrate', {}, _afterMigrate);
            } else {
                _pollTimer = setTimeout(_pollChunk, POLL_INTERVAL_MS);
            }
        });
    }

    function _afterMigrate(err, data) {
        if (err || data.status === 'error') {
            _showError((data && data.message) || err, true);
            return;
        }
        var d = data.data || {};
        _logItem('Migrations', 'ok', 'run: ' + (d.migrations_run || 0) + ', skipped: ' + (d.migrations_skipped || 0));
        _setStage('migrate', 'done');
        _showPanel('success');
        var msg = _modal.querySelector('#su-success-msg');
        if (msg && data.data) {
            msg.textContent = 'Update complete. Now running ' + (data.data.new_version_full || data.data.new_version || '') + '.';
        }
    }

    function _rollback() {
        _post('rollback', {}, function (err, data) {
            var msg = _modal.querySelector('#su-error-msg');
            if (err) {
                if (msg) msg.textContent = 'Rollback failed: ' + err;
                return;
            }
            if (msg) msg.textContent = data.message || 'Rolled back. Reload to verify.';
            var rb = _modal.querySelector('#su-rollback-btn');
            if (rb) rb.style.display = 'none';
        });
    }

    // ── UI helpers ────────────────────────────────────────────────────────────
    function _setStatus(html) {
        var el = _modal.querySelector('#su-apply-status');
        if (el) el.innerHTML = html;
    }

    function _setStage(key, state) {
        var el = _modal.querySelector('#su-stage-' + key);
        if (!el) return;
        el.classList.remove('su-stage-active', 'su-stage-done', 'su-stage-error');
        if (state === 'active') el.classList.add('su-stage-active');
        else if (state === 'done') el.classList.add('su-stage-done');
        else if (state === 'error') el.classList.add('su-stage-error');
    }

    function _logItem(label, status, detail) {
        var log = _modal.querySelector('#su-apply-log');
        if (!log) return;
        var item = document.createElement('div');
        item.className = 'su-log-item su-log-' + status;
        item.innerHTML = '<span class="su-log-label">' + _esc(label) + '</span>'
            + (detail ? '<span class="su-log-detail">' + _esc(detail) + '</span>' : '');
        log.appendChild(item);
        log.scrollTop = log.scrollHeight;
    }

    function _showError(msg, rollbackAvailable) {
        _stopPoll();
        _showPanel('error');
        var el = _modal.querySelector('#su-error-msg');
        if (el) el.textContent = msg || 'An unknown error occurred.';
        var rb = _modal.querySelector('#su-rollback-btn');
        if (rb) rb.style.display = rollbackAvailable ? '' : 'none';
    }

    function _esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ── Init ──────────────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        // Pre-build modal so it's ready on first trigger
        _buildModal();
        _showPanel('checking');
        _modal.classList.remove('su-open');

        // If we landed on smack-update.php directly, auto-open
        if (window._snapUpdaterAutoOpen) {
            open();
        }
    });

    return {
        open:         open,
        close:        close,
        _overlayClick: _overlayClick,
        _startApply:  _startApply,
        _rollback:    _rollback,
    };

}());
// EOF
