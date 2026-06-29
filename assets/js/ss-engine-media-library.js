/**
 * SNAPSMACK - Media Library engine
 *
 * Drives smack-media.php: AJAX upload + progress, asset swap, shortcode
 * builder/copy, and the per-asset global BORDER controls (width 0-10px +
 * hex colour). Border is global — saved once here, rendered everywhere the
 * [img:ID] shortcode appears (see core/parser.php parseImages()).
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

const fileInput   = document.getElementById('file-input');
const pContainer  = document.getElementById('p-container');
const pBar        = document.getElementById('p-bar');
const nameDisplay = document.getElementById('file-name-display');

/**
 * Rebuild the shortcode string shown on a card from its dropdown values.
 */
function updateShortcode(id) {
    const card    = document.getElementById('asset-' + id);
    const size    = card.querySelector('.size-select').value;
    const align   = card.querySelector('.align-select').value;
    const display = card.querySelector('.shortcode-display');
    display.innerText = `[img:${id}|${size}|${align}]`;
}

if (fileInput) {
    fileInput.addEventListener('change', function () {
        if (this.files && this.files[0]) {
            nameDisplay.innerText = this.files[0].name;
            uploadFile(this.files[0]);
        }
    });
}

/**
 * AJAX upload with progress bar.
 */
function uploadFile(file) {
    pContainer.style.display = 'block';
    const formData = new FormData();
    formData.append('file', file);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'smack-media.php', true);

    xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) {
            pBar.style.width = ((e.loaded / e.total) * 100) + '%';
        }
    };

    xhr.onload = () => {
        if (xhr.status === 200) {
            location.reload();
        } else {
            alert('Transmission Interrupted.');
            pContainer.style.display = 'none';
        }
    };

    xhr.send(formData);
}

/**
 * Copy a shortcode to the clipboard with brief visual feedback.
 */
function copyToClipboard(element) {
    const text = element.innerText.trim();
    navigator.clipboard.writeText(text).then(() => {
        element.innerText = 'COPIED';
        setTimeout(() => { element.innerText = text; }, 1000);
    });
}

/**
 * Swap an existing asset's file without changing its ID.
 * All [img:ID|...] shortcodes keep working automatically.
 */
document.querySelectorAll('[id^="swap-input-"]').forEach(function (input) {
    input.addEventListener('change', function () {
        if (!this.files || !this.files[0]) return;
        const assetId = this.dataset.assetId;
        const card    = document.getElementById('asset-' + assetId);
        const thumb   = card.querySelector('.asset-thumb-wrapper img');
        const swapBtn = card.querySelector('.action-edit');

        if (thumb) thumb.style.opacity = '0.35';
        swapBtn.disabled    = true;
        swapBtn.textContent = '...';

        const formData = new FormData();
        formData.append('swap_id', assetId);
        formData.append('file', this.files[0]);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'smack-media.php', true);
        xhr.onload = function () {
            if (xhr.status === 200) {
                location.reload();
            } else {
                if (thumb) thumb.style.opacity = '1';
                swapBtn.disabled    = false;
                swapBtn.textContent = 'SWAP';
                alert('Swap failed. Try again.');
            }
        };
        xhr.send(formData);

        this.value = '';
    });
});

/* ─── GLOBAL BORDER CONTROLS ──────────────────────────────────────────────
   Per-asset border width (0-10px) + hex colour. Live-previews on the card
   thumbnail and auto-saves on release. Applied everywhere via the parser. */

/**
 * Apply a border preview to a card's thumbnail.
 */
function previewBorder(card) {
    const thumb = card.querySelector('.asset-thumb-wrapper img');
    if (!thumb) return;
    const width = parseInt(card.querySelector('.border-width').value, 10);
    const color = card.querySelector('.border-color').value;
    thumb.style.border = width > 0 ? (width + 'px solid ' + color) : 'none';
}

/**
 * Persist a card's border settings.
 */
function saveBorder(card) {
    const id    = card.querySelector('.border-width').dataset.assetId;
    const width = parseInt(card.querySelector('.border-width').value, 10);
    const color = card.querySelector('.border-color').value;
    const note  = card.querySelector('.border-saved-note');

    const formData = new FormData();
    formData.append('border_id', id);
    formData.append('border_width', width);
    formData.append('border_color', color);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'smack-media.php', true);
    xhr.onload = function () {
        if (note) {
            note.textContent = (xhr.status === 200) ? 'saved' : 'error';
            setTimeout(() => { note.textContent = ''; }, 1200);
        }
    };
    xhr.send(formData);
}

document.querySelectorAll('.asset-border-control').forEach(function (ctrl) {
    const card  = ctrl.closest('.asset-card');
    const range = ctrl.querySelector('.border-width');
    const color = ctrl.querySelector('.border-color');
    const label = ctrl.querySelector('.border-width-val');

    range.addEventListener('input', function () {
        if (label) label.textContent = (this.value === '0') ? 'Off' : this.value + 'px';
        previewBorder(card);
    });
    range.addEventListener('change', function () { saveBorder(card); });

    color.addEventListener('input',  function () { previewBorder(card); });
    color.addEventListener('change', function () { saveBorder(card); });
});

// ===== SNAPSMACK EOF =====
