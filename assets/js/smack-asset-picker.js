/**
 * SNAPSMACK - Asset Picker
 * Alpha v0.7.5
 *
 * Visual media library picker shared between the static-page hero field
 * (smack-pages.php) and the shortcode toolbar image button.
 *
 * Data is bootstrapped from data-* attributes on #asset-picker-grid:
 *   data-assets   — JSON array of { id, asset_name, asset_path }
 *   data-base-url — site base URL with trailing slash
 *
 * Exposes: window.ssOpenAssetPicker(mode, textarea)
 *   mode     — 'hero' | 'shortcode'
 *   textarea — the target <textarea> for shortcode insertion (shortcode mode)
 */
(function () {
    'use strict';

    var overlay  = document.getElementById('ss-asset-picker-overlay');
    if (!overlay) return; // not on a page that uses the picker

    var grid     = document.getElementById('asset-picker-grid');
    var scOpts   = document.getElementById('asset-picker-sc-opts');

    // Bootstrap data from attributes set by PHP
    var assets  = JSON.parse(grid.dataset.assets  || '[]');
    var baseUrl = grid.dataset.baseUrl || '';

    var pickerMode     = null;
    var pickerTextarea = null;
    var selectedAsset  = null;

    var imgExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'];

    // ── Build the grid once ──────────────────────────────────────────────────
    assets.forEach(function (a) {
        var ext  = a.asset_path.split('.').pop().toLowerCase();
        var cell = document.createElement('div');
        cell.className    = 'asset-picker-cell';
        cell.dataset.id   = a.id;
        cell.dataset.path = a.asset_path;

        if (imgExts.indexOf(ext) >= 0) {
            var img = document.createElement('img');
            img.src = baseUrl + a.asset_path;
            img.alt = a.asset_name;
            cell.appendChild(img);
        }

        var label = document.createElement('span');
        label.textContent = a.asset_name;
        cell.appendChild(label);

        cell.addEventListener('click', function () {
            grid.querySelectorAll('.asset-picker-cell').forEach(function (c) {
                c.classList.remove('selected');
            });
            this.classList.add('selected');
            selectedAsset = { id: this.dataset.id, path: this.dataset.path };

            if (pickerMode === 'hero') {
                document.getElementById('image_asset_val').value = selectedAsset.path;
                var preview = document.getElementById('hero-preview');
                preview.innerHTML = '<img src="' + baseUrl + selectedAsset.path + '" alt="">';
                closeAssetPicker();
            } else if (pickerMode === 'shortcode') {
                scOpts.classList.remove('d-none');
            }
        });

        grid.appendChild(cell);
    });

    // ── Open / close ─────────────────────────────────────────────────────────
    function openAssetPicker(mode, textarea) {
        pickerMode     = mode;
        pickerTextarea = textarea || null;
        selectedAsset  = null;
        grid.querySelectorAll('.asset-picker-cell').forEach(function (c) {
            c.classList.remove('selected');
        });
        scOpts.classList.add('d-none');
        overlay.classList.remove('d-none');
    }

    function closeAssetPicker() {
        overlay.classList.add('d-none');
    }

    // ── Hero field buttons ────────────────────────────────────────────────────
    var pickBtn  = document.getElementById('hero-pick-btn');
    var clearBtn = document.getElementById('hero-clear-btn');
    if (pickBtn)  pickBtn.addEventListener('click',  function () { openAssetPicker('hero'); });
    if (clearBtn) clearBtn.addEventListener('click', function () {
        document.getElementById('image_asset_val').value = '';
        document.getElementById('hero-preview').innerHTML = '<span class="dim">No image selected</span>';
    });

    // ── Close triggers ───────────────────────────────────────────────────────
    var closeBtn = document.getElementById('asset-picker-close');
    if (closeBtn) closeBtn.addEventListener('click', closeAssetPicker);
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeAssetPicker();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAssetPicker();
    });

    // ── Shortcode insert ──────────────────────────────────────────────────────
    var insertBtn = document.getElementById('asset-sc-insert');
    if (insertBtn) insertBtn.addEventListener('click', function () {
        if (!selectedAsset || !pickerTextarea) return;
        var size  = document.getElementById('asset-sc-size').value;
        var align = document.getElementById('asset-sc-align').value;
        var tag   = '[img:' + selectedAsset.id + '|' + size + '|' + align + ']';

        var ta    = pickerTextarea;
        var start = ta.selectionStart;
        var end   = ta.selectionEnd;
        ta.value  = ta.value.substring(0, start) + tag + ta.value.substring(end);
        ta.selectionStart = ta.selectionEnd = start + tag.length;
        ta.focus();
        closeAssetPicker();
    });

    // ── Expose for shortcode-toolbar.js ──────────────────────────────────────
    window.ssOpenAssetPicker = openAssetPicker;
}());
