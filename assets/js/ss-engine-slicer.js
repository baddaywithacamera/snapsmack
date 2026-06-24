/**
 * SNAPSMACK - Panorama Slicer engine
 *
 * Drives smack-slicer.php: mode selector (Triptych / Cover Slices), source drop,
 * orientation toggle, draggable cut bars, live canvas previews (mirroring the GD
 * slicer's centre-square-crop), the trigram post-picker, and XHR submit.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    const dropZone = document.getElementById('sl-drop');
    const fileInput= document.getElementById('sl-file');
    const editor   = document.getElementById('sl-editor');
    const stage    = document.getElementById('sl-stage');
    const img      = document.getElementById('sl-img');
    const divA     = document.getElementById('sl-divA');
    const divB     = document.getElementById('sl-divB');
    const submitBtn= document.getElementById('sl-submit');
    const form     = document.getElementById('sl-form');
    const btnH     = document.getElementById('sl-h');
    const btnV     = document.getElementById('sl-v');
    const orientVal= document.getElementById('sl-orient-val');
    const cutAInput= document.getElementById('sl-cut-a');
    const cutBInput= document.getElementById('sl-cut-b');
    const modeInput= document.getElementById('sl-mode');
    const progWrap = document.getElementById('sl-progress-wrap');
    const progBar  = document.getElementById('sl-progress-bar');
    const trigPick = document.getElementById('sl-trigram-pick');
    const postInputs = [document.getElementById('sl-post-l'),
                        document.getElementById('sl-post-m'),
                        document.getElementById('sl-post-r')];
    if (!form || !dropZone) return;

    let mode   = 'triptych';
    let orient = 'h';           // 'h' = vertical cuts (L/M/R), 'v' = horizontal cuts (T/M/B)
    let fa = 1/3, fb = 2/3;
    const srcImg = new Image();
    let loaded = false;
    const slots = [null, null, null]; // chosen post ids per slot

    const labels = { h: ['L','M','R'], v: ['T','M','B'] };

    // ── Mode selection ──────────────────────────────────────────────────────
    function setMode(m) {
        mode = m; modeInput.value = m;
        document.getElementById('sl-mode-triptych').classList.toggle('active', m === 'triptych');
        document.getElementById('sl-mode-trigram').classList.toggle('active', m === 'trigram');
        trigPick.style.display = (m === 'trigram') ? '' : 'none';
        submitBtn.textContent = (m === 'trigram') ? 'SLICE & ASSIGN AS COVERS' : 'SLICE INTO THREE POSTS';
        refreshSubmit();
    }
    document.getElementById('sl-mode-triptych').addEventListener('click', () => setMode('triptych'));
    document.getElementById('sl-mode-trigram').addEventListener('click', () => setMode('trigram'));

    // ── Source load ─────────────────────────────────────────────────────────
    dropZone.addEventListener('click', () => fileInput.click());
    dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('is-over'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('is-over'));
    dropZone.addEventListener('drop', e => {
        e.preventDefault(); dropZone.classList.remove('is-over');
        if (e.dataTransfer.files.length) loadFile(e.dataTransfer.files[0]);
    });
    fileInput.addEventListener('change', () => { if (fileInput.files.length) loadFile(fileInput.files[0]); });

    function loadFile(file) {
        if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return;
        const dt = new DataTransfer(); dt.items.add(file);
        fileInput.files = dt.files;
        srcImg.onload = () => {
            loaded = true;
            img.src = srcImg.src;
            editor.style.display = '';
            fa = 1/3; fb = 2/3;
            layout(); redraw(); refreshSubmit();
        };
        srcImg.src = URL.createObjectURL(file);
    }

    // ── Orientation ─────────────────────────────────────────────────────────
    function setOrient(o) {
        orient = o; orientVal.value = o;
        btnH.classList.toggle('active', o === 'h');
        btnV.classList.toggle('active', o === 'v');
        divA.className = 'sl-div ' + (o === 'h' ? 'sl-div--h' : 'sl-div--v');
        divB.className = 'sl-div ' + (o === 'h' ? 'sl-div--h' : 'sl-div--v');
        const L = labels[o];
        for (let i = 0; i < 3; i++) {
            document.getElementById('sl-l' + i).textContent = L[i];
            document.getElementById('sl-slot-label-' + i).textContent = L[i];
        }
        layout(); redraw();
    }
    btnH.addEventListener('click', () => setOrient('h'));
    btnV.addEventListener('click', () => setOrient('v'));

    function layout() {
        if (orient === 'h') {
            divA.style.left = (fa * 100) + '%'; divA.style.top = '';
            divB.style.left = (fb * 100) + '%'; divB.style.top = '';
        } else {
            divA.style.top = (fa * 100) + '%'; divA.style.left = '';
            divB.style.top = (fb * 100) + '%'; divB.style.left = '';
        }
        cutAInput.value = fa.toFixed(4);
        cutBInput.value = fb.toFixed(4);
    }

    // Draw each slice exactly as the GD engine will (centre square-crop, cover).
    function redraw() {
        if (!loaded) return;
        const W = srcImg.naturalWidth, H = srcImg.naturalHeight;
        let regions;
        if (orient === 'h') {
            const xa = fa * W, xb = fb * W;
            regions = [[0, 0, xa, H], [xa, 0, xb - xa, H], [xb, 0, W - xb, H]];
        } else {
            const ya = fa * H, yb = fb * H;
            regions = [[0, 0, W, ya], [0, ya, W, yb - ya], [0, yb, W, H - yb]];
        }
        regions.forEach((r, i) => {
            const cv = document.getElementById('sl-c' + i);
            const cx = cv.getContext('2d');
            cx.fillStyle = '#fff'; cx.fillRect(0, 0, cv.width, cv.height);
            const [rx, ry, rw, rh] = r;
            if (rw < 1 || rh < 1) return;
            const sq = Math.min(rw, rh);
            const ox = rx + (rw - sq) / 2, oy = ry + (rh - sq) / 2;
            cx.drawImage(srcImg, ox, oy, sq, sq, 0, 0, cv.width, cv.height);
        });
    }

    // ── Divider drag ────────────────────────────────────────────────────────
    let dragging = null;
    divA.addEventListener('pointerdown', e => { dragging = 'a'; e.preventDefault(); });
    divB.addEventListener('pointerdown', e => { dragging = 'b'; e.preventDefault(); });
    window.addEventListener('pointermove', e => {
        if (!dragging) return;
        const rect = stage.getBoundingClientRect();
        let f = (orient === 'h')
            ? (e.clientX - rect.left) / rect.width
            : (e.clientY - rect.top)  / rect.height;
        f = Math.max(0.02, Math.min(0.98, f));
        if (dragging === 'a') fa = Math.min(f, fb - 0.02);
        else                  fb = Math.max(f, fa + 0.02);
        layout(); redraw();
    });
    window.addEventListener('pointerup', () => { dragging = null; });

    // ── Trigram post-picker ─────────────────────────────────────────────────
    const picks = Array.from(document.querySelectorAll('.sl-pick'));
    const slotBoxes = Array.from(document.querySelectorAll('.sl-slot-box'));

    picks.forEach(btn => btn.addEventListener('click', () => {
        if (btn.classList.contains('used')) return;
        const free = slots.indexOf(null);
        if (free === -1) return;
        slots[free] = parseInt(btn.dataset.id);
        postInputs[free].value = btn.dataset.id;
        btn.classList.add('used');
        const box = slotBoxes[free];
        box.classList.add('filled');
        box.dataset.pickId = btn.dataset.id;
        box.innerHTML = btn.dataset.thumb
            ? '<img src="' + btn.dataset.thumb + '" alt="">'
            : '<span class="sl-slot-empty">#' + btn.dataset.id + '</span>';
        refreshSubmit();
    }));

    slotBoxes.forEach((box, idx) => box.addEventListener('click', () => {
        if (slots[idx] === null) return;
        const freedId = slots[idx];
        slots[idx] = null;
        postInputs[idx].value = '';
        box.classList.remove('filled');
        box.removeAttribute('data-pick-id');
        box.innerHTML = '<span class="sl-slot-empty">empty</span>';
        const pick = picks.find(p => parseInt(p.dataset.id) === freedId);
        if (pick) pick.classList.remove('used');
        refreshSubmit();
    }));

    // ── Submit gating ───────────────────────────────────────────────────────
    function refreshSubmit() {
        let ok = loaded;
        if (mode === 'trigram') ok = ok && slots.every(s => s !== null);
        submitBtn.disabled = !ok;
    }

    form.addEventListener('submit', e => {
        e.preventDefault();
        if (!fileInput.files.length) return;
        if (mode === 'trigram' && !slots.every(s => s !== null)) return;
        submitBtn.disabled = true;
        progWrap.style.display = ''; progBar.style.width = '0%';

        const xhr = new XMLHttpRequest();
        xhr.open('POST', window.location.href);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.addEventListener('progress', evt => {
            if (evt.lengthComputable) progBar.style.width = Math.round(evt.loaded / evt.total * 100) + '%';
        });
        xhr.addEventListener('load', () => {
            progBar.style.width = '100%';
            if (xhr.responseText.trim() === 'success') {
                window.location.href = 'smack-lt-gram.php?msg=' +
                    (mode === 'trigram' ? 'TRIGRAM_CREATED' : 'TRIPTYCH_CREATED');
            } else {
                alert(xhr.responseText || 'Slice failed.');
                submitBtn.disabled = false; progWrap.style.display = 'none';
            }
        });
        xhr.addEventListener('error', () => {
            alert('Network error during upload.');
            submitBtn.disabled = false; progWrap.style.display = 'none';
        });
        xhr.send(new FormData(form));
    });
}());
// ===== SNAPSMACK EOF =====
